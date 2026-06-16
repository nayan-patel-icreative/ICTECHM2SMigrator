# TODO - per-run migration CSV reports (steps 1-8)

## Backend
- [x] Step 1: Add `migration_runs.report_path` nullable column + update `MigrationRun` model fillable.

- [x] Step 2: Implement `MigrationRunReportWriter` service (init CSV, appendRow with flock+fputcsv, humanizeFailureReason, headersForType, finalize footer).

- [x] Step 3: Instrument `ProcessProductMigrationItemJob` to append one parent product row on succeeded/failed/skipped.
- [x] Step 4: Instrument other item jobs (manufacturer/customer/order/newsletter) to append rows on terminal states + update finalize jobs to write footer.
- [x] Step 5: Add `MigrationRunReportController` + API route `GET /api/migration/runs/{run}/report` with shop isolation.
- [x] Step 6: Extend each migration `status` endpoint payload with `report_available` and `report_download_url` for latest/current run.

## Admin UI
- [x] Step 7: Add `downloadBlob` helper in `admin/src/api/client.js`.
- [x] Step 8: Add top-right download icon button to each migration card in `admin/src/App.jsx`, using `report_available`.

## Tests
- [x] Add unit tests for CSV writer + humanizeFailureReason.
- [x] Add feature test for authorized download.
