<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\MagentoConnection;
use App\Services\Magento\MagentoClient;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MagentoConnectionTest extends TestCase
{
    use DatabaseTransactions;

    private function getAuthHeader(Shop $shop): array
    {
        config()->set('shopify.api_key', 'test-key');
        config()->set('shopify.api_secret', 'test-secret');

        $token = JWT::encode([
            'aud' => 'test-key',
            'dest' => 'https://' . $shop->shop_domain,
            'exp' => now()->addMinutes(5)->timestamp,
        ], 'test-secret', 'HS256');

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_can_retrieve_and_save_magento_connection(): void
    {
        $domain = 'test-shop-' . uniqid() . '.myshopify.com';
        Shop::query()->where('shop_domain', $domain)->delete();

        $shop = Shop::query()->create([
            'shop_domain' => $domain,
            'access_token' => 'token-test',
        ]);

        $headers = $this->getAuthHeader($shop);

        // 1. Get connection when none exists
        $response = $this->withHeaders($headers)->get('/api/shopware-connection');
        $response->assertOk();
        $response->assertJson(['connected' => false]);

        // 2. Save connection details
        $langConfig = [
            ['id' => 'de-1', 'name' => 'German', 'locale' => 'de-DE', 'enabled' => true],
            ['id' => 'fr-1', 'name' => 'French', 'locale' => 'fr-FR', 'enabled' => false]
        ];

        $payload = [
            'api_url' => 'https://magento-test.com',
            'access_token' => 'magento-token-xyz',
            'store_view_code' => 'de',
            'store_view_name' => 'German View',
            'language_config' => $langConfig,
            'files_path' => '/var/www/magento/pub/media',
        ];

        $response = $this->withHeaders($headers)->postJson('/api/shopware-connection', $payload);
        $response->assertOk();
        $response->assertJson([
            'connected' => true,
            'api_url' => 'https://magento-test.com',
            'access_token_saved' => true,
            'store_view_code' => 'de',
            'store_view_name' => 'German View',
            'language_config' => $langConfig,
            'files_path' => '/var/www/magento/pub/media',
        ]);

        // Verify DB update
        $conn = MagentoConnection::query()->where('shop_id', $shop->id)->first();
        $this->assertNotNull($conn);
        $this->assertSame('https://magento-test.com', $conn->api_url);
        $this->assertSame('magento-token-xyz', $conn->access_token);
        $this->assertSame($langConfig, $conn->language_config);

        // 3. Fetch connection again, verify config is returned
        $response = $this->withHeaders($headers)->get('/api/shopware-connection');
        $response->assertOk();
        $response->assertJson([
            'connected' => true,
            'api_url' => 'https://magento-test.com',
            'access_token_saved' => true,
            'language_config' => $langConfig,
        ]);
    }

    public function test_can_fetch_languages_from_magento_connection(): void
    {
        $domain = 'test-shop-2-' . uniqid() . '.myshopify.com';
        Shop::query()->where('shop_domain', $domain)->delete();

        $shop = Shop::query()->create([
            'shop_domain' => $domain,
            'access_token' => 'token-test-2',
        ]);

        $conn = MagentoConnection::query()->create([
            'shop_id' => $shop->id,
            'api_url' => 'https://magento-test.com',
            'access_token' => 'magento-token-xyz',
        ]);

        $headers = $this->getAuthHeader($shop);

        $mockStoreViews = [
            ['id' => '1', 'name' => 'Default Store View', 'code' => 'default', 'locale' => 'en-US', 'currency' => 'USD', 'website_id' => '1'],
            ['id' => '2', 'name' => 'German Store View', 'code' => 'de', 'locale' => 'de-DE', 'currency' => 'EUR', 'website_id' => '1'],
        ];

        // Mock MagentoClient
        $mockClient = $this->createMock(MagentoClient::class);
        $mockClient->expects($this->once())
            ->method('getStoreViews')
            ->with($this->callback(function ($passedConn) use ($conn) {
                return $passedConn->id === $conn->id;
            }))
            ->willReturn($mockStoreViews);

        $this->app->instance(MagentoClient::class, $mockClient);

        $response = $this->withHeaders($headers)->get('/api/shopware-languages');
        $response->assertOk();
        $response->assertJson([
            'languages' => [
                [
                    'id' => '1',
                    'name' => 'Default Store View (en-US)',
                    'locale' => 'en-US',
                ],
                [
                    'id' => '2',
                    'name' => 'German Store View (de-DE)',
                    'locale' => 'de-DE',
                ],
            ]
        ]);
    }
}
