# StoX V9 Data Export Framework Specification

| Field | Value |
|---|---|
| **Epic** | `V9-DATA-001` — Data Export Framework |
| **Version target** | V9 |
| **Status** | IMPLEMENTED / VERIFIED — scope remains frozen |
| **Implementation audit** | [V9-DATA-001 acceptance audit](../../audits/V9-DATA-001-implementation-audit.md) |
| **Owner** | Product / Architecture |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |

## 1. Product intent

Provide a reusable investor-facing export framework across StoX rather than page-specific downloads. Users may export structured data they are already authorized to view, including table data, chart series and supported analytical/historical datasets.

## 2. Supported scope

Support export of:

- table rows;
- filtered/sorted result sets;
- full supported datasets even when only part is rendered/paginated;
- selected rows/items;
- chart underlying data series;
- selected historical/fundamental/analytical datasets available to the user;
- combined multi-dataset XLSX exports via an export basket.

Out of scope: PDF/report generation, chart image export, unrestricted database/account dumps.

## 3. Formats

Supported formats:

- **CSV**
- **XLSX**

XLSX may use multiple sheets for logically distinct datasets. Exports contain resolved values only; do not emit executable spreadsheet formulas.

## 4. Export configuration

Before generation, always ask the user to choose scope where applicable:

- current filtered/sorted view;
- full matching dataset;
- selected rows/items.

Allow users to choose which columns/series are included. Defaults should reflect the originating page/context.

## 5. Data representation

- Preserve best available stored/calculated numeric precision.
- CSV contains plain numeric values.
- XLSX may apply readable number formatting without changing underlying values.
- Include units/scales where relevant.
- Prefer canonical underlying values with user-friendly labels where useful.
- Use stable representations for symbols/identifiers, dates and enums/states.

## 6. Metadata and provenance

Include useful metadata/provenance such as:

- export timestamp;
- dataset/context;
- selected scope;
- filters and sort;
- date range/cadence;
- selected fields/series;
- source/provenance where meaningful;
- StoX/schema identifier where useful.

## 7. XLSX behavior

A logical export may contain multiple sheets, e.g. Summary, Historical Fundamentals, Chart Series and Metadata/Provenance.

For combined basket exports, use **one sheet per basket item**. Users may rename sheet titles before export, subject to XLSX naming/uniqueness rules.

## 8. Multi-dataset export basket

Provide a persistent, account-private export basket.

Rules:

- persists across sessions;
- private to the account; not shareable;
- stores configuration only, not data snapshots;
- fetches fresh data at export time;
- stores dataset/context, scope, fields/series, filters/sort and date/cadence settings;
- validates items before export;
- surfaces stale/incompatible configurations for correction;
- enforces a practical hard item limit;
- no reusable export presets.

## 9. Authorization

Export must exactly follow normal StoX data-access permissions.

- Export must not widen access.
- Server-side generation must re-check authorization.
- Restricted/hidden fields remain unavailable.
- Basket items are revalidated for permissions at export time.

## 10. Synchronous vs background generation

- Small exports: synchronous/immediate.
- Large exports: background jobs.

Exact thresholds are implementation-level decisions based on row count, workbook complexity, file size, memory and runtime.

Users may cancel queued/running background exports. Cancellation must stop further work safely, remove incomplete temporary artifacts and leave no downloadable file.

## 11. Background export notifications

Use the V9 notification framework for background completion/failure.

- In-app notification on completion/failure.
- Secure download action while valid.
- Optional email only if user preferences permit.

## 12. Retention and history

No persistent user-facing export history.

Background-generated files:

- remain downloadable for **24 hours**;
- are deleted after expiry;
- may be regenerated if needed.

## 13. Security

No password-protected exports.

Security relies on authenticated/account-scoped generation and download, authorization checks, protected temporary storage, transport security and 24-hour retention.

Do not expose generated files through guessable public URLs.

## 14. Chart export boundary

Export chart **data only**: x-axis/date/category values, series values, labels/names, units and relevant metadata.

PNG/SVG/image export is out of scope.

## 15. Safety limits

Enforce explicit hard safety limits for:

- maximum rows/records;
- maximum generated file size;
- maximum workbook complexity/sheets;
- maximum basket item count;
- runtime/memory protection.

If exceeded, ask the user to narrow scope rather than failing unpredictably.

## 16. Implementation-level defaults

Use a reusable export service/framework shared across StoX data surfaces.

Recommended architecture:

- dataset adapters/providers expose canonical fields, authorization and scope resolution;
- background jobs use Laravel queues;
- generated files use protected temporary storage;
- download endpoints re-check account authorization and expiry;
- use streaming-capable CSV/XLSX generation where practical;
- sanitize formula-like text values to prevent spreadsheet formula injection;
- use safe deterministic filenames;
- make background generation idempotent enough to avoid duplicate artifacts on retry.

## 17. Frozen PO decisions

- Export tables, chart series, filtered/sorted datasets and supported analytical data.
- Formats: CSV + XLSX.
- Always ask export scope.
- Allow column/series selection.
- XLSX supports multi-sheet exports.
- Include useful metadata/provenance.
- Full supported dataset may be exported even when not fully rendered.
- Small exports synchronous; large exports background jobs.
- No persistent export history.
- Background files expire after 24 hours.
- No password protection.
- Chart export is data-only.
- Export raw precision, not UI-rounded precision.
- No reusable export presets.
- Export authorization equals normal StoX data authorization.
- Values only; no executable formulas.
- Prefer canonical underlying values with friendly labels where useful.
- Background completion/failure uses notification framework.
- Users may cancel background exports.
- Support multi-dataset combined XLSX export basket.
- Export basket persists per account across sessions.
- Basket is private/non-shareable.
- Basket snapshots configuration only; data is fetched fresh at export time.
- Validate stale/incompatible basket items before export.
- One XLSX sheet per basket item.
- Users may rename sheet titles.
- Apply a practical basket hard limit.
- Apply explicit export safety limits.

## 18. Acceptance criteria

The epic is complete when:

- reusable export plumbing is available across supported StoX data surfaces;
- CSV and XLSX exports work for supported datasets;
- explicit scope and field/series selection are implemented;
- full matching datasets can be exported beyond current pagination/rendering;
- metadata/provenance and raw precision are preserved appropriately;
- authorization is re-enforced server-side;
- background generation, cancellation, notifications and 24-hour retention work safely;
- combined persistent export-basket XLSX flow works with validation and one sheet per item;
- security and size limits prevent unsafe/unbounded exports;
- no out-of-scope PDF/chart-image/formula/history features are introduced.
