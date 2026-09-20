# V7-REQ-002 — Fundamental Data Runtime Verification

## 1. Finding Recap

`V7-REQ-002` was initially `RUNTIME_VERIFICATION_REQUIRED` after local implementation and feature tests established the canonical V7 fundamentals model. The first production verification found a Yahoo PHP/Guzzle transport failure and an empty canonical store. That historical finding is retained below; the managed yfinance remediation has now been deployed and verified successfully.

## 2. Accepted Contract

The authoritative V7 contract requires an ongoing provider-independent fundamentals capability with Yahoo/yfinance as the implemented adapter. It includes:

- canonical quarterly and annual income, balance-sheet, and cash-flow facts;
- explicit consolidated/standalone basis and reporting-period metadata;
- provider, first-fetch, availability, and revision provenance;
- immutable restatement history;
- availability-aware point-in-time reads;
- Admin-controlled freshness defaults of 5 months quarterly and 15 months annual;
- explicit missing/stale/unavailable behavior, never silent zero substitution;
- derived StoX-owned metrics with explicit basis semantics;
- resumable, rate-limited, idempotent incremental updates with visible failures;
- Admin/API/UI coverage and scheduled operation.

The deep historical bootstrap is explicitly outside this V7 requirement. V4-FEAT-054 was moved to V8 and may later populate arbitrary history using the same canonical model. Its absence is therefore an accepted limitation, not the current blocker.

## 3. Deployed Implementation

The verified active release is `3500f4c07aecddba6ee23f9d7f909664e99b4f24`, and runtime health is green. The fundamentals migration `2026_09_12_100001_v7_stox_fundamentals_and_ml` is applied. The deployed provider path is:

```text
YahooFundamentalDataProvider
  -> Symfony Process
  -> scripts/yahoo_fundamentals.py
  -> managed shared yfinance venv
```

The managed runtime is Python 3.12.3 with `yfinance==1.7.0`. A same-host TCS quarterly probe succeeded. Canonical PHP normalization, availability, revision, freshness, and persistence remain authoritative.

Authoritative objects and paths are:

- `stox_fundamental_facts`: canonical facts and immutable revisions;
- `stox_fundamental_settings`: provider, freshness, rate, retry, and pause policy;
- `stox_fundamental_update_runs`: run-level status and aggregate evidence;
- `stox_fundamental_update_jobs`: bounded per-stock/cadence work and retry state;
- `FundamentalDataService`: as-of selection, revisions, derived metrics, and freshness;
- `FundamentalUpdateService`: incremental, bounded, resumable processing;
- `YahooFundamentalDataProvider`: provider boundary and normalization;
- `/api/v1/stocks/{stock}/fundamentals` and Admin fundamentals endpoints;
- `stox:fundamentals-update --batch=20`, scheduled hourly at minute 30.

## 4. Production Inventory

The initial pre-remediation production inventory returned zero facts and is retained as historical evidence: provider `yahoo`, no source rows, and no current-data coverage. After deployment and normal processing, the current inventory is:

| Measure | Result |
| --- | --- |
| Fundamental fact rows | 2,530 |
| Stocks with fundamental records | Populated; exact stock count is operational inventory data |
| Provider/source distribution | `yahoo`; transport evidence `yfinance` |
| Period/as-of date range | Quarterly and annual income, balance-sheet, and cash-flow facts present |
| Ingestion timestamp range | Populated by successful post-deployment runs |
| Revision counts | Representative facts at revision 1 and current |
| `is_current` distribution | Representative stored facts are current |
| Configured provider | `yahoo` |
| Quarterly freshness | 5 months |
| Annual freshness | 15 months |
| Paused | false |
| Request delay / attempts | 750 ms / 3 |

The final production state has usable current canonical data. The provider's coverage is not universal: ordinary issuer cases such as AARNAV can remain explicit provider no-data failures, rather than being silently converted to zero or treated as successful facts.

## 5. Provider Runtime

The original production provider was `YahooFundamentalDataProvider` using the PHP/Guzzle Yahoo cookie/crumb transport. Production returned `fc.yahoo.com` 404, no cookie, and `getcrumb` 429/401 Invalid Cookie. That transport was retired. The same production VPS successfully returned TCS quarterly financials through the managed yfinance runtime, and the replacement is now active.

Normal production ingestion subsequently succeeded:

- one run processed 18 and succeeded 18, with 0 failed and 0 skipped;
- a later run processed 34 and succeeded 34, with 0 failed and 0 skipped;
- canonical facts reached 2,530;
- representative rows contain `provider=yahoo`, `source_meta.transport=yfinance`, `source_meta.availability_source=first_fetch_fallback`, `revision_number=1`, and `is_current=true`;
- quarterly and annual income, balance-sheet, and cash-flow facts were persisted.

The original failed runs remain historical evidence:

- runs `147` through `156` were left `running` with `Yahoo fundamentals request failed with HTTP 401`;
- the latest observed run was `156`, started `2026-09-19 09:30:04` server time;
- each observed run requested 40 jobs and processed 0 successfully;
- the job table contained 3,120 `queued` and 3,120 `retry` jobs;
- no successful source/as-of timestamp exists in the canonical facts table.

This was a concrete provider/runtime failure, not an evidence-only limitation. It is resolved in the deployed transport; no claim is made that every listed NSE/BSE issuer has Yahoo fundamental coverage.

## 6. Freshness and Missing-Data Semantics

The repository implementation distinguishes `missing`, `stale`, `sanity_rejected`, and `fresh` states, applies the configured quarterly/annual thresholds, and returns null/ineligible metrics when required facts are absent. The populated production store now demonstrates current-data usability and operational freshness processing. AARNAV quarterly/annual no-data responses remained explicit eligible-issuer provider failures with the normal retry budget; they did not become zero-valued success.

The API is deployed and canonical facts are available through the existing PHP service/API path. Historical unauthenticated-probe behavior remains an authorization/runtime concern outside this fundamentals closure; it is not used as evidence of provider success.

## 7. Revision and Point-in-Time Behavior

Production verified populated canonical facts, Yahoo/yfinance provenance, conservative first-fetch availability fallback, current-data ingestion, and the deployed scheduler/retry path. It did not independently exercise a live provider restatement sequence. Automated tests establish the revision semantics:

- identical payloads are deduplicated;
- changed values create a new revision and mark the prior revision non-current;
- `availability_date <= as_of` bounds reads;
- historical reads select the revision available at the requested date;
- current metrics resolve the latest applicable period/revision;
- metric freshness is returned separately from metric value eligibility.

Before deployment, the invalid share mappings were removed: `Repurchase Of Capital Stock` and legacy `commonStock` no longer map to `shares_outstanding`; `Ordinary Shares Number` remains the valid yfinance share-count source. Automated tests verify `A -> A` dedupes with the original availability date preserved, `A -> B` creates revision 2, and `A -> B -> A` creates revision 3 rather than incorrectly deduplicating against historical A. When yfinance provides no availability date, the conservative first-observed date remains the availability date for that revision. Representative production facts were revision 1/current, which verifies the populated provider/storage path but is not evidence of a live production restatement.

## 8. Historical Bootstrap Boundary

Deep historical fundamental population is explicitly V4-FEAT-054, moved to V8. That boundary is accepted by the V7 specification and remains an `ACCEPTABLE_VARIATION`, not a V7 blocker.

## 9. Scheduler and Failure Evidence

Production `schedule:list` shows:

```text
30 * * * * php artisan stox:fundamentals-update --batch=20
```

The command is bounded and uses the update service’s retry, rate-delay, per-job status, and run evidence. Benchmark eligibility is enforced: new runs exclude `is_benchmark=true`, and existing benchmark jobs are skipped before provider invocation without provider-failure alerting. Fresh run 160 had 40 non-benchmark jobs: 2 succeeded, 36 freshness-skipped, and 2 AARNAV provider no-data failures after the normal three-attempt budget, finishing `completed_with_errors`.

The final production lifecycle state is:

```text
jobs_queued=0
jobs_retry=0
jobs_running=0
jobs_failed=86
jobs_superseded=6240
jobs_completed=38
jobs_skipped=36
active_runs=0
facts=2530
```

The failed and superseded rows are retained historical evidence from the broken-provider/backlog period. There is no unfinished fundamentals work, and terminal historical failures are not treated as a V7 blocker.

## 10. Local Verification

Latest repository validation passed: the V7/fundamentals, scheduler, and unattended-operation suite passed **53 tests and 293 assertions**; Python adapter tests passed **4**; deployment contract tests passed **3**; PHP lint passed; and `git diff --check` passed. These tests cover immutable revisions, point-in-time resolution, missing-input behavior, provider/runtime errors, benchmark eligibility, retry/compaction/locking, Admin defaults/authorization, and ML integration boundaries. They supplement the production ingestion evidence and do not imply that a live production restatement sequence was exercised.

## 11. Gap Register

| ID | Finding | Classification | Severity |
| --- | --- | --- | --- |
| FND-001 | PHP/Guzzle Yahoo transport returned HTTP 401 and prevented acquisition | `IMPLEMENTED` | High (historical) |
| FND-002 | Production freshness, current-data usability, provenance, and the provider/storage path were unproven while the store was empty; live production restatement behavior remains test-backed rather than production-exercised | `IMPLEMENTED` for accepted V7 scope | Medium (historical) |
| FND-003 | Deep historical bootstrap is not populated | `ACCEPTABLE_VARIATION` | Low |

FND-001 is resolved by the managed yfinance transport and successful production ingestion. FND-002 is resolved for the accepted V7 current-data/provenance/freshness and automated revision-semantics scope; production did not need to manufacture a live restatement to satisfy the accepted contract. FND-003 remains explicitly outside V7 under V4-FEAT-054/V8.

## 12. Remediation Status

The repository remediation and production verification are complete for the accepted V7 scope:

- The PHP/Guzzle Yahoo cookie/crumb transport was retired after production returned `fc.yahoo.com` 404, no cookies, and `getcrumb` 429/401 Invalid Cookie. The same VPS successfully fetched `TCS.NS` quarterly data through `yfinance` 1.7.0, so `YahooFundamentalDataProvider` now invokes the managed Python adapter and keeps normalization, availability, revisions, and persistence in PHP.
- Scheduled incremental slices reconcile stale/exhausted jobs, resume the oldest unfinished work, respect `next_attempt_at` and `max_attempts`, finalize terminal parent runs, and use durable cache locks in addition to scheduler overlap protection.
- Incremental run creation skips stock/cadence work already owned by another unfinished incremental run. Backlog reconciliation now compacts duplicate unfinished incremental jobs by `(stock_id, cadence)`: it retains the newest viable job, marks redundant jobs `superseded`, preserves prior attempt/error evidence, clears their next-attempt time, and finalizes obsolete parent runs. Completed jobs and manual targeted runs are excluded. The operation is transactional, process-locked, and idempotent.
- Provider-wide failures now use the existing deduplicated operational-alert framework and retain the provider error on run/job evidence. The deployment provisions a shared Python virtualenv, pins `yfinance==1.7.0`, and the runtime health gate verifies the executable, adapter, import, and version without making an external Yahoo request.
- Fundamentals run creation excludes `is_benchmark` instruments, and existing benchmark jobs are terminalized as skipped/ineligible before provider invocation without raising the provider-failure alert. Ordinary issuer no-data responses retain the normal retry/failure lifecycle.

Latest validation passed: the V7/fundamentals, scheduler, and unattended-operation suite passed **53 tests and 293 assertions**; Python adapter tests passed **4**; deployment contract tests passed **3**; PHP lint passed; and `git diff --check` passed. No combined total is inferred across these separate test runners.

Production verification recorded active release `3500f4c07aecddba6ee23f9d7f909664e99b4f24`, green runtime health, Python 3.12.3, yfinance 1.7.0, successful same-host TCS probing, successful normal ingestion, populated canonical facts, benchmark exclusion, and an empty unfinished-work set. The historical failed/superseded backlog remains auditable and terminal.

## 13. Final Assessment

`V7-REQ-002 = IMPLEMENTED`.

The canonical implementation, managed provider runtime, point-in-time/revision logic, freshness policy, scheduled updater, benchmark eligibility, retry/backlog lifecycle, failure persistence, and production current-data ingestion are verified. The accepted V7 contract is satisfied with canonical provenance-bearing facts and no unfinished fundamentals work. Deep historical bootstrap remains the explicit V8 limitation, and ordinary issuer no-data cases remain visible operational failures rather than silent substitutions.
