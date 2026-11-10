<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Feature;

use Carbon\Carbon;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupLog;
use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;

class QueryEfficiencyTest extends TestCase
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

    public function test_the_number_of_selects_does_not_grow_with_the_number_of_tagged_events(): void
    {
        $this->settledEvents('test:few-', 5);
        $this->settledEvents('test:many-', 20);

        $few = $this->selectsForRun(now()->subDays(2), ['--only' => 'test:few-']);
        $many = $this->selectsForRun(now()->subDays(2), ['--only' => 'test:many-']);

        $this->assertSame($few, $many);
    }

    public function test_a_run_reads_the_ledger_and_checkpoints_in_a_fixed_number_of_selects(): void
    {
        $this->settledEvents('test:settled-', 20);

        // last_tick and cursors, latest slot per command, settled slots, pending slots,
        // plus the outage and last_tick writes looking up their rows.
        $this->assertSame(6, $this->selectsForRun(now()->subDays(2)));
    }

    public function test_the_below_threshold_exit_does_not_query_per_event(): void
    {
        $this->settledEvents('test:few-', 5);
        $this->settledEvents('test:many-', 20);

        $few = $this->selectsForRun(now()->subMinute(), ['--only' => 'test:few-']);
        $many = $this->selectsForRun(now()->subMinute(), ['--only' => 'test:many-']);

        $this->assertSame($few, $many);
    }

    public function test_replay_decisions_are_unchanged_for_a_mixed_scenario(): void
    {
        config(['schedule-catchup.max_occurrences' => 5]);

        $ran = [];
        $record = function (string $name) use (&$ran): void {
            $ran[] = $name.'@'.getenv('SCHEDULED_EVENT_TIME');
        };

        $this->setTick(Carbon::parse('2026-08-15 12:00:00'));

        // Both slots already ran normally.
        Schedule::call(fn () => $record('settled'))->name('test:settled')->dailyAt('00:00')->replayOnCatchup();
        $this->log('test:settled', '2026-08-16 00:00:00', 0, 1);
        $this->log('test:settled', '2026-08-17 00:00:00', 0, 1);

        // Deferred before the last tick: still owed although the walk starts later.
        Schedule::call(fn () => $record('deferred'))->name('test:deferred')->dailyAt('00:00')->replayOnCatchup();
        $this->log('test:deferred', '2026-08-14 00:00:00', null, 0);

        // Failed once before the last tick and succeeds on retry.
        Schedule::call(fn () => $record('retried'))->name('test:retried')->dailyAt('00:00')->replayOnCatchup();
        $this->log('test:retried', '2026-08-13 00:00:00', 1, 1);

        // Failed twice and fails again: becomes exhausted.
        Schedule::call(function () use ($record): void {
            $record('failing');
            throw new RuntimeException('boom');
        })->name('test:failing')->dailyAt('00:00')->replayOnCatchup();
        $this->log('test:failing', '2026-08-16 00:00:00', 1, 2);

        // Exhausted already: never retried; the other slot still runs.
        Schedule::call(fn () => $record('exhausted'))->name('test:exhausted')->dailyAt('00:00')->replayOnCatchup();
        $this->log('test:exhausted', '2026-08-16 00:00:00', 1, 3);

        // A capped walk from an earlier run resumes from its cursor and stays capped.
        Schedule::call(fn () => $record('capped'))->name('test:capped')->everyMinute()->replayOnCatchup();
        SchedulerCatchupCheckpoint::set('cursor:test:capped', '2026-08-17 11:30:00');

        // A stale cursor for an event that has caught up is cleared.
        Schedule::call(fn () => $record('stale'))->name('test:stale')->dailyAt('11:58')->replayOnCatchup();
        SchedulerCatchupCheckpoint::set('cursor:test:stale', '2026-08-17 11:50:00');

        // The ledger is ahead of the last tick, so the walk starts from its latest slot.
        Schedule::call(fn () => $record('ahead'))->name('test:ahead')->hourlyAt(0)->replayOnCatchup();
        $this->log('test:ahead', '2026-08-17 09:00:00', 0, 1);

        // Two events sharing one ledger key: the second resumes from the slot the first
        // just logged, so its 2026-08-16 06:00 slot is stepped over (long-standing behaviour).
        Schedule::call(fn () => $record('shared-a'))->name('test:shared')->dailyAt('00:00')->replayOnCatchup();
        Schedule::call(fn () => $record('shared-b'))->name('test:shared')->dailyAt('06:00')->replayOnCatchup();

        $this->artisan('schedule-catchup:run')->assertExitCode(0);

        $this->assertSame([
            'deferred@2026-08-14 00:00:00',
            'deferred@2026-08-16 00:00:00',
            'deferred@2026-08-17 00:00:00',
            'retried@2026-08-13 00:00:00',
            'retried@2026-08-16 00:00:00',
            'retried@2026-08-17 00:00:00',
            'failing@2026-08-16 00:00:00',
            'failing@2026-08-17 00:00:00',
            'exhausted@2026-08-17 00:00:00',
            'capped@2026-08-17 11:31:00',
            'capped@2026-08-17 11:32:00',
            'capped@2026-08-17 11:33:00',
            'capped@2026-08-17 11:34:00',
            'capped@2026-08-17 11:35:00',
            'stale@2026-08-17 11:58:00',
            'ahead@2026-08-17 10:00:00',
            'ahead@2026-08-17 11:00:00',
            'shared-a@2026-08-16 00:00:00',
            'shared-a@2026-08-17 00:00:00',
            'shared-b@2026-08-17 06:00:00',
        ], $ran);

        $this->assertSame('2026-08-17 11:35:00', SchedulerCatchupCheckpoint::get('cursor:test:capped'));
        $this->assertNull(SchedulerCatchupCheckpoint::get('cursor:test:stale'));
        $this->assertSame('2026-08-15 12:00:00', SchedulerCatchupCheckpoint::get('last_tick'), 'Failures hold the checkpoint back.');

        $failing = SchedulerCatchupLog::where('command', 'test:failing')->orderBy('scheduled_at')->get();
        $this->assertSame([3, 1], $failing->pluck('attempts')->all());
        $this->assertSame(0, SchedulerCatchupLog::where('command', 'test:retried')->where('exit_code', '!=', 0)->count());
    }

    private function settledEvents(string $prefix, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Schedule::call(fn () => null)->name($prefix.$i)->dailyAt('00:00')->replayOnCatchup();
            $this->log($prefix.$i, '2026-08-16 00:00:00', 0, 1);
            $this->log($prefix.$i, '2026-08-17 00:00:00', 0, 1);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function selectsForRun(Carbon $lastTick, array $options = []): int
    {
        $this->setTick($lastTick);

        return $this->countSelects(fn () => $this->artisan('schedule-catchup:run', $options)->assertExitCode(0));
    }

    private function countSelects(callable $callback): int
    {
        $selects = 0;

        DB::listen(function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects++;
            }
        });

        $callback();

        return $selects;
    }

    private function log(string $command, string $scheduledAt, ?int $exitCode, int $attempts): void
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
