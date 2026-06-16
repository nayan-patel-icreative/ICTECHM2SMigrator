<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Http\Request;

class ShopifyController extends Controller
{
    private ShopifyAdminGraphqlClient $client;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    public function me(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'shop_domain' => $shop->shop_domain,
            'installed_at' => $shop->installed_at?->toIso8601String(),
        ]);
    }

    public function locations(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $query = <<<'GQL'
query Locations($first: Int!) {
  locations(first: $first) {
    nodes {
      id
      name
      isActive
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, ['first' => 50]);

        $errors = $res['errors'] ?? null;
        if ($errors) {
            $baseUrl = rtrim((string) config('shopify.app_url'), '/');
            $host = (string) $request->query('host', '');
            $reauthUrl = $baseUrl.'/auth/shopify?shop='.urlencode($shop->shop_domain);
            if ($host !== '') {
                $reauthUrl .= '&host='.urlencode($host);
            }

            $firstCode = data_get($errors, '0.extensions.code');
            if ($firstCode === 'ACCESS_DENIED') {
                return response()->json([
                    'message' => 'Missing required Shopify access scopes. Please re-authorize the app.',
                    'errors' => $errors,
                    'reauth_url' => $reauthUrl,
                ], 403);
            }

            $firstStatus = data_get($errors, '0.status');
            if ((int) $firstStatus === 401) {
                return response()->json([
                    'message' => 'Shopify access token is invalid or expired. Please re-authorize the app.',
                    'errors' => $errors,
                    'reauth_url' => $reauthUrl,
                ], 403);
            }

            return response()->json(['message' => 'Shopify API error.', 'errors' => $errors], 502);
        }

        $nodes = data_get($res, 'data.locations.nodes', []);
        if (!is_array($nodes)) {
            $nodes = [];
        }

        return response()->json([
            'locations' => array_values(array_map(function ($n) {
                return [
                    'id' => $n['id'] ?? null,
                    'name' => $n['name'] ?? null,
                    'is_active' => $n['isActive'] ?? null,
                ];
            }, $nodes)),
        ]);
    }
}
