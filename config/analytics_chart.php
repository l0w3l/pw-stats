<?php

declare(strict_types=1);

return [
    'period_limits' => [
        'day' => (int) env('ANALYTICS_CHART_DAY_PERIODS', 30),
        'week' => (int) env('ANALYTICS_CHART_WEEK_PERIODS', 12),
        'month' => (int) env('ANALYTICS_CHART_MONTH_PERIODS', 12),
    ],
    'width' => (int) env('ANALYTICS_CHART_WIDTH', 1200),
    'height' => (int) env('ANALYTICS_CHART_HEIGHT', 675),
    'cache_path' => storage_path('app/private/analytics-charts'),
    'cache_retention_hours' => (int) env('ANALYTICS_CHART_CACHE_RETENTION_HOURS', 168),
    'cache_version' => env('ANALYTICS_CHART_CACHE_VERSION', '1'),
    'lock_seconds' => (int) env('ANALYTICS_CHART_LOCK_SECONDS', 30),
    'lock_wait_seconds' => (int) env('ANALYTICS_CHART_LOCK_WAIT_SECONDS', 5),
];
