<?php

use App\Services\Http\HttpRetryPolicy;
use App\Services\Telegram\TelegramWebAppDataClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('it caches Telegram web app data until a refresh is requested', function () {
    config()->set('cache.default', 'array');
    config()->set('services.http.retry_attempts', 1);
    config()->set('services.access-token.base_uri', 'http://access-token:8000/');
    Cache::flush();

    Http::fake([
        'http://access-token:8000/*' => Http::sequence()
            ->push(telegramWebAppDataResponse('first-auth-date'))
            ->push(telegramWebAppDataResponse('second-auth-date')),
    ]);

    $client = new TelegramWebAppDataClient(new HttpRetryPolicy);

    expect($client->get('pixelworld'))->toContain('auth_date=first-auth-date')
        ->and($client->get('pixelworld'))->toContain('auth_date=first-auth-date')
        ->and($client->get('pixelworld', refresh: true))->toContain('auth_date=second-auth-date');

    Http::assertSentCount(2);
});

function telegramWebAppDataResponse(string $authDate): array
{
    return [
        'flat' => [
            'user' => '{"id":1}',
            'chat_instance' => 'chat-instance',
            'chat_type' => 'private',
            'auth_date' => $authDate,
            'signature' => 'signature',
            'hash' => 'hash',
        ],
    ];
}
