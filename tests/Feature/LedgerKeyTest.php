<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Feature;

use Carbon\Carbon;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;

class LedgerKeyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-17 12:00:00');
        $this->ran = [];
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_the_same_command_on_two_crons_replays_each_events_own_missed_slots(): void
    {
        $hourly = $this->event('hourly', 'test:dual')->hourlyAt(0);
        $daily = $this->event('daily', 'test:dual')->dailyAt('06:30');

        $this->setTick(Carbon::parse('2026-08-17 05:00:00'));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        // Before 1.2.3 the daily event resumed from the hourly one's 11:00 row and its
        // 06:30 slot was never replayed.
        $this->assertSame([
            'hourly@2026-08-17 06:00:00',
            'hourly@2026-08-17 07:00:00',
            'hourly@2026-08-17 08:00:00',
            'hourly@2026-08-17 09:00:00',
            'hourly@2026-08-17 10:00:00',
            'hourly@2026-08-17 11:00:00',
            'daily@2026-08-17 06:30:00',
        ], $this->ran);

        $this->assertSame(6, SchedulerCatchupLog::where('command', ScheduleEventKey::for($hourly))->count());
        $this->assertSame(1, SchedulerCatchupLog::where('command', ScheduleEventKey::for($daily))->count());
    }

    public function test_a_normal_run_of_one_event_does_not_settle_the_other(): void
    {
        $hourly = $this->event('hourly', 'test:dual')->hourlyAt(30);
        $this->event('daily', 'test:dual')->dailyAt('11:30');

        Date::setTestNow('2026-08-17 11:30:00');
        $hourly->exitCode = 0;
        EventFacade::dispatch(new ScheduledTaskFinished($hourly, 0.5));
        Date::setTestNow('2026-08-17 12:00:00');

        $this->setTick(Carbon::parse('2026-08-17 11:00:00'));
        $this->artisan('schedule-catchup:run', ['--force' => true])->assertExitCode(0);

        $this->assertSame(['daily@2026-08-17 11:30:00'], $this->ran);
    }

    public function test_an_upgrade_with_only_pre_1_2_3_rows_settles_slots_without_replaying_twice(): void
    {
        $event = $this->event('solo', 'test:solo')->hourlyAt(0);

        $this->legacyLog('test:solo', '2026-08-17 06:00:00', 0, 1);
        $this->legacyLog('test:solo', '2026-08-17 07:00:00', 0, 1);
        $this->legacyLog('test:solo', '2026-08-17 08:00:00', 1, 1);
        $this->legacyLog('test:solo', '2026-08-17 09:00:00', 0, 1);

        $this->setTick(Carbon::parse('2026-08-17 05:00:00'));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        // The old rows settle 06:00, 07:00 and 09:00, the walk resumes from the latest one,
        // and the pending 08:00 slot is retried.
        $this->assertSame([
            'solo@2026-08-17 08:00:00',
            'solo@2026-08-17 10:00:00',
            'solo@2026-08-17 11:00:00',
        ], $this->ran);

        $retried = SchedulerCatchupLog::where('command', ScheduleEventKey::for($event))
            ->where('scheduled_at', '2026-08-17 08:00:00')
            ->sole();
        $this->assertSame(0, $retried->exit_code);
        $this->assertSame(0, SchedulerCatchupLog::where('command', 'test:solo')->where('exit_code', '!=', 0)->count(), 'The pending old row moved to the new key.');

        // Even from the old last tick, nothing is replayed a second time.
        $this->ran = [];
        $this->setTick(Carbon::parse('2026-08-17 05:00:00'));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame([], $this->ran);
    }

    public function test_a_pending_pre_1_2_3_row_keeps_its_attempt_count(): void
    {
        config(['schedule-catchup.max_attempts' => 3]);

        $event = Schedule::call(function (): void {
            throw new RuntimeException('boom');
        })->name('test:retry')->dailyAt('11:00')->replayOnCatchup();

        $this->legacyLog('test:retry', '2026-08-17 11:00:00', 1, 2);

        $this->setTick(Carbon::parse('2026-08-17 10:00:00'));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $log = SchedulerCatchupLog::sole();
        $this->assertSame(ScheduleEventKey::for($event), $log->command);
        $this->assertSame(3, $log->attempts);
        $this->assertTrue($log->isSettled(3));
    }

    public function test_an_unambiguous_pre_1_2_3_cursor_is_honoured_and_migrated(): void
    {
        config(['schedule-catchup.max_occurrences' => 5]);

        $event = $this->event('capped', 'test:capped')->everyMinute();
        SchedulerCatchupCheckpoint::set('cursor:test:capped', '2026-08-17 11:30:00');

        $this->setTick(Carbon::parse('2026-08-17 11:50:00'));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame([
            'capped@2026-08-17 11:31:00',
            'capped@2026-08-17 11:32:00',
            'capped@2026-08-17 11:33:00',
            'capped@2026-08-17 11:34:00',
            'capped@2026-08-17 11:35:00',
        ], $this->ran);
        $this->assertNull(SchedulerCatchupCheckpoint::get('cursor:test:capped'));
        $this->assertSame('2026-08-17 11:35:00', SchedulerCatchupCheckpoint::get('cursor:'.ScheduleEventKey::for($event)));

        $this->ran = [];
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame('capped@2026-08-17 11:36:00', $this->ran[0]);
    }

    public function test_pre_1_2_3_rows_shared_by_several_events_are_attributed_by_their_crons(): void
    {
        $hourly = $this->event('hourly', 'test:dual')->hourlyAt(0);
        $daily = $this->event('daily', 'test:dual')->dailyAt('06:30');

        // An old install: both events wrote to the one key, and the hourly event's 11:00 row
        // is what made the daily one skip its 06:30 slot.
        $this->legacyLog('test:dual', '2026-08-17 06:00:00', 0, 1);
        $this->legacyLog('test:dual', '2026-08-17 07:00:00', 0, 1);
        $this->legacyLog('test:dual', '2026-08-17 11:00:00', 0, 1);
        $this->legacyLog('test:dual', '2026-08-17 06:30:00', 1, 1);

        // A shared old cursor may belong to either event, so it never moves a walk forward.
        SchedulerCatchupCheckpoint::set('cursor:test:dual', '2026-08-17 11:30:00');

        $this->setTick(Carbon::parse('2026-08-17 05:00:00'));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame([
            'hourly@2026-08-17 08:00:00',
            'hourly@2026-08-17 09:00:00',
            'hourly@2026-08-17 10:00:00',
            'daily@2026-08-17 06:30:00',
        ], $this->ran);

        $this->assertSame(
            ['2026-08-17 06:30:00'],
            SchedulerCatchupLog::where('command', ScheduleEventKey::for($daily))->pluck('scheduled_at')->map->toDateTimeString()->all()
        );
        $this->assertSame(3, SchedulerCatchupLog::where('command', ScheduleEventKey::for($hourly))->count());
        $this->assertNull(SchedulerCatchupCheckpoint::get('cursor:test:dual'), 'Retired once every sharer has walked.');
    }

    private function event(string $label, string $name): CallbackEvent
    {
        return Schedule::call(function () use ($label): void {
            $this->ran[] = $label.'@'.getenv('SCHEDULED_EVENT_TIME');
        })->name($name)->replayOnCatchup();
    }

    private function legacyLog(string $command, string $scheduledAt, ?int $exitCode, int $attempts): void
    {
        SchedulerCatchupLog::create([
            'command' => $command,
            'source' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'ran_at' => $scheduledAt,
            'exit_code' => $exitCode,
            'attempts' => $attempts,
        ]);
    }

    private function setTick(Carbon $at): void
    {
        SchedulerCatchupCheckpoint::set('last_tick', $at->toDateTimeString());
    }
}
