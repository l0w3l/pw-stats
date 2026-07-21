<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'flaresolverr' => [
        'base_uri' => env('FLARE_SOLVER_URL', 'http://flaresolverr:8191/'),
    ],

    'access-token' => [
        'base_uri' => env('ACCESS_TOKEN_BASE_URI', 'http://access-token:8000/'),
        'web_app_data_cache_ttl_seconds' => (int) env('TELEGRAM_WEB_APP_DATA_CACHE_TTL_SECONDS', 3600),
    ],

    'http' => [
        'connect_timeout_seconds' => (int) env('HTTP_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (int) env('HTTP_TIMEOUT_SECONDS', 20),
        'retry_attempts' => (int) env('HTTP_RETRY_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('HTTP_RETRY_DELAY_MS', 250),
        'retry_jitter_ms' => (int) env('HTTP_RETRY_JITTER_MS', 250),
        'retry_max_delay_seconds' => (int) env('HTTP_RETRY_MAX_DELAY_SECONDS', 30),
        'auth_lock_seconds' => (int) env('HTTP_AUTH_LOCK_SECONDS', 300),
        'auth_lock_wait_seconds' => (int) env('HTTP_AUTH_LOCK_WAIT_SECONDS', 300),
    ],

    'pixel-world' => [
        'base_uri' => env('PIXEL_WORLD_BASE_URI', 'https://pw.game/api/v2/'),
        'requests_per_minute' => (int) env('PIXEL_WORLD_REQUESTS_PER_MINUTE', 120),
        'access_token_cache_ttl_seconds' => (int) env('PIXEL_WORLD_ACCESS_TOKEN_CACHE_TTL_SECONDS', 3600),
        'token_cache_store' => env('PIXEL_WORLD_TOKEN_CACHE_STORE'),
        'leaderboard_page_limit' => (int) env('PIXEL_WORLD_LEADERBOARD_PAGE_LIMIT', 50),
        'collection_lock_ttl_seconds' => (int) env('PIXEL_WORLD_COLLECTION_LOCK_TTL_SECONDS', 7200),
        'reconciliation_passes' => (int) env('PIXEL_WORLD_RECONCILIATION_PASSES', 3),
    ],
];
