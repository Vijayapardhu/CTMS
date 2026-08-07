<?php

namespace App\Services\Maps\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place that actually talks to Google.
 *
 * Every provider goes through here so that timeout, retry, quota accounting
 * and the circuit breaker are defined once. Four copies of "retry twice then
 * give up" is four chances to get it subtly different, and the one that gets
 * it wrong is the one that takes the live map down.
 *
 * The contract upwards is simple: `get()` returns decoded JSON, or null. It
 * never throws. Callers translate null into their own fallback.
 */
class GoogleMapsGateway
{
    /**
     * How many consecutive failures before the breaker opens.
     */
    private const FAILURE_THRESHOLD = 5;

    public function __construct(private readonly string $service) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $url, array $query = []): ?array
    {
        return $this->send(fn () => Http::timeout($this->timeout())
            ->retry($this->retries(), $this->retryDelayMs(), throw: false)
            ->get($url, $query + ['key' => $this->apiKey()]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    public function post(string $url, array $payload, array $headers = []): ?array
    {
        return $this->send(fn () => Http::timeout($this->timeout())
            ->retry($this->retries(), $this->retryDelayMs(), throw: false)
            ->withHeaders($headers + ['X-Goog-Api-Key' => $this->apiKey()])
            ->post($url, $payload));
    }

    /**
     * Whether calls will actually be attempted right now.
     *
     * False when there is no key, when the feature is switched off, when the
     * daily quota is spent, or when the breaker is open.
     */
    public function isAvailable(): bool
    {
        return $this->isEnabled()
            && $this->apiKey() !== ''
            && ! $this->quotaExhausted()
            && ! $this->breakerIsOpen();
    }

    /**
     * Requests made against today's allowance.
     */
    public function requestsToday(): int
    {
        return (int) Cache::get($this->quotaKey(), 0);
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>|null
     */
    private function send(callable $request): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        // Counted before the call, not after. A request that times out still
        // consumed quota at the far end, and counting only successes is how a
        // budget gets quietly overrun.
        $this->recordRequest();

        try {
            $response = $request();
        } catch (\Throwable $e) {
            $this->recordFailure($e->getMessage());

            return null;
        }

        if ($response->failed()) {
            $this->recordFailure("HTTP {$response->status()}");

            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            $this->recordFailure('Response was not JSON.');

            return null;
        }

        // Google reports its own errors inside a 200.
        $status = $body['status'] ?? 'OK';

        if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            $this->recordFailure("Google returned {$status}");

            if ($status === 'OVER_QUERY_LIMIT') {
                $this->exhaustQuota();
            }

            return null;
        }

        $this->recordSuccess();

        return $body;
    }

    private function recordRequest(): void
    {
        Cache::add($this->quotaKey(), 0, now()->endOfDay());
        Cache::increment($this->quotaKey());
    }

    private function recordSuccess(): void
    {
        Cache::forget($this->breakerKey());
    }

    private function recordFailure(string $reason): void
    {
        $failures = (int) Cache::get($this->breakerKey(), 0) + 1;

        Cache::put($this->breakerKey(), $failures, now()->addMinutes($this->breakerMinutes()));

        Log::warning('Google Maps request failed', [
            'service' => $this->service,
            'reason' => $reason,
            'consecutive_failures' => $failures,
        ]);

        if ($failures === self::FAILURE_THRESHOLD) {
            // Logged once, at the moment it trips, rather than on every
            // subsequent call — otherwise an outage buries the log.
            Log::error('Google Maps circuit breaker opened; falling back to offline estimates', [
                'service' => $this->service,
                'reopens_in_minutes' => $this->breakerMinutes(),
            ]);
        }
    }

    private function breakerIsOpen(): bool
    {
        return (int) Cache::get($this->breakerKey(), 0) >= self::FAILURE_THRESHOLD;
    }

    private function quotaExhausted(): bool
    {
        $limit = $this->dailyLimit();

        return $limit > 0 && $this->requestsToday() >= $limit;
    }

    private function exhaustQuota(): void
    {
        Cache::put($this->quotaKey(), $this->dailyLimit(), now()->endOfDay());
    }

    private function quotaKey(): string
    {
        return "maps:quota:{$this->service}:".now()->toDateString();
    }

    private function breakerKey(): string
    {
        return "maps:breaker:{$this->service}";
    }

    private function apiKey(): string
    {
        return (string) config('services.google_maps.key', '');
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.google_maps.enabled', false);
    }

    private function timeout(): int
    {
        return (int) config('services.google_maps.timeout_seconds', 3);
    }

    private function retries(): int
    {
        return (int) config('services.google_maps.retries', 2);
    }

    private function retryDelayMs(): int
    {
        return (int) config('services.google_maps.retry_delay_ms', 200);
    }

    private function breakerMinutes(): int
    {
        return (int) config('services.google_maps.breaker_minutes', 5);
    }

    private function dailyLimit(): int
    {
        return (int) config("services.google_maps.daily_limits.{$this->service}", 0);
    }
}
