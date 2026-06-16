<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ShopifyAdminGraphqlClient
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout' => 60,
        ]);
    }

    /**
     * @return array{data?: mixed, errors?: mixed}
     */
    public function query(Shop $shop, string $query, array $variables = []): array
    {
        $endpoint = sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $shop->shop_domain,
            config('shopify.api_version')
        );

        $attempts = (int) config('shopify.http.max_retries', 5);
        $baseBackoffMs = (int) config('shopify.http.base_backoff_ms', 500);
        $fastFailThrottled = (bool) env('SHOPIFY_GRAPHQL_THROTTLE_FAST_FAIL', false);
        $costDebugHeader = (bool) env('SHOPIFY_GRAPHQL_COST_DEBUG_HEADER', false);
        $costDebugLog = (bool) env('SHOPIFY_GRAPHQL_COST_DEBUG_LOG', false);

        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $headers = [
                    'X-Shopify-Access-Token' => $shop->access_token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ];

                if ($costDebugHeader) {
                    $headers['Shopify-GraphQL-Cost-Debug'] = '1';
                }

                $res = $this->http->post($endpoint, [
                    'headers' => $headers,
                    'json' => [
                        'query' => $query,
                        'variables' => (object) $variables,
                    ],
                ]);

                $status = $res->getStatusCode();
                $body = (string) $res->getBody();

                $data = json_decode($body, true);
                if (!is_array($data)) {
                    return ['errors' => [['message' => 'Invalid JSON response from Shopify']]];
                }

                if ($status >= 200 && $status <= 299) {
                    if ($this->isGraphqlThrottled($data)) {
                        if ($costDebugLog) {
                            $this->logGraphqlCostDebug($shop, $data, true);
                        }
                        // Return throttled response to the caller so queue jobs can release with
                        // an accurate delay, instead of blocking the worker in a retry loop.
                        // If you want the previous blocking behavior, set SHOPIFY_GRAPHQL_THROTTLE_FAST_FAIL=false
                        // and handle retries at the call site.
                        return $data;
                    }

                    if ($costDebugLog) {
                        $this->logGraphqlCostDebug($shop, $data, false);
                    }

                    $this->maybePaceFromThrottleStatus($data);
                    return $data;
                }

                return [
                    'errors' => [[
                        'message' => 'Unexpected Shopify response status',
                        'status' => $status,
                        'body' => $data,
                    ]],
                ];
            } catch (GuzzleException $e) {
                $lastException = $e;

                $retryAfterMs = 0;
                $status = null;
                $responseBody = null;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $status = $e->getResponse()->getStatusCode();

                    $raw = (string) $e->getResponse()->getBody();
                    $decoded = json_decode($raw, true);
                    $responseBody = is_array($decoded) ? $decoded : $raw;

                    $retryAfter = $e->getResponse()->getHeaderLine('Retry-After');
                    if ($retryAfter !== '') {
                        $seconds = (int) $retryAfter;
                        if ($seconds > 0) {
                            $retryAfterMs = $seconds * 1000;
                        }
                    }
                }

                if ($status === 401) {
                    return [
                        'errors' => [[
                            'message' => 'Shopify Admin API unauthorized',
                            'status' => 401,
                            'body' => $responseBody,
                        ]],
                    ];
                }

                if ($status === 429) {
                    // Don't block in the HTTP client on throttling.
                    // Return immediately with Retry-After (when available) so callers can release.
                    return [
                        'errors' => [[
                            'message' => is_string($responseBody) ? $responseBody : 'Shopify rate limited',
                            'status' => 429,
                            'body' => $responseBody,
                            'retry_after_ms' => $retryAfterMs,
                        ]],
                    ];
                }

                $sleepMs = $this->computeBackoffMs($attempt, $baseBackoffMs, $retryAfterMs, $status, $status === 429);

                Log::warning('Shopify GraphQL request failed; retrying', [
                    'shop' => $shop->shop_domain,
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'sleep_ms' => $sleepMs,
                    'error' => $e->getMessage(),
                    'status' => $status,
                ]);

                usleep($sleepMs * 1000);
            }
        }

        if ($lastException) {
            return ['errors' => [['message' => $lastException->getMessage()]]];
        }

        return ['errors' => [['message' => 'Unknown Shopify request failure']]];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isGraphqlThrottled(array $data): bool
    {
        $errors = $data['errors'] ?? null;
        if (!is_array($errors) || count($errors) === 0) {
            return false;
        }

        foreach ($errors as $e) {
            if (!is_array($e)) {
                continue;
            }

            $message = strtolower((string) ($e['message'] ?? ''));
            $code = strtolower((string) ($e['extensions']['code'] ?? ''));
            if (str_contains($message, 'thrott') || str_contains($message, 'too many') || $code === 'throttled') {
                return true;
            }
        }

        return false;
    }

    private function computeBackoffMs(int $attempt, int $baseBackoffMs, int $retryAfterMs, ?int $status, bool $throttled): int
    {
        $backoffMs = $baseBackoffMs * (2 ** ($attempt - 1));
        $capMs = $throttled ? 120000 : 10000;
        $backoffMs = min($capMs, $backoffMs);
        $minMs = $throttled ? 3000 : 0;

        return max($minMs, $retryAfterMs, $backoffMs);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function maybePaceFromThrottleStatus(array $data): void
    {
        // Intentionally no-op.
        // We only apply pacing when Shopify actually throttles (see computeThrottleSleepMsFromResponse).
        // Proactive pacing on successful responses reduces throughput for migration jobs.
        return;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function computeThrottleSleepMsFromResponse(array $data): int
    {
        $status = data_get($data, 'extensions.cost.throttleStatus');
        if (!is_array($status)) {
            return 0;
        }

        $currently = data_get($status, 'currentlyAvailable');
        $restoreRate = data_get($status, 'restoreRate');
        if (!is_numeric($currently) || !is_numeric($restoreRate)) {
            return 0;
        }

        $currently = (float) $currently;
        $restoreRate = (float) $restoreRate;
        if ($restoreRate <= 0) {
            return 0;
        }

        $requested = data_get($data, 'extensions.cost.requestedQueryCost');
        $requested = is_numeric($requested) ? (float) $requested : 0.0;

        $need = max(0.0, $requested - $currently);
        $sleepMs = (int) ceil(($need / $restoreRate) * 1000.0);
        return max(200, min(120000, $sleepMs));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logGraphqlCostDebug(Shop $shop, array $data, bool $throttled): void
    {
        $cost = data_get($data, 'extensions.cost');
        if (!is_array($cost)) {
            return;
        }

        $throttleStatus = data_get($cost, 'throttleStatus');
        if (!is_array($throttleStatus)) {
            $throttleStatus = null;
        }

        $requested = data_get($cost, 'requestedQueryCost');
        $actual = data_get($cost, 'actualQueryCost');

        // Only log when throttle info exists to avoid noisy logs.
        if ($requested === null && $actual === null && $throttleStatus === null) {
            return;
        }

        Log::info('Shopify GraphQL cost debug', [
            'shop' => $shop->shop_domain,
            'throttled' => $throttled,
            'requestedQueryCost' => $requested,
            'actualQueryCost' => $actual,
            'throttleStatus' => $throttleStatus,
        ]);
    }
}
