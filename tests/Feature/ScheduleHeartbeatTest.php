<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Feature;

use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ScheduleHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_last_tick_on_first_run(): void
    {
        $this->artisan('schedule-catchup:heartbeat')->assertExitCode(0);

        $this->assertDatabaseHas('cyber_schedule_catchup_checkpoints', [
            'key' => 'last_tick',
        ]);
    }

    public function test_it_updates_an_existing_tick(): void
    {
        SchedulerCatchupCheckpoint::set('last_tick', '2026-01-01 00:00:00');

        $this->artisan('schedule-catchup:heartbeat')->assertExitCode(0);

        $this->assertNotSame(
            '2026-01-01 00:00:00',
            SchedulerCatchupCheckpoint::get('last_tick')
        );
    }
}
