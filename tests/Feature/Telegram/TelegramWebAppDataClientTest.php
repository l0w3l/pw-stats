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

    expect($client->get('pixelworld'))->toContain('auth_date=first-auth-date');

    $this->travel(10)->years();

    expect($client->get('pixelworld'))->toContain('auth_date=first-auth-date')
        ->and($client->get('pixelworld', refresh: true))->toContain('auth_date=second-auth-date')
        ->and($client->get('pixelworld'))->toContain('auth_date=second-auth-date');

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization'));
});

test('it preserves extra signed fields and their exact encoding', function () {
    config()->set('cache.default', 'array');
    config()->set('services.http.retry_attempts', 1);
    config()->set('services.access-token.base_uri', 'http://access-token:8000/');
    Cache::flush();

    $initData = telegramWebAppData('123').'&query_id=AAE%2Bunchanged&extra_signed=a%20b';
    Http::fake(['http://access-token:8000/*' => Http::response(['decoded' => $initData])]);

    $result = (new TelegramWebAppDataClient(new HttpRetryPolicy))->get('pixelworld');

    expect($result)->toBe($initData);
    Http::assertSent(fn ($request) => $request->url() === 'http://access-token:8000/main_web_view?bot_username=pixelworld'
        && ! $request->hasHeader('Authorization'));
});

test('it rejects malformed sidecar payloads', function (array $payload) {
    config()->set('cache.default', 'array');
    config()->set('services.http.retry_attempts', 1);
    config()->set('services.access-token.base_uri', 'http://access-token:8000/');
    Cache::flush();
    Http::fake(['http://access-token:8000/*' => Http::response($payload)]);

    expect(fn () => (new TelegramWebAppDataClient(new HttpRetryPolicy))->get('pixelworld'))
        ->toThrow(RuntimeException::class, 'Invalid response');
})->with([
    'missing decoded value' => [[]],
    'non-string decoded value' => [['decoded' => ['auth_date' => '123']]],
    'missing mandatory field' => [['decoded' => 'user=%7B%22id%22%3A1%7D&auth_date=123&hash=hash']],
    'duplicate mandatory field' => [['decoded' => telegramWebAppData('123').'&hash=other']],
]);

function telegramWebAppDataResponse(string $authDate): array
{
    return [
        'decoded' => telegramWebAppData($authDate),
    ];
}

function telegramWebAppData(string $authDate): string
{
    return 'user=%7B%22id%22%3A1%7D&chat_instance=chat-instance&chat_type=private'
        ."&auth_date={$authDate}&signature=signature&hash=hash";
}
