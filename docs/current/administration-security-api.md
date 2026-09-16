# Administration, Security, And API

## Current Behaviour

StoX uses Laravel Sanctum session authentication for the SPA and personal access tokens for API automation with scoped permissions. Auth includes login, logout, CSRF token probe, guest-safe auth-me probe, session listing, logout-other-sessions, targeted session revocation, invite acceptance, password reset links, profile/password/photo updates, and admin user/session management.

Admin features are role-gated. They include stock administration, sync controls, operational alerts, universe price sync, data quality, user and invite management, password reset link management, audit explorer, tax rule versions, fundamentals, ML scoring, and automated execution entitlement.

The `/api/v1` Trading OS API is additive. It uses Sanctum session auth and active portfolio middleware, not JWT. Legacy `/api/*` routes remain in place for existing SPA features. Token scopes gate sensitive read/write/execute surfaces.

Request correlation, frontend logging, sync logs, system logs, operational alerts, and audit explorer are the main operational debugging surfaces.

## Technical Contract

Auth/security routes:

- `/api/auth/login`, `/api/auth/me`, `/api/auth/csrf-token`, `/api/auth/logout`
- `/api/auth/sessions`, `/api/auth/sessions/logout-others`, `/api/auth/sessions/{sessionId}`
- `/api/invites/{token}`, `/api/invites/accept`
- `/api/reset-password/{token}`, `/api/reset-password/accept`
- `/api/profile`, `/api/profile/password`, `/api/profile/photo`
- `/api/personal-api-tokens`
- `/api/v1/totp/*`

Admin routes:

- `/api/users`, user admin/session routes
- `/api/invites`, admin invite management
- `/api/password-reset-links`
- `/api/admin/stocks`, stock activate/deactivate/create/update
- `/api/admin/audit`, `/api/admin/audit/export`
- `/api/admin/tax-rule-versions`
- `/api/operational-alerts/*`
- `/api/universe-price-sync/*`
- `/api/data-quality/*`
- `/api/v1/admin/fundamentals/*`
- `/api/v1/admin/ml/*`
- `/api/v1/admin/users/{user}/automated-execution-entitlement`

Primary models include `User`, `UserInvite`, `PasswordResetLink`, `PersonalAccessToken`, `ProfileSetting`, `Setting`, `SystemLog`, `SyncLog`, `OperationalAlert`, `TaxRuleVersion`, `BrokerConnection`, and execution security models.

Primary services include `AuthAuditService`, `SessionManagementService`, `UserInviteService`, `PasswordResetLinkService`, `ProfileSettingsService`, `ProfilePhotoService`, `SettingsService`, `SystemLogService`, `SyncLogService`, `AdminInvestmentOwnershipAuditService`, `AdminOperationalAlertService`, `TotpService`, and `AutomatedExecutionEntitlementService`.

## Security Rules

- Admin-only routes require admin middleware.
- Active portfolio middleware applies to authenticated portfolio-scoped workflows.
- Personal API tokens must use explicit scopes for portfolio, notes, and execution-sensitive APIs.
- Invite and reset tokens must remain hashed/unguessable and rate-limited.
- Broker callback state must be short-lived and encrypted.
- Public wiki tokens authorize only the shared page payload.
- Frontend logs are diagnostic and must not become trusted business input.

## Debugging Sources

- 401/419: check Sanctum cookie/CSRF/session state and login throttling.
- 403: check admin middleware, active portfolio access, token scope, execution entitlement, or TOTP state.
- Admin data leakage concern: check controller query scopes and active profile/user ownership.
- API contract drift: regenerate OpenAPI and compare `app/openapi/v1.json`.

## Related Docs

- [Product Overview](./product-overview.md)
- [Frontend And Navigation](./frontend-and-navigation.md)
- [Execution, Broker, And Safety](./execution-broker-safety.md)
- [Knowledge And Documentation](./knowledge-and-documentation.md)

## Historical Context

Early architecture documents discussed JWT. Current implementation and accepted behaviour use Sanctum session auth for the SPA/API surface. Treat JWT language in archived specs as historical intent unless a future current doc reintroduces it.

