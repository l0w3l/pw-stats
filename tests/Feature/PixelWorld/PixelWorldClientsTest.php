<?php

use App\Data\PixelWorld\Stats\LeaderboardPlayerData;
use App\Services\Http\HttpRetryPolicy;
use App\Services\PixelWorld\Auth\PixelWorldTokenProvider;
use App\Services\PixelWorld\Leaderboard\PixelWorldLeaderboardClient;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use App\Services\Telegram\TelegramWebAppDataProvider;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Http\Message\RequestInterface;
use Spatie\LaravelData\Exceptions\CannotCreateData;

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('services.http.retry_attempts', 1);
    config()->set('services.pixel-world.requests_per_minute', 1000);
    Cache::flush();
    RateLimiter::clear('pixel-world:leaderboard-requests');
});

test('it exchanges web app data for a cached bearer token', function () {
    $webAppData = new FakeTelegramWebAppDataProvider;

    Http::fake([
        'pw.game/*' => Http::response(['ok' => true, 'data' => ['access_token' => 'bearer-token']]),
    ]);

    $provider = new PixelWorldTokenProvider($webAppData, new HttpRetryPolicy);

    expect($provider->token())->toBe('bearer-token')
        ->and($provider->token())->toBe('bearer-token')
        ->and($webAppData->refreshes)->toBe([false]);

    $nextProcessWebAppData = new FakeTelegramWebAppDataProvider;
    $nextProcessProvider = new PixelWorldTokenProvider($nextProcessWebAppData, new HttpRetryPolicy);

    expect($nextProcessProvider->token())->toBe('bearer-token')
        ->and($nextProcessWebAppData->refreshes)->toBe([]);

    Http::assertSentCount(1);
});

test('it keeps the bearer token in process memory between API requests', function () {
    $webAppData = new FakeTelegramWebAppDataProvider;
    Http::fake([
        'pw.game/*' => Http::response(['ok' => true, 'data' => ['access_token' => 'memory-token']]),
    ]);
    $provider = new PixelWorldTokenProvider($webAppData, new HttpRetryPolicy);

    expect($provider->token())->toBe('memory-token');
    Cache::forget('pixel-world:access-token');

    expect($provider->token())->toBe('memory-token')
        ->and($webAppData->refreshes)->toBe([false]);

    Http::assertSentCount(1);
});

test('it requests web app data for the configured bot username', function () {
    config()->set('services.pixel-world.bot_username', 'custom_bot');
    $webAppData = new FakeTelegramWebAppDataProvider('custom_bot');
    Http::fake([
        'pw.game/*' => Http::response(['ok' => true, 'data' => ['access_token' => 'bot-token']]),
    ]);

    expect((new PixelWorldTokenProvider($webAppData, new HttpRetryPolicy))->token())->toBe('bot-token');
});

test('it refreshes rejected web app data during login', function () {
    $webAppData = new FakeTelegramWebAppDataProvider;

    Http::fake([
        'pw.game/*' => Http::sequence()
            ->push([], 401)
            ->push(['ok' => true, 'data' => ['access_token' => 'bearer-token']]),
    ]);

    $provider = new PixelWorldTokenProvider($webAppData, new HttpRetryPolicy);

    expect($provider->token())->toBe('bearer-token')
        ->and($webAppData->refreshes)->toBe([false, true]);
});

test('the login request does not follow redirects with web app credentials', function () {
    $redirectOptions = [];
    Http::globalMiddleware(captureRedirectOptions($redirectOptions));
    Http::fake([
        'pw.game/*' => Http::response('', 302, ['Location' => 'https://hostile.example/collect']),
        'hostile.example/*' => Http::response([
            'data' => ['access_token' => 'stolen-token'],
        ]),
    ]);

    $provider = new PixelWorldTokenProvider(new FakeTelegramWebAppDataProvider, new HttpRetryPolicy);

    expect(fn () => $provider->token())->toThrow(RuntimeException::class)
        ->and($redirectOptions)->toBe([false]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'hostile.example'));
});

test('a stale bearer refresh reuses a token already replaced by another worker', function () {
    Cache::put('pixel-world:access-token', 'new-token', 60);
    $webAppData = new FakeTelegramWebAppDataProvider;
    $provider = new PixelWorldTokenProvider($webAppData, new HttpRetryPolicy);

    expect($provider->refresh('old-token'))->toBe('new-token')
        ->and($webAppData->refreshes)->toBe([]);

    Http::assertNothingSent();
});

test('the leaderboard client retries rate limited responses', function () {
    config()->set('services.http.retry_attempts', 2);
    config()->set('services.http.retry_delay_ms', 0);
    config()->set('services.http.retry_jitter_ms', 0);

    Http::fake([
        'https://pw.game/api/v2/auth/login/telegram-mini-apps' => Http::response([
            'ok' => true,
            'data' => ['access_token' => 'bearer-token'],
        ]),
        'https://pw.game/api/v2/stat/leaderboard/players*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '0'])
            ->push(pixelWorldLeaderboardResponse()),
    ]);

    $retryPolicy = new HttpRetryPolicy;
    $provider = new PixelWorldTokenProvider(new FakeTelegramWebAppDataProvider, $retryPolicy);
    $client = new PixelWorldLeaderboardClient($provider, $retryPolicy);

    expect($client->page(LeaderboardRange::Month, 2, 20)->ok)->toBeTrue();

    Http::assertSentCount(3);
});

test('the leaderboard client refreshes a rejected bearer without reopening Telegram', function () {
    $webAppData = new FakeTelegramWebAppDataProvider;
    $loginRequests = 0;
    $leaderboardRequests = 0;

    Http::fake(function (Request $request) use (&$loginRequests, &$leaderboardRequests) {
        if (str_contains($request->url(), '/auth/login/telegram-mini-apps')) {
            $loginRequests++;

            return Http::response([
                'ok' => true,
                'data' => ['access_token' => $loginRequests === 1 ? 'expired-token' : 'fresh-token'],
            ]);
        }

        $leaderboardRequests++;

        return $leaderboardRequests === 1
            ? Http::response([], 401)
            : Http::response(pixelWorldLeaderboardResponse());
    });

    $retryPolicy = new HttpRetryPolicy;
    $tokenProvider = new PixelWorldTokenProvider($webAppData, $retryPolicy);
    $client = new PixelWorldLeaderboardClient($tokenProvider, $retryPolicy);
    $leaderboard = $client->page(LeaderboardRange::Month, 2, 20);

    expect($leaderboard->data->leaderboard->list->players[0])->toBeInstanceOf(LeaderboardPlayerData::class)
        ->and($loginRequests)->toBe(2)
        ->and($webAppData->refreshes)->toBe([false, false]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stat/leaderboard/players')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

test('the leaderboard client rejects semantically mismatched responses', function () {
    Http::fake([
        'https://pw.game/api/v2/auth/login/telegram-mini-apps' => Http::response([
            'ok' => true,
            'data' => ['access_token' => 'bearer-token'],
        ]),
        'https://pw.game/api/v2/stat/leaderboard/players*' => Http::response(pixelWorldLeaderboardResponse()),
    ]);
    $retryPolicy = new HttpRetryPolicy;
    $client = new PixelWorldLeaderboardClient(
        new PixelWorldTokenProvider(new FakeTelegramWebAppDataProvider, $retryPolicy),
        $retryPolicy,
    );

    expect(fn () => $client->page(LeaderboardRange::Day, 1, 1))
        ->toThrow(RuntimeException::class, 'Invalid day leaderboard response for page 1');
});

test('the leaderboard client rejects contradictory totals and player places', function () {
    $payload = pixelWorldLeaderboardResponse();
    $payload['data']['leaderboard']['range'] = 'day';
    $payload['data']['leaderboard']['list']['page'] = 1;
    $payload['data']['leaderboard']['list']['total'] = 0;
    Http::fake([
        'https://pw.game/api/v2/auth/login/telegram-mini-apps' => Http::response([
            'ok' => true,
            'data' => ['access_token' => 'bearer-token'],
        ]),
        'https://pw.game/api/v2/stat/leaderboard/players*' => Http::response($payload),
    ]);
    $retryPolicy = new HttpRetryPolicy;
    $client = new PixelWorldLeaderboardClient(
        new PixelWorldTokenProvider(new FakeTelegramWebAppDataProvider, $retryPolicy),
        $retryPolicy,
    );

    expect(fn () => $client->page(LeaderboardRange::Day, 1, 1))
        ->toThrow(RuntimeException::class, 'Invalid day leaderboard response for page 1');
});

test('the leaderboard request does not follow redirects with its bearer token', function () {
    $redirectOptions = [];
    Http::globalMiddleware(captureRedirectOptions($redirectOptions));
    Http::fake([
        'https://pw.game/api/v2/auth/login/telegram-mini-apps' => Http::response([
            'ok' => true,
            'data' => ['access_token' => 'bearer-token'],
        ]),
        'https://pw.game/api/v2/stat/leaderboard/players*' => Http::response(
            '',
            302,
            ['Location' => 'https://hostile.example/collect'],
        ),
        'hostile.example/*' => Http::response(pixelWorldLeaderboardResponse()),
    ]);

    $retryPolicy = new HttpRetryPolicy;
    $provider = new PixelWorldTokenProvider(new FakeTelegramWebAppDataProvider, $retryPolicy);
    $client = new PixelWorldLeaderboardClient($provider, $retryPolicy);

    expect(fn () => $client->page(LeaderboardRange::Month, 1, 20))->toThrow(CannotCreateData::class)
        ->and($redirectOptions)->toBe([false, false]);

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'hostile.example'));
});

test('the retry policy honors integer Retry-After seconds', function () {
    config()->set('services.http.retry_attempts', 2);
    config()->set('services.http.retry_max_delay_seconds', 30);
    $delays = [];
    $responses = [retryResponse(429, ['Retry-After' => '12']), retryResponse()];
    $policy = new HttpRetryPolicy(function (int $microseconds) use (&$delays): void {
        $delays[] = $microseconds;
    });

    $policy->send(fn () => array_shift($responses));

    expect($delays)->toBe([12_000_000]);
});

test('the retry policy honors and caps future HTTP-date Retry-After values', function () {
    config()->set('services.http.retry_attempts', 2);
    config()->set('services.http.retry_max_delay_seconds', 10);
    $delays = [];
    $this->freezeTime(function () use (&$delays): void {
        $retryAt = now('UTC')->addMinute()->format(DATE_RFC7231);
        $responses = [retryResponse(503, ['Retry-After' => $retryAt]), retryResponse()];
        $policy = new HttpRetryPolicy(function (int $microseconds) use (&$delays): void {
            $delays[] = $microseconds;
        });

        $policy->send(fn () => array_shift($responses));

        expect($delays)->toBe([10_000_000]);
    });
});

test('the retry policy treats a past HTTP-date Retry-After as immediately eligible', function () {
    config()->set('services.http.retry_attempts', 2);
    $delays = [];
    $retryAt = now('UTC')->subMinute()->format(DATE_RFC7231);
    $responses = [retryResponse(503, ['Retry-After' => $retryAt]), retryResponse()];
    $policy = new HttpRetryPolicy(function (int $microseconds) use (&$delays): void {
        $delays[] = $microseconds;
    });

    $policy->send(fn () => array_shift($responses));

    expect($delays)->toBe([0]);
});

test('the retry policy falls back to backoff for a malformed Retry-After date', function () {
    config()->set('services.http.retry_attempts', 2);
    config()->set('services.http.retry_delay_ms', 125);
    config()->set('services.http.retry_jitter_ms', 0);
    $delays = [];
    $responses = [retryResponse(503, ['Retry-After' => 'not-a-date']), retryResponse()];
    $policy = new HttpRetryPolicy(function (int $microseconds) use (&$delays): void {
        $delays[] = $microseconds;
    });

    $policy->send(fn () => array_shift($responses));

    expect($delays)->toBe([125_000]);
});

class FakeTelegramWebAppDataProvider implements TelegramWebAppDataProvider
{
    public array $refreshes = [];

    public function __construct(private readonly string $expectedBotUsername = 'pixelworld') {}

    public function get(string $botUsername, bool $refresh = false): string
    {
        expect($botUsername)->toBe($this->expectedBotUsername);
        $this->refreshes[] = $refresh;

        return 'web-app-data';
    }
}

function pixelWorldLeaderboardResponse(): array
{
    return [
        'ok' => true,
        'data' => [
            'leaderboard' => [
                'range' => 'month',
                'user' => pixelWorldClientPlayer('viewer', 100),
                'list' => [
                    'page' => 2,
                    'total' => 1,
                    'players' => [pixelWorldClientPlayer('player', 1)],
                ],
            ],
        ],
    ];
}

function pixelWorldClientPlayer(string $uuid, int $place): array
{
    return [
        'uuid' => $uuid,
        'image_url' => 'https://example.test/avatar.jpg',
        'mask_image_url' => null,
        'level' => 42,
        'nickname' => $uuid,
        'has_premium' => true,
        'place' => $place,
        'points' => 100,
    ];
}

/** @param list<bool|array<string, mixed>> $redirectOptions */
function captureRedirectOptions(array &$redirectOptions): Closure
{
    return static function (callable $handler) use (&$redirectOptions): Closure {
        return static function (RequestInterface $request, array $options) use ($handler, &$redirectOptions): PromiseInterface {
            if (str_contains((string) $request->getUri(), 'pw.game')) {
                $redirectOptions[] = $options['allow_redirects'] ?? [];
            }

            return $handler($request, $options);
        };
    };
}

/** @param array<string, string> $headers */
function retryResponse(int $status = 200, array $headers = []): Response
{
    return new Response(new Psr7Response($status, $headers));
}
