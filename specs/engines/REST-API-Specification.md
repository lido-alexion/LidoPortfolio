# REST API Specification

  Field          Value
  -------------- ------------------------
  **Document**   REST API Specification
  **Version**    1.1
  **Status**     Active (V1.0 / SD-025 / SD-026 aligned)

------------------------------------------------------------------------

# 1. Purpose

Define the REST API contract for the Trading Operating System. APIs
expose business capabilities, not database tables.

------------------------------------------------------------------------

# 2. Design Principles

-   RESTful resource-oriented APIs.
-   JSON request and response bodies.
-   Version all APIs (`/api/v1`).
-   Stateless requests.
-   UTC timestamps (ISO-8601).
-   Idempotent operations where applicable.

------------------------------------------------------------------------

# 3. Authentication

-   JWT Bearer Token
-   HTTPS only
-   Role-based authorization
-   Every request SHALL be authenticated except login and health
    endpoints.

------------------------------------------------------------------------

# 4. Standard Response

## Success

``` json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

## Error

``` json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Description"
  }
}
```

------------------------------------------------------------------------

# 5. API Modules

## Securities

  Method   Endpoint                  Description
  -------- ------------------------- ------------------
  GET      /api/v1/securities        List securities
  GET      /api/v1/securities/{id}   Security details

## Market Data

  Method   Endpoint               Description
  -------- ---------------------- ----------------
  GET      /api/v1/price-bars     Query OHLCV
  POST     /api/v1/imports        Trigger import
  GET      /api/v1/imports/{id}   Import status

## Discovery

  Method   Endpoint                 Description
  -------- ------------------------ -----------------
  POST     /api/v1/discovery/runs   Start screening
  GET      /api/v1/candidates       List candidates

## Evaluation

  Method   Endpoint                  Description
  -------- ------------------------- -------------------------
  POST     /api/v1/evaluation/runs   Start evaluation
  GET      /api/v1/evaluations       List evaluation results

## Recommendations

  Method   Endpoint                                    Description
  -------- ------------------------------------------- ------------------------------------------
  POST     /api/v1/recommendations/generate            Generate recommendations
  GET      /api/v1/recommendations                     Open / filtered list
  GET      /api/v1/recommendations/{id}                Recommendation details
  POST     /api/v1/recommendations/{id}/review         Approve / Reject / Defer (actionable only)
  GET      /api/v1/recommendations/pending-execution   Approved queue awaiting trade
  POST     /api/v1/recommendations/{id}/cancel-execution  Cancel pending execution → cancelled
  POST     /api/v1/recommendations/{id}/expire         Expire recommendation
  POST     /api/v1/recommendations/{id}/reopen         Undo Approve / Reject / Defer → pending_review

### Review body (approval — not execution)

``` json
{ "decision": "approved" }
```

Allowed `decision` values: `approved` | `accepted` (BC alias → approved) |
`rejected` | `deferred`.

Approve sets status `pending_execution`. It does **not** create a
ledger transaction (SD-025).

Detail payload SHALL expose:

- `market_opinion` — direction, strength, confidence, evidence
- `portfolio_action` / `recommendation_type` — portfolio decision enum
- `ui_label` — friendly label (Buy, Buy More, …)
- `execution_plan` — sizing / sell plan when actionable (else null)
- `current_allocation_pct`, `target_allocation_pct`, `suggested_allocation_pct`
- `suggested_allocation_amount` — capital allocated at generation (SD-026)
- `reserved_amount`, `reservation_status`, `reserved_at`, `executed_amount`
- `cash_balance_at_generation`, `reserved_cash_at_generation`,
  `available_cash_at_generation`
- `reasoning`, `category` (`actionable` | `informational`)
- `order_side` — `buy` | `sell` | null
- `execution_status` — e.g. `pending` while awaiting trade
- `can_reopen`, `can_cancel_execution`

Approve on a **buy** reserves cash (SD-026); fails if amount exceeds
available investable cash. Cancel-execution / expire / reopen **release**
reservation. Execute **converts** reservation and posts cash buy/sell.

## Cash (SD-026)

Legacy `/api` surface (active portfolio; Sanctum):

  Method   Endpoint              Description
  -------- --------------------- --------------------------------
  GET      /api/cash             Balance, reserved, available (`?include_reservations=1` optional)
  GET      /api/cash/reservations Active reservation breakdown
  GET      /api/cash/ledger      Recent ledger entries
  POST     /api/cash/deposit     `{ amount, remarks?, transaction_date? }`
  POST     /api/cash/withdraw    `{ amount, remarks?, transaction_date? }` (≤ available)
  POST     /api/cash/adjust      `{ amount, remarks?, transaction_date? }` (reason/remarks optional)

Summary fields: `cash_balance`, `reserved_cash`,
`available_investable_cash`.

## Notifications

  Method   Endpoint                           Description
  -------- ---------------------------------- ----------------------
  GET      /api/v1/notifications              Notification history
  POST     /api/v1/notifications/{id}/retry   Retry delivery

## Execution (ledger + legacy orders)

Primary V1.0 path — **Transactions module** (not under `/api/v1` only):

  Method   Endpoint              Description
  -------- --------------------- -----------------------------------------------
  POST     /api/transactions     Create ledger row; optional `recommendation_id`

When `recommendation_id` is set, the recommendation MUST be
`pending_execution`; on success it becomes `executed` and the row stores
`source` + `recommendation_id`.

Legacy / BC order APIs (remain; no dedicated Orders page):

  Method   Endpoint                    Description
  -------- --------------------------- ---------------------------------
  POST     /api/v1/orders              Create order (`execute_now` **defaults false**)
  GET      /api/v1/orders              List orders
  POST     /api/v1/orders/{id}/execute Execute / fill order
  POST     /api/v1/orders/{id}/cancel  Cancel order
  GET      /api/v1/transactions        List transactions (TOS view)
  GET      /api/v1/positions           Current positions

Approval APIs are under Recommendations; transaction creation is under
Transactions. Do not treat order create as approval.
## Review

  Method   Endpoint                   Description
  -------- -------------------------- -----------------
  POST     /api/v1/reviews/generate   Generate report
  GET      /api/v1/reviews            List reports
  GET      /api/v1/reviews/{id}       Report details

------------------------------------------------------------------------

# 6. Query Parameters

Support:

-   page
-   pageSize
-   sort
-   order
-   filter
-   search

------------------------------------------------------------------------

# 7. HTTP Status Codes

-   200 OK
-   201 Created
-   202 Accepted
-   204 No Content
-   400 Bad Request
-   401 Unauthorized
-   403 Forbidden
-   404 Not Found
-   409 Conflict
-   422 Validation Error
-   500 Internal Server Error

------------------------------------------------------------------------

# 8. Idempotency

The following operations SHOULD support idempotency keys:

-   Import trigger
-   Recommendation generation
-   Notification retry

------------------------------------------------------------------------

# 9. Versioning

Base path:

    /api/v1

Breaking changes SHALL require a new version.

------------------------------------------------------------------------

# 10. Cursor Implementation Notes

-   Implement controllers by engine ownership.
-   Keep request validation separate from business logic.
-   Return consistent response envelopes.
-   Generate OpenAPI documentation alongside implementation.
-   Do not expose database schema directly through APIs.
