# F042 completed-repair rollback (#383)

## Implemented path

New F042 repairs capture complete physical before and after price rows in `portfolio_price_adjustment_repair_snapshots`, including persisted source and provenance fields. Factor metadata stores version, row count, and hashes of both images. Capture, adjustment, snapshot completion, and the completed marker share the stock-locked repair transaction.

Admin endpoint `POST /api/data-quality/issues/{issue}/rollback-completed-repair` restores those before-images by primary key and appends a linked rejected resolution in one transaction. It verifies snapshot integrity, exact row membership, and every current after-image hash before writing. It refuses legacy completed factors without snapshots, incomplete images, drift, and overlapping factor/F020 events. Raw evidence and unrelated price rows remain untouched; the factor is deactivated and rollback metadata is retained. Repeating a successful rollback is idempotent. The existing accept/reject guard still blocks metadata-only reversal while repair is completed. Derived metrics and repair proofs refresh; Strategy/ML/trading replay is not invoked.

## Native rehearsal

The managed sandbox denied private Unix socket creation with `Operation not permitted`. Run the following rehearsal in an isolated environment where private sockets are allowed. It verifies the immutable approved dump SHA and 149/142/7 row shape on a disposable MariaDB server with networking disabled, imports only the price fixture, then runs the focused application rollback/API tests against an ephemeral MariaDB database and removes the scratch datadir on exit.

```bash
cd /home/nitty/stox-universe-pricing-383
./app/scripts/rehearse-f042-exact-rollback-mariadb.sh /home/nitty/stox-383-bnagrochem-preflight-20261126-9VC0jl
```

The focused application tests exercise service transactions and deterministic two-process races on native MariaDB. The concurrency feature test covers F020-vs-F042 ownership, repair-vs-repair, repair-vs-rollback, and state rechecks after a competing commit. The rehearsal asserts `VERSION()`/`@@version_comment` identify MariaDB before reporting success. Never use production configuration or credentials.

```bash
cd /home/nitty/stox-universe-pricing-383/app
APP_ENV=testing DB_CONNECTION=mysql DB_SOCKET=<private-test-socket> DB_DATABASE=<disposable-test-db> DB_USERNAME=<local-test-user> DB_PASSWORD= DB_URL= CACHE_STORE=array OTEL_SDK_DISABLED=true OTEL_TRACES_EXPORTER=none OTEL_METRICS_EXPORTER=none OTEL_LOGS_EXPORTER=none php artisan test --compact tests/Unit/BnagrochemF042EvidenceValidatorTest.php tests/Unit/DataQualityResolutionServiceTest.php tests/Feature/DataQualityDetectionTest.php tests/Feature/DataQualityApiTest.php tests/Feature/CorporateActionRepairSnapshotConcurrencyTest.php
```

## Normal #383 evidence and repair flow

The normal exchange sync requests the configured feed and maps a matching event into a pending `DataQualityIssue` through `DataQualityIssueService`. Its `exchange_ratio` evidence is retained as the raw feed payload and is only a discovery signal. An authenticated Admin can stage source evidence through `POST /api/data-quality/issues/{issue}/stage-bnagrochem-f042-evidence`, uploading the three source artifacts. The stager is restricted to stock 7096's exact pending exchange-feed issue, checks each complete file against its configured official SHA-256, validates the archive's inner CSV hash and the unique dated instrument row, and inserts evidence idempotently. It never changes issue status or prices; mismatched existing evidence is a conflict and is not overwritten. The Admin queue then supports independent human review. `POST /api/data-quality/issues/{issue}/accept` enters `DataQualityResolutionService`, which locks and rechecks the issue and stock, applies the F042-specific evidence validator, records the human resolution, and stages a `PriceAdjustmentFactor`. A successful acceptance stages factor metadata with `ohlcv_repair_status=pending`; it does not edit prices. The existing factors-only repair command supports a scoped dry-run and, only under separate production authorization, an apply. Completed repair rollback uses the audited Admin endpoint and exact captured before-images.

For stock 7096, `exchange_ratio` alone is insufficient. Acceptance requires one exact row for each configured official item: NSE Listing circular CML77412, NSE Clearing circular CMPT77438, and the Nov. 13 official NSE archive observation with both archive and raw-CSV hashes plus the unique instrument row. The validator checks configured URLs, hashes, identity, ex-date, and row values; it rejects missing, duplicate, changed, or unknown evidence keys while preserving any generic `exchange_ratio` discovery rows, and it forbids auto-accept. The clearing circular corroborates identity and does not establish the price divisor. The committed test fixtures are the genuine PDFs and ZIP archive from the retained source review; their hashes match the catalogue, and the decompressed CSV hash and exact row were independently checked before staging implementation. The supplied issue says stock 7096 currently has no DQ issue or factor; no production query was made to independently inspect that state.

The normal flow is exchange sync → pending discovery issue → authenticated Admin evidence upload and hash verification → human review and acceptance → factor staging → scoped standard repair dry-run → separately authorized apply if selected → exact rollback through the Admin endpoint if required. Until the genuine bundle is staged and reviewed, acceptance must return `DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED`; no factor is staged and no price row changes. Never bypass this gate with hand-written factor/action rows or direct SQL price edits. The #358 historical-gap evidence remains separate and unresolved by factor acceptance.

## Verification in this sandbox

- SQLite focused rollback/API tests: 29 passed, 94 assertions in the supplied rehearsal; after this candidate's evidence API and concurrency regressions were added, the focused rerun reports 46 tests, 38 passed, 154 assertions, and 8 MySQL-only concurrency tests skipped. The end-to-end case uses the normal exchange-sync mapping (with its provider request mocked), genuine official source fixtures, and verifies upload idempotency, preservation of generic ratio evidence, human acceptance, factor-only staging, standard repair, exact Admin rollback, stale acceptance refusal, and unchanged restored price/volume.
- Migration portability: passed for 204 migrations.
- Native rehearsal: previous host artifact confirms the disposable price-only MariaDB restore and exact 142-row restoration; the supplied log confirms 29 application tests / 94 assertions on MariaDB. This candidate adds deterministic race cases and updates the rehearsal to assert server identity, socket propagation to child workers, and run those cases; that expanded rehearsal has not been run in this sandbox, where private socket creation is denied. Independent host follow-up on 2026-11-26 at 19:33 IST passed all 48 focused tests (231 assertions), including deterministic separate-worker races, on a private socket-only disposable MariaDB 10.11.14. The exact fixture SHA-256 was acbea5ec602bf11b9c2d539bab0f17930453054949ece791b6fd6e7cd8834a4b (149 rows: 142 pre-split, 7 post-split). No production writes occurred.
- No live DB/services, production configuration, credentials, Strategy/ML/trading replay, or network were accessed.

**ACTION REQUIRED FROM YOU**

- Hosted CI and production deployment gates remain required before operational acceptance.
- Run the Admin upload on the actual pending production issue only after verifying the exact stock/date/ratio and obtaining the separate production write authorization for any later repair apply. The upload itself only stages verified source hashes and pending evidence; it does not accept, repair, or edit prices.
