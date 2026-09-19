# V7-REQ-002 — Fundamental Data Runtime Verification

## 1. Finding Recap

`V7-REQ-002` was `RUNTIME_VERIFICATION_REQUIRED` after local implementation and feature tests established the canonical V7 fundamentals model. Production verification found that the deployed scheduler and schema exist, but the configured provider is not currently producing usable data.

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

The deployed release is `671996b3244464c686fc4eab7e283364afd576c5`, and public build identity matches the active release. The fundamentals migration `2026_09_12_100001_v7_stox_fundamentals_and_ml` is applied.

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

Read-only production queries returned:

| Measure | Result |
| --- | --- |
| Fundamental fact rows | 0 |
| Stocks with fundamental records | 0 |
| Provider/source distribution | No rows |
| Period/as-of date range | None |
| Ingestion timestamp range | None |
| Revision counts | None |
| `is_current` distribution | None |
| Configured provider | `yahoo` |
| Quarterly freshness | 5 months |
| Annual freshness | 15 months |
| Paused | false |
| Request delay / attempts | 750 ms / 3 |

With no canonical facts, the production state is missing coverage rather than fresh or stale coverage. No current-data consumer can obtain a usable fundamental value from the deployed store.

## 5. Provider Runtime

The production provider is `YahooFundamentalDataProvider`, using Yahoo quote-summary statement endpoints. Recent hourly runs demonstrate repeated provider operation attempts but no successful acquisition:

- runs `147` through `156` were left `running` with `Yahoo fundamentals request failed with HTTP 401`;
- the latest observed run was `156`, started `2026-09-19 09:30:04` server time;
- each observed run requested 40 jobs and processed 0 successfully;
- the job table contained 3,120 `queued` and 3,120 `retry` jobs;
- no successful source/as-of timestamp exists in the canonical facts table.

This is a concrete provider/runtime failure, not an evidence-only limitation. No manual production sync was triggered; the existing scheduled attempts were sufficient to establish the failure without changing financial data.

## 6. Freshness and Missing-Data Semantics

The repository implementation distinguishes `missing`, `stale`, `sanity_rejected`, and `fresh` states, applies the configured quarterly/annual thresholds, and returns null/ineligible metrics when required facts are absent. Local tests cover missing required flow facts and freshness behavior. Production currently exercises the `missing` boundary because there are no facts; there is no populated production record from which to verify fresh/stale display behavior.

The API is deployed, but an unauthenticated probe to a stock fundamentals endpoint returned the application's generic HTTP 500 because the request passed through an auth redirect to an undefined `login` route. This did not expose data, but it is not usable current-data evidence and is outside the V7 provider closure.

## 7. Revision and Point-in-Time Behavior

Repository behavior and tests establish the intended semantics:

- identical payloads are deduplicated;
- changed values create a new revision and mark the prior revision non-current;
- `availability_date <= as_of` bounds reads;
- historical reads select the revision available at the requested date;
- current metrics resolve the latest applicable period/revision;
- metric freshness is returned separately from metric value eligibility.

Production cannot independently demonstrate revision behavior because the canonical fact table is empty. No revision data was fabricated for this audit.

## 8. Historical Bootstrap Boundary

Deep historical fundamental population is explicitly V4-FEAT-054, moved to V8. That boundary is accepted by the V7 specification. The current V7 blocker is narrower and more immediate: the ongoing provider-driven acquisition has not produced even current canonical facts, so current usability and operational freshness cannot be claimed.

## 9. Scheduler and Failure Evidence

Production `schedule:list` shows:

```text
30 * * * * php artisan stox:fundamentals-update --batch=20
```

The command is bounded and uses the update service’s retry, rate-delay, per-job status, and run evidence. However, the repeated 401 failures leave work in queued/retry states and no successful run has completed. The failure is persisted in `last_error`; it is not silently converted to zero facts. Operational visibility exists, but successful provider operation and current-data coverage do not.

## 10. Local Verification

`FundamentalDataIntegrationTest` and `MlScoringLifecycleTest` passed: **6 tests, 33 assertions**. These tests cover immutable revisions, point-in-time resolution, missing-input behavior, Admin defaults/authorization, and ML integration boundaries. They do not replace the failed production provider evidence.

## 11. Gap Register

| ID | Finding | Classification | Severity |
| --- | --- | --- | --- |
| FND-001 | Configured Yahoo provider repeatedly returns HTTP 401 in production; no canonical facts have been ingested | `PARTIALLY_IMPLEMENTED` | High |
| FND-002 | Production freshness, current-data usability, and revision behavior cannot be demonstrated with an empty canonical store | `PARTIALLY_IMPLEMENTED` | Medium |
| FND-003 | Deep historical bootstrap is not populated | `ACCEPTABLE_VARIATION` | Low |

FND-003 is explicitly outside V7 under V4-FEAT-054/V8. FND-001 is the closure blocker; FND-002 follows from the absence of data and should be reassessed after provider recovery.

## 12. Remediation Status

The repository remediation is complete and production-pending:

- Yahoo requests now establish and reuse a cookie/crumb session, send a centralized explicit User-Agent, and refresh the session once on HTTP 401/403 or Invalid Crumb before failing explicitly.
- Scheduled incremental slices reconcile stale/exhausted jobs, resume the oldest unfinished work, respect `next_attempt_at` and `max_attempts`, finalize terminal parent runs, and use durable cache locks in addition to scheduler overlap protection.
- Incremental run creation skips stock/cadence work already owned by another unfinished incremental run. Existing queued/retry work is not deleted; repeated scheduled invocations provide the recovery path and preserve run/job evidence.
- Provider-wide failures now use the existing deduplicated operational-alert framework and retain the provider error on run/job evidence.

The focused remediation suite passes **11 tests and 42 assertions**. The broader V7 fundamentals, ML, schedule, and unattended-operation suite passes **37 tests and 229 assertions** in the current repository state.

Production still requires deployment of this remediation, a successful normal Yahoo-backed update, representative canonical facts with provenance, and confirmation that the existing queued/retry backlog drains or reaches explicit terminal states.

## 13. Final Assessment

`V7-REQ-002 = PARTIALLY_IMPLEMENTED`.

The canonical implementation, point-in-time/revision logic, freshness policy, scheduled updater, and failure persistence are present and locally verified. Production schema and scheduling are deployed, but the configured provider is failing with HTTP 401, leaving zero fundamental records and no usable current-data coverage. The requirement cannot move to `IMPLEMENTED` until a normal provider run succeeds and produces representative canonical data with provenance.
