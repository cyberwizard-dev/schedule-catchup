<?php

namespace Cyberwizard\ScheduleCatchup\Console\Commands;

use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Illuminate\Console\Command;

class ScheduleHeartbeatCommand extends Command
{
    protected $signature = 'schedule-catchup:heartbeat';

    protected $description = 'Records the current time as the last scheduler tick.';

    public function handle()
    {
        SchedulerCatchupCheckpoint::set(SchedulerCatchupCheckpoint::LAST_TICK, now()->toDateTimeString());

        $this->info('Scheduler heartbeat recorded.');

        return Command::SUCCESS;
    }
}
