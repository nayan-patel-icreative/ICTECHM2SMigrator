# Requirements Document

## Introduction

The Discount Migration feature extends the ICTECHS2SMigrator Shopify app to migrate **Shopware 6 Promotions** into **Shopify Discounts**. The app already migrates Products, Customers, Orders, Newsletters, and Manufacturers using a queue-based job pipeline (Laravel queues, `MigrationRun` / `MigrationItem` tracking, fingerprint-based skip logic, and CSV/PDF reports). This feature adds a new migration type — `discounts` — that follows the same architectural patterns.

Shopware promotions are richer than Shopify discounts in several ways (sales channel scoping, combination rules, "fixed unit price" and "free item" types). The migration must faithfully map what can be mapped, store unmappable data as Shopify metafields for reference, and clearly communicate to the user what was lost in translation.

---

## Glossary

- **Shopware_Promotion**: A Shopware 6 entity (endpoint: `GET /api/promotion`) representing a discount rule with conditions, discount tabs, and optional promotion codes.
- **Shopify_Discount**: A Shopify discount created via the Admin GraphQL API. Can be automatic (`discountAutomaticBasicCreate`, `discountAutomaticFreeShippingCreate`, `discountAutomaticBxgyCreate`) or code-based (`discountCodeBasicCreate`, `discountCodeFreeShippingCreate`, `discountCodeBxgyCreate`).
- **Discount_Mapper**: The new service class responsible for transforming a Shopware_Promotion into a Shopify_Discount payload.
- **Promotion_Code**: A string code attached to a Shopware_Promotion that customers enter at checkout. Maps to a Shopify discount code.
- **Migration_Run**: A `migration_runs` database record tracking the overall state of a single discount migration execution (status: queued → running → finished/cancelled/failed).
- **Migration_Item**: A `migration_items` database record tracking the per-promotion migration state (status: queued → running → succeeded/skipped/failed).
- **Fingerprint**: A hash of the Shopware_Promotion payload used to detect changes and skip re-migration of unchanged promotions.
- **Shopify_Discount_GID**: The Shopify Global ID of a created discount (e.g., `gid://shopify/DiscountAutomaticNode/123`).
- **ShopifyIdMapping**: The existing `shopify_id_mappings` table that stores the mapping between a Shopware source ID and a Shopify GID, keyed by `entity_type = 'discount'`.
- **Unmappable_Promotion**: A Shopware_Promotion whose discount type (`fixed_unit_price` or `free_item` without product context) cannot be represented in Shopify. These are skipped with a recorded reason.
- **Combination_Flag**: The Shopware `preventCombination` boolean on a promotion. Shopify has no equivalent; stored as a metafield.
- **Sales_Channel_Scope**: The Shopware promotion's `salesChannels` association. Shopify discounts are not scoped to sales channels; stored as a metafield.
- **ShopwareClient**: The existing `App\Services\Shopware\ShopwareClient` service used to call the Shopware Admin API.
- **ShopifyAdminGraphqlClient**: The existing `App\Services\Shopify\ShopifyAdminGraphqlClient` service used to call the Shopify Admin GraphQL API.
- **Queue_Worker**: The Laravel queue worker process that processes queued jobs.

---

## Requirements

### Requirement 1: Fetch Shopware Promotions

**User Story:** As a merchant, I want the migrator to fetch all promotions from my Shopware 6 store, so that every promotion is available for migration to Shopify.

#### Acceptance Criteria

1. WHEN the discount migration is started, THE ShopwareClient SHALL fetch Shopware_Promotions from the `/api/promotion` endpoint using paginated requests of 100 items per page.
2. WHEN fetching Shopware_Promotions, THE ShopwareClient SHALL include the following associations in each request: `codes`, `discounts`, `salesChannels`, `orderRules`, `personaRules`, `cartRules`.
3. WHEN a paginated fetch returns fewer items than the page size, THE Migration_Run SHALL treat that page as the final page and dispatch the finalize job.
4. WHEN the Shopware API returns an error during fetch, THE Migration_Run SHALL be marked as `failed` and the error SHALL be logged with `Log::error`.
5. FOR ALL pages fetched, THE ShopwareClient SHALL return a `total` count consistent with the sum of items across all pages (pagination invariant).

---

### Requirement 2: Determine Shopify Discount Type

**User Story:** As a merchant, I want each Shopware promotion to be mapped to the most appropriate Shopify discount type, so that the discount behaviour is preserved as closely as possible.

#### Acceptance Criteria

1. WHEN a Shopware_Promotion has no `codes` entries, THE Discount_Mapper SHALL produce an automatic discount payload (using `discountAutomaticBasicCreate`, `discountAutomaticFreeShippingCreate`, or `discountAutomaticBxgyCreate` as appropriate).
2. WHEN a Shopware_Promotion has one or more `codes` entries, THE Discount_Mapper SHALL produce a code-based discount payload (using `discountCodeBasicCreate`, `discountCodeFreeShippingCreate`, or `discountCodeBxgyCreate` as appropriate).
3. WHEN a Shopware_Promotion discount type is `percentage`, THE Discount_Mapper SHALL map it to a Shopify percentage discount with the `percentageValue` field set to the Shopware `value` field.
4. WHEN a Shopware_Promotion discount type is `absolute` (fixed amount), THE Discount_Mapper SHALL map it to a Shopify fixed-amount discount with the `discountAmount` field set to the Shopware `value` field.
5. WHEN a Shopware_Promotion discount type is `free_shipping` or the `apply_to` scope is `Shipping`, THE Discount_Mapper SHALL map it to a Shopify free-shipping discount using `discountAutomaticFreeShippingCreate` or `discountCodeFreeShippingCreate`.
6. WHEN a Shopware_Promotion discount type is `fixed_unit_price` or `free_item` without resolvable product GIDs, THE Discount_Mapper SHALL mark the promotion as Unmappable_Promotion, record the reason in `error_context`, and set the Migration_Item status to `skipped`.
7. FOR ALL valid Shopware_Promotion inputs, THE Discount_Mapper SHALL produce a Shopify discount type that is one of: `basic`, `free_shipping`, or `bxgy` — never an undefined type.

---

### Requirement 3: Map Promotion Metadata

**User Story:** As a merchant, I want promotion names, validity dates, and active status to be preserved in Shopify, so that the discount is recognisable and correctly scheduled.

#### Acceptance Criteria

1. WHEN mapping a Shopware_Promotion, THE Discount_Mapper SHALL set the Shopify discount `title` to the Shopware promotion `name` field, truncated to 255 characters if necessary.
2. WHEN a Shopware_Promotion has a `validFrom` date, THE Discount_Mapper SHALL set the Shopify discount `startsAt` to the ISO 8601 representation of `validFrom`.
3. WHEN a Shopware_Promotion has a `validUntil` date, THE Discount_Mapper SHALL set the Shopify discount `endsAt` to the ISO 8601 representation of `validUntil`.
4. WHEN a Shopware_Promotion has no `validFrom` date, THE Discount_Mapper SHALL omit `startsAt` from the Shopify payload (the discount becomes active immediately upon creation).
5. WHEN a Shopware_Promotion `active` field is `false`, THE Discount_Mapper SHALL set the Shopify discount `startsAt` to a date 100 years in the future, effectively disabling the discount while preserving it in Shopify.
6. FOR ALL Shopware_Promotions with both `validFrom` and `validUntil` set, THE Discount_Mapper SHALL ensure `startsAt` is before or equal to `endsAt` in the produced payload.

---

### Requirement 4: Map Discount Value and Scope

**User Story:** As a merchant, I want the discount value and the scope of what it applies to (entire order, specific products, shipping) to be correctly mapped, so that the discount behaves as intended in Shopify.

#### Acceptance Criteria

1. WHEN a Shopware_Promotion discount `scope` is `cart`, THE Discount_Mapper SHALL set the Shopify discount `appliesOnAllProducts` to `true`.
2. WHEN a Shopware_Promotion discount `scope` is `delivery`, THE Discount_Mapper SHALL produce a free-shipping discount type regardless of the discount value type.
3. WHEN a Shopware_Promotion discount `scope` is `set` (specific product range), THE Discount_Mapper SHALL record the product range restriction in `error_context` as `product_scope_requires_manual_review: true`. The `appliesOnAllProducts` field MAY be set to `true` as a safe fallback, because Shopify product-specific discounts require Shopify product GIDs that may not yet be available and human oversight is indicated by the manual review flag.
4. WHEN a Shopware_Promotion has a `value` field that is a positive number, THE Discount_Mapper SHALL produce a Shopify discount value that is greater than zero.
5. WHEN a Shopware_Promotion percentage `value` exceeds 100, THE Discount_Mapper SHALL cap the Shopify `percentageValue` at 100 and log a warning with `Log::warning`.

---

### Requirement 5: Map Minimum Requirements

**User Story:** As a merchant, I want minimum cart value and minimum quantity conditions from Shopware promotions to be preserved in Shopify, so that the discount only applies when the customer meets the threshold.

#### Acceptance Criteria

1. WHEN a Shopware_Promotion has a cart rule with a minimum order value condition, THE Discount_Mapper SHALL set the Shopify discount `minimumRequirement` to `{ subtotal: { greaterThanOrEqualToSubtotal: "<amount>" } }`.
2. WHEN a Shopware_Promotion has a cart rule with a minimum quantity condition, THE Discount_Mapper SHALL set the Shopify discount `minimumRequirement` to `{ quantity: { greaterThanOrEqualToQuantity: <count> } }`, including when the count is zero (which Shopify treats as no minimum).
3. WHEN a Shopware_Promotion has no minimum order value or quantity condition, THE Discount_Mapper SHALL omit `minimumRequirement` from the Shopify payload (no minimum required).
4. WHEN both a minimum value and a minimum quantity condition are present on the same promotion, THE Discount_Mapper SHALL prefer the minimum value condition and record the quantity condition in `error_context` as `minimum_quantity_condition_dropped: true`.

---

### Requirement 6: Map Usage Limits

**User Story:** As a merchant, I want global usage limits and per-customer limits from Shopware promotions to be preserved in Shopify, so that the discount cannot be over-redeemed.

#### Acceptance Criteria

1. WHEN the mapping process is active and a Shopware_Promotion has a `maxRedemptionsGlobal` value greater than zero, THE Discount_Mapper SHALL set the Shopify discount `usageLimit` to that value.
2. WHEN a Shopware_Promotion has a `maxRedemptionsPerCustomer` value of 1, THE Discount_Mapper SHALL set the Shopify discount `appliesOncePerCustomer` to `true`.
3. WHEN the mapping process is active and a Shopware_Promotion has no `maxRedemptionsGlobal` set (null or zero), THE Discount_Mapper SHALL omit `usageLimit` from the Shopify payload (unlimited uses).
4. WHEN the mapping process is active and a Shopware_Promotion has a `maxRedemptionsPerCustomer` value greater than 1, THE Discount_Mapper SHALL set `appliesOncePerCustomer` to `false`, record the per-customer limit in `error_context` as `per_customer_limit_not_fully_mappable: <value>`, and store the original value as a metafield per Requirement 8.6.

---

### Requirement 7: Migrate Promotion Codes

**User Story:** As a merchant, I want all promotion codes from Shopware to be created as discount codes in Shopify, so that customers can continue using their existing codes after migration.

#### Acceptance Criteria

1. WHEN a Shopware_Promotion has one or more `codes` entries, THE Discount_Mapper SHALL produce a code-based Shopify discount and include all code values in the `codes` array of the creation payload.
2. WHEN a Shopware promotion code `code` string is empty or null, THE Discount_Mapper SHALL skip that code entry and log a warning with `Log::warning`.
3. WHEN a Shopify discount code creation returns any `userErrors` (including a code-already-exists error), THE Migration_Item SHALL be marked as `succeeded`, treating all user errors as idempotent outcomes.
4. WHEN a Shopware_Promotion has more than 1,000 codes, THE Discount_Mapper SHALL migrate the first 1,000 codes and record `codes_truncated: true` with the total count in `error_context`.
5. FOR ALL Shopware_Promotions with codes, THE number of Shopify discount codes created SHALL equal the number of valid (non-empty) Shopware codes, up to the 1,000-code limit.

---

### Requirement 8: Store Unmappable Data as Metafields

**User Story:** As a merchant, I want data that cannot be directly mapped to Shopify to be preserved as metafields on the Shopify discount, so that I can reference the original Shopware configuration after migration.

#### Acceptance Criteria

1. WHEN a Shopware_Promotion has a `preventCombination` flag set to `true`, THE Discount_Mapper SHALL store it as a Shopify discount metafield with namespace `shopware`, key `prevent_combination`, type `single_line_text_field`, value `"true"`.
2. WHEN a Shopware_Promotion has `salesChannels` associations, THE Discount_Mapper SHALL store the list of sales channel names as a Shopify discount metafield with namespace `shopware`, key `sales_channels`, type `json`.
3. THE Discount_Mapper SHALL always store the Shopware promotion `id` as a Shopify discount metafield with namespace `shopware`, key `promotion_id`, type `single_line_text_field`, to enable idempotent re-migration.
4. THE Discount_Mapper SHALL always store the Shopware promotion `priority` as a Shopify discount metafield with namespace `shopware`, key `priority`, type `single_line_text_field`.
5. WHEN a Shopware_Promotion discount `scope` is `set` (product-specific), THE Discount_Mapper SHALL store the product range restriction details as a Shopify discount metafield with namespace `shopware`, key `product_scope_json`, type `json`.
6. WHEN a Shopware_Promotion has a `maxRedemptionsPerCustomer` value greater than 1, THE Discount_Mapper SHALL attempt to store the original value as a Shopify discount metafield with namespace `shopware`, key `max_redemptions_per_customer`, type `single_line_text_field`. IF the metafield storage fails, THE Migration_Item SHALL continue and succeed without the metafield, accepting that this Shopware configuration detail will be lost.

---

### Requirement 9: Idempotent Discount Upsert

**User Story:** As a merchant, I want re-running the discount migration to be safe and not create duplicate discounts, so that I can re-migrate after fixing errors without polluting my Shopify store.

#### Acceptance Criteria

1. BEFORE creating a Shopify discount, THE Migration_Item job SHALL query the `shopify_id_mappings` table for an existing mapping with `entity_type = 'discount'` and `source_id = <shopware_promotion_id>`.
2. WHEN an existing Shopify_Discount_GID is found in `shopify_id_mappings`, THE Migration_Item job SHALL update the existing Shopify discount using the appropriate `discountAutomaticBasicUpdate` or `discountCodeBasicUpdate` mutation instead of creating a new one.
3. WHEN a Shopware_Promotion fingerprint matches the previously succeeded fingerprint, THE Migration_Item job SHALL set the Migration_Item status to `skipped` and increment only the `processed` counter.
4. WHEN a Shopify discount update returns a `userError` indicating the discount no longer exists in Shopify, THE Migration_Item job SHALL fall back to creating a new discount and update the `shopify_id_mappings` record.
5. FOR ALL re-runs of the discount migration on unchanged data, THE Migration_Run SHALL report zero `succeeded` items and all items as `skipped`.

---

### Requirement 10: Migration Run Lifecycle

**User Story:** As a merchant, I want the discount migration to follow the same run lifecycle as other migrations in the app, so that I can monitor progress, cancel if needed, and download a report.

#### Acceptance Criteria

1. WHEN a discount migration is started and no other discount migration is already `queued` or `running`, THE DiscountMigrationService SHALL create a new Migration_Run with `type = 'discounts'` and `status = 'queued'`, then dispatch `RunDiscountMigrationJob`.
2. WHEN a discount migration is started and a Migration_Run with `type = 'discounts'` is already `queued` or `running`, THE DiscountMigrationService SHALL return the existing Migration_Run without creating a duplicate.
3. WHEN all Migration_Items for a Migration_Run are in a terminal state (`succeeded`, `skipped`, or `failed`), THE FinalizeDiscountMigrationRunJob SHALL set the Migration_Run `status` to `finished` and `finished_at` to the current timestamp.
4. WHEN a merchant cancels a running discount migration, THE DiscountMigrationService SHALL set the Migration_Run `status` to `cancelled` and `finished_at` to the current timestamp.
5. WHEN a Migration_Run reaches `finished` or `cancelled` status, THE MigrationRunReportWriter SHALL finalize the CSV report for that run.
6. THE Migration_Run counters SHALL satisfy the invariant: `processed = succeeded + failed + skipped` at all times after the run completes.

---

### Requirement 11: Per-Item Error Handling and Partial Migration

**User Story:** As a merchant, I want a single promotion failure to not stop the entire migration, so that as many promotions as possible are migrated even when some have errors.

#### Acceptance Criteria

1. WHEN a Migration_Item job throws an unhandled exception, THE ProcessDiscountMigrationItemJob SHALL catch the exception, set the Migration_Item `status` to `failed`, record the exception message in `error_message`, and increment the `failed` counter on the Migration_Run.
2. WHEN a Shopify API call returns `userErrors`, THE ProcessDiscountMigrationItemJob SHALL set the Migration_Item `status` to `failed`, record the user errors in `error_context`, and continue processing the next item. The Migration_Run `failed` counter SHALL be incremented only at the item level.
3. WHEN a Shopify API call returns network-level `errors`, THE ProcessDiscountMigrationItemJob SHALL set the Migration_Item `status` to `failed`, record the errors in `error_context`, and continue processing the next item. The Migration_Run `failed` counter SHALL be incremented only at the item level.
4. WHEN a Migration_Item fails, THE MigrationRunReportWriter SHALL append a row to the CSV report with `status = 'failed'` and a human-readable failure reason.
5. IF the Queue_Worker is not running when a migration start is requested, THEN THE DiscountMigrationController SHALL return HTTP 409 with the message `"Queue worker is not running. Migration cannot start until the worker process is online."`.

---

### Requirement 12: Preview Promotions Before Migration

**User Story:** As a merchant, I want to preview how my Shopware promotions will be mapped before starting the migration, so that I can identify potential issues and understand what will be created in Shopify.

#### Acceptance Criteria

1. WHEN a preview request is made, THE DiscountMigrationController SHALL fetch up to 20 Shopware_Promotions (configurable via `limit` parameter, max 20) from the requested `page` and return a summary for each.
2. FOR EACH promotion in the preview, THE DiscountMigrationController SHALL return: `source_id`, `name`, `shopify_discount_type` (the resolved Shopify type), `is_automatic` (boolean), `code_count`, `value`, `value_type`, `valid_from`, `valid_until`, `is_active`, `issues` (array of human-readable mapping warnings), and `fingerprint`.
3. WHEN a promotion in the preview has an unmappable discount type, THE DiscountMigrationController SHALL include a human-readable issue string in the `issues` array (e.g., `"Discount type 'fixed_unit_price' has no Shopify equivalent and will be skipped"`).
4. WHEN a promotion in the preview has a product-scoped discount, THE DiscountMigrationController SHALL include a warning in the `issues` array: `"Product-scoped discount requires manual review after migration"`.
5. WHEN the `include_payload` parameter is `true`, THE DiscountMigrationController SHALL include the full mapped Shopify discount payload in the preview response for debugging.

---

### Requirement 13: Admin UI — Discount Migration Panel

**User Story:** As a merchant, I want a dedicated Discount Migration section in the admin panel, so that I can preview, start, monitor, and cancel the discount migration from the same interface as other migrations.

#### Acceptance Criteria

1. THE Admin_UI SHALL display a "Discounts" migration card in the migration page alongside the existing Products, Customers, Orders, Newsletter, and Manufacturers cards.
2. WHEN the discount migration status is `queued` or `running`, THE Admin_UI SHALL display a progress indicator showing `processed`, `succeeded`, `failed`, and elapsed duration, refreshed every 3 seconds. The progress indicator SHALL appear immediately when the status becomes `queued`, not only after processing begins.
3. WHEN the discount migration status is `finished`, THE Admin_UI SHALL display a summary banner showing total succeeded, failed, and skipped counts, and a "Download Report" button. The "Download Report" button SHALL only be shown when the migration status is `finished`.
4. WHEN the discount migration status is `finished` and `failed > 0`, THE Admin_UI SHALL display the 10 most recently failed items with their `source_id` and `error_message`.
5. THE Admin_UI SHALL display a "Preview" button that fetches and displays the first 5 promotions with their mapped Shopify type and any issues, before the user starts the migration.
6. THE Admin_UI SHALL display a "Start Migration" button that is disabled when: the Shopware connection is not configured, the Queue_Worker is offline, or a migration is already running.
7. WHEN a migration is `queued` or `running`, THE Admin_UI SHALL display a "Cancel" button that sends a cancel request and refreshes the status.
8. THE Admin_UI SHALL display a mapping summary banner explaining what Shopware promotion concepts are not supported in Shopify (sales channel scoping, combination rules, fixed unit price type, per-customer limits > 1), so the merchant understands the limitations before migrating.

---

### Requirement 14: API Routes

**User Story:** As a developer, I want the discount migration to expose the same REST API surface as other migration types, so that the frontend can interact with it consistently.

#### Acceptance Criteria

1. THE Backend SHALL expose `GET /api/migration/discounts/status` returning the current Migration_Run state and recent failed items, protected by the `shopify.session_token` middleware.
2. THE Backend SHALL expose `POST /api/migration/discounts/preview` accepting optional `limit` (integer, 1–20) and `page` (integer, 1–100000) parameters. IF `limit` or `page` exceed their maximum values, THE DiscountMigrationController SHALL return HTTP 422 with a validation error message.
3. THE Backend SHALL expose `POST /api/migration/discounts/start` accepting no body parameters, returning HTTP 202 with `run_id` and `status` on success.
4. THE Backend SHALL expose `POST /api/migration/discounts/cancel` returning `{ "cancelled": true/false }`.
5. ALL discount migration routes SHALL be grouped under the `throttle:migration` middleware, consistent with other migration routes.

---

### Requirement 15: Migration Report

**User Story:** As a merchant, I want a downloadable CSV report after the discount migration, so that I can audit which promotions were migrated, skipped, or failed.

#### Acceptance Criteria

1. WHEN a Migration_Run reaches `finished` or `cancelled` status, THE MigrationRunReportWriter SHALL produce a CSV report with one row per Migration_Item.
2. FOR EACH row in the report, THE MigrationRunReportWriter SHALL include: `shopware_promotion_id`, `promotion_name`, `shopify_discount_type`, `shopify_discount_gid`, `code_count`, `status` (`succeeded`/`skipped`/`failed`), `reason`, and `migrated_at_utc`.
3. WHEN a Migration_Item status is `skipped`, THE report row `reason` field SHALL contain `"No changes detected (fingerprint matched)"`.
4. WHEN a Migration_Item status is `failed`, THE report row `reason` field SHALL contain a human-readable summary of the failure.
5. THE report SHALL be downloadable via `GET /api/migration/runs/{run}/report` using the existing `MigrationRunReportController`.

---

## Correctness Properties

### Property 1: Pagination Invariant
For any valid page and limit combination when fetching Shopware_Promotions, the number of items returned SHALL be less than or equal to the requested limit. The `total` field SHALL remain consistent across all pages of the same migration run.

### Property 2: Discount Type Completeness
For any Shopware_Promotion with a `discountType` of `percentage`, `absolute`, or `free_shipping`, THE Discount_Mapper SHALL always produce a non-null Shopify discount type. The output type SHALL always be one of `{ basic, free_shipping, bxgy }` — never undefined or null.

### Property 3: Code Mapping Round-Trip
For any list of valid (non-empty) Shopware promotion code strings, THE Discount_Mapper SHALL produce a Shopify codes array where every input code appears exactly once in the output. Formally: `sort(output_codes) == sort(input_codes)` for inputs with ≤ 1,000 codes.

### Property 4: Date Ordering Invariant
For any Shopware_Promotion where both `validFrom` and `validUntil` are set, THE Discount_Mapper SHALL produce a payload where `startsAt <= endsAt`. This invariant SHALL hold for all valid date combinations including same-day promotions.

### Property 5: Value Positivity Invariant
For any Shopware_Promotion with a numeric `value` field greater than zero, THE Discount_Mapper SHALL produce a Shopify discount value that is also greater than zero. The mapping SHALL never produce a zero or negative discount value from a positive input.

### Property 6: Fingerprint Determinism
For any Shopware_Promotion payload, THE Fingerprint service SHALL always produce the same hash string for the same input. Formally: `fingerprint(x) == fingerprint(x)` for all valid promotion payloads. Re-running the migration on unchanged data SHALL always result in `skipped` status for all items.

### Property 7: Run Counter Invariant
After a Migration_Run reaches `finished` or `cancelled` status, the counters SHALL satisfy: `processed == succeeded + failed + skipped`. This invariant SHALL hold regardless of the order in which parallel job workers complete.

### Property 8: Idempotent Upsert
Running the discount migration twice on the same Shopware data SHALL produce the same set of Shopify discounts. The second run SHALL not create duplicate discounts. Formally: `|shopify_discounts_after_run_2| == |shopify_discounts_after_run_1|` for unchanged source data.

### Property 9: Partial Failure Isolation
For any batch of N promotions where K promotions fail, the Migration_Run SHALL still process all N promotions and report exactly K failures and (N - K) successes or skips. A single item failure SHALL never prevent subsequent items from being processed.

### Property 10: Usage Limit Preservation
For any Shopware_Promotion with `maxRedemptionsGlobal > 0`, THE Discount_Mapper SHALL produce a Shopify payload where `usageLimit == maxRedemptionsGlobal`. The mapping SHALL be exact with no rounding or truncation.
