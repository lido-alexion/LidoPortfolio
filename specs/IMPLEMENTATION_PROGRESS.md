# Trading Operating System — Implementation Progress

Working log for evolving Lido Portfolio toward the `/specs` MVP.
Existing stack (Laravel + React + Sanctum + `portfolio_*` tables) is preserved.

## MVP status

**MVP is COMPLETE** for the clarified end-to-end workflow:

Market Data → Discovery → Evaluation → Recommendation → User Review (Approve) → Pending Execution → Manual/Broker Trade → Review

Acceptance demo: [`./MVP_DEMO_CHECKLIST.md`](./MVP_DEMO_CHECKLIST.md).

Independent freeze audit (2026-07-25): [`architecture/audit/`](./architecture/audit/) — verdict **YES** for clarified MVP; release posture **Internal Testing Only** ([`architecture/audit/MVP_VERDICT.md`](./architecture/audit/MVP_VERDICT.md)).

---

## Assumptions

| ID | Assumption |
|----|------------|
| A1 | Keep Sanctum session auth (not JWT). Confirmed for MVP. |
| A2 | Keep `portfolio_*` tables; logical domain names need not match physical table names. |
| A3 | New TOS entities use `portfolio_tos_*` tables. |
| A4 | No dedicated Discovery Engine Specification file; Discovery orchestrates PatternScan + Screener services. |
| A5 | Engines under `app/app/Engines/`; wrap existing Services. |
| A6 | REST `/api/v1/*` additive; legacy `/api/*` unchanged. |
| A7 | Discovery sources: patterns, screener hits, holdings/watchlist fallback. |
| A8 | Evaluation weighted scoring is acceptable for MVP (no pluggable rules engine). |
| A9 | Telegram only for MVP notifications. |
| A10 | Broker automation out of MVP; manual execution via Transactions + pending-execution queue. |
| A11 | Recommendations start as `pending_review`; Approve → `pending_execution`; execute separately (SD-025). |
| A12 | Strategy entity deferred; recommendations remain portfolio-scoped. |
| A13 | Data Engine formal publish/validation gates deferred (existing import OK). |

## Deviations from Spec (accepted)

| Spec | Deviation | Why |
|------|-----------|-----|
| JWT auth | Sanctum cookies | Confirmed clarification |
| Separate securities/price_bars tables | Reuse `portfolio_*` | Confirmed clarification |
| Email/webhook channels | Telegram only | Confirmed clarification |
| Strategy entity | Not implemented | Deferred |

---

## Tasks

### Pass 1 (foundation)

- [x] **T0–T15** Engine layer, schema, `/api/v1`, pipeline, Recommendations page (first pass)

### Pass 2 (MVP completion sprint)

- [x] **M1** User Review workflow (`pending_review` → Approve/`pending_execution` | Reject | Defer) + review history
- [x] **M2** Candidates UI (`/candidates`) with filters + evidence
- [x] **M3** Evaluations UI (`/evaluations`) with scores/indicators/explanation
- [x] **M4** Recommendation detail + Approve/Reject/Defer UI
- [x] **M5** Pending execution → manual transaction / cancel (SD-025; legacy orders BC)
- [x] **M6** Review dashboard page (`/review`)
- [x] **M7** Recommendation outcome tracking (ref vs current price)
- [x] **M8** Notification history page (`/notification-history`)
- [x] **M9** Tests updated; `MVP_DEMO_CHECKLIST.md`; progress + `implementation.md`

### PO-approved follow-up TODOs (2026-09-02; not started)

- [x] **REC-UX-001** Replace the misleading “No trade recommendations / market data” empty state with a successful informational-only outcome message when the pipeline produced insights but no actionable trades.
- [x] **REC-UX-002** Show Strategy identity on Recommendation and Market Insight rows so per-strategy results for the same stock do not look like duplicates.
- [x] **REC-DATA-001** Safely retire the two legacy `HOLD_POSITION + pending_execution` rows; informational HOLD records must never be executable.
- [x] **REC-UX-003** Present informational allocation meaningfully (for example, “No position → Watch”) instead of an unexplained `0.00% → 0.00%`.
- [x] **REC-UX-004** Clarify the default open/current scope versus “Show all history,” including the visible time range and pagination behavior.
- [x] **REC-UX-005** Hide `HOLD_POSITION` insights from the Recommendations table by default; add an independent **Show HOLD insights** toggle for review/audit. Preserve HOLD rows and their Evaluation/Recommendation evidence. The toggle applies consistently whether current/open or all-history scope is selected. It does not legitimize legacy `HOLD_POSITION + pending_execution` rows; REC-DATA-001 must still retire those invalid lifecycle records.

---

## Completion log

| When | Task | Notes |
|------|------|-------|
| 2026-07-25 | T0–T15 | First pass: engines + pipeline slice |
| 2026-07-25 | M1–M9 | MVP completion sprint: user review, UIs, order lifecycle, outcomes |
| 2026-07-25 | SD-022 | Actionable (BUY/SELL) vs informational (HOLD/WATCH) recommendation workflows |
| 2026-07-25 | SD-023 | Market Opinion → Portfolio Decision → Execution Plan redesign |
| 2026-07-25 | SD-024 | Undo Accept/Reject/Defer + reopen on TOS fill delete |
| 2026-07-25 | SD-025 | Recommendation approval separated from trade execution |
| 2026-07-25 | SD-026 | Cash management + portfolio-wide capital allocation (reserved cash, ScorePriority allocator) |
| 2026-07-25 | UI | Cash tab (`/cash`): deposit/withdraw/adjust/statement/reservations; Dashboard shows available cash only |
| 2026-07-26 | SD-027 | Strategy Configuration framework: Evaluation facts → Strategy scoring → Recommendation; Strategy UI + APIs |
| 2026-07-26 | SD-028 | Fixed supported indicator catalogue (no plugins / no Add Indicator) |
| 2026-07-26 | SD-029 | Factory Momentum Strategy 1.0 seed + protected factory / duplicate / weight=100 validation |
| 2026-07-29 | SD-029 amended | Single editable Minervini Strategy; Save in place; duplicate/version UX removed; weight auto-normalise |
| 2026-07-26 | SD-030 | Strategies consume Screeners; Minervini Trend Template factory screener; exit strategy; eligibility explainability |
| 2026-07-26 | SD-031 | Analytics Ownership Model: Stock / Evaluation / Portfolio / Market owners; Dashboard/Watchlist/Portfolio/Discovery page questions |
| 2026-07-26 | SD-032 | Market Analysis Engine: benchmark OHLCV → sentiment/phase/analytics; Dashboard + Rec/Strategy/Portfolio consume |
| 2026-07-30 | SD-033 | Indicator Registry **design** accepted (docs only): unified metadata/discovery; preserve TI/Evaluation/Strategy calc; types Primary/Composite/Metric; Admin UI spec; planned Liquidity/Tradability metadata; PB-054/055/056/057 + TD-19 |
| 2026-07-30 | Plan | Indicator Registry **implementation plan** (Epics 1–7 / Stories / Tasks) — `specs/architecture/indicators/10-Indicator-Registry-Implementation-Plan.md`; still no production code |
| 2026-07-30 | Epic 1 | Indicator Registry **foundation** coded: `App\Services\Indicators\*` + factory seed + unit tests; no consumer cutover; calculators untouched |
| 2026-07-30 | Epic 2 | Indicator Registry **migration**: ScreenerCatalog + SupportedIndicators façades; seeds SoT; min-bars helper; validator; façade parity tests; no calc/UI changes |
| 2026-07-30 | SD-034 | Trading Artifact Framework **design** accepted (docs only): shared envelope for Indicator / Screener / Strategy; absorb Strategy Templates; preserve `definition_json` / `config_json`; PB-058/059/060 |
| 2026-08-28 | V4-FEAT-008 | TAF **remainder** deferred to V5 (PO). Envelope/registries/package I/O/Create-Enable-Archive/AI docs already shipped; do not treat as unimplemented. |

---

## Remaining gaps (post-MVP / future — not blocking)

- Indicator Registry Admin UI + later phases (PB-055+; Epics 1–2 metadata/façades landed)
- Trading Artifact Framework **remainder** (V5 **V4-FEAT-008**): immutable published versions, sharing/distribution, extra AI draft UX, dependency dashboards, rollback, bundle UI, fork workflows. Envelope/registries/package I/O already shipped.
- Wire Strategy indicator parameters into Evaluation (PB-054 / TD-19)
- Liquidity / Tradability indicator calculators (PB-057)
- Dedicated Discovery Engine Specification document
- Email / webhook / SMS / push channels
- Automated broker execution (Zerodha, GTT, …)
- Multi-strategy Create/Enable/Archive shipped in V3; remaining TAF library/binding dual-UX is **V5 V4-FEAT-008**
- OpenAPI for `/api/v1`
- Formal Data Engine publish/validation gates & trading calendar product
- Pluggable evaluation rules / multi-benchmark market analysis / constituent breadth V2
- CI workflow improvements
- Pipeline auto-run after daily sync (config exists; default off)

These do **not** block MVP sign-off per the completion-sprint clarifications.
