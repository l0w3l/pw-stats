<?php

declare(strict_types=1);

return [
    'rate_limit' => [
        // Must point to a shared, lock-capable cache in multi-worker production.
        'cache_store' => env('TELEGRAM_NOTIFICATION_CACHE_STORE', env('CACHE_STORE', 'database')),
        'global_per_second' => (int) env('TELEGRAM_NOTIFICATION_GLOBAL_PER_SECOND', 25),
        'same_chat_interval_ms' => (int) env('TELEGRAM_NOTIFICATION_SAME_CHAT_INTERVAL_MS', 1000),
    ],
];
