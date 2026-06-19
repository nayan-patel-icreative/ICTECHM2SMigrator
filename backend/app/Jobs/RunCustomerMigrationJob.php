<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Models\ShopifyIdMapping;
use App\Services\Migration\CustomerFingerprint;
use App\Services\Migration\MigrationRunReportWriter;
use App\Services\Migration\CustomerPayloadMapper;
use App\Services\Migration\ShopifyCustomerSyncService;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use App\Services\Magento\MagentoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunCustomerMigrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    private int $runId;

    private int $page;

    /** @var array<int, mixed> */
    private array $filter;

    /**
     * @param array<int, mixed> $filter
     */
    public function __construct(int $runId, int $page = 1, array $filter = [])
    {
        $this->runId = $runId;
        $this->page = max(1, $page);
        $this->filter = $filter;
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

        try {
            $shop = $run->shop;
            $conn = $shop ? $shop->magentoConnection : null;

            if (!$shop || !$conn) {
                $run->status = 'failed';
                $run->finished_at = now();
                $run->save();
                return;
            }

            $run->status = 'running';
            $run->save();

            $magento = app(MagentoClient::class);
            $mapper = app(CustomerPayloadMapper::class);
            $fingerprints = app(CustomerFingerprint::class);
            $sync = app(ShopifyCustomerSyncService::class);

            $perPage = 50;

            $run->refresh();
            if ($run->status === 'cancelled') {
                return;
            }

            $res = $magento->searchCustomers($conn, $perPage, $this->page, $this->filter);
            $customers = $res['customers'] ?? [];
            $total = (int) ($res['total'] ?? 0);

            if (!is_array($customers) || count($customers) === 0) {
                $run->status = 'finished';
                $run->finished_at = now();
                $run->save();
                app(MigrationRunReportWriter::class)->finalize($run->id);
                return;
            }

            foreach ($customers as $c) {
                $run->refresh();
                if ($run->status === 'cancelled') {
                    return;
                }

                $sourceId = (string) ($c['id'] ?? '');
                if ($sourceId === '') {
                    continue;
                }

                $existingMapping = ShopifyIdMapping::query()
                    ->where('shop_id', $shop->id)
                    ->where('entity_type', 'customer')
                    ->where('source_id', $sourceId)
                    ->first();
                $existingCustomerGid = $existingMapping ? (string) $existingMapping->shopify_gid : '';
                $hasExistingShopifyCustomer = false;
                if ($existingCustomerGid !== '') {
                    $hasExistingShopifyCustomer = $this->shopifyCustomerExists($shop, $existingCustomerGid);
                    if (!$hasExistingShopifyCustomer && $existingMapping) {
                        $existingMapping->delete();
                        $existingCustomerGid = '';
                    }
                }

                $item = MigrationItem::query()->firstOrCreate([
                    'migration_run_id' => $run->id,
                    'entity_type' => 'customer',
                    'source_id' => $sourceId,
                ], [
                    'status' => 'queued',
                ]);

                if (in_array($item->status, ['skipped', 'succeeded'], true)) {
                    continue;
                }

                $item->status = 'running';
                $item->started_at = now();
                $item->error_message = null;
                $item->error_context = null;
                $item->save();

                try {
                    $customerLocale = '';
                    $customerLangName = '';

                    // --- Fetch newsletter subscription status from Magento ---
                    $newsletterStatus = 'NOT_FOUND';
                    try {
                        $newsletterStatus = $magento->getCustomerNewsletterStatus($conn, $sourceId);
                    } catch (\Throwable) {
                        // Non-fatal: proceed without newsletter status
                    }

                    $payload = $mapper->mapCustomer($c, $shop, $newsletterStatus);
                    if ($customerLocale !== '') {
                        $payload['locale'] = $customerLocale;
                    }

                    // Build fingerprint BEFORE moving emailMarketingConsent to __metafields
                    // so that changes in newsletter subscription trigger re-migration
                    $fp = $fingerprints->make($payload);

                    $metafields = $mapper->mapMagentoMetafields($c, $shop, $newsletterStatus);
                    if (is_array($metafields) && count($metafields) > 0) {
                        $payload['__metafields'] = $metafields;
                    }


                    $previousFp = $this->latestSucceededFingerprint($shop->id, $sourceId);
                    if ($hasExistingShopifyCustomer && is_string($previousFp) && $previousFp !== '' && hash_equals($previousFp, $fp)) {
                        $item->status = 'skipped';
                        $item->fingerprint = $fp;
                        $item->finished_at = now();
                        $item->save();
                        $this->appendReportRow($run, $item, 'skipped', $c);

                        $run->processed++;
                        $run->save();
                        continue;
                    }

                    if ($hasExistingShopifyCustomer && $item->fingerprint && hash_equals($item->fingerprint, $fp)) {
                        $item->status = 'skipped';
                        $item->finished_at = now();
                        $item->save();
                        $this->appendReportRow($run, $item, 'skipped', $c);

                        $run->processed++;
                        $run->save();
                        continue;
                    }

                    $syncRes = $sync->upsertBySourceId($shop, $sourceId, $payload);
                    if (!empty($syncRes['errors']) || !empty($syncRes['userErrors'])) {
                        $item->status = 'failed';
                        $item->error_message = 'Shopify customer upsert failed';
                        $item->error_context = $syncRes;
                        $item->finished_at = now();
                        $item->save();
                        $this->appendReportRow($run, $item, 'failed', $c);

                        $run->processed++;
                        $run->failed++;
                        $run->save();
                        continue;
                    }

                    $item->status     = 'succeeded';
                    $item->fingerprint = $fp;
                    $item->shopify_gid = (string) ($syncRes['customerGid'] ?? '');
                    $item->finished_at = now();
                    $item->save();
                    $this->appendReportRow($run, $item, 'succeeded', $c);

                    ShopifyIdMapping::query()->updateOrCreate([
                        'shop_id'     => $shop->id,
                        'entity_type' => 'customer',
                        'source_id'   => $sourceId,
                    ], [
                        'shopify_gid' => (string) ($syncRes['customerGid'] ?? ''),
                    ]);

                    // --- Non-blocking: store customer language preference ---
                    try {
                        if ($customerLocale !== '' && !empty($syncRes['customerGid'])) {
                            $translationSync = app(ShopifyTranslationSyncService::class);
                            $translationSync->storeLanguagePreferenceMetafield(
                                $shop,
                                (string) $syncRes['customerGid'],
                                $customerLocale,
                                $customerLangName
                            );
                        }
                    } catch (\Throwable) {
                        // Non-fatal — customer migration already succeeded
                    }

                    $run->processed++;
                    $run->succeeded++;
                    $run->save();
                } catch (\Throwable $e) {
                    $item->status = 'failed';
                    $item->error_message = $e->getMessage();
                    $item->error_context = ['trace' => substr($e->getTraceAsString(), 0, 2000)];
                    $item->finished_at = now();
                    $item->save();
                    $this->appendReportRow($run, $item, 'failed', $c);

                    $run->processed++;
                    $run->failed++;
                    $run->save();
                }
            }

            if (($this->page * $perPage) < max(0, $total)) {
                self::dispatch($run->id, $this->page + 1, $this->filter);
                return;
            }

            $run->status = 'finished';
            $run->finished_at = now();
            $run->save();
            app(MigrationRunReportWriter::class)->finalize($run->id);
        } catch (\Throwable $e) {
            Log::error('Customer migration job failed', [
                'run_id' => $run->id,
                'shop' => optional($run->shop)->shop_domain,
                'error' => $e->getMessage(),
            ]);
            $run->status = 'failed';
            $run->finished_at = now();
            $run->save();
            app(MigrationRunReportWriter::class)->finalize($run->id);
        }
    }

    private function latestSucceededFingerprint(int $shopId, string $sourceId): ?string
    {
        $row = MigrationItem::query()
            ->select('migration_items.fingerprint')
            ->join('migration_runs', 'migration_runs.id', '=', 'migration_items.migration_run_id')
            ->where('migration_runs.shop_id', $shopId)
            ->where('migration_runs.type', 'customers')
            ->where('migration_items.entity_type', 'customer')
            ->where('migration_items.source_id', $sourceId)
            ->where('migration_items.status', 'succeeded')
            ->whereNotNull('migration_items.fingerprint')
            ->orderByDesc('migration_items.id')
            ->first();

        if (!$row) {
            return null;
        }

        $fp = $row->fingerprint;
        return is_string($fp) && $fp !== '' ? $fp : null;
    }

    private function shopifyCustomerExists(\App\Models\Shop $shop, string $customerGid): bool
    {
        try {
            $client = app(ShopifyAdminGraphqlClient::class);
            $q = <<<'GQL'
query CustomerExists($id: ID!) {
  customer(id: $id) { id }
}
GQL;

            $res = $client->query($shop, $q, ['id' => $customerGid]);
            if (isset($res['errors'])) {
                return true;
            }

            $id = (string) data_get($res, 'data.customer.id', '');
            return $id !== '';
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function appendReportRow(MigrationRun $run, MigrationItem $item, string $status, array $customer): void
    {
        try {
            $writer = app(MigrationRunReportWriter::class);
            $writer->appendRow($run, [
                'magento_customer_id' => (string) $item->source_id,
                'email' => (string) ($customer['email'] ?? ''),
                'status' => $status,
                'reason' => $status === 'failed'
                    ? $writer->humanizeFailureReason($item)
                    : ($status === 'skipped' ? 'No changes detected (fingerprint matched)' : ''),
                'shopify_customer_id' => (string) ($item->shopify_gid ?? ''),
                'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }
}
