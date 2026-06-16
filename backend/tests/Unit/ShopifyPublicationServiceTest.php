<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\ShopifyIdMapping;
use App\Services\Migration\ShopifyPublicationService;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ShopifyPublicationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_get_or_create_market_publication_id_returns_cached(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->id = 123;
        $marketGid = 'gid://shopify/Market/1';

        $cacheKey = 'shopify:market_publication_id:123:' . md5($marketGid);
        Cache::put($cacheKey, 'gid://shopify/Publication/cached_pub', 60);

        $service = new ShopifyPublicationService($mockClient);
        $result = $service->getOrCreateMarketPublicationId($shop, $marketGid, 'German Market');

        $this->assertSame('gid://shopify/Publication/cached_pub', $result);
    }

    public function test_get_or_create_market_publication_id_queries_existing_and_creates_if_not_found(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->id = 123;
        $marketGid = 'gid://shopify/Market/1';

        // Mock queries:
        // 1. resolveOnlineStorePublicationId
        // 2. Query existing catalogs for the market
        // 3. PublicationCreate mutation
        // 4. CatalogCreate mutation
        $mockClient->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query Publications')) {
                    return [
                        'data' => [
                            'publications' => [
                                'nodes' => [
                                    [
                                        'id' => 'gid://shopify/Publication/online_store_pub',
                                        'name' => 'Online Store',
                                        'catalog' => ['title' => 'Online Store']
                                    ]
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'query MarketCatalogs')) {
                    $this->assertSame('gid://shopify/Market/1', $variables['marketId']);
                    return [
                        'data' => [
                            'market' => [
                                'id' => 'gid://shopify/Market/1',
                                'name' => 'German Market',
                                'catalogs' => [
                                    'nodes' => [
                                        // Only has the online store catalog inherited
                                        [
                                            'id' => 'gid://shopify/AppCatalog/123',
                                            'title' => 'Online Store',
                                            'publication' => [
                                                'id' => 'gid://shopify/Publication/online_store_pub'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation PublicationCreate')) {
                    $this->assertFalse($variables['input']['autoPublish']);
                    return [
                        'data' => [
                            'publicationCreate' => [
                                'publication' => [
                                    'id' => 'gid://shopify/Publication/new_market_pub'
                                ],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation CatalogCreate')) {
                    $this->assertSame('German Market Catalog', $variables['input']['title']);
                    $this->assertSame('gid://shopify/Publication/new_market_pub', $variables['input']['publicationId']);
                    $this->assertSame(['gid://shopify/Market/1'], $variables['input']['context']['marketIds']);
                    return [
                        'data' => [
                            'catalogCreate' => [
                                'catalog' => [
                                    'id' => 'gid://shopify/MarketCatalog/new123',
                                    'title' => 'German Market Catalog',
                                    'publication' => [
                                        'id' => 'gid://shopify/Publication/new_market_pub'
                                    ]
                                ],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyPublicationService($mockClient);
        $result = $service->getOrCreateMarketPublicationId($shop, $marketGid, 'German Market');

        $this->assertSame('gid://shopify/Publication/new_market_pub', $result);
    }

    public function test_sync_product_to_markets_publishes_if_visible_unpublishes_if_not(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        
        // Create a real Shop to pass the foreign key constraint
        $shop = Shop::query()->create([
            'shop_domain' => 'test-pub-service.myshopify.com',
            'access_token' => 'test-token',
        ]);
        
        $productGid = 'gid://shopify/Product/999';

        // 1. Mapped markets in DB:
        // Market 1 -> Shopware channel 111
        // Market 2 -> Shopware channel 222
        ShopifyIdMapping::query()->create([
            'shop_id' => $shop->id,
            'entity_type' => 'market',
            'source_id' => '111',
            'shopify_gid' => 'gid://shopify/Market/1',
        ]);
        ShopifyIdMapping::query()->create([
            'shop_id' => $shop->id,
            'entity_type' => 'market',
            'source_id' => '222',
            'shopify_gid' => 'gid://shopify/Market/2',
        ]);

        // Mock getOrCreateMarketPublicationId cache entries
        Cache::put('shopify:market_publication_id:' . $shop->id . ':' . md5('gid://shopify/Market/1'), 'gid://shopify/Publication/pub1', 60);
        Cache::put('shopify:market_publication_id:' . $shop->id . ':' . md5('gid://shopify/Market/2'), 'gid://shopify/Publication/pub2', 60);

        // Product is visible in channel 111 but not in channel 222
        $visibilities = [
            ['salesChannelId' => '111', 'visibility' => 30]
        ];

        $mockClient->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'mutation PublishablePublish')) {
                    $this->assertSame('gid://shopify/Product/999', $variables['id']);
                    $this->assertSame('gid://shopify/Publication/pub1', $variables['input'][0]['publicationId']);
                    return [
                        'data' => [
                            'publishablePublish' => [
                                'publishable' => ['id' => 'gid://shopify/Product/999'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation PublishableUnpublish')) {
                    $this->assertSame('gid://shopify/Product/999', $variables['id']);
                    $this->assertSame('gid://shopify/Publication/pub2', $variables['input'][0]['publicationId']);
                    return [
                        'data' => [
                            'publishableUnpublish' => [
                                'publishable' => ['id' => 'gid://shopify/Product/999'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyPublicationService($mockClient);
        $results = $service->syncProductToMarkets($shop, $productGid, $visibilities);

        $this->assertTrue($results['gid://shopify/Market/1']['ok']);
        $this->assertTrue($results['gid://shopify/Market/2']['ok']);
    }
}
