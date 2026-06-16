# Technical Design: Discount Migration

## Overview

This design adds a `discounts` migration type to the ICTECHS2SMigrator app, following the exact same architectural patterns as the existing `manufacturers`, `products`, `customers`, `orders`, and `newsletter` migrations.

Shopware 6 Promotions are fetched via the `/api/promotion` endpoint and mapped to Shopify discounts via the Admin GraphQL API. The mapping is one-to-one per promotion: each Shopware promotion becomes either an automatic or code-based Shopify discount depending on whether it has promotion codes. Data that has no Shopify equivalent (sales channel scoping, combination rules, priority) is preserved as `shopware`-namespace metafields on the Shopify discount.

---

## Components and Interfaces

### New Components

| Component | Type | Location |
|-----------|------|----------|
| `DiscountMigrationController` | HTTP Controller | `app/Http/Controllers/Api/DiscountMigrationController.php` |
| `DiscountMigrationService` | Service | `app/Services/Migration/DiscountMigrationService.php` |
| `DiscountMapper` | Service | `app/Services/Migration/DiscountMapper.php` |
| `DiscountFingerprint` | Service | `app/Services/Migration/DiscountFingerprint.php` |
| `RunDiscountMigrationJob` | Job | `app/Jobs/RunDiscountMigrationJob.php` |
| `ProcessDiscountMigrationItemJob` | Job | `app/Jobs/ProcessDiscountMigrationItemJob.php` |
| `FinalizeDiscountMigrationRunJob` | Job | `app/Jobs/FinalizeDiscountMigrationRunJob.php` |

### Modified Components

| Component | Change |
|-----------|--------|
| `routes/api.php` | Add `migration/discounts` route group |
| `ShopwareClient` | Add `fetchPromotions()` method |
| `admin/src/App.jsx` | Add Discounts migration card |

---

## Data Models

No new database tables are required. The existing `migration_runs`, `migration_items`, and `shopify_id_mappings` tables are reused with `type = 'discounts'` and `entity_type = 'discount'`.

### `migration_runs` row for discounts

| Column | Value |
|--------|-------|
| `type` | `'discounts'` |
| `status` | `queued` → `running` → `finished` / `cancelled` / `failed` |
| `processed` | incremented per item |
| `succeeded` | incremented per succeeded item |
| `failed` | incremented per failed item |

### `migration_items` row per promotion

| Column | Value |
|--------|-------|
| `entity_type` | `'discount'` |
| `source_id` | Shopware promotion UUID |
| `status` | `queued` → `running` → `succeeded` / `skipped` / `failed` |
| `fingerprint` | SHA-256 of canonical promotion fields |
| `error_message` | Exception message on failure |
| `error_context` | JSON: `product_scope_requires_manual_review`, `codes_truncated`, `per_customer_limit_not_fully_mappable`, etc. |

### `shopify_id_mappings` row per promotion

| Column | Value |
|--------|-------|
| `entity_type` | `'discount'` |
| `source_id` | Shopware promotion UUID |
| `shopify_gid` | e.g. `gid://shopify/DiscountAutomaticNode/123456` |

### Shopware → Shopify field mapping

| Shopware field | Shopify field | Notes |
|----------------|---------------|-------|
| `name` | `title` | Truncated to 255 chars; fallback `"Unnamed Promotion"` |
| `validFrom` | `startsAt` | ISO 8601 UTC; omitted if null |
| `validUntil` | `endsAt` | ISO 8601 UTC; omitted if null |
| `active = false` | `startsAt` = +100 years UTC | Disables discount without deleting |
| `codes[].code` | discount codes array | Code-based if any codes present |
| `discounts[].type = percentage` | `percentageValue` | Capped at 100 |
| `discounts[].type = absolute` | `discountAmount` | Fixed amount |
| `discounts[].scope = delivery` | free-shipping mutation | Overrides value type |
| `discounts[].scope = cart` | `appliesOnAllProducts: true` | Entire order |
| `discounts[].scope = set` | `appliesOnAllProducts: true` + `error_context` flag | Manual review required |
| `maxRedemptionsGlobal > 0` | `usageLimit` | Exact value |
| `maxRedemptionsPerCustomer = 1` | `appliesOncePerCustomer: true` | |
| `cartRules[].conditions` | `minimumRequirement` | Subtotal or quantity |
| `preventCombination` | metafield `shopware.prevent_combination` | No Shopify equivalent |
| `salesChannels[].name` | metafield `shopware.sales_channels` | JSON array |
| `id` | metafield `shopware.promotion_id` | Always written |
| `priority` | metafield `shopware.priority` | Always written |

### Unmappable discount types

| Shopware type | Behaviour |
|---------------|-----------|
| `fixed_unit_price` | Skipped — `error_context.unmappable_reason = 'fixed_unit_price'` |
| `free_item` (no product GIDs) | Skipped — `error_context.unmappable_reason = 'free_item_no_gids'` |

---

## Architecture

### New Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/DiscountMigrationController.php` | `preview`, `status`, `start`, `cancel` endpoints |
| `app/Services/Migration/DiscountMigrationService.php` | `start()`, `status()`, `cancel()` — mirrors `ManufacturerMigrationService` |
| `app/Services/Migration/DiscountMapper.php` | Maps a Shopware promotion array to a Shopify GraphQL mutation name + variables |
| `app/Services/Migration/DiscountFingerprint.php` | SHA-256 fingerprint of canonical promotion fields |
| `app/Jobs/RunDiscountMigrationJob.php` | Paginates `/api/promotion`, dispatches item jobs |
| `app/Jobs/ProcessDiscountMigrationItemJob.php` | Fetches one promotion, maps it, upserts to Shopify |
| `app/Jobs/FinalizeDiscountMigrationRunJob.php` | Waits for all items, sets run to `finished` |

### Modified Files

| File | Change |
|------|--------|
| `backend/routes/api.php` | Add `migration/discounts` prefix group with 4 routes |
| `app/Services/Shopware/ShopwareClient.php` | Add `fetchPromotions(conn, perPage, page): array` |
| `admin/src/App.jsx` | Add Discounts card alongside existing migration cards |

---

## Component Designs

### 1. DiscountFingerprint

Stateless. Hashes the fields that matter for idempotency.

```php
class DiscountFingerprint
{
    public function make(array $promotion): string
    {
        $payload = [
            'name'                     => data_get($promotion, 'name'),
            'active'                   => data_get($promotion, 'active'),
            'validFrom'                => data_get($promotion, 'validFrom'),
            'validUntil'               => data_get($promotion, 'validUntil'),
            'priority'                 => data_get($promotion, 'priority'),
            'maxRedemptionsGlobal'     => data_get($promotion, 'maxRedemptionsGlobal'),
            'maxRedemptionsPerCustomer'=> data_get($promotion, 'maxRedemptionsPerCustomer'),
            'preventCombination'       => data_get($promotion, 'preventCombination'),
            'discounts'                => data_get($promotion, 'discounts'),
            'codes'                    => data_get($promotion, 'codes'),
            'salesChannels'            => data_get($promotion, 'salesChannels'),
            'cartRules'                => data_get($promotion, 'cartRules'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS);
        return hash('sha256', is_string($json) ? $json : '');
    }
}
```

---

### 2. DiscountMapper

Stateless. Returns `['mutation' => string, 'variables' => array, 'issues' => array]`.

```php
class DiscountMapper
{
    /**
     * Map a Shopware promotion to a Shopify discount mutation payload.
     *
     * @return array{
     *   mutation: string,
     *   variables: array,
     *   issues: string[],
     *   skipped: bool,
     *   skip_reason: string|null
     * }
     */
    public function map(array $promotion): array

    /**
     * Resolve the Shopify mutation name based on discount type and code presence.
     * Returns null if the promotion is unmappable.
     */
    private function resolveMutation(array $promotion): ?string

    /**
     * Build the discount input variables for the resolved mutation.
     */
    private function buildInput(array $promotion, string $mutation): array

    /**
     * Extract minimum requirement from cartRules conditions.
     * Returns null if no minimum condition found.
     */
    private function resolveMinimumRequirement(array $promotion): ?array

    /**
     * Build metafields array for unmappable/extra Shopware data.
     */
    private function buildMetafields(array $promotion): array

    /**
     * Resolve startsAt, accounting for active=false override.
     */
    private function resolveStartsAt(array $promotion): ?string

    /**
     * Filter and truncate codes to 1,000 valid entries.
     * Returns [codes[], truncated_count_or_null].
     */
    private function filterCodes(array $promotion): array
}
```

#### Mutation resolution logic

```
hasScope(delivery)  → free_shipping mutation
discountType = free_shipping → free_shipping mutation
discountType = percentage   → basic mutation
discountType = absolute     → basic mutation
discountType = fixed_unit_price → SKIP
discountType = free_item (no GIDs) → SKIP

hasCodes → discountCode* variant
no codes → discountAutomatic* variant
```

Full decision table:

| Scope | Type | Has codes | Mutation |
|-------|------|-----------|----------|
| delivery | any | no | `discountAutomaticFreeShippingCreate` |
| delivery | any | yes | `discountCodeFreeShippingCreate` |
| cart / set | free_shipping | no | `discountAutomaticFreeShippingCreate` |
| cart / set | free_shipping | yes | `discountCodeFreeShippingCreate` |
| cart / set | percentage | no | `discountAutomaticBasicCreate` |
| cart / set | percentage | yes | `discountCodeBasicCreate` |
| cart / set | absolute | no | `discountAutomaticBasicCreate` |
| cart / set | absolute | yes | `discountCodeBasicCreate` |
| any | fixed_unit_price | any | **SKIP** |
| any | free_item | any | **SKIP** |

#### `startsAt` resolution

```
if active === false:
    startsAt = Carbon::now()->addYears(100)->startOfDay()->toIso8601String()
    endsAt   = omitted
elseif validFrom is set:
    startsAt = Carbon::parse(validFrom)->utc()->toIso8601String()
    endsAt   = validUntil ? Carbon::parse(validUntil)->utc()->toIso8601String() : omitted
    if startsAt > endsAt: clamp endsAt = startsAt, record in error_context
else:
    startsAt = omitted
    endsAt   = validUntil ? Carbon::parse(validUntil)->utc()->toIso8601String() : omitted
```

#### Metafields always written

```php
['namespace' => 'shopware', 'key' => 'promotion_id', 'type' => 'single_line_text_field', 'value' => $promotion['id']],
['namespace' => 'shopware', 'key' => 'priority',     'type' => 'single_line_text_field', 'value' => (string)($promotion['priority'] ?? '')],
```

#### Metafields written conditionally

```php
// preventCombination = true
['namespace' => 'shopware', 'key' => 'prevent_combination', 'type' => 'single_line_text_field', 'value' => 'true']

// salesChannels not empty
['namespace' => 'shopware', 'key' => 'sales_channels', 'type' => 'json',
 'value' => json_encode(array_column($promotion['salesChannels'], 'name'))]

// scope = set
['namespace' => 'shopware', 'key' => 'product_scope_json', 'type' => 'json',
 'value' => json_encode($promotion['discounts'])]

// maxRedemptionsPerCustomer > 1
['namespace' => 'shopware', 'key' => 'max_redemptions_per_customer', 'type' => 'single_line_text_field',
 'value' => (string)$promotion['maxRedemptionsPerCustomer']]
```

---

### 3. ShopwareClient — `fetchPromotions()`

New method added to the existing `ShopwareClient`:

```php
/**
 * Fetch a page of Shopware promotions with all required associations.
 *
 * @return array{promotions: array, total: int}
 */
public function fetchPromotions(ShopwareConnection $conn, int $perPage = 100, int $page = 1): array
{
    $body = [
        'limit' => $perPage,
        'page'  => $page,
        'associations' => [
            'codes'        => [],
            'discounts'    => [],
            'salesChannels'=> [],
            'orderRules'   => [],
            'personaRules' => [],
            'cartRules'    => [
                'associations' => [
                    'conditions' => [],
                ],
            ],
        ],
    ];

    $response = $this->post($conn, '/api/search/promotion', $body);

    return [
        'promotions' => data_get($response, 'data', []),
        'total'      => (int) data_get($response, 'meta.total', 0),
    ];
}
```

---

### 4. DiscountMigrationService

Mirrors `ManufacturerMigrationService` exactly, with `type = 'discounts'` and `RunDiscountMigrationJob`.

```php
class DiscountMigrationService
{
    public function start(Shop $shop): MigrationRun
    public function status(Shop $shop): ?MigrationRun
    public function cancel(Shop $shop): bool
}
```

`start()` guards against duplicate runs with `whereIn('status', ['queued', 'running'])`, creates the run, calls `MigrationRunReportWriter::init()`, then dispatches `RunDiscountMigrationJob`.

`cancel()` sets `status = 'cancelled'`, `finished_at = now()`, calls `MigrationRunReportWriter::finalize()`.

---

### 5. RunDiscountMigrationJob

Mirrors `RunManufacturerMigrationJob`. Paginates `ShopwareClient::fetchPromotions()` 100 at a time, dispatches one `ProcessDiscountMigrationItemJob` per promotion ID, then dispatches `FinalizeDiscountMigrationRunJob` when the last page is reached.

```php
public function handle(): void
{
    // guard: run cancelled/finished/failed → return
    // set run->status = 'running'
    // call ShopwareClient::fetchPromotions($conn, 100, $this->page)
    // foreach promotion → dispatch ProcessDiscountMigrationItemJob($run->id, $sourceId)
    // if hasMore → dispatch self($run->id, $this->page + 1)
    // else → dispatch FinalizeDiscountMigrationRunJob($run->id)->delay(2s)
    // catch Throwable → run->status = 'failed', run->finished_at = now(), save
}
```

---

### 6. ProcessDiscountMigrationItemJob

Core item processor. Mirrors `ProcessManufacturerMigrationItemJob` structure.

```php
public function handle(): void
{
    // 1. Load run + shop + conn; guard cancelled/finished/failed
    // 2. firstOrCreate MigrationItem (entity_type='discount', source_id)
    // 3. Guard already succeeded/skipped
    // 4. Set item->status = 'running', save

    // 5. Fetch promotion from Shopware (single item by ID)
    // 6. Compute fingerprint
    // 7. Check latestSucceededFingerprint — if match AND Shopify GID exists → skip

    // 8. Call DiscountMapper::map($promotion)
    // 9. If skipped → markSkipped($run, $item, $reason)

    // 10. Check shopify_id_mappings for existing GID
    //     → if found: use update mutation
    //     → if not found: use create mutation

    // 11. Execute GraphQL mutation via ShopifyAdminGraphqlClient
    // 12. Handle userErrors (stale GID → fallback to create)
    // 13. Upsert shopify_id_mappings with returned GID
    // 14. Set item->status = 'succeeded', fingerprint, finished_at, save
    // 15. appendRow to MigrationRunReportWriter
    // 16. incrementRunCounters(processed+1, succeeded+1)

    // catch Throwable → markFailed($run, $item, $e->getMessage())
}
```

#### Shopify GraphQL mutations used

**Create mutations:**
- `discountAutomaticBasicCreate(automaticBasicDiscount: DiscountAutomaticBasicInput!)`
- `discountAutomaticFreeShippingCreate(freeShippingAutomaticDiscount: DiscountFreeShippingAutomaticInput!)`
- `discountCodeBasicCreate(basicCodeDiscount: DiscountCodeBasicInput!)`
- `discountCodeFreeShippingCreate(freeShippingCodeDiscount: DiscountCodeFreeShippingInput!)`

**Update mutations (upsert path):**
- `discountAutomaticBasicUpdate(id: ID!, automaticBasicDiscount: DiscountAutomaticBasicInput!)`
- `discountAutomaticFreeShippingUpdate(id: ID!, freeShippingAutomaticDiscount: DiscountFreeShippingAutomaticInput!)`
- `discountCodeBasicUpdate(id: ID!, basicCodeDiscount: DiscountCodeBasicInput!)`
- `discountCodeFreeShippingUpdate(id: ID!, freeShippingCodeDiscount: DiscountCodeFreeShippingInput!)`

All mutations return `{ userErrors { field message } }` plus the created/updated discount node with `id`.

#### Stale GID fallback

```
if update mutation returns userError containing "not found" or "doesn't exist":
    delete shopify_id_mappings row
    re-run with create mutation
    upsert new GID into shopify_id_mappings
```

#### Report row columns

```
shopware_promotion_id | promotion_name | shopify_discount_type | shopify_discount_gid
code_count | status | reason | migrated_at_utc
```

---

### 7. FinalizeDiscountMigrationRunJob

Mirrors `FinalizeManufacturerMigrationRunJob` exactly, with `entity_type = 'discount'`.

```php
public function handle(): void
{
    // guard: run cancelled/finished/failed → return
    // check remaining items with status queued/running
    //   → if any: re-dispatch self with 3s delay, return
    // count processed/succeeded/failed from migration_items
    // set run->processed, succeeded, failed, status='finished', finished_at=now()
    // call MigrationRunReportWriter::finalize($run->id)
}
```

---

### 8. DiscountMigrationController

Mirrors `ManufacturerMigrationController` exactly.

```php
class DiscountMigrationController extends Controller
{
    public function preview(Request $request): JsonResponse   // fetch + map up to 20 promotions
    public function status(Request $request): JsonResponse    // current run + recent_failed_items
    public function start(Request $request): JsonResponse     // queue health check + service->start()
    public function cancel(Request $request): JsonResponse    // service->cancel()
}
```

#### `preview()` response shape

```json
{
  "page": 1,
  "total": 42,
  "items": [
    {
      "source_id": "uuid",
      "name": "Summer Sale",
      "shopify_discount_type": "basic",
      "is_automatic": true,
      "code_count": 0,
      "value": 10,
      "value_type": "percentage",
      "valid_from": "2026-06-01T00:00:00Z",
      "valid_until": "2026-06-30T23:59:59Z",
      "is_active": true,
      "issues": [],
      "fingerprint": "abc123..."
    }
  ]
}
```

`issues` examples:
- `"Discount type 'fixed_unit_price' has no Shopify equivalent and will be skipped"`
- `"Product-scoped discount requires manual review after migration"`
- `"Per-customer limit of 3 cannot be fully mapped (Shopify only supports once-per-customer)"`
- `"Promotion codes truncated to 1,000 (total: 1,250)"`

#### `status()` response shape

```json
{
  "run": {
    "id": 7,
    "type": "discounts",
    "status": "running",
    "processed": 12,
    "succeeded": 10,
    "failed": 2,
    "started_at": "2026-05-29T10:00:00Z",
    "finished_at": null,
    "duration_seconds": 45,
    "report_available": false,
    "report_download_url": "/api/migration/runs/7/report"
  },
  "recent_failed_items": [
    { "id": 99, "source_id": "uuid", "error_message": "...", "error_context": {}, "finished_at": "..." }
  ]
}
```

---

### 9. API Routes

Added to `routes/api.php` inside the existing `throttle:migration` group:

```php
Route::prefix('migration/discounts')->group(function () {
    Route::post('/preview', [DiscountMigrationController::class, 'preview']);
    Route::get('/status',   [DiscountMigrationController::class, 'status']);
    Route::post('/start',   [DiscountMigrationController::class, 'start']);
    Route::post('/cancel',  [DiscountMigrationController::class, 'cancel']);
});
```

---

### 10. Admin UI — Discounts Card

A new `DiscountsMigrationCard` component is added to `admin/src/App.jsx` (or extracted into its own file following the existing card pattern). It renders alongside the existing Manufacturers, Products, Customers, Orders, and Newsletter cards.

The card includes:
- Mapping limitations banner (always visible): lists sales channel scoping, combination rules, `fixed_unit_price`, and per-customer limits > 1 as unsupported
- "Preview" button → calls `POST /api/migration/discounts/preview` with `limit=5`, renders a table of promotions with their mapped type and issues
- "Start Migration" button → disabled when Shopware not configured, queue offline, or migration running
- Progress indicator (when `queued` or `running`): shows `processed`, `succeeded`, `failed`, elapsed time — polls every 3 seconds
- "Cancel" button (when `queued` or `running`)
- Summary banner (when `finished`): succeeded / failed / skipped counts + "Download Report" button
- Failed items list (when `finished` and `failed > 0`): up to 10 items with `source_id` and `error_message`

---

## Data Flow Diagram

```
POST /api/migration/discounts/start
    └─ DiscountMigrationController.start()
        └─ DiscountMigrationService.start()
            ├─ MigrationRun.create(type='discounts', status='queued')
            ├─ MigrationRunReportWriter.init()
            └─ RunDiscountMigrationJob.dispatch(runId)

RunDiscountMigrationJob (page 1..N)
    └─ ShopwareClient.fetchPromotions(conn, 100, page)
        └─ foreach promotion
            └─ ProcessDiscountMigrationItemJob.dispatch(runId, sourceId)
        └─ if hasMore → self.dispatch(runId, page+1)
        └─ else → FinalizeDiscountMigrationRunJob.dispatch(runId, delay=2s)

ProcessDiscountMigrationItemJob
    ├─ ShopwareClient.fetchPromotions(filter by id)   → single promotion
    ├─ DiscountFingerprint.make()                     → fingerprint
    ├─ latestSucceededFingerprint()                   → skip if unchanged
    ├─ DiscountMapper.map()                           → mutation + variables + issues
    ├─ shopify_id_mappings lookup                     → create vs update
    ├─ ShopifyAdminGraphqlClient.query(mutation)      → Shopify GID
    ├─ shopify_id_mappings.updateOrCreate()
    ├─ MigrationItem.update(succeeded, fingerprint)
    ├─ MigrationRunReportWriter.appendRow()
    └─ incrementRunCounters(processed+1, succeeded+1)

FinalizeDiscountMigrationRunJob
    ├─ wait for all items terminal
    ├─ MigrationRun.update(finished, counters)
    └─ MigrationRunReportWriter.finalize()
```

---

## Error Handling Strategy

| Failure | Behaviour |
|---------|-----------|
| Shopware API error during page fetch | `run->status = 'failed'`, `Log::error`, stop |
| Promotion not found by ID | `markFailed`, continue other items |
| Unmappable discount type | `markSkipped` with reason in `error_context`, continue |
| Shopify GraphQL `userErrors` | `markFailed`, record in `error_context`, continue |
| Stale Shopify GID (discount deleted) | Delete mapping, fallback to create, continue |
| Metafield write failure | Non-fatal — log warning, item still succeeds |
| Percentage value > 100 | Cap at 100, record in `error_context.percentage_capped: true` |
| `validFrom > validUntil` | Clamp `endsAt = startsAt`, record in `error_context.date_clamped: true` |
| Codes > 1,000 | Truncate to first 1,000, record `codes_truncated: true` |
| Queue worker offline at start | HTTP 409 with message |
| Unhandled exception in item job | `markFailed`, `Log::error`, continue |

---

## Correctness Properties

### Property 1: Fingerprint Determinism
`DiscountFingerprint::make()` uses `JSON_SORT_KEYS` to ensure key ordering does not affect the hash. Same input always produces the same SHA-256 output.

### Property 2: Idempotent Upsert
`shopify_id_mappings` is checked before every create. Re-running on unchanged data produces `skipped` for all items. Re-running on changed data updates the existing Shopify discount rather than creating a duplicate.

### Property 3: Partial Failure Isolation
Each `ProcessDiscountMigrationItemJob` wraps its entire body in `try/catch`. A failure in one item never prevents subsequent items from being dispatched or processed.

### Property 4: Run Counter Invariant
`FinalizeDiscountMigrationRunJob` recomputes `processed`, `succeeded`, and `failed` from the database before writing them to the run, ensuring `processed == succeeded + failed + skipped` regardless of job ordering.

### Property 5: Non-Fatal Metafields
Metafield writes are wrapped in `try/catch`. Failure to write a metafield logs a warning but does not fail the migration item.

---

## Backward Compatibility

- No existing tables are modified.
- No existing services or jobs are modified.
- The new routes are additive.
- The new `ShopwareClient::fetchPromotions()` method is additive.
- Existing migration types are completely unaffected.
