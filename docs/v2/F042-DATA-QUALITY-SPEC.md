# F042 — Data Quality Detection & Resolution

**Capability ID:** F042  
**Status:** V2 — specification draft (reconciliation with existing implementation)  
**Date:** 2026-08-09  
**Governance:** Deferred from V1 per SD-035 (`MVP_SCOPE.md`)  
**Related:** V1 F020 (Corporate Actions), V2 F043 (Corporate Action Price Repair)  
**Policy decisions:** [F042-POLICY-DECISIONS.md](./F042-POLICY-DECISIONS.md)

---

## 1. Purpose

F042 provides **administrative detection, evidence retention, human/automated resolution, and pipeline gating** for market-data quality problems affecting platform trust.

In the current repository, F042 is implemented as the **Data Quality Center** — initially scoped to **corporate-action-related market-data anomalies** (suspected splits/bonus/face-value events detected from exchange feeds or OHLCV discontinuities).

F042 does **not** mutate raw OHLCV price bars. It governs whether unresolved anomalies block analytics pipelines and records accepted corporate-action ratios as metadata for downstream ops (F043).

---

## 2. Scope

### In scope (V2 F042 — evidence-based)

| Area | Current behaviour |
|------|-------------------|
| **Detection** | Scheduled corporate-action detection via exchange JSON feed and overnight OHLCV gap heuristic |
| **Issue storage** | Generic issue/evidence/resolution schema (`portfolio_data_quality_*`) |
| **Classification** | `issue_type = corporate_action` only; detection method, source, confidence, ex-date, ratio fields |
| **Evidence** | Append-only evidence rows keyed by detection outputs |
| **Resolution** | Admin accept (with optional modified ratio), reject, auto-accept stale pending, reversal via superseding resolutions |
| **Audit** | Append-only resolution chain with resolver user id, notes, timestamps |
| **Governance metadata** | Active `portfolio_price_adjustment_factors` rows on accept (metadata only today) |
| **Pipeline gating** | Block stocks with `pending_review` issues from discovery, evaluation, screener, pattern scan, RS, recommendations |
| **Admin UI** | Data Quality Center queue + Corporate Action History |
| **Admin API** | `/api/data-quality/*` (Sanctum + admin middleware) |
| **Ops CLI / scheduler** | Sync, scan, auto-resolve, legacy migration commands |
| **Production ops** | `deploy/cpanel-data-quality-center.php` browser wrapper |

### Out of scope (explicit)

| Item | Owner |
|------|-------|
| User-facing corporate action apply (split/bonus preview/apply) | **V1 F020** |
| OHLCV price bar mutation / historical price repair | **V2 F043** |
| Generic OHLCV validation (missing bars, impossible OHLC, stale prices unrelated to CA) | **Not implemented** — future enhancement only |
| Hard data publish gates (PB-001) | V1_OUT_OF_SCOPE |
| Profile-scoped issues | **Not implemented** — issues are **stock-global** |
| Non-admin user access | **Not implemented** — admin-only |

---

## 3. Non-Goals

- Replace V1 `CorporateActionService` / `/corporate-actions` user workflow (F020 remains authoritative for portfolio ledger changes).
- Perform price repair or back-adjust OHLCV history (F043).
- Provide a general-purpose reference-data quality platform (strategy-engine §5 aspirational model is not F042).
- Send user notifications about data-quality issues (no alert channel wired).
- Auto-apply corporate actions to portfolio holdings (accept records governance decision only).

---

## 4. Relationship to V1 F020

| Dimension | V1 F020 | V2 F042 |
|-----------|---------|---------|
| **Primary actor** | Portfolio operator (profile-scoped) | Platform admin |
| **Purpose** | Apply known split/bonus to **holdings and transactions** | Detect **suspected** corporate actions from market data; govern review |
| **Data mutated** | `portfolio_transactions`, holdings, `portfolio_corporate_actions` | DQ issue/resolution/evidence tables; adjustment-factor metadata |
| **OHLCV** | May trigger metrics side-effects; does not systematically repair history | Reads OHLCV for detection; **does not write** price bars |
| **UI** | `CorporateActionPage.jsx` | `DataQualityCenterPage.jsx`, `CorporateActionHistoryPage.jsx` |
| **Coexistence** | Legacy manual CAs can be **migrated into DQ audit** via `portfolio:migrate-legacy-corporate-actions` without removing F020 UI |

**Flow relationship:**

```text
Market data anomaly suspected ──→ F042 issue (pending_review) ──→ admin accept/reject
                                                                        │
User knows corporate action ──→ F020 apply (holdings correct) ──────────┘ (parallel paths)
                                                                        │
Accepted F042 ratio metadata ──→ (future) F043 price repair ──→ OHLCV corrected
```

F042 **informs** ops; F020 **corrects portfolio ledger**. They are complementary, not substitutes.

---

## 5. Relationship to V2 F043

| Dimension | F042 | F043 |
|-----------|------|------|
| **Detects** | Suspected CA from feed/heuristic | Misadjusted OHLCV after applied `CorporateAction` |
| **Mutates OHLCV** | No | Yes (`CorporateActionPriceAdjustmentService`) |
| **Uses DQ issues** | Creates/manages issues | No direct dependency today |
| **Uses adjustment factors** | Writes on accept | Does not read them today |
| **Operator surface** | Data Quality Center | Repair commands + `deploy/cpanel-repair-corporate-action-prices.php` |

See [F042-F043-BOUNDARY.md](./F042-F043-BOUNDARY.md) for full boundary rules.

---

## 6. Domain Concepts

| Concept | Definition | Storage |
|---------|------------|---------|
| **Issue** | A detected data-quality problem requiring review | `portfolio_data_quality_issues` |
| **Evidence** | Immutable supporting facts captured at detection | `portfolio_data_quality_issue_evidence` |
| **Resolution** | An append-only decision event (accept/reject/auto/migrated) | `portfolio_data_quality_issue_resolutions` |
| **Issue status** | `pending_review`, `accepted`, `rejected` | Issue row (mutable summary) |
| **Detection method** | `exchange_feed`, `heuristic_gap_detector`, `legacy_manual` | Issue row (immutable after create) |
| **Adjustment factor** | Governance record of accepted CA ratio (divisor/multiplier) | `portfolio_price_adjustment_factors` |
| **Blocked stock** | Stock with any `pending_review` issue | Computed by `DataQualityGuardService` |

**Current issue types:** only `corporate_action` (`DataQualityIssue::TYPE_CORPORATE_ACTION`).

---

## 7. Detection Requirements

### F042-R001 — Exchange feed detection (MUST)

| Field | Value |
|-------|-------|
| Requirement | System SHALL ingest a configurable external JSON corporate-actions feed and create pending `corporate_action` issues for matched portfolio stocks. |
| Priority | MUST |
| Existing | `DataQualityCorporateActionSyncService`, `portfolio:sync-corporate-actions`, daily 20:15 schedule |
| Gap | SPEC_MISSING (behaviour undocumented in formal spec until this document) |
| Evidence | `config/services.php` → `data_quality.corporate_actions_feed_url`; `SyncCorporateActionsCommand` |

**Current behaviour:**
- Feed URL from `CORPORATE_ACTIONS_FEED_URL` env; empty URL → error result, no issues created.
- Supports split, bonus, face_value_split action types with ratio parsing.
- Sets `exchange_match = true`, `confidence = 1.0`, stores `raw_payload`.
- Does not modify OHLCV.

### F042-R002 — Heuristic overnight-gap detection (MUST)

| Field | Value |
|-------|-------|
| Requirement | System SHALL scan active non-benchmark stocks for overnight close→open discontinuities exceeding a threshold and matching common split ratios, creating pending issues with confidence and evidence. |
| Priority | MUST |
| Existing | `DataQualityCorporateActionHeuristicService`, `portfolio:detect-corporate-action-anomalies`, daily 20:45 |
| Gap | NO_GAP |
| Evidence | Compares last 2 `portfolio_stock_prices` bars; default `minGapPercent = 25%`; common ratios `[2,3,5,10,1.5]` |

### F042-R003 — Scheduled detection after market sync (SHOULD)

| Field | Value |
|-------|-------|
| Requirement | Detection commands SHOULD run on a daily schedule after market data sync window. |
| Priority | SHOULD |
| Existing | Scheduled 20:15 / 20:45 / 21:15 (sync timezone from `PORTFOLIO_CRON_TIME`) |
| Gap | DOCUMENTATION_MISSING (ordering rationale not in formal spec) |
| Evidence | `routes/console.php` |

### F042-R004 — Duplicate pending issue prevention (MUST)

| Field | Value |
|-------|-------|
| Requirement | System SHALL NOT create duplicate pending issues for the same stock, issue type, detection method, and ex-date. |
| Priority | MUST |
| Existing | `DataQualityIssueService::createOrRefreshPendingIssueForStock` |
| Gap | IMPLEMENTATION_PARTIAL — dedupe works; see F042-R004b |
| Evidence | Lines 46–58 `DataQualityIssueService.php` |

### F042-R004b — Append evidence on repeated detection (MUST — hardening)

| Field | Value |
|-------|-------|
| Requirement | When repeated detection matches an existing **pending** issue (same dedupe key), the system SHALL append new evidence row(s) with a new `captured_at` and SHALL NOT create a second issue. Original detection fields (`suggested_ratio`, `detected_at`, etc.) SHALL remain immutable. The system MAY update `latest_suggested_ratio` if the new observation differs. |
| Priority | MUST (for V2 hardening) |
| Existing | **Not implemented** — dedupe returns existing silently |
| Gap | IMPLEMENTATION_MISSING |
| Policy | **DECIDED** — see [F042-POLICY-DECISIONS.md §2](./F042-POLICY-DECISIONS.md) |

---

## 8. Issue Classification

### Current fields (immutable at detection)

| Field | Used | Notes |
|-------|------|-------|
| `issue_type` | Yes | Always `corporate_action` today |
| `detection_method` | Yes | `exchange_feed`, `heuristic_gap_detector`, `legacy_manual` |
| `detection_source` | Yes | e.g. `heuristic`, feed source string |
| `corporate_action_type` | Yes | `split`, `bonus`, `face_value_split` |
| `suggested_ratio` / `latest_suggested_ratio` | Yes | Detection output |
| `confidence` | Yes | 0–1 heuristic; 1.0 for exchange feed |
| `ex_date`, `record_date` | Yes | Event dating |
| `previous_close`, `current_open`, `gap_*`, `volume_change_percent` | Yes | Heuristic fields |
| `exchange_match` | Yes | Boolean |
| `detection_payload`, `raw_payload` | Yes | JSON audit |

### Not implemented

- Severity levels (beyond confidence numeric)
- Non-CA issue types
- Profile/tenant scoping
- Affected field granularity beyond CA metadata

### F042-R005 — Immutable detection record (MUST)

| Field | Value |
|-------|-------|
| Requirement | Original detection fields (method, source, suggested ratio at detection, raw payload, detected_at) SHALL NOT be overwritten by resolution actions. |
| Priority | MUST |
| Existing | Resolution updates `issue_status`, `applied_ratio`, `resolved_at`; detection columns untouched |
| Gap | NO_GAP |
| Evidence | `DataQualityResolutionService`; `implementation.md` design note |

---

## 9. Evidence & Traceability

### F042-R006 — Evidence capture (MUST)

| Field | Value |
|-------|-------|
| Requirement | Each issue creation SHALL attach one or more evidence rows with key, label, value, optional payload, and captured_at. |
| Priority | MUST |
| Existing | `DataQualityIssueService::attachEvidence` |
| Gap | NO_GAP |
| Evidence | Heuristic: `gap_ratio`, `volume_change`; Feed: `exchange_ratio` with full payload |

### F042-R007 — Source traceability (MUST)

| Field | Value |
|-------|-------|
| Requirement | Issues SHALL record detection method, source, and timestamps sufficient to reproduce detection context. |
| Priority | MUST |
| Existing | `detection_method`, `detection_source`, `detected_at`, `detection_payload`, `raw_payload` |
| Gap | NO_GAP |

---

## 10. Resolution Lifecycle

### States

```text
                    ┌─────────────────┐
         detect ──→ │ pending_review  │
                    └────────┬────────┘
              accept │      │ reject
                     ▼      ▼
              ┌──────────┐ ┌──────────┐
              │ accepted │ │ rejected │
              └────┬─────┘ └────┬─────┘
                   │ re-resolve │ re-resolve
                   └──────┬─────┘
                          ▼
                   (superseding resolution)
```

### Resolution types (`DataQualityIssueResolution`)

| Type | Meaning |
|------|---------|
| `accepted` | Accepted at suggested ratio |
| `modified_accepted` | Accepted at operator-modified ratio |
| `auto_accepted` | Auto-accepted after pending window |
| `rejected` | Rejected |
| `migrated` | Backfilled from legacy F020 corporate action |
| `reversed` | Constant defined; reversal implemented via `is_reversal` + supersession chain |

### F042-R008 — Manual accept/reject (MUST)

| Field | Value |
|-------|-------|
| Requirement | Admin SHALL accept or reject pending issues via API/UI; accept MAY specify modified ratio and notes. |
| Priority | MUST |
| Existing | `DataQualityController`, `DataQualityResolutionService`, UI |
| Gap | NO_GAP |

### F042-R009 — Resolution audit chain (MUST)

| Field | Value |
|-------|-------|
| Requirement | Each resolution SHALL append an audit row with type, resolver (when manual), notes, applied ratio snapshot, supersession link, and timestamp. |
| Priority | MUST |
| Existing | `portfolio_data_quality_issue_resolutions` |
| Gap | NO_GAP |

### Semantics of “accept” (normative)

Accept (manual or auto) in F042 means **all** of the following — traced from `DataQualityResolutionService::accept()`:

| Meaning | True on accept? |
|---------|-----------------|
| Detected corporate action is believed valid | **Governance intent** — recorded, not verified against OHLCV repair |
| Issue is no longer a blocking data-quality concern | **Yes** — `issue_status` leaves `pending_review` |
| Adjustment factor metadata is trusted for ops | **Yes** — active factor row created |
| Stock allowed back into TOS pipelines | **Yes** — guard blocks only `pending_review` |
| OHLCV is corrected | **No** — F043 domain |
| Portfolio holdings adjusted | **No** — F020 domain |

Accept does **not** mutate raw OHLCV, transactions, or holdings. Pipeline unblock before F043 repair is a **known gap** — pipelines resume using unchanged OHLCV.

### F042-R010 — Auto-accept stale pending issues

| Field | Value |
|-------|-------|
| Requirement | **DECISION_REQUIRED** — see [F042-POLICY-DECISIONS.md §1](./F042-POLICY-DECISIONS.md). Current code auto-accepts **all** pending issues after 15 days. **Recommended (not yet MUST):** auto-accept **only** `exchange_feed` issues with `exchange_match=true` after configurable days; **never** auto-accept `heuristic_gap_detector` issues. |
| Priority | DECISION_REQUIRED (product-owner sign-off before MUST) |
| Existing | `autoAcceptStaleIssues(15)`, `portfolio:auto-resolve-data-quality-issues`, daily 21:15 |
| Gap | IMPLEMENTATION_PARTIAL — unconditional auto-accept is unsafe for heuristic false positives |
| Evidence | `AutoResolveDataQualityIssuesCommand`, `DataQualityResolutionService::autoAcceptStaleIssues` |

### F042-R011 — Adjustment factor metadata on accept (MUST)

| Field | Value |
|-------|-------|
| Requirement | Accept paths SHALL create active adjustment-factor metadata (price divisor, volume multiplier) linked to issue and ex-date; reject/reversal SHALL deactivate factors for the issue. |
| Priority | MUST |
| Existing | `DataQualityAdjustmentFactorService` |
| Gap | IMPLEMENTATION_PARTIAL — **factors not consumed** by any price-read path (F043 future) |
| Evidence | No reads of `PriceAdjustmentFactor` outside DQ services |

### F042-R011b — F043 handoff marker on accept (SHOULD — hardening)

| Field | Value |
|-------|-------|
| Requirement | On accept, adjustment-factor `metadata` SHOULD include an explicit OHLCV repair handoff marker (e.g. `ohlcv_repair_status: pending`). F042 SHALL NOT invoke F043 repair. |
| Priority | SHOULD |
| Existing | Metadata has `source`, `detection_method`, `detection_source` only |
| Gap | IMPLEMENTATION_MISSING |
| Policy | **DECIDED** — see [F042-POLICY-DECISIONS.md §5](./F042-POLICY-DECISIONS.md) |

### F042-R012 — Legacy corporate action migration (SHOULD)

| Field | Value |
|-------|-------|
| Requirement | System SHOULD support one-off migration of applied legacy F020 corporate actions into accepted DQ issues for unified audit history. |
| Priority | SHOULD |
| Existing | `DataQualityLegacyCorporateActionMigrationService`, dry-run default |
| Gap | TEST_MISSING |

---

## 11. Auditability

| Audit element | Supported | Evidence |
|---------------|-----------|----------|
| Who resolved | Yes (manual) | `resolved_by` → `portfolio_users` |
| When | Yes | `resolved_at` on resolution + issue |
| What changed (ratio) | Yes | `applied_ratio`, `suggested_ratio_snapshot` |
| Why (notes) | Yes | `notes`, `metadata` JSON |
| Before/after status | Partial | `metadata.previous_status` |
| Before/after OHLCV values | **No** | F043 domain |
| Resolution method | Yes | `resolution_type` |
| Reversal history | Yes | `is_reversal`, `supersedes_resolution_id` chain |
| Auto vs manual | Yes | `auto_resolved`, `TYPE_AUTO_ACCEPTED` |

### F042-R013 — Append-only resolution history (MUST)

| Field | Value |
|-------|-------|
| Requirement | Resolution records SHALL be append-only; status changes SHALL NOT delete prior resolution rows. |
| Priority | MUST |
| Existing | New row per accept/reject; supersession links |
| Gap | NO_GAP |

---

## 11.A Pipeline gating semantics (normative)

Verified from `DataQualityGuardService` — guard queries **`issue_status = pending_review` only**.

| Issue status | Pipeline blocked? | Intended meaning |
|--------------|-------------------|------------------|
| `pending_review` | **Yes** | Suspected anomaly not yet governed |
| `accepted` (incl. auto, modified, migrated) | **No** | Governance decision recorded; OHLCV may still be wrong until F043 |
| `rejected` | **No** | Detection treated as false positive |

**Unblock does not imply OHLCV correctness.** Reject unblocks even if market data remains anomalous — preferable to indefinite pipeline block.

Policy: **DECIDED** — [F042-POLICY-DECISIONS.md §6](./F042-POLICY-DECISIONS.md)

---

## 12. Profile / Authorization Scope

| Dimension | Current |
|-----------|---------|
| **Authorization** | All `/api/data-quality/*` routes inside `auth:sanctum` + **`admin` middleware** group |
| **UI** | `<AdminRoute>` wraps `/settings/data-quality` and history |
| **Issue scope** | **Global per stock** — not filtered by portfolio profile |
| **Guard scope** | Blocks stock platform-wide when any pending issue exists |

### F042-R014 — Admin-only access (MUST)

| Field | Value |
|-------|-------|
| Requirement | All F042 detection results, resolution actions, and admin APIs SHALL require admin authorization. |
| Priority | MUST |
| Existing | API middleware + AdminRoute |
| Gap | TEST_MISSING (no feature tests verifying 403 for non-admin) |

---

## 13. API Behaviour

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/api/data-quality/dashboard` | Counts by status |
| GET | `/api/data-quality/issues/unresolved` | Pending queue (limit 200, optional `issue_type`) |
| GET | `/api/data-quality/issues/history` | Accepted/rejected (limit 400, filters) |
| GET | `/api/data-quality/issues/{issue}` | Detail + evidence + resolutions |
| POST | `/api/data-quality/issues/{issue}/accept` | `{ applied_ratio?, notes? }` |
| POST | `/api/data-quality/issues/{issue}/reject` | `{ notes? }` |

**Not exposed via API:** trigger sync/scan/auto-resolve (CLI/cpanel only).

### F042-R015 — Admin REST surface (MUST)

| Field | Value |
|-------|-------|
| Requirement | F042 SHALL expose admin JSON endpoints for dashboard, queue, history, detail, accept, and reject. |
| Priority | MUST |
| Existing | `DataQualityController` |
| Gap | TEST_MISSING |

---

## 14. UI Behaviour

| Page | Route | Purpose |
|------|-------|---------|
| Data Quality Center | `/settings/data-quality` | Dashboard cards, pending queue, accept/reject with ratio override |
| Corporate Action History | `/settings/data-quality/history` | Resolved audit; supports re-resolution actions |

Entry: Settings → Global admin → Data Quality card.

### F042-R016 — Admin review UI (MUST)

| Field | Value |
|-------|-------|
| Requirement | Admin SHALL review pending issues and accept/reject from a dedicated UI. |
| Priority | MUST |
| Existing | `DataQualityCenterPage.jsx`, `CorporateActionHistoryPage.jsx` |
| Gap | NO_GAP (functional); DOCUMENTATION_MISSING in formal spec until now |

---

## 15. Data Model

### Tables

| Table | Purpose |
|-------|---------|
| `portfolio_data_quality_issues` | Issue entity + status summary |
| `portfolio_data_quality_issue_evidence` | Evidence rows |
| `portfolio_data_quality_issue_resolutions` | Resolution audit chain |
| `portfolio_price_adjustment_factors` | Accepted ratio metadata (shared infra with future F043) |

**Migration:** `2026_07_30_000001_create_data_quality_tables.php`

### Model sufficiency

| Need | Sufficient? |
|------|-------------|
| CA detection/resolution | **Yes** |
| Multiple issue types | **Partial** — schema generic; code enforces CA only |
| Profile scoping | **No** — would need schema + guard changes |
| OHLCV repair audit | **No** — F043 domain |

---

## 16. Operational Workflow

### CURRENT workflow (as implemented)

```text
DailyMarketDataJob (V1 OHLCV sync)
        │
        ├─ 20:15 portfolio:sync-corporate-actions
        │         └─ HTTP feed → pending issues (exchange_feed)
        │
        ├─ 20:45 portfolio:detect-corporate-action-anomalies
        │         └─ OHLCV gap scan → pending issues (heuristic_gap_detector)
        │
        └─ 21:15 portfolio:auto-resolve-data-quality-issues
                  └─ auto-accept pending > 15 days → adjustment factors

Admin UI/API: review pending → accept/reject → resolution audit
        │
        └─ pending_review cleared → stock unblocked from pipelines

Pipeline consumers (sync inline on each run):
  DataQualityGuardService → Discovery, Evaluation, Screener,
  PatternScan, RelativeStrength, RecommendationGenerationPipeline

Optional one-off: portfolio:migrate-legacy-corporate-actions
  (F020 records → accepted DQ issues, migrated resolution)

Production: cpanel-data-quality-center.php (?task=sync|scan|auto|migrate)
```

### PROPOSED F042 workflow (formal V2 target)

Same as current workflow with these **explicit governance additions**:

1. Documented detection ordering relative to daily sync.
2. Documented auto-accept policy (days threshold, opt-out per issue type).
3. Formal test coverage before production promotion as V2-complete.
4. Clear handoff contract: accepted issue + adjustment factor → F043 repair input (future).
5. No OHLCV mutation within F042 resolution path.

---

## 17. Determinism / Idempotency

| Area | Status | Notes |
|------|--------|-------|
| Deterministic detection (same OHLCV → same issue) | **Partially supported** | Heuristic uses last 2 bars only; feed depends on external payload |
| Idempotent detection runs | **Partially supported** | Dedupes pending by stock/type/method/ex_date; does not refresh evidence |
| Duplicate issue prevention | **Supported** | Pending dedupe query |
| Issue lifecycle transitions | **Supported** | pending → accepted/rejected; re-resolution via supersession |
| Reproducibility | **Partially supported** | Evidence + payloads stored; no detection run id |
| Source traceability | **Supported** | method, source, raw_payload |
| Before/after OHLCV | **Missing** | F043 |
| Audit trail | **Supported** | Resolution chain |
| Profile scoping | **N/A** | Stock-global |
| Authorization | **Supported** | Admin middleware |
| Concurrent resolution | **Not explicitly handled** | Pending queue: last-write-wins (undesired). **Required:** 409 on stale pending mutation — [policy §4](./F042-POLICY-DECISIONS.md) |
| Repeated sync behaviour | **Partially supported** | Re-runs skip duplicate pending; **required:** append evidence (F042-R004b) |
| Detection run identity | **Missing** | **SHOULD:** run id in evidence payload — [policy §3](./F042-POLICY-DECISIONS.md) |

---

## 18. Error Handling

| Scenario | Current behaviour |
|----------|-------------------|
| Missing feed URL | Sync returns error message; zero issues |
| Feed HTTP failure | Error in command result |
| Unknown symbol in feed | Error string collected; other rows continue |
| Accept with invalid ratio | `InvalidArgumentException` (ratio ≤ 0) |
| Issue without stock on accept | `InvalidArgumentException` from adjustment service |
| Non-admin API access | 403 via admin middleware (assumed; untested) |

---

## 19. Acceptance Criteria

| ID | Criterion | Maps to |
|----|-----------|---------|
| F042-AC001 | Scheduled exchange-feed sync creates pending `corporate_action` issues for known symbols without modifying OHLCV | F042-R001 |
| F042-AC002 | Scheduled heuristic scan flags stocks with ≥25% overnight gap matching common split ratios | F042-R002 |
| F042-AC003 | Duplicate pending issues are not created for same stock, type, method, ex-date | F042-R004 |
| F042-AC004 | Detection fields remain unchanged after accept/reject | F042-R005 |
| F042-AC005 | Each new issue has at least one evidence row | F042-R006 |
| F042-AC006 | Admin can accept with default or modified ratio; reject with notes | F042-R008 |
| F042-AC007 | Each resolution appends audit row with resolver (manual) or auto flag | F042-R009 |
| F042-AC008 | Accept creates active adjustment factor; reject deactivates factors for issue | F042-R011 |
| F042-AC009 | Stocks with pending issues are excluded from evaluation scoring (score 0, reason `data_quality_pending_review`) | F042 guard |
| F042-AC010 | Non-admin users cannot access `/api/data-quality/*` | F042-R014 |
| F042-AC011 | **DECISION_REQUIRED** — If conditional auto-accept adopted: only eligible feed issues auto-accept; heuristic never auto-accepts. If policy unchanged: pending older than threshold → `auto_accepted` | F042-R010 |
| F042-AC013 | Repeated detection appends evidence without duplicate pending issue | F042-R004b |
| F042-AC014 | Pending-queue accept/reject returns 409 when issue no longer `pending_review` | Concurrent resolution policy |
| F042-AC012 | Legacy migration dry-run reports counts without writing | F042-R012 |

---

## 20. Test Requirements

| Requirement | Priority | Current |
|-------------|----------|---------|
| Unit tests for issue dedupe and evidence attachment | MUST | **Missing** |
| Unit tests for resolution accept/reject/supersession | MUST | **Missing** |
| Unit tests for adjustment factor create/deactivate | MUST | **Missing** |
| Feature tests for admin API auth + accept/reject | MUST | **Missing** |
| Feature tests for guard integration (evaluation blocked) | SHOULD | **Missing** |
| Tests for sync/heuristic detection fixtures | SHOULD | **Missing** |
| Tests for legacy migration dry-run/apply | SHOULD | **Missing** |

**Note:** F043 tests (`CorporateActionPriceRepairServiceTest`) are **not** F042 coverage.

---

## 21. Current Implementation Mapping

| Requirement area | Primary code |
|------------------|--------------|
| Issue CRUD | `DataQualityIssueService` |
| Feed detection | `DataQualityCorporateActionSyncService` |
| Heuristic detection | `DataQualityCorporateActionHeuristicService` |
| Resolution | `DataQualityResolutionService` |
| Adjustment metadata | `DataQualityAdjustmentFactorService` |
| Pipeline guard | `DataQualityGuardService` |
| Legacy bridge | `DataQualityLegacyCorporateActionMigrationService` |
| API | `DataQualityController` |
| UI | `DataQualityCenterPage.jsx`, `CorporateActionHistoryPage.jsx` |
| Scheduler | `routes/console.php` |
| Ops | `deploy/cpanel-data-quality-center.php` |
| Models | `DataQualityIssue`, `DataQualityIssueEvidence`, `DataQualityIssueResolution`, `PriceAdjustmentFactor` |

---

## 22. Known Gaps

See [F042-IMPLEMENTATION-GAP-MATRIX.md](./F042-IMPLEMENTATION-GAP-MATRIX.md) for full matrix.

**Summary:**

| Category | Count | Examples |
|----------|------:|---------|
| SPEC_MISSING | 1 | No formal spec until this document |
| TEST_MISSING | 12+ | Entire F042 subsystem untested in PHPUnit |
| IMPLEMENTATION_PARTIAL | 3 | Dedupe without refresh; unused adjustment factors; auto-accept policy |
| DOCUMENTATION_MISSING | 2 | Auto-accept threshold; detection ordering |
| DEFERRED_TO_F043 | 2 | OHLCV repair; before/after price audit |
| NO_GAP | 8+ | Core detection, resolution, guard, UI |

---

## 23. Deferred to F043

| Capability | Reason |
|------------|--------|
| Scan/repair misadjusted OHLCV after applied corporate actions | `CorporateActionPriceRepairService` |
| Mutate `portfolio_stock_prices` history | `CorporateActionPriceAdjustmentService` |
| Consume adjustment factors to drive repair | Not wired today |
| cpanel price repair scripts | `deploy/cpanel-repair-corporate-action-prices.php` |

F042 **may hand off** accepted ratio + ex-date + stock to F043; that integration is **not implemented**.

---

## 24. Policy semantics reference

Full analysis and decision status: **[F042-POLICY-DECISIONS.md](./F042-POLICY-DECISIONS.md)**

| Topic | Status |
|-------|--------|
| Auto-accept | DECISION_REQUIRED (recommended: feed-only conditional) |
| Repeated detection | DECIDED → F042-R004b |
| Detection run ID | DECIDED → SHOULD in evidence payload |
| Concurrent resolution | DECIDED → pending 409 / history re-resolution |
| F043 handoff | DECIDED → F042-R011b metadata marker |
| Pipeline gating | DECIDED → §11.A |

---

## 25. Future Enhancements

*Not F042 scope unless separately governed.*

| Enhancement | Rationale |
|-------------|-----------|
| Non-CA issue types (missing OHLCV, stale prices, impossible OHLC) | Schema extensible; no detection code |
| Manual API trigger for sync/scan | Ops convenience |
| Issue refresh on re-detection (update evidence) | `createOrRefreshPendingIssueForStock` name suggests intent |
| Profile-scoped issues | Multi-tenant future |
| Read adjustment factors in analytics | Requires F043 + price pipeline design |
| Email/admin notifications on new pending issues | No channel today |
| Hard publish gates blocking sync | PB-001 out of scope |

---

*Related: [F042-IMPLEMENTATION-GAP-MATRIX.md](./F042-IMPLEMENTATION-GAP-MATRIX.md), [F042-F043-BOUNDARY.md](./F042-F043-BOUNDARY.md)*
