# ICTECHS Shopware → Shopify Migrator

## Audience
This document is written for PMs and operational users who will run migrations.

## What this app does
This app migrates data from a Shopware store into Shopify in the following order:

1. Connect Shopware (credentials)
2. Select Shopify location
3. Migrate products + variants
4. Migrate customers
5. Migrate orders (supports “all” and “by date”)
6. Migrate newsletter recipients (depends on customers)

## Prerequisites
- Shopify store with the app installed
- Shopware Admin access (to create an Integration and obtain Client ID/Secret)
- Server access to run the backend queue worker

## Environments and throttling expectations (important)
- **Development / trial stores:** Shopify `orderCreate` is limited by Shopify to **~5 new orders per minute**.
- **Paid stores:** Shopify allows higher throughput; actual speed depends on Shopify throttling and payload complexity.

If order migration is slow on a dev/trial store, it is expected behavior.

## How to capture screenshots for this document
Use these steps whenever a section contains a **[Screenshot Placeholder]**.

- **Step 1:** Navigate to the referenced screen in the app.
- **Step 2:** Ensure the relevant area is visible (do not include secrets/tokens).
- **Step 3:** Capture the screenshot.
  - Linux: `PrtSc` (whole screen) or `Shift+PrtSc` (area)
  - macOS: `Cmd+Shift+4`
  - Windows: `Win+Shift+S`
- **Step 4:** Save with a clear name (example: `01-connect-shopware.png`).
- **Step 5:** Insert into this document under the placeholder.

## Accessing the Admin UI
- Install the app in Shopify.
- Open the embedded app from Shopify Admin (Apps → this app).

**[Screenshot Placeholder]** App opened inside Shopify Admin (navigation + page title visible).

## Dashboard page
The Dashboard provides:
- Shopify connection status (automatic)
- Shop domain and install time
- Shopware connection status
- Tips/warnings about prerequisites

**[Screenshot Placeholder]** Dashboard showing Shopify connection details.

## Migration page (step-by-step)
Open the **Migration** page from the left navigation.

**[Screenshot Placeholder]** Migration page showing all cards (1 through 6).

---

# 1) Connect Shopware
## Purpose
This step stores the Shopware API URL and OAuth integration credentials that the migrator uses to read data from Shopware.

## How to get Shopware credentials
In Shopware Admin:
1. Open **Settings**
2. Go to **System → Integrations**
3. Create a new integration and enable API access
4. Copy **Client ID** and **Client Secret**
5. Identify your Shopware Admin/API base URL (example: `https://your-shopware-domain.com`)

**[Screenshot Placeholder]** Shopware Admin → Settings → System → Integrations.

## In the app
1. Go to **Migration → 1) Connect Shopware**
2. Enter:
   - Shopware API URL
   - Client ID
   - Client Secret
3. Click **Save connection**
4. Confirm status becomes **Connected**

**[Screenshot Placeholder]** “Connect Shopware” card with fields filled (secret masked).

---

# 2) Select location and migrate
## Purpose
Select the Shopify location used for inventory-related operations.

## In the app
1. Go to **Migration → 2) Select location and migrate**
2. Select a **Location** from the dropdown
3. If no locations appear, create at least one in Shopify Admin (Settings → Locations) and retry

**[Screenshot Placeholder]** Location dropdown with a location selected.

---

# 3) Migrate products + variants
## Purpose
Migrates products and their variants from Shopware into Shopify.

## Recommended approach
1. Run **Preview (dry run)** first to validate mapping and identify issues
2. Start migration when preview looks correct

## In the app
1. Go to **Migration → 3) Migrate products + variants**
2. Click **Preview (dry run)**
3. Review:
   - Total product count
   - Sample items, variant counts
   - Any flagged issues
4. Click **Start migration** and confirm
5. Monitor status:
   - Run number
   - State
   - Elapsed/total time
   - Processed / succeeded / failed / skipped

**[Screenshot Placeholder]** Product preview results (showing total + at least one list item).

**[Screenshot Placeholder]** Product migration status panel while running.

---

# 4) Migrate customers
## Purpose
Migrates customers from Shopware into Shopify.

## In the app
1. Go to **Migration → 4) Migrate customers**
2. Click **Preview (dry run)** to validate mapping
3. Click **Start customer migration** and confirm
4. Monitor the status panel

## Optional: Migrate customers by date
If the UI offers date filtering:
1. Open the filtered migration dialog
2. Select:
   - After a date, or
   - Between two dates
3. Preview filtered total
4. Start filtered migration

**[Screenshot Placeholder]** Customer migration card.

**[Screenshot Placeholder]** Customer preview list.

**[Screenshot Placeholder]** (If available) Customer “Migrate by date” modal.

---

# 5) Migrate orders
## Purpose
Creates Shopify orders based on Shopware order history.

## Prerequisites
- Products migration completed
- Customer migration completed

The app will show prerequisite warnings if requirements are not met.

**[Screenshot Placeholder]** Order migration prerequisites warning banner (if present).

## Recommended approach
1. Run **Preview (dry run)** first (validate totals, addresses, line items)
2. Start migration
3. For large stores, prefer **Migrate By Date** in batches

## Full order migration
1. Go to **Migration → 5) Migrate orders**
2. Click **Preview (dry run)**
3. Click **Start order migration**
4. Confirm in the modal

**[Screenshot Placeholder]** Order preview results list.

## Order migration by date
1. Click **Migrate By Date**
2. Select filter mode:
   - After a date
   - Between two dates
3. Click **Preview filtered orders**
4. Verify “Filtered total”
5. Click **Continue to start filtered migration**
6. Confirm start

**[Screenshot Placeholder]** Orders “Migrate orders by order date” modal.

## Monitoring and interpreting status
The status panel shows:
- Run number
- State (queued/running/finished/cancelled)
- Processed / succeeded / failed / skipped
- Recent failures (with error reason)

**[Screenshot Placeholder]** Order status panel.

---

# 6) Migrate newsletter recipients
## Purpose
Migrates Shopware newsletter recipients into Shopify and updates customer email marketing consent.

## Prerequisites
- Customer migration completed

## In the app
1. Go to **Migration → 6) Migrate newsletter recipients**
2. Click **Preview (dry run)**
3. Click **Start newsletter migration** and confirm
4. Monitor status and review recent failures if any

**[Screenshot Placeholder]** Newsletter preview list.

**[Screenshot Placeholder]** Newsletter status panel.

---

# Operational notes
## Queue worker must be running
Migrations execute as background jobs.

Operational checklist:
- Queue worker online indicator in the UI should be green/online
- If worker is offline, start/restart the worker service on the server

**[Screenshot Placeholder]** Banner showing “Queue worker is offline” (if present).

## Cancelling a migration
Each card provides a **Cancel** button while a run is active.

Expected behavior:
- State transitions to `cancelled`
- Already succeeded items remain succeeded
- Remaining items stop processing

**[Screenshot Placeholder]** Cancel button and a cancelled run status.

---

# Troubleshooting
## “Queue worker is offline”
- Confirm the backend queue worker process is running.
- Restart the queue worker.

## “Too many attempts” during order migration
- On Shopify dev/trial stores, Shopify limits `orderCreate` to ~5/min.
- On paid stores, reduce concurrency or allow cooldown logic to stabilize.

## Slow order migration on dev/trial
- Expected due to Shopify’s `orderCreate` dev/trial limit.
- Use paid store for faster throughput.

## Common log locations
- Backend logs: `backend/storage/logs/laravel.log`

---

# Verification checklist (post-migration)
## Products
- Spot-check migrated products in Shopify Admin
- Confirm variants, images, and inventory are present

## Customers
- Spot-check customer profiles
- Confirm addresses are present

## Orders
- Spot-check a sample of orders:
  - Order totals
  - Addresses
  - Line items
  - Tags/metafields (if used)

## Newsletter
- Confirm marketing consent status is applied as expected

---

# Change log for this document
- v1: Initial end-to-end PM usage guide
