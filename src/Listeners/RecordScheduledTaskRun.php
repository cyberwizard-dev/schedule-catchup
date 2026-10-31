<?php

namespace Cyberwizard\ScheduleCatchup\Listeners;

use Cron\CronExpression;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\ScheduleCatchup\Support\ScheduleReplayRegistry;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use WeakMap;

class RecordScheduledTaskRun
{
    /** Slot each running task started for, so a long run is not credited to a later slot. */
    private static ?WeakMap $startedFor = null;

    public function starting(ScheduledTaskStarting $event): void
    {
        if (! ScheduleReplayRegistry::isTagged($event->task)) {
            return;
        }

        try {
            self::$startedFor ??= new WeakMap;
            self::$startedFor[$event->task] = $this->slotFor($event->task);
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Records every normal run of a replayable event against the slot it satisfied.
     *
     * Without this ledger the catch-up command cannot tell a slot that was missed from one
     * `schedule:run` already handled, and would replay work that had in fact been done.
     */
    public function handle(ScheduledTaskFinished $event): void
    {
        $task = $event->task;

        if (! ScheduleReplayRegistry::isTagged($task)) {
            return;
        }

        try {
            $slot = self::$startedFor[$task] ?? $this->slotFor($task);
            unset(self::$startedFor[$task]);
        } catch (\Throwable $e) {
            return;
        }

        try {
            SchedulerCatchupLog::updateOrCreate(
                [
                    'command' => ScheduleEventKey::for($task),
                    'scheduled_at' => $slot,
                ],
                [
                    'source' => 'scheduled',
                    'ran_at' => now()->toDateTimeString(),
                    'exit_code' => $task->exitCode,
                    'attempts' => 1,
                ]
            );
        } catch (UniqueConstraintViolationException) {
            return;
        } catch (QueryException $e) {
            Log::warning('Failed to record scheduled task run', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The most recent due time at or before now, which is the slot this run satisfied.
     * Derived from the cron expression rather than the clock so it matches the value the
     * catch-up command computes for the same occurrence, byte for byte.
     */
    protected function slotFor(object $task): string
    {
        $previous = (new CronExpression($task->getExpression()))
            ->getPreviousRunDate(now(), 0, true, $task->timezone);

        return $previous->format('Y-m-d H:i:s');
    }
}
