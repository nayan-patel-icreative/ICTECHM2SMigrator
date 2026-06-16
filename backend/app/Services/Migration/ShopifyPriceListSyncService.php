<?php

namespace App\Services\Migration;

use App\Models\Shop;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Syncs Shopware product prices into Shopify using the correct currency.
 *
 * Strategy:
 *   1. Detect the Shopware product currency (e.g. GBP).
 *   2. If the Shopify store's primary market already uses that currency → prices are already correct.
 *   3. If not → update the primary market's currency to match Shopware via marketCurrencySettingsUpdate.
 *      This is the same mechanism that makes orderCreate show £ instead of ₹.
 *   4. Additionally, set fixed prices on a currency-specific price list so the prices
 *      are explicitly recorded in the correct currency for all markets.
 */
class ShopifyPriceListSyncService
{
    private ShopifyAdminGraphqlClient $client;

    public function __construct(ShopifyAdminGraphqlClient $client)
    {
        $this->client = $client;
    }

    /**
     * Ensure the store's primary market currency matches the Shopware product currency,
     * then set fixed prices on the matching price list.
     *
     * @param array<string, string> $variantPrices        variantGid => gross price string
     * @param array<string, string|null> $variantComparePrices  variantGid => compareAt price or null
     * @return array{ok?: bool, skipped?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    public function syncVariantPrices(        Shop $shop,
        string $currencyCode,
        array $variantPrices,
        array $variantComparePrices = []
    ): array {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($currencyCode === '' || count($variantPrices) === 0) {
            return ['ok' => true, 'skipped' => true];
        }

        // Step 1: Ensure the primary market uses the Shopware currency
        $marketResult = $this->ensurePrimaryMarketCurrency($shop, $currencyCode);
        if (!empty($marketResult['errors']) || !empty($marketResult['userErrors'])) {
            Log::warning('Could not update primary market currency; price list sync may show wrong currency', [
                'shop' => $shop->shop_domain,
                'currency' => $currencyCode,
                'result' => $marketResult,
            ]);
            // Don't abort — still try the price list sync
        }

        // Step 2: Find or create a price list for this currency and set fixed prices
        $priceListGid = $this->resolvePriceListForCurrency($shop, $currencyCode);
        if ($priceListGid === null) {
            Log::warning('Could not resolve price list for currency; skipping price list sync', [
                'shop' => $shop->shop_domain,
                'currency' => $currencyCode,
            ]);
            return ['ok' => true, 'skipped' => true];
        }

        // Step 3: Build the prices input
        $prices = [];
        foreach ($variantPrices as $variantGid => $amount) {
            if (!is_string($variantGid) || $variantGid === '' || !is_numeric($amount)) {
                continue;
            }

            $entry = [
                'variantId' => $variantGid,
                'price' => [
                    'amount' => number_format((float) $amount, 2, '.', ''),
                    'currencyCode' => $currencyCode,
                ],
            ];

            $compareAt = $variantComparePrices[$variantGid] ?? null;
            if (is_numeric($compareAt) && (float) $compareAt > 0) {
                $entry['compareAtPrice'] = [
                    'amount' => number_format((float) $compareAt, 2, '.', ''),
                    'currencyCode' => $currencyCode,
                ];
            }

            $prices[] = $entry;
        }

        if (count($prices) === 0) {
            return ['ok' => true, 'skipped' => true];
        }

        $mutation = <<<'GQL'
mutation AddFixedPrices($priceListId: ID!, $prices: [PriceListPriceInput!]!) {
  priceListFixedPricesAdd(priceListId: $priceListId, prices: $prices) {
    prices {
      variant { id }
      price { amount currencyCode }
    }
    userErrors { field message code }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'priceListId' => $priceListGid,
            'prices' => $prices,
        ]);

        if (isset($res['errors'])) {
            return ['errors' => $res['errors']];
        }

        $userErrors = data_get($res, 'data.priceListFixedPricesAdd.userErrors', []);
        $userErrors = is_array($userErrors) ? $userErrors : [];
        if (count($userErrors) > 0) {
            return ['userErrors' => $userErrors];
        }

        return ['ok' => true];
    }

    /**
     * Sync Shopware advanced (rule-based) prices to dedicated Shopify price lists.
     * One price list per ruleId, named "Shopware Advanced Prices – {ruleId}".
     * Non-fatal: errors are returned but never thrown.
     *
     * @param array<string, array{ruleName: string, entries: array<int, array{variantGid: string, amount: string, currencyCode: string, compareAt: string|null, quantityMin: int, ruleName: string}>}> $groupedEntries Output of AdvancedPriceMapper::map()
     * @return array<string, array{ok?: bool, skipped?: bool, userErrors?: array<int, mixed>, errors?: mixed}> Keyed by ruleId
     */
    public function syncAdvancedPrices(Shop $shop, string $currencyCode, array $groupedEntries): array
    {
        $results = [];

        foreach ($groupedEntries as $ruleId => $group) {
            $ruleId = (string) $ruleId;
            $entries = $group['entries'] ?? [];

            if (count($entries) === 0) {
                $results[$ruleId] = ['ok' => true, 'skipped' => true];
                continue;
            }

            $listName = 'Shopware Advanced Prices - ' . $ruleId;
            $cacheKey = 'shopify:adv_price_list_gid:' . $shop->id . ':' . $ruleId . ':' . strtoupper($currencyCode);

            $priceListGid = $this->resolvePriceList($shop, $currencyCode, $listName, $cacheKey);
            if ($priceListGid === null) {
                Log::warning('Could not resolve advanced price list; skipping rule', [
                    'shop'     => $shop->shop_domain,
                    'rule_id'  => $ruleId,
                    'currency' => $currencyCode,
                ]);
                $results[$ruleId] = ['ok' => true, 'skipped' => true];
                continue;
            }

            $prices = [];
            foreach ($entries as $entry) {
                $variantGid = (string) ($entry['variantGid'] ?? '');
                $amount = (string) ($entry['amount'] ?? '');
                if ($variantGid === '' || !is_numeric($amount)) {
                    continue;
                }

                $priceEntry = [
                    'variantId' => $variantGid,
                    'price' => [
                        'amount' => number_format((float) $amount, 2, '.', ''),
                        'currencyCode' => strtoupper($currencyCode),
                    ],
                ];

                $compareAt = $entry['compareAt'] ?? null;
                if (is_numeric($compareAt) && (float) $compareAt > (float) $amount) {
                    $priceEntry['compareAtPrice'] = [
                        'amount' => number_format((float) $compareAt, 2, '.', ''),
                        'currencyCode' => strtoupper($currencyCode),
                    ];
                }

                $prices[] = $priceEntry;
            }

            if (count($prices) === 0) {
                $results[$ruleId] = ['ok' => true, 'skipped' => true];
                continue;
            }

            $mutation = <<<'GQL'
mutation AddFixedPrices($priceListId: ID!, $prices: [PriceListPriceInput!]!) {
  priceListFixedPricesAdd(priceListId: $priceListId, prices: $prices) {
    prices {
      variant { id }
      price { amount currencyCode }
    }
    userErrors { field message code }
  }
}
GQL;

            $res = $this->client->query($shop, $mutation, [
                'priceListId' => $priceListGid,
                'prices'      => $prices,
            ]);

            if (isset($res['errors'])) {
                $results[$ruleId] = ['errors' => $res['errors']];
                continue;
            }

            $userErrors = data_get($res, 'data.priceListFixedPricesAdd.userErrors', []);
            $userErrors = is_array($userErrors) ? $userErrors : [];
            if (count($userErrors) > 0) {
                $results[$ruleId] = ['userErrors' => $userErrors];
                continue;
            }

            $results[$ruleId] = ['ok' => true];
        }

        return $results;
    }

    /**
     * Ensure the store's primary market currency matches the given currency code.
     * Uses marketCurrencySettingsUpdate — the same mechanism that makes orders show £ instead of ₹.
     *
     * @return array{ok?: bool, skipped?: bool, userErrors?: array<int, mixed>, errors?: mixed}
     */
    private function ensurePrimaryMarketCurrency(Shop $shop, string $currencyCode): array
    {
        $cacheKey = 'shopify:primary_market_currency:'.$shop->id;
        $cached = Cache::get($cacheKey);

        // If we already know the primary market is set to this currency, skip
        if ($cached === $currencyCode) {
            return ['ok' => true, 'skipped' => true];
        }

        $lock = Cache::lock('shopify:primary_market_currency_update:'.$shop->id, 60);
        return $lock->block(30, function () use ($shop, $currencyCode, $cacheKey) {
            // Double-check after lock
            if (Cache::get($cacheKey) === $currencyCode) {
                return ['ok' => true, 'skipped' => true];
            }

            // Fetch the primary market
            $primaryMarket = $this->fetchPrimaryMarket($shop);
            if ($primaryMarket === null) {
                // Can't fetch market (missing scope or API error) — cache as unknown and skip
                // The store currency may already be correct (set manually in Shopify Admin)
                Cache::put($cacheKey, '__unknown__', now()->addMinutes(30));
                return ['ok' => true, 'skipped' => true];
            }

            $marketId = (string) ($primaryMarket['id'] ?? '');
            $currentCurrency = strtoupper((string) ($primaryMarket['currencySettings']['baseCurrency']['currencyCode'] ?? ''));

            if ($currentCurrency === $currencyCode) {
                Cache::put($cacheKey, $currencyCode, now()->addHours(24));
                return ['ok' => true, 'skipped' => true];
            }

            // Update the primary market's currency to match Shopware
            $mutation = <<<'GQL'
mutation UpdateMarketCurrency($marketId: ID!, $input: MarketCurrencySettingsUpdateInput!) {
  marketCurrencySettingsUpdate(marketId: $marketId, input: $input) {
    market {
      id
      currencySettings {
        baseCurrency { currencyCode }
      }
    }
    userErrors { field message code }
  }
}
GQL;

            $res = $this->client->query($shop, $mutation, [
                'marketId' => $marketId,
                'input' => [
                    'baseCurrency' => $currencyCode,
                ],
            ]);

            if (isset($res['errors'])) {
                return ['errors' => $res['errors']];
            }

            $userErrors = data_get($res, 'data.marketCurrencySettingsUpdate.userErrors', []);
            $userErrors = is_array($userErrors) ? $userErrors : [];
            if (count($userErrors) > 0) {
                return ['userErrors' => $userErrors];
            }

            $newCurrency = strtoupper((string) data_get(
                $res,
                'data.marketCurrencySettingsUpdate.market.currencySettings.baseCurrency.currencyCode',
                ''
            ));

            Cache::put($cacheKey, $newCurrency !== '' ? $newCurrency : $currencyCode, now()->addHours(24));

            Log::info('Updated primary market currency to match Shopware', [
                'shop' => $shop->shop_domain,
                'from' => $currentCurrency,
                'to' => $currencyCode,
            ]);

            return ['ok' => true];
        });
    }

    /**
     * Fetch the store's primary market with its currency settings.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPrimaryMarket(Shop $shop): ?array
    {
        $query = <<<'GQL'
query PrimaryMarket {
  markets(first: 10) {
    nodes {
      id
      name
      primary
      currencySettings {
        baseCurrency { currencyCode }
        localCurrencies
      }
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, []);
        if (isset($res['errors'])) {
            return null;
        }

        $nodes = data_get($res, 'data.markets.nodes', []);
        if (!is_array($nodes) || count($nodes) === 0) {
            return null;
        }

        // Find the primary market first
        foreach ($nodes as $node) {
            if (!empty($node['primary'])) {
                return $node;
            }
        }

        // Fall back to first market
        return $nodes[0];
    }

    /**
     * Find an existing price list for the given currency, or create one.
     * Results are cached per shop+currency for 24 hours.
     *
     * @return string|null The price list GID, or null on failure
     */
    private function resolvePriceListForCurrency(Shop $shop, string $currencyCode): ?string
    {
        $cacheKey = 'shopify:price_list_gid:'.$shop->id.':'.$currencyCode;
        $name = 'Shopware '.$currencyCode.' Prices';
        return $this->resolvePriceList($shop, $currencyCode, $name, $cacheKey);
    }

    /**
     * General price list resolver: find or create a price list by currency + name.
     * Cached by the provided cache key for 24 hours.
     *
     * @return string|null The price list GID, or null on failure
     */
    private function resolvePriceList(Shop $shop, string $currencyCode, string $name, string $cacheKey): ?string
    {
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lock = Cache::lock('shopify:price_list_resolve:'.$shop->id.':'.md5($cacheKey), 60);
        return $lock->block(30, function () use ($shop, $currencyCode, $name, $cacheKey) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            // Search by both currency and name to find the right list
            $existing = $this->findPriceListByCurrencyAndName($shop, $currencyCode, $name);
            if ($existing !== null) {
                Cache::put($cacheKey, $existing, now()->addHours(24));
                return $existing;
            }

            $created = $this->createPriceListWithName($shop, $currencyCode, $name);
            if ($created !== null) {
                Cache::put($cacheKey, $created, now()->addHours(24));
                Log::info('Created Shopify price list', [
                    'shop'           => $shop->shop_domain,
                    'currency'       => $currencyCode,
                    'name'           => $name,
                    'price_list_gid' => $created,
                ]);
            }

            return $created;
        });
    }

    /**
     * Query Shopify for an existing price list matching currency and name.
     * Falls back to currency-only match if name not found.
     * Fetches all price lists and filters locally (avoids filter API compatibility issues).
     */
    private function findPriceListByCurrencyAndName(Shop $shop, string $currencyCode, string $name): ?string
    {
        $query = <<<'GQL'
query FindPriceLists {
  priceLists(first: 50) {
    nodes {
      id
      name
      currency
    }
  }
}
GQL;

        $res = $this->client->query($shop, $query, []);
        if (isset($res['errors'])) {
            return null;
        }

        $nodes = data_get($res, 'data.priceLists.nodes', []);
        if (!is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $node) {
            $nodeCurrency = strtoupper((string) ($node['currency'] ?? ''));
            $nodeName = (string) ($node['name'] ?? '');
            // Prefer exact name+currency match, fall back to currency-only
            if ($nodeCurrency === $currencyCode && $nodeName === $name) {
                $gid = (string) ($node['id'] ?? '');
                if ($gid !== '') {
                    return $gid;
                }
            }
        }

        // Fall back: currency-only match
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodeCurrency = strtoupper((string) ($node['currency'] ?? ''));
            if ($nodeCurrency === $currencyCode) {
                $gid = (string) ($node['id'] ?? '');
                if ($gid !== '') {
                    return $gid;
                }
            }
        }

        return null;
    }

    /**
     * Create a new price list with the given name and currency with a 0% adjustment.
     */
    private function createPriceListWithName(Shop $shop, string $currencyCode, string $name): ?string
    {
        $mutation = <<<'GQL'
mutation CreatePriceList($input: PriceListCreateInput!) {
  priceListCreate(input: $input) {
    priceList {
      id
      name
      currency
    }
    userErrors { field message code }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'input' => [
                'name'     => $name,
                'currency' => $currencyCode,
                'parent'   => [
                    'adjustment' => [
                        'type'  => 'PERCENTAGE_DECREASE',
                        'value' => 0.0,
                    ],
                ],
            ],
        ]);

        if (isset($res['errors'])) {
            Log::warning('Failed to create price list', [
                'shop'     => $shop->shop_domain,
                'currency' => $currencyCode,
                'name'     => $name,
                'errors'   => $res['errors'],
            ]);
            return null;
        }

        $userErrors = data_get($res, 'data.priceListCreate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            // "Name already taken" — try to find the existing one
            foreach ($userErrors as $ue) {
                $msg = strtolower((string) ($ue['message'] ?? ''));
                if (str_contains($msg, 'taken') || str_contains($msg, 'already')) {
                    return $this->findPriceListByCurrencyAndName($shop, $currencyCode, $name);
                }
            }
            Log::warning('Price list create userErrors', [
                'shop'       => $shop->shop_domain,
                'currency'   => $currencyCode,
                'name'       => $name,
                'userErrors' => $userErrors,
            ]);
            return null;
        }

        $gid = (string) data_get($res, 'data.priceListCreate.priceList.id', '');
        return $gid !== '' ? $gid : null;
    }

    /**
     * @deprecated Use createPriceListWithName instead.
     * Create a new price list for the given currency with a 0% adjustment.
     */
    private function createPriceList(Shop $shop, string $currencyCode): ?string
    {
        $mutation = <<<'GQL'
mutation CreatePriceList($input: PriceListCreateInput!) {
  priceListCreate(input: $input) {
    priceList {
      id
      name
      currency
    }
    userErrors { field message code }
  }
}
GQL;

        $res = $this->client->query($shop, $mutation, [
            'input' => [
                'name' => 'Shopware '.$currencyCode.' Prices',
                'currency' => $currencyCode,
                'parent' => [
                    'adjustment' => [
                        'type' => 'PERCENTAGE_DECREASE',
                        'value' => 0.0,
                    ],
                ],
            ],
        ]);

        if (isset($res['errors'])) {
            Log::warning('Failed to create price list', [
                'shop' => $shop->shop_domain,
                'currency' => $currencyCode,
                'errors' => $res['errors'],
            ]);
            return null;
        }

        $userErrors = data_get($res, 'data.priceListCreate.userErrors', []);
        if (is_array($userErrors) && count($userErrors) > 0) {
            Log::warning('Price list create userErrors', [
                'shop' => $shop->shop_domain,
                'currency' => $currencyCode,
                'userErrors' => $userErrors,
            ]);
            return null;
        }

        $gid = (string) data_get($res, 'data.priceListCreate.priceList.id', '');
        return $gid !== '' ? $gid : null;
    }
}
