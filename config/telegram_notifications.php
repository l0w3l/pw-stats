<?php

declare(strict_types=1);

return [
    'delivery' => [
        'batch_size' => (int) env('TELEGRAM_NOTIFICATION_BATCH_SIZE', 25),
        'claim_ttl_seconds' => (int) env('TELEGRAM_NOTIFICATION_CLAIM_TTL_SECONDS', 180),
        'queue' => env('TELEGRAM_NOTIFICATION_QUEUE', 'default'),
        'max_attempts' => (int) env('TELEGRAM_NOTIFICATION_MAX_ATTEMPTS', 5),
        'backoff_seconds' => array_map(
            'intval',
            explode(',', (string) env('TELEGRAM_NOTIFICATION_BACKOFF_SECONDS', '60,300,900,3600')),
        ),
        'max_backoff_seconds' => (int) env('TELEGRAM_NOTIFICATION_MAX_BACKOFF_SECONDS', 3600),
        'default_retry_after_seconds' => (int) env('TELEGRAM_NOTIFICATION_DEFAULT_RETRY_AFTER_SECONDS', 60),
        // "disable" prevents known-permanent Telegram 4xx errors from recurring daily.
        'permanent_failure_policy' => env('TELEGRAM_NOTIFICATION_PERMANENT_FAILURE_POLICY', 'disable'),
    ],

    'rate_limit' => [
        // Must point to a shared, lock-capable cache in multi-worker production.
        'cache_store' => env('TELEGRAM_NOTIFICATION_CACHE_STORE', env('CACHE_STORE', 'database')),
        'global_per_second' => (int) env('TELEGRAM_NOTIFICATION_GLOBAL_PER_SECOND', 25),
        'same_chat_interval_ms' => (int) env('TELEGRAM_NOTIFICATION_SAME_CHAT_INTERVAL_MS', 1000),
    ],

    'inbound_rate_limit' => [
        // Use a shared store (normally Redis) so all webhook/worker processes
        // enforce the same actor + chat budget.
        'cache_store' => env('TELEGRAM_INBOUND_CACHE_STORE', env('CACHE_STORE', 'database')),
        'max_attempts' => (int) env('TELEGRAM_INBOUND_MAX_ATTEMPTS', 10),
        'decay_seconds' => (int) env('TELEGRAM_INBOUND_DECAY_SECONDS', 60),
    ],
];
