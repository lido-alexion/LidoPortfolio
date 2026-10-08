# V9-DATA-001 Implementation Audit

**Audit base:** `463ccd4ee751cba30e6dab83a79adb5a83dc69da` (`origin/master`)
**Status:** INCOMPLETE — do not mark IMPLEMENTED / VERIFIED. This audit covers the current worktree changes and the verification performed on 2026-10-07.

## Acceptance criteria

| # | Frozen requirement | Current evidence | Result |
|---:|---|---|---|
| 1 | Reusable provider contract: authorization, scopes, fields, labels/canonical values, estimates | `ExportDatasetProvider` contract is implemented by the registry; catalog exposes field descriptions, capabilities and estimates; account/profile check is server-side. Registry remains a switch implementation and does not yet offer independently registered adapters. | **Partial** |
| 2 | CSV | Shared writer emits canonical values, metadata and formula-safe text. | Partial; focused tests pass; no full API feature test. |
| 3 | XLSX | Shared values-only writer with safe unique names; synchronous and asynchronous exports embed metadata in their single data sheet. | Partial; focused writer tests pass. |
| 4 | Current/full/selected scope | Current scope is rejected because registered surfaces expose no exact current filtered/sorted query. Supported scopes are explicit. Selected API and worker use stable provider identities and reject stale identities. | **Partial** — no page supports true selected-row interaction; UI offers full only. |
| 5 | Field/series selection | Export button accepts field selection; provider fields are allow-listed. Basket UI does not yet expose field/series configuration. | Partial |
| 6 | Full matching results beyond pagination | Snapshot provider queries its full authorized dataset rather than browser page rows. | Partial — only the registered portfolio datasets are supported. |
| 7 | Raw precision | Values are serialized without display rounding or float conversion in the writer. | Partial; DB/model precision behavior has not had a feature test. |
| 8 | Friendly labels and canonical values | Catalog describes labels and canonical forms; rows retain canonical metric/date/amount values. | Partial — labels are provenance descriptions, not a dual label/value column for every dataset. |
| 9 | Complete metadata/provenance | Dataset, scope, fields, timestamp, source/profile, cadence and field labels are included where available; XLSX metadata is in the data sheet and CSV includes metadata rows. | Partial — no filters/sort/date-range exist on these current adapters. |
| 10 | XLSX multiple sheets | Writer supports multiple sheets; basket outputs exactly one sheet per item with metadata in that sheet. | Partial |
| 11 | Persistent private basket | One basket row is keyed by user; API queries/upserts by authenticated owner. | Partial — account-isolation feature test pending. |
| 12 | Basket stores configuration only | Basket JSON stores item configuration only. | Pass by inspection |
| 13 | Fresh worker resolution | Background payload carries profile/dataset/scope/fields/identity configuration; worker reloads authorized profile and resolves rows at execution time. | Partial — worker integration test pending. |
| 14 | Basket stale/incompatible validation | Export validates provider, permissions, fields, scope and selected identities at execution request time. | Partial — correction UI and feature tests pending. |
| 15 | One sheet per basket item and rename | Safe unique sheet names; metadata appended within each item sheet. | Partial — basket integration test pending. |
| 16 | Safe names | Writer strips invalid characters, trims apostrophes, truncates to 31 chars and de-duplicates case-insensitively. | Partial; unit test passes. |
| 17 | Basket item hard limit | Configured maximum 10; request validation enforces it. | Partial; boundary feature test pending. |
| 18 | Server-side authorization | API provider check verifies profile ownership; queued worker repeats profile ownership check before fresh resolution. | Partial — permission revocation and endpoint feature tests pending. |
| 19 | Download ownership/expiry | Authenticated owner-scoped query, ready/expiry check, exact owner/token/format path check. | Partial; endpoint tests pending. |
| 20 | Restricted fields unavailable | Caller fields must be in provider's allow-listed columns; only currently registered public fields are exposed. | Partial — no provider-specific hidden-field policy test. |
| 21 | Synchronous small export | Configurable default 1,000-row threshold. | Partial; feature test pending. |
| 22 | Background large export | Queue job writes protected `.partial`, promotes only when state remains running and uncancelled. | Partial; queue lifecycle tests pending. |
| 23 | Cancellation | Owner-scoped atomic cancellation; writer checks state between rows; job removes its partial on cancellation. | Partial — concurrency/race tests pending. |
| 24 | No downloadable partial output | Conditional ready transition and final-path cleanup prevent cancelled worker promotion. | Partial — concurrent cancellation race untested. |
| 25 | COMM-001 completion/failure notice | Job uses `NotificationPublisher` event path. | Partial — notification assertions and retry behavior untested. |
| 26 | Optional email obeys preference | Export is categorized under existing optional-email preferences; it is disabled by default and uses planner gating. | Partial — end-to-end preference delivery test pending. |
| 27 | 24-hour retention | Ready artifacts set expiry to `now()->addDay()`. | Partial; boundary test pending. |
| 28 | Expiry cleanup | Existing scheduled purge removes expired artifacts/files. | Partial; command test pending. |
| 29 | No user-facing history | No list/history endpoint added. | Pass by inspection |
| 30 | Protected, non-guessable file access | Local private storage, UUID token, authenticated owner-scoped route and exact path check. | Partial; endpoint/security tests pending. |
| 31 | Chart data only | Chart dataset exports snapshot values; no image route or format. | Partial — no chart-specific metadata test. |
| 32 | Maximum rows | Configured 50,000 default; writer and request enforce. | Partial; unit limit test passes. |
| 33 | Maximum file bytes | CSV checks while writing; XLSX checks archive size after close and removes failed temporary/output files. | **Partial** — XLSX temporary generation can exceed the configured cap before rejection. |
| 34 | Sheets/fields/cells | Configured limits: 10 sheets, 100 aggregate fields, 50,000 aggregate rows and 500,000 workbook cells; basket limit shares sheet cap. | Partial; aggregate row rejection and cancellation cleanup have focused unit coverage; aggregate field/cell boundaries and basket API behavior lack integration coverage. |
| 35 | Runtime/memory | Configured 120-second and 256 MiB checks during writer loops. | **Partial** — providers materialize result rows and XLSX XML in memory before all checks; not a strict process-level ceiling. |
| 36 | Deterministic safe file names | Download name uses a dataset slug; internal path is owner/UUID/format-derived. | Partial; endpoint test pending. |
| 37 | Formula injection | Formula-like text with leading whitespace/control is neutralized; numeric negatives remain numeric. | Partial; writer tests pass. |
| 38 | Values only | XLSX emits inline strings and numeric `<v>` values; no formula element generation. | Partial; writer test passes. |
| 39 | Retry-safe work/notifications | Atomic queued claim, terminal state checks and guarded promotion reduce duplicate artifacts. | **Open** — failure between ready transition and notification can lose the notification; no durable notification outbox/idempotency proof. |
| 40 | Excluded formats/features | No PDF, image, password protection, or reusable presets added. | Pass by inspection |

## Surface and capability limits

Registered datasets remain dashboard summary, portfolio growth chart data, and daily portfolio snapshots. Current filtered/sorted exports, general analytical/fundamental providers, basket field/filter configuration, and a selectable-row UI are not implemented. The UI exposes only the full scope for current surfaces; server-side `selected` requests require stable provider IDs and reject stale IDs. No current-view scope is advertised.

## Remaining unsupported acceptance criteria

The current implementation does not support true current filtered/sorted scope, general analytics or fundamental datasets, basket field/filter configuration in the UI, or selectable-row interaction (criteria 4–6). It does not provide dual display-label/value columns for every dataset (8), full provenance for filters/sort/date ranges that current providers do not expose (9), a general hidden-field policy test (20), chart-specific metadata evidence (31), or strict process-level memory/runtime ceilings before provider and XML materialization (35). These are implementation limitations, not verified passes.

Additional acceptance evidence remains incomplete for provider adapter registration, API/ownership/expiry security, selected-scope UI behavior, database precision, background worker and cancellation races, notifications/preferences/retries, artifact retention and purge, aggregate basket API boundaries, and basket integration (criteria 1–2, 7, 11, 13–15, 17–30, 32–39). Criteria 12 and 29 remain pass by inspection; no new claim of full implementation or verification is made.

## Verification evidence

| Command | Result |
|---|---|
| `git status --short --branch`, `git rev-parse HEAD`, `git rev-parse origin/master`, `git merge-base HEAD origin/master` | Branch `codex/v9-data001-completion`; HEAD and `origin/master` both `463ccd4ee751cba30e6dab83a79adb5a83dc69da`; merge base equals both. |
| `php -l app/Services/Export/ExportFileWriter.php && php -l app/Http/Controllers/Api/ExportController.php && php -l app/Jobs/GenerateExportArtifact.php && php -l tests/Unit/Export/ExportFileWriterTest.php` (from `app/`) | Passed: all four files have no syntax errors. |
| `vendor/bin/phpunit tests/Unit/Export` (from `app/`) | Passed: 12 tests, 31 assertions. OpenTelemetry logged connection errors to unavailable local collector `127.0.0.1:4318`; PHPUnit exit was 0. |
| `php artisan openapi:v1 --check` (from `app/`) | Passed: OpenAPI document is current (220 operations). |
| `npm run docs:static:check` (from `app/`) | Passed: static documentation contract current (53 topics). |
| `git diff --check` (repository root) | Passed. |
| Database-dependent checks | Not run, as requested. No MySQL command, migration, frontend suite, commit, push, or deploy was run. |

No full backend CI, browser suite, notification integration suite, migration gate, or complete frontend suite pass is claimed. The canonical V9 wishlist remains FROZEN / IMPLEMENTATION-READY because acceptance evidence is incomplete.
