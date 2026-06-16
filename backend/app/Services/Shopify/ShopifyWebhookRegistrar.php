<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookRegistrar
{
    private ShopifyAdminGraphqlClient $client;

    private Client $rest;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
        $this->rest = new Client([
            'timeout' => 60,
            'http_errors' => false,
        ]);
    }

    public function registerComplianceWebhooks(Shop $shop): void
    {
        return;
    }

    private function createComplianceWebhook(Shop $shop, string $topic, string $callbackPath): void
    {
        $callbackUrl = rtrim((string) config('shopify.app_url'), '/').$callbackPath;

        $apiVersion = (string) Config::get('shopify.api_version');
        $endpoint = sprintf('https://%s/admin/api/%s/webhooks.json', $shop->shop_domain, $apiVersion);

        $res = $this->rest->post($endpoint, [
            'headers' => [
                'X-Shopify-Access-Token' => $shop->access_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $callbackUrl,
                    'format' => 'json',
                ],
            ],
        ]);

        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        $decoded = json_decode($body, true);

        if ($status >= 200 && $status <= 299) {
            return;
        }

        // Shopify returns 422 when webhook already exists or topic is invalid.
        Log::error('Failed to register compliance webhook (REST)', [
            'shop' => $shop->shop_domain,
            'topic' => $topic,
            'status' => $status,
            'response' => is_array($decoded) ? $decoded : $body,
        ]);
    }
}
