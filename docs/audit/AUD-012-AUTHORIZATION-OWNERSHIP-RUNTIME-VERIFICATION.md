# AUD-012 - Authorization / Ownership / Runtime Boundary Verification

## 1. Finding Recap

AUD-012 began as `RUNTIME_VERIFICATION_REQUIRED` because static role and
ownership tests did not establish that the deployed data and runtime
configuration preserved those boundaries. The production ownership audit is
clean, but deployment verification found a confirmed critical issue before
role-authenticated probing could safely continue: the temporary DebugAgent
authentication hook is enabled in production.

The hook is documented in code as pre-launch-only. It can authenticate an
unauthenticated API request as the first Admin when a shared debug token is
provided. The token value is intentionally omitted from this document and was
not used. This is a confirmed production authorization bypass risk.

## 2. Security Contract

```text
Admin -> global/shared Admin resources only; never Investor ownership
Investor A -> Investor A profiles and resources only
PAT -> token owner plus granted scope; ownership and safety checks still apply
Guest -> only explicitly public or signed/capability routes
```

Frontend route separation is defense in depth. Laravel middleware, active
profile resolution, owner-scoped queries, role middleware, PAT abilities, and
service-level execution gates are authoritative.

## 3. Production Actor / Data Context

Sanitized production inventory:

| Measure | Result |
| --- | --- |
| Admin users | 1 |
| Investor users | 1 |
| Active live portfolios | 3 |
| Eligible Semi-Automatic/Automatic portfolios | 1 |
| Soft-deleted portfolios | 2 |
| Personal access tokens | 0 |
| Admin-owned Investor data conflicts | 0 |

The one eligible live profile belongs to the Investor account. No production
Admin default profile or Admin-owned Investor resource was found.

## 4. Route Inventory

The deployed and current repository route inventory each report 443 registered
routes. The previous AUD-012 static inventory recorded 437 API operations, so
the current count is six higher. The route families remain materially covered
by the existing matrix, but the six-operation delta is retained as a route
inventory follow-up rather than silently treated as covered.

Representative current families include portfolio/accounting, holdings,
transactions, recommendations, execution/broker, notes, Wiki, artifacts,
Backtest/Replay, Admin operations, tokens/sessions, notifications, calendar,
and public shared-Wiki capability routes.

## 5. Ownership Audit

The deployed read-only command:

```text
php artisan portfolio:audit-admin-investment-ownership --json
```

returned `safe_to_enforce: true`, one Admin account checked, zero conflicting
Admin accounts, and no Admin-owned Investor data. No production data was
changed.

The audit covers Admin profiles and related `profile_id` rows plus direct
broker/execution ownership tables. No orphan or cross-user ownership conflict
was reported by the command.

## 6. Active Profile Isolation

Static middleware and tests cover `X-Profile-Id`, `X-Portfolio-Id`, and
`portfolio_id` selection aliases. An Investor-owned profile resolves; a
foreign/deleted profile returns the accepted non-disclosing failure; an Admin
does not acquire a default Investor profile.

No authenticated cross-Investor production probe was run because production
has one Investor account and zero PATs. The production ownership audit is
clean, while the full foreign-object runtime matrix remains open.

## 7. Admin -> Investor Boundary

The static `RoleSeparatedApplicationTest` proves Admin rejection across
portfolios, transactions, holdings, notes, recommendations, and broker status,
while shared session/profile/settings/notification/calendar routes remain
available without creating a profile.

Unauthenticated production probes could not be used as clean role-denial
evidence: protected API requests returned HTTP 500 because PHP could not append
to the current Laravel log file. No Admin credential was used in production.

## 8. Investor -> Admin Boundary

Static tests prove an Investor is rejected from representative Admin APIs and
the current route inventory retains `admin` middleware on Admin families. No
production Investor credentials were used for mutation or privileged probes.

The current production debug hook prevents claiming a clean runtime boundary
until disabled and rechecked.

## 9. Cross-Investor Object Isolation

No second production Investor exists for a safe live foreign-ID probe. The
repository tests cover foreign profile/member and representative object
ownership cases. Production evidence therefore remains limited to clean
relational ownership data, not a live two-Investor denial drill.

## 10. Nested / Member Route Isolation

Static coverage includes profile/member binding and representative contextual
note, Wiki, artifact, and execution ownership paths. The current route count
delta and lack of a second production Investor leave exhaustive nested/member
runtime coverage open. No evidence of a bypass was found.

## 11. PAT Scope + Ownership

Production has zero personal access tokens, so no live PAT probe was possible.
Static `V6PersonalApiTokenTest` coverage proves owner plus scope composition,
foreign member rejection, missing-scope denial, and Admin PAT non-ownership.
PAT scope does not replace active-profile, role, TOTP, entitlement, halt,
reconciliation, or broker-readiness checks.

## 12. Execution / Broker Ownership

The static execution tests retain profile/user ownership and final safety gates
before broker submission. No production order or broker mutation was attempted.
The production ownership command found no Admin broker/execution ownership
conflict. A fresh runtime broker-owner probe remains unavailable because no
safe second-user/PAT fixture exists.

## 13. Knowledge / Notes / Wiki

Static `V6ContextualNotesTest`, Knowledge Board, image, and Wiki suites cover
owner/profile scoping, nested resources, private data, and public share-token
relations. No production public share was created or exercised. Existing
public capability routes were included in the route inventory review.

## 14. Artifacts / Sharing / Bindings

Artifact library, sharing, binding, and grant tests cover owner/system/grant
semantics and revoked access. AUD-007 covers rollout/version safety; this
audit does not repeat that lifecycle. Production artifact grant and private
cross-user probes remain runtime-unverified.

## 15. Notifications / Calendar / Shared Routes

Static and route-level evidence covers current-user notification/settings
scope and Admin shared-route behavior without an active Investor profile.
AUD-010 covers calendar semantics; this audit covers only the Admin/global
authorization boundary. No production mutation was performed.

## 16. Public Capability Boundaries

Unauthenticated production probes returned:

| Surface | Result |
| --- | --- |
| `/api/build-info` | 200, public build metadata |
| `/api/auth/me` | 200 with `user: null` |
| Invalid shared-Wiki token | 404 |
| Protected Investor/Admin APIs | 500 due log-write permission failure |

The protected-API 500s are not authorization success and do not expose
business data in the observed response, but they are a runtime error-state
defect that must be corrected before clean denial verification.

## 17. Debug / Deployment Auth Controls

Production `php artisan config:show portfolio.debug_agent` reported the
debug-agent hook enabled. The deployed middleware is prepended to the API
group and accepts a shared header/query token; on a match it logs in the first
Admin for an otherwise unauthenticated request. This is incompatible with the
production authorization contract and is classified as critical.

The production environment was also checked during this audit without
printing the configured token. The token was not used. `APP_DEBUG` and
`APP_ENV` were not treated as sufficient compensation for this hook.

The current release's Laravel log file is owned `nitty:nitty` with mode 664,
while PHP-FPM runs as `www-data`; this causes protected unauthenticated API
requests that attempt to log to return HTTP 500. This is a separate deployment
runtime defect that obscures expected 401/403 behavior.

## 18. Frontend Role Separation

Static `AppRoutes` / `AdminAppRoutes` separation remains coherent: Admin does
not receive Investor portfolio/trading navigation, and Investor does not
receive Admin operations navigation. Direct URLs remain backend-authorized;
browser role walkthrough was not performed because the production debug hook
and log-permission defect make a clean auth runtime test unsafe and
unrepresentative.

## 19. CORS / CSRF / Guest Exposure

The route/config review retains Laravel/Sanctum CSRF handling for browser
cookie mutations and applies PAT abilities only to PAT-authenticated requests.
The deployment model's broad non-credentialed CORS behavior is not by itself a
credential bypass. Public surfaces observed were build info, auth-me behavior,
and signed/capability Wiki routes; protected business routes were not publicly
successful.

CSRF and authenticated CORS behavior remain runtime follow-up items because
no clean authenticated browser session was used in this audit.

## 20. Production Data Integrity

The ownership audit returned zero conflicts. Production has one Admin, one
Investor, three live profiles, two soft-deleted profiles, and no PATs. No
Admin-owned portfolios, profile rows, direct broker connections, or execution
records were reported. No production rows were edited.

## 21. Gap Register

| ID | Finding | Classification | Severity | Evidence |
| --- | --- | --- | --- | --- |
| AUTHR-001 | Debug-agent shared-token authentication is enabled in production | `PARTIALLY_IMPLEMENTED` | Critical | Deployed `portfolio.debug_agent.enabled=true`; middleware can authenticate as first Admin |
| AUTHR-002 | Protected unauthenticated API denials return HTTP 500 because PHP cannot write Laravel logs | `PARTIALLY_IMPLEMENTED` | High | Production probes and `www-data`/log-file permission mismatch |
| AUTHR-003 | Production ownership audit is clean | `IMPLEMENTED` | High | `portfolio:audit-admin-investment-ownership --json` returned zero conflicts |
| AUTHR-004 | Role separation, profile aliases, PAT scope/ownership, and representative object checks | `IMPLEMENTED_WITH_LIMITATION` | High | Static tests pass; no second production Investor/PAT fixture |
| AUTHR-005 | Current route inventory is 443 versus prior 437-operation audit | `RUNTIME_VERIFICATION_REQUIRED` | Medium | Deployed and current `route:list --json` both report 443 |
| AUTHR-006 | Full authenticated Admin/Investor browser and foreign-object runtime matrix | `RUNTIME_VERIFICATION_REQUIRED` | Medium | Production credentials/second Investor fixture unavailable; static coverage exists |

## 22. Cross-Audit Evidence

- AUD-009: deployed release and production runtime configuration are available,
  but the log permission defect is new authorization-boundary evidence.
- AUD-011: broker ownership command and execution ownership tests provide
  supporting evidence; no live broker mutation was attempted.
- AUD-010: global calendar/Admin semantics are separate from Investor private
  ownership.
- AUD-007: artifact rollout is separately verified; artifact grants remain an
  authorization runtime boundary here.

## 23. Final AUD-012 Assessment

**Disposition: `PARTIALLY_IMPLEMENTED` (High severity, High confidence).**

The production ownership audit is clean and static role/ownership enforcement
is substantial. However, the enabled production debug-agent hook is a confirmed
critical authorization bypass risk, and the log-permission defect prevents
clean unauthenticated denial results. These are actual deployed boundary
defects, not merely missing test coverage.

`V5-REQ-018` remains `PARTIALLY_IMPLEMENTED` pending removal/disablement of the
debug hook, correction of runtime log permissions, and a clean representative
role/foreign-object runtime matrix.

## 24. Open Questions

- What administrator-controlled process will disable the pre-launch debug hook
  and prevent its re-enablement in future production releases?
- Which deployment step will ensure shared Laravel logs are writable by the
  PHP-FPM user while retaining appropriate ownership and permissions?
- Which approved dedicated test identities can support a two-Investor and PAT
  runtime denial matrix without touching real financial state?
