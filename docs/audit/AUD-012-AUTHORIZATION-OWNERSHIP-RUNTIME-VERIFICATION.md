# AUD-012 - Authorization / Ownership / Runtime Boundary Verification

## 1. Finding Recap

AUD-012 began as `RUNTIME_VERIFICATION_REQUIRED` because static role and
ownership tests did not establish that the deployed data and runtime
configuration preserved those boundaries. The production ownership audit is
clean. Remediation then found two deployed runtime defects: the temporary
DebugAgent authentication hook was enabled in production, and PHP-FPM could
not append to the current Laravel log.

Before remediation, the hook was documented as pre-launch-only but could
authenticate an unauthenticated API request as the first Admin when a shared
debug token was provided. The token value is intentionally omitted from this
document and was not used. This was a confirmed production authorization
bypass risk.

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

The latest master route inventory reports 443 total registered routes,
including 438 `api/*` operations and five framework/web routes. The prior
AUD-012 artifact recorded 437 `api/*` operations. Re-running that audited
revision (`e5c6fb2`) produces 442 total routes and 437 API operations. The
apparent six-route delta is therefore one actual new API operation plus five
non-API framework routes that were outside the prior API-only inventory.

The exact comparison found one added API route, no removed routes, no
method/path changes, and no action or middleware changes for existing routes:

| Delta route | Method / URI | Action | Middleware | Classification |
| --- | --- | --- | --- | --- |
| Actual API addition | `POST /api/notification-center/{notification}/deliveries/{delivery}/retry` | `NotificationCenterController@retryDelivery` | `api`, `auth:sanctum` | Shared authenticated; current-user notification ownership; no active profile; no PAT ability middleware |
| Prior API-scope exclusion | `GET|HEAD /sanctum/csrf-cookie` | Sanctum `CsrfCookieController@show` | `web` | Guest bootstrap; no business data or ownership |
| Prior API-scope exclusion | `GET|HEAD /storage/{path}` | Laravel signed file-serving closure | none at route level; signed relative URL enforced by framework for private local disk | Signed/capability route; no active profile; path capability only |
| Prior API-scope exclusion | `PUT /storage/{path}` | Laravel signed file-receiving closure | none at route level; `upload` flag plus signed relative URL required | Signed/capability route; no active profile; path capability only |
| Prior API-scope exclusion | `GET|HEAD /up` | Laravel health closure | none | Guest health probe; no business data |
| Prior API-scope exclusion | `GET|HEAD /{any?}` | Laravel SPA `ViewController` | `web` | Guest frontend fallback; excludes `api/*` paths |

The retry route is statically covered by
`NotificationCenterDeliveryApiTest`: the account query scopes the parent
notification to the authenticated user, the delivery is then constrained to
that notification, and foreign-user retry returns 404. It does not create an
active Investor profile and does not bypass PAT scope because notification
center is an account-scoped shared surface. The five framework routes expose
no Investor-owned API data; storage read/write requires Laravel's signed
relative capability, the CSRF route only establishes a cookie, `/up` is a
health response, and the SPA fallback is not an API route.

No route can be identified in this delta that crosses Investor ownership,
creates Admin Investor ownership, bypasses Admin middleware, bypasses active
profile ownership, exposes broker/execution state, or creates an unintended
guest business API surface. Legacy and `/api/v1` routes had no corresponding
method/path changes in the exact comparison.

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

The initial unauthenticated production probes returned:

| Surface | Result |
| --- | --- |
| `/api/build-info` | 200, public build metadata |
| `/api/auth/me` | 200 with `user: null` |
| Invalid shared-Wiki token | 404 |
| Protected Investor/Admin APIs | 500 due log-write permission failure |

The protected-API 500s are not authorization success and do not expose
business data in the observed response, but they are a runtime error-state
defect that must be corrected before clean denial verification.

After the DebugAgent configuration cache was refreshed, the same protected
probes still returned 500 because the dated log files remain `nitty:nitty`
mode 664. A dummy invalid debug header also did not authenticate, but this
does not replace the required clean denial probe after log repair.

## 17. Debug / Deployment Auth Controls

The remediation changed DebugAgent configuration to default disabled with no
repository fallback token, removed query-string authentication, and added an
explicit production hard block in `DebugAgentToken`. Production configuration
now contains `LIDO_AGENT_DEBUG_ENABLED=false`; after config cache refresh,
`php artisan config:show portfolio.debug_agent` reports `enabled=false`. The
token value was not printed or used.

The deployment release gate and runtime health check both require effective
`production|false` state and the explicit production environment flag. This
prevents a future release from activating successfully with DebugAgent enabled.

Before remediation, the current Laravel log files were `nitty:nitty` mode
664 while PHP-FPM ran as `www-data`, causing protected unauthenticated API
requests that attempted to log to return HTTP 500. The repository now defines
the durable `nitty:www-data` setgid/group-writable model and health checks it.
The existing shared log directory is `nitty:www-data` mode 775, but the
current dated log files still require an administrator-owned group repair; the
limited deployment sudo boundary intentionally cannot change them.

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
| AUTHR-001 | Debug-agent shared-token authentication is enabled in production | `IMPLEMENTED` | Critical | Production config cache reports `enabled=false`; production is hard-blocked in middleware; deployment/health gates reject enabled state |
| AUTHR-002 | Protected unauthenticated API denials return HTTP 500 because PHP cannot write Laravel logs | `IMPLEMENTED` | High | Release `6838f8277a79a3cd36996f6f50b33dd559915caa`; `storage/logs` repaired to `www-data` group with setgid 2775; `GET /api/portfolios` returns 401; runtime health is green |
| AUTHR-003 | Production ownership audit is clean | `IMPLEMENTED` | High | `portfolio:audit-admin-investment-ownership --json` returned zero conflicts |
| AUTHR-004 | Role separation, profile aliases, PAT scope/ownership, and representative object checks | `IMPLEMENTED_WITH_LIMITATION` | High | Static tests pass; no second production Investor/PAT fixture |
| AUTHR-005 | Current route inventory is 443 versus prior 437-operation audit | `IMPLEMENTED` | Medium | Fresh master inventory: 443 total / 438 API; audited revision `e5c6fb2`: 442 total / 437 API; exact diff has one protected notification retry addition and five prior API-scope exclusions, with no authorization gap |
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
is substantial. AUTHR-001 and AUTHR-002 are now closed: DebugAgent is
disabled and hard-blocked in production, shared Laravel log permissions are
correct, `GET /api/portfolios` returns 401 rather than 500, and the production
runtime health gate passes. AUTHR-005 is now closed; AUTHR-006 remains open,
so the audit stays partially implemented.

`V5-REQ-018` remains `PARTIALLY_IMPLEMENTED` pending the clean representative
role/foreign-object runtime matrix tracked by AUTHR-006.

## 24. Open Questions

- Which approved dedicated test identities can support a two-Investor and PAT
  runtime denial matrix without touching real financial state?

## 25. Remediation Validation

Repository protections are implemented and validated:

- `DebugAgentTokenTest`: production hard block, disabled state, missing token,
  and query-token rejection.
- Deployment contract tests: explicit production flag, hard DebugAgent gate,
  writable-path checks, and no privileged unit installation.
- Production config cache: `portfolio.debug_agent.enabled=false` with
  `APP_ENV=production` and `LIDO_AGENT_DEBUG_ENABLED=false`.

Production activation completed on release
`6838f8277a79a3cd36996f6f50b33dd559915caa`. Laravel log files now use the
`www-data` group with group-write permissions under a setgid `storage/logs`
directory (`2775`), so future files inherit the shared group. The production
runtime health check passed DebugAgent, public build identity, browser module,
queue coverage, scheduler heartbeat, and writable-path checks. A protected
unauthenticated `GET /api/portfolios` now returns `401 Unauthorized`.
