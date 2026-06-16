<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Models\ShopifyIdMapping;
use App\Services\Migration\DiscountFingerprint;
use App\Services\Migration\DiscountMapper;
use App\Services\Migration\MigrationRunReportWriter;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use App\Services\Magento\MagentoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDiscountMigrationItemJob implements ShouldQueue
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
        $this->runId    = $runId;
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
            'entity_type'      => 'discount',
            'source_id'        => $this->sourceId,
        ], [
            'status' => 'queued',
        ]);

        if (in_array($item->status, ['skipped', 'succeeded'], true)) {
            return;
        }

        $item->status      = 'running';
        $item->started_at  = now();
        $item->error_message = null;
        $item->save();

        try {
            $magento      = app(MagentoClient::class);
            $fingerprints = app(DiscountFingerprint::class);
            $mapper       = app(DiscountMapper::class);
            $graphql      = app(ShopifyAdminGraphqlClient::class);

            // Fetch single promotion by ID
            $promotion = $magento->fetchSalesRule($conn, $this->sourceId);

            if (! is_array($promotion) || empty($promotion)) {
                $this->markFailed($run, $item, 'Magento sales rule not found');

                return;
            }

            $couponType = $promotion['coupon_type'] ?? null;
            if ($couponType === 2 || $couponType === '2' || $couponType === 'SPECIFIC_COUPON') {
                $coupons = $magento->fetchCouponsForRule($conn, $this->sourceId);
                if (!empty($coupons)) {
                    $promotion['coupon_code'] = $coupons[0]['code'] ?? '';
                    if (isset($coupons[0]['usage_limit'])) {
                        $promotion['uses_per_coupon'] = $coupons[0]['usage_limit'];
                    }
                    if (isset($coupons[0]['usage_per_customer'])) {
                        $promotion['uses_per_customer'] = $coupons[0]['usage_per_customer'];
                    }
                }
            }

            $promotionName = trim((string) ($promotion['name'] ?? ''));



            // Debug: log the promotion structure
            Log::debug('Discount migration: promotion data loaded', [
                'run_id'         => $run->id,
                'source_id'      => $this->sourceId,
                'name'           => $promotionName,
            ]);

            // Fingerprint check
            $fp         = $fingerprints->make($promotion);
            $previousFp = $this->latestSucceededFingerprint($shop->id, $this->sourceId);

            $existingGid = $this->existingShopifyGid($shop->id, $this->sourceId);
            if ($existingGid !== null) {
                if (!$this->checkGidExistsOnShopify($shop, $existingGid, $graphql)) {
                    ShopifyIdMapping::query()
                        ->where('shop_id', $shop->id)
                        ->where('entity_type', 'discount')
                        ->where('source_id', $this->sourceId)
                        ->delete();
                    $existingGid = null;
                }
            }

            if (is_string($previousFp) && $previousFp !== '' && hash_equals($previousFp, $fp)) {
                // Only skip if the Shopify discount still exists
                if ($existingGid !== null) {
                    $this->markSkipped($run, $item, $fp, $promotionName, 'No changes detected (fingerprint matched)');

                    return;
                }
            }

            // Map promotion to Shopify payload
            $mapped = $mapper->map($promotion);

            if ($mapped['skipped']) {
                $this->markSkipped($run, $item, $fp, $promotionName, $mapped['skip_reason'] ?? 'Unmappable discount type');

                return;
            }

            $createMutation = $mapped['mutation'];
            $variables      = $mapped['variables'];
            $issues         = $mapped['issues'];

            // Extract non-GraphQL fields that need separate API calls
            $extraCodes = $variables['_extra_codes'] ?? [];
            $metafields = $variables['_metafields'] ?? [];
            unset($variables['_extra_codes'], $variables['_metafields']);



            // Determine create vs update
            $existingGid = $this->existingShopifyGid($shop->id, $this->sourceId);
            $shopifyGid  = null;

            if ($existingGid !== null) {
                // Try update first
                $updateMutation = $mapper->updateMutation($createMutation);
                $updateVars     = array_merge(['id' => $existingGid], $variables);
                $updateResult   = $graphql->query($shop, $this->buildMutation($updateMutation, $variables), $updateVars);

                if ($this->isStaleGidError($updateResult)) {
                    // Discount was deleted in Shopify — remove stale mapping and fall back to create
                    ShopifyIdMapping::query()
                        ->where('shop_id', $shop->id)
                        ->where('entity_type', 'discount')
                        ->where('source_id', $this->sourceId)
                        ->delete();

                    $existingGid = null;
                } elseif (! empty($updateResult['errors'])) {
                    $this->markFailed($run, $item, $this->formatErrors($updateResult['errors']));

                    return;
                } else {
                    $userErrors = $this->extractUserErrors($updateMutation, $updateResult);
                    if (! empty($userErrors)) {
                        $this->markFailed($run, $item, $this->formatUserErrors($userErrors), ['user_errors' => $userErrors]);

                        return;
                    }
                    $shopifyGid = $this->extractDiscountGid($updateMutation, $updateResult);
                }
            }

            if ($existingGid === null) {
                // Create
                $createResult = $graphql->query($shop, $this->buildMutation($createMutation, $variables), $variables);

                if (! empty($createResult['errors'])) {
                    $this->markFailed($run, $item, $this->formatErrors($createResult['errors']));

                    return;
                }

                $userErrors = $this->extractUserErrors($createMutation, $createResult);
                if (! empty($userErrors)) {
                    $this->markFailed($run, $item, $this->formatUserErrors($userErrors), ['user_errors' => $userErrors]);

                    return;
                }

                $shopifyGid = $this->extractDiscountGid($createMutation, $createResult);
            }

            // Upsert ID mapping
            if ($shopifyGid !== null && $shopifyGid !== '') {
                ShopifyIdMapping::query()->updateOrCreate([
                    'shop_id'     => $shop->id,
                    'entity_type' => 'discount',
                    'source_id'   => $this->sourceId,
                ], [
                    'shopify_gid' => $shopifyGid,
                ]);
            }

            // Add extra promotion codes via discountRedeemCodeBulkAdd (non-fatal)
            if ($shopifyGid !== null && count($extraCodes) > 0) {
                $this->bulkAddCodes($shop, $shopifyGid, $extraCodes, $run, $item);
            }

            // Set metafields via metafieldsSet (non-fatal)
            if ($shopifyGid !== null && count($metafields) > 0) {
                $this->setMetafields($shop, $shopifyGid, $metafields, $run, $item);
            }

            // Store non-fatal issues in error_context
            $errorContext = [];
            if (! empty($issues)) {
                $errorContext['mapping_issues'] = $issues;
            }

            $item->status        = 'succeeded';
            $item->fingerprint   = $fp;
            $item->finished_at   = now();
            $item->error_context = ! empty($errorContext) ? $errorContext : null;
            $item->save();

            try {
                app(MigrationRunReportWriter::class)->appendRow($run, [
                    'magento_promotion_id'  => $item->source_id,
                    'promotion_name'        => $promotionName,
                    'shopify_discount_type' => $this->discountTypeLabel($createMutation),
                    'shopify_discount_gid'  => $shopifyGid ?? '',
                    'code_count'            => isset($promotion['coupon_code']) ? 1 : 0,
                    'status'                => 'succeeded',
                    'reason'                => '',
                    'migrated_at_utc'       => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
                ]);
            } catch (\Throwable) {
                // ignore report errors
            }

            $this->incrementRunCounters($run->id, ['processed' => 1, 'succeeded' => 1]);

            // Languages handled directly.
        } catch (\Throwable $e) {
            Log::error('Discount migration item failed', [
                'run_id'    => $run->id,
                'source_id' => $this->sourceId,
                'error'     => $e->getMessage(),
            ]);
            $this->markFailed($run, $item, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // GraphQL helpers
    // -------------------------------------------------------------------------

    /**
     * Add extra promotion codes to an existing Shopify code discount (non-fatal).
     */
    private function bulkAddCodes(
        \App\Models\Shop $shop,
        string $discountGid,
        array $codes,
        MigrationRun $run,
        MigrationItem $item
    ): void {
        try {
            $codesInput = array_map(fn ($c) => ['code' => $c], $codes);
            $mutation   = <<<'GQL'
            mutation discountRedeemCodeBulkAdd($discountId: ID!, $codes: [DiscountRedeemCodeInput!]!) {
                discountRedeemCodeBulkAdd(discountId: $discountId, codes: $codes) {
                    bulkCreation {
                        id
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GQL;

            $graphql = app(\App\Services\Shopify\ShopifyAdminGraphqlClient::class);
            $result  = $graphql->query($shop, $mutation, [
                'discountId' => $discountGid,
                'codes'      => $codesInput,
            ]);

            $userErrors = data_get($result, 'data.discountRedeemCodeBulkAdd.userErrors', []);
            if (! empty($userErrors)) {
                Log::warning('Discount migration: bulk code add had userErrors (non-fatal)', [
                    'run_id'      => $run->id,
                    'source_id'   => $this->sourceId,
                    'discount_gid'=> $discountGid,
                    'user_errors' => $userErrors,
                ]);
                $ctx = is_array($item->error_context) ? $item->error_context : [];
                $ctx['bulk_code_add_errors'] = $userErrors;
                $item->error_context = $ctx;
                $item->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Discount migration: bulk code add failed (non-fatal)', [
                'run_id'    => $run->id,
                'source_id' => $this->sourceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set metafields on a Shopify discount via metafieldsSet (non-fatal).
     */
    private function setMetafields(
        \App\Models\Shop $shop,
        string $ownerGid,
        array $metafields,
        MigrationRun $run,
        MigrationItem $item
    ): void {
        try {
            // Attach ownerId to each metafield entry
            $metafieldsWithOwner = array_map(function ($mf) use ($ownerGid) {
                return array_merge($mf, ['ownerId' => $ownerGid]);
            }, $metafields);

            $mutation = <<<'GQL'
            mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $metafields) {
                    metafields {
                        key
                        namespace
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GQL;

            $graphql = app(\App\Services\Shopify\ShopifyAdminGraphqlClient::class);
            $result  = $graphql->query($shop, $mutation, ['metafields' => $metafieldsWithOwner]);

            $userErrors = data_get($result, 'data.metafieldsSet.userErrors', []);
            if (! empty($userErrors)) {
                Log::warning('Discount migration: metafieldsSet had userErrors (non-fatal)', [
                    'run_id'      => $run->id,
                    'source_id'   => $this->sourceId,
                    'owner_gid'   => $ownerGid,
                    'user_errors' => $userErrors,
                ]);
                $ctx = is_array($item->error_context) ? $item->error_context : [];
                $ctx['metafields_set_errors'] = $userErrors;
                $item->error_context = $ctx;
                $item->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Discount migration: metafieldsSet failed (non-fatal)', [
                'run_id'    => $run->id,
                'source_id' => $this->sourceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function buildMutation(string $mutationName, array $variables): string
    {
        $inputKey = $this->mutationInputKey($mutationName);
        $isUpdate = str_contains($mutationName, 'Update');

        if ($isUpdate) {
            return <<<GQL
            mutation {$mutationName}(\$id: ID!, \${$inputKey}: {$this->mutationInputType($mutationName)}!) {
                {$mutationName}(id: \$id, {$inputKey}: \${$inputKey}) {
                    {$this->mutationResultField($mutationName)} {
                        id
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GQL;
        }

        return <<<GQL
        mutation {$mutationName}(\${$inputKey}: {$this->mutationInputType($mutationName)}!) {
            {$mutationName}({$inputKey}: \${$inputKey}) {
                {$this->mutationResultField($mutationName)} {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;
    }

    private function mutationInputKey(string $mutation): string
    {
        return match (true) {
            str_contains($mutation, 'AutomaticBasic')       => 'automaticBasicDiscount',
            str_contains($mutation, 'AutomaticFreeShipping') => 'freeShippingAutomaticDiscount',
            str_contains($mutation, 'CodeBasic')            => 'basicCodeDiscount',
            str_contains($mutation, 'CodeFreeShipping')     => 'freeShippingCodeDiscount',
            default                                         => 'discount',
        };
    }

    private function mutationInputType(string $mutation): string
    {
        return match (true) {
            str_contains($mutation, 'AutomaticBasic')        => 'DiscountAutomaticBasicInput',
            str_contains($mutation, 'AutomaticFreeShipping') => 'DiscountAutomaticFreeShippingInput',
            str_contains($mutation, 'CodeBasic')             => 'DiscountCodeBasicInput',
            str_contains($mutation, 'CodeFreeShipping')      => 'DiscountCodeFreeShippingInput',
            default                                          => 'DiscountInput',
        };
    }

    private function mutationResultField(string $mutation): string
    {
        return match (true) {
            str_contains($mutation, 'AutomaticBasic')        => 'automaticDiscountNode',
            str_contains($mutation, 'AutomaticFreeShipping') => 'automaticDiscountNode',
            str_contains($mutation, 'CodeBasic')             => 'codeDiscountNode',
            str_contains($mutation, 'CodeFreeShipping')      => 'codeDiscountNode',
            default                                          => 'discountNode',
        };
    }

    private function extractDiscountGid(string $mutation, array $result): ?string
    {
        $resultField = $this->mutationResultField($mutation);
        $gid = data_get($result, "data.{$mutation}.{$resultField}.id");

        return is_string($gid) && $gid !== '' ? $gid : null;
    }

    private function extractUserErrors(string $mutation, array $result): array
    {
        $errors = data_get($result, "data.{$mutation}.userErrors");

        return is_array($errors) ? $errors : [];
    }

    private function isStaleGidError(array $result): bool
    {
        $userErrors = [];
        foreach ($result['data'] ?? [] as $mutationData) {
            if (is_array($mutationData) && isset($mutationData['userErrors'])) {
                $userErrors = $mutationData['userErrors'];
                break;
            }
        }

        foreach ($userErrors as $err) {
            $msg = strtolower((string) ($err['message'] ?? ''));
            if (str_contains($msg, 'not found') || str_contains($msg, "doesn't exist") || str_contains($msg, 'does not exist')) {
                return true;
            }
        }

        return false;
    }

    private function formatErrors(array $errors): string
    {
        $messages = array_map(fn ($e) => is_array($e) ? (string) ($e['message'] ?? '') : (string) $e, $errors);

        return implode('; ', array_filter($messages));
    }

    private function formatUserErrors(array $userErrors): string
    {
        $messages = array_map(fn ($e) => is_array($e) ? (string) ($e['message'] ?? '') : (string) $e, $userErrors);

        return implode('; ', array_filter($messages));
    }

    private function discountTypeLabel(string $mutation): string
    {
        return match (true) {
            str_contains($mutation, 'FreeShipping') => 'free_shipping',
            str_contains($mutation, 'Basic')        => 'basic',
            default                                 => 'unknown',
        };
    }

    // -------------------------------------------------------------------------
    // DB helpers
    // -------------------------------------------------------------------------

    private function existingShopifyGid(int $shopId, string $sourceId): ?string
    {
        $mapping = ShopifyIdMapping::query()
            ->where('shop_id', $shopId)
            ->where('entity_type', 'discount')
            ->where('source_id', $sourceId)
            ->first();

        if (! $mapping) {
            return null;
        }

        $gid = (string) ($mapping->shopify_gid ?? '');

        return $gid !== '' ? $gid : null;
    }

    private function latestSucceededFingerprint(int $shopId, string $sourceId): ?string
    {
        $fp = MigrationItem::query()
            ->join('migration_runs', 'migration_runs.id', '=', 'migration_items.migration_run_id')
            ->where('migration_runs.shop_id', $shopId)
            ->where('migration_runs.type', 'discounts')
            ->where('migration_items.entity_type', 'discount')
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
            $run->failed    = (int) $run->failed    + (int) ($delta['failed']    ?? 0);
            $run->save();
        });
    }

    private function markFailed(MigrationRun $run, MigrationItem $item, string $message, array $extraContext = []): void
    {
        $item->status        = 'failed';
        $item->error_message = $message;
        $item->finished_at   = now();
        if (! empty($extraContext)) {
            $item->error_context = $extraContext;
        }
        $item->save();

        try {
            $writer = app(MigrationRunReportWriter::class);
            $writer->appendRow($run, [
                'magento_promotion_id'  => $item->source_id,
                'promotion_name'        => '',
                'shopify_discount_type' => '',
                'shopify_discount_gid'  => '',
                'code_count'            => 0,
                'status'                => 'failed',
                'reason'                => $writer->humanizeFailureReason($item),
                'migrated_at_utc'       => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable) {
            // ignore
        }

        $this->incrementRunCounters($run->id, ['processed' => 1, 'failed' => 1]);
    }

    private function checkGidExistsOnShopify($shop, string $gid, $graphql): bool
    {
        try {
            $query = 'query($id: ID!) { node(id: $id) { id } }';
            $res = $graphql->query($shop, $query, ['id' => $gid]);
            $node = $res['data']['node'] ?? null;
            return $node !== null;
        } catch (\Throwable $e) {
            Log::warning('Failed to check GID existence on Shopify', ['gid' => $gid, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function markSkipped(MigrationRun $run, MigrationItem $item, string $fingerprint, string $promotionName, string $reason): void
    {
        $item->status      = 'skipped';
        $item->fingerprint = $fingerprint;
        $item->finished_at = now();
        $item->save();

        try {
            app(MigrationRunReportWriter::class)->appendRow($run, [
                'magento_promotion_id'  => $item->source_id,
                'promotion_name'        => $promotionName,
                'shopify_discount_type' => '',
                'shopify_discount_gid'  => '',
                'code_count'            => 0,
                'status'                => 'skipped',
                'reason'                => $reason,
                'migrated_at_utc'       => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable) {
            // ignore
        }

        $this->incrementRunCounters($run->id, ['processed' => 1]);
    }
}
