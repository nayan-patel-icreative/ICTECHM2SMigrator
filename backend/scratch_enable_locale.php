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
mutation shopLocaleEnable($locale: String!) {
  shopLocaleEnable(locale: $locale) {
    shopLocale {
      locale
      name
      published
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

$res = $client->query($shop, $query, ['locale' => 'en-GB']);
echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
