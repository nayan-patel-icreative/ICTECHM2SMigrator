<?php

namespace App\Services\Migration;

use App\Models\MigrationRun;
use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

class MigrationLocationResolver
{
    public function resolveForRun(MigrationRun $run, ?Shop $shop = null): string
    {
        $gid = trim((string) $run->shopify_location_gid);
        if ($gid !== '') {
            return $gid;
        }

        $fromProducts = MigrationRun::query()
            ->where('shop_id', $run->shop_id)
            ->where('type', 'products')
            ->whereNotNull('shopify_location_gid')
            ->where('shopify_location_gid', '!=', '')
            ->orderByDesc('id')
            ->value('shopify_location_gid');

        if (is_string($fromProducts) && trim($fromProducts) !== '') {
            return trim($fromProducts);
        }

        $shop = $shop ?: $run->shop;
        if (!$shop) {
            return '';
        }

        return $this->fetchFirstActiveLocationGid($shop);
    }

    private function fetchFirstActiveLocationGid(Shop $shop): string
    {
        $query = <<<'GQL'
query Locations($first: Int!) {
  locations(first: $first) {
    nodes {
      id
      isActive
    }
  }
}
GQL;

        try {
            $client = app(ShopifyAdminGraphqlClient::class);
            $res = $client->query($shop, $query, ['first' => 10]);
            if (isset($res['errors'])) {
                return '';
            }

            $nodes = data_get($res, 'data.locations.nodes', []);
            if (!is_array($nodes)) {
                return '';
            }

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (($node['isActive'] ?? true) === false) {
                    continue;
                }
                $id = trim((string) ($node['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }
}
