# API Inventory — Trading Operating System

**Audit date:** 2026-07-25  
**Base path:** `/api/v1`  
**Auth:** Laravel Sanctum session cookies (`auth:sanctum`) + `active.portfolio` middleware  
**Controller:** `App\Http\Controllers\Api\V1\TradingOsController`  
**Envelope:** `App\Engines\Support\ApiEnvelope` (`success`/`data`/`meta` or `error`)

Status: **Implemented** | **Partial** | **Missing** | **Extra (beyond REST draft)**

---

## Trading OS endpoints

| Method | URL | Purpose | Auth | Request | Response (data) | Engine | Status |
|--------|-----|---------|------|---------|-----------------|--------|--------|
| GET | `/api/v1/securities` | List securities | Sanctum + portfolio | `search`, page size | Paginator of stocks | Data | Implemented |
| GET | `/api/v1/securities/{id}` | Security details | Sanctum + portfolio | path id | Stock | Data | Implemented |
| GET | `/api/v1/price-bars` | Query OHLCV | Sanctum + portfolio | `security_id`, `from`, `to` | Paginator of prices | Data | Implemented |
| GET | `/api/v1/dataset/status` | Dataset / sync status | Sanctum + portfolio | — | version, counts, sync | Data | Implemented (extra vs draft) |
| POST | `/api/v1/imports` | Trigger market import | Sanctum + portfolio | `force?` | accepted + status | Data | Implemented |
| GET | `/api/v1/imports/{id}` | Import / sync run status | Sanctum + portfolio | path id | SyncRun-ish | Data | Implemented |
| POST | `/api/v1/discovery/runs` | Run discovery | Sanctum + portfolio | — | run + candidates summary | Discovery | Implemented |
| GET | `/api/v1/candidates` | List candidates | Sanctum + portfolio | `source`, `search` | candidate array | Discovery | Implemented |
| POST | `/api/v1/evaluation/runs` | Run evaluation | Sanctum + portfolio | — | run + results summary | Evaluation | Implemented |
| GET | `/api/v1/evaluations` | List evaluation results | Sanctum + portfolio | — | ranked results | Evaluation | Implemented |
| POST | `/api/v1/recommendations/generate` | Generate recommendations | Sanctum + portfolio | — | recommendations (+ may notify) | Recommendation (+ Notification) | Implemented |
| GET | `/api/v1/recommendations` | List recommendations | Sanctum + portfolio | `open=1` / `all=1` | list | Recommendation | Implemented |
| GET | `/api/v1/recommendations/{id}` | Recommendation detail + reviews | Sanctum + portfolio | path id | detail | Recommendation | Implemented |
| POST | `/api/v1/recommendations/{id}/review` | Accept / Reject / Defer | Sanctum + portfolio | `decision`, `notes?` | updated recommendation | Recommendation | **Extra** |
| GET | `/api/v1/recommendations/{id}/reviews` | Review history | Sanctum + portfolio | path id | review rows | Recommendation | **Extra** |
| GET | `/api/v1/notifications` | Notification history | Sanctum + portfolio | — | notifications | Notification | Implemented |
| POST | `/api/v1/notifications/{id}/retry` | Retry delivery | Sanctum + portfolio | — | notification | Notification | Implemented |
| POST | `/api/v1/orders` | Create order (pending or execute_now) | Sanctum + portfolio | side, qty, price?, recommendation_id?, execute_now? | order | Execution | Implemented |
| GET | `/api/v1/orders` | List orders | Sanctum + portfolio | — | orders | Execution | Implemented |
| POST | `/api/v1/orders/{id}/execute` | Fill pending order | Sanctum + portfolio | `price`, qty? | order | Execution | **Extra** |
| POST | `/api/v1/orders/{id}/cancel` | Cancel pending order | Sanctum + portfolio | — | order | Execution | **Extra** |
| GET | `/api/v1/transactions` | List ledger txs (profile) | Sanctum + portfolio | — | transactions | Execution | Implemented |
| GET | `/api/v1/positions` | Current holdings as positions | Sanctum + portfolio | — | positions | Execution | Implemented |
| POST | `/api/v1/reviews/generate` | Generate review report | Sanctum + portfolio | period? | report + metrics | Review | Implemented |
| GET | `/api/v1/reviews` | List reports | Sanctum + portfolio | — | reports | Review | Implemented |
| GET | `/api/v1/reviews/{id}` | Report details | Sanctum + portfolio | path id | report + metrics | Review | Implemented |
| GET | `/api/v1/review/dashboard` | Dashboard snapshot | Sanctum + portfolio | — | cards, outcomes, decisions | Review | **Extra** |
| GET | `/api/v1/review/outcomes` | Recommendation outcomes | Sanctum + portfolio | — | outcomes | Review | **Extra** |
| POST | `/api/v1/pipeline/run` | Full decision pipeline | Sanctum + portfolio | `notify`, `review` query/body | pipeline_run + stages | Pipeline | **Extra** |

| GET | `/api/v1/capital` | Portfolio capital snapshot | Sanctum + portfolio | — | strategies / OD-19 / OD-20 | Accounting | Implemented |
| PUT | `/api/v1/capital/allocations` | Update strategy allocations | Sanctum + portfolio | allocations[] | snapshot | Accounting | Implemented |
| PUT | `/api/v1/capital/reserve-pct` | Portfolio cash reserve % | Sanctum + portfolio | portfolio_cash_reserve_pct | snapshot | Accounting | Implemented |
| GET/POST | `/api/v1/capital/requests/{id}/…` | Lender list / approve / reject | Sanctum + portfolio | lender_strategy_id? | request + loan | Lending | Implemented |
| GET/PUT | `/api/v1/capital/recall-period` | Effective recall period (OD-07) | Sanctum + portfolio | portfolio_recall_period_days? | period + cooldown | Recall | Implemented (Phase 3A) |
| GET/POST | `/api/v1/capital/recalls` | List / request recall | Sanctum + portfolio | loan_id, kind, amount? | recall | Recall | Implemented (Phase 3A) |
| GET | `/api/v1/capital/recalls/{id}` | Recall detail + bridge/proceeds | Sanctum + portfolio | — | recall detailed | Recall | Implemented (Phase 3A) |
| GET | `/api/v1/capital/bridge-loans` | List Recall Bridge Loans | Sanctum + portfolio | filters | bridge rows | Recall | Implemented (Phase 3A) |
| GET | `/api/v1/capital/bridge-loans/{id}` | Bridge detail | Sanctum + portfolio | — | bridge detailed | Recall | Implemented (Phase 3A) |
| POST | `/api/v1/capital/bridge-loans` | Manual create | Sanctum + portfolio | — | 405 Forbidden | Recall | Rejected by design |
| GET | `/api/v1/capital/pending-sale-proceeds` | Proceeds from Stock Sale | Sanctum + portfolio | filters | proceeds rows | Recall | Implemented (Phase 3A) |
| POST | `…/mark-available` | Force availability | Sanctum + portfolio | — | 405 Forbidden | Recall | Rejected by design |
| POST | `/api/v1/capital/resolve` | Capital resolution plan | Sanctum + portfolio | strategy_id, required_amount | resolution | CapitalResolution | Implemented (Phase 3A) |
| GET | `/api/v1/recommendations/{id}/capital-resolution` | UI capital-resolution contract | Sanctum + portfolio | — | own/recall/bridge/actual | CapitalResolution | Implemented (Phase 3A) |

**Count:** 29 Trading OS `/api/v1` routes inventoried.

---

## Missing APIs (relative to specs)

| Spec expectation | Status |
|------------------|--------|
| JWT Bearer auth | Intentionally Deferred (Sanctum) |
| Trading calendar query | Missing |
| Dedicated evaluation history by run id | Partial (runs in DB; list is latest-oriented) |
| OpenAPI document | Missing |
| Idempotency-Key on import / generate | Partial (notifications only) |
| Role-based authorization matrix | Partial (authenticated + active portfolio) |

---

## Deprecated APIs

None formally deprecated on `/api/v1`.  
Engine method `listActive()` is deprecated internally; HTTP still exposes open/all listing.

---

## Legacy APIs still in use

The SPA continues to use pre-TOS `/api/*` endpoints for core portfolio operations, including:

- Auth (`/api/auth/*`, Sanctum CSRF)
- Holdings, transactions, dashboard, sync
- Watchlists, screeners, pattern scan
- Analytics, alerts, corporate actions, knowledge board, calendar

**Assessment:** Intentional dual surface (A6). TOS UI uses `/api/v1` only for the five MVP pages; market data demo step still relies on legacy sync UX.

---

## Auth notes

- Not Bearer JWT.  
- Cookie session + CSRF headers (`X-CSRF-TOKEN` / `X-XSRF-TOKEN`).  
- `X-Profile-Id` selects active portfolio for `active.portfolio` middleware.
