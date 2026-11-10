<?php

namespace Cyberwizard\ScheduleCatchup\Events;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;

/**
 * A replay attempt of a missed occurrence failed. Fired on every failed attempt;
 * exhausted is true once the attempt count has reached max_attempts.
 */
class ScheduleOccurrenceFailed
{
    public function __construct(
        public Event $event,
        public Carbon $scheduledAt,
        public ?int $exitCode,
        public string $error,
        public int $attempts,
        public bool $exhausted
    ) {}
}
