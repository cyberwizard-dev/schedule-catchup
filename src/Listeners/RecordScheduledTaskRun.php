<?php

namespace Cyberwizard\ScheduleCatchup\Listeners;

use Cron\CronExpression;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\ScheduleCatchup\Support\ScheduleReplayRegistry;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class RecordScheduledTaskRun
{
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
            $slot = $this->slotFor($task);
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
        } catch (QueryException $e) {
            if ((int) $e->getCode() !== 23000) {
                Log::warning('Failed to record scheduled task run', ['error' => $e->getMessage()]);
            }
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
