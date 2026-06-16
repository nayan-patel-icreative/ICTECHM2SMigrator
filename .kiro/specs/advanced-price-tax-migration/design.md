# Technical Design: Advanced Price & Tax Migration

## Components and Interfaces

### New Components

| Component | Type | Location |
|-----------|------|----------|
| `TaxMapper` | Service class | `app/Services/Migration/TaxMapper.php` |
| `AdvancedPriceMapper` | Service class | `app/Services/Migration/AdvancedPriceMapper.php` |
| `price_mode` DB column | Migration | `database/migrations/2026_05_27_000002_add_price_mode_to_shops_table.php` |

### Modified Components

| Component | Interface Change |
|-----------|-----------------|
| `ProductPayloadMapper` | `mapParentWithVariants()` gains optional `?string $priceMode`; `mapShopwareMetafields()` gains optional `?string $priceMode` |
| `ShopifyPriceListSyncService` | New `syncAdvancedPrices(Shop, string, array): array` method |
| `ShopwareClient` | `prices` association gains nested `rule` sub-association |
| `ShopifyProductSyncService` | 5 new metafield definitions added |
| `ProcessProductMigrationItemJob` | Passes `price_mode`, calls `syncAdvancedPrices()` |
| `Shop` model | `price_mode` added to `$fillable` |

---

## Data Models

### `shops` table — new column

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `price_mode` | `string` | `'gross'` | Whether to use gross or net Shopware prices as Shopify selling price |

### AdvancedPriceMapper output structure

```php
// Return type: array<string, array{ruleName: string, entries: array<int, array{
//   variantGid: string,
//   amount: string,
//   currencyCode: string,
//   compareAt: string|null,
//   quantityMin: int,
//   ruleName: string
// }>>
// Keyed by ruleId
[
    'rule-uuid-123' => [
        'ruleName' => 'Wholesale',
        'entries'  => [
            ['variantGid' => 'gid://shopify/ProductVariant/1', 'amount' => '15.00', 'currencyCode' => 'GBP', 'compareAt' => '20.00', 'quantityMin' => 1, 'ruleName' => 'Wholesale'],
            ['variantGid' => 'gid://shopify/ProductVariant/1', 'amount' => '12.00', 'currencyCode' => 'GBP', 'compareAt' => null,    'quantityMin' => 10, 'ruleName' => 'Wholesale'],
        ],
    ],
]
```

### New Shopify metafields (namespace: `shopware`)

| Key | Type | Source |
|-----|------|--------|
| `tax_rate` | `single_line_text_field` | `product.tax.taxRate` |
| `tax_name` | `single_line_text_field` | `product.tax.name` |
| `advanced_price_count` | `single_line_text_field` | `count(product.prices[])` |
| `advanced_prices_json` | `json` | Summary of `product.prices[]` |
| `price_mode` | `single_line_text_field` | Active price mode used during migration |

---

## Overview

This design extends the existing product migration pipeline to migrate tax metadata, gross/net price mode, compare-at prices, purchase costs, and Shopware advanced (rule-based) prices into Shopify. All changes follow existing patterns in the codebase (Cache::lock, Log::warning, error_context, metafield definitions).

---

## Architecture

### New Files

| File | Purpose |
|------|---------|
| `app/Services/Migration/TaxMapper.php` | Resolves taxable flag and tax metafields from Shopware tax data |
| `app/Services/Migration/AdvancedPriceMapper.php` | Maps `product.prices[]` to Shopify price list entries per rule |
| `database/migrations/2026_05_27_000002_add_price_mode_to_shops_table.php` | Adds `price_mode` column to `shops` table |

### Modified Files

| File | Changes |
|------|---------|
| `app/Models/Shop.php` | Add `price_mode` to `$fillable` |
| `app/Services/Shopware/ShopwareClient.php` | Add `rule` association to `prices[]` in default/reduced associations |
| `app/Services/Migration/ProductPayloadMapper.php` | Use TaxMapper, price_mode, purchase cost, net price support |
| `app/Services/Migration/ShopifyProductSyncService.php` | Add `tax_rate`, `tax_name`, `advanced_price_count`, `advanced_prices_json`, `price_mode` metafield definitions |
| `app/Services/Migration/ShopifyPriceListSyncService.php` | Add `syncAdvancedPrices()` method for rule-based price lists |
| `app/Jobs/ProcessProductMigrationItemJob.php` | Call advanced price sync after base price sync |

---

## Component Designs

### 1. TaxMapper (`app/Services/Migration/TaxMapper.php`)

Stateless helper — no constructor dependencies.

```php
class TaxMapper
{
    /**
     * Resolve taxable flag from Shopware product/variant tax data.
     * Falls back to parent product tax if variant has none.
     */
    public function isTaxable(array $variant, array $fallbackParent): bool

    /**
     * Extract tax rate as float (e.g. 25.0) or null if not present.
     */
    public function taxRate(array $product): ?float

    /**
     * Extract tax name string (e.g. "Standard rate") or empty string.
     */
    public function taxName(array $product): string
}
```

**Logic:**
- `isTaxable()`: reads `tax.taxRate` from `$variant` first, then `$fallbackParent`. If `taxRate > 0` → `true`. If `taxRate === 0` → `false`. If null/missing → `true` (safe default).
- `taxRate()`: `data_get($product, 'tax.taxRate')` → cast to float, or null.
- `taxName()`: `data_get($product, 'tax.name')` → string, or `''`.

---

### 2. AdvancedPriceMapper (`app/Services/Migration/AdvancedPriceMapper.php`)

Maps Shopware `prices[]` entries to structured price list entries per rule.

```php
class AdvancedPriceMapper
{
    /**
     * Map Shopware prices[] to grouped price list entries.
     *
     * @param array $prices  Shopware product.prices[] array
     * @param array<string,string> $variantIdByShopwareId  swVariantId => shopifyVariantGid
     * @param array<int,string> $allVariantGids  fallback for simple products
     * @param string $currencyCode  e.g. "GBP"
     * @param string $priceMode  "gross" or "net"
     * @return array<string, array{ruleName: string, entries: array}>
     *   Keyed by ruleId. Each entry: [variantGid, amount, currency, compareAt, quantityMin, ruleName]
     */
    public function map(
        array $prices,
        array $variantIdByShopwareId,
        array $allVariantGids,
        string $currencyCode,
        string $priceMode = 'gross'
    ): array
}
```

**Logic per `prices[]` entry:**
1. Skip if `gross`/`net` is not numeric or ≤ 0.
2. Resolve variant GID: use `variantIdByShopwareId[$entry['productId']]` if set, else all `$allVariantGids` (for simple products).
3. `$amount` = `$priceMode === 'net' ? $entry['net'] : $entry['gross']`
4. `$compareAt` = list price if `listPrice.gross` (or `listPrice.net`) > `$amount`, else null.
5. `$quantityMin` = `max(1, (int) ($entry['quantityStart'] ?? 1))`
6. `$ruleId` = `(string) ($entry['ruleId'] ?? 'default')`
7. `$ruleName` = `(string) data_get($entry, 'rule.name', $ruleId)`
8. Deduplicate by `[$ruleId, $variantGid, $quantityMin]` — keep lower price.
9. Group by `$ruleId` → return map.

---

### 3. ProductPayloadMapper changes

#### 3a. `variantFromShopware()` — add TaxMapper + purchase cost

```php
// Before (hardcoded):
'taxable' => true,

// After:
'taxable' => $taxMapper->isTaxable($variant, $fallbackParent),

// New — purchase cost:
$purchaseCost = $this->resolvePurchaseCost($variant, $fallbackParent);
if ($purchaseCost !== null) {
    $payload['inventoryItem'] = ['cost' => $purchaseCost];
}
```

New private method:
```php
private function resolvePurchaseCost(array $variant, array $fallbackParent): ?string
{
    $cost = data_get($variant, 'purchasePrice') ?? data_get($fallbackParent, 'purchasePrice');
    if (!is_numeric($cost) || (float)$cost <= 0) return null;
    return number_format((float)$cost, 2, '.', '');
}
```

#### 3b. `moneyToPrice()` / `moneyToCompareAtPrice()` — price_mode aware

Both methods gain a `string $priceMode = 'gross'` parameter:
- `moneyToPrice()`: reads `price.0.gross` (gross mode) or `price.0.net` (net mode)
- `moneyToCompareAtPrice()`: reads `price.0.listPrice.gross` or `price.0.listPrice.net`

`mapParentWithVariants()` receives `?string $priceMode` and passes it through to `buildVariants()` → `variantFromShopware()`.

#### 3c. `mapShopwareMetafields()` — add tax + advanced price metafields

```php
// Tax metafields
$taxRate = $taxMapper->taxRate($parent);
if ($taxRate !== null) {
    $this->pushProductMetafield($out, 'tax_rate', (string)$taxRate);
}
$taxName = $taxMapper->taxName($parent);
if ($taxName !== '') {
    $this->pushProductMetafield($out, 'tax_name', $taxName);
}

// Advanced price metafields
$advancedPrices = data_get($parent, 'prices', []);
$advancedPrices = is_array($advancedPrices) ? $advancedPrices : [];
$this->pushProductMetafield($out, 'advanced_price_count', (string)count($advancedPrices));
if (count($advancedPrices) > 0) {
    $summary = array_map(fn($p) => [
        'ruleId'        => $p['ruleId'] ?? null,
        'quantityStart' => $p['quantityStart'] ?? null,
        'quantityEnd'   => $p['quantityEnd'] ?? null,
        'gross'         => $p['gross'] ?? null,
        'net'           => $p['net'] ?? null,
    ], $advancedPrices);
    $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json) && strlen($json) <= 5000) {
        $this->pushProductMetafield($out, 'advanced_prices_json', $json, 'json');
    }
}

// Price mode used
$this->pushProductMetafield($out, 'price_mode', $priceMode ?? 'gross');
```

---

### 4. ShopifyProductSyncService — new metafield definitions

Add to `$definitions` array in `ensureCommonProductMetafieldDefinitions()`:

```php
['name' => 'Tax Rate',              'namespace' => 'shopware', 'key' => 'tax_rate',              'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
['name' => 'Tax Name',              'namespace' => 'shopware', 'key' => 'tax_name',              'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
['name' => 'Advanced Price Count',  'namespace' => 'shopware', 'key' => 'advanced_price_count',  'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
['name' => 'Advanced Prices JSON',  'namespace' => 'shopware', 'key' => 'advanced_prices_json',  'ownerType' => 'PRODUCT', 'type' => 'json',                   'pin' => true],
['name' => 'Price Mode',            'namespace' => 'shopware', 'key' => 'price_mode',            'ownerType' => 'PRODUCT', 'type' => 'single_line_text_field', 'pin' => true],
```

Cache key `shopify:product_common_metafields_ensured:{shopId}` must be cleared on deploy.

---

### 5. ShopifyPriceListSyncService — `syncAdvancedPrices()`

New public method alongside existing `syncVariantPrices()`:

```php
/**
 * Sync Shopware advanced (rule-based) prices to dedicated Shopify price lists.
 * One price list per ruleId, named "Shopware Advanced Prices – {ruleId}".
 * Non-fatal: errors are returned but never throw.
 *
 * @param array $groupedEntries  Output of AdvancedPriceMapper::map()
 * @return array<string, array{ok?:bool, skipped?:bool, errors?:mixed, userErrors?:mixed}>
 *   Keyed by ruleId.
 */
public function syncAdvancedPrices(Shop $shop, string $currencyCode, array $groupedEntries): array
```

**Per ruleId:**
1. Resolve price list GID via `resolvePriceListForCurrency()` — but use a rule-specific cache key: `shopify:adv_price_list_gid:{shopId}:{ruleId}:{currency}` and name `"Shopware Advanced Prices – {ruleId}"`.
2. Build `$prices` array from entries.
3. Call `priceListFixedPricesAdd` mutation (same as existing).
4. Return result per ruleId.

To support rule-specific price list names, extract `resolvePriceListForCurrency()` into a more general `resolvePriceList(Shop $shop, string $currencyCode, string $name, string $cacheKey): ?string` private method.

---

### 6. ShopwareClient — add `rule` association to `prices[]`

In `defaultProductAssociations()` and `reducedProductAssociations()`, update the `prices` entry:

```php
// Before:
'prices' => [],

// After:
'prices' => [
    'associations' => [
        'rule' => [],
    ],
],
```

Apply to both parent and children associations.

---

### 7. ProcessProductMigrationItemJob — orchestration changes

After existing base price sync block, add:

```php
// Advanced price sync (non-fatal)
$advancedPrices = data_get($parent, 'prices', []);
if (is_array($advancedPrices) && count($advancedPrices) > 0) {
    $advMapper = app(AdvancedPriceMapper::class);
    $grouped = $advMapper->map(
        $advancedPrices,
        $variantIdByShopwareId,
        $allVariantGids,
        $priceData['currency'],
        $shop->price_mode ?? 'gross'
    );
    if (count($grouped) > 0) {
        $advResults = $priceListSync->syncAdvancedPrices($shop, $priceData['currency'], $grouped);
        foreach ($advResults as $ruleId => $result) {
            if (!empty($result['errors']) || !empty($result['userErrors'])) {
                Log::warning('Advanced price list sync failed', [
                    'run_id'    => $run->id,
                    'source_id' => $sourceId,
                    'rule_id'   => $ruleId,
                    'result'    => $result,
                ]);
                $ctx = is_array($item->error_context) ? $item->error_context : [];
                $ctx['advanced_price_list_sync'][$ruleId] = $result;
                $item->error_context = $ctx;
                $item->save();
            }
        }
    }
}
```

Also pass `$shop->price_mode ?? 'gross'` to `mapParentWithVariants()` and `extractVariantPricesForPriceList()`.

---

### 8. Database Migration

```php
// 2026_05_27_000002_add_price_mode_to_shops_table.php
Schema::table('shops', function (Blueprint $table) {
    $table->string('price_mode')->default('gross')->after('uninstalled_at');
});
```

Shop model `$fillable` gains `'price_mode'`.

---

### 9. Fingerprint Coverage

`ProductFingerprint::make()` already hashes the full `$payload` array from `mapParentWithVariants()`. Since `prices[]` content is now included in metafields (via `advanced_prices_json`) and affects variant `taxable` and `inventoryItem.cost`, any change to `prices[]` or `tax` will change the fingerprint automatically — no separate change needed.

---

## Data Flow Diagram

```
ShopwareClient.fetchProductWithChildren()
    └─ product.price[], product.prices[].rule, product.tax, product.purchasePrice

ProductPayloadMapper.mapParentWithVariants($parent, $children, $locationGid, $shopId, $priceMode)
    ├─ TaxMapper.isTaxable()          → variant.taxable
    ├─ moneyToPrice($priceMode)       → variant.price
    ├─ moneyToCompareAtPrice($priceMode) → variant.compareAtPrice
    └─ resolvePurchaseCost()          → variant.inventoryItem.cost

ProductPayloadMapper.mapShopwareMetafields($parent, $children, $shop)
    ├─ TaxMapper.taxRate/taxName()    → tax_rate, tax_name metafields
    ├─ prices[] summary               → advanced_price_count, advanced_prices_json metafields
    └─ priceMode                      → price_mode metafield

ProcessProductMigrationItemJob
    ├─ sync.upsertByCustomId()        → productGid, variantIdByShopwareId, allVariantGids
    ├─ priceListSync.syncVariantPrices()   → base GBP prices on primary price list
    ├─ priceListSync.syncAdvancedPrices()  → rule-based prices on per-rule price lists
    └─ sync.setProductMetafields()    → tax + advanced price metafields
```

---

## Error Handling Strategy

| Failure | Behaviour |
|---------|-----------|
| Tax data missing | Default `taxable=true`, no tax metafields written |
| `prices[]` association not available from Shopware | Log warning, skip advanced price sync, product still migrates |
| Advanced price list create fails | Log warning, record in `error_context.advanced_price_list_sync`, continue |
| `priceListFixedPricesAdd` fails | Log warning, record in `error_context`, continue |
| Purchase cost invalid | Omit `inventoryItem.cost`, no error |
| Price mode not set on shop | Default to `'gross'` |

---

## Correctness Properties

### Property 1: Idempotency
`priceListFixedPricesAdd` replaces existing entries by variant GID — re-running a migration is safe and produces the same result.

### Property 2: Fingerprint Coverage
`prices[]` content flows into `advanced_prices_json` metafield and `taxable` variant field, both included in the payload fingerprint hash. Any change to advanced prices or tax triggers re-migration automatically.

### Property 3: Non-Fatal Advanced Prices
Advanced price sync failures never fail the product migration — errors are only recorded in `error_context.advanced_price_list_sync`.

### Property 4: Price Mode Default
`price_mode` defaults to `'gross'` everywhere — existing behaviour is fully preserved for shops that have not set a price mode.

---

## Error Handling

| Failure | Behaviour |
|---------|-----------|
| Tax data missing | Default `taxable=true`, no tax metafields written |
| `prices[]` association not available from Shopware | Log warning, skip advanced price sync, product still migrates |
| Advanced price list create fails | Log warning, record in `error_context.advanced_price_list_sync`, continue |
| `priceListFixedPricesAdd` fails | Log warning, record in `error_context`, continue |
| Purchase cost invalid | Omit `inventoryItem.cost`, no error |
| Price mode not set on shop | Default to `'gross'` |

- Unit test `TaxMapper::isTaxable()` with taxRate > 0, = 0, and null.
- Unit test `AdvancedPriceMapper::map()` with quantity breaks, rule names, deduplication, and empty input.
- Unit test `ProductPayloadMapper::variantFromShopware()` with `price_mode = 'net'` to verify net price is used.
- Unit test `ProductPayloadMapper::resolvePurchaseCost()` with valid, zero, and missing purchase prices.
- Integration test: run a product migration with `prices[]` data and verify price list entries are created in Shopify.

---

## Backward Compatibility

- All new parameters to `mapParentWithVariants()` are optional with defaults.
- Existing `ProcessProductMigrationItemJob` calls without `price_mode` continue to work (defaults to `'gross'`).
- New metafield definitions are additive — existing products are unaffected until re-migrated.
- The `price_mode` DB column has a default of `'gross'` so existing shops are unaffected.
```

---

## Correctness Properties

### Property 1: Idempotency
`priceListFixedPricesAdd` replaces existing entries by variant GID — re-running a migration is safe and produces the same result.

### Property 2: Fingerprint Coverage
`prices[]` content flows into `advanced_prices_json` metafield and `taxable` variant field, both included in the payload fingerprint hash. Any change to advanced prices or tax triggers re-migration automatically.

### Property 3: Non-Fatal Advanced Prices
Advanced price sync failures never fail the product migration — errors are only recorded in `error_context.advanced_price_list_sync`.

### Property 4: Price Mode Default
`price_mode` defaults to `'gross'` everywhere — existing behaviour is fully preserved for shops that have not set a price mode.

---

## Error Handling

| Failure | Behaviour |
|---------|-----------|
| Tax data missing | Default `taxable=true`, no tax metafields written |
| `prices[]` association not available from Shopware | Log warning, skip advanced price sync, product still migrates |
| Advanced price list create fails | Log warning, record in `error_context.advanced_price_list_sync`, continue |
| `priceListFixedPricesAdd` fails | Log warning, record in `error_context`, continue |
| Purchase cost invalid | Omit `inventoryItem.cost`, no error |
| Price mode not set on shop | Default to `'gross'` |

---

## Testing Strategy

- Unit test `TaxMapper::isTaxable()` with taxRate > 0, = 0, and null.
- Unit test `AdvancedPriceMapper::map()` with quantity breaks, rule names, deduplication, and empty input.
- Unit test `ProductPayloadMapper::variantFromShopware()` with `price_mode = 'net'` to verify net price is used.
- Unit test `ProductPayloadMapper::resolvePurchaseCost()` with valid, zero, and missing purchase prices.
- Integration test: run a product migration with `prices[]` data and verify price list entries are created in Shopify.

---

## Backward Compatibility

- All new parameters to `mapParentWithVariants()` are optional with defaults.
- Existing `ProcessProductMigrationItemJob` calls without `price_mode` continue to work (defaults to `'gross'`).
- New metafield definitions are additive — existing products are unaffected until re-migrated.
- The `price_mode` DB column has a default of `'gross'` so existing shops are unaffected.
