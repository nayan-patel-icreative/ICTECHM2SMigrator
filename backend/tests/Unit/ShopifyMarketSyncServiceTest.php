<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Migration\ShopifyMarketSyncService;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Tests\TestCase;

class ShopifyMarketSyncServiceTest extends TestCase
{
    public function test_get_markets_and_domains_success(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        $graphqlResponse = [
            'data' => [
                'markets' => [
                    'edges' => [
                        [
                            'node' => [
                                'id' => 'gid://shopify/Market/1',
                                'name' => 'Europe Market',
                                'handle' => 'europe-market',
                                'enabled' => true,
                                'regions' => [
                                    'edges' => [
                                        ['node' => ['code' => 'DE', 'name' => 'Germany']],
                                        ['node' => ['code' => 'FR', 'name' => 'France']],
                                    ]
                                ],
                                'webPresences' => [
                                    'edges' => [
                                        [
                                            'node' => [
                                                'id' => 'gid://shopify/WebPresence/1',
                                                'subfolderSuffix' => 'eur',
                                                'defaultLocale' => ['locale' => 'en'],
                                                'domain' => [
                                                    'id' => 'gid://shopify/Domain/1',
                                                    'host' => 'test.myshopify.com',
                                                    'url' => 'https://test.myshopify.com/eur',
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'shop' => [
                    'domains' => [
                        [
                            'id' => 'gid://shopify/Domain/1',
                            'host' => 'test.myshopify.com',
                            'url' => 'https://test.myshopify.com',
                        ],
                        [
                            'id' => 'gid://shopify/Domain/2',
                            'host' => 'custom-domain.com',
                            'url' => 'https://custom-domain.com',
                        ]
                    ],
                    'primaryDomain' => [
                        'id' => 'gid://shopify/Domain/1',
                        'host' => 'test.myshopify.com',
                        'url' => 'https://test.myshopify.com',
                    ]
                ]
            ]
        ];

        $mockClient->expects($this->once())
            ->method('query')
            ->willReturn($graphqlResponse);

        $service = new ShopifyMarketSyncService($mockClient);
        $result = $service->getMarketsAndDomains($shop);

        $this->assertCount(1, $result['markets']);
        $this->assertSame('gid://shopify/Market/1', $result['markets'][0]['id']);
        $this->assertSame('Europe Market', $result['markets'][0]['name']);
        $this->assertTrue($result['markets'][0]['enabled']);
        $this->assertSame(['DE', 'FR'], $result['markets'][0]['regions']);
        $this->assertCount(1, $result['markets'][0]['webPresences']);
        $this->assertSame('eur', $result['markets'][0]['webPresences'][0]['subfolderSuffix']);

        $this->assertCount(2, $result['domains']);
        $this->assertSame('custom-domain.com', $result['domains'][1]['host']);
        $this->assertSame('gid://shopify/Domain/1', $result['primaryDomain']['id']);
    }

    public function test_sync_market_creates_new_market_and_web_presence_subfolder(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        $mockClient->expects($this->exactly(5))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => ['edges' => []],
                            'shop' => [
                                'domains' => [
                                    [
                                        'id' => 'gid://shopify/Domain/1',
                                        'host' => 'test.myshopify.com',
                                        'url' => 'https://test.myshopify.com',
                                    ]
                                ],
                                'primaryDomain' => [
                                    'id' => 'gid://shopify/Domain/1',
                                    'host' => 'test.myshopify.com',
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    $this->assertSame('German Storefront', $variables['input']['name']);
                    $this->assertSame('DE', $variables['input']['conditions']['regionsCondition']['regions'][0]['countryCode']);
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new123', 'name' => 'German Storefront'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation WebPresenceCreate')) {
                    $this->assertSame('de-de', $variables['input']['defaultLocale']);
                    $this->assertSame('de', $variables['input']['subfolderSuffix']);
                    return [
                        'data' => [
                            'webPresenceCreate' => [
                                'webPresence' => ['id' => 'gid://shopify/WebPresence/wp123'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketAttachWebPresence')) {
                    $this->assertSame('gid://shopify/Market/new123', $variables['id']);
                    $this->assertSame(['gid://shopify/WebPresence/wp123'], $variables['input']['webPresencesToAdd']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new123'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['status']) && $variables['input']['status'] === 'ACTIVE') {
                    $this->assertSame('gid://shopify/Market/new123', $variables['id']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new123', 'enabled' => true],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'German Storefront',
            'code' => 'de',
            'locale' => 'de-DE',
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertTrue($res['ok']);
        $this->assertSame('gid://shopify/Market/new123', $res['market_id']);
    }

    public function test_sync_market_country_collision_resolution(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        $mockClient->expects($this->exactly(6))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Market/existing_eur',
                                            'name' => 'Europe Market',
                                            'handle' => 'europe-market',
                                            'enabled' => true,
                                            'regions' => [
                                                'edges' => [
                                                    ['node' => ['code' => 'DE', 'name' => 'Germany']],
                                                    ['node' => ['code' => 'FR', 'name' => 'France']],
                                                ]
                                            ],
                                            'webPresences' => ['edges' => []]
                                        ]
                                    ]
                                ]
                            ],
                            'shop' => [
                                'domains' => [],
                                'primaryDomain' => ['id' => 'gid://shopify/Domain/1', 'host' => 'test.myshopify.com']
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['conditions']['regionsCondition']['regions'])) {
                    $this->assertSame('gid://shopify/Market/existing_eur', $variables['id']);
                    $regions = $variables['input']['conditions']['regionsCondition']['regions'];
                    $this->assertCount(1, $regions);
                    $this->assertSame('FR', $regions[0]['countryCode']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/existing_eur'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    $this->assertSame('DE Storefront', $variables['input']['name']);
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_de', 'name' => 'DE Storefront'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation WebPresenceCreate')) {
                    return [
                        'data' => [
                            'webPresenceCreate' => [
                                'webPresence' => ['id' => 'gid://shopify/WebPresence/wp_de'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketAttachWebPresence')) {
                    $this->assertSame('gid://shopify/Market/new_de', $variables['id']);
                    $this->assertSame(['gid://shopify/WebPresence/wp_de'], $variables['input']['webPresencesToAdd']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_de'],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketUpdate') && isset($variables['input']['status']) && $variables['input']['status'] === 'ACTIVE') {
                    $this->assertSame('gid://shopify/Market/new_de', $variables['id']);
                    return [
                        'data' => [
                            'marketUpdate' => [
                                'market' => ['id' => 'gid://shopify/Market/new_de', 'enabled' => true],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'DE Storefront',
            'code' => 'de',
            'locale' => 'de-DE',
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertTrue($res['ok']);
        $this->assertSame('gid://shopify/Market/new_de', $res['market_id']);
    }

    public function test_sync_market_error_creating_market(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';

        $mockClient->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'query GetMarketsAndDomains')) {
                    return [
                        'data' => [
                            'markets' => ['edges' => []],
                            'shop' => [
                                'domains' => [],
                                'primaryDomain' => ['id' => 'gid://shopify/Domain/1', 'host' => 'test.myshopify.com']
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'mutation MarketCreate')) {
                    return [
                        'data' => [
                            'marketCreate' => [
                                'market' => null,
                                'userErrors' => [
                                    [
                                        'field' => ['conditions'],
                                        'message' => 'Region DE already assigned to another market',
                                        'code' => 'TAKEN'
                                    ]
                                ]
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyMarketSyncService($mockClient);
        $salesChannel = [
            'name' => 'DE Storefront',
            'code' => 'de',
            'locale' => 'de-DE',
        ];

        $res = $service->syncMarket($shop, $salesChannel);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Shopify error creating market', $res['error']);
        $this->assertCount(1, $res['userErrors']);
    }
}
