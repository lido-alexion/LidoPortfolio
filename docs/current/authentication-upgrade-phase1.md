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

The live browser test now covers two real cookie contexts, tab refresh, concurrent writes with one context-local CSRF token, foreign portfolio denial, stale same-role identity denial, investor and admin logout isolation in both directions, remote session revocation from a separate login, and server-side expiry by aging only a session row in the temporary SQLite database. All live login fixtures disable remember-me so expiry cannot restore a session through a recaller cookie. The PHP server runs in a dedicated process group; cleanup stops the whole group and removes its temporary database and environment file. Playwright output stays in the repository's ignored test-results directory so CI can upload failure evidence.

### Hosted verification still required

On the network-enabled GitHub Ubuntu runner, with Node 22, PHP 8.4 plus `pdo_sqlite`, and the existing Chromium install, run the canonical `./scripts/verify-ci.sh --frontend` with `LIDO_ENV_PATH` pinned to a disposable test file. The verifier runs `npm ci`, Vitest, typecheck, the `/portfolio/build/` production build, Chromium setup, the live auth test, and the mocked journey suite. Bunny Fonts CSS must resolve during build. First run `npx playwright test tests/e2e/auth-entry.spec.js --config tests/e2e/playwright.config.js --project=chromium --update-snapshots`; review all four generated login snapshots against the existing baselines, checking the investor/admin context label and reciprocal entry link at desktop and mobile sizes and ensuring form controls are not clipped. Then rerun that test without `--update-snapshots`, followed by the live auth test and complete journey suite. Commit only those four reviewed snapshot files if the 70px card movement is visually intentional. Do not use snapshot update mode for the complete journey suite.

Run `./scripts/verify-ci.sh --backend` on the isolated MySQL CI service to validate the complete backend inventory and migration gate. The 296-test hosted inventory was not run to completion locally, and focused green tests do not substitute for that gate. No production config, data, runtime, provider, or trade was accessed. Git metadata was inspected read-only and not modified.

The remediation review additionally corrected the live fixture to test a portfolio owned by investor A while authenticated as investor B (the prior fixture selected B's own portfolio). Its browser flow now operates the real portfolio switcher in two investor tabs, checks tab-local selection across refresh and duplicate-tab creation, attempts writes with missing and wrong-context CSRF, tests concurrent writes and stale identity, and retains Playwright results under `app/tests/e2e/test-results/auth-live/` so the workflow's failure artifact upload survives harness cleanup. The admin CSRF bootstrap now sends `X-Stox-Auth-Context: admin`. Both destructive migration and fixture creation fail closed unless `LIDO_ENV_PATH` and SQLite database resolve inside the harness's unique temporary directory; all verifier PHP/Composer calls receive an explicitly pinned disposable `LIDO_ENV_PATH`.

On this sandbox, the final focused backend run passed **92 tests / 437 assertions**. Shell/PHP/JavaScript syntax checks and `git diff --check` passed. The live auth harness successfully migrated its isolated SQLite database and created fixtures, then could not bind its local PHP server (`127.0.0.1:8001`); the browser assertions did not execute and no screenshot baseline was modified. This is an environment restriction, not a passing browser gate.

**Hosted steps:** run `./scripts/verify-ci.sh --frontend` and `./scripts/verify-ci.sh --backend` as required CI jobs. The frontend job uploads `app/tests/e2e/test-results/` on failure (including the live auth trace). Inspect the uploaded trace and any actual visual diffs; do not update snapshots until all four auth-entry desktop/mobile images are reviewed, then run the auth-entry test once in update mode and once without it. Keep hosted Chromium, full journeys, MySQL backend suite, migration portability, and full auth browser execution as open gates until those exact runs pass.

## Browser readiness probe remediation (2026-11-26)

Hosted frontend run **45857218391** repeatedly failed with `Session store not set on request` during harness startup. The probe called the session-backed `GET /api/auth/csrf-token` directly on Laravel port 8001 without a browser `Origin` or `Referer`. Sanctum therefore treated the API request as stateless, skipped the stateful SPA session middleware, and the endpoint then correctly failed when it read the session. This was a harness health-check defect, not a reason to exempt the endpoint from CSRF or authentication middleware.

`app/scripts/run-auth-live-e2e.sh` now probes that endpoint with the configured Vite browser origin/referrer (`http://127.0.0.1:4178`) and explicit investor context, then validates that the JSON contains a non-empty token before considering Laravel ready. This exercises the same Sanctum stateful-origin decision and session access used by Playwright. Failed startup runs copy the Laravel server log into `app/tests/e2e/test-results/auth-live/harness-laravel.log`; Playwright's retained failure trace and the CI artifact upload remain enabled. The disposable environment and SQLite database are still removed by harness cleanup.

Local checks: `bash -n app/scripts/run-auth-live-e2e.sh` and `git diff --check` passed. Focused disposable SQLite backend suites (`AuthSessionContextTest`, `AuthSessionTest`, `AuthCsrfLoginTest`, `PortfolioMiddlewareTest`) passed **28 tests / 109 assertions**. The live harness migrated its isolated SQLite database and created fixtures, but the sandbox rejected binding the PHP server to `127.0.0.1:8001`; therefore the corrected readiness request and Playwright browser assertions could not run locally. The retained startup log records the bind failure. No login baseline was regenerated. The checked-in desktop and mobile login baselines omit the new active-context label and reciprocal administrator/investor link; this explains the expected layout movement, but actual Chromium captures still need visual review before any snapshot update.

### Hosted run 45866570007 follow-up

The backend failure `V8 GuidedTourTest::test_admin_cannot_use_investor_guided_tour_api` (login helper expected 200, received 403) came from that test's session-login helper omitting `X-Stox-Auth-Context`. The request correctly defaulted to investor and the role/context guard rejected the admin login. The helper now selects `admin` or `investor` from the fixture role before login. The test then continues to assert that admin GET and PUT requests to `/api/guided-tour` are forbidden, preserving the route's investor-only authorization.

The readiness probe now requires both a non-empty CSRF token and the issued `stox-auth-e2e-session` cookie, accepting the Netscape cookie-jar `#HttpOnly_` prefix. This confirms startup traversed Sanctum's browser-origin stateful middleware and established a Laravel session. Focused SQLite verification passed **5 GuidedTourTest cases / 29 assertions** with a temporary pinned test environment and fake key. PHP, shell, and JavaScript syntax checks and `git diff --check` passed. A separate unpinned diagnostic invocation was discarded because it attempted the ambient database and telemetry configuration; it is not part of verification evidence.

The checkout cannot inspect hosted artifacts or execute hosted MySQL/Chromium in this sandbox. The latest frontend run's remaining status is therefore unknown; the old bare-probe failure is addressed in source but requires a fresh hosted run. Visual snapshot review, full MySQL backend parity, actual live-browser cookie/session journey, and safe-account interactive acceptance remain open. No visual baseline was fabricated or changed.

## Browser CSRF enforcement remediation (2026-11-27)

Hosted frontend run **45909985671** passed backend and 656 frontend unit tests but exposed a real browser CSRF bypass: Chromium's same-origin `Sec-Fetch-Site` header caused Laravel's `PreventRequestForgery` middleware to accept an authenticated `PUT` without a session token. This was middleware behavior, not a fixture defect. Sanctum's stateful-origin detection and context-selected session cookies were functioning; Laravel's newer Fetch Metadata shortcut was treating same-origin metadata as sufficient CSRF authorization.

`RequireSessionCsrfToken` now replaces Sanctum's configured CSRF validator and disables only that origin shortcut. Mutating browser requests must present a token matching the selected session. The live browser test now checks that a tokenless write and an investor-token/admin-context write leave the portfolio name unchanged, then proves a valid investor token can update it. A focused unit regression checks same-origin metadata, missing/mismatched tokens, and a valid session token. Existing auth-context, native remember-session, debug-agent, and personal API token tests continue to pass; non-stateful bearer/debug API requests retain their existing middleware contract.

Local evidence: **33 focused feature tests / 126 assertions passed**, plus the new CSRF middleware unit test (**4 assertions passed**); PHP/JavaScript syntax checks and `git diff --check` passed. `npm run test:e2e:auth-live` created and migrated only its disposable SQLite fixture, then could not bind `127.0.0.1:8001` in this sandbox, so the browser assertions did not run locally. Required closure evidence remains a fresh hosted frontend run including the live Chromium journey, full MySQL backend parity, visual review of the four auth-entry snapshots without unreviewed regeneration, and safe-account interactive acceptance. The supervisor owns branch integration and hosted CI; no Git metadata, production resource, or screenshot baseline was changed here.

Read-only integration inspection found `HEAD=823bd4b9` and the locally cached `origin/master=b898f176`; no fetch or Git metadata update was performed. The feature checkout contains the current cached master base and the #422 merge commit `31eba9b8`, while #418 work remains ahead of cached master. No hosted run after this harness correction has been observed. Required remaining gates are a fresh hosted frontend run (including the live browser journey and screenshot review), full MySQL backend parity, visual acceptance of the four auth-entry snapshots, and safe-account interactive acceptance. Google/OIDC criteria remain Phase 2 as defined by the frozen spec.

An earlier branch audit at `fc53489` found divergence from `origin/master` at merge base `7df06f7`. That audit predates the merge represented by current `HEAD=823bd4b9` and is superseded by the read-only graph check above: cached `origin/master=b898f176` is now an ancestor of this checkout. The #418 implementation is still ahead of `origin/master` and has not been integrated into master. No refs, index, or other Git metadata were changed during this review.

## Acceptance coverage additions (2026-11-27)

The live browser journey now includes same-user portfolio deletion recovery: one tab deletes the secondary portfolio while another tab has it selected, and the second tab must recover to the default portfolio. Existing cache and BroadcastChannel unit tests cover cache clearing for one user and delivery only to listeners for that user. The live journey also exercises remember-me restoration after expiring the server-side session, then rotates only the disposable investor's remember token and verifies that the old recaller no longer authenticates while another investor's session remains valid. Its fixture refuses token rotation for identities outside the disposable E2E email domain.

`V6PersonalApiTokenTest` now proves that a fully scoped bearer client can write without a browser session token; the strict CSRF validator remains the configured Sanctum validator for stateful browser requests. This complements the live browser checks for missing-token rejection, a token from the other context, and a valid session-token write.

Local evidence for these additions: the CSRF middleware unit test and personal API token feature suite passed **7 tests / 27 assertions** with an explicit disposable `LIDO_ENV_PATH`, fake app key, and SQLite `:memory:`. The cache-key test and portfolio-event user-filter test passed, and PHP/JavaScript syntax plus Playwright discovery passed. The updated live journey did not execute in this sandbox: its isolated SQLite migration and synthetic fixtures completed, but the PHP server could not bind `127.0.0.1:8001` (`Failed to listen`). Hosted Chromium execution, the active hosted CI result, full MySQL backend parity, interactive safe-account acceptance, and review of the four login snapshots remain open. No visual baseline was changed.

## Same-context switch and live-fixture review (2026-11-27)

The reported hosted journey failure is an authorization boundary signal: `EnsureAuthSessionRole` returns 401 when the request's `X-Stox-Auth-User` does not match the principal in the selected cookie. That is the required fail-closed response for an old tab after another tab replaces the shared investor cookie. The cross-account portfolio contract still requires 404 once `/api/auth/me` confirms the cookie belongs to investor B. The browser journey now checks B's identity immediately after login and before probing investor A's portfolio, so a rerun distinguishes stale-cookie/session state (401 at the identity check) from ownership behavior (404 at the foreign-profile lookup). No authorization status expectation was weakened.

Review also found that the new deletion recovery step searched for `Investor A Secondary` after the concurrent-write gate had renamed that same portfolio to `E2E Concurrent Beta`. The selector and post-delete assertion now use the renamed fixture, allowing the test to reach the intended same-user deletion recovery check. The disposable live harness accepts `STOX_AUTH_E2E_PORT` and forwards it to Vite, so a port conflict can be avoided without touching an existing listener.

Focused local evidence: `AuthSessionContextTest` passed **9 tests / 40 assertions** using the repository's in-memory SQLite PHPUnit configuration, fake app key, and disabled telemetry export. The live harness still cannot bind a loopback PHP server in this sandbox, even with an alternate port, so it did not execute Chromium, the new switch identity assertion, deletion recovery, remember-me restoration/revocation, or the bearer write scenario. The checkout cannot inspect the hosted trace or run hosted MySQL. Current hosted result supplied by the owner remains PHP PASS and frontend live browser 401 where 404 was expected; that result is not considered closed pending the clarified rerun. Four login snapshots remain unchanged and require human visual review. Outstanding gates: fresh hosted frontend/live Chromium trace, full MySQL backend parity, review of the four auth-entry screenshots, and safe-account interactive acceptance. No production system, secrets, Git metadata, dependency installation, screenshot baseline, trade, or deployment was touched.

## Hosted live-browser timeout review (2026-11-27)

Inspected the supplied host log and retained Playwright trace for the timed-out journey. Migrations, fixture creation, initial investor/admin sessions, portfolio tab switching, duplicate tab, strict missing/wrong-context CSRF rejection, valid CSRF write, and the subsequent browser startup all completed. The revocation login then received HTTP 204 from `/sanctum/csrf-cookie` but its following `/api/auth/csrf-token` request remained pending until the 30-second test timeout. The trace also shows an unrelated UI request to `/api/v1/protections` remaining pending while pages are open. This is consistent with the four-worker PHP development server being starved by overlapping background browser requests; the trace does not prove that as the sole cause.

The isolated live harness now defaults to eight PHP workers (configurable from 4 to 16 with `STOX_AUTH_E2E_PHP_WORKERS`). Every browser fetch in the login/CSRF helpers aborts after 10 seconds so a stall is attributed to the request rather than consuming the whole test timeout. The end-to-end journey has a 120-second maximum to accommodate its real UI and session flows. These are harness timing/capacity changes only; auth status expectations, CSRF validation, role checks, stale-header rejection, ownership hiding, and portfolio-tab behavior are unchanged.

The earlier hosted 401 at the foreign portfolio probe is now disambiguated by checking `/api/auth/me` immediately after investor B's login and before requesting investor A's portfolio. A 401 at that identity checkpoint means the active cookie/header still represents stale identity; only after confirming B does the test require the foreign portfolio lookup to return 404. The assertion does not accept 401 as an ownership result.

Local rerun attempted with `STOX_AUTH_E2E_PORT=18081 npm run test:e2e:auth-live`: the dedicated SQLite database migrated and synthetic fixtures were created, but this environment again denied the PHP loopback bind, so Chromium could not start. `bash -n` and `node --check` passed. The host rerun command is `STOX_AUTH_E2E_PHP_WORKERS=8 npm run test:e2e:auth-live`; keep the existing bounded Playwright test and do not use snapshot-update mode for the journey suite. The four current auth-entry screenshots were visually inspected: both desktop/mobile baselines show the form and error state without the current `Investor sign-in` label and `Administrator sign-in` link, so they are stale relative to `LoginPage.jsx`. A fresh hosted auth-entry snapshot run and review of all four changed images remains required; no screenshot baseline was edited here. Full MySQL parity and safe-account interactive acceptance also remain open. No production access, secrets, real data, trade, dependency install, Git metadata operation, other checkout, push, merge, or deploy was used.

## 1246 CSRF failure and current verification (2026-11-27)

Preserved `auth-live` Playwright trace and harness access log show the 419 at the second independent investor login (`auth-context-live.spec.js:148`) occurs on `POST /api/auth/login`, after both CSRF bootstrap requests returned successfully. The POST includes the fetched plain token, but arrives with a different guest session cookie than the session that supplied that token. This is a fixture race: the revocation-only browser page loaded the SPA, whose own background CSRF bootstrap requests could overlap the fixture's explicit bootstrap and rotate the shared guest session cookie. Laravel's strict CSRF rejection is correct; this is not a server-side relaxation or role mismatch.

The live journey now opens revocation, expiry, and remember-me API-only browser contexts on a minimal same-origin document. It still uses real browser cookie jars and the actual Sanctum CSRF-cookie, plain-token, login, and mutation endpoints. The primary investor/admin pages continue to load the real SPA and retain all existing UI, context isolation, portfolio, expiry/revocation, remember-me, and CSRF assertions. The fixture change does not skip or retry a failed login. The previous host trace, error context, and access log remain in `app/tests/e2e/test-results/auth-live/` until a host rerun replaces them.

Current local checks after this correction: `V6PersonalApiTokenTest` passed **6 tests / 23 assertions** using a fake key and SQLite `:memory:`; the portfolio deletion recovery Vitest passed **1 test**; `node --check`, `php -l`, `bash -n`, and `git diff --check 510a00bf` passed. The live browser journey was not rerun because this sandbox denies loopback listeners. I visually reviewed the four checked-in auth-entry baselines: desktop/mobile login and error states are legible and unclipped, but omit the approved administrator entry selector/link currently implemented in the login screen. The run-1246 expected/actual/diff screenshot sets themselves are not present in this checkout, so their rendered changes and errors cannot be assessed here. No auth-entry baseline was changed; review each host-produced actual/expected/diff set before making any justified baseline update.

Supervisor host acceptance commands from the isolated checkout are:

```bash
STOX_AUTH_E2E_PHP_WORKERS=8 npm run test:e2e:auth-live
npx playwright test tests/e2e/auth-entry.spec.js --config tests/e2e/playwright.config.js --project=chromium
./scripts/verify-ci.sh --frontend
./scripts/verify-ci.sh --backend
```

Retain and inspect the new live trace/access log on failure. Visually compare all four desktop/mobile login actual/expected/diff sets for the approved administrator entry, layout, readable controls, clipping, and errors before considering only evidence-backed baseline updates. Complete interactive safe-account acceptance for separate administrator/investor sessions, tab-local portfolio ownership, refresh, expiry/revocation, remember-me, and concurrent writes. Phase 1 remains **not release-ready** until hosted browser/frontend, MySQL backend parity, screenshot review, and interactive acceptance pass.

## Supervisor host evidence — 2026-11-27 14:44 IST

The real Chromium live journey completed successfully (1 passed, 23.4s) on the isolated host with disposable SQLite, clean environment and synthetic fixtures. This covers the revocation/expiry/remember-me API-only contexts plus the real SPA main investor/admin journey and strict CSRF/portfolio assertions. Reviewed expected/actual desktop and mobile login and error images: only the approved investor context label and administrator sign-in link plus their vertical spacing changed; fields, controls, error text and links remain visible and unclipped. Archived the four original comparisons, updated exactly those four snapshot files, reran the auth-entry browser suite without snapshot-update mode (4 passed, 15.7s). Hosted full frontend and MySQL CI on the new head and safe interactive acceptance remain mandatory; do not merge or deploy until verified.
