<?php

namespace Cyberwizard\ScheduleCatchup\Events;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;

/**
 * A missed occurrence was replayed successfully. Runtime is in seconds.
 */
class ScheduleOccurrenceReplayed
{
    public function __construct(
        public Event $event,
        public Carbon $scheduledAt,
        public int $exitCode,
        public float $runtime
    ) {}
}
