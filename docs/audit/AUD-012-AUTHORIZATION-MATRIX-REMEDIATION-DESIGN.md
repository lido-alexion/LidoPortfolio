# AUD-012 Authorization Matrix Remediation Design

## 1. Executive Summary

This static review covered 94 React route declarations in `App.jsx` and 437
registered `api/*` operations from `php artisan route:list --json`, including
legacy-compatible `/api/*`, additive `/api/v1/*`, guest callbacks, public
share surfaces, and inherited middleware.

The central authorization model is coherent in the paths inspected:

- `ResolveActivePortfolio` resolves only an Investor-owned profile, returns
  `404` for a supplied foreign profile, and rejects Admin access to Investor
  application routes before creating a default profile.
- Admin routes use `EnsureUserIsAdmin`; Admin is not made an Investor owner.
- The scope middleware applies only when Sanctum authenticated the request by
  personal access token, so browser sessions are not accidentally scope-bound.
- Controllers/services examined for portfolios, notes, execution, artifacts,
  wiki, and profile-scoped accounting use active-profile or owner-scoped
  queries in addition to frontend guards.

No static cross-user read/write path, Admin-to-Investor ownership bypass, PAT
scope bypass, or legacy `/api/*` bypass of an equivalent `/api/v1/*` boundary
was confirmed. The audit's original concern remains valid as a coverage gap:
the repository has representative tests rather than a generated, complete
actor-by-route/object matrix. The highest-risk unresolved item is therefore
**AUTH-001: incomplete executable coverage of all profile-bound legacy and v1
route-model-binding endpoints** (High, test/assurance gap; not a confirmed
exploit).

## 2. Security Model

| Actor | Authority | Explicit limits |
| --- | --- | --- |
| Investor | Own profiles and their portfolio-scoped data; own broker connection, notes, private artifacts, sessions and tokens | Cannot select a foreign profile/resource or Admin endpoint. |
| Admin | Global operations: users, invites, session administration, entitlement, stock/data quality/calendar, registries, ML/fundamentals, operations/audit | Is not an Investor portfolio owner and is rejected from Investor application APIs. |
| PAT actor | The token owner's identity plus listed abilities | Token scope does not bypass owner/profile checks, Admin role, TOTP, entitlement, halt, reconciliation, or broker readiness. |
| Guest/public | Invite/reset verification, signed email verification, public wiki page/image capability, Kite callback state | Receives only the capability's narrowly scoped surface. |

`ResolveActivePortfolio` is both a request-context resolver and an Investor
authorization boundary. Its Investor lookup is `where user_id = request user`
plus supplied/default profile. Its Admin exception list permits only shared
identity/notification/calendar/indicator routes or routes carrying `admin`;
otherwise it returns `403` without creating Investor state.

## 3. Frontend Route Matrix

The SPA is a usability guard only; backend enforcement remains authoritative.

| Frontend route family | Intended role | Guard / rendering branch | Profile-dependent | Backend dependency | Risk |
| --- | --- | --- | --- | --- | --- |
| `/`, transactions, cash, holdings, watchlists, recommendations, strategy, screeners, candidates, backtests, portfolio history/compare/snapshots, review | INVESTOR_ONLY | `AppRoutes`; Admin renders `AdminAppRoutes` instead | Yes | Active-profile APIs | Medium: broad route surface; backend middleware is decisive. |
| execution/broker settings and safety controls | INVESTOR_ONLY | `AppRoutes`; safety controls hidden for Admin | Yes/user-scoped | `/api/v1/execution/*`, `/api/v1/broker/*` | High: backend gates/TOTP remain required. |
| knowledge board/wiki, notes, calendar, notification history/settings | INVESTOR_ONLY or AUTHENTICATED_SHARED by feature | Investor `AppRoutes`; shared profile/settings pages also in `AdminAppRoutes` | Notes/wiki yes; profile/notifications user scoped | Legacy knowledge/notification/calendar APIs | Medium. |
| artifact library, screeners/strategy registry | INVESTOR_ONLY with explicit shared/system access | `AppRoutes`; administrative registry variants wrapped with `AdminRoute` | Binding/profile dependent | `/api/v1/artifacts/*`, registry APIs | High: ownership/grant verification matters. |
| `/settings/users`, stocks, sync, data quality, indicators, registries, fundamentals, ML, audit, ops alerts | ADMIN_ONLY | `AdminRoute` in Investor route map; direct `AdminAppRoutes` for Admin | No active Investor profile | Admin middleware APIs | Medium: direct URL still needs backend check. |
| `/profile`, `/documentation`, notification history/settings | AUTHENTICATED_SHARED | Present in both route trees | User scoped, not Investor portfolio scoped | Auth/profile/docs/notification APIs | Low. |
| `/invite/:token`, `/reset-password/:token`, `/documentation` when guest, `/wiki/shared/:token` | PUBLIC | unauthenticated route branch/public wiki branch | No | guest/signed/capability APIs | Medium: capability expiry and token handling. |

`App.jsx` selects `AdminAppRoutes` instead of `AppRoutes` for an Admin, which
prevents accidental rendering of Investor pages. The explicit `AdminRoute`
wrappers in the Investor map are defense-in-depth and redirect non-Admin
users; they do not replace `admin` backend middleware.

## 4. Backend Route Matrix

The full 437-operation inventory was obtained from Laravel route metadata.
The following grouped matrix records inherited middleware and ownership
mechanisms for every material route family; aliases represent all methods and
member variants in that family.

| Method / route family | Intended role | Middleware | Active profile | Ownership checked where? |
| --- | --- | --- | --- | --- |
| Guest `/api/auth/login`, invites, reset, build info | PUBLIC | throttle/public | No | Token/hash/expiry services. |
| Guest `/api/wiki/shared/{token}` and images | PUBLIC capability | token format; public controller checks share state | No | `WikiShareController`: token hash, active/revoked share, page-image relation. |
| Guest `/api/v1/broker/kite/callback` | PUBLIC callback | encrypted state validation in service | No | State binds user/provider/return path/expiry. |
| `/api/auth/*`, `/api/profile*`, sessions, personal tokens | AUTHENTICATED_SHARED | `auth:sanctum`, `active.portfolio` with Admin shared-route exception | No for business data | Controllers scope to authenticated user/session/token. |
| `/api/notification-center/*`, settings, email destinations | AUTHENTICATED_SHARED | `auth:sanctum`; active-profile exception for Admin shared routes | No | Current user/channel/destination query scope. |
| `/api/calendar/*` | AUTHENTICATED_SHARED | `auth:sanctum`, `active.portfolio` exception | Mixed | Calendar controller distinguishes personal events from Admin global holidays. |
| `/api/portfolios*`, transactions, cash, holdings, corporate actions, watchlists, knowledge, analytics, tax, alerts, screeners, strategies, recommendations, backtests, replay | INVESTOR_ONLY | `auth:sanctum`, `active.portfolio` | Yes | Active profile plus controller/service profile/owner queries. |
| `/api/v1/execution/*`, broker status, orders, protections, reconciliation | INVESTOR_ONLY | `auth:sanctum`, `active.portfolio`, selected `token.scope` | Yes and user-scoped broker identity | Active profile, `ExecutionGate`, order/recommendation/profile checks, TOTP/entitlement/safety. |
| `/api/v1/artifacts/*` | Investor/shared/system, Admin for indicators | `auth:sanctum`, `active.portfolio`; controller type checks | Depends on artifact operation | Registry service/grants/adoption/binding plus Admin indicator restriction. |
| `/api/admin/*`, `/api/v1/admin/*`, user/session/entitlement, stock/data quality/sync/registry/ML/fundamental/ops/audit | ADMIN_ONLY | `auth:sanctum`, `active.portfolio`, `admin` | No | `EnsureUserIsAdmin`; global/system resource scope. |

Route-model-binding endpoints are not assumed safe solely because of binding.
The review traced representative resource classes (portfolio, note, wiki,
transaction, holding, recommendation/order/execution, artifact, backtest and
calendar) to active-profile queries, controller checks, or service ownership
checks. The remaining unproven issue is systematic test coverage for every
bound parameter and nested member route.

## 5. Active Portfolio Enforcement

| Case | Static behavior | Evidence | Status |
| --- | --- | --- | --- |
| Investor, owned `X-Profile-Id`/`X-Portfolio-Id`/`portfolio_id` | Resolves owned active profile | `ResolveActivePortfolio` query filters `user_id` and id | SECURE |
| Investor, foreign profile header/query | Non-disclosing `404` | Middleware and `PortfolioMiddlewareTest` | SECURE |
| Investor, missing profile | Uses/creates the user's default profile | Middleware and profile service | SECURE |
| Admin, Investor endpoint | `403`; no default Investor profile creation | Middleware and `RoleSeparatedApplicationTest` | SECURE |
| Admin, Admin/shared route | Sets active profile to null | middleware route inspection | SECURE_BUT_UNTESTED across every exception-list path |
| deleted/inactive profile | Global soft-delete scope excludes deleted profiles | Eloquent model/middleware behavior | SECURE_BUT_UNTESTED for every endpoint |

The exception list in `isAdminOrSharedRoute()` is sensitive infrastructure:
adding a new Admin/shared route without `admin` middleware or without updating
the intended exception behavior can create an Admin UX failure or, if a
portfolio controller is accidentally listed, a boundary risk. This is a
change-control/test concern, not a confirmed current bypass.

## 6. Object Ownership Matrix

| Resource/domain | Owner key | Intended accessor | Enforcement | Test evidence | Gap |
| --- | --- | --- | --- | --- | --- |
| Profiles, transactions, holdings, cash, reservations, capital/loans/recalls | `profile_id -> PortfolioProfile.user_id` | Investor owner | active profile plus controller/service scoped queries | portfolio, financial integrity, capital/lending tests | SECURE_BUT_UNTESTED complete member-route matrix |
| Strategies, holdings adoption, recommendations, positions | profile and strategy ownership | Investor owner | active profile; strategy/profile checks; execution services | V3 ownership/adoption/execution tests | SECURE_BUT_UNTESTED nested mismatch matrix |
| Orders, execution decisions, broker connection, halt/TOTP | profile and/or `user_id` | Investor owner; Admin entitlement only | active profile, `ExecutionGate`, broker services, TOTP | Live execution, advanced order, callback, TOTP tests | SECURE_BUT_UNTESTED all order IDs and PAT paths |
| Screeners, reusable artifacts, bindings | user/profile/system/grant | Owner, same-user adoption, explicit grant, Admin indicator operator | registry controller/services and type checks | shared screener, artifact sharing/lifecycle tests | SECURE_BUT_UNTESTED grant revocation and every legacy route |
| Backtests, replay, reports, analytics evidence | profile/user/run owner | Investor owner | active profile and controllers/services | replay/backtest tests | SECURE_BUT_UNTESTED bound run/detail endpoints |
| Notes, wiki pages/revisions/images/share | user/profile/page/share token | Investor owner or public bearer capability | owner/profile query; token hash/revocation/relation checks | V6 notes and knowledge tests | SECURE_BUT_UNTESTED nested page/image/revision matrix |
| Notifications/settings/history | user/profile channel | Current user | controller user scope | notification/token tests | SECURE_BUT_UNTESTED destination and notification-id matrix |
| Stock master, data quality, holidays, operations, registries, ML/fundamentals | global/system | Admin, with selected global Investor reads | admin middleware/controller checks | role-separation/admin tests | SECURE_BUT_UNTESTED full Admin endpoint matrix |

## 7. Admin Capability Matrix

| Capability | Backend guard | Investor-owned data access | Static result |
| --- | --- | --- | --- |
| Users, invites, role/session operations | `admin` | Administrative identity only | SECURE |
| Execution entitlement grant/revoke | `admin`; target user explicit | Does not grant ownership of that user's profile/broker | SECURE_BUT_UNTESTED revocation race matrix |
| Stocks, indices, sync, data quality, holidays | `admin` or calendar Admin branch | Global market operations | SECURE |
| System registries/indicators, ML/fundamentals, audit/operational alerts | `admin` and controller checks | Global/system records, not portfolio ownership | SECURE_BUT_UNTESTED full endpoint matrix |
| Investor portfolio/accounting/execution/note resources | rejected by `ResolveActivePortfolio` | None | SECURE in representative tests |

## 8. Investor Capability Matrix

| Capability | Expected boundary | Static result |
| --- | --- | --- |
| Profile selection | Own profiles only | SECURE; foreign header gives `404`. |
| Portfolio/accounting/strategy/recommendation/execution | Active profile plus resource/profile checks | SECURE_BUT_UNTESTED comprehensively. |
| Broker/TOTP/halt | Current user only; execution policy adds entitlement/readiness | SECURE_BUT_UNTESTED PAT and every mutation matrix. |
| Private knowledge/notes/wiki | Owner/profile only | SECURE_BUT_UNTESTED nested-resource matrix. |
| Artifacts | Owner, system, or explicit grant/adoption | SECURE_BUT_UNTESTED sharing/revocation matrix. |
| Admin operations | Admin middleware | SECURE in representative rejection tests. |

## 9. PAT Scope Matrix

| Scope | Route family | Ownership still enforced? | Safety gates preserved? | Evidence |
| --- | --- | --- | --- | --- |
| `portfolio:read` / `portfolio:write` | portfolio list/member mutations | Yes: active profile/user ownership | N/A | `V6PersonalApiTokenTest`, route middleware. |
| `notes:read` / `notes:write` | contextual notes | Yes: user/profile/note owner | N/A | route scopes plus notes controller/tests. |
| `execution:read` | execution mode/state/broker status | Yes | TOTP not relevant to reads; no authority bypass | route metadata/tests. |
| `execution:submit` | mode, halt/recover, quote policy, submit, emergency broker actions | Yes | TOTP, entitlement, halt, readiness and final gate remain service-level checks | route metadata, execution/TOTP tests. |
| No PAT / browser session | all normal scoped routes | Yes | Scope middleware deliberately passes cookie sessions; domain checks still apply | `EnsurePersonalApiTokenScope`. |

No static scope bypass was found. Missing test coverage: foreign-object attempts with a valid narrow PAT, Admin PAT behavior, and each execution mutation's independent safety gate.

## 10. Callback / Identity Binding

Kite callback is guest-safe because it cannot rely on the SPA cookie after a
cross-domain redirect. `BrokerConnectionService::loginUrl()` creates encrypted,
short-lived state containing the initiating user/provider and allowlisted return
context. Callback tests prove valid state attaches the connection to that user
and invalid state makes no Kite request. Required future tests should add
expiry, replay, state swapping between two users, and provider/return-target
tampering.

## 11. Frontend/Backend Mismatches

| Observation | Classification | Impact |
| --- | --- | --- |
| Admin SPA omits Investor routes while backend rejects Investor APIs | aligned defense in depth | No confirmed gap. |
| Investor SPA hides Admin navigation and wraps direct routes in `AdminRoute`; backend has `admin` middleware | aligned defense in depth | No confirmed gap. |
| Shared profile/notification/calendar frontend routes rely on backend's Admin exception list | BACKEND_ONLY_GUARD | Requires route-matrix regression tests, not a code change now. |
| Admin direct URLs outside `AdminAppRoutes` fall back to admin settings/users | frontend UX behavior | No backend authorization conclusion. |

## 12. Test Coverage Matrix

| Test area | What current tests prove | What they do not prove |
| --- | --- | --- |
| `RoleSeparatedApplicationTest` | Admin has no default portfolio; representative Investor APIs reject Admin; shared/session/profile/settings/notification/calendar exceptions retain no-profile behavior | Every Admin/Investor route and object ID. |
| `PortfolioMiddlewareTest` | default profile, owned switching, `X-Profile-Id`, `X-Portfolio-Id`, query selection, deleted/foreign profile `404`, foreign portfolio member rejection | Every profile-bound controller and nested body parameter. |
| `V6PersonalApiTokenTest` | token create/list/revoke, scoped owner member access, foreign member rejection, missing scope and Admin PAT non-ownership | execution/no-scope permutations and every scoped endpoint. |
| `KiteCallbackTest` | encrypted state connects initiator; invalid, expired and tampered state make no Kite request; another browser user cannot receive the initiator's connection | one-time replay behavior and deployment cache/session configuration. |
| `V6ContextualNotesTest` and Knowledge tests | profile/user CRUD ownership portions, foreign delete rejection and PAT scope/ownership composition | exhaustive nested IDs/images/revisions/shares. |
| Artifact sharing/registry tests | selected sharing/isolation lifecycle | every grant/revocation/binding route. |
| Execution, ownership, capital tests | selected profile/user/execution checks | generated per-route attack matrix. |

## 13. Confirmed Findings

### AUTH-001 - High-risk route/object authorization coverage expanded; complete matrix remains open

- **Requirement:** Admin/Investor ownership matrix and foreign-resource
  rejection must be established for all protected route families.
- **Evidence:** High-risk regression coverage now exercises active-profile
  aliases, deleted/foreign profiles, portfolio member binding, PAT scope plus
  ownership, Admin PAT non-ownership, and contextual-note ownership. The
  `PortfolioProfile` binding now resolves through `auth('sanctum')`, allowing
  a valid PAT to resolve its own member route while retaining owner scoping.
  The 437-operation inventory is not yet represented by a declarative test
  manifest.
- **Exploit/failure scenario:** A new legacy or v1 member route may omit a
  controller/service scope check while appearing protected by frontend UI or
  active profile middleware.
- **Verdict:** `PARTIALLY_ENFORCED` (assurance expansion implemented; no
  bypass remains in the tested paths).
- **Severity:** High (assurance gap, not confirmed exploit).
- **Recommended remediation:** Extend the executable matrix to remaining
  nested execution, accounting, artifact/grant and legacy/v1 pairs.

### AUTH-002 - Admin/shared-route exception regression coverage expanded; complete list remains open

- **Requirement:** Admin must use global/shared APIs without becoming an
  Investor resource owner.
- **Evidence:** Regression coverage now verifies Admin access to sessions,
  profile, settings, notification center/settings and calendar while asserting
  no Admin portfolio is created. Wildcard/member exception paths remain to be
  enumerated.
- **Exploit/failure scenario:** A future path addition may be wrongly omitted
  (Admin denial) or incorrectly added (boundary regression).
- **Verdict:** `PARTIALLY_ENFORCED`.
- **Severity:** Medium.
- **Recommended remediation:** Add explicit cases for each remaining wildcard
  exception path and a nearby Investor-only control route.

### AUTH-003 - Debug-agent authentication hook is deployment-sensitive

- **Requirement:** Debug authentication must never create an unintended
  production Admin bypass.
- **Evidence:** `DebugAgentToken` prepends to API middleware but is inert
  unless `portfolio.debug_agent.enabled` and a non-empty secret are deployed.
- **Exploit/failure scenario:** Production enables the hook or exposes its
  secret.
- **Verdict:** `RUNTIME_VERIFICATION_REQUIRED`.
- **Severity:** High if enabled in production; otherwise no runtime effect.
- **Recommended remediation:** Verify production configuration/secret policy;
  consider a deployment assertion in a separately approved security change.

No finding was classified `OWNERSHIP_GAP`, `ROLE_GAP`, `TOKEN_SCOPE_GAP`, or
`LEGACY_ROUTE_GAP` from the static evidence reviewed.

## 14. Runtime Verification

- Confirm `debug_agent.enabled` is false and no debug token is deployed.
- Exercise Sanctum cookie/CSRF and PAT requests against deployed middleware.
- Run two-Investor plus Admin route/object smoke matrix in staging with real
  IDs, including callbacks and broker entitlement changes.
- Verify public wiki capability revocation/image restrictions and Kite state
  expiry/replay behavior in deployed cache/session configuration.

## 15. Remediation Options

| Finding | Option | Advantages | Disadvantages |
| --- | --- | --- | --- |
| AUTH-001 | Declarative endpoint/resource policy manifest plus parameterized tests | Complete, reviewable, catches new route drift | Initial classification work. |
| AUTH-001 | Test only high-risk controllers manually | Smaller first patch | Cannot establish complete matrix. |
| AUTH-002 | Derive Admin/shared handling from explicit middleware metadata | Less path-list drift | Broader middleware redesign. |
| AUTH-002 | Retain list and add exhaustive regression tests | Smallest safe option | List remains manual. |
| AUTH-003 | Deployment verification/runbook assertion | No production behavior change | Cannot prove remote config from repository. |

## 16. Recommended Implementation Sequence

1. Add parameterized foreign-profile/foreign-resource tests for execution,
   broker, recommendation/order, transaction/cash, and knowledge mutations.
2. Add Admin-versus-Investor actor cases for every `admin` and
   `active.portfolio` route family, including no-profile-creation assertions.
3. Add PAT foreign-object and execution safety cases.
4. Add Kite callback replay/expiry/user-swap cases.
5. Add an Admin/shared exception-list regression matrix.
6. Verify deployment configuration for the debug hook and Sanctum/callback
   behavior.

## 17. Proposed Test Plan

Create a shared actor fixture: Investor A/Profile A, Investor B/Profile B,
Admin without a profile, guest, and PAT A with narrow abilities. Add a
parameterized test manifest, likely rooted in a new
`AuthorizationMatrixTest`, with focused companion cases in existing suites.

| File | Cases |
| --- | --- |
| `RoleSeparatedApplicationTest` | Every Admin and shared route family; Admin no-profile invariant. |
| `PortfolioMiddlewareTest` | header/query/default/deleted/foreign profile and nested parent-child mismatch. |
| `LiveExecutionFeatureTest` / `AdvancedOrdersFeatureTest` | foreign recommendation/order/protection, PAT scope, TOTP/entitlement/halt independence. |
| `V6PersonalApiTokenTest` | foreign profile/resource with valid scope; Admin PAT; execution scopes. |
| `V6ContextualNotesTest` and knowledge tests | foreign note/page/revision/image/share mutation and public capability constraints. |
| artifact/screener registry tests | owner/grant/revocation/system/admin-indicator matrix. |
| `KiteCallbackTest` | expired/reused/swapped state and return-target/provider tampering. |

For every protected category assert: owner succeeds, Investor B fails, Admin
matches documented role behavior, guest is `401`/public-only, and PAT respects
both scope and ownership. Preserve current `403` versus non-disclosing `404`
behavior; do not normalize error semantics as part of the coverage patch.

## 18. Open Questions

1. Should the path-based Admin/shared exception list be replaced by explicit
   route metadata, or is exhaustive regression coverage sufficient?
2. Which public deployment mechanism proves the debug-agent hook cannot be
   enabled in production without an intentional break-glass process?
3. Which non-disclosure cases are contractually required to be `404` rather
   than `403` beyond the active-profile and note/wiki examples?

## Validation Record

- Reviewed route groups and Laravel route metadata, middleware aliases and
  inheritance, `ResolveActivePortfolio`, `EnsureUserIsAdmin`, PAT scope
  middleware, React Admin/Investor routing, ownership-sensitive controllers,
  callback handling, and listed authorization tests.
- The AUTH-001/AUTH-002 assurance patch added focused regression coverage and
  changed only `PortfolioProfile::resolveRouteBinding()` to use the Sanctum
  guard. The prior default-guard lookup made owner PAT member routes resolve
  as `404` before scope/ownership policy could be exercised; the updated guard
  retains the existing owner filter.

## 19. Remediation Outcome

- **AUTH-001:** Partially implemented. No cross-user read/write path was
  found in the tested high-risk profile, portfolio, PAT or contextual-note
  paths. The bearer-token member-route resolution defect was corrected with a
  focused regression test.
- **AUTH-002:** Partially implemented. Admin/shared exception regression
  coverage proves the tested paths retain a null active profile and do not
  create Investor ownership.
- **AUTH-003:** Unchanged. Debug-agent configuration and production
  Sanctum/callback behavior require deployment verification.
- Kite login state is encrypted and time-bound. It is not currently a
  server-side single-use nonce; replay prevention remains an explicit
  follow-up decision rather than an inferred contract change.
