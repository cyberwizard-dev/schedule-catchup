<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Feature;

use Carbon\Carbon;
use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupCompleted;
use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupFailed;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;

class ScheduleCatchupTest extends TestCase
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

    public function test_it_records_baseline_when_no_previous_tick(): void
    {
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertDatabaseHas('cyber_schedule_catchup_checkpoints', ['key' => 'last_tick']);
    }

    public function test_it_exits_early_when_gap_is_below_threshold(): void
    {
        $this->setTick(now()->subMinutes(2));

        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = now()->toDateTimeString();
        })->name('test:noop')->dailyAt('00:00')->replayOnCatchup();

        $this->artisan('schedule-catchup:run', ['--only' => 'test:noop'])->assertExitCode(0);

        $this->assertEmpty($ran);
    }

    public function test_it_replays_missed_events_without_force_after_downtime(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = now()->toDateTimeString();
        })->name('test:no-force')->dailyAt('00:00')->replayOnCatchup();

        $this->setTick(now()->subDays(2));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:no-force'])->assertExitCode(0);

        $this->assertCount(2, $ran);
        $this->assertSame(2, SchedulerCatchupLog::count());
    }

    public function test_it_replays_tagged_missed_occurrences_once(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = now()->toDateTimeString();
        })->name('test:replay-me')->dailyAt('00:00')->replayOnCatchup();

        $this->setTick(now()->subDays(2));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:replay-me'])->assertExitCode(0);

        $this->assertCount(2, $ran);
        $this->assertSame(2, SchedulerCatchupLog::count());
    }

    public function test_it_does_not_replay_untagged_events(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = now()->toDateTimeString();
        })->name('test:untagged')->dailyAt('00:00');

        $this->setTick(now()->subDays(2));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:untagged'])->assertExitCode(0);

        $this->assertEmpty($ran);
        $this->assertSame(0, SchedulerCatchupLog::count());
    }

    public function test_second_catch_up_does_not_double_replay(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = now()->toDateTimeString();
        })->name('test:dedup')->dailyAt('00:00')->replayOnCatchup();

        $this->setTick(now()->subDays(2));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:dedup'])->assertExitCode(0);
        $this->assertCount(2, $ran);

        $this->setTick(now()->subDays(2));
        $this->artisan('schedule-catchup:run', ['--only' => 'test:dedup'])->assertExitCode(0);

        $this->assertCount(2, $ran);
        $this->assertSame(2, SchedulerCatchupLog::count());
    }

    public function test_dry_run_does_not_execute_events(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = now()->toDateTimeString();
        })->name('test:dry')->dailyAt('00:00')->replayOnCatchup();

        $this->setTick(now()->subDays(2));

        $lastTick = SchedulerCatchupCheckpoint::get('last_tick');

        $this->artisan('schedule-catchup:run', ['--only' => 'test:dry', '--dry-run' => true])->assertExitCode(0);

        $this->assertEmpty($ran);
        $this->assertSame(0, SchedulerCatchupLog::count());
        $this->assertSame($lastTick, SchedulerCatchupCheckpoint::get('last_tick'));
    }

    public function test_failed_replay_withholds_checkpoint_and_is_retried(): void
    {
        $attempts = [];
        $event = Schedule::call(function () use (&$attempts): void {
            $attempts[] = now()->toDateTimeString();
            throw new RuntimeException('boom');
        })->name('test:boom')->everyMinute()->replayOnCatchup();

        $this->setTick(now()->subMinutes(10));
        $lastTick = SchedulerCatchupCheckpoint::get('last_tick');

        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $firstCount = count($attempts);

        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertGreaterThan(0, $firstCount);
        $this->assertGreaterThan($firstCount, count($attempts));
        $this->assertSame($lastTick, SchedulerCatchupCheckpoint::get('last_tick'));

        $logs = SchedulerCatchupLog::where('command', ScheduleEventKey::for($event))->get();

        $this->assertGreaterThan(0, $logs->count());
        $this->assertTrue($logs->every(fn ($log) => $log->exit_code !== 0));
        $this->assertTrue($logs->every(fn ($log) => $log->attempts === 2));
        $this->assertTrue($logs->every(fn ($log) => str_contains((string) $log->last_error, 'boom')));
        $this->assertTrue($logs->every(fn ($log) => ! $log->isSettled(3)));
    }

    public function test_retries_are_bounded_by_max_attempts(): void
    {
        config(['schedule-catchup.max_attempts' => 2]);

        $attempts = 0;
        $event = Schedule::call(function () use (&$attempts): void {
            $attempts++;
            throw new RuntimeException('boom');
        })->name('test:bounded')->dailyAt('11:57')->replayOnCatchup();

        $this->setTick(now()->subMinutes(5));
        $lastTick = SchedulerCatchupCheckpoint::get('last_tick');

        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->assertSame(1, $attempts);

        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->assertSame(2, $attempts);

        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->assertSame(2, $attempts, 'Exhausted occurrences must not be retried again.');

        $log = SchedulerCatchupLog::where('command', ScheduleEventKey::for($event))->firstOrFail();
        $this->assertSame(2, $log->attempts);
        $this->assertTrue($log->isSettled(2));
        $this->assertNotSame($lastTick, SchedulerCatchupCheckpoint::get('last_tick'), 'An exhausted occurrence must stop holding the checkpoint back.');
    }

    public function test_overlap_skip_is_not_recorded_as_success(): void
    {
        $ran = 0;
        $event = Schedule::call(function () use (&$ran): void {
            $ran++;
        })->name('test:overlap')->dailyAt('11:57')->withoutOverlapping()->replayOnCatchup();

        $event->mutex->create($event);

        $this->setTick(now()->subMinutes(5));
        $lastTick = SchedulerCatchupCheckpoint::get('last_tick');

        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame(0, $ran);
        $owed = SchedulerCatchupLog::where('command', ScheduleEventKey::for($event))->sole();
        $this->assertNull($owed->exit_code, 'An overlap skip must not retire the occurrence.');
        $this->assertSame(0, $owed->attempts);
        $this->assertFalse($owed->isSettled(3));
        $this->assertSame($lastTick, SchedulerCatchupCheckpoint::get('last_tick'));

        $event->mutex->forget($event);

        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame(1, $ran, 'The deferred occurrence must be replayed once the overlap clears.');
    }

    public function test_normal_run_is_recorded_and_suppresses_replay(): void
    {
        $ran = 0;
        Schedule::call(function () use (&$ran): void {
            $ran++;
        })->name('test:ledger')->dailyAt('11:55')->replayOnCatchup();

        $task = collect(Schedule::events())->firstWhere(fn ($event) => str_contains($event->getSummaryForDisplay(), 'test:ledger'));
        $task->exitCode = 0;

        EventFacade::dispatch(new ScheduledTaskFinished($task, 0.5));

        $this->assertDatabaseHas('cyber_schedule_catchup_logs', [
            'command' => ScheduleEventKey::for($task),
            'source' => 'scheduled',
            'exit_code' => 0,
        ]);

        $this->setTick(now()->subMinutes(10));

        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame(0, $ran, 'A slot the scheduler already ran must not be replayed.');
    }

    public function test_untagged_task_is_not_recorded_by_the_ledger(): void
    {
        Schedule::call(function (): void {})->name('test:untagged')->dailyAt('11:55');

        $task = collect(Schedule::events())->firstWhere(fn ($event) => str_contains($event->getSummaryForDisplay(), 'test:untagged'));
        $task->exitCode = 0;

        EventFacade::dispatch(new ScheduledTaskFinished($task, 0.5));

        $this->assertSame(0, SchedulerCatchupLog::count());
    }

    public function test_replayed_event_receives_scheduled_time_env(): void
    {
        $captured = null;

        Schedule::call(function () use (&$captured): void {
            $captured = getenv('SCHEDULED_EVENT_TIME');
        })->name('test:env')->dailyAt('00:00')->replayOnCatchup();

        $this->setTick(now()->subDays(2));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:env'])->assertExitCode(0);

        $this->assertNotEmpty($captured);
        $this->assertInstanceOf(Carbon::class, Carbon::parse($captured));
    }

    public function test_cap_drains_over_runs_instead_of_deadlocking(): void
    {
        $ran = [];
        Schedule::call(function () use (&$ran): void {
            $ran[] = now()->toDateTimeString();
        })->name('test:cap')->everyMinute()->replayOnCatchup();

        $this->setTick(now()->subHours(2));
        $lastTick = SchedulerCatchupCheckpoint::get('last_tick');

        $this->artisan('schedule-catchup:run', ['--only' => 'test:cap'])->assertExitCode(0);
        $this->assertSame(50, count($ran));
        $this->assertSame($lastTick, SchedulerCatchupCheckpoint::get('last_tick'));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:cap'])->assertExitCode(0);
        $this->assertSame(100, count($ran));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:cap'])->assertExitCode(0);
        $this->assertSame(119, count($ran));
    }

    public function test_it_dispatches_completed_event_and_runs_replay_callback(): void
    {
        EventFacade::fake([ScheduleCatchupCompleted::class]);

        $callbackCount = null;

        config(['schedule-catchup.on_replay' => function (int $count, array $details) use (&$callbackCount): void {
            $callbackCount = $count;
        }]);

        Schedule::call(fn () => null)->name('test:completed')->dailyAt('00:00')->replayOnCatchup();

        $this->setTick(now()->subDays(2));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:completed'])->assertExitCode(0);

        EventFacade::assertDispatched(ScheduleCatchupCompleted::class, fn ($event) => $event->replayed === 2 && count($event->details) === 2);

        $this->assertSame(2, $callbackCount);
    }

    public function test_it_dispatches_failed_event_and_runs_failure_callback(): void
    {
        EventFacade::fake([ScheduleCatchupFailed::class]);

        $callbackFailures = null;

        config(['schedule-catchup.on_failure' => function (array $newFailures, array $exhausted) use (&$callbackFailures): void {
            $callbackFailures = $newFailures;
        }]);

        Schedule::call(function (): void {
            throw new RuntimeException('boom');
        })->name('test:failed-event')->everyMinute()->replayOnCatchup();

        $this->setTick(now()->subMinutes(10));

        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        EventFacade::assertDispatched(ScheduleCatchupFailed::class, fn ($event) => $event->newFailures !== []);

        $this->assertNotEmpty($callbackFailures);
    }

    private function setTick(Carbon $at): void
    {
        SchedulerCatchupCheckpoint::set('last_tick', $at->toDateTimeString());
    }
}
