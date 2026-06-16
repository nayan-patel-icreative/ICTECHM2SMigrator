<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

$shop = Shop::first();
$client = app(ShopifyAdminGraphqlClient::class);

$query = <<<'GQL'
query GetAvailableLocales {
  availableLocales {
    isoCode
    name
  }
}
GQL;

$res = $client->query($shop, $query);
$locales = $res['data']['availableLocales'] ?? [];

echo "Matching locales:\n";
foreach ($locales as $loc) {
    if (stripos($loc['isoCode'], 'en') === 0 || stripos($loc['isoCode'], 'gb') === 0) {
        echo "  - {$loc['isoCode']}: {$loc['name']}\n";
    }
}
