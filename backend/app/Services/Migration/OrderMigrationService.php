<?php

namespace App\Services\Migration;

use App\Jobs\RunOrderMigrationJob;
use App\Models\MigrationRun;
use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

class OrderMigrationService
{
    /**
     * @return array{ready: bool, messages: array<int, string>, products: array<string, mixed>, customers: array<string, mixed>}
     */
    public function prerequisites(Shop $shop): array
    {
        $bypass = filter_var((string) env('BYPASS_ORDER_PREREQUISITES', 'false'), FILTER_VALIDATE_BOOLEAN);
        if ($bypass) {
            return [
                'ready' => true,
                'messages' => [],
                'products' => [
                    'run_status' => null,
                    'processed' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'mapping_count' => 0,
                    'missing_count' => 0,
                    'ready' => true,
                ],
                'customers' => [
                    'run_status' => null,
                    'processed' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'mapping_count' => 0,
                    'missing_count' => 0,
                    'ready' => true,
                ],
            ];
        }

        $productsRun = $this->latestRun($shop, 'products');
        $customersRun = $this->latestRun($shop, 'customers');

        $productMappings = $this->mappingHealth($shop, 'product', 'Product');
        $customerMappings = $this->mappingHealth($shop, 'customer', 'Customer');

        $messages = [];
        $productsReady = $this->runCompletedSuccessfully($productsRun) && $productMappings['available'] === true;
        $customersReady = $this->runCompletedSuccessfully($customersRun) && $customerMappings['available'] === true;

        if (!$this->runCompletedSuccessfully($productsRun)) {
            $messages[] = 'Complete product migration successfully before migrating orders.';
        } elseif ($productMappings['available'] !== true) {
            $messages[] = 'Some migrated Shopify products are missing. Re-run product migration before migrating orders.';
        }

        if (!$this->runCompletedSuccessfully($customersRun)) {
            $messages[] = 'Complete customer migration successfully before migrating orders.';
        } elseif ($customerMappings['available'] !== true) {
            $messages[] = 'Some migrated Shopify customers are missing. Re-run customer migration before migrating orders.';
        }

        return [
            'ready' => $productsReady && $customersReady,
            'messages' => $messages,
            'products' => [
                'run_status' => $productsRun ? $productsRun->status : null,
                'processed' => $productsRun ? (int) $productsRun->processed : 0,
                'succeeded' => $productsRun ? (int) $productsRun->succeeded : 0,
                'failed' => $productsRun ? (int) $productsRun->failed : 0,
                'mapping_count' => $productMappings['mapping_count'],
                'missing_count' => $productMappings['missing_count'],
                'ready' => $productsReady,
            ],
            'customers' => [
                'run_status' => $customersRun ? $customersRun->status : null,
                'processed' => $customersRun ? (int) $customersRun->processed : 0,
                'succeeded' => $customersRun ? (int) $customersRun->succeeded : 0,
                'failed' => $customersRun ? (int) $customersRun->failed : 0,
                'mapping_count' => $customerMappings['mapping_count'],
                'missing_count' => $customerMappings['missing_count'],
                'ready' => $customersReady,
            ],
        ];
    }

    public function start(Shop $shop, string $locationGid): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'orders')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            if ($locationGid !== '' && !$existing->shopify_location_gid) {
                $existing->shopify_location_gid = $locationGid;
                $existing->save();
            }

            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'orders',
            'status' => 'queued',
            'shopify_location_gid' => $locationGid !== '' ? $locationGid : null,
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run);

        RunOrderMigrationJob::dispatch($run->id);

        return $run;
    }

    /**
     * @param array<int, mixed> $filter
     */
    public function startFiltered(Shop $shop, array $filter, string $locationGid): MigrationRun
    {
        $existing = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'orders')
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            if ($locationGid !== '' && !$existing->shopify_location_gid) {
                $existing->shopify_location_gid = $locationGid;
                $existing->save();
            }

            return $existing;
        }

        $run = MigrationRun::query()->create([
            'shop_id' => $shop->id,
            'type' => 'orders',
            'status' => 'queued',
            'shopify_location_gid' => $locationGid !== '' ? $locationGid : null,
            'started_at' => now(),
        ]);
        app(MigrationRunReportWriter::class)->init($run, ['filters' => $filter]);

        RunOrderMigrationJob::dispatch($run->id, 1, $filter);

        return $run;
    }

    public function status(Shop $shop): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'orders')
            ->orderByDesc('id')
            ->first();
    }

    public function cancel(Shop $shop): bool
    {
        $run = MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', 'orders')
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('id')
            ->first();

        if (!$run) {
            return false;
        }

        $run->status = 'cancelled';
        $run->finished_at = now();
        $run->save();
        app(MigrationRunReportWriter::class)->finalize($run->id);

        return true;
    }

    private function latestRun(Shop $shop, string $type): ?MigrationRun
    {
        return MigrationRun::query()
            ->where('shop_id', $shop->id)
            ->where('type', $type)
            ->orderByDesc('id')
            ->first();
    }

    private function runCompletedSuccessfully(?MigrationRun $run): bool
    {
        if (!$run || $run->status !== 'finished' || (int) $run->failed > 0) {
            return false;
        }

        return (int) $run->processed > 0;
    }

    /**
     * @return array{available: bool, mapping_count: int, missing_count: int}
     */
    private function mappingHealth(Shop $shop, string $entityType, string $expectedTypename): array
    {
        $mappings = ShopifyIdMapping::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', $entityType)
            ->whereNotNull('shopify_gid')
            ->pluck('shopify_gid')
            ->filter(fn ($gid) => is_string($gid) && trim($gid) !== '')
            ->values();

        $count = $mappings->count();
        if ($count === 0) {
            return [
                'available' => false,
                'mapping_count' => 0,
                'missing_count' => 0,
            ];
        }

        $missing = 0;
        $client = app(ShopifyAdminGraphqlClient::class);
        $query = <<<'GQL'
query NodesExist($ids: [ID!]!) {
  nodes(ids: $ids) {
    __typename
    ... on Product { id }
    ... on Customer { id }
  }
}
GQL;

        foreach ($mappings->chunk(100) as $chunk) {
            $ids = array_values($chunk->all());
            $res = $client->query($shop, $query, ['ids' => $ids]);
            if (isset($res['errors'])) {
                return [
                    'available' => false,
                    'mapping_count' => $count,
                    'missing_count' => $count,
                ];
            }

            $nodes = data_get($res, 'data.nodes', []);
            $nodes = is_array($nodes) ? $nodes : [];
            foreach ($nodes as $node) {
                $typename = is_array($node) ? (string) ($node['__typename'] ?? '') : '';
                $id = is_array($node) ? (string) ($node['id'] ?? '') : '';
                if ($typename !== $expectedTypename || $id === '') {
                    $missing++;
                }
            }

            if (count($nodes) < count($ids)) {
                $missing += count($ids) - count($nodes);
            }
        }

        return [
            'available' => $missing === 0,
            'mapping_count' => $count,
            'missing_count' => $missing,
        ];
    }
}
