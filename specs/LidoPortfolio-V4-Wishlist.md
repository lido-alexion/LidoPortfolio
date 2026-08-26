# LidoPortfolio V4 Wishlist

| Field | Value |
|-------|-------|
| **V3 Status** | **V3 STRICTLY COMPLETE** (strict register-to-implementation pass 2026-08-26) |
| **Document type** | Forward-looking V4 register only |
| **Created** | 2026-08-25 |
| **Last reconciled** | 2026-08-26 (strict closure) |
| **Canonical path** | [`specs/LidoPortfolio-V4-Wishlist.md`](LidoPortfolio-V4-Wishlist.md) |
| **Related** | [`LidoPortfolio-V3-Specification.md`](LidoPortfolio-V3-Specification.md) · [`../implementation.md`](../implementation.md) |

## Purpose

This register holds **only**:

1. **Genuine new V4 functionality** that was not part of V3 normative scope, **or**
2. **Genuine unresolved product/spec decisions** where implementing correctly would require inventing a rule V3 never defined.

It is **not** a deferral bin for V3 bugs, V3 technical debt, V3 UX polish, or historical notes.

**Do not reopen frozen V3 workstreams** (WS2–WS4, §34.3–§34.4, OD-10, OD-17, §10.4–§10.5, §29 product surfaces, zero-own UNFUNDED lending, B3 Dashboard reserve warning, OD-16 engine + Strategy window control) unless a *new* regression is proven.

### Status values

`OPEN` · `BLOCKED` (waiting on SPEC_DECISION) · `COMPLETE`

---

## 1. What V3 closed (do not reopen)

Normative V3 is implemented, tested, and documented, including (non-exhaustive): multi-strategy / OD-01, WS2–WS4, §34.3–§34.4, OD-10/17, §10.4–§10.5, §29 surfaces, OD-12 settings, max_holdings, archive, capital badges, §5.7 lending limits, §19 success flags, §30 capital/lending notifies, **OD-16 Strategy weakest-window control**, deterministic `schedulerTimestamp`, and `DailyMarketDataJobTest` harness fix.

Living detail: [`implementation.md`](../implementation.md).

---

## 2. Genuine V4 features (new functionality)

| ID | Item | Why genuinely V4 | Priority | Status |
|----|------|------------------|----------|--------|
| V4-FEAT-001 | Broker / live execution automation | V3 §3 / §32 Decision 11 + SD-010: V3 does **not** require broker automation; manual/semi-auto fill is V3 | P2 | OPEN |
| V4-FEAT-002 | Advanced orders (GTT / stop / target / partial fills) | Broker-era order types; depends on FEAT-001 | P3 | OPEN |
| V4-FEAT-003 | B4 persistent app-wide critical banner | V3 §29: **B4 is explicit wishlist**; B3 Dashboard reserve warning is current V3 | P2 | OPEN |
| V4-FEAT-004 | Notification channel abstraction + email/webhook | V3 §30 requires Telegram/in-app capability (shipped); multi-channel is new | P2 | OPEN |
| V4-FEAT-005 | Market regime assessment (non-stub) | Not a V3 normative engine; Evaluation stub residual | P2 | OPEN |
| V4-FEAT-006 | Liquidity & Tradability indicator calculators | Indicator Registry expansion; not V3 SoT | P2 | OPEN |
| V4-FEAT-007 | Indicator Registry deeper versioning / remaining cutover | SD-033 residual beyond V3 registries already shipped | P2 | OPEN |
| V4-FEAT-008 | Trading Artifact Framework remaining phases | SD-034 residual beyond Screener/Strategy registries shipped in V3 | P2 | OPEN |
| V4-FEAT-009 | Review reports list UI + deeper metrics | New Review UX beyond V3 Dashboard/API | P3 | OPEN |
| V4-FEAT-010 | Pipeline ops hardening beyond shipped F148/F149 | F148/F149 schedule hooks are V3-complete; further ops defaults are new | P2 | OPEN |
| V4-FEAT-011 | Stocks admin SPA surface | Admin product expansion; not V3 | P3 | OPEN |
| V4-FEAT-012 | Admin force-logout of other users (PD-007) | Auth product expansion; not V3 | P3 | OPEN |
| V4-FEAT-013 | Cash-as-of / export / compare polish | F014 residual polish; not V3 | P3 | OPEN |
| V4-FEAT-014 | Backtest history “Duplicate” action | New UX convenience; not V3 | P3 | OPEN |
| V4-FEAT-015 | Tax reporting / attribution / benchmarks | New product surface | P3 | OPEN |
| V4-FEAT-016 | Mobile application | New client | TBD | OPEN |
| V4-FEAT-017 | AI assistant (non-decision) | New assistive surface | TBD | OPEN |
| V4-FEAT-018 | ML scoring models | Optional non-deterministic path; V3 is deterministic | TBD | OPEN |
| V4-FEAT-019 | Options / crypto / ETF products | Markets expansion | TBD | OPEN |
| V4-FEAT-020 | Live paper / portfolio replay modes | New simulation modes | TBD | OPEN |
| V4-FEAT-021 | Strategy indicator params → EvaluationEngine wiring | EvaluationEngine is a separate TOS path from V3 Strategy fit/scoring; wiring is a V4 Evaluation design choice (was TD-19 / V4-BUG-002 / V4-TD-001) | P1 | OPEN |
| V4-FEAT-022 | Hard dataset publish / validation gate | Pre-discovery data-platform hardening (was V4-TD-002) | P1 | OPEN |
| V4-FEAT-023 | Immutable dataset versioning | Data-platform hardening (was V4-TD-003) | P2 | OPEN |
| V4-FEAT-024 | Recommendation `markExecuted` ownership refactor | Architecture cleanup (was V4-TD-004) | P3 | OPEN |
| V4-FEAT-025 | OpenAPI for `/api/v1` | Machine-readable contract (was V4-TD-006) | P3 | OPEN |
| V4-FEAT-026 | Vitest / E2E smoke for TOS UI | New test harness (was V4-TD-007) | P2 | OPEN |
| V4-FEAT-027 | Split TradingOsController / shared React hooks | Maintainability (was V4-TD-008/009) | P3 | OPEN |
| V4-FEAT-028 | Structured logging / pagination consistency | Platform hardening (was V4-TD-010/011) | P3 | OPEN |
| V4-FEAT-029 | Pluggable Evaluation rules modules | Evaluation architecture (was V4-TD-012) | P3 | OPEN |
| V4-FEAT-030 | CI workflow for PHPUnit + frontend build | Ops (was V4-TD-013) | P2 | OPEN |
| V4-FEAT-031 | Production secrets / single-folder deploy hardening | Ops (was V4-TD-014) | P3 | OPEN |
| V4-FEAT-032 | Repository layer for TOS aggregates | Architecture (was V4-TD-015) | P3 | OPEN |
| V4-FEAT-033 | Discovery inline default screener | Discovery UX enhancement (was V4-UX-004) | P3 | OPEN |
| V4-FEAT-034 | Richer Evaluation history UX | Evaluation UX (was V4-UX-005) | P3 | OPEN |
| V4-FEAT-035 | TypeScript / TanStack Query / AG Grid migration | Stack migration (was V4-UX-006) | TBD | OPEN |
| V4-FEAT-036 | Optional JWT/token API for non-SPA clients | Auth expansion; Sanctum SPA is V3 (was V4-UX-007 / TD-016 drift → docs reconciled) | TBD | OPEN |

Notification channel interface (old V4-TD-005) is covered by **V4-FEAT-004**.

---

## 3. Genuine V4 specification decisions (cannot invent)

| ID | Decision Needed | Why V3 cannot close it | Current safe V3 behavior | Status |
|----|-----------------|------------------------|--------------------------|--------|
| V4-SPEC-001 | Same-stock unmanaged adoption **cost-basis / multi-lot merge** math | §10.4 requires merge when destination owns symbol; **cost math never frozen** (OD-15 = entry-date continuity only) | **422** reject; no invented WAVG | BLOCKED |
| V4-SPEC-002 | Corporate-action **rights-issue** (and non-bonus/split types) math | OD-10 freezes parent-owner quantity only; rights formulas not decided | Apply path: split/bonus only | OPEN |
| V4-SPEC-003 | CA **cost / trailing-high / stop / target** restatement after CA | Explicit OD-10 leftovers | Ownership attachment only | OPEN |
| V4-SPEC-004 | Optional **cash-ledger entry types** for loan/recall/bridge | §22 / DEP-ADOPT-MERGE: capital tables are sufficient; dedicated ledger kinds never required | No dedicated ledger kinds | OPEN |
| V4-SPEC-005 | Further **cross-owner sell** attribution policy | Spec leaves some edges conservative | Conservative unmanaged / attributable paths | OPEN |
| V4-SPEC-006 | Live-era **portfolio exclusivity** / broker account binding | Decision 11 deferred to broker era | Multi-portfolio paper/manual continues | OPEN |

**Rule:** Do not implement SPEC-* by inventing formulas. Freeze an SD / OD / DEP (or V4 equivalent) first.

---

## 4. Closed in V3 strict pass (removed from active backlog)

| Former ID | Resolution |
|-----------|------------|
| V4-BUG-001 | **FIXED** — deterministic `schedulerTimestamp` restored |
| V4-BUG-002 | **Reclassified** → V4-FEAT-021 (not a V3 bug; Evaluation≠Strategy-fit) |
| V4-BUG-003 | **FIXED** in V3 closure — max position enforced |
| V4-BUG-004 | **FIXED** — `DailyMarketDataJobTest` no longer uses RefreshDatabase |
| V4-UX-001 | **IMPLEMENTED IN V3** — Strategy Portfolio Rules OD-16 window control |
| V4-UX-002 | Duplicate of FEAT-003 (B4) — kept only as FEAT-003 |
| V4-UX-003 | Blocked presentation of SPEC-004 — no separate UX row |
| V4-TD-001–016 | Reclassified to FEAT-021+ or FEAT-004 / docs; none remain as open V3 debt |
| V4-HIST-* | **Archived** — see §5; not active backlog |

**Open V3 bugs / V3 TD / V3 UX:** **none**.

---

## 5. Historical archive (closed — not active V4 work)

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

## 6. Acceptance rules

An item may be marked **COMPLETE** only when: frozen decision (if SPEC), production behavior, focused tests, V3 regressions green, and `implementation.md` updated — without inventing unspecified math.

---

## 7. Change log

| Date | Change |
|------|--------|
| 2026-08-25 | Initial register from Final V3 Completion Audit + backlog packs |
| 2026-08-26 | V4-BUG-001 fixed; V4-BUG-003 marked complete |
| 2026-08-26 | **Strict closure rewrite:** removed open V3 bugs/TD/UX/HIST from active backlog; OD-16 UI + DailyMarketDataJobTest fixed in V3; former TD/UX rows folded into genuine V4 FEAT or SPEC only |

## Appendix — Former ID map

| Former | Now |
|--------|-----|
| V4-BUG-002 / V4-TD-001 | V4-FEAT-021 |
| V4-TD-002 | V4-FEAT-022 |
| V4-TD-003 | V4-FEAT-023 |
| V4-TD-004 | V4-FEAT-024 |
| V4-TD-005 | V4-FEAT-004 |
| V4-TD-006 | V4-FEAT-025 |
| V4-TD-007 | V4-FEAT-026 |
| V4-TD-008/009 | V4-FEAT-027 |
| V4-TD-010/011 | V4-FEAT-028 |
| V4-TD-012 | V4-FEAT-029 |
| V4-TD-013 | V4-FEAT-030 |
| V4-TD-014 | V4-FEAT-031 |
| V4-TD-015 | V4-FEAT-032 |
| V4-TD-016 | Docs reconciled (Sanctum is auth SoT); optional token API → V4-FEAT-036 |
| V4-UX-001 | **V3 implemented** |
| V4-UX-004 | V4-FEAT-033 |
| V4-UX-005 | V4-FEAT-034 |
| V4-UX-006 | V4-FEAT-035 |
| V4-UX-007 | V4-FEAT-036 |
