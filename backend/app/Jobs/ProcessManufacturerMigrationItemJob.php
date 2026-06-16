<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Models\ShopifyIdMapping;
use App\Services\Migration\ManufacturerFingerprint;
use App\Services\Migration\MigrationRunReportWriter;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Magento\MagentoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessManufacturerMigrationItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    private int $runId;

    private string $sourceId;

    public function __construct(int $runId, string $sourceId)
    {
        $this->runId = $runId;
        $this->sourceId = trim($sourceId);
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.magentoConnection')->find($this->runId);
        if (! $run || in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $shop = $run->shop;
        $conn = $shop ? $shop->magentoConnection : null;
        if (! $shop || ! $conn || $this->sourceId === '') {
            return;
        }

        $item = MigrationItem::query()->firstOrCreate([
            'migration_run_id' => $run->id,
            'entity_type' => 'manufacturer',
            'source_id' => $this->sourceId,
        ], [
            'status' => 'queued',
        ]);

        if (in_array($item->status, ['skipped', 'succeeded'], true)) {
            return;
        }

        $item->status = 'running';
        $item->started_at = now();
        $item->error_message = null;
        $item->save();

        try {
            $magento = app(MagentoClient::class);
            $fingerprints = app(ManufacturerFingerprint::class);

            $res = $magento->searchManufacturers($conn, 2000, 1);
            $manufacturer = null;
            foreach ($res['manufacturers'] ?? [] as $m) {
                if (($m['id'] ?? '') === $this->sourceId) {
                    $manufacturer = $m;
                    break;
                }
            }
            if (! is_array($manufacturer)) {
                $this->markFailed($run, $item, 'Magento manufacturer not found');

                return;
            }

            $vendorName = trim((string) ($manufacturer['name'] ?? ''));

            if ($vendorName === '') {
                $this->markFailed($run, $item, 'Manufacturer has no name');

                return;
            }

            $fp = $fingerprints->make($manufacturer);
            $previousFp = $this->latestSucceededFingerprint($shop->id, $this->sourceId);
            if (is_string($previousFp) && $previousFp !== '' && hash_equals($previousFp, $fp)) {
                $item->status = 'skipped';
                $item->fingerprint = $fp;
                $item->finished_at = now();
                $item->save();
                try {
                    app(MigrationRunReportWriter::class)->appendRow($run, [
                        'magento_manufacturer_id' => $item->source_id,
                        'manufacturer_name' => $vendorName,
                        'status' => 'skipped',
                        'reason' => 'No changes detected (fingerprint matched)',
                        'shopify_vendor' => $vendorName,
                        'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
                    ]);
                } catch (\Throwable) {
                    // ignore
                }
                $this->incrementRunCounters($run->id, ['processed' => 1]);

                return;
            }

            ShopifyIdMapping::query()->updateOrCreate([
                'shop_id'     => $shop->id,
                'entity_type' => 'manufacturer',
                'source_id'   => $this->sourceId,
            ], [
                // Stores canonical Shopify product vendor label (not a Shopify GID).
                'shopify_gid' => $vendorName,
            ]);

            // Magento translations handled per product.

            $item->status = 'succeeded';
            $item->fingerprint = $fp;
            $item->finished_at = now();
            $item->save();
            try {
                app(MigrationRunReportWriter::class)->appendRow($run, [
                    'magento_manufacturer_id' => $item->source_id,
                    'manufacturer_name' => $vendorName,
                    'status' => 'succeeded',
                    'reason' => '',
                    'shopify_vendor' => $vendorName,
                    'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
                ]);
            } catch (\Throwable) {
                // ignore
            }

            $this->incrementRunCounters($run->id, [
                'processed' => 1,
                'succeeded' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::error('Manufacturer migration item failed', [
                'run_id' => $run->id,
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($run, $item, $e->getMessage());
        }
    }

    private function latestSucceededFingerprint(int $shopId, string $sourceId): ?string
    {
        $fp = MigrationItem::query()
            ->join('migration_runs', 'migration_runs.id', '=', 'migration_items.migration_run_id')
            ->where('migration_runs.shop_id', $shopId)
            ->where('migration_runs.type', 'manufacturers')
            ->where('migration_items.entity_type', 'manufacturer')
            ->where('migration_items.source_id', $sourceId)
            ->where('migration_items.status', 'succeeded')
            ->orderByDesc('migration_items.id')
            ->value('migration_items.fingerprint');

        return is_string($fp) && $fp !== '' ? $fp : null;
    }

    /**
     * @param  array{processed?: int, succeeded?: int, failed?: int}  $delta
     */
    private function incrementRunCounters(int $runId, array $delta): void
    {
        DB::transaction(function () use ($runId, $delta) {
            $run = MigrationRun::query()->lockForUpdate()->find($runId);
            if (! $run) {
                return;
            }
            $run->processed = (int) $run->processed + (int) ($delta['processed'] ?? 0);
            $run->succeeded = (int) $run->succeeded + (int) ($delta['succeeded'] ?? 0);
            $run->failed = (int) $run->failed + (int) ($delta['failed'] ?? 0);
            $run->save();
        });
    }

    private function markFailed(MigrationRun $run, MigrationItem $item, string $message): void
    {
        $item->status = 'failed';
        $item->error_message = $message;
        $item->finished_at = now();
        $item->save();
        try {
            $writer = app(MigrationRunReportWriter::class);
            $writer->appendRow($run, [
                'magento_manufacturer_id' => $item->source_id,
                'manufacturer_name' => '',
                'status' => 'failed',
                'reason' => $writer->humanizeFailureReason($item),
                'shopify_vendor' => '',
                'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable) {
            // ignore
        }

        $this->incrementRunCounters($run->id, [
            'processed' => 1,
            'failed' => 1,
        ]);
    }
}
