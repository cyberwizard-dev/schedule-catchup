<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Unit;

use Cyberwizard\ScheduleCatchup\Support\ScheduleReplayRegistry;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Support\Facades\Schedule;

class ScheduleReplayRegistryTest extends TestCase
{
    public function test_tagged_events_are_recognized(): void
    {
        $event = Schedule::call(fn () => null)->name('test:tag');

        $event->replayOnCatchup();

        $this->assertTrue(ScheduleReplayRegistry::isTagged($event));
    }

    public function test_untagged_events_are_not_recognized(): void
    {
        $event = Schedule::call(fn () => null)->name('test:untagged');

        $this->assertFalse(ScheduleReplayRegistry::isTagged($event));
    }
}
