# Trading OS MVP Demo Checklist

Acceptance test for the end-to-end Trading Operating System workflow on Lido Portfolio.

**MVP is complete when every step below can be demonstrated without manual database edits.**

Prerequisites: MySQL running, `php artisan migrate` applied (includes `portfolio_tos_*` tables), app reachable (`php artisan serve --port=8001` + frontend assets), logged-in user with an active portfolio.

---

## 1. Market data

1. Ensure holdings and/or watchlist symbols exist for the active portfolio.
2. (Admin) Sync prices: Dashboard **Sync prices for today** or Settings → universe/holdings sync as usual.
3. Optional API check: `GET /api/v1/dataset/status` returns securities/price stats.

**Pass:** Latest closes available for symbols you will analyse.

---

## 2. Discovery → Candidates UI

1. Open **Discovery** in the main nav (`/candidates`).
2. Click **Run discovery** (or run the full pipeline later).
3. Confirm a candidate list appears with source and discovery reason.
4. Open **Evidence** on a row; confirm patterns/signals JSON.

**Pass:** At least one candidate is visible with evidence.

---

## 3. Evaluation UI

1. Open **Evaluations** (`/evaluations`).
2. Click **Run evaluation** (requires a completed discovery run).
3. Confirm ranked rows with score, confidence, and explanation.
4. Open **Details** — indicators, component scores, passed/failed rules.

**Pass:** Ranked evaluation results are explainable from the UI.

---

## 4. Recommendations + User Review

1. Open **Recommendations** (`/recommendations`).
2. Click **Run decision pipeline** (runs discovery → evaluation → recommendations → optional Telegram → review report).
4. Confirm recommendations: trade actions (Buy / Buy More / Sell Partial / Sell All) under **Trade recommendations**; Hold / Watch under **Market insights**.
5. Open a trade row — confirm Market Opinion, Portfolio Decision, allocations, Execution Plan, evidence.
6. Accept / Defer / Reject only on trade rows; insights are view-only.
7. Confirm review history for actionable rows.

**Pass:** Accept / Reject / Defer persist; history shows user + timestamp + decision.

---

## 4b. Undo review / undo fill

1. Accept (or Reject / Defer) an actionable recommendation.
2. Click **Undo decision — reopen for review** (use **Show all history** to find Rejected).
3. Confirm status returns to `pending_review` and Accept/Defer/Reject are available again.
4. Accept → Execute a fill → on **Transactions**, delete that row.
5. Confirm toast mentions TOS reopen; recommendation is `pending_review` again; order is `cancelled`.

**Pass:** Mistakes can be undone without database edits; executed path only via transaction delete.

---

## 5. Execution (manual order lifecycle)

1. On an **accepted** recommendation, use **Record execution**:
   - **Pending** — creates order with status `pending` (no ledger fill yet), **or**
   - **Execute** — creates and fills immediately with price/qty.
2. Open **Review** (`/review`) → **Orders** table.
3. For a pending order: **Execute** (enter fill price) or **Cancel**.
4. Confirm holdings/transactions updated after execute; recommendation becomes `executed`.

**Pass:** Orders move through Pending → Executed or Pending → Cancelled without broker automation.

---

## 6. Review dashboard + outcomes

1. Open **Review** (`/review`).
2. Confirm portfolio snapshot cards (value, XIRR, accept/reject counts).
3. Confirm **Recommendation outcomes** table: reference price, current price, gain/loss.
4. Confirm recent review decisions list.

**Pass:** Outcomes and counts reflect the recommendations you reviewed/executed.

---

## 7. Notification history

1. With Telegram configured for the portfolio, run the pipeline with notifications on.
2. Open **Notifications** (`/notification-history`).
3. Confirm rows show channel, status, attempts, created/delivered times.
4. If a delivery failed, use **Retry**.

**Pass:** Notification history is visible (Telegram only). Skip if Telegram intentionally disabled — then confirm empty state message.

---

## Quick API smoke (optional)

```text
POST /api/v1/pipeline/run
GET  /api/v1/candidates
GET  /api/v1/evaluations
GET  /api/v1/recommendations
POST /api/v1/recommendations/{id}/review  { "decision": "accepted" }
POST /api/v1/orders  { ..., "execute_now": false }
POST /api/v1/orders/{id}/execute  { "price": ... }
GET  /api/v1/review/dashboard
GET  /api/v1/notifications
```

Automated coverage: `php artisan test --filter=TradingOsPipelineTest`

---

## Sign-off

| Step | Pass? | Notes |
|------|-------|-------|
| 1 Market data | | |
| 2 Discovery | | |
| 3 Evaluations | | |
| 4 User review | | |
| 5 Orders | | |
| 6 Review dashboard | | |
| 7 Notifications | | |

When all applicable rows pass, the Trading OS **MVP workflow is complete**.
