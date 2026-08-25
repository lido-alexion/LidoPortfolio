# LidoPortfolio V4 Wishlist / Deferred Work Register

| Field | Value |
|-------|-------|
| **V3 Status** | **COMPLETE WITH DEFERRED ITEMS** (final audit 2026-08-24; release-readiness verification 2026-08-24) |
| **Document type** | Persistent deferred-work inventory |
| **Created** | 2026-08-25 |
| **Canonical path** | [`specs/LidoPortfolio-V4-Wishlist.md`](LidoPortfolio-V4-Wishlist.md) |
| **Related** | [`LidoPortfolio-V3-Specification.md`](LidoPortfolio-V3-Specification.md) · [`../implementation.md`](../implementation.md) · [`architecture/governance/PRODUCT_BACKLOG.md`](architecture/governance/PRODUCT_BACKLOG.md) · [`architecture/audit/TECHNICAL_DEBT.md`](architecture/audit/TECHNICAL_DEBT.md) · [`architecture/audit/KNOWN_LIMITATIONS.md`](architecture/audit/KNOWN_LIMITATIONS.md) |

## Purpose

Persistent tracking of deferred features, known bugs, technical debt, unresolved product/spec decisions, and optional enhancements carried forward after V3 freeze.

**This document is NOT a V4 implementation specification.**  
Items requiring product/spec decisions must be frozen before implementation. Do not invent behavior for unspecified requirements.

**Do not reopen frozen V3 workstreams** (WS2–WS4, §34.3–§34.4, OD-10, OD-17, §10.4–§10.5, §29 horizon/first-entry, zero-own UNFUNDED lending, B3 Dashboard reserve warning) unless a *new* regression is proven.

### Classification legend

| Class | Meaning |
|-------|---------|
| **FEATURE** | Future capability intentionally not part of V3 |
| **BUG** | Incorrect or flaky behavior; not a V3 release blocker |
| **TECH_DEBT** | Code/architecture/ops debt to clean eventually |
| **SPEC_DECISION** | Cannot safely implement until product/spec is frozen |
| **UX_POLISH** | Useful enhancement; not a correctness gap |
| **HISTORICAL** | Context only — **not** an active V4 task |

### Status values

`OPEN` · `VERIFY` (may already be partially/fully shipped — confirm before scheduling) · `BLOCKED` (waiting on SPEC_DECISION) · `COMPLETE` (only after acceptance rules in §9)

---

## 1. V3 Carry-Forward Summary

V3 normative, implementable scope is complete. This register captures everything intentionally left unresolved or carried from pre-V3 backlog/debt that remains useful.

**V3 completed (do not list as open gaps):** OD-01, OD-10 quantity ownership, §10.4 adoption, §10.5 backfill, WS2, WS3, WS4 (incl. zero-own UNFUNDED), §34.3, §34.4, OD-17, §29 horizon + `first_entry_pct`, B3 Dashboard reserve warning.

**Explicit V3 carry-forwards (must appear below):** same-stock adoption cost-basis merge; CA rights; CA cost/trailing/stop restatement; B4 banner; broker automation; weakest-position window Strategy UI; optional loan/recall/bridge cash-ledger types; `schedulerTimestamp` JS test flake; OD-17 historical 550 references (informational only).

---

## 2. V4 Priority Summary

| Priority | Meaning | Guidance |
|----------|---------|----------|
| **P0** | Correctness / important bugs affecting trust | Fix when scheduling maintenance |
| **P1** | Important product capability or high-impact debt | Needs product decision and/or design freeze first if SPEC_DECISION |
| **P2** | Useful enhancement / medium debt | Schedule after P0–P1 |
| **P3** | Optional polish / future vision | No urgency |
| **TBD** | Priority not assigned from current evidence | Do not invent urgency |

**Suggested focus when V4 planning starts (not a commitment):**

- **P0/P1 candidates:** V4-BUG-001 (scheduler timestamp tests); V4-SPEC-001–003 (adopt merge / CA rights / CA restatement); V4-TD-001 (Strategy params → Evaluation, TD-19); V4-TD-002 (data publish gate) — *after* product prioritization.
- **P2:** B4, broker era, cash-ledger kinds, notifications abstraction, UI test harness.
- **P3 / TBD:** weakest-window UI, OpenAPI, stocks admin, mobile/AI, markets expansion.

---

## 3. V4 Feature Wishlist

| ID | Item | Current State | Desired Outcome | Dependencies | Priority | Status | Source |
|----|------|---------------|-----------------|--------------|----------|--------|--------|
| V4-FEAT-001 | Broker / live execution automation | Manual fill recording; no broker adapter | Assisted/live order path with adapters | Auth, security, live-trading specs | P2 | OPEN | PB-045; V3 Decision 11; KNOWN_LIMITATIONS #17; `specs/architecture/live-trading/` |
| V4-FEAT-002 | Advanced orders (GTT / stop / target / partial fills) | Not present | Broker-era order types | V4-FEAT-001 | P3 | OPEN | PB-046 |
| V4-FEAT-003 | B4 persistent app-wide critical banner | B3 Dashboard reserve shortfall warning only | Optional persistent critical alert surface | Product design (colour/placement not frozen) | P2 | OPEN | V3 §29 B4 wishlist; `implementation.md` Final V3 Deferred |
| V4-FEAT-004 | Notification channel abstraction + email/webhook | Telegram-primary | Config-driven multi-channel | PB-012 design | P2 | OPEN | PB-012/013/023; TD-08; KL #14–15 |
| V4-FEAT-005 | Market regime assessment (non-stub) | Stub / neutral composite in Evaluation | Real regime input to scoring | Evaluation design | P2 | OPEN | PB-021; KL #8; EvaluationEngine stubs |
| V4-FEAT-006 | Liquidity & Tradability indicator calculators | Metadata / planned | Real Primary + composite scores | Indicator Registry | P2 | OPEN | PB-057; KL #10c |
| V4-FEAT-007 | Indicator Registry remaining consumer cutover / definition versions | Registry UI + façades exist; deeper versioning deferred | Full SD-033 Phase 4–5 outcomes | PB-055/056 | P2 | VERIFY | PB-055/056; `IndicatorRegistryPage.jsx` exists — confirm residual vs plan |
| V4-FEAT-008 | Trading Artifact Framework remaining phases | Screener/Strategy registries + AI prompt builder shipped in part | Complete SD-034 phases as still open | Artifact specs | P2 | VERIFY | PB-058–060; KL #10d may be stale — reconcile with registries |
| V4-FEAT-009 | Review reports list UI + deeper metrics | API + dashboard focus | Dedicated reports UX; drawdown/execution quality | ReviewEngine | P3 | OPEN | PB-029/030; TD-16; KL #20–21 |
| V4-FEAT-010 | Production-safe scheduled pipeline ops hardening | Optional schedule + post-sync hooks exist (F148/F149) | Ops alerts, runbooks, production defaults as product chooses | Existing schedule services | P2 | VERIFY | PB-011; `implementation.md` F148/F149 — PB-010 likely closed |
| V4-FEAT-011 | Stocks admin SPA surface | Partial / open item historically | Full stocks admin UX | Admin routes | P3 | OPEN | `implementation.md` Open Items |
| V4-FEAT-012 | Admin force-logout of other users (PD-007) | Deferred under F005 | Admin can revoke other sessions | Auth product decision | P3 | OPEN | `implementation.md` F005 residual; PD-007 |
| V4-FEAT-013 | F014 cash-as-of / export / compare polish | V2 closed; residual polish | Cash historical views/exports | Product prioritization | P3 | OPEN | V2 residuals / `implementation.md` |
| V4-FEAT-014 | Backtest history “Duplicate” action | Button disabled “Coming soon” | Duplicate run from history | Backtest UX | P3 | OPEN | `BacktestHistoryPage.jsx` |
| V4-FEAT-015 | Tax reporting / attribution / benchmarks | Not productized | Review/tax surfaces | ReviewEngine | P3 | OPEN | PB-052 |
| V4-FEAT-016 | Mobile application | SPA only | Mobile client | API maturity | TBD | OPEN | PB-051 |
| V4-FEAT-017 | AI assistant (non-decision) | Prompt builders only | Assistive product surface | Trust-first ADR | TBD | OPEN | PB-050 |
| V4-FEAT-018 | ML scoring models | Deterministic Evaluation today | Optional ML path | ADR vs determinism | TBD | OPEN | PB-048 |
| V4-FEAT-019 | Options / crypto / ETF products | Equity focus | Expanded markets | Data providers | TBD | OPEN | PB-049 |
| V4-FEAT-020 | Live paper / portfolio replay | Documented as not implemented in audits | Paper/live simulation modes | Product freeze | TBD | OPEN | Backtest coverage audits |

Same-stock adoption merge, CA rights, and CA restatement are **not** listed here as FEATURES until their SPEC decisions land — see §5 (`V4-SPEC-*`). Broker exclusivity for “one live account” rides with V4-FEAT-001.

---

## 4. V4 Known Bugs

| ID | Bug | Current Behavior | Expected Behavior | Evidence / Location | Priority | Status |
|----|-----|------------------|-------------------|---------------------|----------|--------|
| V4-BUG-001 | `schedulerTimestamp` JS locale format mismatch | Formatter yields e.g. `07 Jul, 2026, 5:00 AM GMT+5:30` | Tests expect e.g. `07 Jul 2026, 05:00:04` | `app/tests/js/schedulerTimestamp.test.mjs`; release-readiness verification 2026-08-24 | P2 | OPEN |
| V4-BUG-002 | Strategy indicator parameters ignored by Evaluation | Strategy UI saves periods/lookbacks; Evaluation uses `trading_os.evaluation` defaults | Active strategy parameters drive Evaluation (with documented fallback) | TD-19; PB-054; KL #10a; EvaluationEngine | P1 | OPEN |
| V4-BUG-003 | `max_position_pct` weakly/ unused in sizing path | Cap may not constrain suggested size as operators expect | Enforce portfolio/strategy max position policy in sizing | TD-06; KL #12 | P2 | VERIFY |
| V4-BUG-004 | `DailyMarketDataJobTest` full-suite order flake | Fails in full suite (`Target class [db.schema] does not exist`); passes in isolation | Deterministic green in full suite | `implementation.md` Test-suite stabilization; `DailyMarketDataJobTest` | P3 | OPEN |

**Note:** V4-BUG-001 is a **test/tooling** issue relative to locale formatting. Do not treat it as a V3 functional regression. Do not “fix” by weakening assertions without deciding the intended display contract.

---

## 5. V4 Specification / Product Decisions

| ID | Decision Needed | Why It Is Unspecified | Current Safe Behavior | Decision Owner | Status | Source |
|----|-----------------|-----------------------|-----------------------|----------------|--------|--------|
| V4-SPEC-001 | Same-stock unmanaged adoption **cost-basis / multi-lot merge** mathematics | V3 §10.4 requires merge when destination already owns the symbol, but cost-basis merge rules were never frozen; OD-15 freezes entry-date continuity only | **422** reject; no invented WAVG/merge | Product / Architecture | OPEN / BLOCKED for coding | `HoldingAdoptionService`; `HoldingAdoptionTest::test_same_stock_destination_is_blocked`; V3 §10.4 / OD-15; Final V3 Deferred |
| V4-SPEC-002 | Corporate-action **rights-issue** (and other non-bonus/split CA types) calculation rules | OD-10 freezes **parent-owner quantity attachment** only; rights math not decided | Apply path accepts **split/bonus** only; other types unsupported / repair unsupported | Product / Architecture | OPEN | V3 §10.7; `CorporateActionService`; F043 unsupported actions |
| V4-SPEC-003 | CA **cost-basis / average price / trailing-high / stop-loss price / target** restatement after CA | Explicit OD-10 leftovers | Ownership attachment only; no invented restatement | Product / Architecture | OPEN | V3 §10.7; `implementation.md` OD-10 |
| V4-SPEC-004 | Optional **cash-ledger entry types** for loan / recall / bridge movements | Accounting presentation undecided; current functionality works via existing capital tables | No dedicated ledger kinds required for V3 correctness | Product / Accounting | OPEN | WS4 delta “Still open” #11; Final V3 Deferred |
| V4-SPEC-005 | Ambiguous **cross-owner sell** / ledger attribution edge cases beyond current conservative unmanaged fallback | Spec leaves some multi-owner sell attribution conservative; further policy not frozen | Conservative unmanaged / attributable paths as shipped | Product | OPEN | V3 ownership sections; OD-10 ambiguous fallback |
| V4-SPEC-006 | Live-era **portfolio exclusivity** and broker account binding rules | Deferred to broker era (V3 Decision 11) | Multi-portfolio paper/manual era continues | Product | OPEN | V3 §3 / Decision 11; depends on V4-FEAT-001 |

**Rule:** Do not implement V4-SPEC-* items by inventing formulas. Freeze an SD / OD / DEP (or V4 equivalent) first.

---

## 6. V4 Technical Debt

| ID | Item | Current State | Desired Outcome | Priority | Status | Source |
|----|------|---------------|-----------------|----------|--------|--------|
| V4-TD-001 | Strategy parameters → EvaluationEngine wiring | See V4-BUG-002 | Single source of truth for indicator periods | P1 | OPEN | TD-19 / PB-054 |
| V4-TD-002 | Hard dataset publish / validation gate | Downstream can consume incomplete OHLCV | Published-dataset gate before discovery | P1 | OPEN | PB-001; TD-04; KL #1 |
| V4-TD-003 | Immutable dataset versioning | Date-string soft version | Reproducible snapshot id | P2 | OPEN | PB-002; TD-13; KL #3 |
| V4-TD-004 | Recommendation `markExecuted` ownership | Execution may mutate recommendation status directly | `RecommendationEngine::markExecuted` ownership | P3 | OPEN | PB-014; TD-02 |
| V4-TD-005 | Notification channel interface | Telegram-centric | Channel abstraction | P2 | OPEN | TD-08; PB-012 |
| V4-TD-006 | OpenAPI for `/api/v1` | Docs markdown only | Machine-readable contract | P3 | OPEN | PB-016; TD-10; KL #25 |
| V4-TD-007 | Vitest / E2E smoke for TOS UI | Backend/node unit tests only | UI regression harness | P2 | OPEN | PB-032; TD-12; KL #26 |
| V4-TD-008 | Split monolithic TradingOsController | Large controller | Controllers by module | P3 | OPEN | PB-040; TD-03 |
| V4-TD-009 | Shared TOS React hooks/components | Duplicated fetch/error patterns | Shared hooks | P3 | OPEN | PB-031; TD-11 |
| V4-TD-010 | Structured logging fields (engine / request_id) | Partial | App Arch §7 alignment | P3 | OPEN | PB-033; TD-17 |
| V4-TD-011 | Consistent pagination on v1 lists | Inconsistent | page/pageSize everywhere | P3 | OPEN | PB-027; TD-18 |
| V4-TD-012 | Pluggable Evaluation rules modules | Embedded rules | Extracted testable rules | P3 | OPEN | PB-022; TD-09 |
| V4-TD-013 | CI workflow for PHPUnit + frontend build | Manual / local emphasis | Automated CI | P2 | OPEN | `implementation.md` Pending Improvements |
| V4-TD-014 | Production secrets / single-folder deploy hardening | Nested `lidoportfolio/` + `portfolio/` | Hardened secrets; optional layout | P3 | OPEN | `implementation.md` Wishlist; `deploy/DEPLOY.md` |
| V4-TD-015 | Repository layer for TOS aggregates | Eloquent in engines | Optional repositories | P3 | OPEN | PB-026; TD-01 |
| V4-TD-016 | Spec drift: JWT-in-specs vs Sanctum-in-code | Documented as known | Formal spec update | P3 | OPEN | TD-14; KL #27 |

**Do not confuse with closed items:** `pipeline.run_after_daily_sync` / scheduled decision pipeline (F148/F149) appear **implemented** — treat PB-010 as **VERIFY/HISTORICAL**, not new debt.

---

## 7. V4 Optional UX / Polish

| ID | Item | Current State | Desired Outcome | Priority | Status | Source |
|----|------|---------------|-----------------|----------|--------|--------|
| V4-UX-001 | Strategy-page weakest-position evaluation window editor | Engine reads `weakest_position_window_days`; no §29-mandated control | Optional Strategy UI control | P3 | OPEN | V3 §29 (not required); `WeakestPositionRanker`; WS4 delta #12 |
| V4-UX-002 | B4 critical banner presentation | See V4-FEAT-003 | Same item — listed here as polish face of B4 | P2 | OPEN | V3 §29 B4 |
| V4-UX-003 | Cash-ledger labels for loan/recall/bridge | See V4-SPEC-004 | UX/accounting presentation after SPEC freeze | P2 | BLOCKED | WS4 delta #11 |
| V4-UX-004 | Discovery inline default screener when hits stale | Relies on prior screener runs | Optional inline run | P3 | OPEN | PB-024; TD-15; KL #5 |
| V4-UX-005 | Thin Evaluation history UX | Latest-focused | Richer run history UX | P3 | OPEN | KL #10 |
| V4-UX-006 | TypeScript / TanStack Query / AG Grid | JSX SPA | Stack migrations if product chooses | TBD | OPEN | PB-041/042 |
| V4-UX-007 | Optional JWT/token API for non-SPA clients | Sanctum SPA cookies | Token clients | TBD | OPEN | PB-043 |

---

## 8. Historical / Non-Actionable Items

**These are NOT active V4 tasks.** Preserve for context only.

| ID | Item | Note | Source |
|----|------|------|--------|
| V4-HIST-001 | OD-17 / V1 `HISTORY_DEPTH_TARGET_DAYS = 550` product ceiling | **Resolved in V3:** campaign completes on `all_available`; numeric target removed from operational completion. Remaining mentions are as-built/historical. **Do not reintroduce a numeric ceiling.** | V3 OD-17; `HistoryDepthBackfillService`; Final V3 / release-readiness audits |
| V4-HIST-002 | V3 workstreams WS1–WS4, §34.3–§34.4, §10.4–§10.5, §29, zero-own UNFUNDED | **COMPLETE** | `implementation.md` Final V3 Completion Audit |
| V4-HIST-003 | SD-035 V2 eleven deferred capabilities | **V2 CLOSED** | `docs/v2/V2-FINAL-RECONCILIATION.md`; DOCS.md §3.L |
| V4-HIST-004 | PB-044 “multi-strategy isolation” as V2.0 backlog row | Largely superseded by **V3 multi-strategy / OD-01** — do not reopen as blank-slate feature | PRODUCT_BACKLOG PB-044 vs V3 SoT |
| V4-HIST-005 | PB-003 “Deep Position Review” shallow SL | Partially superseded by **V3 §34.3** portfolio SL/trailing/precedence — residual “deep review UX” would be a *new* V4 story if desired | PRODUCT_BACKLOG / TD-05 vs §34.3 |
| V4-HIST-006 | Pre-V3 PRODUCT_BACKLOG / TECHNICAL_DEBT / KNOWN_LIMITATIONS packs | Still useful indexes; **this V4 register is the forward-looking tracker** for post-V3 work. Prefer linking PB-/TD- IDs rather than forking conflicting statuses. | `architecture/governance/PRODUCT_BACKLOG.md`, `architecture/audit/*` |
| V4-HIST-007 | WS4 delta §4–§6 “do not implement yet” schema/service lists | Largely **implemented** in V3 WS4; keep as historical build plan | `V3-WS4-Recall-Bridge-Implementation-Delta.md` |

---

## 9. V4 Acceptance / Closure Rules

An item may be marked **COMPLETE** only when **all** applicable conditions hold:

1. **Spec freeze** — If the item was `SPEC_DECISION` / `BLOCKED`, a frozen product decision (SD/OD/DEP or explicit V4 decision record) exists.
2. **Implementation** — Required production behavior exists (not a stub/placeholder).
3. **Tests** — Focused tests cover the new behavior; no “tests rewritten merely to pass.”
4. **Regressions** — Relevant frozen V3 suites remain green (or failures are classified and tracked).
5. **Documentation** — `implementation.md` updated; in-app help / API docs updated when user-facing; this register’s Status → COMPLETE with date.
6. **No silent invention** — Unspecified mathematics (cost merge, CA restatement, etc.) were not invented.

Marking COMPLETE because “some related code exists” is **not** sufficient (see VERIFY rows).

---

## 10. Change Log

| Date | Change |
|------|--------|
| 2026-08-25 | Initial V4 Wishlist / Deferred Work Register created from Final V3 Completion Audit, release-readiness verification, V3 deferred list, WS4 delta “Still open,” and selected open items from PRODUCT_BACKLOG / TECHNICAL_DEBT / KNOWN_LIMITATIONS / `implementation.md`. |
| 2026-08-25 | Indexed from `DOCS.md` (§ Start here + §3.78), `specs/README.md`, `implementation.md` Final V3 Deferred; pointers added on PRODUCT_BACKLOG / TECHNICAL_DEBT / KNOWN_LIMITATIONS. |

---

## Appendix A — Mapping: V3 deferred → V4 IDs

| V3 deferred / unspecified | V4 ID(s) |
|---------------------------|----------|
| Same-stock adoption cost-basis merge | V4-SPEC-001 |
| CA rights processing | V4-SPEC-002 |
| CA cost / trailing / stop restatement | V4-SPEC-003 |
| B4 persistent banner | V4-FEAT-003 / V4-UX-002 |
| Broker / live automation | V4-FEAT-001 (+ V4-FEAT-002, V4-SPEC-006) |
| Weakest-position window Strategy UI | V4-UX-001 |
| Optional loan/recall/bridge cash-ledger types | V4-SPEC-004 / V4-UX-003 |
| `schedulerTimestamp` JS failures | V4-BUG-001 |
| OD-17 historical 550 references | V4-HIST-001 |

## Appendix B — Relationship to older backlog docs

- **Canonical post-V3 tracker:** this file.
- **Pre-V3 backlog/debt:** keep as historical indexes; when scheduling work, create/confirm a V4-* ID here and reference the PB-/TD- number.
- **V3 SoT:** remains authoritative for *already frozen* V3 behavior; do not weaken V3 COMPLETE status via this wishlist.
