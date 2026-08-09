# F042 Policy Decisions

**Date:** 2026-08-09  
**Status:** Policy / semantics analysis — no implementation authorized  
**Spec:** [F042-DATA-QUALITY-SPEC.md](./F042-DATA-QUALITY-SPEC.md)  
**Boundary:** [F042-F043-BOUNDARY.md](./F042-F043-BOUNDARY.md)

This document resolves policy questions raised during F042 specification reconciliation. Items marked **DECISION_REQUIRED** need explicit product-owner confirmation before hardening encodes them as MUST requirements.

---

## 1. Auto-Accept Policy

### Decision

**DECISION_REQUIRED** — Recommended policy: **Option 3 — conditional auto-accept only** (see below). Do **not** retain unconditional 15-day auto-accept for all detection methods as a MUST requirement.

### Rationale

Tracing `DataQualityResolutionService::accept()` and `autoAcceptStaleIssues()`:

| Effect of accept (manual or auto) | What happens |
|-----------------------------------|--------------|
| `issue_status` | Set to `accepted` |
| Resolution audit | Append `auto_accepted` / `accepted` row |
| Adjustment factor | Active row in `portfolio_price_adjustment_factors` |
| Raw OHLCV | **Unchanged** |
| Portfolio transactions / holdings | **Unchanged** |
| Pipeline gating | Stock **unblocked** — guard keys only on `pending_review` |

**What “accept” means in current code (not naming):**

Accept is a **governance decision** that:

1. The applied ratio is the authoritative ops metadata for this suspected corporate action.
2. The issue is **no longer a blocking data-quality concern** for TOS pipelines.
3. An adjustment-factor record is created for **future** F043 consumption.

Accept does **not** mean OHLCV is corrected, portfolio holdings are adjusted (F020), or the detection is verified correct — only that admin/system recorded approval of the ratio metadata.

**Financial / integrity risk (current vs future):**

| Risk | Current (pre-F043) | Future (F043 wired) |
|------|--------------------|---------------------|
| False-positive heuristic auto-accepted | Stock unblocked; pipelines use **unchanged wrong OHLCV** | Same until F043 repair run; wrong factor could drive wrong repair |
| Adjustment factor affects V1 | **No** — nothing reads `PriceAdjustmentFactor` outside DQ services | F043 should read factors |
| Accept mutates financial data | **No** direct mutation | F043 repair is separate step |
| Auto-accept after 15 days on all methods | **Yes** — includes low-confidence heuristic gaps | Amplifies above risks |

Unconditional 15-day auto-accept is **unsafe for F042’s stated purpose** (governance before repair): it removes pipeline protection without fixing market data and without human review for high false-positive detection paths.

### Current behaviour

- Command: `portfolio:auto-resolve-data-quality-issues` (daily 21:15)
- Default threshold: 15 days (`--days=` overridable)
- Selects all `pending_review` issues with `detected_at <= cutoff`
- Calls `accept(..., auto: true)` with `suggested_ratio` — no method/confidence filter

### Recommended future behaviour (pending product-owner sign-off)

**Option 3 — Auto-accept only under stricter conditions:**

| Detection path | Auto-accept? | Condition |
|----------------|--------------|-----------|
| `exchange_feed` + `exchange_match=true` | **Eligible** | After configurable day threshold (default 15) |
| `heuristic_gap_detector` | **Never** | Requires manual admin accept/reject |
| Any issue with `confidence < 1.0` | **Never** (unless exchange feed) | Manual review |
| Missing / invalid `suggested_ratio` | **Never** | Would throw on accept today |

**Alternative considered — Option 2 (never auto-accept):** Safest for data integrity; highest ops burden (stocks blocked indefinitely if queue ignored). Rejected as default recommendation because exchange-feed issues are high-confidence and benefit from time-bounded unblock **if** ops accepts that trade-off.

**Alternative considered — Option 1 (keep unconditional 15-day):** Rejected — conflates “stop blocking pipelines” with “trust detection” for heuristic false positives.

**Alternative considered — Option 4 (escalation state):** Valuable long-term but requires new status enum — defer to future enhancement unless product owner prioritizes.

### Options evaluated (summary)

| Option | Safety | Ops impact | Pipeline impact | F043 compatibility | Verdict |
|--------|--------|------------|-----------------|-------------------|---------|
| 1. Keep 15-day all | Low | Low | Premature unblock | Poor handoff discipline | Reject |
| 2. Never auto-accept | Highest | High | Indefinite block if ignored | Clear manual gate | Acceptable fallback |
| 3. Conditional auto-accept | High | Medium | Feed-only auto-unblock | Clean high-confidence path | **Recommended** |
| 4. Escalation state | High | Medium | Configurable | Good | Future |
| 5. Disable command entirely | High | High | Same as option 2 | Neutral | Same as 2 |

### Implementation impact (when hardening authorized)

- Filter `autoAcceptStaleIssues` by `detection_method` / `exchange_match` / `confidence`
- Document threshold in config (env or settings)
- Update F042-AC011 to reflect conditional policy
- Add tests for excluded heuristic auto-accept
- **Product owner must confirm:** feed-only rule and day threshold before MUST encoding

---

## 2. Repeated Detection

### Decision

**DECIDED** — **Option B: Idempotent issue + append-only evidence**

When repeated detection matches an existing pending issue (same stock, issue type, detection method, ex-date), the system SHALL **not** create a duplicate issue and SHALL **append** a new evidence observation (and optionally update non-immutable summary fields such as `latest_suggested_ratio` if detection output changed).

### Rationale

Repository evidence supports Option B:

- Evidence table is **append-only by design** (`attachEvidence` always inserts).
- Resolution history is append-only; original detection fields are immutable (F042-R005).
- Option A (current): silent no-op loses audit of repeated observations — ops cannot see ongoing detection.
- Option C (update issue fields): blurs immutable detection record; conflicts with F042-R005 spirit.
- Option D (new issue per run): breaks dedupe; multiple pending rows would block longer; poor ops UX.

Option B improves auditability, reproducibility, and testability while preserving deterministic issue identity. Compatible with F043 — one issue id per CA event.

### Current behaviour

`createOrRefreshPendingIssueForStock` returns existing pending row **without** calling `attachEvidence`.

### Required future behaviour

On dedupe match:

1. Return existing issue id.
2. Append evidence row(s) with new `captured_at` and observation payload.
3. MAY update `latest_suggested_ratio` if new detection differs (does not overwrite original `suggested_ratio`).
4. MUST NOT reset `detected_at` or mutate original detection columns.

### Implementation impact

- Extend `createOrRefreshPendingIssueForStock` (or rename to reflect behaviour).
- Tests: second detect appends evidence, no second issue row.
- Optional: include detection run reference in evidence payload (see §3).

---

## 3. Detection Run Identity

### Decision

**DECIDED** — **SHOULD** (not MUST): include a **detection run identifier** in evidence `evidence_payload` / `detection_payload` on each detection append.

A dedicated `portfolio_data_quality_detection_runs` table is **COULD** / unnecessary for initial hardening.

### Rationale

| Need | Run ID helps? |
|------|---------------|
| Scheduled vs manual runs | Yes — correlate batch |
| Repeated detection audit | Yes — group evidence rows |
| Debugging feed/heuristic | Yes |
| Strict reproducibility | Partial — OHLCV snapshot still in evidence |
| F043 handoff | Low direct need |

Full run registry adds schema complexity without blocking hardening. A UUID or `{command}:{timestamp}` string in evidence payload satisfies audit and debugging at low cost.

### Current behaviour

No run identifier; each evidence row has `captured_at` only.

### Required future behaviour

- Detection commands generate `run_id` per execution.
- Each new evidence append includes `run_id` in payload.
- MUST NOT require new table for V2 hardening phase 1.

### Implementation impact

- Low — command-level UUID passed to services.
- Tests assert evidence payload contains run_id.

---

## 4. Concurrent Resolution

### Decision

**DECIDED** — **Two-mode semantics with stale-mutation rejection on pending queue**

| Surface | Semantics |
|---------|-----------|
| **Pending queue** (`/settings/data-quality`) | Accept/reject permitted **only** when `issue_status = pending_review`. Use optimistic check: if status changed since load, return **409 Conflict** with fresh issue. |
| **History / re-resolution** (`/settings/data-quality/history`) | Accept/reject permitted on `accepted` or `rejected` issues **intentionally** — creates superseding resolution (existing reversal model). Require non-empty notes for re-resolution. |

Do **not** use unguarded last-write-wins on the pending queue.

### Rationale

Current code (`DataQualityController`) has **no status precondition**. Two admins resolving the same pending issue sequentially produce valid append-only resolutions but **last write wins** on `issue_status` — confusing and error-prone.

History page **intentionally** re-resolves settled issues (supersession chain) — this must remain distinct from stale pending-queue mutations.

Concurrent DB transactions: second pending-queue mutation should fail fast if status ≠ `pending_review`.

### Current behaviour

- Last-write-wins on issue summary row.
- All resolutions append regardless of order.
- History and queue share same API endpoints without mode distinction.

### Required future behaviour

- Pending accept/reject: precondition `pending_review`; 409 on stale state.
- Re-resolution: allowed from history; `is_reversal=true` when superseding (existing logic).
- UI: refresh issue on 409.

### Implementation impact

- Controller or service guard + API error code.
- Feature tests for concurrent pending resolution.
- Optional: expose `updated_at` for client optimistic checks.

---

## 5. F042 / F043 Handoff

### Decision

**DECIDED** — **Option B: Record adjustment factor + explicit “repair pending” handoff state**

F042 acceptance SHALL:

1. Create/update active adjustment-factor metadata (existing behaviour).
2. Mark handoff state in factor `metadata` (e.g. `ohlcv_repair_status: pending`) — **does not invoke F043**.

F042 acceptance SHALL **NOT** invoke F043 repair (Option C prohibited).

### Rationale

| Option | Assessment |
|--------|------------|
| A. Record factor only | Current behaviour — insufficient for ops visibility |
| B. Record + repair-pending marker | Clear separation; F043 scans `pending` factors | **Selected** |
| C. Invoke F043 directly | Violates F042/F043 boundary |
| D. Other state machine | Over-engineering for hardening v1 |

Preserves: F042 = governance; F043 = mutation. F043 future job queries active factors where `ohlcv_repair_status = pending` (or equivalent).

### Current behaviour

Factor metadata: `{ source, detection_method, detection_source }` only. No repair status. F043 does not read factors.

### Required future behaviour

- On accept: set `metadata.ohlcv_repair_status = pending` (name finalized in hardening).
- On F043 successful repair (future): F043 sets `completed` / deactivates factor per F043 spec.
- On reject: deactivate factor; no repair pending.

### Implementation impact

- Metadata field only in hardening — no F043 work in F042 phase.
- Document contract in F042-F043-BOUNDARY.md cross-reference.

---

## 6. Pipeline Gating

### Decision

**DECIDED** — Document and preserve current guard semantics with explicit risk note for post-accept pre-F043 window.

### Semantics (verified from `DataQualityGuardService`)

| Issue state | Resolution type | Pipeline blocked? | Notes |
|-------------|-----------------|-------------------|-------|
| `pending_review` | — | **Yes** | Any pending issue for stock blocks |
| `accepted` | manual / modified / auto / migrated | **No** | Guard ignores non-pending |
| `rejected` | manual | **No** | Treated as false positive |
| `auto_accepted` | auto | **No** | Same as accepted (`issue_status=accepted`) |

**Guard implementation:** `where('issue_status', pending_review)` only — resolution type irrelevant to gating.

### Desired semantics (explicit)

1. **Block** = stock excluded from Discovery, Evaluation, Screener, PatternScan, RelativeStrength, RecommendationGenerationPipeline.
2. **Unblock** = any terminal resolution (accept or reject) clearing `pending_review`.
3. **Accept before F043** = stock re-enters pipelines using **existing OHLCV** — known gap until F043 repair.
4. **Reject** = admin declares detection invalid; stock unblocked even if OHLCV still anomalous — intentional trade-off (pipelines prefer availability over indefinite block).

### Risk note

Acceptance **does not** imply OHLCV correctness today. Pipeline unblock is a **governance** decision, not a **data repair** confirmation. Ops must run F043 repair separately (future).

### Current behaviour

Matches desired semantics above. No code change required for gating logic — documentation and auto-accept policy change address the hidden risk.

### Implementation impact

- Document in spec (done via spec update).
- Optional future: separate “data repair complete” gate — **out of F042 scope** unless product owner expands.

---

## Decision status summary

| Topic | Status |
|-------|--------|
| Auto-accept policy | **DECISION_REQUIRED** (recommended: conditional feed-only) |
| Repeated detection | **DECIDED** (Option B) |
| Detection run ID | **DECIDED** (SHOULD in evidence payload) |
| Concurrent resolution | **DECIDED** (pending-queue 409; history re-resolution) |
| F042/F043 handoff | **DECIDED** (factor + repair-pending metadata) |
| Pipeline gating | **DECIDED** (document current semantics) |

---

*Related: [F042-DATA-QUALITY-SPEC.md](./F042-DATA-QUALITY-SPEC.md), [F042-IMPLEMENTATION-GAP-MATRIX.md](./F042-IMPLEMENTATION-GAP-MATRIX.md)*
