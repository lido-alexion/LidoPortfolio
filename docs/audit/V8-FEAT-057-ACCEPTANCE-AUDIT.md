# FEAT-057 ML Feature Engineering, Training & Validation — reconciliation audit

Status: **REVIEW**

This is a code-reconciliation checkpoint against `docs/archive/specs/V8-ML-Feature-Engineering-Training-Validation-Specification.md`. Existing inherited ML code is useful V7/V8 foundation, but it is not treated as complete merely because training and admin endpoints exist.

| Requirement | Status | Evidence / finding |
|---|---|---|
| Versioned machine-readable feature registry | VERIFIED locally | `MlFeatureRegistryService` now emits stable IDs, formula version, source, transformation, lookback, horizons, sector applicability, tier, missing policy, PIT classification, deprecation field and definition hash |
| Immutable feature-set versioning | PASS locally | `MlFeatureRegistryService::resolveFeatureProfile` emits deterministic feature-set ID/version, ordered keys, per-feature versions, preprocessing identity, exclusions and definition hash; invalid/duplicate/ineligible requests fail loudly |
| Balanced V8 candidate catalogue | PASS locally / runtime coverage pending | The registry is machine-checked at 50/50 implemented across technical/price, fundamental, market/regime, breadth, sector-relative and deterministic-pattern groups; pattern features are explicitly classified (`consolidation_width_20d_pct`, `range_position_20d`, `candle_body_to_range_1d`). `docs/audit/V8-FEAT-057-FEATURE-MATRIX.md` records the per-feature frozen mapping; bounded per-horizon runtime coverage is now persisted per feature and partition, while authoritative production population remains open |
| Horizon-specific applicability | PASS locally for current catalogue | Every registered feature declares eligible horizons; resolver filters/validates against the canonical ordered implemented set and rejects ineligible requests. Current catalogue intentionally permits the present implemented families across all three horizons pending evidence-based pruning |
| 1m / 3m / 6m sampling | PASS locally | `MlTrainingDatasetBuilder` now emits versioned horizon-aware sampling metadata and selects the last available trading day per ISO week for 1m, and per month for 3m/6m; `MlTrainingDatasetBuilderTest` covers all three policies and label-safe streamed builds |
| Current active/eligible training universe | PASS locally | Dataset universe now requires active NSE stocks, excludes benchmarks and requires price history |
| PIT fundamentals | VERIFIED locally | Fundamental facts are filtered by availability date and latest known revision per period/as-of row |
| PIT prices and labels | VERIFIED locally | Features use as-of unadjusted prices; labels use adjusted prices only in the forward label window |
| PIT market breadth / sector context | PASS locally / production population pending | `ConfiguredHistoricalUniverseProvider` reads an immutable dated archive, maps only to canonical NSE securities, preserves provider symbol/token/exchange and response version, rejects malformed/duplicate/unknown identities, and never falls back to the current universe. `MlHistoricalUniverseMembershipService::backfillFromProvider` persists durable runs, retry counts, failed dates and explicit empty coverage. Provider/backfill tests cover transient retry, permanent failure, identity validation and idempotent materialization. Only production archive population remains external |
| Intraday/minute boundary | PASS by search | No minute/order-book/microstructure features are wired into FEAT-057 builder; FEAT-065 remains separate |
| Missing-value policy | PASS locally | Resolved profile pins `v8-preprocessing-1`; isolated ML runtime tests prove training-only median/missingness handling, categorical unknown handling, profile/list mismatch rejection, persisted preprocessing state, and prediction schema alignment |
| Outlier handling / redundancy handling | PASS locally | The versioned `v8-outlier-policy-1` applies conservative 1st/99th percentile clipping only to economically tail-sensitive growth, leverage and valuation features. Bounds are fitted from training rows only, persisted in the model preprocessing state, reused at inference, and never applied to naturally bounded features by default. Redundancy diagnostics remain training-only, persist threshold/fit partition/high-correlation pairs, and do not silently prune features. Python adapter regression tests and the bounded 1m/3m/6m campaign cover the contract. |
| Logistic baseline | PASS by architecture | `MlScoringService` persists `interpretable_logistic_baseline` model path |
| Gradient-boosted challenger | PASS by architecture | Challenger evidence and `hist_gradient_boosting_challenger` path exist |
| Secondary return regressor | PASS locally and in bounded campaign | The bounded 1m/3m/6m campaign records the challenger evidence and return-regressor status for every horizon; skipped remains an explicit insufficient-label outcome rather than an invented model |
| Chronological validation | PASS locally / bounded campaign verified | Partitioned chronological builder, horizon-derived forward label windows, explicit `purge_embargo` metadata, label-overlap purging, and `MlChronologicalValidationGridService` tests exist. The environment-configurable bounded campaign completed 1m/3m/6m through the real adapter with non-empty folds |
| Repeated windows / regime slices | PASS locally | Monthly test-window grid and benchmark-volatility slices are covered |
| Probability calibration | PASS locally / external deployment evidence pending | The same-architecture bounded campaign completed calibration for all three horizons and persisted adapter calibration metadata; production runtime remains external |
| Active-model comparison | PASS locally for unavailable branch / paired-active runtime pending | `MlCandidateEvidenceService` persists same-horizon active-model identity, feature-set identity, validation split, metrics, comparability and metric deltas. The bounded campaign explicitly recorded `no_active_model_for_horizon`; only same-window comparison against an actually active deployed model remains pending |
| Deterministic StoX baseline comparison | PASS locally and in bounded campaign | The baseline file is evaluated against the exact test partition; the bounded 1m/3m/6m campaign asserted comparable persisted baseline evidence |
| Promotion evidence | PASS locally | Candidate/rejected lifecycle, thresholds, challenger evidence and explicit Admin promotion gate exist |
| Explainability | PASS locally / investor-facing browser acceptance pending | `MlExplainabilityService` maps model-native contributions through the pinned feature profile, excludes dropped/ineligible inputs, preserves feature versions/horizon and produces ranked signed drivers. The matching x86_64 bounded campaign now reloads each persisted logistic artifact and asserts non-empty native contribution output; investor-facing browser acceptance remains pending |
| Candidate archive integrity | PASS locally | Immutable evidence archives pin model version, feature/dataset identity, artifact reference and evidence checksum; `MlCandidateArchiveIntegrityService` reports evidence tampering, missing artifacts and artifact checksum mismatch without falling back to current registry metadata |
| Dataset/model feature-set identity | PASS locally and in bounded campaign | Dataset `feature_definitions.resolved_feature_profile` and model `audit_metadata.feature_profile` pin horizon, registry, feature keys/versions, preprocessing and definition hash; the environment-configurable bounded campaign asserted these identities independently for 1m/3m/6m |
| FEAT-056 boundary | PASS by architecture | Scheduling, deployment and live drift remain in FEAT-056 services rather than dataset construction |

## FEAT-057 issue #18 implementation evidence

The repository now includes `NseHistoricalUniverseArchiveProvider` and `ml:backfill-nse-universe`. The provider prefers dated MII files, falls back to dated cash bhavcopy, parses legacy and UDiFF layouts, filters `EQ`/`BE`/`BZ` company equities and `INF` fund/ETF identifiers, maps ISIN-first then symbol, rejects mapping below 90% and rejects empty/excluded-only files, validates requested dates from official filenames/content dates, and records quality diagnostics on snapshot boundaries. It uses the existing membership, boundary, and durable backfill-run lifecycle; no current-universe fallback is present. Range backfills resolve only dates present in StoX market history, excluding weekends and active trade holidays; explicit `--dates` remains exact. The feature registry is `v8-registry-13`, and historical sector construction resolves only dated membership evidence.

Focused evidence is covered by `NseHistoricalUniverseArchiveProviderTest` (legacy/UDiFF parsing, ISIN rename, ETF/fund exclusion, mapping-floor rejection, missing-date/no-fallback, idempotent/resumable backfill) and `MlHistoricalSectorLeakageTest` (historical sector, unknown sector, and null relative strength behavior). Production archive population and acceptance remain intentionally open.

## Verified implementation slice

Horizon-aware sampling, dated membership snapshots, explicit horizon profiles, dataset/model feature-set identity, explicit historical snapshot coverage diagnostics, resumable historical snapshot backfill, horizon-derived purge/embargo evidence, training-only missing-value/outlier preprocessing, immutable candidate-evidence archival and archive integrity verification are implemented and tested. With the matching x86_64 Python runtime, the bounded campaign now executes the real adapter/training path for **1m/3m/6m** and passes with **1,449 assertions**, including challenger/regressor evidence, persisted per-feature train/validation/test coverage, training-only outlier bounds and reload-time native contribution output. Implementation is complete to the current repository boundary; status is **REVIEW** pending production provider population, paired comparison against a deployed active model where applicable, and investor-facing browser acceptance.

Latest evidence: `MlTrainingDatasetBuilderTest` **9/9** (576 assertions) covers weekly/monthly sampling, active-universe exclusion, label purging, context coverage, membership coverage, and persisted resolved profiles; `MlFeatureRegistryAdminTest` covers deterministic profiles and invalid requests; `MlCandidateEvidenceTest` covers paired active/baseline evidence, no-active semantics and pinned explainability; the combined focused ML/PIT/provider group passes **31/31** (675 assertions), including snapshot coverage/backfill/provider/bounded-query tests; isolated `ml_adapter.py` contract tests pass **13/13** under the matching x86_64 runtime, including training-only redundancy and outlier-policy tests. With that runtime, `MlBoundedTrainingCampaignTest` completed real 1m/3m/6m campaigns and persisted candidate evidence/archive rows: **1/1, 1,449 assertions**. The campaign used bounded deterministic price fixtures, recorded no-active-model explicitly, asserted challenger/regressor and same-window deterministic-baseline evidence, persisted per-feature coverage for train/validation/test partitions, persisted training-only outlier bounds and reloaded each artifact to verify native contribution output; dated-provider population, active-model pairing where an active model exists and investor-facing browser acceptance remain open. The full Feature suite under local PHP 512 MB is now **1,346 passed / 1,347 total / 1 skip / 0 failures** with **11,014 assertions** under the matching x86_64 runtime; the remaining skip is the normal-CLI OpenTelemetry extension check. The earlier FEAT-064 fixture cascade was repaired without weakening the readiness gate. The OpenAPI artifact has been regenerated and its contract test passes.
Runtime note: PHP is x86_64 under Rosetta on an arm64 host. A matching x86_64 Python 3.11.2 environment with NumPy 2.4.6, scikit-learn 1.9.1 and joblib 1.6.0 passed the PHP→adapter train smoke path, including logistic fitting, boosted challenger fitting, calibration, artifact creation and structured response handling. The virtualenv is external and not committed.


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Dated membership preview | IN PROGRESS | Build `ef66133c`, VPS: governed run #1 had 332/360 processed, zero failed, one queued acceptance job and zero boundaries at 2026-10-01 18:31:37 UTC. One-shot worker was present; no duplicate run/worker/apply/training started here. |
| Apply, provenance/mapping per date, build identity and 1m/3m/6m preflight | NOT YET RUN | Existing continuation owns preview/apply. Recheck 360 digests and each >=90% map, 360 immutable boundaries and current-build campaign before qualification. |
| Production adapter/training, archive, pairing, investor browser ML | BLOCKED (upstream data/runtime) | Campaign `996fa344-2533-4bf0-a555-9b052de2c8cb` blocked; FEAT-054 bootstrap only began with 3 Yahoo-only stocks. Never train until explicit preflight success. |

### Preview completion verification — 2026-10-01 18:36 UTC

Read-only DB aggregation on deployed build `ef66133c`: run #1 status `completed`, preview cursor 360, exactly 360 result entries and 360 source entries, 360 processed/360 requested, 0 failed, 0 membership boundaries and 0 queued acceptance jobs. Minimum per-date canonical mapping was **94.2266%**, with **zero** dates below 90%. Every result had snapshot and source SHA-256 and matching requested/validated date (zero mismatches by the checked fields). **PASS for preview diagnostics only.** The existing continuation owns apply; apply and campaign/training remain NOT YET RUN here.

### Governed apply failed closed — 2026-10-01 18:37–18:40 UTC

Another continuation invoked apply on the successful preview run #1 at 18:37:57 UTC, then cancelled at 18:40:10 UTC after first-date failure. Read-only verification at 19:17 UTC: status `cancelled`, mode `apply`, 0 processed, failed date `2022-11-04` with one recorded attempt, 0 membership boundaries, one unreserved queue job and no matching worker. Recomputing the first sealed source at 19:19 UTC produced the same 94.2266% mapping but a **different membership SHA-256** (`303515db…` preview versus `95bb97b8…` current), hence a different snapshot digest. The service rejects changed mapping/provenance after preview. **FAILED apply with safety gate intact; no partial membership write.** Do not resume this cancelled run or reuse its preview. Diagnose security-master/mapping drift or parser determinism with the NSE operator, then use a new governed preview on a stable build and data identity. The queued job must be reconciled before another worker starts.


## Issue #18 correctness follow-up — 2026-10-02 (local only)

Latest master already provided PIT sector lookup, challenger classification and the NSE archive/backfill architecture. This follow-up pins sector source/formula metadata in registry `v8-registry-13` and parser `nse-pit-universe-parser-3`. It rejects conflicting ISIN/symbol identities, validates cached official CSV dates, preserves provider source/SHA identity on memberships and boundaries, and persists success/rejection mapping diagnostics per date in backfill-run `source_diagnostics`. No rejected snapshot is materialized. Regression fixtures exercise actual historical feature construction with sufficient price history and changing current-master sectors.

Production data/source acquisition and acceptance were not performed in this task. Existing boundaries are immutable: upgrading does not rewrite them. Operators must review earlier evidence under its recorded parser/registry identity; fresh acceptance must use the new identity and the [production procedure](../current/ml-production-acceptance.md#issue-18-historical-universe-operations).

The legacy `ml:capture-universe-membership` command is restricted to today’s date. Past/future `--effective-from` values fail before membership or boundary writes; historical population must use dated-source backfill.


## Historical identity investigation — 2026-10-03

Status remains **REVIEW**. Correction to the initial investigation: this checkout
runs as `nitty` on `stoxla-prod`; production is locally readable. The failed
attempt to SSH to the same host was an investigator error, **not an access
blocker**. The initial SSH-blocked classification has been replaced by the
independently verified findings below. Production bootstrap reads used a MySQL
**read-only transaction**; source/stock diagnostic copies and evidence downloads
were written only to scratch. No production worker, preview, apply, training,
data mutation or service change was performed.

### Verified production identities and state

| Check | State | Evidence |
|---|---|---|
| Build | PASS, read-only verification | `2f89e574f65342f037b8d84ae50b7ccbdf606160`, build `build-391-attempt-1-2f89e574f65342f037b8d84ae50b7ccbdf606160`. Checkout fetched and matched `origin/master` at investigation start. |
| Campaign | BLOCKED in production | `996fa344-2533-4bf0-a555-9b052de2c8cb`, cutoff `2026-10-01`. Its 1m/3m/6m union contains exactly **360** reference dates. |
| Governed run | CANCELLED | Run **3**, mode `preview`, cursor **0**, processed **0**, failed date `2022-11-04`. All **360** source IDs resolve to sealed objects; unique source dates equal both requested dates and campaign reference-date union. |
| First source | PASS, resolved from run 3 | `f85aabce-a346-41e8-bd60-643d3d7ee628`, `cm04NOV2022bhav.csv.zip`, date `2022-11-04`, **86,543** archive bytes. Original archive size/hash independently matched manifest SHA-256 `df02c39caf5bb6ffb97f43b6f76d67d4486d4483d38969d16e9b519483104d66`; extracted CSV SHA-256 independently matched `483beea8f0ea3ea980e4a183b1457d6f86644767343181babcc0617ce5895001`. |
| Parser v3 mapping | FAILED quality gate; independently reproduced | **1,583 / 1,836 = 86.2200%**, **253** unmatched, exactly matching run diagnostics. Earlier v2 **1,730 / 1,836 = 94.2266%** remains historical evidence; the **147** row difference is now traced to conflicting-symbol ISIN fallback. |
| Membership boundaries | PASS, safety preserved | Exactly **1** boundary: `2026-10-01`, `forward_official_nse`, **2,576** members; unrelated to cancelled run 3. No historical dates committed by run 3. |
| Acceptance runtime | PASS, read-only state check | `stoxla-ml-acceptance.service` inactive; **0** acceptance workers; **1** queued acceptance job, **0** reserved jobs. Job was not consumed or removed; reconcile before a future authorized worker start. |

### Exact classification of the 253 rejected source rows

The [253-row ledger](data/FEAT-057-run3-identity-classification.csv) records every
historical symbol/ISIN, factual v3 rejection, candidate stock ID/ISIN and evidence
disposition. It contains security identifiers only, not user data or private paths.
Classification distinguishes a query result from proof of a corporate action.

| Disjoint disposition | Count | Finding |
|---|---:|---|
| No current NSE identity candidate, no authoritative disposition acquired | **105** | No eligible current NSE non-benchmark row matched historical ISIN or symbol. These are unknown to this master, not proven nonexistent companies. Listing, rename, delisting and restructuring evidence remains required. |
| No current NSE candidate; amalgamation suspension verified | **1** | `HDFC / INE001A01036`. NSE/CML/57423, published 2023-07-04, suspends HDFC equity trading effective 2023-07-13 for amalgamation. Retain a separate historical security identity; do not alias it onto HDFC Bank solely because of the merger. |
| Valid dated ISIN subdivision; historical alias absent | **1** | `NESTLEIND`, `INE239A01016` → `INE239A01024`, canonical production stock **1295**; evidence and bounded correction below. |
| Subdivision lead with revised-date evidence requiring reconciliation | **1** | `HAL`, `INE066F01012` → candidate `INE066F01020`. Initial NSE/CML/58539 says 2023-09-29; MSE/LIST/14216/2023 revises to 2023-09-28. No HAL alias is added pending the revised NSE effective-date evidence. |
| Same-symbol/nonempty-ISIN conflict, continuity or reuse unproven | **145** | Candidate identity is not sufficient proof. No aliases inferred from names, symbols or shared ISIN prefixes. |
| **Total** | **253** | Factual resolver split: **106** no candidate + **147** conflicting symbol/ISIN. |

**Reused symbols: 0 proven among these 253; actual count unresolved.** A conflict
is not automatically a reused symbol. **Incorrect master identity: 0 proven**;
master repair requires authoritative evidence and downstream-reference review.
**Missing historical aliases: 1 proven** (Nestlé), already counted above; this is
not an additional row. The other **252** rejected rows remain unmapped by the
draft, with different acquisition/remediation needs recorded in the ledger.

Separately, **4 v3-mapped source rows** each have **2 current NSE non-benchmark
master records with the same ISIN**. These are outside the 253 rejected rows:

| Historical symbol / ISIN | Candidate stock IDs / symbols |
|---|---|
| GUJGASLTD / INE844O01030 | 744 / GUJGASLTD; 7148 / GUJENERGY |
| LYPSAGEMS / INE142K01011 | 1126 / LYPSAGEMS; 7354 / AURUS |
| SANGINITA / INE753W01010 | 7252 / SANGINITA; 7710 / AGASTYAEN |
| SILLYMONKS / INE203Y01012 | 1728 / SILLYMONKS; 7693 / CRESTO |

Duplicate identity candidates are proven; which canonical ID to retain is not.
Do not choose `first()`, merge price histories, or delete stock rows automatically.
The draft rejects these ambiguous identities until separately reviewed remediation.

### Authoritative evidence and smallest correction

The reviewed evidence registry is
`app/config/ml_nse_historical_identities.php`, version
`nse-historical-identities-1`. It currently contains **only Nestlé**:

- The sealed 2022-11-04 NSE bhavcopy proves the historical symbol and old ISIN.
- [NSE/CML/60084](https://archives.nseindia.com/content/circulars/CML60084.pdf),
  published 2024-01-02, establishes a share subdivision and new ISIN effective
  **2024-01-05**. Downloaded SHA-256:
  `4dda660aea17bdedd1e429b23c0d775a55ee5ee6f38a649904faabc37ef1ad0e`.
- [Nestlé 2023–24 annual report filed with NSE](https://archives.nseindia.com/annual_reports/AR_24138_NESTLEIND_2023_2024_15062024233329.pdf)
  explicitly connects the old and new ISINs to the subdivision. Downloaded SHA-256:
  `a2bd86b483d58de5cb6fac36113ff1851d39d14d666c33f24c8cac382f180dfa`.

The correction is deliberately bounded to **2022-11-04 through 2024-01-04**, not
the security's inferred entire lifetime. The annual report confirms security
continuity retrospectively; it does not supply a historical financial feature,
sector, price or membership. Every membership still needs contemporaneous source
evidence on its own date. Only a unique NSE non-benchmark canonical ISIN candidate
may resolve. Missing evidence, overlapping evidence intervals, wrong historical
symbol, out-of-range dates and missing/duplicate canonical targets fail closed.
The existing conflicting-ISIN symbol guard remains in place. No merger chaining,
name similarity, partial-ISIN matching or raw-price changes are introduced.

Parser identity becomes `nse-pit-universe-parser-4`; snapshots include the reviewed
identity-registry digest, historical identity mapping count and bounded structured
unmapped-reason counts. These bind evidence changes into existing preview/apply
snapshot digests. Duplicate ISIN matches now fail closed. Explicit CSV escape
arguments preserve existing backslash semantics while suppressing PHP 8.4 CSV
deprecations. Public diagnostics strip `nse_source_file` as well as existing
private identifiers/paths; durable reason counts remain available on failures.

Additional authoritative classification references:
[HDFC suspension, NSE/CML/57423](https://archives.nseindia.com/content/circulars/CML57423.pdf);
[initial HAL circular, NSE/CML/58539](https://archives.nseindia.com/content/circulars/CML58539.pdf);
[HAL revised date, MSE/LIST/14216/2023](https://www.msei.in/SX-Content/Circulars/2023/September/Circular-14216.pdf).
The HAL documents are a reason to acquire the revised primary-exchange circular,
not permission to use the superseded date.

### Verification and acceptance limits

| Check | State | Result |
|---|---|---|
| Focused provider/identity regressions | PASS on final draft | **26 tests, 107 assertions**, isolated in-memory DB. Covers valid dated subdivision, interval edges, reused symbol, absent evidence, overlapping aliases, duplicate current/target ISINs, unchanged quality gate and sanitized diagnostics. |
| Draft replay using scratch source/master copies | PASS, rejection expected | **1,580 / 1,836 = 86.0566%**, **256** rejected: **106** absent current candidates, **146** conflicting ISINs without applicable evidence, **4** ambiguous current ISINs. One Nestlé mapping recovered; four unsafe duplicate matches removed. Replay uses an isolated in-memory database, not a production preview. |
| Backend CI parity | PASS | Initial prerequisite failure (`pdo_sqlite` absent) was resolved for CLI tests by extracting the matching extension into scratch and using a process-local PHP INI scan directory. Full PHPUnit: **2,143 tests, 2,141 passed, 2 skipped, 13,709 assertions** (about 24 minutes). OpenAPI is current for **219 operations**. No system PHP package or service was changed. Migration portability passed for **168** migrations; Python contracts reported **20** tests with **8** existing skips. Canonical verifier uses a separate MySQL **8.4** container and database `feat057_identity_ci`, loopback port **33357**, never the production DB. |
| Production preview/apply/training/deployment | NOT RUN | Explicitly outside this investigation's permitted production actions. |

The unchanged 90% floor requires **1,653** mapped members. The draft still needs
**73** additional proven mappings on this first date. Passing this date would not
establish the other 359 dates or production acceptance. FEAT-057 stays REVIEW;
no automatic promotion or lifecycle enablement is authorized.

### Remaining acquisition and remediation

1. For the **145** unresolved same-symbol conflicts, acquire dated NSE ISIN-change
   and corporate-action circulars with old/new ISIN, effective date, document ID,
   URL and hash. Reconcile amendments against adjacent dated security files or
   bhavcopies. Differentiate subdivisions from restructuring, merger/demerger,
   cancellation/reissue and true symbol reuse. Each unsupported row stays unknown.
2. For **HAL**, acquire the revised NSE ex-date notice and corroborating source
   dates before adding an interval. For **105** no-candidate identities, acquire
   historical listing/rename/delisting notices and locate or propose a distinct
   historical canonical security. **HDFC** needs a historical-master remediation,
   not an alias to its acquirer. No production master changes are made here.
3. Review all **4 duplicate ISIN pairs** against dated official rename/listing
   evidence and downstream stock references. Prepare a separate reviewed canonical
   identity repair; preserve raw prices and frozen provenance.
4. Extend the reviewed identity registry only with proven continuity, conservative
   date bounds and evidence hashes; add interval/reuse/absence regressions per
   supported action type. Re-run backend CI parity, fetch/reconcile and diff checks
   before pushing the branch and draft PR. Do not bypass a failing gate.
5. Later authorized production acceptance must reconcile the queued cancelled-run
   job, use a fresh governed preview on the reviewed build, prove each of all
   **360** dates reaches 90%, and follow explicit apply/preflight/1m–3m–6m training
   gates. Do not resume cancelled run 3 or reuse v2/v3 digests with parser v4.

## 2026-10-03 — official evidence acquisition and offline 360-date checkpoint

**FEAT-057 remains REVIEW.** This section supersedes the first-stage unresolved
counts above; it does not replace the recorded production failure or claim a
production acceptance pass.

### Published baseline and production identity

The supervisor published the exact tested first-stage tree
`0fadb4169141376c826fa84e31fc285980dc2b50` as commit
`9203632fd7daaa3b247160c32a8073b67ac5700c`, draft
[PR46](https://github.com/lido-alexion/LidoPortfolio/pull/46). The full backend gate
reported above **passed** on that tree. CI run `37090544041` was queued at the
supervisor update; its eventual GitHub result is not asserted here. Original local
commit `58a173358b5b124d2a6f8fc85bfec66cb60498f5` remains preserved. This stage uses
`feat057-evidence-stage` based on the published commit. No second publication or
PR was attempted; the supervisor owns publication of the next reviewed change.

The independently read production build was
`2f89e574f65342f037b8d84ae50b7ccbdf606160`, campaign
`996fa344-2533-4bf0-a555-9b052de2c8cb`, cutoff **2026-10-01**, cancelled preview
**run 3**, cursor **0**, **zero dates committed**. Source IDs were resolved from
run 3 rather than the transcribed prompt. First source:
`f85aabce-a346-41e8-bd60-643d3d7ee628`, **2022-11-04**, archive SHA256
`df02c39caf5bb6ffb97f43b6f76d67d4486d4483d38969d16e9b519483104d66`.
Production is locally readable on `stoxla-prod`; SSH and production credentials
were not needed. The earlier SSH blocker is invalid. CLI SQLite support was
supplied from scratch for isolated checks; it is not a remaining test blocker.

All **360** sealed source files were copied once into scratch and content hashes
verified. Their dates exactly match run 3 and the campaign horizon-date union.
One read-only stock query copied **2,637** NSE master rows for indexed offline
lookups. No repeated per-row production lookups, production writes, queue changes,
service changes, workers, previews, applies, training or deployment occurred.
The unrelated **2026-10-01 `forward_official_nse`** boundary remains separate.
The cancelled-run retry job was neither consumed nor removed.

### Evidence acquired and reviewed

The first requested checkpoint covered **10 identities**, **11 transitions** and
**23 circulars**, including the two VBL subdivisions. Its historical checkpoint is
[data/FEAT-057-evidence-batch01.json](data/FEAT-057-evidence-batch01.json).
Subsequent batches used six bulk NSE circular indexes (October 2022 through the
campaign cutoff) and the official corporate-action report. The latter is discovery
only: several retrospective rows retain an earlier ISIN and cannot prove the
ISIN immediately before a later split.

The final acquisition manifest records **357 URLs: 354 acquired, 3 failed URL
attempts retained visibly**. Two failed archive-mirror URLs were recovered at their
original indexed `nsearchives.nseindia.com` URLs (CMPT57871, CML61690); the third
was an unused incorrect CMPT58618 lead (404), replaced by the correct HAL notice
CMPT58637. No required active-registry proof depends on a failed download.
[Acquisition manifest](data/FEAT-057-evidence-acquisition.json) retains URLs,
SHA256, attempt status and ZIP-member hashes. Original bytes and extracted pages
remain in the scratch acquisition corpus; no giant raw source/master snapshots
are committed.

The reviewed registry adds **140 bounded historical intervals** for **135 issuers**
using **140 explicit subdivision transitions**. Together with the published
Nestlé interval, there are **141 active intervals for 136 issuers**. DEVIT,
GEEKAYWIRE, SERVOTECH, SHRADHA and VBL require two connected, strictly dated links.
Both historical legs are represented where observed. Each interval begins at its
first sealed historical observation and ends before the documented trading change.
The registry includes official old/new event evidence, document dates, pages,
short excerpts, hashes and explicit amendment references. Runtime validation
rejects incomplete/reversed chains, invalid dates, wrong endpoints and missing
old/new evidence. Exact historical symbol and ISIN remain mandatory; canonical
stock lookup remains unique-ISIN-only. Parser identity is now
`nse-pit-universe-parser-5`, with the complete registry hash still bound into
snapshot diagnostics/digests. The current registry digest is
`51681c4b952c2347bc10c0abd3acdf7cb574b0b2815dbd9f7b3edfd3ab7ca43c`.

**HAL date reconciled:** CML58633 explicitly modifies CML58539 from September 29
to **September 28, 2023**. Clearing circular CMPT58637 independently states that
ex-date. The old HAL interval ends **2023-09-27**. RPPL similarly retains CML63920's
original September 18 date and CML63980's explicit correction to **2024-09-17**.
Neither correction silently overwrites the original document date.

The existing portfolio corporate-action model/sync and frozen F020/F042/F043
facilities were inspected. They govern portfolio events, quality issues and price
repair; they do not authorize historical security-master continuity by symbol.
This change does not call them, add a schema, alter prices or reinterpret mergers.

### Exact classification and remaining remediation

[Per-row classification and next actions](data/FEAT-057-evidence-classification.csv)
accounts for all **253 original v3 rejections** without denominator changes:

| Classification | Count | Disposition |
|---|---:|---|
| Fully proven subdivision aliases | 136 | Nestlé plus 135 newly evidenced issuers; active bounded registry intervals |
| Absent current identity; official disposition unresolved | 105 | Acquire historical listing/rename/delisting and complete ISIN chain; propose distinct historical master identities with downstream-reference review |
| Absent HDFC historical identity | 1 | Official amalgamation suspension already acquired; distinct historical-master remediation, never alias to HDFCBANK |
| Proven subdivision with duplicate current target | 1 | HEG: target INE545A01024 exists as HEG **781** and HEGAM **7712**; no active alias or arbitrary survivor |
| Trading-date reconciliation still required | 3 | EASEMYTRIP, FILATEX, SSWL: new-ISIN notices state different w.e.f. and trade ex-dates; acquire clarification and adjacent dated identity files before registering |
| Contradictory official old ISIN | 1 | LIKHITHA: CMPT54656 prints INE060X01018, while sealed LIKHITHA is INE060901019; require corrected notice or issuer filing explicitly connecting the correct endpoints |
| Capital reduction / insolvency restructuring | 4 | BURNPUR, EASTSILK, MBECL, SUMEETINDS: official recommencement ZIPs acquired; require full cancellation/reissue terms and a reviewed canonical-security decision |
| Consolidation chain incomplete | 2 | KAUSHALYA, VERTOZ: suspension/resumption and new ISIN acquired, but explicit old/new consolidation link and interval semantics remain outside this subdivision-only registry |

**True symbol reuse: 0 proven; incorrect canonical survivor: 0 proven.** These are
not claims that reuse or incorrect identities are absent. Search collisions were
explicitly rejected, including FILATEX/FILATFASH, GLOBAL/VGL and
KAMDHENU/KAMOPAINTS. Same issuer prefix, name, symbol, split headline or current
identity alone never authorized an alias.

The **four original duplicate pairs**, outside the 253 rejected rows, remain
GUJGASLTD/GUJENERGY (**744/7148**), LYPSAGEMS/AURUS (**1126/7354**),
SANGINITA/AGASTYAEN (**7252/7710**) and SILLYMONKS/CRESTO (**1728/7693**).
HEG adds a **fifth duplicate pair** discovered through the new canonical target.
All need separately reviewed authoritative rename/listing evidence and downstream
reference analysis before master remediation. No row was merged/deleted, no target
was selected by order, and no current price series was changed.

[Reviewed but inactive transitions](data/FEAT-057-reviewed-inactive-transitions.json)
retain HEG's proven split, VERTOZ's first split, and SUMEETINDS's later split without
pretending their incomplete/ambiguous paths reach a usable canonical target.
An automatic approval review rejected a draft generation step that would admit
non-unique targets into the active registry. The safer final generator retained
the unique-target requirement; no duplicate override was performed.

### Offline checkpoints and verification

| Check | State | Evidence |
|---|---|---|
| Published PR46 full backend gate | PASSED | 2,143 tests, 2,141 passed, 2 skipped, 13,709 assertions; isolated MySQL |
| PR46 full 360-date mapping replay | FAILED mapping coverage on 72 dates | 288 pass; first date 1,580/1,836 = 86.0566%; worst additional need 73. [Baseline ledger](data/FEAT-057-PR46-360-date-replay.csv) |
| New reviewed evidence full 360-date replay | PASSED offline mapping floor | **360/360** dates; minimum **93.4096%**; largest remaining gap to 90% **0**. [New ledger](data/FEAT-057-evidence-360-date-replay.csv) |
| Actual PHP provider replay parity | PASSED | Eight dates: first two, indices71/72/100/200/300 and final date; mapped, unmatched, source denominator and alias counts equal the offline ledger |
| Focused PHP regressions | PASSED | **29 tests, 706 assertions**; all registry interval boundaries, multistep chains, missing links/proof, reversed order, invalid date, explicit HAL amendment, existing reuse/duplicate/reason sanitization checks |
| Python contracts | PASSED | **22 tests, 8 existing skips**, from app working directory; includes cache-integrity retry, HTML failure and public-URL acquisition checks |
| New-stage full backend CI parity | PASSED | Canonical repository verifier: **2,146 tests, 2,144 passed, 2 skipped, 14,309 assertions**, PHPUnit **2,066.304 seconds**; migration portability **168 migrations**; Python **22 tests / 8 existing skips**; OpenAPI **219 operations** current. Isolated MySQL8.4 `feat057_identity_ci` on loopback33357; scratch PHP INI only. Remote CI remains to be run on the supervisor-published commit |
| Production preview/apply and 1m/3m/6m training | NOT RUN | Explicitly prohibited for this investigation |
| Production acceptance / promotion | BLOCKED pending governed runtime evidence | Offline mapping success does not establish production acceptance |

On **2022-11-04**, the final offline resolver maps **1,715/1,836 (93.4096%)**,
with **136** dated-identity mappings and **121** rejected members: **106** absent
current candidates, **11** unresolved/conflicting candidates (including HEG's
blocked target), and **4** ambiguous current ISINs. The floor still requires
**1,653**, so the scratch result is **62** above it. No member was dropped from
the denominator to obtain that result. Across the corpus: **751,359** eligible
date-memberships, **729,735** mapped, **21,624** unmatched, including **20,733**
dated-alias mappings. Rejections total **14,218** absent candidates, **5,654**
conflicting identities and **1,752** ambiguous current ISINs. Per-date unmatched
counts range from **10 to 121**. BDL and SDBL retain old ISIN observations on
**2024-05-24**, their documented change date; those two observations remain
rejected outside the proven intervals. No interval was stretched to absorb them.

The resumable workbench is documented in
[app/scripts/nse_identity/README.md](../../app/scripts/nse_identity/README.md).
It only downloads public sources and replays scratch copies. Its PHP export calls
the actual evidence validator without bootstrapping Laravel or opening a DB.
Acquisition outputs never write the reviewed registry automatically.

After supervisor publication and verified CI, a separately authorized production
sequence must reconcile the queued cancelled-run job, deploy the exact verified
SHA through the existing release process, create a **fresh governed preview** on
all 360 campaign dates with parser5/evidence digest, review each date's diagnostics,
and satisfy apply/preflight and all horizon training/acceptance gates. Run3 stays
cancelled; earlier parser/preview digests must not be reused. FEAT-057 stays REVIEW.

## Governed multi-date apply lifecycle correction — 2026-10-03

FEAT-057 remains **REVIEW**. Production evidence from build
`6d2963cc4aac15fb9612c79f8a16368ad220f1e1` / build392 supersedes the earlier
not-run preview entry: run4 preview **PASSED 360/360 dates**, minimum **93.4096%**,
with all source bytes/digests checked, no retries and no membership writes.
Its authorized apply was **BLOCKED after 1/360 dates**, despite the durable run
reporting `completed`. Only **2022-11-04** materialized: **1,715 memberships**,
matching the preview digest. Cursor1 and 359 unprocessed dates remained; the
first unprocessed date is **2022-11-11**. This is a lifecycle failure, not a
mapping rejection. All 360 preview results remain intact. Post-apply preflight
and training were **NOT RUN**.

The nested historical-membership backfill reused the governed run ID and marked
its one-date batch complete. The outer Eloquent instance still considered
`queued` and null `completed_at` its original values, so its final save omitted
those columns. The next job observed the prematurely completed row and returned.
The CLI also incorrectly treated that status alone as success.

The correction reloads the shared run immediately after nested materialization,
inside the existing transaction and distributed write lock. The final governed
save is therefore authoritative for the full cursor, status and completion time.
A reload is smaller than factoring a new membership-only API and leaves
standalone backfill behavior unchanged; the nested transient state remains
inside the atomic transaction. A shared completion check additionally requires
all requested dates, cursor, processed dates, sources and snapshot evidence
before accepting apply or reporting CLI success. No parser, identity evidence,
configuration, threshold, raw-price, stock-master or resume-policy change is made.

The isolated regression uses actual sealed CSV sources, preview/snapshot
services and dispatched backfill-job handlers across two dates. On the unmodified
merged base, the first apply unit persisted `completed` instead of `queued`;
a fresh-preview recovery fixture failed likewise. The initial seven regression
cases all failed, including incomplete-preview acceptance and CLI false success.
The final focused suite **PASSED 41 tests / 361 assertions**, including ten new
lifecycle/completeness cases. The required `./scripts/verify-ci.sh --backend`
**PASSED** on isolated MySQL8.4 (`127.0.0.1:33357`, database
`feat057_apply_fix_ci`): **2,156 tests, 2,154 passed, 2 existing skips,
14,502 assertions**; PHPUnit duration **1,757.804 seconds**. Migration portability
passed for **168 migrations**, Python passed **22 tests / 8 existing skips**, and
the OpenAPI contract check passed. Scoped Pint and `git diff --check` passed.
This is local implementation verification, not production qualification.

### Recovery design — proposed, not executed

Keep production run4 immutable; do not broaden completed-run resume, force its
status with SQL, clear locks, repeat apply4 or delete its valid first boundary.
After a reviewed fix and verified release, create one **fresh current-build
campaign** for cutoff2026-10-01 and verify its persisted date union. Create a
fresh governed preview over the same 360 sealed sources. Existing provenance
checks must confirm the first boundary's source and mapped-membership hashes;
conflicting provenance still blocks. Review every new preview result, then apply
that new preview through the existing supported command. The first matching
boundary is idempotently skipped while the governed cursor advances; the
remaining 359 dates can materialize. The regression verifies that this path
preserves the old partial run, its first boundary and its memberships.

A fresh post-apply campaign/preflight must subsequently establish all frozen
membership, breadth, feature/fundamental, sector and dataset gates. Training may
only use a ready current campaign. New FEAT-054 coverage blockers remain unknown
until that preflight actually runs. This code-only phase performs no production
operation and adds no recovery API.
