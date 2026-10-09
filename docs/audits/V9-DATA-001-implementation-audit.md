# V9-DATA-001 Implementation Audit

**Audit base:** `2b73f2eb` (`origin/master` at latest reconciliation)
**Feature baseline commit:** `72a5c6d4` (`feat(v9): build export framework baseline`)
**Status:** INCOMPLETE — do not mark IMPLEMENTED / VERIFIED. This audit covers the current feature branch and verification performed on 2026-10-08.

## Focused Portfolio Snapshots slice (2026-10-08)

- Portfolio growth and snapshots catalog entries now advertise `current` and `full`; only the table snapshots entry advertises `selected`. Current scope requires a supported 90/180/365/all range and `snapshot_date` with `asc`/`desc` sort.
- Provider resolution performs account-scoped date filtering, range row caps, and ordering in SQL. Sync, worker, and basket resolution receive current filters; full resolution deliberately receives no filters. Filter and sort choices are written to export metadata.
- The snapshots API returns stable snapshot IDs. The table uses accessible per-row checkboxes, sends IDs directly for selected exports, hides selected scope with an empty selection, and clears selection when portfolio or date range changes.
- Added focused catalog, provider resolution/identity, API identity/filter validation, and lightweight UI wiring tests. The database-backed feature tests have not been run locally; no MySQL-dependent suite is claimed.
- Basket configuration UI now derives included fields and available scopes from each catalog item. Current scope exposes the supported 90d/180d/365d/all ranges and snapshot-date ascending/descending sort; selected scope accepts stable row IDs. Item configuration saves through the existing basket API, and export validates fields, scopes, filters, and nonempty selected IDs before submitting. The API continues fresh server-side resolution and revalidates field/scope/identity availability.
- Remaining limits: export scope remains restricted to registered datasets and their catalog scopes; selected IDs are entered as stable identifiers rather than selected from a basket-specific row browser. The entire DATA-001 acceptance criteria and integration evidence remain incomplete.

## Acceptance criteria

| # | Frozen requirement | Current evidence | Result |
|---:|---|---|---|
| 1 | Reusable provider contract: authorization, scopes, fields, labels/canonical values, estimates | A tagged provider contract supplies catalog, fresh resolution, authorization, scope support and estimates. Dashboard summary, portfolio snapshots, portfolio analytics and current-holdings fundamentals are separate adapters. | **Partial** — new providers are covered locally; full MySQL and all acceptance evidence remain pending. |
| 2 | CSV | Shared writer emits canonical values, metadata and formula-safe text. | Partial; focused tests pass; no full API feature test. |
| 3 | XLSX | Shared values-only writer with safe unique names; synchronous and asynchronous exports embed metadata in their single data sheet. | Partial; focused writer tests pass. |
| 4 | Current/full/selected scope | Portfolio growth and snapshots expose server-resolved current/full scopes; table snapshots also expose selected. API and worker use stable provider identities. | **Partial** — current scope and selected table interaction now exist only on the snapshots surface; broader provider and integration evidence remains incomplete. |
| 5 | Field/series selection | Export button accepts field selection; basket UI exposes catalog-allow-listed fields per item and validates at least one before save/export. | Partial — dataset-specific field coverage and MySQL verification remain incomplete. |
| 6 | Full matching results beyond pagination | Snapshot and current-holdings fundamentals providers query authorized source rows rather than browser page rows. | Partial — additional dataset coverage and MySQL verification remain incomplete. |
| 7 | Raw precision | Values are serialized without display rounding or float conversion in the writer. | Partial; DB/model precision behavior has not had a feature test. |
| 8 | Friendly labels and canonical values | CSV/XLSX headers use catalog labels; analytics, dashboard summary and fundamentals include readable metric labels beside stable keys, while snapshot rows retain canonical dates and values. | Partial — existing catalog and client integrations need review against the expanded row shapes. |
| 9 | Complete metadata/provenance | Dataset, scope, fields, timestamp, source/profile, cadence, field labels, and current-scope range/sort are included where available. | Partial — broader provenance remains incomplete. |
| 10 | XLSX multiple sheets | Writer supports multiple sheets; basket outputs exactly one sheet per item with metadata in that sheet. | Partial |
| 11 | Persistent private basket | One basket row is keyed by user; API queries/upserts by authenticated owner. Focused feature tests now cover persistence across requests, account isolation, and configuration-only storage. | Partial — feature tests use the local SQLite test configuration; MySQL-backed CI result is pending. |
| 12 | Basket stores configuration only | Basket JSON stores item configuration only; focused feature test confirms no row/data snapshot is persisted. | Partial — local SQLite feature test passed; MySQL-backed CI result pending. |
| 13 | Fresh worker resolution | Feature test creates authorized rows after queueing and verifies the worker resolves fresh account-scoped data at execution time. | Partial — focused local SQLite test passes; full MySQL worker suite remains pending. |
| 14 | Basket stale/incompatible validation | Export validates provider, permissions, fields, scope and selected identities at request time. Queued snapshot selections are revalidated against fresh worker queries; stale identities fail with a safe correction message and failure code. | Partial — broader provider-specific and MySQL-backed verification remains pending. |
| 15 | One sheet per basket item and rename | Safe unique sheet names; metadata appended within each item sheet. A basket API integration test verifies two configured items, metadata/value placement and duplicate-name normalization. | Partial — local SQLite integration passes; MySQL-backed acceptance remains pending. |
| 16 | Safe names | Writer strips invalid characters, trims apostrophes, truncates to 31 chars and de-duplicates case-insensitively. | Partial; unit test passes. |
| 17 | Basket item hard limit | Configured maximum 10; request validation enforces it. Feature test verifies an over-limit update is rejected without overwriting the saved basket. | Partial — MySQL-backed CI result is pending. |
| 18 | Server-side authorization | API provider check verifies profile ownership; queued worker repeats profile ownership check before fresh resolution. Feature test confirms basket read/write/export endpoints require authentication. | Partial — ownership-revocation and MySQL-backed API verification remain pending. |
| 19 | Download ownership/expiry | Focused feature tests verify owner-only status/download, expired and missing-expiry rejection, no stale download link, and exact owner/token/format path checks. The status endpoint now advertises a link only while a ready artifact has a future expiry; download also fails closed when expiry is absent. | Partial — focused local SQLite tests pass; MySQL-backed full suite remains deferred to master CI. |
| 20 | Restricted fields unavailable | Caller fields must be in provider's allow-listed columns; single and basket API tests reject internal fields before snapshot values are selected. | Partial — local SQLite tests pass; additional dataset-specific hidden-field policies and MySQL verification remain pending. |
| 21 | Synchronous small export | Configurable default 1,000-row threshold; single and basket requests estimate authorized row counts before resolving row values, and large exports queue without request-side row selection. | Partial — query-listener tests cover both request paths; MySQL verification remains pending. |
| 22 | Background large export | Large single-dataset and basket requests queue from scoped counts before row selection; worker resolves fresh data at execution and writes the basket workbook. Feature tests cover fresh resolution, workbook output, terminal state and notifications. | Partial — focused local SQLite tests pass; production queue/MySQL verification remains pending. |
| 23 | Cancellation | Owner-scoped cancellation sets a one-day retention expiry, removes partial files, and feature tests cover cancellation before worker start and after file write but before ready promotion. | Partial — focused race test passes; production queue concurrency remains unverified. |
| 24 | No downloadable partial output | Cancellation race test confirms the final and `.partial` files are absent after the cancelled worker returns. | Partial — focused test passes; production queue concurrency remains unverified. |
| 25 | COMM-001 completion/failure notice | Worker tests verify completion and failure events. A durable export-notification outbox records intent atomically with artifact terminal state, then hands delivery to COMM-001. | Partial — local tests cover delivery/retry; MySQL queue integration remains pending. |
| 26 | Optional email obeys preference | Completion notices opt into the existing planner; feature test confirms default-off behavior and delivery only when the export preference is enabled. | Partial — focused local test passes; full mail-transport integration remains pending. |
| 27 | 24-hour retention | Synchronous-ready, background-ready, failed, and cancelled artifacts set expiry to `now()->addDay()`. Feature tests assert the exact generation boundary and cover expired/missing-expiry behavior. | Partial — focused local tests pass; MySQL-backed CI remains pending. |
| 28 | Expiry cleanup | Hourly scheduled purge removes artifact rows and files whose expiry is at or before the current instant. Feature test verifies exact-cutoff deletion and preservation of unexpired/queued artifacts. | Partial — focused local SQLite test passes; MySQL-backed CI remains pending. |
| 29 | No user-facing history | No list/history endpoint added. | Pass by inspection |
| 30 | Protected, non-guessable file access | Local private storage, UUID token, authenticated owner-scoped route and exact path check; feature tests verify other-account 404s and reject a mismatched artifact path. | Partial — focused local SQLite tests pass; broader security review and MySQL CI remain pending. |
| 31 | Chart data only | Portfolio-growth exports the date axis and canonical snapshot series, with chart type, series labels, INR units and active range/sort in metadata; catalog exposes only CSV/XLSX. A provider feature test verifies the chart metadata and no image format. | Partial — focused SQLite test passes; MySQL-backed acceptance remains pending. |
| 32 | Maximum rows | Configured 50,000 default; writer and request enforce. | Partial; unit limit test passes. |
| 33 | Maximum file bytes | CSV checks file size while writing. XLSX checks the finalized archive against the configured byte limit before promotion and removes the archive plus worksheet temporaries when it fails. A regression test verifies the oversized archive is not exposed. | **Pass** — writer boundary and cleanup test pass; peak temporary disk use remains criterion 35. |
| 34 | Sheets/fields/cells | Configured limits: 10 sheets, 100 aggregate fields, 50,000 aggregate rows and 500,000 workbook cells; basket limit shares sheet cap. Writer tests cover aggregate field/cell limits; basket API integration rejects aggregate row/field overflow before row selection. | Partial — MySQL-backed basket boundary verification remains pending. |
| 35 | Runtime/memory | Single and basket requests estimate before row resolution; snapshot and fundamental workers pass database cursors to CSV/XLSX writers, and XLSX streams worksheet rows to bounded temporary files. Dashboard and analytics providers are bounded. Writer checks runtime and memory while consuming rows. | Partial — production queue/resource behavior and peak disk use while the bounded worksheets and archive coexist remain to verify. |
| 36 | Deterministic safe file names | Download name uses a dataset slug; internal path is owner/UUID/format-derived. Feature test asserts the deterministic download name. | Partial — focused local SQLite test passes; additional dataset-name edge cases remain pending. |
| 37 | Formula injection | Formula-like text with leading whitespace/control is neutralized; numeric negatives remain numeric. | Partial; writer tests pass. |
| 38 | Values only | XLSX emits inline strings and numeric `<v>` values; no formula element generation. | Partial; writer test passes. |
| 39 | Retry-safe work/notifications | Unique per-artifact completion/failure outbox records commit atomically with terminal artifact state. A locked, transactional delivery job marks the event delivered with the COMM-001 source; failed attempts retain a due time and a scheduled sweep redispatches pending events. | Partial — focused retry/no-duplicate tests pass; MySQL concurrency and queue recovery verification remain pending. |
| 40 | Excluded formats/features | No PDF, image, password protection, or reusable presets added. | Pass by inspection |

## Surface and capability limits

Registered datasets include dashboard summary, portfolio growth chart data, daily portfolio snapshots, portfolio analytics, and current-holdings fundamental facts. Snapshot and fundamentals providers stream cursor-backed worker rows; analytics and dashboard providers have bounded output. Current scope is advertised only on the two snapshot datasets; selected is advertised only for table snapshots. Basket configuration supports the scopes advertised by each catalog dataset.

## Remaining unsupported acceptance criteria

Remaining evidence includes full MySQL acceptance, integration review of the expanded display-label and canonical-key row shapes (8), complete provenance across all providers (9), and production resource verification (35). Single-export artifact records now persist the selected fields, matching field labels, source/profile metadata, and export timestamp used by the file. Chart-specific time-series metadata and a focused provider test now cover criterion 31; it still needs MySQL-backed acceptance. These are implementation limitations or unverified gates, not verified passes.

Additional acceptance evidence remains incomplete for broader dataset coverage, API/ownership/expiry security, database precision, background worker and cancellation races, notifications/preferences/retries, artifact retention and purge, aggregate cell/row limit integration, and MySQL acceptance (criteria 1–2, 7, 11, 13–15, 17–30, 32, 34–39). Criteria 12 and 29 remain pass by inspection; no new claim of full implementation or verification is made.

Focused basket UI slice verification on 2026-10-08: `node --test tests/js/portfolioSnapshotExport.test.mjs` passed, including catalog-driven field/scope and current/selected configuration contract assertions. `npm run test:js:unit` passed (54 test files, including the expanded export contract test). The canonical `./scripts/verify-ci.sh --frontend` passed JS tests (214), Vitest (177), TypeScript checking, and its hosted-path production build; it stopped at Playwright OS dependency installation because that step required elevated authorization. After installing the cached Chromium browser binaries without system package changes, `npm run test:e2e:journeys` passed: 59 tests passed, 56 skipped by the configured device matrix, 0 failed. `git diff --check` passed. Only `ExportBasketPanel.jsx`, `portfolioSnapshotExport.test.mjs`, and this audit changed for this slice; no PHP/API/OpenAPI changes were made. Database-dependent feature tests were not run. The API continues fresh resolution at export time. Earlier focused slice verification is recorded below; no full DATA-001 completion or DB-backed evidence is claimed.

## Verification evidence

| Command | Result |
|---|---|
| Branch reconciliation | `codex/v9-data001-completion` is reconciled with `origin/master` at `2b73f2eb`; no history was rewritten. |
| PHP syntax checks for changed export controllers, jobs, providers, writer and exception | Passed for all checked PHP files. |
| `vendor/bin/phpunit tests/Unit/Export` (from `app/`) | Latest run passed: 16 tests, 41 assertions; the combined export/API focused suite passed 48 tests, 218 assertions. |
| `php artisan openapi:v1 --check` (from `app/`) | Passed: OpenAPI document is current (221 operations). |
| `npm run docs:static:check` (from `app/`) | Passed: static documentation contract current (53 topics). |
| `git diff --check` (repository root) | Passed after the current test and audit changes. |
| Database-backed backend gate | Focused PHPUnit suites pass on the configured in-memory SQLite test database; MySQL-backed feature/full-suite verification is deferred by the feature-branch CI policy until the master gate. |

The feature baseline and subsequent implementation slices are committed on `codex/v9-data001-completion`, based on current `master`; the V9 wishlist remains FROZEN / IMPLEMENTATION-READY because the acceptance evidence and unsupported capabilities listed above are incomplete.

## Basket API verification slice (2026-10-08)

Added `tests/Feature/V9Data001ExportBasketTest.php` to verify that basket configuration persists across requests, is isolated per account, contains configuration rather than row snapshots, rejects item counts above the configured maximum without mutating the previously saved basket, and requires authentication for read/write/export endpoints. Focused local verification passed: `vendor/bin/phpunit tests/Feature/V9Data001ExportBasketTest.php` (3 tests, 28 assertions). CI run 37790260359 passed its enabled jobs; PHPUnit and frontend jobs were intentionally skipped by the current feature-branch CI policy and remain deferred to master. The repository PHPUnit configuration uses in-memory SQLite locally; this is supplemental only. `git diff --check` passed. No production DB or deployment was touched.

## Artifact access and expiry verification slice (2026-10-08)

Added focused feature coverage for owner-only artifact status/download, deterministic download filenames, expired artifacts, artifacts missing expiry, and strict owner/token/format path matching. The tests exposed that `status()` advertised expired artifacts and `download()` allowed a ready artifact with no expiry. The controller now withholds links unless expiry is in the future and rejects downloads when expiry is absent or no longer future. `php -l` passed; the combined focused run passed: `vendor/bin/phpunit tests/Feature/V9Data001ExportArtifactAccessTest.php tests/Feature/V9Data001ExportBasketTest.php tests/Unit/Export` (19 tests, 74 assertions; 2 existing PHPUnit notices). MySQL-backed acceptance remains pending under the feature-branch CI policy.

## Artifact retention and purge verification slice (2026-10-08)

Added a feature test that freezes time at the expiry boundary and checks both database rows and private files. It exposed an exclusive `< now()` purge comparison that left an artifact expiring exactly at the run cutoff until a later hourly pass; the command now uses `<= now()`. The test verifies expired and exact-cutoff artifacts are purged while an unexpired artifact and a queued artifact with no expiry remain. Focused local verification passed: `vendor/bin/phpunit tests/Feature/V9Data001ExportRetentionTest.php tests/Feature/V9Data001ExportArtifactAccessTest.php tests/Feature/V9Data001ExportBasketTest.php` (9 tests, 58 assertions after adding the cancellation expiry case). In-memory SQLite is supplemental; feature-branch CI defers MySQL to master.


## Background worker and cancellation verification slice (2026-10-08)

Added `tests/Feature/V9Data001ExportWorkerTest.php`. It verifies that a queued job resolves newly added account-scoped snapshot rows at execution time, writes and promotes the export, sets expiry, creates the completion notification, does not include another account's row, exits if already cancelled, and removes the final file when cancellation wins after file writing. Focused local verification passed: `vendor/bin/phpunit tests/Feature/V9Data001ExportWorkerTest.php` (4 tests, 21 assertions). PHPUnit in-memory SQLite is supplemental; queue-backend/MySQL verification remains deferred.


## Durable notification and retry slice (2026-10-08)

Added a private `portfolio_export_notification_outbox` with one completion/failure event per artifact. Artifact terminal-state changes and outbox creation share a database transaction; a queued delivery job records its COMM-001 notification source and marks the outbox delivered in one locked transaction. Failed attempts retain retry time/error class, and an hourly-independent one-minute scheduled sweep dispatches due undelivered events. Completion notices enter the existing optional-email preference planner; cancellation and worker-failure records receive a one-day cleanup expiry.

Focused verification passed: export basket/access/retention/worker/outbox plus export unit suites (27 tests, 126 assertions, 2 existing PHPUnit notices); notification publisher/planner regressions (11 tests, 39 assertions); changed PHP syntax checks; and `php scripts/verify-migration-portability.php` (179 migrations). Tests cover worker completion/failure, cancellation race cleanup, outbox retry without duplicate sources, outbox redispatch, and optional email default-off/opt-in. Local PHPUnit uses in-memory SQLite; MySQL CI remains deferred by branch policy.

## XLSX bounded worksheet generation slice (2026-10-08)

The XLSX writer now streams one row at a time into private temporary worksheet XML files and adds them to the ZIP archive by file path. It avoids a second full row array and full worksheet XML string, enforces a configurable 256 MiB aggregate worksheet temporary-byte cap, and removes worksheet/archive temporary files on success, cancellation, or failure. Focused verification passed: `vendor/bin/phpunit tests/Unit/Export` (13 tests, 34 assertions, 2 existing notices) and export basket/access/worker/outbox feature tests (14 tests, 86 assertions) on in-memory SQLite; PHP syntax and `git diff --check` passed. MySQL and strict process-level provider-memory bounds remain pending.

## Queue-before-resolution slice (2026-10-08)

Single-dataset and basket requests now estimate authorized, scope-limited row counts before resolving row values. Large requests create a queued artifact and dispatch only dataset/profile/scope/filter/field configuration; the worker freshly resolves each dataset and writes one workbook sheet per basket item. Provider estimates match current-range and all-range caps. DB query listener tests confirm neither request path selects snapshot values before dispatch, and a worker test verifies basket workbook output. Focused verification passed: export unit and basket/access/retention/worker/outbox suites (31 tests, 143 assertions, 2 existing PHPUnit notices), `php artisan openapi:v1 --check` (221 operations), PHP syntax, and `git diff --check`. MySQL and production queue verification remain pending.


## Independent provider adapter slice (2026-10-08)

Refactored `ExportDatasetRegistry` to compose tagged `ExportDatasetProvider` adapters instead of owning a dataset switch. Dashboard summary and portfolio snapshots now live in separate provider classes; each owns its catalog, fresh resolution, authorization, supported scopes and estimates. App container registration tags both adapters, and the registry rejects missing or duplicate dataset IDs. A unit test verifies an independently implemented provider can be registered and delegated to; a second test verifies duplicate IDs fail closed.

Focused verification passed: `vendor/bin/phpunit tests/Unit/Export` (16 tests, 41 assertions), the combined export/API focused suite (48 tests, 218 assertions), `php artisan openapi:v1 --check` (221 operations), changed-file PHP lint, and `git diff --check`. Tests use in-memory SQLite. This is an extensibility refactor only: the catalog still contains three datasets; general analytics/fundamental adapters, MySQL acceptance and strict worker memory ceilings remain incomplete.

## Analytics, fundamentals and streamed worker slice (2026-10-08)

Added a bounded portfolio analytics dataset with an explicit scalar metric allowlist and a current-holdings fundamentals dataset limited to current revisions available as of the export date. The fundamentals provider scopes through positive-quantity holdings owned by the requested profile and excludes revision internals. Both providers are tagged into the reusable registry. Added feature coverage for owner isolation, held/unheld stocks, current revisions, availability cutoffs, hidden columns and exported values.

Added cursor-backed worker streaming for portfolio snapshots and fundamental facts. The writer now accepts iterable rows for CSV and XLSX, enforces row/cell/runtime/memory limits while consuming them, and does not materialize these worker result sets. Queued selected snapshot identities are revalidated by the worker; stale IDs fail with safe correction guidance and a stable failure code.

Focused verification passed: php artisan test tests/Feature/V9Data001Export*.php tests/Unit/Export; PHP syntax checks; php artisan openapi:v1 --check (221 operations); npm run docs:static:check (53 topics); php scripts/verify-migration-portability.php (179 migrations); and git diff --check. The post-fix full application suite result is recorded below. These checks use the configured local database and do not replace the MySQL acceptance gate. XLSX final ZIP size is checked after close, so criteria 33 and production resource verification remain incomplete.

## Export memory-boundary regression and full-suite result (2026-10-09)

The full suite exposed a false rejection for otherwise small CSV/XLSX exports after earlier tests retained live memory in the same PHPUnit process. The previous check compared total process memory with the export limit. `ExportFileWriter` now records live memory at the start of each write and enforces the configured limit against memory growth during that export. A regression test retains more than the configured limit before invoking both writers and confirms both small exports complete; the existing zero-limit test still confirms the guard rejects actual export growth.

Focused verification passed: `php artisan test tests/Unit/Export tests/Feature/V9Data001Export*.php` (50 tests, 257 assertions), including chart metadata, readable labels beside canonical keys, friendly CSV/XLSX headers, and persisted selected-field provenance. Portfolio growth now records its time-series type, date axis format, series labels and INR units while retaining canonical data columns; only CSV/XLSX formats are advertised. The complete application suite, run after the memory-boundary fix and before this metadata-only addition, finished in 857.31 seconds with 2,304 passed, 2 skipped, and 3 failed (15,789 assertions). All V9-DATA-001 export tests passed, including basket integration, provider coverage, and worker execution. Remaining failures are outside this export feature: two `V9LogTriageConcurrencyTest` SQLite errors for missing `stox_log_error_triages` and `cache` tables, and one `VpsHealthDashboardTest` Mockery expectation where `dispatch()` was called zero times. The local full suite uses in-memory SQLite; feature-branch CI still defers the MySQL-backed gate to master. Production queue/resource verification and the other acceptance gaps listed above remain open.

## MySQL CI remediation and XLSX size-boundary verification (2026-10-09)

The canonical PHP 8.4/MySQL 8.4 CI run 882 exercised the complete 2,312-test backend suite and found two MySQL errors plus one order-sensitive assertion. Snapshot range resolution used a limited subquery inside `IN`, which MySQL 8.4 rejects; it now joins the bounded latest-snapshot derived query. Artifact JSON metadata assertions now ignore key order. The affected snapshot/provider/unit tests pass locally (30 tests, 115 assertions). CI run 885 is verifying the MySQL fix.

Added an XLSX final-archive size regression test with randomized worksheet data. It verifies that an archive above the configured maximum is rejected before promotion and that both the archive and worksheet temporary files are removed. The export writer unit suite passes (14 tests, 41 assertions), so criterion 33's final downloadable-file size bound is verified. Peak temporary disk usage under production workloads remains part of criterion 35.


Provider metadata regression coverage was expanded for portfolio analytics, dashboard summary, and current-holdings fundamentals, including source, profile, computation/as-of timestamps, and human field labels. The combined focused suite `php artisan test tests/Unit/Export tests/Feature/V9Data001Export*.php tests/Feature/PortfolioSnapshotApiTest.php` passed (57 tests, 293 assertions).


## Post-merge frontend gate (2026-10-09)

The production workflow enabled the frontend gate and found one stale static assertion: it searched `ExportDatasetRegistry.php` for provider catalog definitions, although those definitions live in the provider classes. The assertion now reads `DashboardSummaryExportProvider.php` and `PortfolioSnapshotExportProvider.php` directly. The complete `npm run test:js` suite passed locally (214 Node tests and 180 Vitest tests). The production workflow for merge SHA `a3758c51` remains blocked by this frontend failure; production deployment and live queue/resource evidence are still pending.
