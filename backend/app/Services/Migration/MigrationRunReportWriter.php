<?php

namespace App\Services\Migration;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MigrationRunReportWriter
{
    private const FOOTER_SEPARATOR = '--- END OF REPORT ---';

    public function init(MigrationRun $run, array $meta = []): void
    {
        try {
            $path = $this->reportFilePath($run);
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            // Create/overwrite.
            $fp = @fopen($path, 'wb');
            if (! $fp) {
                return;
            }

            // UTF-8 BOM for Excel.
            @fwrite($fp, "\xEF\xBB\xBF");

            // Metadata rows as comments.
            $writer = $this->csvRowWriter($fp);

            $metaRows = $this->metadataRowsForRun($run, $meta);
            foreach ($metaRows as $row) {
                // Use single-column comment rows to keep CSV parseable.
                $writer([$row]);
            }

            // Header row for this migration type.
            $header = $this->headersForType((string) $run->type);
            $writer($header);

            @fclose($fp);

            if (! is_string($run->report_path) || trim($run->report_path) === '') {
                $run->report_path = $path;
                $run->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Migration report init failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function finalize(int $runId, array $summary = []): void
    {
        try {
            $run = MigrationRun::query()->find($runId);
            if (! $run) {
                return;
            }

            $path = $this->reportFilePath($run);
            $fp = @fopen($path, 'ab');
            if (! $fp) {
                return;
            }

            @flock($fp, LOCK_EX);
            @fputcsv($fp, [self::FOOTER_SEPARATOR]);
            @fputcsv($fp, ['summary_label', 'value']);
            @fputcsv($fp, ['summary_processed', (string) ($summary['processed'] ?? $run->processed ?? 0)]);
            @fputcsv($fp, ['summary_succeeded', (string) ($summary['succeeded'] ?? $run->succeeded ?? 0)]);
            @fputcsv($fp, ['summary_failed', (string) ($summary['failed'] ?? $run->failed ?? 0)]);
            @fputcsv($fp, ['summary_finished_at', $run->finished_at ? $run->finished_at->toDateTimeString() : now()->toDateTimeString()]);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        } catch (\Throwable $e) {
            try {
                Log::warning('Migration report finalize failed', [
                    'run_id' => $runId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /**
     * Append exactly one row.
     *
     * @param  array<int|string, mixed> $row
     */
    public function appendRow(MigrationRun|int $runOrId, array $row): void
    {
        try {
            $run = $runOrId instanceof MigrationRun
                ? $runOrId
                : MigrationRun::query()->find($runOrId);
            if (! $run) {
                return;
            }

            $path = $this->reportFilePath($run);
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $fp = @fopen($path, 'ab');
            if (! $fp) {
                return;
            }

            // flock + fputcsv guarantees no interleaving across parallel workers.
            @flock($fp, LOCK_EX);

            // Convert keys to ordered list if associative.
            $ordered = array_is_list($row) ? array_values($row) : $this->orderedRowForType((string) $run->type, $row);

            @fputcsv($fp, $ordered);

            @flock($fp, LOCK_UN);
            @fclose($fp);
        } catch (\Throwable $e) {
            // Swallow I/O errors so migrations are never affected.
            try {
                Log::warning('Migration report appendRow failed', [
                    'run_id' => $runOrId instanceof MigrationRun ? $runOrId->id : $runOrId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /**
     * Build a short plain-language reason for a failed/skipped MigrationItem.
     */
    public function humanizeFailureReason(MigrationItem $item): string
    {
        try {
            $ctx = $item->error_context;
            $msg = null;

            if (is_array($ctx)) {
                $shopifyErrMsg = data_get($ctx, 'errors.0.message')
                    ?: data_get($ctx, 'errors.0.error');
                $userErrMsg = data_get($ctx, 'userErrors.0.message');

                if (is_string($shopifyErrMsg) && $shopifyErrMsg !== '') {
                    $msg = $shopifyErrMsg;
                } elseif (is_string($userErrMsg) && $userErrMsg !== '') {
                    $msg = $userErrMsg;
                }
            }

            if (! is_string($msg) || $msg === '') {
                if (is_string($item->error_message) && trim($item->error_message) !== '') {
                    $msg = $item->error_message;
                } else {
                    $msg = 'Failed';
                }
            }

            // Ensure it stays short.
            $msg = Str::of($msg)->trim();
            if ($msg->length() > 240) {
                $msg = $msg->substr(0, 240).'…';
            }

            return (string) $msg;
        } catch (\Throwable) {
            return 'Failed';
        }
    }

    /**
     * @return array<int, string>
     */
    public function headersForType(string $type): array
    {
        return match ($type) {
            'products' => [
                'magento_product_id',
                'product_number',
                'product_name',
                'variant_count',
                'status',
                'reason',
                'shopify_product_id',
                'migrated_at_utc',
            ],
            'manufacturers' => [
                'magento_manufacturer_id',
                'manufacturer_name',
                'status',
                'reason',
                'shopify_vendor',
                'migrated_at_utc',
            ],
            'markets' => [
                'magento_store_view_id',
                'store_view_name',
                'status',
                'reason',
                'shopify_market_gid',
                'migrated_at_utc',
            ],
            'customers' => [
                'magento_customer_id',
                'email',
                'status',
                'reason',
                'shopify_customer_id',
                'migrated_at_utc',
            ],
            'orders' => [
                'magento_order_id',
                'order_number',
                'status',
                'reason',
                'shopify_order_id',
                'migrated_at_utc',
            ],
            'newsletter' => [
                'email',
                'status',
                'reason',
                'migrated_at_utc',
            ],
            'discounts' => [
                'magento_promotion_id',
                'promotion_name',
                'shopify_discount_type',
                'shopify_discount_gid',
                'code_count',
                'status',
                'reason',
                'migrated_at_utc',
            ],
            default => [
                'entity_id',
                'status',
                'reason',
                'migrated_at_utc',
            ],
        };
    }

    /**
     * @param  array<string, mixed> $meta
     */
    private function metadataRowsForRun(MigrationRun $run, array $meta): array
    {
        $startedAt = $run->started_at ? $run->started_at->toDateTimeString() : (string) now();
        $shop = $run->relationLoaded('shop') ? $run->shop : $run->shop()->first();
        $shopName = is_object($shop) ? (string) ($shop->shop_domain ?? ('Shop #' . $run->shop_id)) : ('Shop #' . $run->shop_id);
        $rows = [];

        $rows[] = '# ======================================';
        $rows[] = '# ICTECHM2SMigrator Migration Report';
        $rows[] = '# ======================================';
        $rows[] = '# migration_run_id=' . $run->id;
        $rows[] = '# migration_type=' . $run->type;
        $rows[] = '# shop_id=' . $run->shop_id;
        $rows[] = '# shop_name=' . $shopName;
        $rows[] = '# started_at_utc=' . $startedAt;

        if (! empty($meta['filters'])) {
            $rows[] = '# filters=' . (is_string($meta['filters']) ? $meta['filters'] : json_encode($meta['filters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Optional notes.
        if (! empty($meta['notes'])) {
            $notes = is_string($meta['notes']) ? $meta['notes'] : json_encode($meta['notes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rows[] = '# notes=' . $notes;
        }
        $rows[] = '# ======================================';

        return $rows;
    }

    private function reportFilePath(MigrationRun $run): string
    {
        // If report_path already set, use it.
        if (is_string($run->report_path) && trim($run->report_path) !== '') {
            return $run->report_path;
        }

        // Deterministic fallback.
        return storage_path(
            'app/migration-reports/shop_' . (int) $run->shop_id . '/run_' . (int) $run->id . '.csv'
        );
    }

    /**
     * Return an ordered list matching headers.
     *
     * @param  array<string, mixed> $row
     * @return array<int, mixed>
     */
    private function orderedRowForType(string $type, array $row): array
    {
        $headers = $this->headersForType($type);
        $out = [];
        foreach ($headers as $h) {
            $out[] = array_key_exists($h, $row) ? $row[$h] : '';
        }
        return $out;
    }

    /**
     * @return callable(array<int|string, mixed>): void
     */
    private function csvRowWriter($fp): callable
    {
        return function (array $row) use ($fp): void {
            @fputcsv($fp, array_values($row));
        };
    }
}
