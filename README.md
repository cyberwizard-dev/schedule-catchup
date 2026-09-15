# Schedule Catchup

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cyberwizard/schedule-catchup.svg?style=flat-square)](https://packagist.org/packages/cyberwizard/schedule-catchup)
[![Total Downloads](https://img.shields.io/packagist/dt/cyberwizard/schedule-catchup.svg?style=flat-square)](https://packagist.org/packages/cyberwizard/schedule-catchup)
[![License](https://img.shields.io/github/license/cyberwizard-dev/schedule-catchup.svg?style=flat-square)](LICENSE)

Laravel's scheduler does nothing while your server is down. When it comes back, the slots it missed are simply gone, and nothing in the framework goes looking for them. This package does.

It records every scheduler tick, keeps a ledger of which cron slots actually ran, and replays the ones that were skipped while the app was offline. Replayed events run once, oldest first, with retries bounded so a command that fails every time can't block everything behind it.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Tagging events](#tagging-events)
- [How it works](#how-it-works)
- [Configuration](#configuration)
- [Events and callbacks](#events-and-callbacks)
- [Commands](#commands)
- [Traits](#traits)
- [Tables](#tables)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12, or 13

## Installation

```bash
composer require cyberwizard/schedule-catchup
php artisan migrate
```

The service provider is discovered automatically. It registers `schedule-catchup:run` and `schedule-catchup:heartbeat`, and puts both on a five-minute schedule with `withoutOverlapping()` and `onOneServer()`.

If you want to change the defaults, publish the config file:

```bash
php artisan vendor:publish --tag=schedule-catchup-config
```

## Tagging events

Only tagged events get replayed. Add `replayOnCatchup()` to the events where a missed run actually matters:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:process-unlimited')
    ->dailyAt('00:00')
    ->onOneServer()
    ->replayOnCatchup();
```

Leave high-frequency pollers alone. Replaying a sync that already runs every five minutes is rarely what you want, and tagging it just gives the catch-up run more work to skip.

## How it works

`schedule-catchup:heartbeat` writes the current time to a checkpoint table every five minutes. That timestamp is the last moment the scheduler is known to have been running.

A listener on `ScheduledTaskFinished` records each normal run of a tagged event against the cron slot it satisfied. That ledger is what lets the package tell a missed slot apart from one that already ran, so a restart can't trigger work the scheduler had already done.

When `schedule-catchup:run` fires, it walks each tagged event's cron expression from the checkpoint up to now, drops the slots already in the ledger, and runs what's left oldest-first. The checkpoint only moves forward once nothing is pending, so a failure partway through doesn't lose the rest of the window and doesn't cause a double run on the next pass.

A failed occurrence keeps an attempt count and is retried on later runs. Once it hits `max_attempts` it's marked settled, which stops a command that fails every single time from holding the checkpoint back forever. If a run is skipped because the command was already running, it counts as deferred rather than successful, and gets picked up again once the overlap clears.

There's also a per-event cap (`max_occurrences`) on how much gets replayed in one pass. If a long outage produces more occurrences than the cap, the rest drain over subsequent runs instead of one run trying to do everything at once.

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
| `min_gap_minutes` | Downtime shorter than this is ignored. |
| `max_attempts` | Retries before a failing occurrence is given up on. |
| `max_occurrences` | Cap on occurrences replayed per event per run. The remainder drains over later runs. |
| `cache_driver` | Cache store for the catch-up lock. `null` uses your default. |
| `table_prefix` | Prefix applied to the package tables. |
| `on_replay` | Callback run after successful replays. |
| `on_failure` | Callback run after new failures or give-ups. |

## Events and callbacks

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

If you'd rather not register a listener, the same two hooks can be set in config:

```php
'on_replay' => fn (int $count, array $details) => /* ... */,
'on_failure' => fn (array $newFailures, array $exhausted) => /* ... */,
```

Order of operations is: replays finish, then `ScheduleCatchupCompleted`, then `on_replay`. If anything failed, `ScheduleCatchupFailed` and `on_failure` follow.

`ScheduleCatchupFailed` only fires for new failures and final give-ups. Intermediate retries stay quiet, so one broken command doesn't turn into an alert every five minutes.

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

`--only` does not advance the checkpoint, which makes it safe for a one-off replay of a single event.

## Traits

`ResolvesScheduledTime` is for commands that need to know which slot they're standing in for. During a replay that's the missed occurrence, not the current clock time, so month and day arithmetic lands in the period the run was meant to cover:

```php
use Cyberwizard\ScheduleCatchup\Traits\ResolvesScheduledTime;

class MyCommand extends Command
{
    use ResolvesScheduledTime;

    public function handle()
    {
        $date = $this->scheduledFor();

        if ($this->isCatchupReplay()) {
            // ...
        }
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

## Tables

The package creates three tables, prefixed with `cyber_` unless you change `table_prefix`:

- `cyber_schedule_catchup_checkpoints` holds the `last_tick` timestamp.
- `cyber_schedule_catchup_logs` is the run ledger: one row per `(command, scheduled_at)`, with the source (`catchup` or `scheduled`), exit code, attempt count, and last error.
- `cyber_schedule_command_runs` backs the `RecordsScheduleRun` trait, unique on `(command, period_key)`.

## Testing

```bash
composer test
```

The suite runs on [Orchestra Testbench](https://packages.tools/testbench) with an in-memory SQLite database, so there's no setup beyond `composer install`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

Pull requests are welcome. Run `composer test` before opening one, and keep the change scoped to a single concern.

## Security

If you find a security issue, report it through GitHub's private security advisories rather than a public issue.

## License

MIT. See [LICENSE](LICENSE).
