# PHPUnit CI process crash investigation

## Evidence and diagnosis boundary

Three hosted backend attempts on `f8284b766fdf4410a85bb6083d8409f23776f358` ended in two adjacent tests in `NseHistoricalUniverseArchiveProviderTest`: the first two stopped at `test_official_archive_path_reuses_supported_downloader_and_records_archive_provenance`; the third reached `Test Prepared` for `test_valid_cached_csv_requires_matching_official_archive_before_it_can_be_sealed` after 2,705 completed cases, then ended before teardown or `Test Finished`. The third attempt's maximum RSS was 527,264 KiB, above the nominal 512 MiB PHP heap limit; RSS includes native allocations, so this does not by itself prove a PHP heap exhaustion. No signal was reported. The affected methods, their class, and the preceding 34-test NSE slice passed in isolated runs.

PHPUnit 12.5.28 sets the text `Premature end of PHP process when running …` while every test runs in-process. If PHP shuts down before that test returns, PHPUnit's shutdown handler prints the text and exits with status 2. It is a generic termination marker, not a diagnosis of OOM, `exit()`, process isolation, or the originating fatal error.

The repeated late-suite location and high process RSS support accumulated process memory as a credible risk. The root cause is not proven. The CI change is a bounded mitigation: it starts fresh PHP processes sequentially for complete, disjoint batches while retaining the full PHPUnit inventory, MySQL CI setup, and 512 MiB per-process limit.

## Partition 3 lifecycle failure

On draft PR416 head `ae067c0b6a0f21caf3a159061aefa02d41178d92`, hosted run `45724122330` (job `139589603303`) completed and inventory-verified partitions 1 and 2. Partition 3 ran 152 cases and exited 2 at maximum RSS 177,212 KiB, before reaching the previously observed archive test. Its two errors were `IndicatorSeriesParityTest::test_series_last_value_matches_single_evaluate_for_every_indicator` and `LiquidityTradabilityRuntimeTest::test_screener_runtime_can_evaluate_composites_without_catalog_ids`; both failed with `Target class [config] does not exist` through `FundamentalMetricCatalog::derivedMetrics()` while `ScreenerCatalog::indicatorIds()` built the catalog.

Both test classes extended bare `PHPUnit\Framework\TestCase` despite calling Laravel-backed catalog and evaluation services. Isolated runs reproduced both errors before any assertions. Changing those two classes to the repository's `Tests\TestCase` boots the actual application and test configuration; no catalog data is stubbed and no assertions or provider cases are removed. Under isolated SQLite, both exact methods then passed (1 test/156 assertions and 1 test/7 assertions), both complete classes passed (13/364), and adjacent screener/indicator registry tests passed (34/555).

The remaining partition 3 was not replayed locally: its inventory includes migration-backed `RefreshDatabase` tests and other integration-shaped tests, so running the complete batch with SQLite would not match hosted MySQL CI and could fail for dialect reasons. Its hosted low RSS and two deterministic test-bootstrap errors do not support an OOM diagnosis. The PHP-process partitioning remains a mitigation for the earlier termination pattern, not a proven root-cause correction; hosted CI on the reviewed candidate is still required.

## Partition and inventory contract

The hosted runner asks PHPUnit for its XML test inventory before running tests. That inventory includes concrete data-provider cases. The runner:

- validates every discovered test file is under the configured `Unit` or `Feature` suite;
- preserves PHPUnit discovery order and groups whole files into sequential batches of at most 300 cases, without mixing suites;
- runs each batch with the normal PHPUnit configuration and full assertions, with no filter or skip;
- records per-process resource usage and PHPUnit events;
- requires each successful batch's `Test Finished` event IDs to match its discovered inventory exactly; and
- stops and returns the first nonzero PHPUnit status, printing the final events for diagnosis.

The current inventory contains 2,919 cases across 464 test files and produces 11 batches (three Unit and eight Feature). The runner recalculates these from the checked-out PHPUnit configuration each run; it does not keep a static test list.

Each process continues to use the verifier's isolated MySQL service and the unchanged `memory_limit=512M`. A failed batch blocks the backend gate and the following OpenAPI check. The local non-GitHub invocation retains the previous single-process command.

Because PHP static state resets between batches, Laravel's `RefreshDatabase` may run `migrate:fresh` again when a new process first reaches such a test. This is expected to target only the disposable MySQL service configured by the hosted backend job and may increase runtime. Any batch failure caused by that process boundary must be investigated as a real CI failure; the runner does not retry or mask it.

## Verification still required

The focused Python regression tests cover provider-expanded inventory, deterministic partitioning, exact finished-case accounting, and failure propagation. They do not prove hosted memory pressure has been eliminated. The canonical hosted `./scripts/verify-ci.sh --backend` must pass on the exact reviewed head; inspect each partition's measured RSS and event output. Do not treat successful inventory planning or focused tests as a passing backend gate.
