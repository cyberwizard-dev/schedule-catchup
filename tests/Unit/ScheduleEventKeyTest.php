<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Unit;

use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Support\Facades\Schedule;

class ScheduleEventKeyTest extends TestCase
{
    public function test_it_strips_the_artisan_prefix_from_command_keys(): void
    {
        $event = Schedule::command('subscriptions:process-unlimited')->daily();

        $this->assertSame('subscriptions:process-unlimited', ScheduleEventKey::for($event));
    }

    public function test_it_falls_back_to_the_description_for_callbacks(): void
    {
        $event = Schedule::call(fn () => null)->name('test:key');

        $this->assertSame('test:key', ScheduleEventKey::for($event));
    }
}
