<?php

namespace Cyberwizard\ScheduleCatchup\Console\Commands;

use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Models\SchedulerOutage;
use Illuminate\Console\Command;

class SchedulePruneCommand extends Command
{
    protected $signature = 'schedule-catchup:prune {--days= : Keep this many days (default: config prune_after_days)}';

    protected $description = 'Deletes settled run records and outages older than the retention period.';

    public function handle()
    {
        $days = (int) ($this->option('days') ?? config('schedule-catchup.prune_after_days', 90));

        if ($days < 1) {
            $this->info('Pruning is turned off.');

            return Command::SUCCESS;
        }

        $cutoff = now()->subDays($days)->toDateTimeString();
        $maxAttempts = max(1, (int) config('schedule-catchup.max_attempts', 3));

        // Only settled rows: a pending one is still owed a replay.
        $logs = SchedulerCatchupLog::where('scheduled_at', '<', $cutoff)
            ->where(fn ($query) => $query->where('exit_code', 0)->orWhere('attempts', '>=', $maxAttempts))
            ->delete();
        $outages = SchedulerOutage::where('ended_at', '<', $cutoff)->delete();

        $this->info("Pruned {$logs} run record(s) and {$outages} outage(s) older than {$days} days.");

        return Command::SUCCESS;
    }
}
