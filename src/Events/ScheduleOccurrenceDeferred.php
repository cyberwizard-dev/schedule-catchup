<?php

namespace Cyberwizard\ScheduleCatchup\Events;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;

/**
 * A replay was skipped because the command was already running; the occurrence
 * stays owed and is retried on a later run.
 */
class ScheduleOccurrenceDeferred
{
    public function __construct(
        public Event $event,
        public Carbon $scheduledAt
    ) {}
}
