<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopifyPublicationService
{
    private ShopifyAdminGraphqlClient $client;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * Publish a product or collection to the Online Store sales channel.
     *
     * @return array{ok?: bool, skipped?: bool, userErrors?: array<int, mixed>, errors?: mixed, reason?: string}
     */
    public function publishToOnlineStore(Shop $shop, string $resourceGid): array
    {
        $publicationId = $this->resolveOnlineStorePublicationId($shop);
        if ($publicationId === null) {
            return ['skipped' => true, 'reason' => 'online_store_publication_not_found'];
        }

        $mutation = <<<'GQL'
mutation PublishablePublish($id: ID!, $input: [PublicationInput!]!) {
  publishablePublish(id: $id, input: $input) {
    publishable {
      ... on Product { id }
      ... on Collection { id }
    }
    userErrors { field message }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'id' => $resourceGid,
            'input' => [
                ['publicationId' => $publicationId],
            ],
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.publishablePublish.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    private function resolveOnlineStorePublicationId(Shop $shop): ?string
    {
        $cacheKey = 'shopify:online_store_publication_id:'.$shop->id;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $query = <<<'GQL'
query Publications {
  publications(first: 25) {
    nodes {
      id
      name
      catalog { title }
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, []);
        if (isset($res['errors'])) {
            return null;
        }

        $nodes = data_get($res, 'data.publications.nodes', []);
        $nodes = is_array($nodes) ? $nodes : [];

        $fallback = null;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = trim((string) data_get($node, 'id', ''));
            if ($id === '') {
                continue;
            }
            $name = strtolower(trim((string) data_get($node, 'name', '')));
            $catalogTitle = strtolower(trim((string) data_get($node, 'catalog.title', '')));
            if (
                str_contains($name, 'online store')
                || str_contains($catalogTitle, 'online store')
                || $name === 'online_store'
            ) {
                Cache::put($cacheKey, $id, now()->addDays(7));

                return $id;
            }
            if ($fallback === null) {
                $fallback = $id;
            }
        }

        if ($fallback !== null) {
            Cache::put($cacheKey, $fallback, now()->addDays(7));

            return $fallback;
        }

        return null;
    }

    /**
     * Get or create a custom publication ID for a specific market.
     */
    public function getOrCreateMarketPublicationId(Shop $shop, string $marketGid, string $marketName): ?string
    {
        $cacheKey = 'shopify:market_publication_id:'.$shop->id.':'.md5($marketGid);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // 1. Query existing catalogs for the market
        $query = <<<'GQL'
query MarketCatalogs($marketId: ID!) {
  market(id: $marketId) {
    id
    name
    catalogs(first: 10) {
      nodes {
        id
        title
        publication {
          id
        }
      }
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['marketId' => $marketGid]);
        if (isset($res['errors'])) {
            Log::error('ShopifyPublicationService: Failed to query market catalogs', [
                'shop' => $shop->shop_domain,
                'market_gid' => $marketGid,
                'errors' => $res['errors']
            ]);
            return null;
        }

        $catalogs = data_get($res, 'data.market.catalogs.nodes', []);
        $onlineStorePublicationId = $this->resolveOnlineStorePublicationId($shop);

        foreach ($catalogs as $catalog) {
            $pubId = data_get($catalog, 'publication.id');
            if ($pubId && $pubId !== $onlineStorePublicationId) {
                // Found a custom catalog/publication for this market!
                Cache::put($cacheKey, $pubId, now()->addDays(7));
                return $pubId;
            }

            // If the catalog exists but has no publication, delete it so we can recreate it with a publication!
            if (!$pubId) {
                $catalogId = data_get($catalog, 'id');
                if ($catalogId) {
                    Log::info('ShopifyPublicationService: Deleting publication-less catalog to recreate it', [
                        'shop' => $shop->shop_domain,
                        'catalog_id' => $catalogId
                    ]);
                    $deleteMutation = <<<'GQL'
mutation CatalogDelete($id: ID!, $deleteDependentResources: Boolean) {
  catalogDelete(id: $id, deleteDependentResources: $deleteDependentResources) {
    deletedId
    userErrors { field message }
  }
}
GQL;
                    $this->client->query($shop, $deleteMutation, [
                        'id' => $catalogId,
                        'deleteDependentResources' => true
                    ]);
                }
            }
        }

        // 2. If no custom catalog/publication, create a new Publication first
        Log::info('ShopifyPublicationService: Creating custom publication for market', [
            'shop' => $shop->shop_domain,
            'market_gid' => $marketGid,
            'market_name' => $marketName
        ]);

        $pubMutation = <<<'GQL'
mutation PublicationCreate($input: PublicationCreateInput!) {
  publicationCreate(input: $input) {
    publication {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $pubRes = $this->client->query($shop, $pubMutation, [
            'input' => [
                'autoPublish' => false
            ]
        ]);

        if (isset($pubRes['errors'])) {
            Log::error('ShopifyPublicationService: GraphQL error creating market publication', [
                'shop' => $shop->shop_domain,
                'market_gid' => $marketGid,
                'errors' => $pubRes['errors']
            ]);
            return null;
        }

        $pubUserErrors = data_get($pubRes, 'data.publicationCreate.userErrors', []);
        if (!empty($pubUserErrors)) {
            Log::error('ShopifyPublicationService: Shopify error creating market publication', [
                'shop' => $shop->shop_domain,
                'market_gid' => $marketGid,
                'userErrors' => $pubUserErrors
            ]);
            return null;
        }

        $pubId = data_get($pubRes, 'data.publicationCreate.publication.id');
        if (!$pubId) {
            Log::error('ShopifyPublicationService: No publication ID returned', [
                'shop' => $shop->shop_domain,
                'market_gid' => $marketGid
            ]);
            return null;
        }

        // 3. Create a new Catalog for this market using the publication ID
        Log::info('ShopifyPublicationService: Creating custom catalog for market with publication', [
            'shop' => $shop->shop_domain,
            'market_gid' => $marketGid,
            'market_name' => $marketName,
            'publication_id' => $pubId
        ]);

        $catalogMutation = <<<'GQL'
mutation CatalogCreate($input: CatalogCreateInput!) {
  catalogCreate(input: $input) {
    catalog {
      id
      title
      publication {
        id
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $createRes = $this->client->query($shop, $catalogMutation, [
            'input' => [
                'title' => $marketName . ' Catalog',
                'status' => 'ACTIVE',
                'publicationId' => $pubId,
                'context' => [
                    'marketIds' => [$marketGid]
                ]
            ]
        ]);

        if (isset($createRes['errors'])) {
            Log::error('ShopifyPublicationService: GraphQL error creating market catalog', [
                'shop' => $shop->shop_domain,
                'market_gid' => $marketGid,
                'errors' => $createRes['errors']
            ]);
            return null;
        }

        $userErrors = data_get($createRes, 'data.catalogCreate.userErrors', []);
        if (!empty($userErrors)) {
            Log::error('ShopifyPublicationService: Shopify error creating market catalog', [
                'shop' => $shop->shop_domain,
                'market_gid' => $marketGid,
                'userErrors' => $userErrors
            ]);
            return null;
        }

        $catalogPubId = data_get($createRes, 'data.catalogCreate.catalog.publication.id');
        if ($catalogPubId) {
            Cache::put($cacheKey, $catalogPubId, now()->addDays(7));
            return $catalogPubId;
        }

        return null;
    }

    /**
     * Sync product visibility across market publications based on Magento store view visibilities.
     */
    public function syncProductToMarkets(Shop $shop, string $productGid, array $visibilities): array
    {
        // 1. Gather all active Magento store view IDs
        $activeChannelIds = [];
        foreach ($visibilities as $v) {
            if (is_array($v) && !empty($v['salesChannelId'])) {
                $activeChannelIds[] = trim($v['salesChannelId']);
            }
        }

        // 2. Fetch all mapped markets for the current shop
        $mappings = ShopifyIdMapping::query()
            ->where('shop_id', $shop->id)
            ->where('entity_type', 'market')
            ->get();

        $results = [];

        foreach ($mappings as $mapping) {
            $salesChannelId = $mapping->source_id;
            $marketGid = $mapping->shopify_gid;

            // Simple default name for catalog
            $marketName = 'Market ' . substr(md5($marketGid), 0, 5);
            $pubId = $this->getOrCreateMarketPublicationId($shop, $marketGid, $marketName);
            if (!$pubId) {
                $results[$marketGid] = ['ok' => false, 'error' => 'Could not resolve publication ID'];
                continue;
            }

            $shouldBeVisible = in_array($salesChannelId, $activeChannelIds, true);

            if ($shouldBeVisible) {
                $mutation = <<<'GQL'
mutation PublishablePublish($id: ID!, $input: [PublicationInput!]!) {
  publishablePublish(id: $id, input: $input) {
    publishable {
      ... on Product { id }
    }
    userErrors { field message }
  }
}
GQL;
                $res = $this->client->query($shop, $mutation, [
                    'id' => $productGid,
                    'input' => [
                        ['publicationId' => $pubId]
                    ]
                ]);
            } else {
                $mutation = <<<'GQL'
mutation PublishableUnpublish($id: ID!, $input: [PublicationInput!]!) {
  publishableUnpublish(id: $id, input: $input) {
    publishable {
      ... on Product { id }
    }
    userErrors { field message }
  }
}
GQL;
                $res = $this->client->query($shop, $mutation, [
                    'id' => $productGid,
                    'input' => [
                        ['publicationId' => $pubId]
                    ]
                ]);
            }

            if (isset($res['errors'])) {
                $results[$marketGid] = ['ok' => false, 'errors' => $res['errors']];
            } else {
                $userErrors = data_get($res, 'data.publishablePublish.userErrors') 
                    ?? data_get($res, 'data.publishableUnpublish.userErrors') 
                    ?? [];
                if (!empty($userErrors)) {
                    $results[$marketGid] = ['ok' => false, 'userErrors' => $userErrors];
                } else {
                    $results[$marketGid] = ['ok' => true];
                }
            }
        }

        return $results;
    }
}
