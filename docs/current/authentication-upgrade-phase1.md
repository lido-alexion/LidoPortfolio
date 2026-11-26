# Authentication upgrade — Phase 1 implementation

Phase 1 adds independent native administrator and investor browser sessions. Google identity linking and sign-in remain Phase 2 and are not enabled.

## Session architecture

- Every browser request selects a context with `X-Stox-Auth-Context: investor|admin`. Missing header means `investor`, preserving the existing native login and session cookie name. Unknown values return 400.
- `SelectAuthSessionContext` runs before cookie decryption and session startup. It selects the normal session cookie for investors and a `-admin` suffixed cookie for admins, derived from `SESSION_COOKIE` or Laravel's existing default. The `web` guard remains the Sanctum session guard; its framework session store and guard are reset at request boundaries so persistent workers cannot retain a prior request's context.
- The remember-me cookie is independently named per context. Login rejects a role/context mismatch and invalidates the attempted session. Authenticated browser requests are checked against the selected context after Sanctum authentication. Personal API tokens remain token-authenticated and retain ability checks.
- `X-Stox-Auth-Context` is sent with API calls and both CSRF bootstrap requests. The plain CSRF token endpoint returns the token for the selected session; CSRF validation is therefore bound to that session's token. The legacy `XSRF-TOKEN` cookie remains for Sanctum compatibility, while the SPA uses its context-local token memory.
- The login screen links to `/login?auth_context=admin`; `sessionStorage` keeps the selection tab-local. The existing route-level admin middleware still enforces permissions server-side.

## Portfolio and UI isolation

- The existing `portfolio_active_id` tab-local key, `X-Profile-Id` header, default portfolio resolution, and server-side `user_id` ownership check are preserved. No portfolio ID is written to auth session data.
- Dashboard cache invalidation and same-user portfolio-deletion broadcasts are scoped to the authenticated user. Logout clears only the current user's dashboard cache. Portfolio bootstrap responses are ignored after the tab's authenticated user changes; API responses from an earlier identity are canceled before reaching page state handlers.
- API role mismatch, a missing selected session, or an expired selected session never falls back to the other context. Existing admin restrictions and portfolio ownership checks remain authoritative.

## Kite callback state

Kite login state is encrypted, short lived, and bound to the initiating StoX user. It also carries a cryptographically random identifier whose hash indexes a shared server-side cache entry. The callback consumes that entry under a cache lock before resolving the user or exchanging the Kite request token. Repeated, expired, tampered, unknown, or unconsumable states fail closed; numeric IDs returned by cache drivers are normalized before owner comparison. Newly issued states require the replay key, so old in-flight pre-replay Kite URLs may need to be restarted after upgrade. Cache lock and shared-value support across application workers are required for multi-worker use.

## Verification evidence

Verified `PDO sqlite::memory:` can create and query an isolated table before test execution. Focused backend command (with a fake test-only app key and local telemetry export disabled):

```bash
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
DB_CONNECTION=sqlite DB_DATABASE=:memory: OTEL_SDK_DISABLED=true \
LIDO_TELEMETRY_OFFICIAL_SDK_ENABLED=false \
php artisan test --filter='AuthSessionContextTest|AuthSessionTest|AuthCsrfLoginTest|KiteCallbackTest|PortfolioMiddlewareTest|OpenApiV1ContractTest|UserInviteTest|PasswordResetLinkTest'
```

Result: 63 tests passed, including independent login, cookies, remember-me cookies, CSRF tokens, logout isolation, wrong-context denial, invite and password-reset binding, foreign portfolio selection, native session management, Kite state replay, and initiating-user callback ownership.

Frontend evidence: `npx vitest run --exclude tests/js/tos/otelBrowserXhr.test.jsx` passed 95 files / 651 tests; `npm run typecheck`, `npm run docs:static`, and `npm run docs:static:check` passed. Targeted Node tests passed for auth-context selection and per-user cache clearing. A Vite production build was attempted with `VITE_APP_BASE=/portfolio/build/` but could not fetch the configured Bunny Fonts CSS because network DNS is unavailable. The full Node unit suite's authenticated-smoke test is blocked because this sandbox rejects child-process spawning (`EPERM`).

The Playwright browser journey matrix and interactive same-browser UI acceptance have not run. The repository's MySQL-required backend CI parity gate was not run; these tests used the verified disposable in-memory SQLite database only. No production database, production environment, real broker account, or live order was used.

## Remaining scope and blockers

- Phase 1 still needs frontend CI-parity build and browser journey gates from the frozen spec (independent investor portfolio tabs, duplicate tabs, refresh/expiry/revocation, and interactive UI acceptance). The build and authenticated smoke test are blocked by network restrictions described above; Playwright journeys have not run.
- Google OAuth/OIDC linking and its test matrix are Phase 2, deliberately out of this implementation.
- Before rollout, production configuration must confirm that its cache driver supports atomic locks and shared values across workers. The owner should validate administrator entry and simultaneous account behavior with safe test accounts; no production credential change or cutover has occurred.

## Independent release-readiness review (2026-11-26)

The review found and corrected three Phase 1 issues:

- `EnsureAuthSessionRole` was only present in middleware priority and was not attached to protected routes. It now runs directly after `auth:sanctum` on authenticated route groups, before portfolio resolution. This covers routes without portfolio middleware and leaves explicit personal API tokens on their existing ability checks.
- The SPA now sends the identity it rendered each request for. The server rejects a browser-session request if another same-role login replaced that context cookie, including writes from tabs whose sessionStorage still names the previous principal. Stale response errors are discarded before 401 handling, CSRF retry, failure reporting, or toast behavior. A background session probe that discovers a different principal expires the old tab instead of adopting that identity into its existing page state.
- Existing investor remember-me cookies keep Laravel's legacy cookie name; admin remember-me cookies use a separate name.

Additional tests cover stale same-role requests, role/context enforcement outside portfolio routes, callback state replay, and a two-tab browser journey that uses disposable API fixtures to check tab-local portfolio selection and request headers. This is a UI journey over deterministic mocked API responses, not an end-to-end Laravel/database run. The browser journey source is present but could not execute in this restricted environment: binding the local Vite test server to `127.0.0.1:4177` fails with `EPERM`. No browser gate was skipped or weakened.

Review verification on this checkout:

```bash
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=:memory: OTEL_SDK_DISABLED=true LIDO_TELEMETRY_OFFICIAL_SDK_ENABLED=false php artisan test --filter='AuthSessionContextTest|AuthSessionTest|AuthCsrfLoginTest|KiteCallbackTest|PortfolioMiddlewareTest|UserInviteTest|PasswordResetLinkTest'
# 57 tests passed

npm run docs:static:check
# passed
npm run typecheck
# passed
npx vitest run --exclude tests/js/tos/otelBrowserXhr.test.jsx
# 96 files passed, 653 tests passed
npx vitest run tests/js/tos/auth-stale-api-response.test.jsx
# 2 tests passed: stale 401/419 responses neither retry nor trigger recovery side effects

VITE_APP_BASE=/portfolio/build/ npm run build
# blocked: fonts.bunny.net DNS lookup returned EAI_AGAIN

npx playwright test tests/e2e/auth-context-multi-tab.spec.js --config tests/e2e/playwright.config.js --project=chromium
# blocked before test execution: local webServer listen returned EPERM
```

Phase 1 is **not release-ready yet**. Required release gates remain the full frontend CI verifier (including production build and Chromium journeys), hosted MySQL backend CI parity, and interactive acceptance with safe test accounts. These environment blocks do not establish a passing gate; no production environment or database was accessed.

## Supervisor compatibility remediation (2026-11-26)

Laravel compatibility review found that inheriting `SessionGuard::getName()` changed the existing investor session key because the subclass name participates in the hash. The custom guard now explicitly retains `login_web_` + `sha1(Illuminate\\Auth\\SessionGuard::class)` for investors; admin sessions retain the contextual guard key and separate admin cookie. The custom guard creator also passes Laravel's configured `hashing.rehash_on_login`, `auth.timebox_duration`, and `app.key`, preserving login rehash policy, timing behavior, and the legacy recaller HMAC. A regression loads a session value under the pre-upgrade framework key and checks the configured-key recaller HMAC and legacy recaller name.

The `SelectAuthSessionContext` global middleware is prepended before cookie decryption and session startup. `EnsureAuthSessionRole` is attached after `auth:sanctum` and before portfolio resolution on protected groups; login independently checks role/context and invalidates a mismatch. Missing context defaults to investor, malformed context returns 400, and an investor/admin mismatch does not switch contexts. CSRF bootstrap and mutating API requests carry the same context header; Sanctum CSRF checks run after context selection against the selected session. These checks preserve existing route authorization, personal-token ability checks, portfolio ownership, and callback state consumption.

The two-page Playwright case uses one browser context (shared cookie jar) with separate page `sessionStorage` and disposable mocked API fixtures. It verifies per-tab portfolio IDs and the context/user/profile request headers. It does **not** exercise Laravel cookie issuance, duplicate-tab cloning, refresh, expiry, revocation, concurrent writes, or real CSRF rotation; those remain covered by backend cases where applicable or require the browser/interactive gate below.

Latest local verification used:

```bash
cd app
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=:memory: OTEL_SDK_DISABLED=true LIDO_TELEMETRY_OFFICIAL_SDK_ENABLED=false php artisan test --filter='AuthSessionContextTest|AuthSessionTest|AuthCsrfLoginTest|KiteCallbackTest|PortfolioMiddlewareTest|UserInviteTest|PasswordResetLinkTest'
npm run typecheck
npx vitest run --exclude tests/js/tos/otelBrowserXhr.test.jsx
npm run docs:static:check
VITE_APP_BASE=/portfolio/build/ npm run build
npx playwright test tests/e2e/auth-context-multi-tab.spec.js --config tests/e2e/playwright.config.js --project=chromium
```

The focused PHP command passed 58 tests / 306 assertions using only SQLite `:memory:` and a fake key. Typecheck and static docs validation passed; Vitest passed 97 files / 655 tests. The build is blocked in this sandbox by DNS failure for `fonts.bunny.net` (`EAI_AGAIN`); Playwright's configured local server cannot bind in this sandbox (`EPERM` before browser execution). Neither is counted as a passing gate.

Required release gates and execution ownership:

```bash
# Hosted CI / supervisor isolated MySQL runner, from repository root:
./scripts/verify-ci.sh --backend

# Hosted CI / supervisor runner with network access and the configured Chromium binary:
./scripts/verify-ci.sh --frontend
```

Run these against the isolated CI test database and a network-enabled build/browser runner, never a production database. Before release, complete interactive acceptance with safe test accounts across two investor tabs and simultaneous investor/admin contexts, covering refresh, expiry/revocation, CSRF refresh, concurrent writes, and session logout. Supervisor owns master reconciliation and PR/CI; this checkout has made no remote or Git metadata changes.
