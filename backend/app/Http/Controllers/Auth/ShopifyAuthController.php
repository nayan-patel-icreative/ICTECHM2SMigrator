<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Shopify\ShopifyWebhookRegistrar;
use App\Support\ShopifyHmac;
use App\Support\ViteManifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use GuzzleHttp\Client;

class ShopifyAuthController extends Controller
{
    private ShopifyWebhookRegistrar $webhookRegistrar;

    public function __construct(ShopifyWebhookRegistrar $webhookRegistrar)
    {
        $this->webhookRegistrar = $webhookRegistrar;
    }

    public function redirectToShopify(Request $request)
    {
        $shop = (string) $request->query('shop', '');
        if (!$this->isValidShopDomain($shop)) {
            return response()->json(['message' => 'Invalid shop.'], 422);
        }

        $host = (string) $request->query('host', '');

        $state = Str::random(32);
        $request->session()->put('shopify_oauth_state', $state);
        $request->session()->put('shopify_oauth_shop', $shop);
        if ($host !== '') {
            $request->session()->put('shopify_oauth_host', $host);
        }

        $params = http_build_query([
            'client_id' => config('shopify.api_key'),
            'scope' => implode(',', config('shopify.scopes')),
            'redirect_uri' => config('shopify.app_url').'/auth/shopify/callback',
            'state' => $state,
        ]);

        return redirect()->away('https://'.$shop.'/admin/oauth/authorize?'.$params);
    }

    public function handleCallback(Request $request)
    {
        $secret = (string) config('shopify.api_secret');
        if ($secret === '') {
            return response()->json(['message' => 'Shopify secret not configured.'], 500);
        }

        $shop = (string) $request->query('shop', '');
        $hmac = $request->query('hmac');
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if (!$this->isValidShopDomain($shop)) {
            return response()->json(['message' => 'Invalid shop.'], 422);
        }

        if (!ShopifyHmac::verifyQuery($request->query(), $secret, is_string($hmac) ? $hmac : null)) {
            return response()->json(['message' => 'Invalid HMAC.'], 401);
        }

        $expectedState = (string) $request->session()->pull('shopify_oauth_state', '');
        $expectedShop = (string) $request->session()->pull('shopify_oauth_shop', '');
        $expectedHost = (string) $request->session()->pull('shopify_oauth_host', '');

        if ($expectedState === '' || !hash_equals($expectedState, $state) || $expectedShop !== $shop) {
            return response()->json(['message' => 'Invalid OAuth state.'], 401);
        }

        if ($code === '') {
            return response()->json(['message' => 'Missing OAuth code.'], 422);
        }

        $client = new Client(['timeout' => 60]);

        try {
            $res = $client->post('https://'.$shop.'/admin/oauth/access_token', [
                'json' => [
                    'client_id' => config('shopify.api_key'),
                    'client_secret' => $secret,
                    'code' => $code,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Shopify token exchange failed', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Token exchange failed.'], 502);
        }

        $payload = json_decode((string) $res->getBody(), true);
        $token = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        $scopes = is_array($payload) ? ($payload['scope'] ?? null) : null;

        if (!is_string($token) || $token === '') {
            return response()->json(['message' => 'Missing access token.'], 502);
        }

        $shopModel = Shop::query()->updateOrCreate(
            ['shop_domain' => $shop],
            [
                'access_token' => $token,
                'scopes' => is_string($scopes) ? $scopes : implode(',', config('shopify.scopes')),
                'installed_at' => now(),
                'uninstalled_at' => null,
            ]
        );

        $this->webhookRegistrar->registerComplianceWebhooks($shopModel);

        $redirect = '/app?shop='.urlencode($shop);
        if ($expectedHost !== '') {
            $redirect .= '&host='.urlencode($expectedHost);
        }

        return redirect()->to($redirect);
    }

    public function app(Request $request)
    {
        $shop = (string) $request->query('shop', '');
        if (!$this->isValidShopDomain($shop)) {
            return response()->json(['message' => 'Invalid shop.'], 422);
        }

        $host = (string) $request->query('host', '');

        $apiKey = (string) config('shopify.api_key');
        if ($apiKey === '') {
            return response()->json(['message' => 'Shopify API key not configured.'], 500);
        }

        $appUrl = (string) config('shopify.app_url');
        $cssTags = '';

        $adminJs = '';
        $devUrl = env('ADMIN_DEV_URL');
        if (app()->environment('local') && is_string($devUrl) && $devUrl !== '') {
            $adminJs = rtrim($devUrl, '/').'/src/main.jsx';
        }

        if ($adminJs === '') {
            $manifestPath = public_path('admin/.vite/manifest.json');
            $entry = ViteManifest::entry($manifestPath, 'index.html', '/admin');
            foreach ($entry['css'] as $href) {
                $cssTags .= '<link rel="stylesheet" href="'.e($href).'" />';
            }
            $adminJs = $entry['js'];
        }

        if ($adminJs === '') {
            return response()->json(['message' => 'Admin UI not configured. Set ADMIN_DEV_URL (dev) or build admin assets (prod).'], 500);
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="shopify-api-key" content="__API_KEY__" />
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    __CSS_TAGS__
  </head>
  <body>
    <div id="root"></div>
    <script>
      window.__APP_CONFIG__ = {
        apiKey: '__API_KEY__',
        shop: '__SHOP__',
        host: '__HOST__',
        appUrl: '__APP_URL__'
      };
    </script>
    <script type="module" src="__ADMIN_ENTRY__"></script>
  </body>
</html>
HTML;

        $html = str_replace('__API_KEY__', e($apiKey), $html);
        $html = str_replace('__SHOP__', e($shop), $html);
        $html = str_replace('__HOST__', e($host), $html);
        $html = str_replace('__APP_URL__', e($appUrl), $html);
        $html = str_replace('__ADMIN_ENTRY__', e($adminJs), $html);
        $html = str_replace('__CSS_TAGS__', $cssTags, $html);

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    private function isValidShopDomain(string $shop): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/i', $shop);
    }
}
