<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Feature;

use Carbon\Carbon;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceDeferred;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceFailed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceReplayed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOutageRecorded;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\ScheduleCatchup\Support\ScheduleReplayRegistry;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;

class OccurrenceEventsTest extends TestCase
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

    public function test_it_dispatches_an_event_for_each_replayed_occurrence(): void
    {
        EventFacade::fake([ScheduleOccurrenceReplayed::class]);

        Schedule::call(function (): void {
            usleep(1000);
        })->name('test:replayed')->dailyAt('00:00')->replayOnCatchup();

        $this->setTick(now()->subDays(2));

        $this->artisan('schedule-catchup:run', ['--only' => 'test:replayed'])->assertExitCode(0);

        EventFacade::assertDispatchedTimes(ScheduleOccurrenceReplayed::class, 2);
        EventFacade::assertDispatched(ScheduleOccurrenceReplayed::class, fn (ScheduleOccurrenceReplayed $replayed) => $replayed->exitCode === 0
            && $replayed->runtime > 0
            && $replayed->scheduledAt->toDateTimeString() === '2026-08-17 00:00:00'
            && ScheduleReplayRegistry::isTagged($replayed->event));
    }

    public function test_it_dispatches_a_failed_event_on_every_attempt_and_flags_exhaustion(): void
    {
        config(['schedule-catchup.max_attempts' => 2]);
        EventFacade::fake([ScheduleOccurrenceFailed::class]);

        Schedule::call(function (): void {
            throw new RuntimeException('boom');
        })->name('test:failed-occurrence')->dailyAt('11:57')->replayOnCatchup();

        $this->setTick(now()->subMinutes(5));

        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        EventFacade::assertDispatchedTimes(ScheduleOccurrenceFailed::class, 2);
        EventFacade::assertDispatched(ScheduleOccurrenceFailed::class, fn (ScheduleOccurrenceFailed $failed) => $failed->attempts === 1
            && ! $failed->exhausted
            && $failed->exitCode === null
            && $failed->error === 'boom'
            && $failed->scheduledAt->toDateTimeString() === '2026-08-17 11:57:00');
        EventFacade::assertDispatched(ScheduleOccurrenceFailed::class, fn (ScheduleOccurrenceFailed $failed) => $failed->attempts === 2 && $failed->exhausted);
    }

    public function test_it_dispatches_a_deferred_event_when_the_command_is_already_running(): void
    {
        EventFacade::fake([ScheduleOccurrenceDeferred::class]);

        $event = Schedule::call(fn () => null)->name('test:deferred')->dailyAt('11:57')->withoutOverlapping()->replayOnCatchup();
        $event->mutex->create($event);

        $this->setTick(now()->subMinutes(5));

        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        EventFacade::assertDispatched(ScheduleOccurrenceDeferred::class, fn (ScheduleOccurrenceDeferred $deferred) => $deferred->event === $event
            && $deferred->scheduledAt->toDateTimeString() === '2026-08-17 11:57:00');

        $event->mutex->forget($event);
    }

    public function test_it_dispatches_an_outage_event_only_when_the_outage_is_created(): void
    {
        EventFacade::fake([ScheduleOutageRecorded::class]);

        $this->setTick(Carbon::parse('2026-08-17 09:00:00'));

        $this->artisan('schedule-catchup:run')->assertExitCode(0);
        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        EventFacade::assertDispatchedTimes(ScheduleOutageRecorded::class, 1);
        EventFacade::assertDispatched(ScheduleOutageRecorded::class, fn (ScheduleOutageRecorded $recorded) => $recorded->outage->minutes === 180
            && $recorded->outage->started_at->toDateTimeString() === '2026-08-17 09:00:00');
    }
}
