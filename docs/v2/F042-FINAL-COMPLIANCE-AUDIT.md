# F042 Final Compliance Audit

**Date:** 2026-08-09  
**Type:** Post-implementation compliance audit (read-only)  
**Authoritative sources:** `F042-DATA-QUALITY-SPEC.md`, `F042-POLICY-DECISIONS.md`, `F042-F043-BOUNDARY.md`, `F042-IMPLEMENTATION-GAP-MATRIX.md`  
**Code inspected:** current `DataQuality*` services/controllers/models/commands/tests; F020 `CorporateAction*`; F043 `CorporateActionPriceRepair*` (read-only)

**Restrictions observed:** No application code, tests, schema, specifications, or V1 governance were modified. F043 was not implemented.

---

## 1. Audit scope

Independent verification that V2 F042 hardening satisfies approved requirements before F043 starts.

Verified by:

1. Requirement-by-requirement code inspection
2. Test inventory and assertion review (35 F042 tests re-run: **35/35 pass**)
3. Call-graph / boundary inspection vs F020 and F043
4. Comparison of auto-accept eligibility against product-owner D1 policy text

The prior implementation report was **not** treated as authoritative.

---

## 2. Requirement compliance matrix

Status values: `PASS` | `PARTIAL` | `FAIL` | `NOT_APPLICABLE`

### Requirements (F042-R*)

| Requirement | Priority | Implementation | Test | Result | Evidence |
|-------------|----------|----------------|------|--------|----------|
| F042-R001 Exchange feed detection | MUST | Creates pending CA issues from JSON feed; no OHLCV write | Feature: `DataQualityDetectionTest::test_exchange_feed_creates_pending_issue` | **PASS** | `DataQualityCorporateActionSyncService`; schedule 20:15 |
| F042-R002 Heuristic gap detection | MUST | Last-2-bar gap scan; pending issues | Feature: heuristic create + no OHLCV mutation | **PASS** | `DataQualityCorporateActionHeuristicService` |
| F042-R003 Scheduled detection | SHOULD | Daily sync/detect/auto at 20:15/20:45/21:15 | No schedule assertion test | **PASS** | `routes/console.php` (omission of schedule test acceptable for SHOULD) |
| F042-R004 Duplicate pending prevention | MUST | Dedupe key stock+type+method+ex_date; `lockForUpdate` | Unit: dedupe test | **PASS** | `DataQualityIssueService::createOrRefreshPendingIssueForStock` — see §4 for first-insert race note (non-blocker) |
| F042-R004b Append evidence on re-detect | MUST | Appends evidence; preserves `suggested_ratio`/`detected_at`; may update `latest_suggested_ratio` | Unit: `test_repeated_detection_appends_evidence…` | **PASS** | IssueService lines 65–77 |
| F042-R005 Immutable detection on resolution | MUST | Accept/reject update status/applied_ratio only | Implicit via resolution tests; no dedicated AC004 field assert | **PASS** | `DataQualityResolutionService` update arrays |
| F042-R006 Evidence on create | MUST | `attachEvidence` on create | Detection + issue service tests | **PASS** | IssueService |
| F042-R007 Source traceability | MUST | method/source/payloads/timestamps | Detection tests + model fields | **PASS** | Issue schema + detectors |
| F042-R008 Manual accept/reject | MUST | Admin API + UI | Feature API accept/reject | **PASS** | Controller + `DataQualityCenterPage` |
| F042-R009 Resolution audit chain | MUST | Append-only resolutions with supersession | Unit + API history re-resolve | **PASS** | Resolution model/service |
| F042-R010 Conditional auto-accept | MUST | Feed-only eligibility; configurable days | `DataQualityAutoAcceptTest` (8 cases) | **PASS** | See §3 (NULL confidence nuance = non-blocker) |
| F042-R011 Adjustment factor on accept | MUST | Creates active factor; reject deactivates | Unit + API | **PASS** | `DataQualityAdjustmentFactorService` |
| F042-R011b Repair-pending handoff | SHOULD | `metadata.ohlcv_repair_status=pending`; queryable scope | Unit/API assert pending marker | **PASS** | Spec § text for R011b “Existing” is stale; **code matches policy** |
| F042-R012 Legacy CA migration | SHOULD | Dry-run default migration service | Feature dry-run test | **PASS** | `DataQualityLegacyCorporateActionMigrationService` |
| F042-R013 Append-only resolutions | MUST | New row per decision; no delete | Resolution tests show multiple rows on re-resolve | **PASS** | ResolutionService |
| F042-R014 Admin-only access | MUST | `admin` middleware on all `/api/data-quality/*` | Feature: 403 non-admin, 401 unauth | **PASS** | `api.php` + `EnsureUserIsAdmin` |
| F042-R015 Admin REST surface | MUST | dashboard/unresolved/history/show/accept/reject | Feature API tests | **PASS** | `DataQualityController` |
| F042-R016 Admin review UI | MUST | Queue + history pages; history sends `re_resolve` | UI present; API covers mutations | **PASS** | React pages |

### Acceptance criteria (F042-AC*)

| Requirement | Priority | Implementation | Test | Result | Evidence |
|-------------|----------|----------------|------|--------|----------|
| F042-AC001 Feed sync creates pending, no OHLCV | MUST | Yes | Detection feature | **PASS** | Feed fake HTTP test |
| F042-AC002 Heuristic ≥25% gap | MUST | Yes | Heuristic feature | **PASS** | Gap fixture 200→100 |
| F042-AC003 No duplicate pending | MUST | Yes | Unit dedupe | **PASS** | IssueServiceTest |
| F042-AC004 Detection fields immutable after accept/reject | MUST | Yes in code | No explicit post-accept field assert | **PASS** | Code path; test gap = non-blocker quality |
| F042-AC005 Evidence on create | MUST | Yes | Detection + unit | **PASS** | |
| F042-AC006 Admin accept/reject/modified | MUST | Yes | API + unit modified | **PASS** | |
| F042-AC007 Resolution audit append | MUST | Yes | Unit/API | **PASS** | |
| F042-AC008 Factor create / deactivate | MUST | Yes | Unit reject + accept | **PASS** | |
| F042-AC009 Evaluation blocks pending (score 0, reason) | MUST | Yes in `EvaluationEngine` | Guard unit only — **no EvaluationEngine feature test** | **PARTIAL** | Implementation correct; test coverage incomplete |
| F042-AC010 Non-admin 403 | MUST | Yes | Feature | **PASS** | |
| F042-AC011 Conditional auto-accept | MUST | Yes | AutoAcceptTest | **PASS** | Spec label still says DECISION_REQUIRED (doc drift) |
| F042-AC012 Legacy dry-run | SHOULD | Yes | Feature | **PASS** | |
| F042-AC013 Re-detect appends evidence | MUST | Yes | Unit | **PASS** | |
| F042-AC014 Pending stale → 409 | MUST | Yes | Unit + feature API | **PASS** | Code `DATA_QUALITY_STALE_RESOLUTION` |

---

## 3. Auto-accept compliance

### Implemented eligibility (verified in code)

```text
pending_review
AND detection_method = exchange_feed
AND exchange_match = true
AND suggested_ratio IS NOT NULL AND > 0
AND (confidence IS NULL OR confidence >= 1.0)
AND detected_at <= now() - threshold_days
```

Config: `DATA_QUALITY_AUTO_ACCEPT_DAYS` default **15** → `config('services.data_quality.auto_accept_days')`.  
Command: `--days=` override; null uses config.

### Policy checklist

| Check | Result |
|-------|--------|
| Heuristic never auto-accepts | **PASS** — filtered by `detection_method` + dedicated test |
| Low confidence never | **PASS** — `confidence < 1.0` rejected + test |
| Missing ratio never | **PASS** — SQL + helper + test |
| `exchange_match=false` never | **PASS** + test |
| Threshold configurable / default 15 / used | **PASS** — config + test `test_configurable_threshold_is_honoured` |
| Produces `auto_accepted` + policy metadata | **PASS** |
| Creates adjustment factor | **PASS** |
| Does not modify OHLCV | **PASS** — no price writes in accept path; tests assert |
| Does not invoke F043 | **PASS** — no call to `CorporateActionPriceRepairService` |

### Confidence rule nuance (non-blocker)

Approved policy table states eligibility includes **confidence ≥ 1.0**.

Implementation allows **`confidence IS NULL OR >= 1.0`**.

- Exchange-feed detector always writes `confidence = 1.0`.
- Literal reading of “≥ 1.0” would reject NULL; code treats NULL as eligible.
- This is a **defensive edge case**, not a silent stricter rule. Practical impact: negligible for normal feed-created issues.
- Classification: **non-blocker** (behaviour matches product intent for feed path; document as nuance).

---

## 4. Repeated detection compliance

| Check | Result | Notes |
|-------|--------|-------|
| Dedupe key stock/type/method/ex_date | **PASS** | Query + optional `whereDate(ex_date)` |
| No second issue on re-detect | **PASS** | Tested |
| Append evidence | **PASS** | Tested |
| Immutable original fields | **PASS** | `suggested_ratio`/`detected_at` preserved; `latest_suggested_ratio` may update |
| `captured_at` on evidence | **PASS** | Default `now()` |
| `detection_method` in payload | **PASS** | `attachEvidence` |
| `detection_run_id` in payload | **PASS** | When provided |
| Transaction boundary | **PASS** | Entire dedupe+append in `DB::transaction` |
| `lockForUpdate` | **PASS** for existing row | Prevents double-append races on existing pending |
| First-create race without unique index | **PARTIAL** | Two concurrent first inserts of same key can still race (empty `lockForUpdate` does not gap-lock on SQLite/MySQL without unique constraint). No unique index on pending dedupe key. Unlikely in single scheduler; **non-blocker**. |

---

## 5. Detection run compliance

| Check | Result |
|-------|--------|
| Run ID format `{command}:{uuid}` | **PASS** — `DataQualityIssueService::newDetectionRunId` |
| One ID per sync invocation | **PASS** — generated once in `syncFromExchangeFeed` |
| One ID per heuristic scanAllStocks | **PASS** |
| Propagated to all evidence in invocation | **PASS** — passed into `createOrRefreshPendingIssueForStock` |
| Persisted in evidence payload | **PASS** — tested |
| Survives resolution | **PASS** — evidence rows not mutated on accept |
| Entry points cover main detectors | **PASS** — sync + heuristic |
| Legacy migration run ID | **NOT_APPLICABLE** — not a detection command; SHOULD scope |

---

## 6. Concurrent resolution compliance

| Scenario | Result | Evidence |
|----------|--------|----------|
| Pending accept success | **PASS** | API test |
| Pending reject success | **PASS** | API test |
| Accept after already accepted → 409 | **PASS** | Feature + unit; code `DATA_QUALITY_STALE_RESOLUTION` |
| Reject after already rejected → 409 | **PASS** | Feature + unit |
| Stale pending mutation after other admin resolved | **PASS** (sequential model) | Same 409 path; `lockForUpdate` inside txn |
| Invalid resolution not created on 409 | **PASS** | Exception before resolution insert (status check after lock) |
| History `re_resolve=true` allowed | **PASS** | API + UI |
| `re_resolve` does not bypass admin auth | **PASS** | Still under `admin` middleware |

### Non-blockers

1. **409 body does not include fresh issue** — Policy suggested “409 with fresh issue”; actual response is ApiEnvelope `{ success:false, error:{code,message} }` only.
2. **Re-resolution notes not required** — Policy §4 DECIDED: “Require non-empty notes for re-resolution.” Controller still allows `notes` nullable when `re_resolve=true`. **PARTIAL vs policy detail**; core concurrency protection still **PASS**.
3. **Tests are sequential, not multi-threaded** — They prove stale-state rejection, not parallel DB races. Acceptable for practical compliance; not a functional FAIL.

---

## 7. F042/F043 boundary compliance

| F042 MUST / SHOULD | Result |
|--------------------|--------|
| Detect / govern issues | **PASS** |
| Create resolutions | **PASS** |
| Create adjustment-factor metadata | **PASS** |
| Mark `ohlcv_repair_status=pending` | **PASS** |
| Queryable pending repair (`scopePendingOhlcvRepair`) | **PASS** |
| Modify OHLCV | **PASS** (does not) — no StockPrice writes in F042 resolution |
| Invoke F043 | **PASS** (does not) — `CorporateActionPriceRepairService` has zero references to DQ/factors |
| Modify holdings/transactions for price repair | **PASS** (does not) |
| Marker misread as repaired | **PASS** — F043 does not read marker today; value is `pending` not completed |

Boundary is **clean**.

---

## 8. Pipeline gating compliance

Guard: `issue_status = pending_review` only (`DataQualityGuardService`).

| Status | Blocked? | Verified |
|--------|----------|----------|
| pending_review | Yes | Guard unit test |
| accepted | No | Guard + pipeline test |
| rejected | No | Guard unit |
| auto_accepted (`issue_status=accepted`) | No | Pipeline gating feature test |

Consumers using guard:

| Consumer | Uses guard? |
|----------|-------------|
| DiscoveryEngine | Yes — `blockedStockIdMap` |
| EvaluationEngine | Yes — score 0 + `data_quality_pending_review` |
| ScreenerRunService | Yes |
| PatternScanService | Yes |
| RelativeStrengthService | Yes |
| RecommendationGenerationPipeline | Yes |

Invariant **UNBLOCKED ≠ OHLCV_REPAIRED**: tested in `DataQualityPipelineGatingTest` and accept OHLCV tests.

---

## 9. Authorization compliance

| Path | Unauth | Non-admin | Admin |
|------|--------|-----------|-------|
| Dashboard / mutations | 401 | 403 | 200 |
| `re_resolve=true` | 401 | 403 | 200 (when allowed by status) |

`re_resolve` is **not** an authorization bypass — only relaxes pending-status precondition after admin middleware.

---

## 10. Audit-trail compliance

| Lifecycle event | Auditable? | Notes |
|-----------------|------------|-------|
| Original detection | Yes | Issue fields + evidence |
| Repeated detection | Yes | Appended evidence + run IDs |
| Manual accept | Yes | Resolution + `resolved_by` |
| Modified accept | Yes | `modified_accepted` |
| Reject | Yes | `rejected` |
| Auto-accept | Yes | `auto_accepted` + `auto_accept_policy` metadata |
| Re-resolution | Yes | `is_reversal`, `supersedes_resolution_id` |
| F043 repair-pending handoff | Yes | Factor metadata marker |

Sufficient to reconstruct lifecycle for ops/audit. Before/after OHLCV intentionally absent (F043).

---

## 11. Test-quality assessment

| Area | Quality | Notes |
|------|---------|-------|
| Auto-accept positives/negatives | **Strong** | Eligible + 5 negative cases + threshold |
| Repeated detection | **Strong** | Dedupe + immutability + run IDs |
| API auth | **Strong** | Admin / non-admin / unauth |
| Stale resolution | **Adequate** | Sequential 409; not parallel |
| Pipeline / OHLCV invariant | **Adequate** | Guard + unblock≠repair |
| EvaluationEngine AC009 | **Weak** | Guard unit only; reason string untested at engine level |
| True concurrent insert race | **Absent** | No unique constraint test |
| Would fail if impl removed? | **Mostly yes** | Critical paths have asserting tests |

Overall: tests are **substantive**, not superficial. Main gaps are EvaluationEngine integration and true concurrency.

---

## 12. F020 regression assessment

F042 changes touch DQ services, controller, history UI (`re_resolve`), config, and tests — **not** `CorporateActionService` apply/preview paths.

Pre-existing F020 SQLite UNIQUE failures on `portfolio_stock_prices` during metrics/RS side-effects remain. They match prior final V1 audit classification.

| Question | Answer |
|----------|--------|
| F042 altered F020 intended behaviour? | **No** (code inspection) |
| Failures are F020 regressions from F042? | **No** |
| Affects F042 completeness? | **No** |

---

## 13. Existing test failures

Re-classified after this audit session (F042 suite green: 35/35).

| Test | Failure | Root cause | Introduced by F042? | Affects V1? | Affects F042? | Affects F043 readiness? |
|------|---------|------------|---------------------|-------------|---------------|-------------------------|
| `CorporateActionApiTest::test_preview_and_apply_split_via_api` | 500 UNIQUE stock_prices | Metrics/RS history fetch insert race on SQLite | **NO** | Non-blocker fixture | **NO** | **NO** |
| `CorporateActionServiceTest` (3) | Same UNIQUE | Same | **NO** | Non-blocker | **NO** | **NO** |
| `StockPriceHistoryServiceTest::test_growth_and_relative_strength_calculation` | RS null vs 10.0 | Pre-existing analytics fixture/env | **NO** | Debt | **NO** | **NO** |
| Other suite failures from prior baseline (`TransactionStockResolverTest`, `RelativeStrengthServiceTest` construct) | Fixture/env | Pre-existing | **NO** | Debt | **NO** | **NO** |

---

## 14. F043 readiness assessment

**Yes — F042 is a sufficiently clean foundation for F043 to begin.**

F043 can later consume:

| Artifact | Available? |
|----------|------------|
| `PriceAdjustmentFactor` row | Yes |
| `applied_ratio` / divisor / multiplier | Yes |
| `effective_ex_date` | Yes |
| `stock_id` / `issue_id` | Yes |
| `detection_method` / `detection_source` | Yes (metadata) |
| `ohlcv_repair_status=pending` | Yes + queryable scope |
| Evidence / resolution audit via issue_id | Yes |

**Missing for F043 (document only — do not add now):**

- F043 today does **not** read factors (expected — F043 work)
- No explicit link from factor → F020 `portfolio_corporate_actions.id` (may use stock+ex_date+ratio)
- No “repair completed” writer yet (F043 responsibility)

None of these block starting F043 design/implementation.

---

## 15. Genuine remaining F042 gaps

### Non-blockers (do not prevent F042_COMPLIANT_WITH_NON_BLOCKERS)

1. History re-resolution does not enforce non-empty notes (policy §4 detail)
2. 409 response lacks fresh issue payload (policy UX detail)
3. NULL confidence treated as auto-accept eligible (edge vs literal ≥ 1.0)
4. No unique DB constraint on pending dedupe key (theoretical first-insert race)
5. No EvaluationEngine feature test for AC009 reason string
6. Spec document drift: §17 / R011b “Existing” / AC011 label still partially outdated vs code

### Out of scope (correctly deferred)

- F043 OHLCV repair / factor consumption
- Non-CA detection types
- Profile-scoped issues
- Notifications / hard publish gates

---

## 16. Final verdict

### **F042_COMPLIANT_WITH_NON_BLOCKERS**

**Rationale:**

- All **MUST** functional requirements and critical acceptance criteria are implemented and verified in code.
- Auto-accept policy D1, repeated detection D2, concurrent 409 D4, handoff marker D5, and pipeline gating D6 are satisfied.
- F043 boundary is clean; no OHLCV mutation; no F043 invocation.
- Remaining items are policy UX details, test-depth gaps, theoretical concurrency without unique index, and documentation drift — **not** incomplete MUST behaviour.

F042 is ready for product acceptance and for **starting F043** as a separate initiative.

---

## Cleanup resolution (2026-08-09)

The following non-blockers from this audit were subsequently fixed in a limited cleanup:

| Item | Status |
|------|--------|
| History re-resolution requires non-empty notes | **Resolved** — `DataQualityController` validation + API tests |
| AC009 EvaluationEngine automated coverage | **Resolved** — `DataQualityEvaluationGatingTest` |
| Spec §17 / R011b Existing / AC011 label drift | **Resolved** — documentation alignment in `F042-DATA-QUALITY-SPEC.md` |

Still remaining non-blockers (intentionally not fixed):

| Item | Status |
|------|--------|
| Unique index on pending dedupe key | Documented known limitation |
| Optional 409 fresh-issue response body | Acceptable as-is (`DATA_QUALITY_STALE_RESOLUTION`) |

---

## 17. Files changed

| File | Action |
|------|--------|
| `docs/v2/F042-FINAL-COMPLIANCE-AUDIT.md` | **Created** (this audit) |
| `DOCS.md` | **Updated** — index entry for this audit |

No application code, tests, migrations, APIs, frontend, F042 specification content, policy decision content, or V1 governance were modified for this audit beyond DOCS indexing.

*Note: A later limited cleanup may update application code/tests/spec separately; see cleanup report if present.*
