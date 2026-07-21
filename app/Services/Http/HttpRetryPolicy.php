<?php

declare(strict_types=1);

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

class HttpRetryPolicy
{
    /** @param callable(): Response $request */
    public function send(callable $request): Response
    {
        $attempts = max(1, (int) config('services.http.retry_attempts'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw $exception;
                }

                $this->pause($attempt);

                continue;
            }

            if (! $this->shouldRetry($response) || $attempt === $attempts) {
                return $response;
            }

            $this->pause($attempt, $response);
        }

        throw new \LogicException('HTTP retry loop completed without a response.');
    }

    private function shouldRetry(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function pause(int $attempt, ?Response $response = null): void
    {
        $retryAfter = $response?->header('Retry-After');

        if (is_numeric($retryAfter)) {
            $seconds = min(
                max(0, (int) $retryAfter),
                max(0, (int) config('services.http.retry_max_delay_seconds')),
            );
            usleep($seconds * 1_000_000);

            return;
        }

        $baseDelay = max(0, (int) config('services.http.retry_delay_ms')) * $attempt;
        $jitter = random_int(0, max(0, (int) config('services.http.retry_jitter_ms')));

        usleep(($baseDelay + $jitter) * 1000);
    }
}
