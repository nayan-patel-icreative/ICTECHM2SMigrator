# Implementation Plan: Discount Migration

## Overview

Implements Shopware 6 Promotion → Shopify Discount migration following the exact same patterns as the existing `manufacturers`, `products`, `customers`, `orders`, and `newsletter` migrations. No existing tables or services are modified except for two additive changes: a new `fetchPromotions()` method on `ShopwareClient` and a new route group in `api.php`.

## Tasks

- [x] 1. Create `DiscountFingerprint` service
  - Create `app/Services/Migration/DiscountFingerprint.php`
  - Implement `make(array $promotion): string`
  - Hash fields: `name`, `active`, `validFrom`, `validUntil`, `priority`, `maxRedemptionsGlobal`, `maxRedemptionsPerCustomer`, `preventCombination`, `discounts`, `codes`, `salesChannels`, `cartRules`
  - Use `json_encode(..., JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS)` then `hash('sha256', ...)`
  - Return empty-string-safe: if json_encode fails, hash empty string
  - **Requirements:** R9.3, Property 6

- [x] 2. Create `DiscountMapper` service
  - Create `app/Services/Migration/DiscountMapper.php`
  - Implement `map(array $promotion): array` returning `['mutation', 'variables', 'issues', 'skipped', 'skip_reason']`
  - Implement private `resolveMutation(array $promotion): ?string` using the decision table:
    - `scope=delivery` or `type=free_shipping` → `discountAutomatic/CodeFreeShippingCreate`
    - `type=percentage` or `type=absolute` → `discountAutomatic/CodeBasicCreate`
    - `type=fixed_unit_price` or `type=free_item` → return `null` (skip)
    - Code presence (`codes[]` non-empty) selects `discountCode*` vs `discountAutomatic*`
  - Implement `resolveStartsAt(array $promotion): ?string`:
    - `active=false` → Carbon::now()->addYears(100)->startOfDay()->utc()->toIso8601String(), omit `endsAt`
    - `validFrom` set → parse to UTC ISO 8601
    - If `startsAt > endsAt` → clamp `endsAt = startsAt`, record `date_clamped: true` in issues
  - Implement `resolveMinimumRequirement(array $promotion): ?array`:
    - Scan `cartRules[].conditions` for minimum order value → `subtotal` shape
    - Scan for minimum quantity → `quantity` shape
    - Prefer subtotal; if both present record `minimum_quantity_condition_dropped: true` in issues
    - Return null if no condition found
  - Implement `filterCodes(array $promotion): array`:
    - Filter out empty/null code strings, log `Log::warning` per skipped code
    - Truncate to first 1,000; record `codes_truncated: true` + total count in issues if truncated
  - Implement `buildMetafields(array $promotion): array`:
    - Always: `shopware.promotion_id`, `shopware.priority`
    - Conditional: `shopware.prevent_combination`, `shopware.sales_channels`, `shopware.product_scope_json`, `shopware.max_redemptions_per_customer`
  - Implement `buildInput(array $promotion, string $mutation): array`:
    - Set `title` (truncate to 255, fallback `"Unnamed Promotion"`)
    - Set `startsAt` / `endsAt` from `resolveStartsAt()`
    - Set `customerGets.value` (percentage capped at 100, or fixed amount)
    - Set `appliesOnAllProducts: true` for cart/set scope
    - Set `minimumRequirement` from `resolveMinimumRequirement()`
    - Set `usageLimit` when `maxRedemptionsGlobal > 0`
    - Set `appliesOncePerCustomer` based on `maxRedemptionsPerCustomer`
    - Set `codes` array from `filterCodes()` for code-based mutations
    - Set `metafields` from `buildMetafields()`
  - **Requirements:** R2, R3, R4, R5, R6, R7, R8, Property 2, 3, 4, 5

- [x] 3. Add `fetchPromotions()` to `ShopwareClient`
  - Add `fetchPromotions(ShopwareConnection $conn, int $perPage = 100, int $page = 1): array` to `app/Services/Shopware/ShopwareClient.php`
  - POST to `/api/search/promotion` with `limit`, `page`, and associations: `codes`, `discounts`, `salesChannels`, `orderRules`, `personaRules`, `cartRules` (with nested `conditions`)
  - Return `['promotions' => array, 'total' => int]`
  - **Requirements:** R1.1, R1.2

- [x] 4. Create `DiscountMigrationService`
  - Create `app/Services/Migration/DiscountMigrationService.php`
  - Implement `start(Shop $shop): MigrationRun`:
    - Guard: return existing run if `type='discounts'` and `status` in `['queued','running']`
    - Create run with `type='discounts'`, `status='queued'`, `started_at=now()`
    - Call `MigrationRunReportWriter::init($run)`
    - Dispatch `RunDiscountMigrationJob::dispatch($run->id)`
  - Implement `status(Shop $shop): ?MigrationRun`:
    - Return latest run for shop with `type='discounts'` ordered by `id` desc
  - Implement `cancel(Shop $shop): bool`:
    - Find active run (`queued` or `running`); return false if none
    - Set `status='cancelled'`, `finished_at=now()`, save
    - Call `MigrationRunReportWriter::finalize($run->id)`
    - Return true
  - **Requirements:** R10.1, R10.2, R10.4, R10.5

- [x] 5. Create `RunDiscountMigrationJob`
  - Create `app/Jobs/RunDiscountMigrationJob.php`
  - Constructor: `int $runId`, `int $page = 1`
  - `public int $timeout = 1200`
  - In `handle()`:
    - Load run with `shop.shopwareConnection`; guard cancelled/finished/failed
    - Set `run->status = 'running'`, save (only on page 1 or if still queued)
    - Call `ShopwareClient::fetchPromotions($conn, 100, $this->page)`
    - If empty result → dispatch `FinalizeDiscountMigrationRunJob::dispatch($run->id)->delay(2s)`, return
    - Foreach promotion: extract `source_id`, dispatch `ProcessDiscountMigrationItemJob::dispatch($run->id, $sourceId)`
    - If `($this->page * 100) < $total` → dispatch `self::dispatch($run->id, $this->page + 1)`
    - Else → dispatch `FinalizeDiscountMigrationRunJob::dispatch($run->id)->delay(2s)`
    - Catch `\Throwable`: `Log::error`, set `run->status='failed'`, `run->finished_at=now()`, save
  - **Requirements:** R1.1, R1.3, R1.4, R10.1

- [x] 6. Create `FinalizeDiscountMigrationRunJob`
  - Create `app/Jobs/FinalizeDiscountMigrationRunJob.php`
  - Constructor: `int $runId`
  - `public int $timeout = 300`
  - In `handle()`:
    - Load run; guard cancelled/finished/failed → return
    - Check for remaining items with `entity_type='discount'` and `status` in `['queued','running']`
    - If any remaining → re-dispatch `self::dispatch($run->id)->delay(3s)`, return
    - Count `processed` (succeeded+failed+skipped), `succeeded`, `failed` from `migration_items`
    - Set `run->processed`, `run->succeeded`, `run->failed`, `run->status='finished'`, `run->finished_at=now()`, save
    - Call `MigrationRunReportWriter::finalize($run->id)`
  - **Requirements:** R10.3, R10.6, Property 4, 7

- [x] 7. Create `ProcessDiscountMigrationItemJob`
  - Create `app/Jobs/ProcessDiscountMigrationItemJob.php`
  - Constructor: `int $runId`, `string $sourceId`
  - `public int $timeout = 300`
  - In `handle()`:
    - Load run with `shop.shopwareConnection`; guard cancelled/finished/failed
    - `firstOrCreate` MigrationItem with `entity_type='discount'`, `source_id`; guard already succeeded/skipped
    - Set `item->status='running'`, `item->started_at=now()`, save
    - Fetch single promotion from Shopware (filter by id)
    - If not found → `markFailed($run, $item, 'Shopware promotion not found')`, return
    - Compute fingerprint via `DiscountFingerprint::make()`
    - Check `latestSucceededFingerprint()` — if match AND Shopify GID exists in `shopify_id_mappings` → `markSkipped()`, return
    - Call `DiscountMapper::map($promotion)`
    - If `$result['skipped']` → `markSkipped($run, $item, $result['skip_reason'])`, return
    - Look up `shopify_id_mappings` for `entity_type='discount'`, `source_id`
    - If GID found → use update mutation; if update returns "not found" userError → delete mapping, fall back to create
    - If no GID → use create mutation
    - Execute GraphQL via `ShopifyAdminGraphqlClient`
    - If `userErrors` → `markFailed($run, $item, ...)`, return
    - Upsert `shopify_id_mappings` with returned GID
    - Store `error_context` issues from mapper (non-fatal warnings)
    - Set `item->status='succeeded'`, `item->fingerprint`, `item->finished_at=now()`, save
    - Call `MigrationRunReportWriter::appendRow($run, [...])`
    - Call `incrementRunCounters($run->id, ['processed'=>1, 'succeeded'=>1])`
    - Catch `\Throwable` → `markFailed($run, $item, $e->getMessage())`
  - Implement private helpers:
    - `latestSucceededFingerprint(int $shopId, string $sourceId): ?string` — joins `migration_runs` on `type='discounts'`
    - `incrementRunCounters(int $runId, array $delta): void` — DB::transaction with lockForUpdate
    - `markFailed(MigrationRun $run, MigrationItem $item, string $message): void` — sets failed status, appends report row, increments counters
    - `markSkipped(MigrationRun $run, MigrationItem $item, string $reason): void` — sets skipped status, appends report row, increments processed only
  - Report row columns: `shopware_promotion_id`, `promotion_name`, `shopify_discount_type`, `shopify_discount_gid`, `code_count`, `status`, `reason`, `migrated_at_utc`
  - **Requirements:** R2, R3, R4, R5, R6, R7, R8, R9, R11, Property 3, 8, 9

- [x] 8. Create `DiscountMigrationController`
  - Create `app/Http/Controllers/Api/DiscountMigrationController.php`
  - Inject `DiscountMigrationService $service` in constructor
  - Implement `preview(Request $request): JsonResponse`:
    - Validate `limit` (integer, 1–20, default 10) and `page` (integer, 1–100000, default 1)
    - Return 422 if Shopware connection missing
    - Fetch promotions via `ShopwareClient::fetchPromotions()`
    - For each promotion call `DiscountMapper::map()` and `DiscountFingerprint::make()`
    - Return `{ page, total, items: [{ source_id, name, shopify_discount_type, is_automatic, code_count, value, value_type, valid_from, valid_until, is_active, issues, fingerprint }] }`
  - Implement `status(Request $request): JsonResponse`:
    - Call `service->status($shop)`; return `{ run: null }` if none
    - Return run fields + `recent_failed_items` (last 10 failed) + `duration_seconds`
    - Include `report_available`, `report_download_url`
  - Implement `start(Request $request): JsonResponse`:
    - Check `QueueHealthService::probe()`; return 409 if offline
    - Call `service->start($shop)`; return 202 with `{ run_id, status }`
  - Implement `cancel(Request $request): JsonResponse`:
    - Call `service->cancel($shop)`; return `{ cancelled: true/false }`
  - **Requirements:** R11.5, R12, R13.6, R14

- [x] 9. Register routes in `api.php`
  - Add `use App\Http\Controllers\Api\DiscountMigrationController;` import
  - Inside the existing `throttle:migration` group, add:
    ```php
    Route::prefix('migration/discounts')->group(function () {
        Route::post('/preview', [DiscountMigrationController::class, 'preview']);
        Route::get('/status',   [DiscountMigrationController::class, 'status']);
        Route::post('/start',   [DiscountMigrationController::class, 'start']);
        Route::post('/cancel',  [DiscountMigrationController::class, 'cancel']);
    });
    ```
  - **Requirements:** R14.1–R14.5

- [x] 10. Add Discounts migration card to Admin UI
  - In `admin/src/App.jsx`, add a `DiscountsMigrationCard` component (or inline section) alongside existing migration cards
  - Mapping limitations banner (always visible): list sales channel scoping, combination rules, `fixed_unit_price` type, and per-customer limits > 1 as unsupported in Shopify
  - "Preview" button: calls `POST /api/migration/discounts/preview` with `limit=5`, renders table of promotions with mapped type and issues array
  - "Start Migration" button: disabled when Shopware not configured, queue offline, or migration `queued`/`running`
  - Progress indicator (when `queued` or `running`): shows `processed`, `succeeded`, `failed`, elapsed time — polls `GET /api/migration/discounts/status` every 3 seconds
  - "Cancel" button (when `queued` or `running`): calls `POST /api/migration/discounts/cancel`
  - Summary banner (when `finished`): succeeded / failed / skipped counts + "Download Report" button linking to `report_download_url`
  - Failed items list (when `finished` and `failed > 0`): up to 10 items with `source_id` and `error_message`
  - **Requirements:** R13

- [x] 11. Run diagnostics and verify
  - Run `getDiagnostics` on all new and modified PHP files
  - Fix any type errors, missing imports, or undefined method references
  - Verify `php artisan route:list` shows the 4 new discount routes
  - Confirm `DiscountMapper` handles all 5 Shopware discount types without throwing
  - Confirm `DiscountFingerprint::make()` is deterministic (same input → same hash)
  - **Requirements:** All

## Task Dependency Graph

```json
{
  "waves": [
    { "wave": 1, "tasks": [1, 2, 3] },
    { "wave": 2, "tasks": [4, 5, 6] },
    { "wave": 3, "tasks": [7] },
    { "wave": 4, "tasks": [8] },
    { "wave": 5, "tasks": [9, 10] },
    { "wave": 6, "tasks": [11] }
  ]
}
```

## Notes

- Tasks 1, 2, and 3 are fully independent — no shared files.
- Tasks 4, 5, and 6 depend on tasks 1–3 but are independent of each other.
- Task 7 (`ProcessDiscountMigrationItemJob`) depends on tasks 1, 2, 3, 5, and 6 — implement last among the backend jobs.
- Task 8 (`DiscountMigrationController`) depends on tasks 2, 3, and 4.
- Task 9 (routes) depends on task 8.
- Task 10 (UI) can be done in parallel with tasks 8–9 once the API contract is clear from task 8.
- The `MigrationRunReportWriter`, `ShopifyIdMapping`, `MigrationRun`, and `MigrationItem` models are reused as-is — no changes needed.
- The `QueueHealthService` is reused as-is for the queue health check in `start()`.
- Metafield write failures in `ProcessDiscountMigrationItemJob` must be caught and treated as non-fatal — the item must still succeed.
- The stale GID fallback in task 7 (update → "not found" → delete mapping → create) is critical for idempotency after manual Shopify discount deletions.
