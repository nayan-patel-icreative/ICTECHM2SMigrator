<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Migration\ShopifyOrderSyncService;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PinOrderMetafieldDefinitions extends Command
{
    protected $signature = 'shopify:pin-order-metafields {--shop= : The shop domain to process}';
    protected $description = 'Clear cache and re-pin order document metafield definitions for a shop';

    public function handle(): int
    {
        $shopDomain = $this->option('shop');
        if (!$shopDomain) {
            $this->error('Please provide a shop domain: --shop=example.myshopify.com');
            return Command::FAILURE;
        }

        $shop = Shop::where('shop_domain', $shopDomain)->first();
        if (!$shop) {
            $this->error("Shop not found: {$shopDomain}");
            return Command::FAILURE;
        }

        $cacheKey = 'shopify:order_doc_metafields_ensured_v5:' . $shop->id;
        Cache::forget($cacheKey);
        $this->info("Cleared cache for {$shopDomain}");

        $client = app(ShopifyAdminGraphqlClient::class);
        $sync = new ShopifyOrderSyncService($client);
        $result = $sync->ensureOrderDocumentMetafieldDefinitions($shop);

        if (isset($result['ok']) && $result['ok']) {
            $this->info("Metafield definitions ensured and pinned for {$shopDomain}");
            return Command::SUCCESS;
        }

        $this->error('Failed: ' . json_encode($result));
        return Command::FAILURE;
    }
}
