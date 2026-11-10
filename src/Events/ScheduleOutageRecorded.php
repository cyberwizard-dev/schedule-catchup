<?php

namespace Cyberwizard\ScheduleCatchup\Events;

use Cyberwizard\ScheduleCatchup\Models\SchedulerOutage;

/**
 * A new outage row was created for a gap between scheduler ticks.
 */
class ScheduleOutageRecorded
{
    public function __construct(
        public SchedulerOutage $outage
    ) {}
}
