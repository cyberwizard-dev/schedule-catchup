<?php

namespace Cyberwizard\ScheduleCatchup\Console\Commands;

use Carbon\Carbon;
use Cron\CronExpression;
use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupCompleted;
use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupFailed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceDeferred;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceFailed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceReplayed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOutageRecorded;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Models\SchedulerOutage;
use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\ScheduleCatchup\Support\ScheduleReplayRegistry;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as ScheduleManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Throwable;

class ScheduleCatchupCommand extends Command
{
    protected $signature = 'schedule-catchup:run
        {--dry-run : Report missed events without running them}
        {--only= : Only replay events whose summary contains this substring}
        {--force : Run even when the downtime gap is below the threshold}';

    protected $description = 'Replays scheduled events that were missed while the server was down.';

    protected bool $incomplete = false;

    protected bool $capHit = false;

    /** Whether the current event's walk stopped at the occurrence cap. */
    protected bool $eventCapHit = false;

    protected int $maxAttempts = 3;

    /** @var array<int, string> */
    protected array $newFailures = [];

    /** @var array<int, string> */
    protected array $exhausted = [];

    /** @var array<int, array<string, string>> */
    protected array $replayedDetails = [];

    public function handle()
    {
        $this->maxAttempts = max(1, (int) config('schedule-catchup.max_attempts', 3));

        $lock = Cache::store(config('schedule-catchup.cache_driver'))
            ->lock('schedule-catchup:run', max(60, (int) config('schedule-catchup.lock_seconds', 3600)));

        if (! $lock->get()) {
            $this->warn('Another catch-up run is already in progress. Skipping this run.');

            return Command::SUCCESS;
        }

        try {
            return $this->runCatchup();
        } finally {
            $lock->release();
        }
    }

    protected function runCatchup()
    {
        $this->incomplete = false;
        $this->capHit = false;
        $this->newFailures = [];
        $this->exhausted = [];
        $this->replayedDetails = [];

        $lastTick = $this->lastTick();

        if ($lastTick === null) {
            $this->recordTick();

            $this->info('No previous tick found; recorded baseline checkpoint.');

            return Command::SUCCESS;
        }

        $events = $this->replayableEvents();
        $gapMinutes = (int) $lastTick->diffInMinutes(now());

        if (! $this->option('dry-run') && $gapMinutes >= max(1, (int) config('schedule-catchup.outage_after_minutes', 10))) {
            $outage = SchedulerOutage::record($lastTick, now());

            if ($outage->wasRecentlyCreated) {
                event(new ScheduleOutageRecorded($outage));
            }
        }

        if (! $this->option('force') && $gapMinutes < config('schedule-catchup.min_gap_minutes', 2)) {
            // Below the threshold the replay work is skipped, but the checkpoint may only move
            // forward once we know nothing fell inside the window we are about to step over.
            if ($this->nothingDueSince($events, $lastTick)) {
                $this->recordTick();
            }

            $this->info("Last tick was {$gapMinutes} minutes ago; below threshold, nothing to catch up.");

            return Command::SUCCESS;
        }

        $replayed = 0;
        $failed = 0;
        $skipped = 0;
        $deferred = 0;

        foreach ($events as $event) {
            $resumeFrom = $this->resumePoint($event, $lastTick);
            $this->eventCapHit = false;
            $occurrences = $this->occurrencesToReplay($event, $resumeFrom);

            foreach ($occurrences as $scheduledAt) {
                if ($this->option('dry-run')) {
                    $this->line("[dry-run] Would replay [{$event->getSummaryForDisplay()}] scheduled at {$scheduledAt->toDateTimeString()}.");
                    $skipped++;

                    continue;
                }

                if (! $this->serverShouldReplay($event, $scheduledAt)) {
                    $skipped++;

                    continue;
                }

                // Its ->when()/->skip() conditions or environments say the
                // normal run would not have happened either.
                if (! $this->overlapping($event) && ! $this->conditionsPass($event)) {
                    $this->line("Skipped [{$event->getSummaryForDisplay()}] at {$scheduledAt->toDateTimeString()}: its conditions do not pass.");
                    $skipped++;

                    continue;
                }

                $outcome = $this->replayEvent($event, $scheduledAt);

                if ($outcome === 'replayed') {
                    $replayed++;
                    $this->replayedDetails[] = [
                        'event' => $event->getSummaryForDisplay(),
                        'scheduled_at' => $scheduledAt->toDateTimeString(),
                    ];

                    continue;
                }

                $this->incomplete = true;

                if ($outcome === 'deferred') {
                    $deferred++;
                } else {
                    $failed++;
                }
            }

            if (! $this->option('dry-run')) {
                $this->saveCursor($event, $occurrences);
            }
        }

        if ($replayed > 0) {
            $this->notifyReplay($replayed, $skipped, $deferred);
        }

        if ($this->newFailures !== [] || $this->exhausted !== []) {
            $this->notifyFailures();
        }

        $this->info("Catch-up complete. Replayed {$replayed} missed event(s), failed {$failed}, deferred {$deferred}, skipped {$skipped}.");

        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }

        if ($this->option('only')) {
            $this->info('Checkpoint not advanced because --only was used.');

            return Command::SUCCESS;
        }

        if ($this->incomplete || $this->capHit) {
            $this->warn('Some missed events were not completed; the checkpoint was not advanced. Completed events resume from their own progress and incomplete ones will be retried.');

            return Command::SUCCESS;
        }

        $this->recordTick();

        return Command::SUCCESS;
    }

    /**
     * @return array<int, Event>
     */
    protected function replayableEvents(): array
    {
        $only = $this->option('only');

        return collect(Schedule::events())
            ->filter(fn ($event) => ScheduleReplayRegistry::isTagged($event))
            ->filter(fn ($event) => ! $only || str_contains($event->getSummaryForDisplay(), $only))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, Event>  $events
     */
    protected function nothingDueSince(array $events, Carbon $lastTick): bool
    {
        foreach ($events as $event) {
            if ($this->missedOccurrences($event, $lastTick) !== []) {
                return false;
            }
        }

        return true;
    }

    protected function resumePoint(Event $event, Carbon $lastTick): Carbon
    {
        // A walk cut short by the cap resumes where it stopped, even though the
        // heartbeat has since moved the last tick past it.
        $cursor = SchedulerCatchupCheckpoint::get($this->cursorKey($event));
        if ($cursor) {
            return Carbon::parse($cursor);
        }

        $lastLogged = SchedulerCatchupLog::where('command', ScheduleEventKey::for($event))
            ->max('scheduled_at');

        if ($lastLogged) {
            $lastLogged = Carbon::parse($lastLogged);

            if ($lastLogged->gt($lastTick)) {
                return $lastLogged;
            }
        }

        return $lastTick;
    }

    /**
     * Occurrences still owed a run: everything the cron walk turns up that has not been
     * settled, plus earlier slots left pending by a previous run's failure. Pending slots are
     * added explicitly because the forward-only cursor would otherwise step past them.
     *
     * @return array<int, Carbon>
     */
    protected function occurrencesToReplay(Event $event, Carbon $resumeFrom): array
    {
        $settled = $this->settledSlots($event, $resumeFrom);

        $candidates = collect($this->missedOccurrences($event, $resumeFrom))
            ->concat($this->pendingSlots($event))
            ->reject(fn (Carbon $slot) => in_array($slot->toDateTimeString(), $settled, true))
            ->unique(fn (Carbon $slot) => $slot->toDateTimeString())
            ->sortBy(fn (Carbon $slot) => $slot->getTimestamp())
            ->values();

        return $candidates->all();
    }

    /**
     * @return array<int, string>
     */
    protected function settledSlots(Event $event, Carbon $from): array
    {
        return SchedulerCatchupLog::where('command', ScheduleEventKey::for($event))
            ->where('scheduled_at', '>=', $from->toDateTimeString())
            ->where(fn ($query) => $query->where('exit_code', 0)->orWhere('attempts', '>=', $this->maxAttempts))
            ->pluck('scheduled_at')
            ->map(fn ($slot) => Carbon::parse($slot)->toDateTimeString())
            ->all();
    }

    /**
     * @return array<int, Carbon>
     */
    protected function pendingSlots(Event $event): array
    {
        return SchedulerCatchupLog::where('command', ScheduleEventKey::for($event))
            ->where('attempts', '<', $this->maxAttempts)
            ->where(fn ($query) => $query->where('exit_code', '!=', 0)->orWhereNull('exit_code'))
            ->pluck('scheduled_at')
            ->map(fn ($slot) => Carbon::parse($slot))
            ->all();
    }

    /**
     * @return array<int, Carbon>
     */
    protected function missedOccurrences(Event $event, Carbon $from): array
    {
        $cap = max(1, (int) config('schedule-catchup.max_occurrences', 50));
        $occurrences = [];
        $cron = new CronExpression($event->getExpression());
        $cursor = $from->copy();
        $windowEnd = now()->startOfMinute();

        while (count($occurrences) < $cap) {
            $next = $cron->getNextRunDate($cursor, 0, false, $event->timezone);

            if ($next >= $windowEnd) {
                break;
            }

            $occurrences[] = Carbon::instance($next);
            $cursor = Carbon::instance($next);
        }

        if (count($occurrences) === $cap && $cron->getNextRunDate($cursor, 0, false, $event->timezone) < $windowEnd) {
            $this->capHit = true;
            $this->eventCapHit = true;
        }

        return $occurrences;
    }

    /**
     * Honours ->onOneServer() during replay. That gate lives in ScheduleRunCommand, so calling
     * Event::run() directly bypasses it; without this a second host's catch-up could run the
     * same occurrence concurrently rather than merely redundantly.
     */
    protected function serverShouldReplay(Event $event, Carbon $scheduledAt): bool
    {
        if (! $event->onOneServer) {
            return true;
        }

        try {
            return $this->laravel->make(ScheduleManager::class)->serverShouldRun($event, $scheduledAt);
        } catch (Throwable $e) {
            return true;
        }
    }

    protected function overlapping(Event $event): bool
    {
        return $event->withoutOverlapping && $event->mutex->exists($event);
    }

    protected function conditionsPass(Event $event): bool
    {
        try {
            return $event->runsInEnvironment($this->laravel->environment()) && $event->filtersPass($this->laravel);
        } catch (Throwable $e) {
            return true;
        }
    }

    protected function cursorKey(Event $event): string
    {
        return 'cursor:'.ScheduleEventKey::for($event);
    }

    /**
     * Remembers where a capped walk stopped so the rest is replayed on the next run, and
     * forgets it once the event has caught up.
     *
     * @param  array<int, Carbon>  $occurrences
     */
    protected function saveCursor(Event $event, array $occurrences): void
    {
        $key = $this->cursorKey($event);

        if ($this->eventCapHit && $occurrences !== []) {
            SchedulerCatchupCheckpoint::set($key, end($occurrences)->toDateTimeString());

            return;
        }

        if (SchedulerCatchupCheckpoint::get($key) !== null) {
            SchedulerCatchupCheckpoint::query()->where('key', $key)->delete();
        }
    }

    /**
     * @return 'replayed'|'deferred'|'failed'
     */
    protected function replayEvent(Event $event, Carbon $scheduledAt): string
    {
        $this->setScheduledTime($scheduledAt);

        // A background run returns before the command finishes, so its exit code
        // would read as success. Replays run in the foreground.
        $background = $event->runInBackground;
        $event->runInBackground = false;

        // Laravel 12 and older have no skippedBecauseOverlapping flag, so the mutex is checked
        // up front and used when the flag is missing.
        $wasOverlapping = $this->overlapping($event);
        $startedAt = microtime(true);

        try {
            $event->run($this->laravel);
            $exitCode = $event->exitCode;
            $runtime = microtime(true) - $startedAt;
        } catch (Throwable $e) {
            $this->error("Failed to replay [{$event->getSummaryForDisplay()}]: {$e->getMessage()}");
            $this->recordAttempt($event, $scheduledAt, null, $e->getMessage());

            return 'failed';
        } finally {
            $event->runInBackground = $background;
            $this->clearScheduledTime();
        }

        // An overlapping run returns from Event::run() without ever setting an exit code. Logging
        // that as a success would retire the occurrence permanently even though nothing ran.
        // It is logged as still owed, so the next run retries it even after the heartbeat has
        // moved the last tick past it.
        $skippedBecauseOverlapping = property_exists($event, 'skippedBecauseOverlapping')
            ? (bool) $event->skippedBecauseOverlapping
            : $wasOverlapping;

        if ($skippedBecauseOverlapping) {
            $this->warn("Replay of [{$event->getSummaryForDisplay()}] at {$scheduledAt->toDateTimeString()} was skipped because the command is already running; it will be retried.");
            $this->recordDeferred($event, $scheduledAt);

            event(new ScheduleOccurrenceDeferred($event, $scheduledAt));

            return 'deferred';
        }

        if ($exitCode !== null && $exitCode !== 0) {
            $this->error("Replay of [{$event->getSummaryForDisplay()}] exited with code {$exitCode}.");
            $this->recordAttempt($event, $scheduledAt, $exitCode, "Exited with code {$exitCode}.");

            return 'failed';
        }

        SchedulerCatchupLog::updateOrCreate(
            [
                'command' => ScheduleEventKey::for($event),
                'scheduled_at' => $scheduledAt->toDateTimeString(),
            ],
            [
                'source' => 'catchup',
                'ran_at' => now()->toDateTimeString(),
                'exit_code' => 0,
                'last_error' => null,
            ]
        );

        $this->info("Replayed [{$event->getSummaryForDisplay()}] scheduled at {$scheduledAt->toDateTimeString()} (exit {$exitCode}).");

        event(new ScheduleOccurrenceReplayed($event, $scheduledAt, (int) $exitCode, $runtime));

        return 'replayed';
    }

    /**
     * Persists a failed attempt so retries are bounded. Without a durable count a command that
     * fails deterministically is retried every tick forever, holding the checkpoint back and
     * alerting admins each time.
     */
    protected function recordAttempt(Event $event, Carbon $scheduledAt, ?int $exitCode, string $error): void
    {
        $key = ScheduleEventKey::for($event);

        $log = SchedulerCatchupLog::firstOrNew([
            'command' => $key,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
        ]);

        $wasFirstAttempt = ! $log->exists;

        $log->fill([
            'source' => 'catchup',
            'ran_at' => now()->toDateTimeString(),
            'exit_code' => $exitCode ?? 1,
            'attempts' => ($log->attempts ?? 0) + 1,
            'last_error' => mb_substr($error, 0, 2000),
        ])->save();

        $label = "{$event->getSummaryForDisplay()} @ {$scheduledAt->toDateTimeString()}";
        $isExhausted = $log->attempts >= $this->maxAttempts;

        event(new ScheduleOccurrenceFailed($event, $scheduledAt, $exitCode, $error, $log->attempts, $isExhausted));

        if ($isExhausted) {
            $this->exhausted[] = $label;
            $this->error("Giving up on [{$label}] after {$log->attempts} attempt(s).");

            return;
        }

        if ($wasFirstAttempt) {
            $this->newFailures[] = $label;
        }
    }

    protected function recordDeferred(Event $event, Carbon $scheduledAt): void
    {
        $log = SchedulerCatchupLog::firstOrNew([
            'command' => ScheduleEventKey::for($event),
            'scheduled_at' => $scheduledAt->toDateTimeString(),
        ]);

        if ($log->exists) {
            return;
        }

        $log->fill([
            'source' => 'catchup',
            'ran_at' => now()->toDateTimeString(),
            'exit_code' => null,
            'attempts' => 0,
            'last_error' => 'Deferred: the command was already running.',
        ])->save();
    }

    protected function setScheduledTime(Carbon $scheduledAt): void
    {
        putenv('SCHEDULED_EVENT_TIME='.$scheduledAt->toDateTimeString());
        $_ENV['SCHEDULED_EVENT_TIME'] = $scheduledAt->toDateTimeString();
    }

    protected function clearScheduledTime(): void
    {
        putenv('SCHEDULED_EVENT_TIME');
        unset($_ENV['SCHEDULED_EVENT_TIME']);
    }

    protected function lastTick(): ?Carbon
    {
        $value = SchedulerCatchupCheckpoint::get(SchedulerCatchupCheckpoint::LAST_TICK);

        return $value ? Carbon::parse($value) : null;
    }

    protected function recordTick(): void
    {
        SchedulerCatchupCheckpoint::set(SchedulerCatchupCheckpoint::LAST_TICK, now()->toDateTimeString());
    }

    protected function notifyReplay(int $count, int $skipped, int $deferred): void
    {
        event(new ScheduleCatchupCompleted($count, $skipped, $deferred, $this->replayedDetails));

        $onReplay = config('schedule-catchup.on_replay');

        if (is_callable($onReplay)) {
            $onReplay($count, $this->replayedDetails);
        }
    }

    /**
     * Only new failures and final give-ups are surfaced. Every intermediate retry firing a
     * notification turns one broken command into an alert every five minutes.
     */
    protected function notifyFailures(): void
    {
        event(new ScheduleCatchupFailed($this->newFailures, $this->exhausted, $this->maxAttempts));

        $onFailure = config('schedule-catchup.on_failure');

        if (is_callable($onFailure)) {
            $onFailure($this->newFailures, $this->exhausted);
        }
    }
}
