<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Auth;

use App\Services\Http\HttpRetryPolicy;
use App\Services\Telegram\TelegramWebAppDataProvider;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelWorldTokenProvider
{
    private const CACHE_KEY = 'pixel-world:access-token';

    private ?string $memoryToken = null;

    public function __construct(
        private readonly TelegramWebAppDataProvider $webAppDataProvider,
        private readonly HttpRetryPolicy $retryPolicy,
    ) {}

    public function token(): string
    {
        if ($this->memoryToken !== null) {
            return $this->memoryToken;
        }

        $cached = $this->cache()->get(self::CACHE_KEY);

        if (is_string($cached)) {
            return $this->memoryToken = $cached;
        }

        return $this->refreshLock()->block((int) config('services.http.auth_lock_wait_seconds'), function (): string {
            $cached = $this->cache()->get(self::CACHE_KEY);

            return is_string($cached) ? $this->memoryToken = $cached : $this->issueAndCache();
        });
    }

    public function refresh(string $rejectedToken): string
    {
        return $this->refreshLock()->block((int) config('services.http.auth_lock_wait_seconds'), function () use ($rejectedToken): string {
            $cached = $this->cache()->get(self::CACHE_KEY);

            if (is_string($cached) && $cached !== $rejectedToken) {
                return $this->memoryToken = $cached;
            }

            $this->memoryToken = null;
            $this->cache()->forget(self::CACHE_KEY);

            return $this->issueAndCache();
        });
    }

    private function issueAndCache(): string
    {
        $token = $this->login();
        $this->cache()->put(self::CACHE_KEY, $token, $this->expiration($token));

        return $this->memoryToken = $token;
    }

    private function refreshLock(): Lock
    {
        return $this->cache()->lock(
            self::CACHE_KEY.':refresh',
            (int) config('services.http.auth_lock_seconds'),
        );
    }

    private function cache(): Repository
    {
        $store = config('services.pixel-world.token_cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private function login(bool $refreshWebAppData = false): string
    {
        $response = $this->retryPolicy->send(fn () => Http::acceptJson()
            ->connectTimeout((int) config('services.http.connect_timeout_seconds'))
            ->timeout((int) config('services.http.timeout_seconds'))
            ->post(rtrim((string) config('services.pixel-world.base_uri'), '/').'/auth/login/telegram-mini-apps', [
                'web_app_data' => $this->webAppDataProvider->get('pixelworld', $refreshWebAppData),
            ]));

        if (! $refreshWebAppData && in_array($response->status(), [400, 401, 403], true)) {
            return $this->login(refreshWebAppData: true);
        }

        $response->throw();

        $token = $response->json('data.access_token');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Invalid response from Pixel World authentication service.');
        }

        return $token;
    }

    private function expiration(string $token): \DateTimeInterface
    {
        $fallback = now()->addSeconds(max(60, (int) config('services.pixel-world.access_token_cache_ttl_seconds')));
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return $fallback;
        }

        $payloadSegment = strtr($segments[1], '-_', '+/');
        $payload = base64_decode(str_pad($payloadSegment, (int) (4 * ceil(strlen($payloadSegment) / 4)), '='), true);
        $expiration = is_string($payload) ? json_decode($payload, true)['exp'] ?? null : null;

        if (! is_numeric($expiration)) {
            return $fallback;
        }

        $expiresAt = now()->setTimestamp((int) $expiration - 60);

        if (! $expiresAt->isFuture()) {
            return now()->addSecond();
        }

        return $expiresAt->lessThan($fallback) ? $expiresAt : $fallback;
    }
}
