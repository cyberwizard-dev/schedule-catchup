# Changelog

Notable changes to `cyberwizard/schedule-catchup` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.2] - Unreleased

### Changed

- Fewer queries per run. `schedule-catchup:run` used to read the checkpoints and the ledger separately for every tagged event (five selects each: two checkpoint lookups, the latest slot, settled slots and pending slots). It now loads the last tick and every cursor in one query and the ledger for all tagged events in three (latest slot per command, settled slots, pending slots), so the number of reads no longer grows with the number of tagged events. With 20 tagged events and nothing to replay a run went from 103 selects to 6. Per-occurrence writes are unchanged, and replay decisions are the same as before.

## [1.2.1] - 2026-11-10

### Fixed

- Replays crashed on Laravel 12 and older with `Undefined property: CallbackEvent::$skippedBecauseOverlapping`. That flag only exists on Laravel 13; older versions now detect an overlapping replay by checking the event's mutex before running it.

## [1.2.0] - 2026-11-10

### Added

- Per-occurrence events so listeners can report each replay outcome: `ScheduleOccurrenceReplayed` (exit code and runtime in seconds), `ScheduleOccurrenceFailed` (fired on every failed attempt, with attempt count and an `exhausted` flag once `max_attempts` is reached) and `ScheduleOccurrenceDeferred`.
- `ScheduleOutageRecorded`, fired when a new outage row is created.

## [1.1.0] - 2026-10-31

### Added

- Outage tracking: a gap of at least `outage_after_minutes` (default 10) between ticks is recorded in a new `schedule_catchup_outages` table. `ResolvesScheduledTime::outageDuring($from, $to)` tells a command whether the server was down during a period.
- `schedule-catchup:prune`, scheduled daily, deletes settled run records and outages older than `prune_after_days` (default 90). Pending records are kept.
- `lock_seconds` config for the catch-up run's lock (was a fixed 10 minutes; now defaults to an hour).

### Fixed

- Missed occurrences could be lost: the heartbeat moves the checkpoint every five minutes, so occurrences past the `max_occurrences` cap, or deferred because the command was already running, were never replayed. A capped event now keeps its own cursor, and a deferred occurrence is logged as pending.
- `runOnceForPeriod()` could do the work twice when two runs overlapped, swallowed duplicate-key errors thrown by the work itself, and did not recognise Postgres duplicate-key errors. A lock now covers the whole check, work and record.
- A long normal run was credited to the slot it finished in, not the one it started for, so its real slot could be replayed.
- Replays ignored `->when()` / `->skip()` conditions and `->environments()`.
- `runInBackground()` events were recorded as successful the moment a replay started them. Replays now run in the foreground.

### Changed

- The catch-up run only loads ledger rows inside the window it is checking, not the event's whole history.

## [1.0.1] - 2026-09-15

### Changed

- Expanded the README with requirements, configuration, events, commands, traits, and a table reference.
- Added a changelog.

## [1.0.0] - 2026-09-15

### Added

- `schedule-catchup:run` command that replays scheduled events missed during downtime.
- `schedule-catchup:heartbeat` command that records the last scheduler tick.
- Checkpoint, run ledger, and per-period run tables, with a configurable prefix.
- Bounded retries, deferred overlap handling, and a per-event occurrence cap.
- `ScheduleCatchupCompleted` and `ScheduleCatchupFailed` events, plus `on_replay` and `on_failure` config callbacks.
- `ResolvesScheduledTime` and `RecordsScheduleRun` traits.
