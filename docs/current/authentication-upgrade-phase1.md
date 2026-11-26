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

## Live backend/browser assurance additions (2026-11-26)

Added an additive live Playwright harness, `npm run test:e2e:auth-live`. It starts Laravel against a newly created SQLite database in a temporary directory, inserts synthetic investor/admin users and paper portfolios, and proxies browser requests to that Laravel process. It explicitly sets `LIDO_ENV_PATH` to a temporary test-only environment file, uses a fake app key, database sessions, array cache, synchronous queue, four PHP development-server workers, and disabled telemetry. Playwright results also stay in that temporary directory. It makes no broker API calls and creates no orders. The browser case uses one Chromium context with two real pages and exercises independent HttpOnly investor/admin cookies, simultaneous portfolio writes sharing a CSRF session, page refresh, same-role stale identity rejection, foreign portfolio denial, role restrictions, and context-specific logout. Existing focused backend tests cover session expiry/revocation, CSRF context separation, and Kite callback state binding/replay.

The new expiry feature regression resets Laravel's retained in-memory session between test requests, ages the disposable database session row, and verifies the cookie is rejected. Focused backend verification passed **37 tests / 144 assertions**, including auth contexts, sessions, CSRF login, Kite callbacks, and portfolio middleware. TypeScript typecheck, JavaScript syntax checks, Playwright test discovery, and `git diff --check` passed. The live browser harness successfully ran `migrate:fresh` against its temporary SQLite file and created fixtures, then stopped because this sandbox denies listening on `127.0.0.1:8001`; the browser test did not run here. The hosted frontend verifier now provisions PHP/SQLite and runs this live test before the existing mocked journeys. Hosted CI must still provide the browser execution evidence.

Isolation note: the first local Laravel feature-test invocations preceded the explicit `LIDO_ENV_PATH` pin in the harness. Process-level variables selected the fake key and SQLite `:memory:` database, and no production database operation or real broker request was made; however, Laravel bootstrap may have resolved an external environment file during those initial invocations. Later feature-test runs used a temporary test-only environment file. No environment values were printed or copied into the repository. This checkout has not fetched, pushed, deployed, or changed Git metadata.

## Phase 1 CI remediation review (2026-11-26)

Hosted PR #420 run **45827129762** at `fc53489` reported 31 failures among 296 backend tests, mostly 403 responses from administrator fixtures, including `DebugAgentTokenTest::test_debug_token_grants_admin_access_to_protected_api`; frontend CI reported the four desktop/mobile login screenshots changed after adding the context selector.

The 403 causes are separated by authentication type. A real browser session must match `X-Stox-Auth-Context`; test `actingAs` requests and session-login fixtures now select the fixture user's intentional role context. The explicit role-mismatch contract still sends the conflicting header and remains covered. A debug-agent header is a legacy synthetic API credential, not a browser cookie: it now sets only the Sanctum request identity, is marked as synthetic for context middleware, and does not create or own a web session. Personal bearer tokens continue through their existing scope middleware. Addressing a named portfolio as an admin retains the API's ownership-hiding 404; other investor routes remain forbidden.

Current focused local verification, always with `LIDO_ENV_PATH=/tmp/stox418-phase1-test.env`, a fake key, and SQLite `:memory:`:

```bash
cd app
./vendor/bin/phpunit \
  tests/Feature/DebugAgentTokenTest.php \
  tests/Feature/AuthSessionContextTest.php \
  tests/Feature/AuthSessionTest.php \
  tests/Feature/AuthCsrfLoginTest.php \
  tests/Feature/V6PersonalApiTokenTest.php \
  tests/Feature/Execution/KiteCallbackTest.php \
  tests/Feature/PortfolioMiddlewareTest.php \
  tests/Feature/AdminGlobalSettingsTest.php \
  tests/Feature/UserManagementTest.php \
  tests/Feature/RoleSeparatedApplicationTest.php \
  tests/Feature/V8/AccessRequestWorkflowTest.php
# 92 tests passed / 437 assertions
```

PHP syntax checks passed for the debug middleware and live fixture. `npm run typecheck`, JavaScript syntax validation, and shell syntax validation passed. The live harness completed isolated SQLite migrations and fixture creation, then its PHP server could not bind `127.0.0.1:8001`; the mocked login screenshot suite's Vite server also could not bind `127.0.0.1:4177` (`listen EPERM`). Neither browser test executed locally. No screenshot files were regenerated without visual evidence.

The live browser test now covers two real cookie contexts, tab refresh, concurrent writes with one context-local CSRF token, foreign portfolio denial, stale same-role identity denial, context-specific logout, remote session revocation from a separate login, and server-side expiry by aging only a session row in the temporary SQLite database. All live login fixtures disable remember-me so expiry cannot restore a session through a recaller cookie. The PHP server runs in a dedicated process group; cleanup stops the whole group and removes its temporary database, environment file, and Playwright artifacts.

### Hosted verification still required

On the network-enabled GitHub Ubuntu runner, with Node 22, PHP 8.4 plus `pdo_sqlite`, and the existing Chromium install, run the canonical `./scripts/verify-ci.sh --frontend` with `LIDO_ENV_PATH` pinned to a disposable test file. The verifier runs `npm ci`, Vitest, typecheck, the `/portfolio/build/` production build, Chromium setup, the live auth test, and the mocked journey suite. Bunny Fonts CSS must resolve during build. First run `npx playwright test tests/e2e/auth-entry.spec.js --config tests/e2e/playwright.config.js --project=chromium --update-snapshots`; review all four generated login snapshots against the existing baselines, checking the investor/admin context label and reciprocal entry link at desktop and mobile sizes and ensuring form controls are not clipped. Then rerun that test without `--update-snapshots`, followed by the live auth test and complete journey suite. Commit only those four reviewed snapshot files if the 70px card movement is visually intentional. Do not use snapshot update mode for the complete journey suite.

Run `./scripts/verify-ci.sh --backend` on the isolated MySQL CI service to validate the complete backend inventory and migration gate. The 296-test hosted inventory was not run to completion locally, and focused green tests do not substitute for that gate. No production config, data, runtime, provider, or trade was accessed. Git metadata was inspected read-only and not modified.

The remediation review additionally corrected the live fixture to test a portfolio owned by investor A while authenticated as investor B (the prior fixture selected B's own portfolio). Its browser flow now operates the real portfolio switcher in two investor tabs, checks tab-local selection across refresh and duplicate-tab creation, attempts writes with missing and wrong-context CSRF, tests concurrent writes and stale identity, and retains Playwright results under `app/tests/e2e/test-results/auth-live/` so the workflow's failure artifact upload survives harness cleanup. The admin CSRF bootstrap now sends `X-Stox-Auth-Context: admin`. Both destructive migration and fixture creation fail closed unless `LIDO_ENV_PATH` and SQLite database resolve inside the harness's unique temporary directory; all verifier PHP/Composer calls receive an explicitly pinned disposable `LIDO_ENV_PATH`.

On this sandbox, the final focused backend run passed **92 tests / 437 assertions**. Shell/PHP/JavaScript syntax checks and `git diff --check` passed. The live auth harness successfully migrated its isolated SQLite database and created fixtures, then could not bind its local PHP server (`127.0.0.1:8001`); the browser assertions did not execute and no screenshot baseline was modified. This is an environment restriction, not a passing browser gate.

**Hosted steps:** run `./scripts/verify-ci.sh --frontend` and `./scripts/verify-ci.sh --backend` as required CI jobs. The frontend job uploads `app/tests/e2e/test-results/` on failure (including the live auth trace). Inspect the uploaded trace and any actual visual diffs; do not update snapshots until all four auth-entry desktop/mobile images are reviewed, then run the auth-entry test once in update mode and once without it. Keep hosted Chromium, full journeys, MySQL backend suite, migration portability, and full auth browser execution as open gates until those exact runs pass.

Read-only branch audit at review time found local `HEAD` (`fc53489`) and `origin/master` (`b898f17`) have diverged from merge base `7df06f7`; the current master delta removes several authentication-upgrade files present in this feature checkout. Supervisor integration must reconcile that delta before treating this candidate as current-master ready. This review did not fetch or change refs, index, or other Git metadata.
