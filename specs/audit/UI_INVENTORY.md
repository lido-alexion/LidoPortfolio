# UI Inventory — Trading Operating System

**Audit date:** 2026-07-25  
**Router:** `app/resources/js/src/App.jsx`  
**Nav:** `app/resources/js/src/config/mainNav.js`

---

## Trading OS pages

| Route | Page file | Purpose | Main UI elements | APIs used | Engine |
|-------|-----------|---------|------------------|-----------|--------|
| `/candidates` | `pages/CandidatesPage.jsx` | View/run discovery candidates | Filters (source/search), Run discovery, Evidence modal | `GET /v1/candidates`, `POST /v1/discovery/runs` | Discovery |
| `/evaluations` | `pages/EvaluationsPage.jsx` | Ranked evaluation results | Run evaluation, Details modal (indicators/rules) | `GET /v1/evaluations`, `POST /v1/evaluation/runs` | Evaluation |
| `/recommendations` | `pages/RecommendationsPage.jsx` | Review recommendations; create orders | Pipeline run, Accept/Reject/Defer, Pending/Execute order | `GET/POST recommendations*`, `POST /v1/pipeline/run`, `POST /v1/orders` | Recommendation, Pipeline, Execution, Notification (via pipeline) |
| `/review` | `pages/ReviewDashboardPage.jsx` | Performance + orders + outcomes | Snapshot cards, outcomes table, orders Execute/Cancel | `GET /v1/review/dashboard`, `GET /v1/orders`, `POST orders/{id}/execute\|cancel` | Review, Execution |
| `/notification-history` | `pages/NotificationHistoryPage.jsx` | Notification delivery log | List, Retry | `GET /v1/notifications`, `POST /v1/notifications/{id}/retry` | Notification |

All five are authenticated routes and appear in main nav (Discovery, Evaluations, Recommendations, Review, Notifications).

**Shared client:** `api.js` (axios, Sanctum cookies, `X-Profile-Id`). No dedicated TOS components under `components/` — logic is page-local.

---

## Supporting UI (legacy, used by MVP demo)

| Route / area | Purpose for TOS MVP |
|--------------|---------------------|
| Dashboard / Settings sync | Market data sync (Demo step 1) |
| Holdings / Watchlists | Universe for discovery membership |
| Screeners | Produce hits consumed by Discovery |
| Transactions / Holdings | Verify execution side-effects |

---

## Specification pages still missing

| Expected / desirable UI | Status |
|-------------------------|--------|
| Dedicated Data Engine / Securities / Imports console | Missing (legacy sync covers MVP) |
| Discovery run history browser | Missing |
| Evaluation run history browser | Missing |
| Dedicated Orders page | Missing (embedded in Review + Recommendations) |
| Review **Reports** list/detail UI | Missing (API exists; dashboard covers MVP) |
| Strategy management UI | Intentionally Deferred (A12) |
| Broker connection UI | Intentionally Deferred (A10) |
| Multi-channel notification preferences | Intentionally Deferred (A9) |
| Trading calendar UI | Missing |

**MVP_DEMO_CHECKLIST pages:** all present.

---

## Frontend architecture notes

- 33 pages total under `pages/`; 5 are TOS-specific.  
- 62 components under `components/` (none TOS-exclusive).  
- No `features/`, `store/`, or TypeScript types tree as specified in Application Architecture.
