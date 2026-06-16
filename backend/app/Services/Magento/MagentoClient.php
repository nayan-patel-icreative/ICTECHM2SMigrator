<?php

namespace App\Services\Magento;

use App\Models\MagentoConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MagentoClient
{
    /**
     * Send an authenticated GET/POST request to Magento REST API.
     */
    public function request(MagentoConnection $conn, string $method, string $endpoint, array $options = [])
    {
        $baseUrl = rtrim($conn->api_url, '/');
        if (!str_contains($baseUrl, '/rest')) {
            $baseUrl .= '/rest';
        }
        // Ensure endpoint starts with /V1/
        if (!str_starts_with($endpoint, '/V1/')) {
            $endpoint = '/V1/' . ltrim($endpoint, '/');
        }

        $url = $baseUrl . $endpoint;
        $token = $conn->access_token;

        $client = new Client();

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (isset($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        $options['headers'] = $headers;

        try {
            $response = $client->request($method, $url, $options);
            return json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            Log::error('Magento API request failed', [
                'connection_id' => $conn->id,
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? (string)$e->getResponse()->getBody() : null
            ]);
            throw $e;
        }
    }

    /**
     * Convert standard array filters into Magento searchCriteria query array.
     */
    public function buildSearchCriteria(int $limit = 50, int $page = 1, array $filters = []): array
    {
        $query = [
            'searchCriteria[pageSize]' => $limit,
            'searchCriteria[currentPage]' => $page,
        ];

        $groupIndex = 0;
        foreach ($filters as $f) {
            if (!is_array($f)) {
                continue;
            }

            $field = $f['field'] ?? '';
            $value = $f['value'] ?? '';
            $type = $f['type'] ?? 'equals';

            if ($field === '') {
                continue;
            }

            // Map operator types
            $conditionMap = [
                'equals' => 'eq',
                'not_equals' => 'neq',
                'greater_than' => 'gt',
                'greater_than_equals' => 'gteq',
                'less_than' => 'lt',
                'less_than_equals' => 'lteq',
                'like' => 'like',
                'in' => 'in',
            ];
            $condition = $conditionMap[$type] ?? 'eq';

            $query["searchCriteria[filter_groups][$groupIndex][filters][0][field]"] = $field;
            $query["searchCriteria[filter_groups][$groupIndex][filters][0][value]"] = $value;
            $query["searchCriteria[filter_groups][$groupIndex][filters][0][condition_type]"] = $condition;

            $groupIndex++;
        }

        return $query;
    }

    /**
     * Fetch store configuration (store views, locales, base currencies).
     */
    public function getStoreViews(MagentoConnection $conn): array
    {
        $cacheKey = 'magento_store_views:' . $conn->id;
        return Cache::remember($cacheKey, now()->addHour(), function () use ($conn) {
            try {
                // Get all store configs (locales, currencies)
                $configs = $this->request($conn, 'GET', '/store/storeConfigs');
                // Get list of store views
                $stores = $this->request($conn, 'GET', '/store/storeViews');

                $out = [];
                foreach ($stores as $store) {
                    $code = $store['code'] ?? '';
                    if ($code === '') {
                        continue;
                    }

                    // Match config for locale/currency
                    $locale = 'en_US';
                    $currency = 'USD';
                    if (is_array($configs)) {
                        foreach ($configs as $config) {
                            if (isset($config['code']) && $config['code'] === $code) {
                                $locale = $config['locale'] ?? $locale;
                                $currency = $config['base_currency_code'] ?? $currency;
                                break;
                            }
                        }
                    }

                    $out[] = [
                        'id' => (string) ($store['id'] ?? ''),
                        'code' => $code,
                        'name' => $store['name'] ?? $code,
                        'locale' => str_replace('_', '-', $locale),
                        'currency' => $currency,
                        'website_id' => (string) ($store['website_id'] ?? ''),
                    ];
                }
                return $out;
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch store views, falling back', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Search products in Magento.
     */
    public function searchProducts(MagentoConnection $conn, int $limit = 50, int $page = 1, array $filters = []): array
    {
        // Translate filters if scoped to a specific store view code.
        // In Magento, we specify store view code as a prefix or parameter in REST endpoints.
        // Default is 'all', but we can scope by passing it in the URL: /rest/store_view_code/V1/...
        $endpoint = '/V1/products';
        if ($conn->store_view_code) {
            $endpoint = '/' . $conn->store_view_code . $endpoint;
        }

        $query = $this->buildSearchCriteria($limit, $page, $filters);

        try {
            $res = $this->request($conn, 'GET', $endpoint, ['query' => $query]);
            $items = $res['items'] ?? [];
            $total = (int) ($res['total_count'] ?? count($items));

            return [
                'products' => $items,
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error('Magento product search failed', ['error' => $e->getMessage()]);
            return ['products' => [], 'total' => 0];
        }
    }

    /**
     * Search categories in Magento.
     */
    public function searchCategories(MagentoConnection $conn, int $limit = 100, int $page = 1, array $filters = []): array
    {
        $endpoint = '/V1/categories/list';
        if ($conn->store_view_code) {
            $endpoint = '/' . $conn->store_view_code . $endpoint;
        }

        $query = $this->buildSearchCriteria($limit, $page, $filters);

        try {
            $res = $this->request($conn, 'GET', $endpoint, ['query' => $query]);
            $items = $res['items'] ?? [];
            $total = (int) ($res['total_count'] ?? count($items));

            return [
                'categories' => $items,
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error('Magento category search failed', ['error' => $e->getMessage()]);
            return ['categories' => [], 'total' => 0];
        }
    }

    /**
     * Get a category by ID from Magento.
     */
    public function getCategory(MagentoConnection $conn, int $categoryId): array
    {
        $endpoint = '/V1/categories/' . $categoryId;
        if ($conn->store_view_code) {
            $endpoint = '/' . $conn->store_view_code . $endpoint;
        }

        try {
            return $this->request($conn, 'GET', $endpoint) ?: [];
        } catch (\Throwable $e) {
            Log::error('Magento category fetch failed', ['id' => $categoryId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search customers in Magento.
     */
    public function searchCustomers(MagentoConnection $conn, int $limit = 50, int $page = 1, array $filters = []): array
    {
        $endpoint = '/V1/customers/search';
        $query = $this->buildSearchCriteria($limit, $page, $filters);

        try {
            $res = $this->request($conn, 'GET', $endpoint, ['query' => $query]);
            $items = $res['items'] ?? [];
            $total = (int) ($res['total_count'] ?? count($items));

            return [
                'customers' => $items,
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error('Magento customer search failed', ['error' => $e->getMessage()]);
            return ['customers' => [], 'total' => 0];
        }
    }

    /**
     * Search orders in Magento.
     */
    public function searchOrders(MagentoConnection $conn, int $limit = 50, int $page = 1, array $filters = []): array
    {
        $endpoint = '/V1/orders';
        $query = $this->buildSearchCriteria($limit, $page, $filters);

        try {
            $res = $this->request($conn, 'GET', $endpoint, ['query' => $query]);
            $items = $res['items'] ?? [];
            $total = (int) ($res['total_count'] ?? count($items));

            return [
                'orders' => $items,
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error('Magento order search failed', ['error' => $e->getMessage()]);
            return ['orders' => [], 'total' => 0];
        }
    }

    /**
     * Search newsletter recipients (Subscribed customers).
     */
    public function searchNewsletterRecipients(MagentoConnection $conn, int $limit = 100, int $page = 1, array $filters = []): array
    {
        // Fallback: search for customers who are subscribed to the newsletter.
        // Standard Magento 2 has no newsletter list API. We query customers and filter by subscription status if possible,
        // or search all customers and check their newsletter status in the payload.
        $endpoint = '/V1/customers/search';
        $query = $this->buildSearchCriteria($limit, $page, $filters);

        try {
            $res = $this->request($conn, 'GET', $endpoint, ['query' => $query]);
            $items = $res['items'] ?? [];
            $total = (int) ($res['total_count'] ?? count($items));

            $recipients = [];
            foreach ($items as $item) {
                // By default, customer payloads might have is_subscribed flag under extension_attributes
                $ext = $item['extension_attributes'] ?? [];
                $isSubscribed = (bool) ($ext['is_subscribed'] ?? false);

                // If not explicitly set or true, treat them as a candidate
                $recipients[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'email' => $item['email'] ?? '',
                    'firstName' => $item['firstname'] ?? '',
                    'lastName' => $item['lastname'] ?? '',
                    'active' => $isSubscribed,
                    'salesChannelId' => (string) ($item['store_id'] ?? ''),
                ];
            }

            return [
                'recipients' => $recipients,
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error('Magento newsletter subscriber search failed', ['error' => $e->getMessage()]);
            return ['recipients' => [], 'total' => 0];
        }
    }

    /**
     * Fetch a product and its configurable variants.
     */
    public function fetchProductWithChildren(MagentoConnection $conn, string $sourceSku): array
    {
        $endpoint = '/V1/products/' . urlencode($sourceSku);
        if ($conn->store_view_code) {
            $endpoint = '/' . $conn->store_view_code . $endpoint;
        }

        try {
            $parent = $this->request($conn, 'GET', $endpoint);
            if (!$parent) {
                return ['parent' => null, 'children' => []];
            }

            $children = [];
            $typeId = $parent['type_id'] ?? 'simple';

            if ($typeId === 'configurable') {
                $childEndpoint = '/V1/configurable-products/' . urlencode($sourceSku) . '/children';
                if ($conn->store_view_code) {
                    $childEndpoint = '/' . $conn->store_view_code . $childEndpoint;
                }
                $children = $this->request($conn, 'GET', $childEndpoint) ?: [];

                // Fetch stock items for each child variant to ensure they are migrated with correct quantities
                foreach ($children as &$child) {
                    $childSku = $child['sku'] ?? '';
                    if ($childSku) {
                        try {
                            $stockItem = $this->request($conn, 'GET', '/V1/stockItems/' . urlencode($childSku));
                            if ($stockItem) {
                                if (!isset($child['extension_attributes'])) {
                                    $child['extension_attributes'] = [];
                                }
                                $child['extension_attributes']['stock_item'] = $stockItem;
                            }
                        } catch (\Throwable $e) {
                            Log::warning("Could not fetch stock item for child sku: " . $childSku, ['error' => $e->getMessage()]);
                        }
                    }
                }
                unset($child);

                // Fetch options definitions to know configurable attributes (e.g. Size, Color)
                $optionsEndpoint = '/V1/configurable-products/' . urlencode($sourceSku) . '/options/all';
                if ($conn->store_view_code) {
                    $optionsEndpoint = '/' . $conn->store_view_code . $optionsEndpoint;
                }
                $options = $this->request($conn, 'GET', $optionsEndpoint) ?: [];
                $parent['configurable_options'] = $options;
            }

            return [
                'parent' => $parent,
                'children' => $children,
            ];
        } catch (\Throwable $e) {
            Log::error('Magento product fetch with children failed', ['sku' => $sourceSku, 'error' => $e->getMessage()]);
            return ['parent' => null, 'children' => []];
        }
    }

    /**
     * Resolve currency iso code.
     */
    public function resolveCurrencyIsoCode(MagentoConnection $conn, string $currencyCode): string
    {
        return strtoupper(trim($currencyCode));
    }

    /**
     * Fetch full details of an attribute by its numeric ID.
     */
    public function getAttributeDetails(MagentoConnection $conn, string $attributeId): array
    {
        $cacheKey = 'magento_attribute_details:' . $conn->id . ':' . $attributeId;
        return Cache::remember($cacheKey, now()->addHour(), function () use ($conn, $attributeId) {
            try {
                return $this->request($conn, 'GET', '/products/attributes/' . urlencode($attributeId)) ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Fetch full details of an attribute by its text attribute code (e.g. 'manufacturer').
     */
    public function getAttributeDetailsByCode(MagentoConnection $conn, string $attributeCode): array
    {
        $cacheKey = 'magento_attribute_details_code:' . $conn->id . ':' . $attributeCode;
        return Cache::remember($cacheKey, now()->addHour(), function () use ($conn, $attributeCode) {
            try {
                return $this->request($conn, 'GET', '/products/attributes/' . urlencode($attributeCode)) ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Search sales rules (Cart price rules) in Magento.
     */
    public function searchSalesRules(MagentoConnection $conn, int $limit = 50, int $page = 1, array $filters = []): array
    {
        $endpoint = '/V1/salesRules/search';
        $query = $this->buildSearchCriteria($limit, $page, $filters);

        try {
            $res = $this->request($conn, 'GET', $endpoint, ['query' => $query]);
            $items = $res['items'] ?? [];
            $total = (int) ($res['total_count'] ?? count($items));

            return [
                'rules' => $items,
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error('Magento sales rules search failed', ['error' => $e->getMessage()]);
            return ['rules' => [], 'total' => 0];
        }
    }

    /**
     * Fetch a specific sales rule by ID.
     */
    public function fetchSalesRule(MagentoConnection $conn, string $ruleId): array
    {
        try {
            return $this->request($conn, 'GET', '/V1/salesRules/' . urlencode($ruleId)) ?: [];
        } catch (\Throwable $e) {
            Log::error('Magento sales rule fetch failed', ['rule_id' => $ruleId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch coupons associated with a specific sales rule by ID.
     */
    public function fetchCouponsForRule(MagentoConnection $conn, string $ruleId): array
    {
        $query = [
            'searchCriteria' => [
                'filterGroups' => [
                    [
                        'filters' => [
                            [
                                'field' => 'rule_id',
                                'value' => $ruleId,
                                'conditionType' => 'eq'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $res = $this->request($conn, 'GET', '/V1/coupons/search', ['query' => $query]);
            return $res['items'] ?? [];
        } catch (\Throwable $e) {
            Log::error('Magento coupons search failed', ['rule_id' => $ruleId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get newsletter subscription status for a specific customer from Magento.
     * Returns: 'SUBSCRIBED', 'UNSUBSCRIBED', or 'NOT_FOUND'
     */
    public function getCustomerNewsletterStatus(MagentoConnection $conn, string $customerId): string
    {
        try {
            $query = $this->buildSearchCriteria(1, 1, [
                ['field' => 'customer_id', 'value' => $customerId, 'type' => 'equals'],
            ]);

            $res = $this->request($conn, 'GET', '/V1/newsletter/subscriptions', ['query' => $query]);
            $items = $res['items'] ?? [];

            if (!is_array($items) || count($items) === 0) {
                return 'NOT_FOUND';
            }

            // Magento subscriber_status: 1 = Subscribed, 2 = Not Active, 3 = Unsubscribed, 4 = Unconfirmed
            $status = (int) ($items[0]['subscriber_status'] ?? 3);
            return $status === 1 ? 'SUBSCRIBED' : 'UNSUBSCRIBED';
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch newsletter status for customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return 'NOT_FOUND';
        }
    }

    /**
     * Search manufacturers (retrieve options of the 'manufacturer' attribute).
     */
    public function searchManufacturers(MagentoConnection $conn, int $limit = 50, int $page = 1): array
    {
        $details = $this->getAttributeDetailsByCode($conn, 'manufacturer');
        $options = $details['options'] ?? [];
        
        $options = array_values(array_filter($options, function ($opt) {
            $label = trim((string) ($opt['label'] ?? ''));
            $val = trim((string) ($opt['value'] ?? ''));
            return $label !== '' && $val !== '';
        }));

        $total = count($options);
        $offset = ($page - 1) * $limit;
        $sliced = array_slice($options, $offset, $limit);

        $manufacturers = array_map(function ($opt) {
            return [
                'id' => (string) $opt['value'],
                'name' => (string) $opt['label'],
            ];
        }, $sliced);

        return [
            'manufacturers' => $manufacturers,
            'total' => $total,
        ];
    }
}
