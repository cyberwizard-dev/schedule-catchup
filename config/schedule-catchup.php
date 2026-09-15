<?php

return [
    'min_gap_minutes' => (int) env('SCHEDULE_CATCHUP_MIN_GAP_MINUTES', 2),

    'max_attempts' => (int) env('SCHEDULE_CATCHUP_MAX_ATTEMPTS', 3),

    'max_occurrences' => (int) env('SCHEDULE_CATCHUP_MAX_OCCURRENCES', 50),

    'cache_driver' => env('SCHEDULE_CATCHUP_CACHE_DRIVER'),

    'table_prefix' => env('SCHEDULE_CATCHUP_TABLE_PREFIX', 'cyber_'),

    'on_replay' => null,

    'on_failure' => null,
];
