<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Models\ShopifyIdMapping;
use App\Services\Migration\MigrationRunReportWriter;
use App\Services\Migration\MigrationLocationResolver;
use App\Services\Migration\OrderFingerprint;
use App\Services\Migration\OrderPayloadMapper;
use App\Services\Migration\ShopifyOrderDocumentSyncService;
use App\Services\Migration\ShopifyOrderSyncService;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Magento\MagentoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessOrderMigrationItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 200;

    private int $runId;

    /** @var array<string, mixed> */
    private array $order;

    /**
     * SAFE CHANGE: Process exactly one Shopware order payload.
     * Idempotent via migration_items unique key and ShopifyIdMapping.
     * Mapping logic is unchanged: reused from previous RunOrderMigrationJob.
     *
     * @param array<string, mixed> $order
     */
    public function __construct(int $runId, array $order)
    {
        $this->runId = $runId;
        $this->order = $order;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.magentoConnection')->find($this->runId);
        if (!$run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $shop = $run->shop;
        if (!$shop) {
            return;
        }

        $sourceId = (string) ($this->order['entity_id'] ?? $this->order['id'] ?? '');
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            return;
        }

        $item = MigrationItem::query()->firstOrCreate([
            'migration_run_id' => $run->id,
            'entity_type' => 'order',
            'source_id' => $sourceId,
        ], [
            'status' => 'queued',
        ]);

        if (in_array($item->status, ['skipped', 'succeeded'], true)) {
            return;
        }

        $prevRateLimitRequeues = (int) data_get($item->error_context, 'rate_limit_requeues', 0);

        $item->status = 'running';
        $item->started_at = now();
        $item->error_message = null;
        $item->save();

        $mapper = app(OrderPayloadMapper::class);
        $fingerprints = app(OrderFingerprint::class);
        $sync = app(ShopifyOrderSyncService::class);

        $payload = null;
        $fp = null;

        try {
            $order = $this->order;

            // Fetch supplementary Magento data (invoices, shipments, credit memos) if connected
            if ($shop->magentoConnection) {
                $order = $this->enrichOrderWithMagentoData($shop, $order);
            }

            $sync->ensureOrderDocumentMetafieldDefinitions($shop);

            $locationGid = app(MigrationLocationResolver::class)->resolveForRun($run, $shop);
            $mapped = $mapper->mapOrder($shop, $order, $locationGid);
            $payload = $mapped['order'] ?? null;
            $metafields = $mapped['metafields'] ?? [];
            $paymentCapture = isset($mapped['payment_capture']) && is_array($mapped['payment_capture'])
                ? $mapped['payment_capture']
                : null;

            if (!is_array($payload)) {
                $this->markFailed($run, $item, 'Order payload mapping failed', ['source_id' => $sourceId]);
                return;
            }

            $fp = $fingerprints->make($payload);

            if ($this->releaseIfShopifyCooldownActive($shop->id, $item)) {
                return;
            }

            $existingMapping = ShopifyIdMapping::query()
                ->where('shop_id', $shop->id)
                ->where('entity_type', 'order')
                ->where('source_id', $sourceId)
                ->first();

            $existingOrderGid = $existingMapping ? (string) $existingMapping->shopify_gid : '';

            $lastSucceededFingerprint = '';
            if ($existingOrderGid !== '') {
                $lastSucceededFingerprint = (string) (MigrationItem::query()
                    ->where('entity_type', 'order')
                    ->where('source_id', $sourceId)
                    ->where('status', 'succeeded')
                    ->whereNotNull('fingerprint')
                    ->whereHas('run', function ($q) use ($shop) {
                        $q->where('shop_id', $shop->id);
                    })
                    ->orderByDesc('finished_at')
                    ->value('fingerprint') ?? '');
            }

            if ($existingMapping && $existingOrderGid !== '') {
                if ($this->releaseIfShopifyCooldownActive($shop->id, $item)) {
                    return;
                }
                $exists = $sync->shopifyOrderExists($shop, $existingOrderGid);
                if (!$exists) {
                    $existingMapping->delete();
                    $existingMapping = null;
                    $existingOrderGid = '';
                }
            }

            if ($existingOrderGid !== '' && is_string($fp) && $lastSucceededFingerprint !== '' && hash_equals($lastSucceededFingerprint, $fp)) {
                $item->status = 'skipped';
                $item->shopify_gid = $existingOrderGid;
                $item->fingerprint = $fp;
                $item->finished_at = now();
                $item->save();

                $run->refresh();
                $run->processed++;
                $run->save();
                $this->appendReportRow($run, $item, 'skipped');
                return;
            }

            if ($existingOrderGid !== '' && is_string($fp) && $lastSucceededFingerprint !== '' && !hash_equals($lastSucceededFingerprint, $fp)) {
                $tags = [];
                if (isset($payload['tags']) && is_array($payload['tags'])) {
                    $tags = $payload['tags'];
                }

                $customAttributes = isset($payload['customAttributes']) && is_array($payload['customAttributes'])
                    ? $payload['customAttributes']
                    : [];

                if ($this->releaseIfShopifyCooldownActive($shop->id, $item)) {
                    return;
                }
                $lock = $this->cache()->lock($this->orderWriteLockKey($shop->id, $sourceId), 300);
                $update = $lock->block(120, function () use ($sync, $shop, $existingOrderGid, $tags, $metafields, $customAttributes) {
                    return $sync->updateOrderMetadata($shop, $existingOrderGid, $tags, $metafields, $customAttributes);
                });

                if (!empty($update['errors']) || !empty($update['userErrors'])) {
                    $this->markFailed($run, $item, 'Shopify metadata update failed', $update);
                    return;
                }

                $item->status     = 'succeeded';
                $item->fingerprint = $fp;
                $item->shopify_gid = $existingOrderGid;
                $item->finished_at = now();
                $item->save();

                // Language preference is not tracked per order in Magento.

                $run->refresh();
                $run->processed++;
                $run->succeeded++;
                $run->save();
                $this->appendReportRow($run, $item, 'succeeded');
                return;
            }

            if ($item->fingerprint && is_string($fp) && hash_equals($item->fingerprint, $fp)) {
                $item->status = 'skipped';
                $item->finished_at = now();
                $item->save();

                $run->refresh();
                $run->processed++;
                $run->save();
                $this->appendReportRow($run, $item, 'skipped');
                return;
            }

            if ($this->releaseIfShopifyCooldownActive($shop->id, $item)) {
                return;
            }

            // Extract tags and customAttributes for later use
            $tags = [];
            if (isset($payload['tags']) && is_array($payload['tags'])) {
                $tags = array_values(array_filter($payload['tags'], fn ($t) => is_string($t) && trim($t) !== ''));
            }

            $customAttributes = isset($payload['customAttributes']) && is_array($payload['customAttributes'])
                ? $payload['customAttributes']
                : null;

            // Prepare payload without tags for core order creation
            $payloadCore = $payload;
            if (isset($payloadCore['tags'])) {
                unset($payloadCore['tags']);
            }

            $lock = $this->cache()->lock($this->orderWriteLockKey($shop->id, $sourceId), 300);

            // Acquire lock first to serialize jobs, then enforce pacing inside
            $create = $lock->block(120, function () use ($sync, $shop, $payloadCore, $item) {
                // Enforce minimum interval between orderCreate attempts to respect
                // Shopify's 5 orders/minute limit for development/trial stores.
                $delay = $this->getMinIntervalDelay($shop->id);
                if ($delay > 0) {
                    // Release job with delay and return empty to signal retry needed
                    $item->status = 'queued';
                    $item->started_at = null;
                    $item->finished_at = null;
                    $item->save();
                    $this->release($delay);
                    return null;
                }

                // Record attempt time BEFORE making the API call
                $this->recordOrderCreateAttempt($shop->id);

                return $sync->createOrderCore($shop, $payloadCore);
            });

            // If lock callback returned null, job was released for pacing
            if ($create === null) {
                return;
            }

            if (!empty($create['errors'])) {
                $err0 = is_array($create['errors']) ? ($create['errors'][0] ?? null) : null;
                $status = is_array($err0) ? ($err0['status'] ?? null) : null;
                $msg = is_array($err0) ? (string) ($err0['message'] ?? '') : '';
                $isRateLimited = $this->isShopifyRateLimited($status, $msg);

                if ($isRateLimited) {
                    $maybeOrderGid = (string) ($create['orderGid'] ?? '');
                    if ($maybeOrderGid !== '') {
                        ShopifyIdMapping::query()->updateOrCreate([
                            'shop_id' => $shop->id,
                            'entity_type' => 'order',
                            'source_id' => $sourceId,
                        ], [
                            'shopify_gid' => $maybeOrderGid,
                        ]);
                        $item->shopify_gid = $maybeOrderGid;
                    }

                    $item->status = 'queued';
                    $item->error_message = 'Shopify rate limited; will retry';
                    $prevRetries = (int) data_get($item->error_context, 'rate_limit_requeues', 0);
                    $item->error_context = [
                        'errors' => $create['errors'],
                        'fingerprint' => $fp,
                        'rate_limited' => true,
                        'rate_limit_requeues' => $prevRetries + 1,
                    ];
                    $item->started_at = null;
                    $item->finished_at = null;
                    $item->save();

                    $delay = $this->rateLimitDelaySeconds($prevRetries + 1);
                    $graphqlDelay = $this->graphqlThrottleDelaySeconds($create);
                    $retryAfterDelay = $this->httpRetryAfterDelaySeconds($create);
                    $shopThrottleDelay = $this->shopLevelThrottleDelaySeconds($shop->id, true);
                    $unknownThrottleCap = (int) env('SHOPIFY_ORDER_UNKNOWN_THROTTLE_MAX_DELAY', 15);
                    if ($unknownThrottleCap < 1) {
                        $unknownThrottleCap = 1;
                    }
                    if ($graphqlDelay > 0) {
                        $delay = $graphqlDelay;
                    } elseif ($retryAfterDelay > 0) {
                        $delay = $retryAfterDelay;
                    }
                    if ($graphqlDelay > 0) {
                        $delay = max($delay, min($shopThrottleDelay, $graphqlDelay * 2));
                    } elseif ($retryAfterDelay > 0) {
                        $delay = max($delay, min($shopThrottleDelay, $retryAfterDelay * 2));
                    } else {
                        $delay = max($delay, min($shopThrottleDelay, $unknownThrottleCap));
                    }
                    Log::warning('Order migration rate limited; releasing job', [
                        'run_id' => $run->id,
                        'shop' => $shop->shop_domain,
                        'source_id' => $sourceId,
                        'delay_seconds' => $delay,
                        'graphql_throttle_delay_seconds' => $graphqlDelay,
                        'retry_after_delay_seconds' => $retryAfterDelay,
                        'unknown_throttle_cap_seconds' => $unknownThrottleCap,
                        'rate_limit_requeues' => $prevRetries + 1,
                        'min_interval_seconds' => (int) env('SHOPIFY_ORDER_MIN_INTERVAL_SECONDS', 0),
                        'shop_throttle_level' => $this->getShopLevelThrottleLevel($shop->id),
                        'shop_throttle_delay_seconds' => $shopThrottleDelay,
                        'cooldown_until_ts' => $this->cache()->get($this->shopifyCooldownKey($shop->id)),
                    ]);

                    $this->setShopifyCooldown($shop->id, $delay);

                    $this->release($delay);
                    return;
                }

                $this->markFailed($run, $item, 'Shopify API error', [
                    'errors' => $create['errors'],
                    'fingerprint' => $fp,
                ]);
                return;
            }

            if (!empty($create['userErrors'])) {
                $firstUserErr = is_array($create['userErrors']) ? ($create['userErrors'][0] ?? null) : null;
                $userMsg = is_array($firstUserErr) ? (string) ($firstUserErr['message'] ?? '') : '';
                if ($this->isShopifyRateLimited(null, $userMsg)) {
                    $this->debugLogThrottledPayload($run->id, $shop->shop_domain, $sourceId, $payloadCore, $userMsg, $create);

                    $item->status = 'queued';
                    $item->error_message = 'Shopify rate limited; will retry';
                    $prevRetries = $prevRateLimitRequeues;
                    $item->error_context = [
                        'userErrors' => $create['userErrors'],
                        'fingerprint' => $fp,
                        'rate_limited' => true,
                        'rate_limit_requeues' => $prevRetries + 1,
                    ];
                    $item->started_at = null;
                    $item->finished_at = null;
                    $item->save();

                    $delay = $this->rateLimitDelaySeconds($prevRetries + 1);
                    $graphqlDelay = $this->graphqlThrottleDelaySeconds($create);
                    $retryAfterDelay = $this->httpRetryAfterDelaySeconds($create);
                    $shopThrottleDelay = $this->shopLevelThrottleDelaySeconds($shop->id, true);
                    $unknownThrottleCap = (int) env('SHOPIFY_ORDER_UNKNOWN_THROTTLE_MAX_DELAY', 15);
                    if ($unknownThrottleCap < 1) {
                        $unknownThrottleCap = 1;
                    }
                    if ($graphqlDelay > 0) {
                        $delay = $graphqlDelay;
                    } elseif ($retryAfterDelay > 0) {
                        $delay = $retryAfterDelay;
                    }
                    if ($graphqlDelay > 0) {
                        $delay = max($delay, min($shopThrottleDelay, $graphqlDelay * 2));
                    } elseif ($retryAfterDelay > 0) {
                        $delay = max($delay, min($shopThrottleDelay, $retryAfterDelay * 2));
                    } else {
                        $delay = max($delay, min($shopThrottleDelay, $unknownThrottleCap));
                    }
                    Log::warning('Order migration rate limited (userErrors); releasing job', [
                        'run_id' => $run->id,
                        'shop' => $shop->shop_domain,
                        'source_id' => $sourceId,
                        'delay_seconds' => $delay,
                        'graphql_throttle_delay_seconds' => $graphqlDelay,
                        'retry_after_delay_seconds' => $retryAfterDelay,
                        'unknown_throttle_cap_seconds' => $unknownThrottleCap,
                        'rate_limit_requeues' => $prevRetries + 1,
                        'min_interval_seconds' => (int) env('SHOPIFY_ORDER_MIN_INTERVAL_SECONDS', 0),
                        'shop_throttle_level' => $this->getShopLevelThrottleLevel($shop->id),
                        'shop_throttle_delay_seconds' => $shopThrottleDelay,
                        'cooldown_until_ts' => $this->cache()->get($this->shopifyCooldownKey($shop->id)),
                    ]);

                    $this->setShopifyCooldown($shop->id, $delay);

                    $this->release($delay);
                    return;
                }

                $this->markFailed($run, $item, 'Shopify userErrors', [
                    'userErrors' => $create['userErrors'],
                    'fingerprint' => $fp,
                ]);
                return;
            }

            $orderGid = (string) ($create['orderGid'] ?? '');
            if ($orderGid === '') {
                $this->markFailed($run, $item, 'Shopify orderCreate did not return an order id', [
                    'fingerprint' => $fp,
                ]);
                return;
            }

            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id' => $shop->id,
                'entity_type' => 'order',
                'source_id' => $sourceId,
            ], [
                'shopify_gid' => $orderGid,
            ]);

            $this->shopLevelThrottleDelaySeconds($shop->id, false);
            $this->applyMinIntervalCooldownAfterSuccess($shop->id);

            if (is_array($paymentCapture) && $paymentCapture !== []) {
                $manualPay = $sync->createManualPayment($shop, $orderGid, $paymentCapture);
                if (!empty($manualPay['errors']) || !empty($manualPay['userErrors'])) {
                    Log::warning('Post-create manual payment failed (order still created)', [
                        'run_id' => $run->id,
                        'shop' => $shop->shop_domain,
                        'source_id' => $sourceId,
                        'order_gid' => $orderGid,
                        'response' => $manualPay,
                    ]);
                }
            }

            $targetFulfillment = (string) ($payload['fulfillmentStatus'] ?? '');
            if (in_array($targetFulfillment, ['FULFILLED', 'PARTIAL'], true) && $locationGid !== '') {
                $fulfill = $sync->fulfillImportedOrder($shop, $orderGid, $locationGid);
                if (!empty($fulfill['errors']) || !empty($fulfill['userErrors'])) {
                    Log::warning('Post-create fulfillment failed (order still created)', [
                        'run_id' => $run->id,
                        'shop' => $shop->shop_domain,
                        'source_id' => $sourceId,
                        'order_gid' => $orderGid,
                        'response' => $fulfill,
                    ]);
                }
            }

            $item->shopify_gid = $orderGid;
            $item->fingerprint = $fp;
            $item->save();

            // Defer metadata syncing to reduce Shopify throttling impact.
            // This job uses orderGid+sourceId, so metadata cannot attach to the wrong order.
            if (count($tags) > 0 || (is_array($metafields) && count($metafields) > 0) || is_array($customAttributes)) {
                SyncShopifyOrderMetadataJob::dispatch($run->id, $sourceId, $orderGid, $tags, is_array($metafields) ? $metafields : [], $customAttributes);
            }

            $item->status     = 'succeeded';
            $item->finished_at = now();
            $item->save();

            // Language preference is not tracked per order in Magento.

            $run->refresh();
            $run->processed++;
            $run->succeeded++;
            $run->save();
            $this->appendReportRow($run, $item, 'succeeded');
        } catch (\Throwable $e) {
            if ($this->isShopifyRateLimited(null, $e->getMessage())) {
                $item->status = 'queued';
                $item->error_message = 'Shopify rate limited; will retry';
                $prevRetries = (int) data_get($item->error_context, 'rate_limit_requeues', 0);
                $item->error_context = [
                    'fingerprint' => $fp,
                    'exception' => get_class($e),
                    'rate_limited' => true,
                    'rate_limit_requeues' => $prevRetries + 1,
                    'error' => $e->getMessage(),
                ];
                $item->started_at = null;
                $item->finished_at = null;
                $item->save();

                $delay = $this->rateLimitDelaySeconds($prevRetries + 1);
                $shopThrottleDelay = $this->shopLevelThrottleDelaySeconds($shop->id, true);
                $delay = max($delay, $shopThrottleDelay);
                Log::warning('Order migration rate limited (exception); releasing job', [
                    'run_id' => $run->id,
                    'shop' => $shop->shop_domain,
                    'source_id' => $sourceId,
                    'delay_seconds' => $delay,
                    'rate_limit_requeues' => $prevRetries + 1,
                    'min_interval_seconds' => (int) env('SHOPIFY_ORDER_MIN_INTERVAL_SECONDS', 0),
                    'shop_throttle_level' => $this->getShopLevelThrottleLevel($shop->id),
                    'shop_throttle_delay_seconds' => $shopThrottleDelay,
                    'cooldown_until_ts' => $this->cache()->get($this->shopifyCooldownKey($shop->id)),
                    'error' => $e->getMessage(),
                ]);

                $this->setShopifyCooldown($shop->id, $delay);

                $this->release($delay);
                return;
            }

            Log::error('Order migration item failed', [
                'run_id' => $run->id,
                'shop' => $shop->shop_domain,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($run, $item, $e->getMessage(), [
                'fingerprint' => $fp,
                'exception' => get_class($e),
                'payload_present' => is_array($payload),
            ]);
        }
    }

    /**
     * Enrich an order array with raw invoice, shipment, and credit memo data
     * fetched from the Magento REST API. Each result is stored under a dedicated
     * key so the payload mapper can write them as JSON metafields.
     * Non-fatal — if any sub-request fails the key simply stays absent.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function enrichOrderWithMagentoData(\App\Models\Shop $shop, array $order): array
    {
        $magento  = app(MagentoClient::class);
        $conn     = $shop->magentoConnection;
        $orderId  = (string) ($order['entity_id'] ?? '');

        if ($orderId === '' || !$conn) {
            return $order;
        }

        $searchFilter = [
            'searchCriteria[filterGroups][0][filters][0][field]'         => 'order_id',
            'searchCriteria[filterGroups][0][filters][0][value]'         => $orderId,
            'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'eq',
        ];

        // 1. Invoices
        try {
            $res = $magento->request($conn, 'GET', '/V1/invoices', ['query' => $searchFilter]);
            $order['invoices_raw'] = $res['items'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Magento invoices for order ' . $orderId, ['error' => $e->getMessage()]);
            $order['invoices_raw'] = [];
        }

        // 2. Shipments (delivery notes)
        try {
            $res = $magento->request($conn, 'GET', '/V1/shipments', ['query' => $searchFilter]);
            $order['shipments_raw'] = $res['items'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Magento shipments for order ' . $orderId, ['error' => $e->getMessage()]);
            $order['shipments_raw'] = [];
        }

        // 3. Credit memos (credit notes)
        try {
            $res = $magento->request($conn, 'GET', '/V1/creditmemos', ['query' => $searchFilter]);
            $order['credit_notes_raw'] = $res['items'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Magento creditmemos for order ' . $orderId, ['error' => $e->getMessage()]);
            $order['credit_notes_raw'] = [];
        }

        return $order;
    }

    private function markFailed(MigrationRun $run, MigrationItem $item, string $message, array $context): void
    {
        $item->status = 'failed';
        $item->error_message = $message;
        $item->error_context = $context;
        $item->finished_at = now();
        $item->save();

        $run->refresh();
        if ($run->status !== 'cancelled') {
            $run->processed++;
            $run->failed++;
            $run->save();
        }

        $this->appendReportRow($run, $item, 'failed');
    }

    private function appendReportRow(MigrationRun $run, MigrationItem $item, string $status): void
    {
        try {
            $writer = app(MigrationRunReportWriter::class);
            $writer->appendRow($run, [
                'magento_order_id' => (string) $item->source_id,
                'order_number' => (string) ($this->order['increment_id'] ?? ''),
                'status' => $status,
                'reason' => $status === 'failed'
                    ? $writer->humanizeFailureReason($item)
                    : ($status === 'skipped' ? 'No changes detected (fingerprint matched)' : ''),
                'shopify_order_id' => (string) ($item->shopify_gid ?? ''),
                'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function debugLogThrottledPayload(int $runId, string $shopDomain, string $sourceId, array $payloadCore, string $message, array $response): void
    {
        if (!(bool) env('SHOPIFY_ORDER_DEBUG_PAYLOAD', false)) {
            return;
        }

        $size = strlen((string) json_encode($payloadCore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $snapshot = $payloadCore;
        if (isset($snapshot['email']) && is_string($snapshot['email'])) {
            $snapshot['email'] = '[redacted]';
        }
        if (isset($snapshot['phone']) && is_string($snapshot['phone'])) {
            $snapshot['phone'] = '[redacted]';
        }

        $cost = data_get($response, 'extensions.cost');
        $throttleStatus = is_array($cost) ? data_get($cost, 'throttleStatus') : null;
        $requested = is_array($cost) ? data_get($cost, 'requestedQueryCost') : null;
        $actual = is_array($cost) ? data_get($cost, 'actualQueryCost') : null;

        $err0 = data_get($response, 'errors.0');
        $errStatus = is_array($err0) ? ($err0['status'] ?? null) : null;
        $retryAfterMs = is_array($err0) ? ($err0['retry_after_ms'] ?? null) : null;

        Log::warning('Order migration throttled payload snapshot', [
            'run_id' => $runId,
            'shop' => $shopDomain,
            'source_id' => $sourceId,
            'payload_size_bytes' => $size,
            'line_items_count' => is_array($snapshot['lineItems'] ?? null) ? count($snapshot['lineItems']) : null,
            'shipping_lines_count' => is_array($snapshot['shippingLines'] ?? null) ? count($snapshot['shippingLines']) : null,
            'transactions_count' => is_array($snapshot['transactions'] ?? null) ? count($snapshot['transactions']) : null,
            'message' => $message,
            'shopify_error_status' => $errStatus,
            'shopify_retry_after_ms' => $retryAfterMs,
            'shopify_requestedQueryCost' => $requested,
            'shopify_actualQueryCost' => $actual,
            'shopify_throttleStatus' => is_array($throttleStatus) ? $throttleStatus : null,
            'payload' => $snapshot,
        ]);
    }

    private function applyMinIntervalCooldownAfterSuccess(int $shopId): void
    {
        $interval = (int) env('SHOPIFY_ORDER_MIN_INTERVAL_SECONDS', 0);
        if ($interval <= 0) {
            return;
        }

        $this->setShopifyCooldown($shopId, $interval);
    }

    private function shopLevelThrottleLevelKey(int $shopId): string
    {
        return 'shopify:order_throttle_level:'.$shopId;
    }

    private function getShopLevelThrottleLevel(int $shopId): int
    {
        $maxLevel = (int) env('SHOPIFY_ORDER_SHOP_LEVEL_THROTTLE_MAX', 6);
        if ($maxLevel < 0) {
            $maxLevel = 0;
        }

        $level = $this->cache()->get($this->shopLevelThrottleLevelKey($shopId));
        $level = is_numeric($level) ? (int) $level : 0;
        $level = max(0, min($maxLevel, $level));

        return $level;
    }

    private function shopLevelThrottleDelaySeconds(int $shopId, bool $throttled): int
    {
        if (!(bool) env('SHOPIFY_ORDER_SHOP_LEVEL_THROTTLE', false)) {
            return 0;
        }

        $maxLevel = (int) env('SHOPIFY_ORDER_SHOP_LEVEL_THROTTLE_MAX', 6);
        if ($maxLevel < 0) {
            $maxLevel = 0;
        }

        $level = $this->getShopLevelThrottleLevel($shopId);

        if ($throttled) {
            $level = min($maxLevel, $level + 1);
        } else {
            $level = max(0, $level - 1);
        }

        $this->cache()->put($this->shopLevelThrottleLevelKey($shopId), $level, now()->addHours(6));

        if ($level <= 0) {
            return 0;
        }

        $base = (int) env('SHOPIFY_ORDER_SHOP_LEVEL_THROTTLE_BASE_DELAY', 2);
        if ($base < 1) {
            $base = 1;
        }

        $max = (int) env('SHOPIFY_ORDER_SHOP_LEVEL_THROTTLE_MAX_DELAY', 60);
        if ($max < 1) {
            $max = 1;
        }

        $delay = $base * (2 ** ($level - 1));
        return (int) min($max, $delay);
    }

    private function isShopifyRateLimited(mixed $status, string $message): bool
    {
        $msg = strtolower(trim($message));
        if ($msg === '') {
            return $status === 429;
        }

        return ($status === 429) || str_contains($msg, 'too many') || str_contains($msg, 'thrott');
    }

    private function rateLimitDelaySeconds(int $requeues): int
    {
        $requeues = max(1, $requeues);
        $base = (int) env('SHOPIFY_ORDER_RATE_LIMIT_BASE_DELAY', 10);
        if ($base < 1) {
            $base = 1;
        }

        $max = (int) env('SHOPIFY_ORDER_RATE_LIMIT_MAX_DELAY', 60);
        if ($max < 1) {
            $max = 1;
        }

        $jitterMax = (int) env('SHOPIFY_ORDER_RATE_LIMIT_JITTER_MAX', 0);
        if ($jitterMax < 0) {
            $jitterMax = 0;
        }

        $delay = $base * (2 ** ($requeues - 1));
        $delay = (int) min($max, $delay);

        if ($jitterMax > 0) {
            $delay += random_int(0, $jitterMax);
        }

        return $delay;
    }

    /**
     * Derive a retry delay directly from Shopify GraphQL throttle status when present.
     * Returns 0 when not available.
     *
     * @param  array<string, mixed>  $response
     */
    private function graphqlThrottleDelaySeconds(array $response): int
    {
        $status = data_get($response, 'extensions.cost.throttleStatus');
        if (!is_array($status)) {
            return 0;
        }

        $currently = data_get($status, 'currentlyAvailable');
        $restoreRate = data_get($status, 'restoreRate');
        $requested = data_get($response, 'extensions.cost.requestedQueryCost');

        if (!is_numeric($currently) || !is_numeric($restoreRate) || !is_numeric($requested)) {
            return 0;
        }

        $currently = (float) $currently;
        $restoreRate = (float) $restoreRate;
        $requested = (float) $requested;

        if ($restoreRate <= 0) {
            return 0;
        }

        $need = max(0.0, $requested - $currently);
        if ($need <= 0) {
            return 0;
        }

        // Add a small buffer to reduce re-throttling due to concurrent requests / clock skew.
        $seconds = (int) ceil(($need / $restoreRate) + 0.5);
        return max(1, min(120, $seconds));
    }

    /**
     * Prefer Shopify-provided Retry-After on HTTP 429 responses when available.
     * Returns 0 when not present.
     *
     * @param  array<string, mixed>  $response
     */
    private function httpRetryAfterDelaySeconds(array $response): int
    {
        $err0 = data_get($response, 'errors.0');
        if (!is_array($err0)) {
            return 0;
        }

        $status = $err0['status'] ?? null;
        if (!is_numeric($status) || (int) $status !== 429) {
            return 0;
        }

        $ms = $err0['retry_after_ms'] ?? null;
        if (!is_numeric($ms)) {
            return 0;
        }

        $ms = (float) $ms;
        if ($ms <= 0) {
            return 0;
        }

        // Add a small buffer.
        return max(1, (int) ceil(($ms / 1000.0) + 0.5));
    }

    private function orderWriteLockKey(int $shopId, string $sourceId): string
    {
        $concurrency = (int) env('SHOPIFY_ORDER_WRITE_CONCURRENCY', 1);
        if ($concurrency < 1) {
            $concurrency = 1;
        }

        $shard = 0;
        if ($concurrency > 1) {
            $shard = (int) (abs(crc32($sourceId)) % $concurrency);
        }

        return 'shopify:order_write:'.$shopId.':'.$shard;
    }

    private function shopifyCooldownKey(int $shopId): string
    {
        return 'shopify:order_cooldown_until:'.$shopId;
    }

    private function releaseIfShopifyCooldownActive(int $shopId, MigrationItem $item): bool
    {
        $untilTs = $this->cache()->get($this->shopifyCooldownKey($shopId));
        $untilTs = is_numeric($untilTs) ? (int) $untilTs : 0;
        if ($untilTs <= 0) {
            return false;
        }

        $nowTs = now()->timestamp;
        if ($untilTs <= $nowTs) {
            return false;
        }

        $remaining = max(1, $untilTs - $nowTs);

        $item->status = 'queued';
        $item->started_at = null;
        $item->finished_at = null;
        $item->save();

        $this->release($remaining);
        return true;
    }

    private function setShopifyCooldown(int $shopId, int $seconds): void
    {
        $seconds = max(1, $seconds);
        $key = $this->shopifyCooldownKey($shopId);
        $existingUntil = $this->cache()->get($key);
        $existingUntil = is_numeric($existingUntil) ? (int) $existingUntil : 0;

        $untilTs = now()->addSeconds($seconds)->timestamp;
        if ($existingUntil > $untilTs) {
            $untilTs = $existingUntil;
        }

        $ttl = max(60, ($untilTs - now()->timestamp) + 60);
        $this->cache()->put($key, $untilTs, $ttl);
    }

    private function cache(): Repository
    {
        $store = (string) env('SHOPIFY_ORDER_CACHE_STORE', 'file');
        return Cache::store($store);
    }

    private function orderCreateLastAttemptKey(int $shopId): string
    {
        return 'shopify:order_create_last_attempt:'.$shopId;
    }

    private function recordOrderCreateAttempt(int $shopId): void
    {
        $key = $this->orderCreateLastAttemptKey($shopId);
        $this->cache()->put($key, now()->timestamp, 300);
    }

    /**
     * Get the required delay to respect minimum interval between orderCreate attempts.
     * For development/trial stores, Shopify limits to 5 orders/minute (12 sec/order).
     * Returns 0 if no delay needed, otherwise returns seconds to wait.
     */
    private function getMinIntervalDelay(int $shopId): int
    {
        $interval = (int) env('SHOPIFY_ORDER_MIN_INTERVAL_SECONDS', 0);
        if ($interval <= 0) {
            return 0;
        }

        $lastAttemptTs = $this->cache()->get($this->orderCreateLastAttemptKey($shopId));
        $lastAttemptTs = is_numeric($lastAttemptTs) ? (int) $lastAttemptTs : 0;

        if ($lastAttemptTs <= 0) {
            return 0;
        }

        $nowTs = now()->timestamp;
        $elapsed = $nowTs - $lastAttemptTs;

        if ($elapsed >= $interval) {
            return 0;
        }

        $remaining = $interval - $elapsed;
        return max(1, $remaining);
    }
}
