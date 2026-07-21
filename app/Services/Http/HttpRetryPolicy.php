<?php

declare(strict_types=1);

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

class HttpRetryPolicy
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @param null|callable(int): void $sleep */
    public function __construct(?callable $sleep = null)
    {
        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static function (int $microseconds): void {
                usleep($microseconds);
            };
    }

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
        $retryAfterDelay = $this->retryAfterDelay($response?->header('Retry-After'));

        if ($retryAfterDelay !== null) {
            ($this->sleep)($retryAfterDelay * 1_000_000);

            return;
        }

        $baseDelay = max(0, (int) config('services.http.retry_delay_ms')) * $attempt;
        $jitter = random_int(0, max(0, (int) config('services.http.retry_jitter_ms')));

        ($this->sleep)(($baseDelay + $jitter) * 1000);
    }

    private function retryAfterDelay(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }

        $value = trim($header);
        $maxDelay = max(0, (int) config('services.http.retry_max_delay_seconds'));

        if (preg_match('/^\d+$/D', $value) === 1) {
            return min((int) $value, $maxDelay);
        }

        $retryAt = \DateTimeImmutable::createFromFormat(
            DATE_RFC7231,
            $value,
            new \DateTimeZone('GMT'),
        );
        $errors = \DateTimeImmutable::getLastErrors();

        if ($retryAt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $retryAt->format(DATE_RFC7231) !== $value) {
            return null;
        }

        return min(max(0, $retryAt->getTimestamp() - now('UTC')->getTimestamp()), $maxDelay);
    }
}
