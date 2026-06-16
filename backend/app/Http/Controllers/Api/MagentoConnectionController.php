<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMagentoConnectionRequest;
use App\Models\Shop;
use App\Models\MagentoConnection;
use App\Services\Magento\MagentoClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MagentoConnectionController extends Controller
{
    public function show(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $conn = $shop->magentoConnection;
        if (!$conn) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected'            => true,
            'api_url'              => $conn->api_url,
            'files_path'           => $conn->files_path,
            'access_token_saved'   => $conn->access_token ? true : false,
            'language_config'      => $conn->language_config ?? [],
            'store_view_code'      => $conn->store_view_code,
            'store_view_name'      => $conn->store_view_name,
            'shopify_location_gid' => $conn->shopify_location_gid,
        ]);
    }

    public function store(StoreMagentoConnectionRequest $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $data   = $request->validated();
        $apiUrl = rtrim((string) $data['api_url'], '/');

        $conn          = MagentoConnection::query()->firstOrNew(['shop_id' => $shop->id]);
        $conn->api_url = $apiUrl;

        $token = $data['access_token'] ?? null;

        if (!$conn->exists && (!is_string($token) || trim($token) === '')) {
            return response()->json([
                'message' => 'access_token is required when creating a new Magento connection',
                'errors'  => [
                    'access_token' => ['The access_token field is required.'],
                ],
            ], 422);
        }

        if (is_string($token) && $token !== '') {
            $conn->access_token = $token;
        }

        // Persist language configuration if provided
        if (array_key_exists('language_config', $data)) {
            $langConfig         = $data['language_config'];
            $conn->language_config = is_array($langConfig) ? $langConfig : null;

            Cache::forget('magento_languages:'.$conn->id);
        }

        // Persist Store View scoping if provided
        if (array_key_exists('store_view_code', $data)) {
            $code                 = $data['store_view_code'];
            $conn->store_view_code = is_string($code) && $code !== '' ? $code : null;

            Cache::forget('magento_store_views:'.$conn->id);
        }
        if (array_key_exists('store_view_name', $data)) {
            $name                = $data['store_view_name'];
            $conn->store_view_name = is_string($name) && $name !== '' ? $name : null;
        }

        // Persist files_path
        if (array_key_exists('files_path', $data)) {
            $fp = $data['files_path'];
            $conn->files_path = (is_string($fp) && trim($fp) !== '') ? rtrim(trim($fp), '/') : null;
        }

        // Persist shopify_location_gid
        if (array_key_exists('shopify_location_gid', $data)) {
            $conn->shopify_location_gid = $data['shopify_location_gid'];
        }

        $conn->save();

        return response()->json([
            'connected'            => true,
            'api_url'              => $apiUrl,
            'files_path'           => $conn->files_path,
            'access_token_saved'   => $conn->access_token ? true : false,
            'language_config'      => $conn->language_config ?? [],
            'store_view_code'      => $conn->store_view_code,
            'store_view_name'      => $conn->store_view_name,
            'shopify_location_gid' => $conn->shopify_location_gid,
        ], 200);
    }

    /**
     * Fetch available languages from Magento (locales mapped from store views).
     */
    public function languages(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $conn = $shop->magentoConnection;
        if (!$conn || !$conn->api_url || !$conn->access_token) {
            return response()->json([
                'error'     => 'Magento connection not configured',
                'languages' => [],
            ], 422);
        }

        try {
            $magento = app(MagentoClient::class);
            $storeViews = $magento->getStoreViews($conn);

            $languages = [];
            foreach ($storeViews as $sv) {
                $languages[] = [
                    'id'     => $sv['id'],
                    'name'   => $sv['name'] . ' (' . $sv['locale'] . ')',
                    'locale' => $sv['locale'],
                ];
            }

            return response()->json(['languages' => $languages]);
        } catch (\Throwable $e) {
            return response()->json([
                'error'     => $e->getMessage(),
                'languages' => [],
            ], 500);
        }
    }

    /**
     * Fetch available store views from Magento.
     */
    public function storeViews(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $conn = $shop->magentoConnection;
        if (!$conn || !$conn->api_url || !$conn->access_token) {
            return response()->json([
                'error'       => 'Magento connection not configured',
                'store_views' => [],
            ], 422);
        }

        try {
            Cache::forget('magento_store_views:'.$conn->id);

            $magento    = app(MagentoClient::class);
            $storeViews = $magento->getStoreViews($conn);

            return response()->json(['store_views' => $storeViews]);
        } catch (\Throwable $e) {
            return response()->json([
                'error'       => $e->getMessage(),
                'store_views' => [],
            ], 500);
        }
    }
}
