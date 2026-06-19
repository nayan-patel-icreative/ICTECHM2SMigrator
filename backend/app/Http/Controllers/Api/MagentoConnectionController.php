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

        // Verify connectivity before saving
        try {
            $magento = app(MagentoClient::class);
            $tempConn = clone $conn;
            $tempConn->api_url = $apiUrl;
            if (is_string($token) && $token !== '') {
                $tempConn->access_token = $token;
            }

            $magento->request($tempConn, 'GET', '/store/storeConfigs', [
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\ClientException) {
                $response = $e->getResponse();
                $statusCode = $response ? $response->getStatusCode() : 0;

                if ($statusCode === 404) {
                    $message = 'The REST API endpoint could not be found (404 Not Found). Please verify that the API URL is your Magento store\'s root URL (e.g., http://magentostore.local) and not a product or category page URL.';
                } elseif ($statusCode === 401 || $statusCode === 403) {
                    $message = 'Authentication failed (Unauthorized). Please verify that your Magento Access Token is correct and active.';
                } else {
                    $message = 'Magento API returned an error (' . $statusCode . '). Please verify your Magento connection details.';
                }
            } elseif ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                $message = 'Connection failed. Could not reach the Magento server. Please verify that the host domain is correct, active, and accessible.';
            } else {
                if (str_contains($message, ' resulted in a `')) {
                    $parts = explode('response:', $message);
                    $message = trim($parts[0]);
                }
            }

            return response()->json([
                'message' => 'Could not connect to Magento store. Please check your URL and Access Token.',
                'errors'  => [
                    'api_url' => [$message],
                ],
            ], 422);
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

            // Determine primary/default locale
            $defaultCode = $conn->store_view_code ?: 'default';
            $defaultLocale = 'en-US';
            foreach ($storeViews as $sv) {
                if ($sv['code'] === $defaultCode) {
                    $defaultLocale = $sv['locale'];
                    break;
                }
            }

            $languages = [];
            $seenLocales = [$defaultLocale => true];

            foreach ($storeViews as $sv) {
                $locale = $sv['locale'];
                if (isset($seenLocales[$locale])) {
                    continue;
                }
                $seenLocales[$locale] = true;

                $langName = $this->getLanguageNameFromLocale($locale);

                $languages[] = [
                    'id'     => $locale,
                    'name'   => $langName . ' (' . $locale . ')',
                    'locale' => $locale,
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

    private function getLanguageNameFromLocale(string $locale): string
    {
        $parts = explode('-', $locale);
        $langCode = strtolower($parts[0]);

        $names = [
            'en' => 'English',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'it' => 'Italian',
            'nl' => 'Dutch',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
        ];

        return $names[$langCode] ?? ucfirst($langCode);
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
