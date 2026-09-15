# Schedule Catchup

Laravel's scheduler doesn't run anything while your server is down, and when it comes back those slots are simply gone. This package keeps a record of every scheduler tick, works out which cron slots were skipped during the outage, and runs them.

Requires PHP 8.2+. Works with Laravel 11, 12, and 13.

## Install

```bash
composer require cyberwizard/schedule-catchup
php artisan migrate
```

The service provider is discovered automatically. It registers `schedule-catchup:run` and `schedule-catchup:heartbeat`, and schedules both every five minutes with `withoutOverlapping()` and `onOneServer()`.

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=schedule-catchup-config
```

## Tag the events you care about

Only tagged events get replayed. Add `replayOnCatchup()` to the ones where a missed run actually matters:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:process-unlimited')
    ->dailyAt('00:00')
    ->onOneServer()
    ->replayOnCatchup();
```

Leave high-frequency pollers alone. Replaying a sync that already runs every five minutes is usually not what you want.

## How it works

`schedule-catchup:heartbeat` writes the current time to a checkpoint table every five minutes. That timestamp is the last moment the scheduler is known to have been running.

A listener on `ScheduledTaskFinished` records each normal run of a tagged event against the cron slot it satisfied. That ledger is what lets the package tell a missed slot apart from one that already ran.

`schedule-catchup:run` walks each tagged event's cron expression from the checkpoint up to now, drops the slots already in the ledger, and runs what's left oldest-first. The checkpoint only moves forward once nothing is pending, so a failure partway through doesn't lose the rest of the window and doesn't cause a double run on the next pass.

A failed occurrence keeps an attempt count and is retried on later runs. Once it hits `max_attempts` it's marked settled, which stops a command that fails every single time from holding the checkpoint back forever. If a run is skipped because the command was already running, it counts as deferred rather than successful, and gets picked up again once the overlap clears.

## Configuration

```php
// config/schedule-catchup.php
return [
    'min_gap_minutes' => (int) env('SCHEDULE_CATCHUP_MIN_GAP_MINUTES', 2),
    'max_attempts' => (int) env('SCHEDULE_CATCHUP_MAX_ATTEMPTS', 3),
    'max_occurrences' => (int) env('SCHEDULE_CATCHUP_MAX_OCCURRENCES', 50),
    'cache_driver' => env('SCHEDULE_CATCHUP_CACHE_DRIVER'),
    'table_prefix' => env('SCHEDULE_CATCHUP_TABLE_PREFIX', 'cyber_'),
    'on_replay' => null,
    'on_failure' => null,
];
```

| Option | What it does |
|--------|--------------|
| `min_gap_minutes` | Downtime below this is ignored. |
| `max_attempts` | Retries before a failing occurrence is given up on. |
| `max_occurrences` | Cap on occurrences replayed per event per run. The remainder drains over later runs. |
| `cache_driver` | Cache store for the catch-up lock. `null` uses your default. |
| `table_prefix` | Prefix for the package tables. |
| `on_replay` | Callback run after successful replays. |
| `on_failure` | Callback run after new failures or give-ups. |

## Events

```php
use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupCompleted;
use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupFailed;

Event::listen(ScheduleCatchupCompleted::class, function (ScheduleCatchupCompleted $event): void {
    // $event->replayed, $event->skipped, $event->deferred, $event->details
});

Event::listen(ScheduleCatchupFailed::class, function (ScheduleCatchupFailed $event): void {
    // $event->newFailures, $event->exhausted, $event->maxAttempts
});
```

If you'd rather not register a listener, set the same behaviour in config:

```php
'on_replay' => fn (int $count, array $details) => /* ... */,
'on_failure' => fn (array $newFailures, array $exhausted) => /* ... */,
```

The order is: replays finish, then `ScheduleCatchupCompleted`, then `on_replay`. If there were failures, `ScheduleCatchupFailed` and `on_failure` follow.

## Commands

```bash
# Replay missed events
php artisan schedule-catchup:run

# Report what would be replayed, without running anything
php artisan schedule-catchup:run --dry-run

# Limit to events whose summary contains a substring
php artisan schedule-catchup:run --only=subscriptions

# Replay even when the gap is under the threshold
php artisan schedule-catchup:run --force

# Record the current time as the last scheduler tick
php artisan schedule-catchup:heartbeat
```

## Traits

`ResolvesScheduledTime` is for commands that need to know which slot they're standing in for. During a replay that's the missed occurrence, not the current clock time:

```php
use Cyberwizard\ScheduleCatchup\Traits\ResolvesScheduledTime;

class MyCommand extends Command
{
    use ResolvesScheduledTime;

    public function handle()
    {
        $date = $this->scheduledFor();
    }
}
```

`RecordsScheduleRun` runs a callback at most once per period, including across concurrent workers:

```php
use Cyberwizard\ScheduleCatchup\Traits\RecordsScheduleRun;

class MyCommand extends Command
{
    use RecordsScheduleRun;

    public function handle()
    {
        $this->runOnceForPeriod('key-'.now()->format('Y-m'), function (): void {
            // ...
        });
    }
}
```

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
