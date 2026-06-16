<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

$shop = Shop::first();
$client = app(ShopifyAdminGraphqlClient::class);

echo "Querying available locales from Shopify...\n";

$query = <<<'GQL'
query GetAvailableLocales {
  availableLocales {
    isoCode
    name
  }
}
GQL;

$res = $client->query($shop, $query);

if (isset($res['errors'])) {
    echo "GraphQL Errors: " . json_encode($res['errors']) . "\n";
    exit(1);
}

$locales = $res['data']['availableLocales'] ?? [];
echo "Available Locales matching English or United Kingdom:\n";
foreach ($locales as $loc) {
    if (str_contains(strtolower($loc['name']), 'english') || str_contains(strtolower($loc['name']), 'kingdom') || str_contains(strtolower($loc['isoCode']), 'en-')) {
        echo "  - ISO Code: {$loc['isoCode']} | Name: {$loc['name']}\n";
    }
}
