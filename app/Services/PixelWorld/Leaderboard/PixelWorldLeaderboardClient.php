<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Leaderboard;

use App\Data\PixelWorld\Stats\PlayersLeaderboardResponseData;
use App\Services\Http\HttpRetryPolicy;
use App\Services\PixelWorld\Auth\PixelWorldTokenProvider;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class PixelWorldLeaderboardClient implements LeaderboardClient
{
    public function __construct(
        private readonly PixelWorldTokenProvider $tokenProvider,
        private readonly HttpRetryPolicy $retryPolicy,
    ) {}

    public function page(LeaderboardRange $range, int $page, int $limit): PlayersLeaderboardResponseData
    {
        if ($page < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Page and limit must be positive integers.');
        }

        $token = $this->tokenProvider->token();
        $response = $this->request($token, $range, $page, $limit);

        if (in_array($response->status(), [401, 403], true)) {
            $token = $this->tokenProvider->refresh($token);
            $response = $this->request($token, $range, $page, $limit);
        }

        $response->throw();

        return PlayersLeaderboardResponseData::from($response->json());
    }

    private function request(string $token, LeaderboardRange $range, int $page, int $limit): Response
    {
        return $this->retryPolicy->send(function () use ($token, $range, $page, $limit): Response {
            $this->throttle();

            return Http::acceptJson()
                ->connectTimeout((int) config('services.http.connect_timeout_seconds'))
                ->timeout((int) config('services.http.timeout_seconds'))
                ->withToken($token)
                ->get(rtrim((string) config('services.pixel-world.base_uri'), '/').'/stat/leaderboard/players', [
                    'range' => $range->value,
                    'page' => $page,
                    'limit' => $limit,
                ]);
        });
    }

    private function throttle(): void
    {
        $key = 'pixel-world:leaderboard-requests';
        $maxAttempts = max(1, (int) config('services.pixel-world.requests_per_minute'));

        while (true) {
            $lock = Cache::lock("{$key}:lock", 5);

            if (! $lock->get()) {
                usleep(50_000);

                continue;
            }

            try {
                if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                    $waitSeconds = max(1, RateLimiter::availableIn($key));
                } else {
                    $now = microtime(true);
                    $nextRequestAt = max($now, (float) Cache::get("{$key}:next-at", $now));
                    $waitMicroseconds = (int) max(0, ($nextRequestAt - $now) * 1_000_000);

                    Cache::put(
                        "{$key}:next-at",
                        $nextRequestAt + (60 / $maxAttempts),
                        120,
                    );
                    RateLimiter::hit($key, 60);
                }
            } finally {
                $lock->release();
            }

            if (isset($waitMicroseconds)) {
                usleep($waitMicroseconds);

                return;
            }

            sleep($waitSeconds);
        }
    }
}
