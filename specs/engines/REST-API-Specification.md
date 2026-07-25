# REST API Specification

  Field          Value
  -------------- ------------------------
  **Document**   REST API Specification
  **Version**    1.0 Draft
  **Status**     Draft

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

  Method   Endpoint                           Description
  -------- ---------------------------------- --------------------------
  POST     /api/v1/recommendations/generate   Generate recommendations
  GET      /api/v1/recommendations            Open / filtered list
  GET      /api/v1/recommendations/{id}       Recommendation details
  POST     /api/v1/recommendations/{id}/review  Accept / Reject / Defer (actionable only)
  POST     /api/v1/recommendations/{id}/reopen  Undo Accept / Reject / Defer → pending_review

Detail payload SHALL expose:

- `market_opinion` — direction, strength, confidence, evidence
- `portfolio_action` / `recommendation_type` — portfolio decision enum
- `ui_label` — friendly label (Buy, Buy More, …)
- `execution_plan` — sizing / sell plan when actionable (else null)
- `current_allocation_pct`, `target_allocation_pct`, `suggested_allocation_pct`
- `reasoning`, `category` (`actionable` | `informational`)
- `order_side` — `buy` | `sell` | null
- `can_reopen` — Undo Accept/Reject/Defer via `POST /api/v1/recommendations/{id}/reopen`

## Notifications

  Method   Endpoint                           Description
  -------- ---------------------------------- ----------------------
  GET      /api/v1/notifications              Notification history
  POST     /api/v1/notifications/{id}/retry   Retry delivery

## Execution

  Method   Endpoint               Description
  -------- ---------------------- -------------------
  POST     /api/v1/orders         Create order
  GET      /api/v1/orders         List orders
  GET      /api/v1/transactions   List transactions
  GET      /api/v1/positions      Current positions

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
