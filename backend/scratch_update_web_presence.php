<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

$shop = Shop::first();
$client = app(ShopifyAdminGraphqlClient::class);

$wpId = 'gid://shopify/MarketWebPresence/45444366534';

echo "Updating United Kingdom Web Presence...\n";

$mutation = <<<'GQL'
mutation WebPresenceUpdate($id: ID!, $input: WebPresenceUpdateInput!) {
  webPresenceUpdate(id: $id, input: $input) {
    userErrors {
      field
      message
    }
    webPresence {
      id
      subfolderSuffix
      defaultLocale {
        locale
      }
      alternateLocales {
        locale
      }
      rootUrls {
        locale
        url
      }
    }
  }
}
GQL;

$res = $client->query($shop, $mutation, [
    'id' => $wpId,
    'input' => [
        'defaultLocale' => 'en',
        'alternateLocales' => []
    ]
]);

echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
