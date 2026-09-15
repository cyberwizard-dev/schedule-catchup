<?php

namespace Cyberwizard\ScheduleCatchup\Events;

class ScheduleCatchupFailed
{
    public function __construct(
        public array $newFailures,
        public array $exhausted,
        public int $maxAttempts
    ) {}
}
