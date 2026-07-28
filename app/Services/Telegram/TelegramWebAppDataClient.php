<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Http\HttpRetryPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TelegramWebAppDataClient implements TelegramWebAppDataProvider
{
    public function __construct(private readonly HttpRetryPolicy $retryPolicy) {}

    public function get(string $botUsername, bool $refresh = false): string
    {
        $cacheKey = "telegram:web-app-data:{$botUsername}";

        return Cache::lock(
            "{$cacheKey}:refresh",
            (int) config('services.http.auth_lock_seconds'),
        )->block((int) config('services.http.auth_lock_wait_seconds'), function () use ($botUsername, $cacheKey, $refresh): string {
            if ($refresh) {
                Cache::forget($cacheKey);
            }

            $cached = Cache::get($cacheKey);

            if (is_string($cached)) {
                return $cached;
            }

            $webAppData = $this->request($botUsername);
            Cache::put(
                $cacheKey,
                $webAppData,
                now()->addSeconds(max(60, (int) config('services.access-token.web_app_data_cache_ttl_seconds'))),
            );

            return $webAppData;
        });
    }

    private function request(string $botUsername): string
    {
        $response = $this->retryPolicy->send(fn () => Http::acceptJson()
            ->withoutRedirecting()
            ->connectTimeout((int) config('services.http.connect_timeout_seconds'))
            ->timeout((int) config('services.http.timeout_seconds'))
            ->get(rtrim((string) config('services.access-token.base_uri'), '/').'/main_web_view', [
                'bot_username' => $botUsername,
            ]));

        $response->throw();

        $decoded = $response->json('decoded');

        if (! is_string($decoded) || ! $this->hasMandatoryAuthFields($decoded)) {
            throw new \RuntimeException('Invalid response from Telegram web app data service.');
        }

        return $decoded;
    }

    private function hasMandatoryAuthFields(string $initData): bool
    {
        $values = [];

        foreach (explode('&', $initData) as $parameter) {
            if (! str_contains($parameter, '=')) {
                return false;
            }

            [$encodedKey, $encodedValue] = explode('=', $parameter, 2);
            $key = rawurldecode($encodedKey);
            $values[$key][] = rawurldecode($encodedValue);
        }

        foreach (['user', 'chat_instance', 'chat_type', 'auth_date', 'signature', 'hash'] as $key) {
            if (! isset($values[$key]) || count($values[$key]) !== 1 || $values[$key][0] === '') {
                return false;
            }
        }

        return true;
    }
}
