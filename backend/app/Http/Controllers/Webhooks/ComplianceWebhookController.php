<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessComplianceWebhookJob;
use App\Support\ShopifyHmac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ComplianceWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = (string) config('shopify.api_secret');
        if ($secret === '') {
            return response()->json(['message' => 'App not configured.'], 500);
        }

        $raw = (string) $request->getContent();
        $hmac = $request->header('X-Shopify-Hmac-Sha256');
        $topic = (string) $request->header('X-Shopify-Topic', '');
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain', '');

        if (!ShopifyHmac::verifyWebhook($raw, $secret, is_string($hmac) ? $hmac : null)) {
            Log::warning('Compliance webhook HMAC verification failed', [
                'topic' => $topic,
                'shop' => $shopDomain,
            ]);
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = $request->json()->all();

        ProcessComplianceWebhookJob::dispatch([
            'topic' => $topic,
            'shop_domain' => $shopDomain,
            'payload' => $payload,
        ]);

        return response()->json(['ok' => true], 200);
    }
}
