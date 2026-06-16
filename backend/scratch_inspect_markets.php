<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;

$shop = Shop::first();
$client = app(ShopifyAdminGraphqlClient::class);

echo "Querying Shopify Markets details...\n";

$query = <<<'GQL'
query GetMarketsDetails {
  markets(first: 50) {
    edges {
      node {
        id
        name
        handle
        enabled
        regions(first: 50) {
          edges {
            node {
              ... on MarketRegionCountry {
                code
                name
              }
            }
          }
        }
        webPresences(first: 10) {
          edges {
            node {
              id
              subfolderSuffix
              defaultLocale {
                locale
              }
              alternateLocales {
                locale
              }
              domain {
                id
                host
                url
              }
            }
          }
        }
      }
    }
  }
  shopLocales {
    locale
    name
    published
    primary
  }
  shop {
    primaryDomain {
      host
      url
    }
  }
}
GQL;

$res = $client->query($shop, $query);

if (isset($res['errors'])) {
    echo "GraphQL Errors: " . json_encode($res['errors'], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

echo json_encode($res['data'], JSON_PRETTY_PRINT) . "\n";
