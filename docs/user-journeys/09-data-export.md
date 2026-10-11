# Investor data export

Exports follow the data permissions of the originating page. They export data/configuration, not trading instructions. See the frozen [V9 data-export specification](../archive/specs/V9-Data-Export-Framework-Specification.md).

## E2E-18 — Export snapshot data and recover a background export

**Goal:** Download authorized snapshot/chart data with an explicit scope and field selection, while retaining control of a background job.

- Prerequisite: sign in as an investor with an active portfolio and available snapshot data.
- Warning: generated downloads expire after 24 hours; cancelled, failed or expired jobs have no downloadable artifact.
- Search synonyms: export CSV, export chart data, cancel export, download snapshots

1. Open `/portfolio/snapshots`, inspect the date range and choose Export table data or Export chart data.
2. Choose CSV or XLSX, an offered scope, and one or more offered fields. Selected scope uses selected stable row IDs; chart export contains underlying values rather than an image.
3. Cancel the configuration dialog to leave without creating an export, or correct an invalid field selection before submitting.
4. If generation fails, keep the originating data and retry with the intended scope/fields. A small ready export downloads immediately; a queued/running export shows its latest status.
5. Use Refresh export status after a failed read or while waiting. Download becomes available only when generation is ready and its retention has not expired.
6. To stop a queued/running export, choose Cancel export. If cancellation cannot be confirmed, refresh its status before retrying; confirm the cancelled state has no download.
7. Generate a fresh export if the previous job failed or expired. Do not interpret an unavailable status as a completed job.

**Expected result:** Scope, fields and provenance remain explicit. Status/cancellation use the same account-scoped export capability; exporting never changes portfolio/trading state or widens data access.

## E2E-19 — Maintain a private export basket and recover a rejected workbook

**Goal:** Persist a set of dataset configurations and generate a fresh authorized multi-dataset XLSX export.

- Prerequisite: sign in as an investor with an active portfolio and supported export datasets.
- Warning: the basket stores configuration, not a data snapshot; every generation revalidates current access and freshness. The basket is account-private, limited to ten items and not an export history.
- Search synonyms: export basket, combine datasets, XLSX export, spreadsheet download

1. Open `/` and expand Export basket. Inspect the saved items and the available dataset catalog.
2. Add a supported dataset. If saving fails, retry the addition; do not treat an unsaved item as persisted.
3. Set its sheet name, choose fields/scope and provide required snapshot filters or stable selected-row IDs. Correct a highlighted invalid configuration before saving.
4. Save item configuration. If saving fails, retain the draft for correction/retry; after success, reopen or reload the basket to verify persistence.
5. Add another dataset with its own configuration. Choose Export XLSX to save/revalidate the configurations and request fresh data for each sheet.
6. If the server rejects a stale/unavailable dataset, field, selection or permission, review the affected item and retry. Do not reuse the rejected result as a valid workbook.
7. Follow the latest export status/download/cancellation controls as in E2E-18. A queued job does not start a download.
8. Remove an item when no longer needed. A failed removal keeps the saved item so it can be retried.

**Expected result:** Each basket item remains independently configurable and account-private. Failed writes do not falsely appear saved; generation fetches fresh authorized data, with one sheet per item and protected temporary downloads.
