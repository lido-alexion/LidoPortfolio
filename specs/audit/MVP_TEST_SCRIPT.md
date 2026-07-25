# MVP End-to-End Manual Test Script

**Purpose:** Acceptance walkthrough for the Trading Operating System MVP.  
**Aligned with:** `specs/MVP_DEMO_CHECKLIST.md`, Roadmap §9.  
**Pass rule:** Complete without manual database edits.

---

## 0. Setup

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 0.1 | Start MySQL | Server listening | Can connect with `.env` credentials |
| 0.2 | `cd app` → `php artisan migrate --force` | Migrations apply including `portfolio_tos_*` | Tables exist; no migration errors |
| 0.3 | Start app (`php artisan serve --host=127.0.0.1 --port=8001`) + frontend (`npm run dev` or built assets) | App loads | Browser opens `http://127.0.0.1:8001` |
| 0.4 | Log in (seeded admin or existing user) | Authenticated SPA | Nav shows Candidates / Evaluations / Recommendations / Review / Notify log |
| 0.5 | Select/create active portfolio with ≥1 holding and/or watchlist symbols | Active profile set | Holdings or watchlist visible |
| 0.6 | Optional: configure Telegram for portfolio | Settings accept bot/chat | Skip if testing without notifications |

**Automated assist:** `php artisan test --filter=TradingOsPipelineTest` should pass before manual demo.

---

## 1. Importing market data

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 1.1 | Ensure symbols exist on holdings/watchlist | Symbols listed | At least one equity symbol |
| 1.2 | Dashboard **Sync prices for today** (or Settings universe/holdings sync) | Sync completes | Latest close available for demo symbols |
| 1.3 | Optional: `GET /api/v1/dataset/status` (browser network or API client while logged in) | JSON with `dataset_version`, price stats | `securities_active` > 0; `latest_price_date` recent |

**Pass:** Analysis symbols have usable OHLCV.

---

## 2. Running discovery

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 2.1 | Open **Candidates** (`/candidates`) | Page loads | Empty or prior list OK |
| 2.2 | Click **Run discovery** | Request succeeds | Toast/alert or refreshed list; no 500 |
| 2.3 | Inspect table | Rows with symbol, source, reason | ≥1 candidate OR explicit empty with successful run |
| 2.4 | Open **Evidence** on a row | Modal with JSON evidence | Patterns/signals/source payload present |
| 2.5 | Optional filter by source / search | List filters | Filter matches |

**Pass:** Discovery run persisted; candidates UI reflects it.

---

## 3. Evaluation

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 3.1 | Open **Evaluations** (`/evaluations`) | Page loads | — |
| 3.2 | Click **Run evaluation** | Evaluation completes | Ranked rows appear (or empty if no candidates) |
| 3.3 | Inspect score, confidence, explanation | Numeric score + text | Rank ordering sensible (higher score first) |
| 3.4 | Open **Details** | Indicators, component scores, passed/failed rules | Evidence explainable without DB |

**Pass:** Ranked, explainable evaluation results in UI.

---

## 4. Recommendation generation

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 4.1 | Open **Recommendations** (`/recommendations`) | Page loads | — |
| 4.2 | Click **Run decision pipeline** | Pipeline stages complete | Alert/summary shows discovery/eval/rec counts |
| 4.3 | Inspect list | Recommendations with type + status | Status = `pending_review` for new items |
| 4.4 | Open **Review** on a row | Detail shows score, confidence, reference price, expiry, evidence | Fields populated |

**Pass:** Recommendations generated from evaluation with evidence.

---

## 5. User review

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 5.1 | On a pending recommendation, add optional notes → **Accept** | Status → `accepted` | History shows user, timestamp, accepted |
| 5.2 | On another row → **Defer** | Status → `deferred` | Remains reviewable later |
| 5.3 | On another row → **Reject** | Status → `rejected` | Cannot create order for it |
| 5.4 | Attempt order on non-accepted recommendation (if UI allows) | Blocked | API/UI error; no order |

**Pass:** Accept/Reject/Defer persist with audit history.

---

## 6. Order creation & execution

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 6.1 | On **accepted** recommendation → **Pending** (qty/price) | Order created `pending` | No holding change yet |
| 6.2 | Open **Review** (`/review`) → Orders table | Pending order visible | Matches symbol/qty |
| 6.3 | **Execute** with fill price | Order → `executed`; recommendation → `executed` | New transaction; holding qty updated |
| 6.4 | Create another pending order → **Cancel** | Order → `cancelled` | No ledger fill |
| 6.5 | Optional: **Execute** immediately from Recommendations | Creates and fills in one step | Holdings updated |

**Pass:** Pending → Executed and Pending → Cancelled without broker automation.

---

## 7. Notification

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 7.1 | With Telegram configured, run pipeline with notify on | Telegram message received | Message references recommendation(s) |
| 7.2 | Open **Notify log** (`/notification-history`) | Rows with channel/status/attempts | `telegram` channel; delivered or failed recorded |
| 7.3 | If failed, click **Retry** | Status updates | Attempt count increments; success or new error |
| 7.4 | If Telegram disabled | Empty state or skipped notifications | Pipeline still completes; no crash |

**Pass:** History visible; delivery or intentional skip documented.

---

## 8. Review dashboard

| Step | User action | Expected result | Validation criteria |
|------|-------------|-----------------|---------------------|
| 8.1 | Open **Review** (`/review`) | Dashboard cards load | Portfolio value / XIRR / accept-reject / status counts |
| 8.2 | Inspect **Recommendation outcomes** | Ref price, current, gain/loss | Matches accepted/executed symbols |
| 8.3 | Inspect recent review decisions | Lists Accept/Reject/Defer | Matches actions in step 5 |
| 8.4 | Optional: `POST /api/v1/reviews/generate` | Report stored | Metrics include win_rate / expectancy style fields |

**Pass:** Outcomes and counts reflect the session’s decisions/executions.

---

## 9. Regression / API smoke (optional)

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

All must return `success: true` envelopes when preconditions are met.

---

## Sign-off sheet

| Area | Pass? | Tester | Notes |
|------|-------|--------|-------|
| Setup | | | |
| Market data | | | |
| Discovery | | | |
| Evaluation | | | |
| Recommendations | | | |
| User review | | | |
| Orders / execution | | | |
| Notifications | | | |
| Review dashboard | | | |
| Automated PHPUnit | | | |

When all applicable rows pass, the **MVP acceptance test is satisfied**.
