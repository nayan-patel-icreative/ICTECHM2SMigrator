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
query GetMarketsRootUrls {
  markets(first: 50) {
    edges {
      node {
        name
        webPresences(first: 10) {
          edges {
            node {
              subfolderSuffix
              rootUrls {
                locale
                url
              }
            }
          }
        }
      }
    }
  }
}
GQL;

$res = $client->query($shop, $query);
echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
