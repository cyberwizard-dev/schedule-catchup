# Changelog

Notable changes to `cyberwizard/schedule-catchup` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
