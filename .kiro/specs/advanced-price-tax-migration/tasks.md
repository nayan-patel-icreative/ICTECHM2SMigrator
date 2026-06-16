# Implementation Plan: Advanced Price & Tax Migration

## Overview

Implements tax flag migration, gross/net price mode, compare-at price, purchase cost, and Shopware advanced (rule-based) price migration into Shopify. Follows existing patterns: Cache::lock, Log::warning, error_context, metafield definitions.

## Tasks

- [ ] 1. Database migration — add `price_mode` to shops table
  - Create `database/migrations/2026_05_27_000002_add_price_mode_to_shops_table.php`
  - Add `price_mode` string column with default `'gross'` after `uninstalled_at`
  - Add `price_mode` to `Shop::$fillable`
  - Run `php artisan migrate`
  - **Requirements:** 2.1

- [ ] 2. Create `TaxMapper` service class
  - Create `app/Services/Migration/TaxMapper.php`
  - Implement `isTaxable(array $variant, array $fallbackParent): bool`
    - Read `tax.taxRate` from variant first, then fallbackParent
    - `taxRate > 0` → `true`; `taxRate === 0` → `false`; null/missing → `true`
  - Implement `taxRate(array $product): ?float`
  - Implement `taxName(array $product): string`
  - **Requirements:** 1.1, 1.2, 1.3, 1.4, 1.5, 1.7

- [ ] 3. Create `AdvancedPriceMapper` service class
  - Create `app/Services/Migration/AdvancedPriceMapper.php`
  - Implement `map(array $prices, array $variantIdByShopwareId, array $allVariantGids, string $currencyCode, string $priceMode = 'gross'): array`
  - Skip entries where gross/net is not numeric or ≤ 0
  - Resolve variant GID from `variantIdByShopwareId[$entry['productId']]` or fall back to `$allVariantGids`
  - Use `gross` or `net` field based on `$priceMode`
  - Set `compareAt` when `listPrice.gross` (or `listPrice.net`) > selling price
  - Set `quantityMin = max(1, (int)($entry['quantityStart'] ?? 1))`
  - Group by `ruleId`; include `ruleName` from `rule.name`
  - Deduplicate by `[ruleId, variantGid, quantityMin]` — keep lower price
  - Return empty array when no valid entries
  - **Requirements:** 6.1–6.8

- [ ] 4. Update `ShopwareClient` — add `rule` association to `prices[]`
  - In `defaultProductAssociations()`: change `'prices' => []` to `'prices' => ['associations' => ['rule' => []]]`
  - Apply same change to children's associations block
  - In `reducedProductAssociations()`: same change for `prices`
  - **Requirements:** 5.1, 5.2, 5.5

- [ ] 5. Update `ProductPayloadMapper` — tax, price mode, purchase cost
  - Add `use App\Services\Migration\TaxMapper;` import
  - Update `variantFromShopware()` to accept `string $priceMode = 'gross'`
  - Replace hardcoded `'taxable' => true` with `app(TaxMapper::class)->isTaxable($variant, $fallbackParent)`
  - Add `resolvePurchaseCost(array $variant, array $fallbackParent): ?string` private method
  - Add `'inventoryItem' => ['cost' => $purchaseCost]` to variant payload when cost is not null
  - Update `moneyToPrice()` to accept `string $priceMode = 'gross'` and read `price.0.net` when net mode
  - Update `moneyToCompareAtPrice()` to accept `string $priceMode = 'gross'` and read `price.0.listPrice.net` when net mode
  - Enforce: if `compareAtPrice <= price`, omit `compareAtPrice`
  - Propagate `$priceMode` through `mapParentWithVariants()` → `buildVariants()` → `variantFromShopware()`
  - **Requirements:** 1.6, 1.7, 2.2–2.7, 3.1–3.3, 4.1–4.5

- [ ] 6. Update `ProductPayloadMapper::mapShopwareMetafields()` — tax + advanced price metafields
  - Add `TaxMapper` usage: push `tax_rate` and `tax_name` metafields when present
  - Push `advanced_price_count` metafield (count of `prices[]` entries)
  - When `prices[]` non-empty, push `advanced_prices_json` metafield (JSON summary, max 5000 chars)
  - Push `price_mode` metafield with the active price mode value
  - Accept `?string $priceMode = null` parameter (default `'gross'`)
  - **Requirements:** 8.1–8.5

- [ ] 7. Update `ShopifyProductSyncService` — add new metafield definitions
  - Add 5 new entries to `$definitions` array in `ensureCommonProductMetafieldDefinitions()`:
    - `tax_rate`, `tax_name`, `advanced_price_count`, `advanced_prices_json`, `price_mode`
  - Clear `shopify:product_common_metafields_ensured:{shopId}` cache after deploy
  - **Requirements:** 8.1–8.5

- [ ] 8. Update `ShopifyPriceListSyncService` — add `syncAdvancedPrices()`
  - Refactor `resolvePriceListForCurrency()` into general `resolvePriceList(Shop, string, string, string): ?string`
  - Implement `syncAdvancedPrices(Shop $shop, string $currencyCode, array $groupedEntries): array`
    - Iterate over `$groupedEntries` keyed by ruleId
    - Resolve price list per ruleId with cache key `shopify:adv_price_list_gid:{shopId}:{ruleId}:{currency}`
    - Price list name: `"Shopware Advanced Prices – {ruleId}"`
    - Call `priceListFixedPricesAdd` mutation for each ruleId's entries
    - Return results keyed by ruleId (non-throwing)
  - **Requirements:** 7.2, 7.3, 7.6

- [ ] 9. Update `ProcessProductMigrationItemJob` — wire advanced price sync
  - Pass `$shop->price_mode ?? 'gross'` to `mapParentWithVariants()`
  - Pass `$shop->price_mode ?? 'gross'` to `extractVariantPricesForPriceList()`
  - After base price sync block, add advanced price sync block
  - Log warnings and record errors in `error_context.advanced_price_list_sync` per ruleId
  - Never fail the overall product migration
  - Pass `$priceMode` to `mapShopwareMetafields()`
  - **Requirements:** 7.1, 7.4, 7.5, 7.7, 9.1, 9.2

- [ ] 10. Verify fingerprint coverage and run diagnostics
  - Confirm `prices[]` content flows into fingerprint via `advanced_prices_json` metafield
  - Confirm `tax.taxRate` flows into `taxable` variant field → changes fingerprint
  - Run `getDiagnostics` on all modified files and fix any issues
  - **Requirements:** 9.3, 9.4

## Task Dependency Graph

```json
{
  "waves": [
    { "wave": 1, "tasks": [1, 2, 3, 4] },
    { "wave": 2, "tasks": [5, 6, 7, 8] },
    { "wave": 3, "tasks": [9] },
    { "wave": 4, "tasks": [10] }
  ]
}
```

## Notes

- Tasks 1–4 are independent and can be done in parallel.
- Tasks 5 and 6 both modify `ProductPayloadMapper` — do them sequentially.
- Task 8 modifies `ShopifyPriceListSyncService` — refactor `resolvePriceListForCurrency` carefully to not break existing `syncVariantPrices()`.
- After task 7, clear the metafield definitions cache before running a migration: `Cache::forget('shopify:product_common_metafields_ensured:{shopId}')`.
- All advanced price sync failures are non-fatal — the product migration must always complete.
