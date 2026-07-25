# Application Architecture Specification

  Field          Value
  -------------- ----------------------------------------
  **Document**   Application Architecture Specification
  **Version**    1.0 Draft
  **Status**     Draft

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
    Execution Plan)
-   Notification processing
-   Review generation
-   Cleanup tasks

Every job SHALL be independently executable.

------------------------------------------------------------------------

# 10. Configuration

Configuration SHALL be centralized.

Examples:

-   Database
-   JWT
-   Logging
-   Scheduler
-   External providers
-   Feature flags

------------------------------------------------------------------------

# 11. Testing Strategy

-   Unit tests
-   Repository tests
-   API integration tests
-   End-to-end UI tests
-   Engine-level business tests

Business rules SHALL be covered by automated tests.

------------------------------------------------------------------------

# 12. Deployment

Target environment:

-   GoDaddy Linux Hosting (initial)
-   Apache
-   PHP-FPM
-   MariaDB
-   Cron

Architecture SHALL remain portable to VPS or cloud platforms.

------------------------------------------------------------------------

# 13. Security

-   JWT authentication
-   Password hashing
-   CSRF protection where applicable
-   Input validation
-   Output encoding
-   Rate limiting
-   Audit logging

------------------------------------------------------------------------

# 14. Cursor Implementation Notes

-   Implement one engine at a time.
-   Keep engine boundaries intact.
-   Avoid cross-engine business logic.
-   Use feature branches for each engine.
-   Write tests before marking an engine complete.
-   Do not implement future-scope features unless explicitly requested.
