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

    public function __construct(
        private readonly TelegramWebAppDataProvider $webAppDataProvider,
        private readonly HttpRetryPolicy $retryPolicy,
    ) {}

    public function token(): string
    {
        $cached = $this->cache()->get(self::CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        return $this->refreshLock()->block((int) config('services.http.auth_lock_wait_seconds'), function (): string {
            $cached = $this->cache()->get(self::CACHE_KEY);

            return is_string($cached) ? $cached : $this->issueAndCache();
        });
    }

    public function refresh(string $rejectedToken): string
    {
        return $this->refreshLock()->block((int) config('services.http.auth_lock_wait_seconds'), function () use ($rejectedToken): string {
            $cached = $this->cache()->get(self::CACHE_KEY);

            if (is_string($cached) && $cached !== $rejectedToken) {
                return $cached;
            }

            $this->cache()->forget(self::CACHE_KEY);

            return $this->issueAndCache(refreshWebAppData: true);
        });
    }

    private function issueAndCache(bool $refreshWebAppData = false): string
    {
        $token = $this->login($refreshWebAppData);
        $this->cache()->forever(self::CACHE_KEY, $token);

        return $token;
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
            ->withoutRedirecting()
            ->post(rtrim((string) config('services.pixel-world.base_uri'), '/').'/auth/login/telegram-mini-apps', [
                'web_app_data' => $this->webAppDataProvider->get(
                    (string) config('services.pixel-world.bot_username'),
                    $refreshWebAppData,
                ),
            ]));

        if (! $refreshWebAppData && $response->status() === 403) {
            return $this->login(refreshWebAppData: true);
        }

        $response->throw();

        $token = $response->json('data.access_token');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Invalid response from Pixel World authentication service.');
        }

        return $token;
    }
}
