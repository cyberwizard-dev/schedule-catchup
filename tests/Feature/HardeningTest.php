<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Feature;

use Carbon\Carbon;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCommandRun;
use Cyberwizard\ScheduleCatchup\Models\SchedulerOutage;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Cyberwizard\ScheduleCatchup\Traits\RecordsScheduleRun;
use Cyberwizard\ScheduleCatchup\Traits\ResolvesScheduledTime;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Schedule;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-17 12:00:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function setTick(Carbon $at): void
    {
        SchedulerCatchupCheckpoint::set('last_tick', $at->toDateTimeString());
    }

    /** One scheduler tick in production: catch-up, then the heartbeat, then five minutes. */
    private function tick(array $options = []): void
    {
        $this->artisan('schedule-catchup:run', $options)->assertExitCode(0);
        $this->artisan('schedule-catchup:heartbeat')->assertExitCode(0);
        $this->travel(5)->minutes();
    }

    public function test_a_capped_backlog_drains_even_though_the_heartbeat_moves_the_last_tick(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = env('SCHEDULED_EVENT_TIME');
        })->name('test:cap')->everyMinute()->replayOnCatchup();

        $this->setTick(now()->subHours(2));

        $this->tick();
        $this->assertCount(50, $ran);
        $this->tick();
        $this->tick();
        $this->tick();

        // 120 missed in the outage plus the minutes that passed while catching up,
        // each replayed exactly once.
        $this->assertSame(count($ran), count(array_unique($ran)));
        $this->assertContains('2026-08-17 10:01:00', $ran);
        $this->assertContains('2026-08-17 11:59:00', $ran);
        $this->assertNull(SchedulerCatchupCheckpoint::get('cursor:test:cap'), 'The cursor is cleared once caught up.');
    }

    public function test_a_replay_deferred_by_an_overlap_survives_the_heartbeat(): void
    {
        $ran = 0;
        $event = Schedule::call(function () use (&$ran): void {
            $ran++;
        })->name('test:overlap')->dailyAt('11:57')->withoutOverlapping()->replayOnCatchup();
        $event->mutex->create($event);

        $this->setTick(now()->subMinutes(5));
        $this->tick();
        $this->assertSame(0, $ran);

        $event->mutex->forget($event);
        $this->tick();

        $this->assertSame(1, $ran);
        $this->assertSame(0, SchedulerCatchupLog::where('command', 'test:overlap')->sole()->exit_code);
    }

    public function test_an_outage_is_recorded_once_and_a_normal_gap_is_not(): void
    {
        $this->setTick(now()->subMinutes(5));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->assertSame(0, SchedulerOutage::count());

        $this->setTick(Carbon::parse('2026-08-17 09:00:00'));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $outage = SchedulerOutage::sole();
        $this->assertSame('2026-08-17 09:00:00', $outage->started_at->toDateTimeString());
        $this->assertSame('2026-08-17 12:00:00', $outage->ended_at->toDateTimeString());
        $this->assertSame(180, $outage->minutes);
    }

    public function test_a_command_can_ask_whether_the_server_was_down_during_a_period(): void
    {
        SchedulerOutage::record(Carbon::parse('2026-08-17 09:00:00'), Carbon::parse('2026-08-17 11:00:00'));
        $command = new class
        {
            use ResolvesScheduledTime;

            public function check(string $from, string $to): bool
            {
                return $this->outageDuring(Carbon::parse($from), Carbon::parse($to)) !== null;
            }
        };

        $this->assertTrue($command->check('2026-08-17 08:00:00', '2026-08-17 17:00:00'));
        $this->assertTrue($command->check('2026-08-17 10:00:00', '2026-08-17 10:30:00'));
        $this->assertFalse($command->check('2026-08-17 11:00:00', '2026-08-17 17:00:00'));
        $this->assertFalse($command->check('2026-08-16 08:00:00', '2026-08-16 17:00:00'));
    }

    public function test_replays_honour_when_conditions_and_environments(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = 'blocked';
        })->name('test:when')->dailyAt('11:57')->when(fn () => false)->replayOnCatchup();
        Schedule::call(function () use (&$ran): void {
            $ran[] = 'prod-only';
        })->name('test:env')->dailyAt('11:57')->environments('production')->replayOnCatchup();
        Schedule::call(function () use (&$ran): void {
            $ran[] = 'allowed';
        })->name('test:ok')->dailyAt('11:57')->when(fn () => true)->replayOnCatchup();

        $this->setTick(now()->subMinutes(5));
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame(['allowed'], $ran);
    }

    public function test_a_long_normal_run_is_credited_to_the_slot_it_started_for(): void
    {
        $task = Schedule::call(fn () => null)->name('test:slow')->everyFiveMinutes()->replayOnCatchup();

        Date::setTestNow('2026-08-17 11:55:00');
        EventFacade::dispatch(new ScheduledTaskStarting($task));
        Date::setTestNow('2026-08-17 12:01:30');
        $task->exitCode = 0;
        EventFacade::dispatch(new ScheduledTaskFinished($task, 390));

        $this->assertSame(['2026-08-17 11:55:00'], SchedulerCatchupLog::where('command', 'test:slow')->pluck('scheduled_at')->map->toDateTimeString()->all());
    }

    public function test_run_once_does_not_run_while_another_run_holds_the_period(): void
    {
        $command = $this->onceCommand();
        $ran = 0;

        $lock = Cache::lock('schedule-catchup:once:'.sha1($command::class.'|2026-08'), 60);
        $lock->get();
        $this->assertFalse($command->runOnceForPeriod('2026-08', function () use (&$ran): void {
            $ran++;
        }));
        $lock->release();

        $this->assertTrue($command->runOnceForPeriod('2026-08', function () use (&$ran): void {
            $ran++;
        }));
        $this->assertFalse($command->runOnceForPeriod('2026-08', function () use (&$ran): void {
            $ran++;
        }));
        $this->assertSame(1, $ran);
    }

    public function test_run_once_does_not_hide_a_duplicate_key_error_from_the_work_itself(): void
    {
        $command = $this->onceCommand();

        $this->expectException(UniqueConstraintViolationException::class);

        $command->runOnceForPeriod('2026-08', function (): void {
            SchedulerCommandRun::create(['command' => 'x', 'period_key' => 'p', 'ran_at' => now()]);
            SchedulerCommandRun::create(['command' => 'x', 'period_key' => 'p', 'ran_at' => now()]);
        });
    }

    public function test_prune_keeps_owed_slots_and_recent_history(): void
    {
        $row = fn (string $at, ?int $exit, int $attempts) => SchedulerCatchupLog::create([
            'command' => 'test:prune', 'source' => 'scheduled', 'scheduled_at' => $at,
            'ran_at' => $at, 'exit_code' => $exit, 'attempts' => $attempts,
        ]);
        $row('2026-01-01 00:00:00', 0, 1);
        $row('2026-01-02 00:00:00', null, 0);
        $row('2026-08-10 00:00:00', 0, 1);
        SchedulerOutage::record(Carbon::parse('2026-01-01 01:00:00'), Carbon::parse('2026-01-01 03:00:00'));

        $this->artisan('schedule-catchup:prune')->assertExitCode(0);

        $this->assertSame(['2026-01-02 00:00:00', '2026-08-10 00:00:00'], SchedulerCatchupLog::orderBy('scheduled_at')->pluck('scheduled_at')->map->toDateTimeString()->all());
        $this->assertSame(0, SchedulerOutage::count());
    }

    private function onceCommand(): object
    {
        return new class
        {
            use RecordsScheduleRun;
        };
    }
}
