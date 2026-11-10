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
use DateTime;
use DateTimeZone;
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

    /**
     * Checkpoint values loaded once per run (last tick and every tagged event's cursor),
     * kept in step with every write the run makes.
     *
     * @var array<string, string>
     */
    protected array $checkpoints = [];

    /**
     * Ledger rows for every tagged event, loaded in three queries before the walk and keyed
     * by ScheduleEventKey::for(). Rows written under a pre-1.2.3 key are folded in, and
     * `legacyPending` remembers which pending slots still live under that old key.
     *
     * @var array{latest: array<string, string>, settled: array<string, array<int, Carbon>>, pending: array<string, array<int, Carbon>>, legacyPending: array<string, array<string, string>>}|null
     */
    protected ?array $ledger = null;

    /**
     * Ledger keys already walked this run. A second event sharing a key would see the first
     * one's writes, so its share of the ledger is reloaded before it is walked.
     *
     * @var array<string, true>
     */
    protected array $walkedKeys = [];

    /**
     * Every pre-1.2.3 key among the tagged events, mapped to the distinct current keys that
     * share it, in schedule order. One owner means old rows and cursors can be attributed to
     * that event; several mean the old ledger mixed their runs together.
     *
     * @var array<string, array<int, string>>
     */
    protected array $legacyOwners = [];

    /**
     * The first tagged event for each current key.
     *
     * @var array<string, Event>
     */
    protected array $eventsByKey = [];

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
        $this->ledger = null;
        $this->walkedKeys = [];

        $this->mapLegacyKeys();
        $events = $this->replayableEvents();
        $this->loadCheckpoints($events);

        $lastTick = $this->lastTick();

        if ($lastTick === null) {
            $this->recordTick();

            $this->info('No previous tick found; recorded baseline checkpoint.');

            return Command::SUCCESS;
        }

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

        $this->loadLedger($events, $lastTick);

        foreach ($events as $event) {
            if (isset($this->walkedKeys[ScheduleEventKey::for($event)])) {
                $this->loadLedger([$event], $lastTick);
            }

            $resumeFrom = $this->resumePoint($event, $lastTick);
            $this->eventCapHit = false;
            $occurrences = $this->occurrencesToReplay($event, $resumeFrom);
            $this->walkedKeys[ScheduleEventKey::for($event)] = true;

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

        if (! $this->option('dry-run')) {
            $this->retireLegacyCursors($events);
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
     * Maps every pre-1.2.3 key to the current keys that share it. Built from all tagged
     * events, not just those selected by --only, so ambiguity does not depend on the filter.
     */
    protected function mapLegacyKeys(): void
    {
        $this->legacyOwners = [];
        $this->eventsByKey = [];

        foreach (Schedule::events() as $event) {
            if (! ScheduleReplayRegistry::isTagged($event)) {
                continue;
            }

            $key = ScheduleEventKey::for($event);
            $legacy = ScheduleEventKey::legacy($event);

            $this->eventsByKey[$key] ??= $event;

            if (! in_array($key, $this->legacyOwners[$legacy] ?? [], true)) {
                $this->legacyOwners[$legacy][] = $key;
            }
        }
    }

    /**
     * Whether rows and cursors written under this event's pre-1.2.3 key belong to it alone.
     */
    protected function legacyKeyIsUnambiguous(Event $event): bool
    {
        return count($this->legacyOwners[ScheduleEventKey::legacy($event)] ?? []) <= 1;
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

    /**
     * Reads the last tick and every tagged event's cursor in one query.
     *
     * @param  array<int, Event>  $events
     */
    protected function loadCheckpoints(array $events): void
    {
        $keys = collect($events)
            ->flatMap(fn (Event $event) => [$this->cursorKey($event), $this->legacyCursorKey($event)])
            ->prepend(SchedulerCatchupCheckpoint::LAST_TICK)
            ->unique()
            ->values()
            ->all();

        $this->checkpoints = SchedulerCatchupCheckpoint::query()
            ->whereIn('key', $keys)
            ->pluck('value', 'key')
            ->all();
    }

    protected function checkpoint(string $key): ?string
    {
        return $this->checkpoints[$key] ?? null;
    }

    protected function setCheckpoint(string $key, string $value): void
    {
        SchedulerCatchupCheckpoint::set($key, $value);

        $this->checkpoints[$key] = $value;
    }

    protected function forgetCheckpoint(string $key): void
    {
        SchedulerCatchupCheckpoint::query()->where('key', $key)->delete();

        unset($this->checkpoints[$key]);
    }

    /**
     * Loads, for the given events at once, the latest logged slot per key, the settled slots
     * inside the earliest window any of them will walk, and every pending slot. Each event
     * then takes its own share in PHP, applying its own lower bound. Entries for these
     * events' keys are replaced, so this also refreshes one key after it has been walked.
     *
     * Rows written under a pre-1.2.3 key are read in the same queries. When only one event
     * maps to that key they are its rows: they settle its slots, owe its pending slots and
     * count towards its latest slot. When several events share it, the rows cannot be told
     * apart: they still settle any matching slot of every sharer (so nothing that ran is run
     * again), each pending slot is owed by the first sharer whose cron is due at it, and the
     * shared latest slot is ignored, because resuming from it is what skipped the other
     * events' missed slots.
     *
     * @param  array<int, Event>  $events
     */
    protected function loadLedger(array $events, Carbon $lastTick): void
    {
        $this->ledger ??= ['latest' => [], 'settled' => [], 'pending' => [], 'legacyPending' => []];

        $keys = collect($events)
            ->map(fn (Event $event) => ScheduleEventKey::for($event))
            ->unique()
            ->values()
            ->all();

        if ($keys === []) {
            return;
        }

        foreach ($keys as $key) {
            unset(
                $this->ledger['latest'][$key],
                $this->ledger['settled'][$key],
                $this->ledger['pending'][$key],
                $this->ledger['legacyPending'][$key],
            );
        }

        $legacyKeys = collect($events)
            ->map(fn (Event $event) => ScheduleEventKey::legacy($event))
            ->unique()
            ->values()
            ->all();

        $commands = array_values(array_unique([...$keys, ...$legacyKeys]));

        $latest = SchedulerCatchupLog::query()
            ->toBase()
            ->selectRaw('command, max(scheduled_at) as latest')
            ->whereIn('command', $commands)
            ->groupBy('command')
            ->pluck('latest', 'command')
            ->filter()
            ->all();

        foreach ($events as $event) {
            $key = ScheduleEventKey::for($event);
            $candidates = [$latest[$key] ?? null];

            if ($this->legacyKeyIsUnambiguous($event)) {
                $candidates[] = $latest[ScheduleEventKey::legacy($event)] ?? null;
            }

            $newest = collect($candidates)
                ->filter()
                ->map(fn ($slot) => Carbon::parse($slot)->toDateTimeString())
                ->max();

            if ($newest !== null) {
                $this->ledger['latest'][$key] = $newest;
            }
        }

        $earliest = collect($events)
            ->map(fn (Event $event) => $this->resumePoint($event, $lastTick))
            ->min(fn (Carbon $from) => $from->toDateTimeString());

        SchedulerCatchupLog::query()
            ->whereIn('command', $commands)
            ->where('scheduled_at', '>=', $earliest)
            ->where(fn ($query) => $query->where('exit_code', 0)->orWhere('attempts', '>=', $this->maxAttempts))
            ->get(['command', 'scheduled_at'])
            ->each(function (SchedulerCatchupLog $log) use ($keys): void {
                $owners = in_array($log->command, $keys, true)
                    ? [$log->command]
                    : array_intersect($this->legacyOwners[$log->command] ?? [], $keys);

                foreach ($owners as $owner) {
                    $this->ledger['settled'][$owner][] = Carbon::parse($log->scheduled_at);
                }
            });

        SchedulerCatchupLog::query()
            ->whereIn('command', $commands)
            ->where('attempts', '<', $this->maxAttempts)
            ->where(fn ($query) => $query->where('exit_code', '!=', 0)->orWhereNull('exit_code'))
            ->get(['command', 'scheduled_at'])
            ->each(function (SchedulerCatchupLog $log) use ($keys): void {
                $slot = Carbon::parse($log->scheduled_at);

                if (in_array($log->command, $keys, true)) {
                    $this->ledger['pending'][$log->command][] = $slot;

                    return;
                }

                $owner = $this->legacyPendingOwner($log->command, $slot);

                if ($owner !== null && in_array($owner, $keys, true)) {
                    $this->ledger['pending'][$owner][] = $slot;
                    $this->ledger['legacyPending'][$owner][$slot->toDateTimeString()] = $log->command;
                }
            });
    }

    /**
     * The current key that owes a pending slot logged under a pre-1.2.3 key: its only owner,
     * or, when shared, the first owner whose cron is due at that slot (else the first owner).
     */
    protected function legacyPendingOwner(string $legacy, Carbon $slot): ?string
    {
        $owners = $this->legacyOwners[$legacy] ?? [];

        if (count($owners) <= 1) {
            return $owners[0] ?? null;
        }

        foreach ($owners as $owner) {
            $event = $this->eventsByKey[$owner];

            try {
                $timezone = $event->timezone ?: date_default_timezone_get();
                $at = new DateTime($slot->toDateTimeString(), $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone($timezone));

                if ((new CronExpression($event->getExpression()))->isDue($at, $at->getTimezone()->getName())) {
                    return $owner;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $owners[0];
    }

    protected function resumePoint(Event $event, Carbon $lastTick): Carbon
    {
        // A walk cut short by the cap resumes where it stopped, even though the
        // heartbeat has since moved the last tick past it.
        $cursor = $this->checkpoint($this->cursorKey($event));
        if ($cursor) {
            return Carbon::parse($cursor);
        }

        $resumeFrom = $lastTick;
        $lastLogged = $this->ledger['latest'][ScheduleEventKey::for($event)] ?? null;

        if ($lastLogged) {
            $lastLogged = Carbon::parse($lastLogged);

            if ($lastLogged->gt($lastTick)) {
                $resumeFrom = $lastLogged;
            }
        }

        // A cursor written before 1.2.3 is this event's own when nobody else shares its old
        // key. A shared one may belong to another event, so it can only pull the walk back,
        // never push it forward past slots this event still owes.
        $legacyCursor = $this->checkpoint($this->legacyCursorKey($event));

        if ($legacyCursor) {
            $legacyCursor = Carbon::parse($legacyCursor);

            if ($this->legacyKeyIsUnambiguous($event) || $legacyCursor->lt($resumeFrom)) {
                return $legacyCursor;
            }
        }

        return $resumeFrom;
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
        $lowerBound = $from->toDateTimeString();

        return collect($this->ledger['settled'][ScheduleEventKey::for($event)] ?? [])
            ->map(fn (Carbon $slot) => $slot->toDateTimeString())
            ->filter(fn (string $slot) => $slot >= $lowerBound)
            ->values()
            ->all();
    }

    /**
     * @return array<int, Carbon>
     */
    protected function pendingSlots(Event $event): array
    {
        return array_map(
            fn (Carbon $slot) => $slot->copy(),
            $this->ledger['pending'][ScheduleEventKey::for($event)] ?? []
        );
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
     * Where releases before 1.2.3 kept this event's cursor.
     */
    protected function legacyCursorKey(Event $event): string
    {
        return 'cursor:'.ScheduleEventKey::legacy($event);
    }

    /**
     * Drops a pre-1.2.3 cursor once every event sharing its old key has been walked this run.
     * By then each has resumed from it and, if still capped, written a cursor of its own.
     *
     * @param  array<int, Event>  $events
     */
    protected function retireLegacyCursors(array $events): void
    {
        $legacyKeys = collect($events)
            ->map(fn (Event $event) => ScheduleEventKey::legacy($event))
            ->unique();

        foreach ($legacyKeys as $legacy) {
            $cursorKey = 'cursor:'.$legacy;

            if ($this->checkpoint($cursorKey) === null) {
                continue;
            }

            $owners = $this->legacyOwners[$legacy] ?? [];

            if (collect($owners)->every(fn (string $owner) => isset($this->walkedKeys[$owner]))) {
                $this->forgetCheckpoint($cursorKey);
            }
        }
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
            $this->setCheckpoint($key, end($occurrences)->toDateTimeString());

            return;
        }

        if ($this->checkpoint($key) !== null) {
            $this->forgetCheckpoint($key);
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

        $this->ledgerRow($event, $scheduledAt)->fill([
            'source' => 'catchup',
            'ran_at' => now()->toDateTimeString(),
            'exit_code' => 0,
            'last_error' => null,
        ])->save();

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
        $log = $this->ledgerRow($event, $scheduledAt);

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
        $log = $this->ledgerRow($event, $scheduledAt);

        if ($log->exists) {
            // A pending row carried over from a pre-1.2.3 key still needs its new key saved.
            if ($log->isDirty()) {
                $log->save();
            }

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

    /**
     * The ledger row for this event's slot, ready to fill and save. A slot still pending under
     * the event's pre-1.2.3 key is moved to the current key, keeping its attempt count, so the
     * retry budget carries over and the old row is not left owing the slot.
     */
    protected function ledgerRow(Event $event, Carbon $scheduledAt): SchedulerCatchupLog
    {
        $key = ScheduleEventKey::for($event);
        $slot = $scheduledAt->toDateTimeString();

        $log = SchedulerCatchupLog::firstOrNew(['command' => $key, 'scheduled_at' => $slot]);

        $legacy = $this->ledger['legacyPending'][$key][$slot] ?? null;

        if ($log->exists || $legacy === null) {
            return $log;
        }

        unset($this->ledger['legacyPending'][$key][$slot]);

        $legacyLog = SchedulerCatchupLog::query()
            ->where('command', $legacy)
            ->where('scheduled_at', $slot)
            ->first();

        if ($legacyLog === null) {
            return $log;
        }

        $legacyLog->command = $key;

        return $legacyLog;
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
        $value = $this->checkpoint(SchedulerCatchupCheckpoint::LAST_TICK);

        return $value ? Carbon::parse($value) : null;
    }

    protected function recordTick(): void
    {
        $this->setCheckpoint(SchedulerCatchupCheckpoint::LAST_TICK, now()->toDateTimeString());
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
