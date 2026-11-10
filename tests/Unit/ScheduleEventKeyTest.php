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

        $this->assertSame('subscriptions:process-unlimited', ScheduleEventKey::legacy($event));
        $this->assertSame('subscriptions:process-unlimited|0 0 * * *|UTC', ScheduleEventKey::for($event));
    }

    public function test_it_falls_back_to_the_description_for_callbacks(): void
    {
        $event = Schedule::call(fn () => null)->name('test:key');

        $this->assertSame('test:key', ScheduleEventKey::legacy($event));
        $this->assertSame('test:key|* * * * *|UTC', ScheduleEventKey::for($event));
    }

    public function test_the_same_command_on_different_frequencies_gets_different_keys(): void
    {
        $daily = Schedule::command('reports:send --team=1')->dailyAt('00:00');
        $morning = Schedule::command('reports:send --team=1')->dailyAt('06:00');
        $elsewhere = Schedule::command('reports:send --team=1')->dailyAt('00:00')->timezone('Europe/Berlin');

        $this->assertSame(ScheduleEventKey::legacy($daily), ScheduleEventKey::legacy($morning));
        $this->assertSame('reports:send --team=1|0 6 * * *|UTC', ScheduleEventKey::for($morning));
        $this->assertSame('reports:send --team=1|0 0 * * *|Europe/Berlin', ScheduleEventKey::for($elsewhere));
        $this->assertCount(3, array_unique(array_map(ScheduleEventKey::for(...), [$daily, $morning, $elsewhere])));
    }

    public function test_sub_minute_events_include_their_repeat_interval(): void
    {
        $event = Schedule::call(fn () => null)->name('test:fast')->everyTenSeconds();

        $this->assertSame('test:fast|* * * * *|UTC|10s', ScheduleEventKey::for($event));
    }

    public function test_keys_longer_than_the_column_are_shortened_with_a_hash(): void
    {
        $event = Schedule::call(fn () => null)->name('test:'.str_repeat('x', 300))->daily();

        $key = ScheduleEventKey::for($event);

        $this->assertSame(ScheduleEventKey::MAX_LENGTH, mb_strlen($key));
        $this->assertStringEndsWith('#'.sha1(ScheduleEventKey::legacy($event).'|0 0 * * *|UTC'), $key);
    }
}
