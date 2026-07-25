# Trading Operating System — Implementation Progress

Working log for evolving Lido Portfolio toward the `/specs` MVP.
Existing stack (Laravel + React + Sanctum + `portfolio_*` tables) is preserved.

## MVP status

**MVP is COMPLETE** for the clarified end-to-end workflow:

Market Data → Discovery → Evaluation → Recommendation → User Review (Approve) → Pending Execution → Manual/Broker Trade → Review

Acceptance demo: [`MVP_DEMO_CHECKLIST.md`](./MVP_DEMO_CHECKLIST.md).

Independent freeze audit (2026-07-25): [`audit/`](./audit/) — verdict **YES** for clarified MVP; release posture **Internal Testing Only** ([`audit/MVP_VERDICT.md`](./audit/MVP_VERDICT.md)).

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

---

## Remaining gaps (post-MVP / future — not blocking)

- Dedicated Discovery Engine Specification document
- Email / webhook / SMS / push channels
- Automated broker execution (Zerodha, GTT, …)
- Strategy entity / multi-strategy isolation
- OpenAPI for `/api/v1`
- Formal Data Engine publish/validation gates & trading calendar product
- Pluggable evaluation rules / market regime
- CI workflow improvements
- Pipeline auto-run after daily sync (config exists; default off)

These do **not** block MVP sign-off per the completion-sprint clarifications.
