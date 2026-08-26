# LidoPortfolio V4 Wishlist

| Field | Value |
|-------|-------|
| **V3 Status** | **V3 STRICTLY COMPLETE** (strict register-to-implementation pass 2026-08-26) |
| **Document type** | Forward-looking V4 register + V5 deferred features (same genuine-new-work pool) |
| **Created** | 2026-08-25 |
| **Last reconciled** | 2026-08-27 (V4-FEAT-005 implemented: Evaluation market_regime from Market Analysis) |
| **Canonical path** | [`specs/LidoPortfolio-V4-Wishlist.md`](LidoPortfolio-V4-Wishlist.md) |
| **Related** | [`LidoPortfolio-V3-Specification.md`](LidoPortfolio-V3-Specification.md) · [`../implementation.md`](../implementation.md) |

## Purpose

This register holds **only**:

1. **Genuine new post-V3 functionality** that was not part of V3 normative scope (active V4 FEAT IDs and V5-deferred FEAT IDs), **or**
2. **Genuine V4 product/spec decisions** for rules V3 never froze. **V4-SPEC-001 through V4-SPEC-006 are now FROZEN Product Owner decisions** (2026-08-26). Frozen means the rule is specified, **not** that the behaviour is implemented.

It is **not** a deferral bin for V3 bugs, V3 technical debt, V3 UX polish, or historical notes.

**V4 vs V5** in this file is a **roadmap / prioritization** split of that same pool. Moving an item to V5 does **not** mean the capability is implemented, closed, or no longer needed. Feature IDs are unchanged (`V4-FEAT-*`).

**Do not reopen frozen V3 workstreams** (WS2–WS4, §34.3–§34.4, OD-10, OD-17, §10.4–§10.5, §29 product surfaces, zero-own UNFUNDED lending, B3 Dashboard reserve warning, OD-16 engine + Strategy window control) unless a *new* regression is proven.

### Status values

`OPEN` · `BLOCKED` (waiting on a SPEC decision) · `DECIDED` (PO rule frozen; **not** implemented) · `COMPLETE` (implemented in production)

A SPEC may be `DECIDED` while related FEAT rows stay `OPEN`. Do **not** mark a FEAT `COMPLETE` because its prerequisite SPEC is frozen.

---

## 1. What V3 closed (do not reopen)

Normative V3 is implemented, tested, and documented, including (non-exhaustive): multi-strategy / OD-01, WS2–WS4, §34.3–§34.4, OD-10/17, §10.4–§10.5, §29 surfaces, OD-12 settings, max_holdings, archive, capital badges, §5.7 lending limits, §19 success flags, §30 capital/lending notifies, **OD-16 Strategy weakest-window control**, deterministic `schedulerTimestamp`, and `DailyMarketDataJobTest` harness fix.

Living detail: [`implementation.md`](../implementation.md).

---

## 2. Genuine V4 features (active V4 scope)

Active V4 feature count: **22** (**19** `OPEN`, **3** `COMPLETE`).

| ID | Item | Why genuinely V4 | Priority | Status |
|----|------|------------------|----------|--------|
| V4-FEAT-001 | Broker / live execution automation | V3 §3 / §32 Decision 11 + SD-010: V3 does **not** require broker automation; manual/semi-auto fill is V3 | P2 | OPEN |
| V4-FEAT-002 | Advanced orders (GTT / stop / target / partial fills) | Broker-era order types; depends on FEAT-001 | P3 | OPEN |
| V4-FEAT-005 | Market regime assessment (non-stub) | Not a V3 normative engine; Evaluation stub residual. **PO decision (2026-08-27):** Evaluation consumes MarketAnalysisEngine categorical `market_regime` (Bullish/Neutral/Bearish via existing `regimeFromPhase()`). Numeric Evaluation factor is Bullish→100, Neutral→50, Bearish→0. No new phase/regime calculation; sentiment is not the score. Implemented via `MarketRegimeScoreMapper` in EvaluationEngine (2026-08-27). | P2 | COMPLETE |
| V4-FEAT-006 | Liquidity & Tradability indicator calculators | Indicator Registry expansion; not V3 SoT | P2 | OPEN |
| V4-FEAT-008 | Trading Artifact Framework remaining phases | SD-034 residual beyond Screener/Strategy registries shipped in V3 | P2 | OPEN |
| V4-FEAT-009 | Review reports list UI + deeper metrics | New Review UX beyond V3 Dashboard/API | P3 | OPEN |
| V4-FEAT-010 | Pipeline ops hardening beyond shipped F148/F149 | F148/F149 schedule hooks are V3-complete; further ops defaults are new | P2 | OPEN |
| V4-FEAT-011 | Stocks admin SPA surface | Admin product expansion; not V3 | P3 | OPEN |
| V4-FEAT-012 | Admin force-logout of other users (PD-007) | Auth product expansion; not V3 | P3 | OPEN |
| V4-FEAT-013 | Cash-as-of / export / compare polish | F014 residual polish; not V3 | P3 | OPEN |
| V4-FEAT-014 | Backtest history “Duplicate” action | New UX convenience; not V3 | P3 | OPEN |
| V4-FEAT-015 | Tax reporting / attribution / benchmarks | New product surface | P3 | OPEN |
| V4-FEAT-021 | Strategy indicator params → EvaluationEngine wiring | EvaluationEngine is a separate TOS path from V3 Strategy fit/scoring; wiring is a V4 Evaluation design choice (was TD-19 / V4-BUG-002 / V4-TD-001). **PO decision (2026-08-26):** Strategy catalogue parameters are authoritative — a valid Strategy value overrides global Evaluation config; otherwise the existing global/default is used. Keys: `rsi_period`, `lookback_days`, `sma_fast`, `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`. Implemented via `EvaluationParameterResolver` (2026-08-26). | P1 | COMPLETE |
| V4-FEAT-022 | Hard dataset publish / validation gate | Pre-discovery data-platform hardening (was V4-TD-002). **PO clarification (2026-08-27) — correction of the same feature, not a new ID:** Discovery is allowed when the required market dataset was successfully synced within the previous 24 hours of the pipeline run. On Monday, the allowed freshness window is 72 hours. The comparison is based strictly on timestamps, not calendar dates. Holiday-aware freshness / trading-calendar handling is out of scope (deferred to V5). Supersedes the earlier `published === true` / “synced today” gate. Implemented via `DatasetFreshnessGate` in `DailyDecisionPipeline` (2026-08-27). | P1 | COMPLETE |
| V4-FEAT-023 | Immutable dataset versioning | Data-platform hardening (was V4-TD-003) | P2 | OPEN |
| V4-FEAT-024 | Recommendation `markExecuted` ownership refactor | Architecture cleanup (was V4-TD-004) | P3 | OPEN |
| V4-FEAT-025 | OpenAPI for `/api/v1` | Machine-readable contract (was V4-TD-006) | P3 | OPEN |
| V4-FEAT-026 | Vitest / E2E smoke for TOS UI | New test harness (was V4-TD-007) | P2 | OPEN |
| V4-FEAT-027 | Split TradingOsController / shared React hooks | Maintainability (was V4-TD-008/009) | P3 | OPEN |
| V4-FEAT-028 | Structured logging / pagination consistency | Platform hardening (was V4-TD-010/011) | P3 | OPEN |
| V4-FEAT-029 | Pluggable Evaluation rules modules | Evaluation architecture (was V4-TD-012) | P3 | OPEN |
| V4-FEAT-032 | Repository layer for TOS aggregates | Architecture (was V4-TD-015) | P3 | OPEN |

---

## 3. V5 deferred features (moved from V4)

Product Owner decision (2026-08-26): these **14** items are **V5 scope**, not active V4. They remain genuine post-V3 work. IDs, names, rationale, and priorities are unchanged.

This is a **roadmap / prioritization** decision, **not** a claim that the underlying capability is implemented. Status remains `OPEN`. **Do not mark these COMPLETE** because they moved.

| ID | Item | Why genuinely V4 (original rationale) | Priority | Status |
|----|------|---------------------------------------|----------|--------|
| V4-FEAT-003 | B4 persistent app-wide critical banner | V3 §29: **B4 is explicit wishlist**; B3 Dashboard reserve warning is current V3 | P2 | OPEN |
| V4-FEAT-004 | Notification channel abstraction + email/webhook | V3 §30 requires Telegram/in-app capability (shipped); multi-channel is new | P2 | OPEN |
| V4-FEAT-007 | Indicator Registry deeper versioning / remaining cutover | SD-033 residual beyond V3 registries already shipped | P2 | OPEN |
| V4-FEAT-016 | Mobile application | New client | TBD | OPEN |
| V4-FEAT-017 | AI assistant (non-decision) | New assistive surface | TBD | OPEN |
| V4-FEAT-018 | ML scoring models | Optional non-deterministic path; V3 is deterministic | TBD | OPEN |
| V4-FEAT-019 | Options / crypto / ETF products | Markets expansion | TBD | OPEN |
| V4-FEAT-020 | Live paper / portfolio replay modes | New simulation modes | TBD | OPEN |
| V4-FEAT-030 | CI workflow for PHPUnit + frontend build | Ops (was V4-TD-013) | P2 | OPEN |
| V4-FEAT-031 | Production secrets / single-folder deploy hardening | Ops (was V4-TD-014) | P3 | OPEN |
| V4-FEAT-033 | Discovery inline default screener | Discovery UX enhancement (was V4-UX-004) | P3 | OPEN |
| V4-FEAT-034 | Richer Evaluation history UX | Evaluation UX (was V4-UX-005) | P3 | OPEN |
| V4-FEAT-035 | TypeScript / TanStack Query / AG Grid migration | Stack migration (was V4-UX-006) | TBD | OPEN |
| V4-FEAT-036 | Optional JWT/token API for non-SPA clients | Auth expansion; Sanctum SPA is V3 (was V4-UX-007 / TD-016 drift → docs reconciled) | TBD | OPEN |

Notification channel interface (old V4-TD-005) is covered by **V4-FEAT-004** (now V5-classified; still OPEN).

### V4-FEAT-021 — PO parameter-authority rule (COMPLETE)

**PO decision (2026-08-26):** Strategy indicator parameters are authoritative for the indicator parameters defined by the Strategy catalogue. For each supported parameter, a valid Strategy value overrides the global Evaluation configuration; otherwise the existing global/default value is used.

Supported override keys: `rsi_period`, `lookback_days`, `sma_fast`, `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`.

**Implementation (2026-08-26):** `EvaluationParameterResolver` resolves those keys at the Evaluation boundary. Missing/invalid values fall back to existing globals/defaults. Weights / `trading_os.evaluation.weights` remain out of scope and unchanged.

### V4-FEAT-022 — PO dataset freshness gate (COMPLETE)

**PO decision (2026-08-27, original):** The daily decision pipeline must stop before Discovery unless the required market dataset is acceptable; it must not generate evaluation results or recommendations from an unpublished/stale dataset.

**PO clarification (2026-08-27) — same feature, not a new V4 ID:** The earlier implementation rule (`DataEngine::datasetStatus()['published'] === true`, where `published` meant “successfully synced today”) is **superseded**. Frozen rule:

> Discovery is allowed when the required market dataset was successfully synced within the previous 24 hours of the pipeline run. On Monday, the allowed freshness window is 72 hours. The comparison is based strictly on timestamps, not calendar dates. Holiday-aware freshness/trading-calendar handling is explicitly out of scope and is deferred to V5.

Implementation principles:

- Compare last successful dataset-sync timestamp to the **actual pipeline execution timestamp**.
- Normal days: maximum dataset age = **24 hours**.
- Monday (cron/sync timezone, not UTC): maximum dataset age = **72 hours**.
- Inclusive: age `<=` allowed window is fresh enough.
- Missing or unparseable last-success timestamp → blocked.
- Do **not** fall back to “OHLCV exists” or any older dataset beyond the window.
- Do **not** use calendar dates (“synced today”) as the gate.
- Do **not** introduce exchange-calendar or holiday logic.
- Do **not** introduce completeness scores or extra freshness rules.
- `DataEngine::datasetStatus()['published']` may still mean synced-today for inspection/UI; the pipeline gate **must not** use that boolean.

**Implementation (2026-08-27, corrected):** `DatasetFreshnessGate` evaluates age from `DailyMarketSyncService::lastSuccessfulSyncAt()` against `PipelineRun.started_at`. If not allowed, the run is saved as `failed` with reason `dataset_not_fresh` and a `DomainException` (`DATASET_NOT_FRESH`) is thrown before Discovery. Incomplete daily sync no longer overwrites the last successful `last_daily_market_sync_at` timestamp.

### V4-FEAT-005 — Evaluation market regime (COMPLETE)

**PO decision (2026-08-27):** MarketAnalysisEngine is the authoritative source. Categorical `market_regime` remains Bullish / Neutral / Bearish from existing `regimeFromPhase()`. Evaluation keeps its 0–100 factor model. Frozen numeric mapping: Bullish→100, Neutral→50, Bearish→0. Do not use sentiment. Do not create phase-specific scores (Strong Bull and Recovery are both Bullish → 100).

**Implementation (2026-08-27):** `EvaluationEngine` reads `MarketAnalysisEngine::latest()` once per run and maps the categorical value through `MarketRegimeScoreMapper`. Factor key `market_regime` stores the numeric score; evidence also stores categorical `market_regime` and `market_regime_score`. Unavailable Market Analysis still returns Neutral → 50. Backtest `AsOfFactorScorer` remains a 50 stub (historical leakage). `regimeFromPhase()` is unchanged.

---

## 4. Genuine V4 specification decisions (frozen PO rules — not implemented)

These **6** IDs remain the V4 specification register (separate from the 22 active V4 features and from V5). The Product Owner resolved all six on **2026-08-26**.

**Status:** `DECIDED` — the rule is frozen. **Not** `COMPLETE`. V3 did **not** implement these rules. Do not treat V3’s current safe behaviour (below) as the V4 target.

Canonical home for these rules is **this file** (`V4-SPEC-*`). Do **not** duplicate them as new V1 `SD-xxx` rows or as V3 `OD-*` / `DEP-*` (V3 remains frozen). V1 governance pointer: [`architecture/governance/SPECIFICATION_DECISIONS.md`](architecture/governance/SPECIFICATION_DECISIONS.md) (post-V3 note).

### Product philosophy (binds all six)

The application is primarily for **personal use** and a small number of trusted friends. Public/commercial use is a distant possibility, not the current design target.

- Prefer **simplicity** over penny-accurate accounting.
- Prefer **broader useful functionality** over exhaustive accounting precision.
- Edge cases may be ignored when the simplest reasonable solution is sufficient.
- A **clear money trail** still matters (where money came from and where it went).
- Do **not** introduce enterprise accounting, tax-lot, or generalized corporate-action engines the PO did not request.

| ID | Frozen rule (one line) | Status |
|----|------------------------|--------|
| V4-SPEC-001 | Same-stock adoption merge uses simple weighted-average cost; one Strategy position; no lot accounting; final avg rounded to 2 dp half-up | DECIDED |
| V4-SPEC-002 | Do not model rights issues as a special CA; exercised shares are a normal purchase | DECIDED |
| V4-SPEC-003 | For splits and bonuses only, apply the ratio to qty, cost/avg, trailing high, stop, and target | DECIDED |
| V4-SPEC-004 | Cash-ledger special movements are exactly LOAN, RECALL, BRIDGE; signed amount sets cash direction | DECIDED |
| V4-SPEC-005 | Ambiguous cross-owner sells require explicit Strategy/owner attribution; never guess | DECIDED |
| V4-SPEC-006 | One live broker account may hold many Lido Strategies; broker = aggregate, Lido = logical ownership | DECIDED |

---

### V4-SPEC-001 — Same-stock unmanaged adoption cost-basis / multi-lot merge

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). Not implemented. |
| **Why V3 left it** | §10.4 requires merge when the destination Strategy already owns the symbol; cost math was never frozen (OD-15 = entry-date continuity only). This is **not** DEP-ADOPT-MERGE (lending-funded ownership). |
| **Current V3 behaviour** | **422** reject; no invented weighted average. That V3 safety remains until V4 implements this rule. |
| **PO decision** | Use a **simple weighted-average merge**. Portfolio-level accounting, not tax-lot accounting. |
| **Frozen rule** | When an unmanaged holding is adopted into a Strategy that already owns the same stock: combine quantities; combine total cost; compute weighted-average cost; treat the result as **one** Strategy position. **Do not** introduce FIFO, LIFO, tax lots, or separate lot accounting. |
| **Example** | Existing Strategy 50 @ ₹1,000 = ₹50,000. Unmanaged 100 @ ₹1,200 = ₹120,000. Result: **150 shares**, total cost **₹170,000**, unrounded average ₹1,133.333…, stored/resulting average cost **₹1,133.33**. |
| **Implementation clarification (rounding)** | Calculate the weighted-average cost from the available source values. **Do not** round the individual source values before the calculation. Round **only** the final resulting average cost. Final average cost is rounded to **exactly 2 decimal places** using **normal half-up** rounding. Frozen wording: final weighted-average cost rounded to 2 decimal places using half-up rounding; source values are not rounded before calculation. **Do not** introduce additional rounding policy. |
| **Implementation implications** | V4 may now implement this merge instead of 422. Do not invent extra lot or tax semantics. Related FEAT rows stay `OPEN`. Still **DECIDED**, not `COMPLETE`. |

---

### V4-SPEC-002 — Rights issues

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). Not implemented as a rights-issue feature — by design there is **no** special rights CA to build. |
| **Why V3 left it** | OD-10 freezes parent-owner **quantity** only; rights formulas were not decided. |
| **Current V3 behaviour** | Apply path: **split/bonus only**. |
| **PO decision** | **Do not** model rights issues as a special corporate-action operation. |
| **Frozen rule** | Existing holdings are **not** automatically changed because of a rights issue. Do **not** create or manage rights entitlements. If the user actually exercises the rights and receives additional shares, record those shares as a **normal purchase** at the actual subscription price. Do **not** introduce special rights-issue cost-basis or valuation mathematics. |
| **Implementation implications** | Do not add a rights-issue CA type, entitlement ledger, or rights valuation engine. V3 split/bonus apply path is unchanged by this decision. Mergers/demergers are out of scope here. |

---

### V4-SPEC-003 — Corporate-action restatement (splits and bonuses)

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). Not implemented. |
| **Why V3 left it** | Explicit OD-10 leftover: cost / trailing-high / stop / target restatement after CA. |
| **Current V3 behaviour** | Ownership attachment only (quantity follows parent owner). OD-14 raw `close_price` does **not** restate cost, stop, or trailing high. |
| **PO decision** | For corporate actions **already supported** (splits and bonuses), apply the appropriate ratio **consistently** to quantity, cost / average price, trailing high, stop-loss, and target. |
| **Frozen rule** | Example **1:2 split:** 100 shares @ ₹100 → 200 shares @ ₹50. A trailing high of ₹150 becomes ₹75; stop and target scale the same way. **Do not** expand this into a generalized corporate-action accounting engine. Rights, mergers, demergers, etc. are **outside** this rule (rights: V4-SPEC-002). |
| **Implementation implications** | V4 may implement split/bonus restatement of those five fields. Do not build a generic CA math subsystem. V3 quantity-ownership (OD-10) stays; this decision adds restatement **on top** of that ownership rule, and only when V4 implements it. Related FEAT rows stay `OPEN`. |

---

### V4-SPEC-004 — Cash-ledger entry types for loan / recall / bridge

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). Not implemented. |
| **Why V3 left it** | §22 / DEP-ADOPT-MERGE: capital tables were sufficient; dedicated ledger kinds were never required. |
| **Current V3 behaviour** | No dedicated loan/recall/bridge cash-ledger kinds. |
| **PO decision** | Use **dedicated cash-ledger entry types** for relevant loan, recall, bridge, and corresponding money movements so the money trail is understandable. |
| **Frozen rule** | Use **exactly three** semantic special-movement transaction types: **LOAN**, **RECALL**, **BRIDGE**. The amount is **signed** and determines cash direction: **positive** = money enters trading cash; **negative** = money leaves trading cash. **Do not** create separate directional types (`LOAN_IN`, `LOAN_OUT`, `RECALL_IN`, `RECALL_OUT`, `BRIDGE_IN`, `BRIDGE_OUT`). An optional note/reference may provide human context; it is **not** part of the accounting logic. Frozen wording: LOAN, RECALL, BRIDGE are the three semantic special-movement types; signed amount determines cash direction. Prefer simplicity over exhaustive accounting semantics. **Do not** turn this into a full accounting or tax ledger. |
| **Implementation implications** | V4 may add these three ledger kinds with signed amounts. Do not enumerate a chart of accounts beyond this set. V4-UX-003 remains a historical note (no separate UX row). Related FEAT rows stay `OPEN`. Still **DECIDED**, not `COMPLETE`. |

---

### V4-SPEC-005 — Cross-owner sell attribution

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). Not implemented. |
| **Why V3 left it** | Spec left some cross-owner sell edges conservative. |
| **Current V3 behaviour** | Conservative unmanaged / attributable paths; no invented proportional split. |
| **PO decision** | Require **explicit attribution** whenever a sell cannot be unambiguously attributed. Goal: trustworthy Strategy-level performance, not maximum automation. |
| **Frozen rule** | If a sell is unambiguously attributable to an owner/Strategy, use that attribution. If multiple owners/Strategies could legitimately be affected and the transaction does **not** identify the owner, **do not guess** — require the user to specify the Strategy/owner. **Do not** use proportional allocation, largest-position, oldest-position, FIFO, or any other automatic attribution rule. |
| **Implementation implications** | V4 may add an explicit-attribution prompt/API when ambiguous. Do not auto-allocate. Related FEAT rows stay `OPEN`. |

---

### V4-SPEC-006 — Live-era portfolio exclusivity / broker-account binding

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). Not implemented (live broker attribution). |
| **Why V3 left it** | V3 §32 Decision 11 deferred exclusivity/binding to the broker era. |
| **Current V3 behaviour** | Multi-portfolio paper/manual continues. No live broker automation (SD-010). |
| **PO decision** | A **single live broker account may contain multiple Lido Strategies**. Do **not** impose one-broker-account / one-Strategy. |
| **Frozen rule** | Example: Broker Account → Momentum owns Reliance 100; Value owns Reliance 50. **Broker** = actual aggregate holdings and executions. **Lido** = logical Strategy ownership and attribution. The broker does not need to understand Lido Strategy ownership. Live orders/executions therefore need sufficient **Lido-side** Strategy attribution. |
| **Implementation implications** | Unblocks design of V4-FEAT-001 / FEAT-002; those FEATs remain `OPEN`. Do not implement broker automation in this documentation task. Do not require broker-side strategy tags. |

---

## 5. Closed in V3 strict pass (removed from active backlog)

| Former ID | Resolution |
|-----------|------------|
| V4-BUG-001 | **FIXED** — deterministic `schedulerTimestamp` restored |
| V4-BUG-002 | **Reclassified** → V4-FEAT-021 (not a V3 bug; Evaluation≠Strategy-fit) |
| V4-BUG-003 | **FIXED** in V3 closure — max position enforced |
| V4-BUG-004 | **FIXED** — `DailyMarketDataJobTest` no longer uses RefreshDatabase |
| V4-UX-001 | **IMPLEMENTED IN V3** — Strategy Portfolio Rules OD-16 window control |
| Discovery sidebar / strategy create-archive UI | **IMPLEMENTED IN V3** (2026-08-26 product-surface closure) — not V4 |
| V4-UX-002 | Duplicate of FEAT-003 (B4) — kept only as FEAT-003 |
| V4-UX-003 | Blocked presentation of SPEC-004 — no separate UX row |
| V4-TD-001–016 | Reclassified to FEAT-021+ or FEAT-004 / docs; none remain as open V3 debt |
| V4-HIST-* | **Archived** — see §6; not active backlog |

**Open V3 bugs / V3 TD / V3 UX:** **none**.

---

## 6. Historical archive (closed — not active V4 work)

These are **not** open tasks. Kept only as pointers.

| Former ID | Note |
|-----------|------|
| HIST-001 | OD-17 numeric 550 ceiling **resolved**; do not reintroduce |
| HIST-002 | WS1–WS4 / §34 / §10.4–§10.5 / §29 / zero-own UNFUNDED **COMPLETE** |
| HIST-003 | SD-035 V2 eleven deferred — **V2 CLOSED** |
| HIST-004 | PB-044 multi-strategy isolation **superseded by V3 OD-01** |
| HIST-005 | PB-003 deep review SL **superseded by §34.3**; residual deep-review UX would be a *new* FEAT if desired |
| HIST-006 | Pre-V3 PRODUCT_BACKLOG / TECHNICAL_DEBT / KNOWN_LIMITATIONS are **indexes only** |
| HIST-007 | WS4 delta build plan **largely implemented**; keep as historical plan |

---

## 7. Acceptance rules

A SPEC is **DECIDED** when the Product Owner has frozen the rule in this register. That is **not** `COMPLETE`.

An item may be marked **COMPLETE** only when: frozen decision (if SPEC), **production behaviour**, focused tests, V3 regressions green, and `implementation.md` updated — without inventing unspecified math.

Moving a FEAT from V4 to V5 does **not** satisfy acceptance. Freezing V4-SPEC-001–006 does **not** mark any FEAT `COMPLETE`. V5 rows stay `OPEN` until the capability is actually implemented.

---

## 8. Change log

| Date | Change |
|------|--------|
| 2026-08-25 | Initial register from Final V3 Completion Audit + backlog packs |
| 2026-08-26 | V4-BUG-001 fixed; V4-BUG-003 marked complete |
| 2026-08-26 | **Strict closure rewrite:** removed open V3 bugs/TD/UX/HIST from active backlog; OD-16 UI + DailyMarketDataJobTest fixed in V3; former TD/UX rows folded into genuine V4 FEAT or SPEC only |
| 2026-08-26 | V3 product-surface closure: Discovery removed from Market sidebar; strategy Create-from-factory + Archive UI completed in V3 (not added to this register) |
| 2026-08-26 | **Product Owner V4/V5 split:** former 36-item V4 feature register split into **22 active V4 features** and **14 V5-deferred features**. Moved to V5 (IDs unchanged, still OPEN, not COMPLETE): V4-FEAT-003, 004, 007, 016, 017, 018, 019, 020, 030, 031, 033, 034, 035, 036. Active V4 remains: 001, 002, 005, 006, 008–015, 021–029, 032. V4-SPEC-001–006 remain the V4 specification register. This is roadmap/prioritization, not an implementation claim. |
| 2026-08-26 | **Product Owner resolved all six V4 specification decisions (V4-SPEC-001 through V4-SPEC-006)**, establishing deliberately simple portfolio/corporate-action/accounting rules ahead of V4 implementation. These are **frozen product/specification decisions, not implemented functionality**. No FEAT status changed. |
| 2026-08-26 | **PO implementation clarifications:** V4-SPEC-001 weighted-average rounding (final average to 2 decimal places, half-up; do not round source values first); V4-SPEC-004 special cash-ledger types are exactly **LOAN**, **RECALL**, **BRIDGE** with signed amount for cash direction. Both remain **DECIDED**, not implemented. |
| 2026-08-26 | **V4-FEAT-021 PO decision:** Strategy catalogue indicator parameters (`rsi_period`, `lookback_days`, `sma_fast`, `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`) override global Evaluation config when valid; otherwise existing globals/defaults apply. Feature remains **OPEN** until implementation is verified. |
| 2026-08-26 | **V4-FEAT-021 COMPLETE:** `EvaluationParameterResolver` wires the seven catalogue parameters into EvaluationEngine (override-or-fallback). Scoring weights unchanged. Tests: `EvaluationParameterResolverTest`, `EvaluationParameterOverrideTest`. |
| 2026-08-27 | **V4-FEAT-022 PO decision:** Only a `published` dataset may enter the daily decision pipeline; otherwise stop before Discovery with no evaluation/recommendations and no stale-data fallback. Feature remains **OPEN** until implementation is verified. |
| 2026-08-27 | **V4-FEAT-022 COMPLETE (superseded):** `DailyDecisionPipeline` hard-gated on `DataEngine::datasetStatus()['published']` before Discovery. Tests: `DatasetPublishGateTest`. |
| 2026-08-27 | **V4-FEAT-022 PO clarification (not a new feature):** Timestamp freshness supersedes “synced today” / `published === true`. Normal days 24 hours; Monday 72 hours; inclusive; no holiday/exchange calendar (V5). Feature set **OPEN** while the implementation is corrected. |
| 2026-08-27 | **V4-FEAT-022 COMPLETE (correction):** `DatasetFreshnessGate` compares last successful sync timestamp to pipeline `started_at`. Tests: `DatasetFreshnessGateTest`, `DatasetPublishGateTest`. |
| 2026-08-27 | **V4-FEAT-005 PO decision:** Evaluation consumes MarketAnalysisEngine categorical `market_regime` (Bullish/Neutral/Bearish); numeric factor is 100/50/0. No new regime calculation; sentiment unused. Feature remains **OPEN** until implementation is verified. |
| 2026-08-27 | **V4-FEAT-005 COMPLETE:** `EvaluationEngine` maps `MarketAnalysisEngine::latest()['market_regime']` via `MarketRegimeScoreMapper`. Tests: `MarketRegimeScoreMapperTest`, `EvaluationMarketRegimeTest`. |

## Appendix — Former ID map

| Former | Now |
|--------|-----|
| V4-BUG-002 / V4-TD-001 | V4-FEAT-021 |
| V4-TD-002 | V4-FEAT-022 |
| V4-TD-003 | V4-FEAT-023 |
| V4-TD-004 | V4-FEAT-024 |
| V4-TD-005 | V4-FEAT-004 (V5-classified 2026-08-26) |
| V4-TD-006 | V4-FEAT-025 |
| V4-TD-007 | V4-FEAT-026 |
| V4-TD-008/009 | V4-FEAT-027 |
| V4-TD-010/011 | V4-FEAT-028 |
| V4-TD-012 | V4-FEAT-029 |
| V4-TD-013 | V4-FEAT-030 (V5-classified 2026-08-26) |
| V4-TD-014 | V4-FEAT-031 (V5-classified 2026-08-26) |
| V4-TD-015 | V4-FEAT-032 |
| V4-TD-016 | Docs reconciled (Sanctum is auth SoT); optional token API → V4-FEAT-036 (V5-classified 2026-08-26) |
| V4-UX-001 | **V3 implemented** |
| V4-UX-004 | V4-FEAT-033 (V5-classified 2026-08-26) |
| V4-UX-005 | V4-FEAT-034 (V5-classified 2026-08-26) |
| V4-UX-006 | V4-FEAT-035 (V5-classified 2026-08-26) |
| V4-UX-007 | V4-FEAT-036 (V5-classified 2026-08-26) |
