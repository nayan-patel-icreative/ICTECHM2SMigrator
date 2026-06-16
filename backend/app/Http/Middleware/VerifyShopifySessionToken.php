<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifySessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Missing session token.'], 401);
        }

        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            return response()->json(['message' => 'Missing session token.'], 401);
        }

        $apiKey = config('shopify.api_key');
        $secret = config('shopify.api_secret');

        if (!$apiKey || !$secret) {
            return response()->json(['message' => 'Shopify app not configured.'], 500);
        }

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid session token.'], 401);
        }

        $payload = (array) $decoded;
        $aud = $payload['aud'] ?? null;
        $dest = $payload['dest'] ?? null;

        if ($aud !== $apiKey) {
            return response()->json(['message' => 'Invalid token audience.'], 401);
        }

        if (!is_string($dest) || $dest === '') {
            return response()->json(['message' => 'Invalid token destination.'], 401);
        }

        $shopDomain = parse_url($dest, PHP_URL_HOST);
        if (!is_string($shopDomain) || $shopDomain === '') {
            return response()->json(['message' => 'Invalid token destination.'], 401);
        }

        $request->attributes->set('shop_domain', $shopDomain);

        $shop = Shop::query()->where('shop_domain', $shopDomain)->first();
        if (!$shop) {
            $baseUrl = rtrim((string) config('shopify.app_url'), '/');
            $host = (string) $request->query('host', '');
            $installUrl = $baseUrl.'/auth/shopify?shop='.urlencode($shopDomain);
            if ($host !== '') {
                $installUrl .= '&host='.urlencode($host);
            }

            return response()->json([
                'message' => 'Shop not installed.',
                'shop_domain' => $shopDomain,
                'install_url' => $installUrl,
            ], 401);
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }
}
