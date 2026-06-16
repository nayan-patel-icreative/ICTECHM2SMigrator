<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Tests\TestCase;

class ShopifyTranslationSyncServiceTest extends TestCase
{
    public function test_extractTranslationsFromEntity(): void
    {
        $entity = [
            'translations' => [
                [
                    'languageId' => 'lang-de',
                    'name' => 'Deutsch Name',
                    'description' => 'Deutsch Beschreibung',
                    'metaTitle' => 'Deutsch Meta',
                    'metaDescription' => 'Deutsch Meta Desc',
                ],
                [
                    'languageId' => 'lang-fr',
                    'name' => 'French Name',
                    'description' => 'French Description',
                ],
                [
                    'languageId' => 'lang-es',
                    'name' => 'Spanish Name',
                ]
            ]
        ];

        $enabledLanguages = [
            ['id' => 'lang-de', 'locale' => 'de-DE', 'name' => 'Deutsch'],
            ['id' => 'lang-fr', 'locale' => 'fr-FR', 'name' => 'Français'],
        ];

        $extracted = ShopifyTranslationSyncService::extractTranslationsFromEntity($entity, $enabledLanguages);

        $this->assertCount(2, $extracted);
        $this->assertArrayHasKey('de-DE', $extracted);
        $this->assertArrayHasKey('fr-FR', $extracted);
        $this->assertArrayNotHasKey('es-ES', $extracted);

        $this->assertSame('Deutsch Name', $extracted['de-DE']['name']);
        $this->assertSame('Deutsch Beschreibung', $extracted['de-DE']['description']);
        $this->assertSame('Deutsch Meta', $extracted['de-DE']['metaTitle']);
        $this->assertSame('Deutsch Meta Desc', $extracted['de-DE']['metaDescription']);

        $this->assertSame('French Name', $extracted['fr-FR']['name']);
        $this->assertSame('French Description', $extracted['fr-FR']['description']);
        $this->assertArrayNotHasKey('metaTitle', $extracted['fr-FR']);
    }

    public function test_syncTranslations_early_returns_when_empty(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        $mockClient->expects($this->never())->method('query');

        $shop = new Shop();
        $service = new ShopifyTranslationSyncService($mockClient);
        $result = $service->syncTranslations($shop, 'gid://shopify/Product/1', []);

        $this->assertTrue($result['ok']);
    }

    public function test_syncTranslations_calls_graphql_and_metafields(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';
        $shop->access_token = 'token123';

        // Set expectations:
        // First query: pushTranslationsApi (translationsRegister)
        // Second query: storeTranslationsMetafield (metafieldsSet)
        $mockClient->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'shopLocales')) {
                    return [
                        'data' => [
                            'shopLocales' => [
                                ['locale' => 'de-DE', 'published' => true],
                                ['locale' => 'en', 'published' => true],
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'getTranslatableResource')) {
                    return [
                        'data' => [
                            'translatableResource' => [
                                'translatableContent' => [
                                    ['key' => 'title', 'digest' => 'digest-title'],
                                    ['key' => 'body_html', 'digest' => 'digest-body_html'],
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'TranslationsRegister')) {
                    $this->assertSame('gid://shopify/Product/1', $variables['resourceId']);
                    $this->assertCount(2, $variables['translations']);
                    
                    // Verify first locale entry
                    $this->assertSame('de-DE', $variables['translations'][0]['locale']);
                    $this->assertSame('title', $variables['translations'][0]['key']);
                    $this->assertSame('German Name', $variables['translations'][0]['value']);

                    return [
                        'data' => [
                            'translationsRegister' => [
                                'translations' => [
                                    ['locale' => 'de-DE', 'key' => 'title', 'value' => 'German Name']
                                ],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'SetTranslationMeta')) {
                    $this->assertSame('gid://shopify/Product/1', $variables['metafields'][0]['ownerId']);
                    $this->assertSame('shopware_translations', $variables['metafields'][0]['namespace']);
                    $this->assertSame('all_translations', $variables['metafields'][0]['key']);
                    
                    $decoded = json_decode($variables['metafields'][0]['value'], true);
                    $this->assertSame('German Name', $decoded['de-DE']['name']);

                    return [
                        'data' => [
                            'metafieldsSet' => [
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyTranslationSyncService($mockClient);
        $translations = [
            'de-DE' => [
                'name' => 'German Name',
                'description' => 'German Desc'
            ]
        ];

        $result = $service->syncTranslations($shop, 'gid://shopify/Product/1', $translations);
        $this->assertTrue($result['ok']);
    }

    public function test_syncTranslations_maps_5char_locale_to_2char(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';
        $shop->access_token = 'token123';

        $mockClient->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'shopLocales')) {
                    return [
                        'data' => [
                            'shopLocales' => [
                                ['locale' => 'de', 'published' => true],
                                ['locale' => 'en', 'published' => true],
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'getTranslatableResource')) {
                    return [
                        'data' => [
                            'translatableResource' => [
                                'translatableContent' => [
                                    ['key' => 'title', 'digest' => 'digest-title'],
                                    ['key' => 'body_html', 'digest' => 'digest-body_html'],
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'TranslationsRegister')) {
                    // Verify the locale was mapped from de-DE to de
                    $this->assertSame('de', $variables['translations'][0]['locale']);
                    return [
                        'data' => [
                            'translationsRegister' => [
                                'translations' => [
                                    ['locale' => 'de', 'key' => 'title', 'value' => 'German Name']
                                ],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'SetTranslationMeta')) {
                    return [
                        'data' => [
                            'metafieldsSet' => [
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyTranslationSyncService($mockClient);
        $translations = [
            'de-DE' => [
                'name' => 'German Name',
                'description' => 'German Desc'
            ]
        ];

        $result = $service->syncTranslations($shop, 'gid://shopify/Product/1', $translations);
        $this->assertTrue($result['ok']);
    }

    public function test_syncCollectionTranslations(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';
        $shop->access_token = 'token123';

        $mockClient->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                if (str_contains($query, 'shopLocales')) {
                    return [
                        'data' => [
                            'shopLocales' => [
                                ['locale' => 'de-DE', 'published' => true],
                                ['locale' => 'en', 'published' => true],
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'getTranslatableResource')) {
                    return [
                        'data' => [
                            'translatableResource' => [
                                'translatableContent' => [
                                    ['key' => 'title', 'digest' => 'digest-title'],
                                    ['key' => 'body_html', 'digest' => 'digest-body_html'],
                                ]
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'TranslationsRegister')) {
                    $this->assertSame('gid://shopify/Collection/1', $variables['resourceId']);
                    $this->assertCount(2, $variables['translations']);
                    $this->assertSame('de-DE', $variables['translations'][0]['locale']);
                    $this->assertSame('title', $variables['translations'][0]['key']);
                    $this->assertSame('German Category', $variables['translations'][0]['value']);

                    return [
                        'data' => [
                            'translationsRegister' => [
                                'translations' => [
                                    ['locale' => 'de-DE', 'key' => 'title', 'value' => 'German Category']
                                ],
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                if (str_contains($query, 'SetTranslationMeta')) {
                    $this->assertSame('gid://shopify/Collection/1', $variables['metafields'][0]['ownerId']);
                    $this->assertSame('shopware_translations', $variables['metafields'][0]['namespace']);
                    $this->assertSame('all_translations', $variables['metafields'][0]['key']);
                    
                    $decoded = json_decode($variables['metafields'][0]['value'], true);
                    $this->assertSame('German Category', $decoded['de-DE']['name']);

                    return [
                        'data' => [
                            'metafieldsSet' => [
                                'userErrors' => []
                            ]
                        ]
                    ];
                }

                return [];
            });

        $service = new ShopifyTranslationSyncService($mockClient);
        $translations = [
            'de-DE' => [
                'name' => 'German Category',
                'description' => 'German Desc'
            ]
        ];

        $result = $service->syncCollectionTranslations($shop, 'gid://shopify/Collection/1', $translations);
        $this->assertTrue($result['ok']);
    }

    public function test_storeLanguagePreferenceMetafield(): void
    {
        $mockClient = $this->createMock(ShopifyAdminGraphqlClient::class);
        
        $shop = new Shop();
        $shop->shop_domain = 'test.myshopify.com';
        $shop->access_token = 'token123';

        $mockClient->expects($this->once())
            ->method('query')
            ->willReturnCallback(function ($passedShop, $query, $variables = []) {
                $this->assertTrue(str_contains($query, 'SetTranslationMeta'));
                $this->assertSame('gid://shopify/Customer/1', $variables['metafields'][0]['ownerId']);
                $this->assertSame('shopware_translations', $variables['metafields'][0]['namespace']);
                $this->assertSame('language_preference', $variables['metafields'][0]['key']);
                $this->assertSame('json', $variables['metafields'][0]['type']);
                
                $decoded = json_decode($variables['metafields'][0]['value'], true);
                $this->assertSame('de-DE', $decoded['locale']);
                $this->assertSame('Deutsch', $decoded['name']);

                return [
                    'data' => [
                        'metafieldsSet' => [
                            'userErrors' => []
                        ]
                    ]
                ];
            });

        $service = new ShopifyTranslationSyncService($mockClient);
        $result = $service->storeLanguagePreferenceMetafield($shop, 'gid://shopify/Customer/1', 'de-DE', 'Deutsch');
        $this->assertTrue($result['ok']);
    }
}
