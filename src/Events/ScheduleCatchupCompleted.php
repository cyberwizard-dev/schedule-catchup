<?php

namespace Cyberwizard\ScheduleCatchup\Events;

class ScheduleCatchupCompleted
{
    public function __construct(
        public int $replayed,
        public int $skipped,
        public int $deferred,
        public array $details = []
    ) {}
}
