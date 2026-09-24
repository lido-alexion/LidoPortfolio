# Administration, Security, And API

## 1. Purpose And Scope

This document owns identity and authentication, authorization, sessions, Admin boundaries, personal API access, TOTP, automated-execution entitlement, security audit evidence, and API governance. It defines who may act and under what proof; it does not define portfolio accounting or the broker/execution lifecycle itself.

Portfolio ownership is detailed in [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md). Broker submission and execution safety are detailed in [Execution, Broker, And Safety](./execution-broker-safety.md).

**Current implementation anchors:** `app/routes/api.php`, `app/app/Http/Middleware/ResolveActivePortfolio.php`, `EnsureUserIsAdmin`, `EnsurePersonalApiTokenScope`, and the auth/security services under `app/app/Services`.

## 2. Authentication Model

StoX uses Laravel Sanctum session/cookie authentication for the browser SPA, including CSRF protection and an authenticated identity probe. It does **not** use a JWT-in-localStorage architecture. Personal Sanctum access tokens support API automation and are distinct from browser sessions.

Browser authentication proves a current interactive session; token authentication proves a token's user and abilities. Neither mechanism alone bypasses Admin role, active-portfolio ownership, TOTP, execution entitlement, or execution safety checks.

**Current implementation anchors:** `config/sanctum.php`, `AuthController`, `resources/js/src/auth/csrf.js`, `resources/js/src/context/AuthContext.jsx`, and `PersonalApiTokenController`.

## 3. Login And Logout Lifecycle

The browser obtains CSRF state, submits credentials to `/api/auth/login`, is subject to login throttling, receives a server-side session, and confirms identity through `/api/auth/me`. Login records the role and security capabilities relevant to the session, including TOTP status.

Logout invalidates the current authenticated session and clears relevant browser session/auth state. The frontend must not retain an authority claim after logout; cached UI data is not an authorization source.

**Current implementation anchors:** `AuthController`, `AuthAuditService`, `SessionManagementService`, `AuthSessionTest`, `AuthCsrfLoginTest`, and `auth-redirect.test.mjs`.

## 4. Session Management

Users can list their own sessions, identify the current session, revoke a non-current named session, and log out other sessions. A user may not revoke a foreign session or use a targeted-revoke route to remove the current session accidentally. Administrative user/session management is role-gated and must preserve audit evidence.

Session state is user-owned. Revocation/log-out invalidates future use of that session; reset and credential-change consequences are governed by the corresponding security flow.

**Current implementation anchors:** `SessionManagementService`, `AuthController`, `/api/auth/sessions*`, and `AuthSessionTest`.

## 5. User Roles

Current primary roles are **Investor** and **Admin**, represented by `User.is_admin`.

- **Investor:** owns portfolio profiles and the investment/trading resources under them.
- **Admin:** administers global/system resources and users, but is intentionally not an Investor portfolio owner.

Role changes are Admin operations, are not self-service, and must not create, transfer, or silently grant ownership of Investor financial resources. There is no documented service/system user role that changes these user-facing ownership rules.

## 6. Admin Vs Investor Separation

V5 FEAT-042 establishes two separate applications/boundaries: Admin accounts are portfolio-less and are rejected from Investor trading APIs. Admin authority to administer the system does not make Admin the owner of Investor portfolio/trading resources.

| Resource / capability | Investor | Admin | Ownership rule |
| --- | --- | --- | --- |
| Portfolio profiles, holdings, transactions, cash | Own profile-scoped resources | No Investor access | Belong to the Investor user/profile only. |
| Recommendations and strategies | Use own active profile | No Investor API access | Strategy/recommendation ownership remains profile-scoped. |
| Broker connection and execution mode | Use own user-bound connection | No Investor connection | Broker state belongs to initiating Investor user. |
| Automated execution entitlement | Receives/loses entitlement | Grants/revokes it | Entitlement is user-scoped, not Admin ownership. |
| Users, roles, invites, resets, sessions | Own-session actions only | Administer users/system | Admin route plus actor/target audit context. |
| Stock admin, sync, data quality, alerts | Read Investor-relevant effects only through product | Operate global resources | Global operational resources are not Investor portfolio assets. |
| Audit explorer, tax versions, fundamentals, ML | No Admin mutation authority | Admin | System/governance boundary. |
| Knowledge/wiki and personal API tokens | Own profile/user resources | No implicit access | Active profile/user scope applies; public share links are capability-limited. |

**Current implementation anchors:** `ResolveActivePortfolio`, `PortfolioProfileService`, `RoleSeparatedApplicationTest`, `DatabaseSeederRoleBoundaryTest`, and `AdminInvestmentOwnershipAuditService`.

## 7. Resource Ownership Rules

Portfolio profiles are user-owned. Investor portfolio resources are resolved through the active profile and must belong to the authenticated Investor. Strategies, recommendations, holdings, notes/wiki, and portfolio cash inherit that profile boundary. Broker connections and automated-execution entitlement are bound to the user, not merely a browser-selected profile.

Cross-user reads/mutations must fail. Admin cannot acquire an Investor's resources by role alone. Personal API tokens remain owned by their issuing user and must operate through the same ownership checks as session-authenticated requests.

## 8. Active Portfolio Middleware

`ResolveActivePortfolio` resolves `X-Profile-Id`, `X-Portfolio-Id`, or `portfolio_id` only when the requested profile belongs to the authenticated Investor. With no selection it uses or creates the Investor's default portfolio. A foreign/invalid profile returns `404`, avoiding disclosure of another user's profile.

For Admin users, Admin and explicitly shared routes receive no active portfolio; Investor routes return `403` and must not create a default portfolio. Active portfolio is therefore an authorization and business boundary, not merely a frontend selector.

**Current implementation anchor:** `app/app/Http/Middleware/ResolveActivePortfolio.php`.

## 9. Invites

The invite lifecycle is **created -> pending -> accepted / expired / revoked**. Admin creates and manages invites; a raw invitation token is delivered only through the creation/regeneration response and the stored representation is hashed. A pending invite is single-use, expires, and may be regenerated or revoked before acceptance.

Guest validation and acceptance use only the invite capability. Invalid, expired, or used tokens fail without authenticating the requester. Acceptance creates the invited user and records acceptance; an existing account or used token cannot be repurposed as an invite.

**Current implementation anchors:** `UserInvite`, `UserInviteService`, `InviteAcceptController`, `UserInviteController`, and `UserInviteTest`.

## 10. Password Reset

Admin-generated password-reset links use an unguessable token with a hashed stored representation, expiry, acceptance flow, regeneration/revocation, and duplicate-pending protection. Guest validation/acceptance authorizes only password reset for the intended user.

Successful reset changes credentials, invalidates other sessions, and rotates the remember token. Invalid or expired links must not revoke sessions or alter credentials.

**Current implementation anchors:** `PasswordResetLink`, `PasswordResetLinkService`, `PasswordResetLinkController`, `PasswordResetAcceptController`, and `PasswordResetLinkTest`.

## 11. Profile And Credential Changes

Authenticated users may update their profile, password, and profile photo through their own profile endpoints. Profile mutations remain user-scoped. Password reset has explicit cross-session invalidation behavior; any broader session/token invalidation behavior after normal credential/profile changes requires implementation-audit verification.

**Current implementation anchors:** profile controllers/services, `ProfileSettingsService`, `ProfilePhotoService`, and `/api/profile*`.

## 12. Personal API Tokens

Users create, list, and revoke only their own personal Sanctum access tokens. Creation accepts a display name, one or more validated abilities, and an optional future expiry. The plaintext token is returned at creation and must be treated as display-once secret material; the persisted Sanctum token is not the browser session cookie.

Token metadata exposes name, abilities, creation, expiry, and last-use time. Revocation deletes the issuing user's token; unknown or foreign token IDs return `404`.

**Current implementation anchors:** `PersonalApiTokenController`, Laravel `PersonalAccessToken`, `PersonalApiTokensPanel`, and `V6PersonalApiTokenTest`.

## 13. API Scope Model

| Scope | Permitted API category | Still required |
| --- | --- | --- |
| `portfolio:read` | Portfolio read routes | Authenticated issuing user and active-profile ownership. |
| `portfolio:write` | Portfolio create/update/default/clone routes | Issuer ownership and normal validation. |
| `notes:read` | Contextual note reads | Active-profile ownership. |
| `notes:write` | Contextual note mutations | Active-profile ownership and normal validation. |
| `execution:read` | Execution/broker state reads | Ownership and execution-domain checks. |
| `execution:submit` | Mode, halt/recovery, quote policy, submission, emergency controls | Ownership, entitlement, TOTP where required, and execution safety gates. |

`EnsurePersonalApiTokenScope` enforces declared abilities for token-authenticated requests; browser session requests do not need a personal-token ability. **Possessing a valid token never bypasses portfolio ownership, Admin role, execution entitlement, TOTP, or safety gates.**

**Current implementation anchors:** `PersonalApiTokenController::SCOPES`, `EnsurePersonalApiTokenScope`, and `routes/api.php` token-scope declarations.

## 14. TOTP Lifecycle

The StoX execution code is **not configured -> pending setup -> enabled -> recovered/disabled**. Enrollment provisions a pending authenticator secret; confirmation verifies a rotating code, saves only the safe authenticator-app display name, and activates the StoX execution code. Existing users without a saved name display “Authenticator app.” Activation creates hashed StoX recovery codes. Verification is rate-limited and replay-aware. A StoX recovery code is a separate single-use backup; disable clears active/pending authenticator state through one explicitly identified proof path. Kite login OTPs are entered only on Kite/Zerodha.

A StoX execution code — {Authenticator App Name} supplies sensitive-action proof for execution-sensitive operations. It does not itself grant execution authority: entitlement, ownership, mode, broker readiness, halt state, reconciliation, and all execution safety gates remain required.

**Current implementation anchors:** `TotpService`, `TradingOs\TotpController`, `User` TOTP fields, `ExecutionSafetyService`, and `TotpFlowTest`.

## 15. Automated Execution Entitlement

Automated-execution entitlement is Admin-managed and user-scoped through `automated_execution_entitled_at`. It is separate from portfolio execution mode and separate from Kite/broker connection. Admin may grant or revoke it, with actor/target audit evidence.

Revocation prevents future automatic authority even where a portfolio remains configured Automatic. Entitlement alone never authorizes a broker order; all execution readiness and safety gates still apply.

**Current implementation anchors:** `AutomatedExecutionEntitlementService`, `AdminExecutionEntitlementController`, `User.automated_execution_entitled_at`, and execution-safety services/tests.

## 16. Broker Callback Security

The Kite callback is intentionally guest-safe because the broker redirects outside the authenticated SPA-cookie context. The initiating user and allowlisted return destination are recovered from encrypted, short-lived login state, not from untrusted callback query parameters or a browser session assumption.

Invalid/expired state or a missing/error request token fails closed and redirects to a safe account/dashboard destination without contacting Kite for the invalid state. Successful state identifies the initiating user before broker session completion.

**Current implementation anchors:** `BrokerController::kiteCallback()`, `BrokerConnectionService`, `GET /api/v1/broker/kite/callback`, and `KiteCallbackTest`.

## 17. Public And Shared Access Boundaries

Guest-capability endpoints are deliberately narrow:

| Capability | What it authorizes | What it does not authorize |
| --- | --- | --- |
| Invite token | Read/accept that invitation | Browser login, other invites, portfolios, or Admin access. |
| Reset token | Validate/reset the intended credential | Session use, portfolio access, or user administration. |
| Kite callback state | Complete the initiating user's broker login and return safely | Arbitrary user selection, arbitrary return URL, or Investor API session authority. |
| Wiki share token | Render one shared wiki page and its related shared images | Private wiki tree, notes, portfolio data, or mutation. |
| Temporary signed email-verification URL | Verify the intended notification email capability | General account/session authority. |

Shared wiki tokens are hashed for lookup and revoked tokens cease access. Public/capability URLs must never be treated as a general authentication mechanism.

**Current implementation anchors:** `WikiShareService`, `WikiShareController`, `NotificationChannelSettingsService`, `UserInviteService`, and `PasswordResetLinkService`.

## 18. Administration Capabilities

Admin operates global/system resources: user/role/session management, invites, reset links, stock administration, sync controls, data quality, operational alerts, audit explorer, tax rule versions, fundamentals, ML, and automated-execution entitlement. These are Admin resources, not an alternative route to Investor cash, holdings, strategies, recommendations, or broker accounts.

Administrative mutations should retain actor context, target/resource identifiers, and useful outcome/error evidence. Admin operational logs are diagnostic/audit evidence, not financial source-of-truth.

## 19. Authorization Matrix

| Area | Principal/mechanism | Middleware/condition |
| --- | --- | --- |
| SPA auth/session/profile | Authenticated session | `auth:sanctum`; owns current session/profile. |
| Investor portfolio APIs | Investor session or personal token | `auth:sanctum`, `active.portfolio`, profile ownership; token scope when declared. |
| Execution mutation | Investor session/token | Active profile, `execution:submit` for token, plus entitlement/TOTP/safety services. |
| Admin resources | Admin session/token-capable route where provided | `auth:sanctum` and `admin`; no implicit Investor portfolio. |
| Invite/reset/wiki/callback | Guest capability request | Exact token/state/signed request validation only. |
| API documentation | Repository/runtime contract | Generated from live `/api/v1` route inventory; not an authority bypass. |

## 20. Security Lifecycles

| Capability | Lifecycle | Required behavior |
| --- | --- | --- |
| Invite | created -> pending -> accepted/expired/revoked | Single-use; raw token not stored; expired/revoked cannot accept. |
| Password reset | issued -> accepted/expired/revoked | Hash-protected, user-specific, session effects only after valid acceptance. |
| Session | active -> logged out/revoked/expired | User-owned; other-session and targeted revoke are constrained. |
| Personal token | created -> active -> revoked/expired | Scoped, owned by issuer, plaintext secret only at creation. |
| TOTP | not configured -> pending -> enabled -> recovered/disabled | Confirmation and recovery checks; secret/recovery codes protected. |
| Execution entitlement | disabled -> enabled -> revoked | Admin-controlled user attribute; insufficient by itself for execution. |

## 21. Audit And Security Events

Security-sensitive actions should be auditable: login/logout and relevant failures, session revocation, invite/reset issue/use/revocation, role changes, entitlement changes, TOTP enrollment/verification/recovery/disable, broker connect/disconnect, emergency safety actions, and Admin resource mutation.

**Current implementation anchors:** `AuthAuditService`, `PortfolioLoggerService`, `SystemLogService`, `SyncLogService`, `AdminOperationalAlertService`, `AdminAuditExplorerController`, `TotpService`, and `AutomatedExecutionEntitlementService`.

## 22. Request Correlation And Logs

Request/correlation data, frontend logs, system logs, sync logs, operational alerts, and audit explorer support diagnosis. Sensitive fields such as passwords, tokens, OTPs, authorization headers, and request bodies must be redacted. Logs describe observed events; they are not business, authorization, broker, or accounting source-of-truth.

**Current implementation anchors:** `PortfolioLoggerService`, `SystemLogService`, `SyncLogService`, `AdminOperationalAlertService`, and frontend logging routes/components.

## 23. API Architecture

Legacy `/api/*` routes remain for established SPA features. Additive `/api/v1/*` routes carry Trading OS and newer API contracts. Sanctum and active-portfolio resolution apply to authenticated portfolio routes; Admin middleware marks global/Admin boundaries; `token.scope` gates declared personal-token operations.

The Laravel SPA fallback serves application routes, not missing API endpoints. API routing must preserve API `404` semantics rather than returning SPA HTML for an unknown API path.

**Current implementation anchors:** `routes/api.php`, `routes/web.php`, `bootstrap/app.php`, `ResolveActivePortfolio`, and `EnsurePersonalApiTokenScope`.

## 24. OpenAPI Contract

`php artisan openapi:v1` builds the canonical `app/openapi/v1.json` from live `/api/v1` route inventory plus operation overlays and writes the public copy at `app/public/docs/openapi-v1.json`. `php artisan openapi:v1 --check` detects drift.

Regenerate the document whenever a V1 route, operation metadata, security requirement, or overlay changes. The generated contract documents V1 routes; it does not supersede actual middleware, ownership, validation, or business safety invariants, and it is not a complete contract for legacy `/api/*` routes.

**Current implementation anchors:** `WriteOpenApiV1Command`, `V1DocumentBuilder`, `V1OperationOverlays`, and `OpenApiV1ContractTest`.

## 25. API Error Semantics

- **401:** no valid authenticated session/token where authentication is required.
- **403:** authenticated principal lacks Admin authority, token scope, or another authorization/safety permission.
- **404:** unknown resource/route, including foreign active-profile selection where non-disclosure is required.
- **409:** business-state conflict only where a route explicitly uses it; do not assume it is universal.
- **419:** CSRF/session mismatch for browser state-changing requests.
- **422:** syntactically valid request failed validation or documented business validation.
- **429:** throttling/rate limit, including sensitive verification flows where applied.

The exact envelope may differ between legacy and V1 endpoints; clients must not collapse 401, 403, 404, and 419 into one generic “login failed” state.

## 26. Critical Security Invariants

- Sanctum session/cookie auth is the SPA authority; no auth token belongs in localStorage.
- Admin role does not imply Investor resource ownership or Investor API access.
- Foreign portfolio selection/access must fail without disclosure.
- Broker connection and automated-execution entitlement are user-bound.
- Token scope never bypasses ownership, role, entitlement, TOTP, or safety gates.
- TOTP is sensitive-action proof, not an entitlement or broker-order authorization replacement.
- Invite/reset/share/callback tokens authorize only their intended capability.
- Broker callback state is encrypted, short-lived, user-bound, and destination-constrained.
- Logs/audit evidence must not become trusted business input.
- Frontend/client claims are never authority.

## 27. Data Model And Relationships

`User` is the identity and role/entitlement owner. `PortfolioProfile` belongs to an Investor user and scopes investment resources. `UserInvite` and `PasswordResetLink` retain hashed-token lifecycle records and actor/target context. Sanctum `PersonalAccessToken` belongs to its issuing user and carries abilities/expiry. Session storage represents user sessions rather than portfolio ownership.

`BrokerConnection` is user-bound. TOTP secret, pending secret, confirmation/counter, and hashed StoX recovery codes and the safe authenticator-app display name are user security state. `ProfileSetting`/`Setting` distinguish profile/global settings by access rules. `SystemLog`, `SyncLog`, operational-alert records, and audit explorer evidence provide observability rather than authority.

## 28. API Routes

- **Auth/sessions:** `/api/auth/login`, `/me`, `/csrf-token`, `/logout`, and `/sessions*`.
- **Invites/resets:** guest `/api/invites/{token}`, `/invites/accept`, `/reset-password/{token}`, `/reset-password/accept`; Admin `/api/invites*` and `/api/password-reset-links*`.
- **Profile/tokens:** `/api/profile*` and `/api/personal-api-tokens*`.
- **TOTP:** `/api/v1/totp`, `/begin`, `/confirm`, `/verify`, `/recover`, `/disable`.
- **Admin:** `/api/users*`, `/api/admin/*`, operational/sync/data-quality routes, `/api/v1/admin/fundamentals/*`, `/api/v1/admin/ml/*`, and `/api/v1/admin/users/{user}/automated-execution-entitlement`.
- **Broker callback:** guest `/api/v1/broker/kite/callback`; authenticated broker status/login/disconnect routes remain execution-domain endpoints.

Route inventory is implemented in `routes/api.php`; route-level middleware and controller validation determine the concrete authorization boundary.

## 29. Services And Middleware

`AuthAuditService` and `SessionManagementService` own security session evidence/operations. `UserInviteService` and `PasswordResetLinkService` own capability-token lifecycles. Profile/settings/photo services own user/profile settings. `TotpService` owns enrollment, verification, recovery, disable, replay/rate-limit behavior. `AutomatedExecutionEntitlementService` owns Admin-mediated user entitlement changes.

`ResolveActivePortfolio` owns Investor active-profile resolution and Admin/Investor separation. `EnsureUserIsAdmin` owns role gating. `EnsurePersonalApiTokenScope` enforces token abilities. `AdminInvestmentOwnershipAuditService` detects/remediates impermissible Admin investment ownership only under its explicit safety rules.

## 30. Error And Recovery Semantics

| Condition | Expected handling |
| --- | --- |
| Bad credentials or throttled login | Deny authentication without creating authority; surface recoverable login/rate-limit feedback. |
| Expired session/CSRF mismatch | Re-establish browser session/CSRF state; `419` is not a role failure. |
| Foreign profile | Return non-disclosing `404`; do not select/create foreign state. |
| Missing scope/role | Return `403`; acquiring a token cannot repair ownership/role absence. |
| Expired/reused invite or reset | Deny capability; use Admin regeneration/new link path. |
| Revoked/expired token | Deny token API request; issue a new token through owner session. |
| Lost TOTP | Use valid recovery mechanism; do not downgrade execution safety silently. |
| Entitlement revoked while Automatic remains configured | Prevent automatic authority; execution mode configuration alone is insufficient. |
| Invalid/stale Kite callback state | Fail closed and redirect safely without broker session completion. |

## 31. Test And Verification Anchors

| Area | Test anchor | What it proves |
| --- | --- | --- |
| Login/session/CSRF | `app/tests/Feature/AuthSessionTest.php`, `AuthCsrfLoginTest.php` | Session creation, identity, logout, session list/revocation constraints, CSRF endpoints/login. |
| Invites | `app/tests/Feature/UserInviteTest.php` | Hash storage, guest acceptance, expiry/reuse failure, rotation, revoke, and Admin boundary. |
| Resets | `app/tests/Feature/PasswordResetLinkTest.php` | Admin-only management, guest acceptance, expiry/revoke, duplicate prevention, and session invalidation after valid reset. |
| Role separation | `app/tests/Feature/RoleSeparatedApplicationTest.php` | Portfolio-less Admin, Admin rejection from Investor APIs, Investor Admin denial, and shared/Admin route access. |
| Ownership audit | `app/tests/Feature/AdminInvestmentOwnershipAuditTest.php` | Detection/removal/refusal behavior for impermissible Admin investment graph. |
| Personal tokens | `app/tests/Feature/V6PersonalApiTokenTest.php` | Owner create/list/revoke and scope enforcement without breaking cookie auth. |
| TOTP | `app/tests/Feature/Totp/TotpFlowTest.php` | Enrollment, secret protection, verification/replay/rate limits, recovery single-use, disable. |
| Broker callback | `app/tests/Feature/Execution/KiteCallbackTest.php` | Guest callback uses encrypted initiating-user state and rejects invalid state. |
| OpenAPI | `app/tests/Feature/OpenApiV1ContractTest.php` | Generated document freshness, V1 route coverage, declared auth/Admin flags, and command check. |

**Test coverage gap — implementation audit follow-up:** execution-entitlement revocation across all Automatic runtime paths, full personal-token coverage for every declared V1 route, frontend role-boundary discoverability, and normal password-change token/session consequences require direct audit confirmation.

## 32. Debugging Guide

| Symptom | Likely layer |
| --- | --- |
| Login loop | CSRF bootstrap, Sanctum/session cookie domain, `AuthController`, login throttle, frontend auth redirect. |
| `419` | CSRF/session mismatch, not normally an Admin/ownership denial. |
| Unexpected `403` | Admin middleware, Investor/Admin separation, token scope, execution entitlement, TOTP, or safety service. |
| Foreign portfolio visible | `ResolveActivePortfolio`, controller query scoping, profile header/query, and ownership tests. |
| Admin appears to own Investor data | Role boundary, `PortfolioProfileService`, `AdminInvestmentOwnershipAuditService`, audit explorer. |
| Invite/reset fails | Capability token hash/expiry/acceptance state and associated service tests. |
| Token fails on route | `EnsurePersonalApiTokenScope`, token abilities/expiry, active portfolio, and route declaration. |
| TOTP challenge fails | `TotpService` status, rate limit, replay counter, recovery code state, execution caller. |
| Automatic execution blocked | Entitlement, user/profile ownership, execution mode, TOTP and execution-safety gates. |
| Broker callback fails | Encrypted state validity, request token/status, `BrokerConnectionService`, `KiteCallbackTest`. |
| API returns SPA HTML | `routes/api.php` versus web fallback/path; inspect route resolution. |
| OpenAPI differs from runtime | `php artisan openapi:v1 --check`, builder/overlays, and V1 route changes. |

## 33. Implementation Alignment Notes

The following accepted contracts require verification under the V1-V7 implementation audit and runtime checks; this is not a defect list:

- Complete route-by-route active-profile, ownership, Admin, and token-scope coverage, especially legacy APIs.
- Full UI reachability and responsive/error states for session, invite, reset, token, TOTP, and entitlement surfaces.
- End-to-end enforcement when entitlement or TOTP changes during pending/Automatic execution workflows.
- Runtime deployment correctness for Sanctum cookie, CSRF, callback, throttle, and session settings.
- Completeness of audit evidence and redaction under real operational failures.

## 34. Historical Context

Early JWT intent is superseded by Sanctum session authentication for the SPA and personal tokens for automation. V2 added invite, reset, and session/token capabilities. V5 formalized Admin/Investor separation. V6 added TOTP and trusted-execution hardening.

Current behavior is defined by this document and its linked current-domain contracts.
