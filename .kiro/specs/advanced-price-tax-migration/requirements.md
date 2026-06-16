# Requirements Document

## Introduction

This feature extends the ICTECHS2SMigrator (Shopware-to-Shopify migration app) to provide comprehensive Advanced Price and Tax migration. The existing migration handles base product prices via `ProductPayloadMapper::moneyToPrice()` and `ShopifyPriceListSyncService::syncVariantPrices()`, but does not yet cover tax metadata, advanced/rule-based conditional pricing, quantity-break pricing, customer-group prices, purchase cost, or the net-vs-gross price handling decision. This feature closes those gaps so that all commercially relevant pricing data from Shopware is faithfully represented in Shopify after migration.

## Glossary

- **Migrator**: The ICTECHS2SMigrator Laravel application that orchestrates Shopware-to-Shopify data migration.
- **ProductPayloadMapper**: The PHP class (`App\Services\Migration\ProductPayloadMapper`) responsible for transforming Shopware product data into Shopify `productSet` API payloads.
- **PriceListSyncService**: The PHP class (`App\Services\Migration\ShopifyPriceListSyncService`) responsible for writing fixed prices to Shopify price lists.
- **AdvancedPriceMapper**: The new PHP class to be created that maps Shopware `product.prices[]` (rule-based/advanced prices) into Shopify price list entries.
- **TaxMapper**: The new PHP class to be created that maps Shopware tax data to Shopify taxable flag and tax code metafields.
- **Shopware_Product**: A product record fetched from the Shopware Admin API, including its `price[]`, `prices[]`, and `tax` associations.
- **Shopify_Variant**: A product variant record in Shopify, identified by a GID, carrying `price`, `compareAtPrice`, `taxable`, and cost fields.
- **Base_Price**: The standard selling price of a product — `product.price[0].gross` (gross) or `product.price[0].net` (net) in Shopware.
- **Advanced_Price**: A conditional, rule-based price in Shopware stored in `product.prices[]`, associated with a `ruleId` and optional quantity range.
- **List_Price**: The original/compare-at price in Shopware — `product.price[0].listPrice.gross`.
- **Purchase_Price**: The cost of goods in Shopware — `product.purchasePrice` or `product.price[0].net` used as cost basis.
- **Tax_Rate**: The VAT/tax percentage associated with a Shopware product via `product.tax.taxRate`.
- **Tax_Name**: The human-readable tax category name in Shopware — `product.tax.name` (e.g. "Standard rate").
- **Gross_Price**: A price that includes tax (VAT-inclusive).
- **Net_Price**: A price that excludes tax (VAT-exclusive).
- **Price_List**: A Shopify construct that stores fixed or adjusted prices for specific markets or customer segments, identified by currency.
- **Tax_Code**: A string metafield stored on a Shopify product variant that records the originating Shopware tax category name for reference.
- **Quantity_Break**: A price tier in Shopware `product.prices[]` where a different price applies when the ordered quantity falls within `quantityStart`–`quantityEnd`.
- **Customer_Group_Price**: A price in Shopware `product.prices[]` associated with a specific `ruleId` representing a customer group or pricing rule.
- **Migration_Run**: A single execution of the product migration pipeline, tracked by a `MigrationRun` model.
- **Shop**: The Shopify store model (`App\Models\Shop`) that holds connection credentials and configuration.
- **ShopwareConnection**: The Shopware API connection model (`App\Models\ShopwareConnection`) holding API URL and credentials.
- **Price_Mode**: A per-shop configuration setting that controls whether the Migrator uses gross or net prices as the Shopify selling price. Values: `gross` (default) or `net`.

---

## Requirements

### Requirement 1: Tax Flag and Tax Code Migration

**User Story:** As a merchant migrating from Shopware to Shopify, I want each product variant's taxable status and tax category to be correctly set in Shopify, so that Shopify's tax engine applies the right tax treatment at checkout.

#### Acceptance Criteria

1. WHEN a Shopware product has a `tax` association with a non-null `taxRate` greater than 0, THE TaxMapper SHALL set the Shopify variant's `taxable` field to `true`.
2. WHEN a Shopware product has a `tax` association with a `taxRate` equal to 0, THE TaxMapper SHALL set the Shopify variant's `taxable` field to `false`.
3. WHEN a Shopware product has no `tax` association or the `tax` field is null, THE TaxMapper SHALL set the Shopify variant's `taxable` field to `true` as the safe default.
4. WHEN a Shopware product has a `tax.name` value, THE TaxMapper SHALL store that value as a Shopify product metafield with namespace `shopware`, key `tax_name`, and type `single_line_text_field`.
5. WHEN a Shopware product has a `tax.taxRate` value, THE TaxMapper SHALL store that value as a Shopify product metafield with namespace `shopware`, key `tax_rate`, and type `single_line_text_field`.
6. THE ProductPayloadMapper SHALL apply TaxMapper logic to every variant produced by `variantFromShopware()`, replacing the current hardcoded `'taxable' => true` assignment.
7. WHEN a child variant does not have its own `tax` association, THE TaxMapper SHALL fall back to the parent product's `tax` association to determine taxable status.

---

### Requirement 2: Price Mode Configuration (Gross vs Net)

**User Story:** As a merchant, I want to configure whether the Migrator uses gross (tax-inclusive) or net (tax-exclusive) prices as the Shopify selling price, so that the migrated prices match my Shopify store's tax display settings.

#### Acceptance Criteria

1. THE Shop model SHALL support a `price_mode` configuration field with allowed values `gross` and `net`, defaulting to `gross` when not set.
2. WHEN `price_mode` is `gross`, THE ProductPayloadMapper SHALL use `product.price[0].gross` as the Shopify variant selling price.
3. WHEN `price_mode` is `net`, THE ProductPayloadMapper SHALL use `product.price[0].net` as the Shopify variant selling price.
4. WHEN `price_mode` is `gross` and a list price exists, THE ProductPayloadMapper SHALL use `product.price[0].listPrice.gross` as the Shopify variant `compareAtPrice`.
5. WHEN `price_mode` is `net` and a list price exists, THE ProductPayloadMapper SHALL use `product.price[0].listPrice.net` as the Shopify variant `compareAtPrice`.
6. WHEN the resolved selling price is less than or equal to 0, THE ProductPayloadMapper SHALL use `0.00` as the price and SHALL NOT set a `compareAtPrice`.
7. WHEN the resolved `compareAtPrice` is less than or equal to the resolved selling price, THE ProductPayloadMapper SHALL omit the `compareAtPrice` field from the variant payload.

---

### Requirement 3: Compare-At Price (List Price) Migration

**User Story:** As a merchant, I want the Shopware list price (original/RRP price) to be migrated as the Shopify compare-at price, so that customers can see the original price alongside the discounted selling price.

#### Acceptance Criteria

1. WHEN a Shopware product has `price[0].listPrice.gross` set to a value greater than `price[0].gross`, THE ProductPayloadMapper SHALL set the Shopify variant `compareAtPrice` to the list price value (respecting the active `price_mode`).
2. WHEN a Shopware product has `price[0].listPrice` that is null or missing, THE ProductPayloadMapper SHALL omit the `compareAtPrice` field from the variant payload.
3. WHEN a Shopware child variant has its own `price[0].listPrice`, THE ProductPayloadMapper SHALL use the child's list price in preference to the parent's list price.
4. THE PriceListSyncService SHALL include the `compareAtPrice` in the price list fixed price entry when a valid compare-at price is present, using the same currency as the selling price.

---

### Requirement 4: Purchase Price (Cost) Migration

**User Story:** As a merchant, I want the Shopware product's purchase/cost price to be migrated to Shopify as the variant cost, so that Shopify's profit margin reporting is accurate after migration.

#### Acceptance Criteria

1. WHEN a Shopware product has a `purchasePrice` field with a numeric value greater than 0, THE ProductPayloadMapper SHALL include the `inventoryItem.cost` field in the variant payload set to that value formatted to 2 decimal places.
2. WHEN a Shopware product does not have a `purchasePrice` field, THE ProductPayloadMapper SHALL NOT include the `inventoryItem.cost` field in the variant payload.
3. WHEN a Shopware child variant has its own `purchasePrice`, THE ProductPayloadMapper SHALL use the child's `purchasePrice` in preference to the parent's `purchasePrice`.
4. WHEN the final calculated cost value (after any formatting or processing) is 0 or negative, THE ProductPayloadMapper SHALL NOT include the `inventoryItem.cost` field in the variant payload.
5. THE ProductPayloadMapper SHALL format the cost value as a string with exactly 2 decimal places (e.g. `"15.00"`).

---

### Requirement 5: Advanced Price Data Fetching

**User Story:** As a developer, I want the Shopware API client to reliably fetch `product.prices[]` (advanced/rule-based prices) with all required associations, so that the migration pipeline has the data it needs to map advanced prices.

#### Acceptance Criteria

1. THE ShopwareClient SHALL include the `prices` association in the default product associations payload sent to the Shopware search API.
2. WHEN fetching a product with children, THE ShopwareClient SHALL include the `prices` association in the children's associations payload.
3. WHEN the Shopware API returns a `FRAMEWORK__ASSOCIATION_NOT_FOUND` error for the `prices` association, THE ShopwareClient SHALL retry the request using the reduced associations set that omits `prices`, and SHALL log a warning.
4. WHEN `product.prices[]` is a non-empty array, THE ShopwareClient SHALL return it as part of the product data structure accessible via `data_get($product, 'prices')`.
5. THE ShopwareClient SHALL fetch the `rule` association nested within each `prices[]` entry so that `prices[].rule.name` is available for customer group identification.

---

### Requirement 6: Advanced Price Mapping to Shopify Price Lists

**User Story:** As a merchant, I want Shopware's rule-based and quantity-break prices to be migrated to Shopify price lists, so that customer-group-specific and volume pricing is preserved after migration.

#### Acceptance Criteria

1. THE AdvancedPriceMapper SHALL accept a Shopware product's `prices[]` array and return a structured collection of price list entries, each containing: variant GID, price amount, currency code, optional compare-at price, quantity minimum, and rule name.
2. WHEN a Shopware `prices[]` entry has a `quantityStart` value greater than 1, THE AdvancedPriceMapper SHALL record that entry as a quantity-break price with the `quantityStart` value as the minimum quantity.
3. WHEN a Shopware `prices[]` entry has a `ruleId` and the `rule.name` is available, THE AdvancedPriceMapper SHALL include the rule name in the price list entry metadata for traceability.
4. WHEN `price_mode` is `gross`, THE AdvancedPriceMapper SHALL use the `gross` field from each `prices[]` entry as the price amount.
5. WHEN `price_mode` is `net`, THE AdvancedPriceMapper SHALL use the `net` field from each `prices[]` entry as the price amount.
6. WHEN a `prices[]` entry has a `listPrice.gross` (or `listPrice.net` for net mode) greater than the selling price, THE AdvancedPriceMapper SHALL include it as the compare-at price for that entry.
7. WHEN `product.prices[]` is empty, null, or all entries are invalid or filtered out during processing, THE AdvancedPriceMapper SHALL return an empty collection and SHALL NOT attempt any price list writes.
8. THE AdvancedPriceMapper SHALL deduplicate entries with identical ruleId and quantityStart combinations, keeping the entry with the lower price.

---

### Requirement 7: Advanced Price Sync to Shopify

**User Story:** As a merchant, I want the mapped advanced prices to be written to Shopify price lists during product migration, so that the pricing rules are active in Shopify after migration.

#### Acceptance Criteria

1. WHEN the AdvancedPriceMapper returns a non-empty collection for a product, THE ProcessProductMigrationItemJob SHALL call the PriceListSyncService to write those entries to the appropriate Shopify price list after the base product upsert succeeds.
2. THE PriceListSyncService SHALL create a dedicated Shopify price list named `"Shopware Advanced Prices – {ruleId}"` for each distinct Shopware rule ID encountered, if one does not already exist.
3. WHEN writing advanced price entries to a Shopify price list, THE PriceListSyncService SHALL use the `priceListFixedPricesAdd` GraphQL mutation with the correct currency code.
4. WHEN the Shopify price list write fails with a rate-limit error, THE ProcessProductMigrationItemJob SHALL log a warning and continue without failing the overall product migration.
5. WHEN the Shopify price list write fails with a non-rate-limit error, THE ProcessProductMigrationItemJob SHALL record the error in `migration_items.error_context` under the key `advanced_price_list_sync` and continue without failing the overall product migration.
6. THE PriceListSyncService SHALL cache the resolved price list GID per shop and rule ID for 24 hours to avoid redundant API calls across products in the same migration run.
7. WHEN a product has both base prices and advanced prices, THE ProcessProductMigrationItemJob SHALL sync base prices first, then advanced prices, in that order.

---

### Requirement 8: Tax and Price Metafield Storage

**User Story:** As a merchant, I want Shopware tax and pricing metadata to be stored as Shopify metafields on the product, so that the original Shopware pricing context is preserved and accessible for reporting or future re-migration.

#### Acceptance Criteria

1. THE ProductPayloadMapper SHALL store `product.tax.taxRate` as a metafield with namespace `shopware`, key `tax_rate`, and type `single_line_text_field` on the Shopify product.
2. THE ProductPayloadMapper SHALL store `product.tax.name` as a metafield with namespace `shopware`, key `tax_name`, and type `single_line_text_field` on the Shopify product.
3. THE ProductPayloadMapper SHALL store the count of `product.prices[]` entries as a metafield with namespace `shopware`, key `advanced_price_count`, and type `single_line_text_field` on the Shopify product.
4. WHEN `product.prices[]` contains at least one valid entry after processing, THE ProductPayloadMapper SHALL store a JSON summary of the advanced prices (ruleId, quantityStart, quantityEnd, gross, net) as a metafield with namespace `shopware`, key `advanced_prices_json`, and type `json`, truncated to 5000 characters if necessary.
5. THE ProductPayloadMapper SHALL store the resolved `price_mode` used during migration as a metafield with namespace `shopware`, key `price_mode`, and type `single_line_text_field` on the Shopify product.

---

### Requirement 9: Idempotency and Re-migration Safety

**User Story:** As a developer, I want the advanced price and tax migration to be idempotent, so that re-running a migration does not create duplicate price list entries or corrupt existing prices.

#### Acceptance Criteria

1. WHEN a product migration item is re-run and the product fingerprint has not changed, THE ProcessProductMigrationItemJob SHALL skip both base price sync and advanced price sync, consistent with existing fingerprint-skip behaviour.
2. WHEN a product migration item is re-run and the product fingerprint has changed, THE PriceListSyncService SHALL overwrite existing fixed price entries for the affected variants using `priceListFixedPricesAdd`, which is idempotent by variant GID.
3. THE AdvancedPriceMapper SHALL include the `prices[]` array content in the product fingerprint computation so that changes to advanced prices trigger a re-migration.
4. WHEN a Shopify price list already contains a fixed price for a given variant GID, THE PriceListSyncService SHALL replace it with the new value rather than creating a duplicate entry.

---

### Requirement 10: Migration Run Reporting

**User Story:** As a merchant, I want the migration run report to include information about advanced price and tax migration outcomes, so that I can verify the migration was complete and identify any issues.

#### Acceptance Criteria

1. THE MigrationRunReportWriter SHALL include an `advanced_prices_synced` column in the product migration CSV report, containing the count of advanced price entries successfully written to Shopify for each product.
2. WHEN advanced price sync fails for a product, THE MigrationRunReportWriter SHALL record `advanced_price_sync_failed` in the report's `reason` column for that product row, in addition to any existing failure reason.
3. THE MigrationRunReportWriter SHALL include a `tax_name` column in the product migration CSV report, containing the Shopware tax name for each migrated product.
4. WHEN a product has no advanced prices, THE MigrationRunReportWriter SHALL record `0` in the `advanced_prices_synced` column. WHEN a product has advanced prices but only some sync successfully, THE MigrationRunReportWriter SHALL record the actual count of successfully synced entries.
