# Application Architecture Specification

  Field          Value
  -------------- ----------------------------------------
  **Document**   Application Architecture Specification
  **Version**    1.1
  **Status**     Active (V1.0 / SD-025 / SD-026 aligned)

------------------------------------------------------------------------

# 1. Purpose

Define the technical architecture of the Trading Operating System. This
document describes how the system is organized in code, how components
interact, and the standards to be followed during implementation.

------------------------------------------------------------------------

# 2. Technology Stack

## Frontend

-   React
-   TypeScript
-   Bootstrap
-   React Router
-   TanStack Query
-   AG Grid (or equivalent)
-   Chart.js / Lightweight Charts

## Backend

-   PHP 8.x
-   Composer
-   MVC architecture
-   JWT Authentication
-   MariaDB / MySQL
-   Cron jobs for scheduled tasks

------------------------------------------------------------------------

# 3. High-Level Architecture

``` text
React UI
    │
REST API
    │
Application Services
    │
Business Engines
    │
Repositories
    │
MariaDB
```

Business engines remain independent and communicate only through service
interfaces.

------------------------------------------------------------------------

# 4. Backend Structure

``` text
backend/
├── app/
│   ├── Controllers/
│   ├── Services/
│   ├── Engines/
│   ├── Repositories/
│   ├── Models/
│   ├── Middleware/
│   ├── Jobs/
│   ├── Events/
│   ├── DTO/
│   └── Utils/
├── config/
├── database/
├── public/
└── tests/
```

------------------------------------------------------------------------

# 5. Frontend Structure

``` text
frontend/
├── src/
│   ├── api/
│   ├── components/
│   ├── pages/
│   ├── features/
│   ├── hooks/
│   ├── store/
│   ├── layouts/
│   ├── utils/
│   └── types/
└── public/
```

------------------------------------------------------------------------

# 6. Coding Principles

-   Engine-first organization.
-   Single Responsibility Principle.
-   Dependency Injection.
-   Interface-driven design.
-   Immutable DTOs.
-   Business logic isolated from controllers.

------------------------------------------------------------------------

# 7. Logging

Every layer SHALL emit structured logs.

Minimum fields:

-   Timestamp
-   Request ID
-   Engine
-   Component
-   Severity
-   Message

------------------------------------------------------------------------

# 8. Error Handling

-   Standard error response model.
-   Global exception handler.
-   Validation errors separated from system errors.
-   No stack traces returned to clients.

------------------------------------------------------------------------

# 9. Background Jobs

Scheduled jobs include:

-   Market data import
-   Discovery run
-   Evaluation run
-   Recommendation generation (Market Opinion → Portfolio Decision →
    Ranking → Capital Allocation → Trade gen)
-   Notification processing
-   Review generation
-   Cleanup tasks

Every job SHALL be independently executable.

------------------------------------------------------------------------

# 10. Engine vs module responsibilities (SD-025 / SD-026)

## Recommendation Engine

- Market Opinion → Portfolio Decision → Ranking → Capital Allocation →
  Trade Recommendation Generation (SD-026)
- Pluggable `CapitalAllocationStrategy` (default
  `ScorePriorityCapitalAllocator`)
- User review: Approve / Reject / Defer
- Cash reservation on buy Approve; release on cancel/expire/reopen;
  convert on execute
- Status lifecycle including `pending_execution`, expire, cancel-execution
  (cancel of pending trade), reopen
- Does **not** own cash balance posts (delegates to CashManagementService)

## CashManagementService

- Ledger-backed cash balance per profile
- Derived reserved cash from pending-execution buy reservations
- Available investable cash for capital allocation
- Deposit / withdraw / adjust APIs; buy/sell posts from trade fills

## Execution Engine

- Pending-execution handoff and completion tracking
- Completing a recommendation when a linked transaction is created
- Triggers reservation convert + cash buy/sell ledger via cash service
- Undo fill → return to `pending_execution` (+ cash reverse / re-reserve)
- Legacy order APIs (BC); future broker adapters
- Does **not** perform Approve / Reject / Defer

## Transactions Module (existing SPA + `/api/transactions`)

- Primary UI/API for recording buys/sells
- Manual execute of approved recommendations via `recommendation_id`
- Shared create path: `TransactionWriteService` (SD-021)
- No separate Orders page required for V1.0

------------------------------------------------------------------------

# 11. Configuration

Configuration SHALL be centralized.

Examples:

-   Database
-   JWT
-   Logging
-   Scheduler
-   External providers
-   Feature flags

------------------------------------------------------------------------

# 12. Testing Strategy

-   Unit tests
-   Repository tests
-   API integration tests
-   End-to-end UI tests
-   Engine-level business tests

Business rules SHALL be covered by automated tests.

------------------------------------------------------------------------

# 13. Deployment

Target environment:

-   GoDaddy Linux Hosting (initial)
-   Apache
-   PHP-FPM
-   MariaDB
-   Cron

Architecture SHALL remain portable to VPS or cloud platforms.

------------------------------------------------------------------------

# 14. Security

-   JWT authentication
-   Password hashing
-   CSRF protection where applicable
-   Input validation
-   Output encoding
-   Rate limiting
-   Audit logging

------------------------------------------------------------------------

# 15. Cursor Implementation Notes

-   Implement one engine at a time.
-   Keep engine boundaries intact.
-   Avoid cross-engine business logic.
-   Use feature branches for each engine.
-   Write tests before marking an engine complete.
-   Do not implement future-scope features unless explicitly requested.
