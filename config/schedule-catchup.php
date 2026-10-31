<?php

return [
    'min_gap_minutes' => (int) env('SCHEDULE_CATCHUP_MIN_GAP_MINUTES', 2),

    'max_attempts' => (int) env('SCHEDULE_CATCHUP_MAX_ATTEMPTS', 3),

    'max_occurrences' => (int) env('SCHEDULE_CATCHUP_MAX_OCCURRENCES', 50),

    // A gap between scheduler ticks at least this long is recorded as an outage.
    'outage_after_minutes' => (int) env('SCHEDULE_CATCHUP_OUTAGE_AFTER_MINUTES', 10),

    // Settled run records and outages older than this are pruned daily (0 keeps them).
    'prune_after_days' => (int) env('SCHEDULE_CATCHUP_PRUNE_AFTER_DAYS', 90),

    'lock_seconds' => (int) env('SCHEDULE_CATCHUP_LOCK_SECONDS', 3600),

    'cache_driver' => env('SCHEDULE_CATCHUP_CACHE_DRIVER'),

    'table_prefix' => env('SCHEDULE_CATCHUP_TABLE_PREFIX', 'cyber_'),

    'on_replay' => null,

    'on_failure' => null,
];
