<?php

declare(strict_types=1);

return [
    // Minute samples remain the source used by current consumers.
    'player_totals_raw_days' => (int) env('PIXEL_WORLD_PLAYER_TOTAL_RAW_RETENTION_DAYS', 30),

    // Hourly summaries keep first/last and distribution data for longer-term use.
    'player_totals_hourly_days' => (int) env('PIXEL_WORLD_PLAYER_TOTAL_HOURLY_RETENTION_DAYS', 730),

    // One transaction is used per hour; this limits work and catch-up per invocation.
    'player_totals_hours_per_run' => (int) env('PIXEL_WORLD_PLAYER_TOTAL_RETENTION_HOURS_PER_RUN', 24),

    // Period metadata and totals are never removed. Only detailed leaderboard entries beyond
    // these newest-per-range windows are eligible for pruning.
    'leaderboard_detailed_periods' => [
        'day' => (int) env('PIXEL_WORLD_LEADERBOARD_DAY_DETAILED_PERIODS', 30),
        'week' => (int) env('PIXEL_WORLD_LEADERBOARD_WEEK_DETAILED_PERIODS', 12),
        'month' => (int) env('PIXEL_WORLD_LEADERBOARD_MONTH_DETAILED_PERIODS', 12),
    ],

    // Every run deletes at most this many entry rows. The latest two periods of every range
    // are protected regardless of the configured detailed-period windows above.
    'leaderboard_entry_chunk_size' => (int) env('PIXEL_WORLD_LEADERBOARD_ENTRY_RETENTION_CHUNK_SIZE', 5000),
];
