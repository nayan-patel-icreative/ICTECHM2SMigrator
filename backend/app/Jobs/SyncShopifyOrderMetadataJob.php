<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Services\Migration\ShopifyOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncShopifyOrderMetadataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 20;

    private int $runId;

    private string $sourceId;

    private string $orderGid;

    /** @var array<int, string> */
    private array $tags;

    /** @var array<int, array<string, mixed>> */
    private array $metafields;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $customAttributes;

    /**
     * @param array<int, string> $tags
     * @param array<int, array<string, mixed>> $metafields
     * @param array<int, array<string, mixed>>|null $customAttributes
     */
    public function __construct(int $runId, string $sourceId, string $orderGid, array $tags = [], array $metafields = [], ?array $customAttributes = null)
    {
        $this->runId = $runId;
        $this->sourceId = $sourceId;
        $this->orderGid = $orderGid;
        $this->tags = $tags;
        $this->metafields = $metafields;
        $this->customAttributes = $customAttributes;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop')->find($this->runId);
        if (!$run || !$run->shop) {
            return;
        }

        if ($run->status === 'cancelled') {
            return;
        }

        $shop = $run->shop;

        $item = MigrationItem::query()->firstOrCreate([
            'migration_run_id' => $run->id,
            'entity_type' => 'order_metadata',
            'source_id' => $this->sourceId,
        ], [
            'status' => 'queued',
        ]);

        if (in_array($item->status, ['skipped', 'succeeded'], true)) {
            return;
        }

        $prevRequeues = (int) data_get($item->error_context, 'rate_limit_requeues', 0);

        $item->status = 'running';
        $item->started_at = now();
        $item->error_message = null;
        $item->save();

        $sync = app(ShopifyOrderSyncService::class);

        if ($this->releaseIfShopifyCooldownActive($shop->id, $item)) {
            return;
        }

        $lock = Cache::lock($this->orderWriteLockKey($shop->id, $this->sourceId), 300);
        $res = $lock->block(120, function () use ($sync, $shop) {
            return $sync->updateOrderMetadata($shop, $this->orderGid, $this->tags, $this->metafields, $this->customAttributes);
        });

        if (!empty($res['errors']) || !empty($res['userErrors'])) {
            $msg = strtolower((string) json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $isRateLimited = str_contains($msg, 'thrott') || str_contains($msg, 'too many') || str_contains($msg, 'rate');

            if ($isRateLimited) {
                $item->status = 'queued';
                $item->error_message = 'Shopify rate limited; will retry';
                $item->error_context = [
                    'response' => $res,
                    'rate_limited' => true,
                    'rate_limit_requeues' => $prevRequeues + 1,
                ];
                $item->started_at = null;
                $item->finished_at = null;
                $item->save();

                $delay = $this->rateLimitDelaySeconds($prevRequeues + 1);
                Log::warning('Order metadata sync rate limited; releasing job', [
                    'run_id' => $run->id,
                    'shop' => $shop->shop_domain,
                    'source_id' => $this->sourceId,
                    'delay_seconds' => $delay,
                    'rate_limit_requeues' => $prevRequeues + 1,
                ]);

                $this->setShopifyCooldown($shop->id, $delay);
                $this->release($delay);
                return;
            }

            $item->status = 'failed';
            $item->error_message = 'Shopify metadata sync failed';
            $item->error_context = $res;
            $item->finished_at = now();
            $item->save();
            return;
        }

        $item->status = 'succeeded';
        $item->finished_at = now();
        $item->save();
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

    private function shopifyCooldownKey(int $shopId): string
    {
        return 'shopify:order_cooldown_until:'.$shopId;
    }

    private function releaseIfShopifyCooldownActive(int $shopId, MigrationItem $item): bool
    {
        $untilTs = Cache::get($this->shopifyCooldownKey($shopId));
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
        $existingUntil = Cache::get($key);
        $existingUntil = is_numeric($existingUntil) ? (int) $existingUntil : 0;

        $untilTs = now()->addSeconds($seconds)->timestamp;
        if ($existingUntil > $untilTs) {
            $untilTs = $existingUntil;
        }

        $ttl = max(60, ($untilTs - now()->timestamp) + 60);
        Cache::put($key, $untilTs, $ttl);
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
}
