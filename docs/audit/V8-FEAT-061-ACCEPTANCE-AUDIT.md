# V8 FEAT-061 Guided Tour Acceptance Audit

Date: 2026-09-28
Status: **REVIEW — implementation evidence strong; broader browser/accessibility validation pending**

Authoritative contract: `docs/archive/specs/V8-Guided-Tour-Welcome-Onboarding-Specification.md`.

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Investor-only eligibility and Admin exclusion | PASS | `GuidedTourService::isEligible`, protected API, `GuidedTourTest::test_admin_cannot_use_investor_guided_tour_api`, frontend shell guard. |
| Early welcome prompt and configurable cap | PASS | Persisted prompt count/max config, Begin/Skip/Don't show again, backend tests. |
| Manual relaunch after completion/dismissal | PASS | Profile launch hook and `can_manual_relaunch`; Profile target exists. |
| Configuration-driven core multi-route journey | PASS locally | `investorTourSteps.js`, route-aware provider, stable `data-tour` hooks, and a mobile Playwright journey traversing all 8 configured steps to Finish. |
| Backend persistence and own-user authorization | PASS | `portfolio_user_onboarding_state`, GuidedTour controller/service, API authorization tests. |
| Resume and explicit restart | PASS | `current_step_id`, `tour_in_progress`, resume modal, interrupted-tour test. |
| Completion only on explicit Finish | PASS | Backend `complete` action is separate; close/skip do not set completion. |
| Missing targets bounded wait and safe continuation | PASS | `waitForTarget`; provider now advances through every missing non-final step; final valid step still requires explicit Finish. |
| Explanatory-only underlying interaction | PASS locally | Chromium journey clicks the highlighted navigation target while the tour is active and verifies the route does not change and the step remains visible; the panel remains the only tour control surface. |
| Responsive positioning/scrolling | PASS locally / broader acceptance pending | `guidedTourPosition.js` deterministically flips preferred placement and clamps the fixed panel inside the viewport, with fixed narrow-viewport unit coverage; focused Chromium acceptance passes **11/11** across desktop, tablet and 390×844 mobile. Broader device coverage remains. |
| Keyboard/accessibility/focus return | PASS locally / assistive-technology acceptance pending | Welcome, resume and step dialogs provide labelled descriptions, explicit live step announcements, initial focus, Escape handling, Tab containment, background scroll lock and manual-launch focus return; Chromium journeys and full WCAG 2A/2AA axe scans cover the implemented behavior. Native screen-reader audit remains. |
| Normal i18n mechanism | PASS locally | `resources/js/src/i18n/index.js` and `messages.js` provide keyed translation lookup with locale fallback; all tour step, modal, overlay and Profile copy references translation keys rather than embedded literals. English is the current shipped dictionary; additional locales remain additive. |
| Telemetry non-blocking and existing path | PASS | `guidedTourTelemetry.js` posts to existing frontend log endpoint and swallows failures. |
| Refresh/interruption recovery | PASS locally | Backend state is authoritative; browser journeys prove both persisted-step resume/navigation and recovery after `page.reload()` while the tour is in progress. A real production session interruption remains external. |

## Verification executed

- Laravel Guided Tour tests passed in the prior V8 suite.
- JS suite: node tests **193/193** and Vitest **101/101** after the latest frontend regression run.
- Vite build, typecheck, static docs check: passed.
- Guided-tour copy/key coverage: **3/3** focused Node tests passed.
- Playwright journeys pass for the investor welcome modal → Begin → first guided-tour step, persisted-step resume, in-progress `page.reload()` recovery, scrim interception of highlighted navigation, and all 8 configured route steps on desktop Chromium/1024×768 tablet/390×844 mobile. Fixed viewport-positioning tests cover placement flips/clamping; welcome/resume/step focus containment, labelled descriptions and Escape behavior are covered, and the journeys exposed and fixed a real modal-backdrop stacking defect. Screen-reader tooling, production session interruption, and broader device acceptance remain pending.

FEAT-061 remains **REVIEW**. Keyed i18n plus viewport-safe desktop/mobile journeys, persisted-step resume, in-progress refresh recovery, full configured-route traversal, manual-launch focus return and Tab containment are verified locally. Remaining evidence is screen-reader review, production session interruption, and broader live browser acceptance.


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Deployed eight-step Investor journey, persistence across logout/expiry/refresh, missing targets, Admin exclusion | NOT YET RUN | Needs authenticated controlled Investor/Admin accounts on production; record browser, viewport and route/step evidence. |
| Keyboard, real screen reader and another real device/browser | NOT YET RUN | Local Playwright/axe evidence remains implementation evidence. |

### Authenticated tour resume slice — 2026-10-01 about 19:06 UTC

Cloud Chrome Profile exposed Launch tour and Restart from beginning. Launch resumed the persisted Investor tour at step 7/8 (`Help & documentation`) on Dashboard with labelled dialog and Back/Next/Close controls. Refresh closed that manually launched overlay; this account did not show an automatic welcome prompt after reload. Source inspection shows the local refresh test expects a welcome prompt when `show_welcome_prompt=true`, whereas this user's prior prompt history may suppress it. **PASS for persisted manual resume at step 7 only**; full eight-step deployed journey, fresh-user refresh/expiry, Admin exclusion, keyboard/screen-reader and another device remain NOT YET RUN.

### 2026-10-02 deployed continuation

In the signed-in production Investor session, Profile → Launch tour resumed at step 7/8, Help & documentation. Next displayed step 8/8, Profile & tour, with an explicit Finish control; Back returned to step 7 and the Profile route. Escape closed the overlay without pressing Finish. This is a **PASS** for the bounded deployed resume, forward/back navigation and Escape slice, not evidence for first-run, all eight steps, completion persistence, session expiry, Admin exclusion, screen-reader behavior or another device/browser.

The cloud browser automatic approval review rejected Restart from beginning because it would reset this account's saved tour progress. No reset was performed. **FEAT-061 remains REVIEW** until the remaining production and assistive-technology checks are completed.

## Temporary Developer options — FEAT-061 testing

**TEMPORARY TEST/DEVELOPMENT INFRASTRUCTURE.** Disable/remove this facility when developer testing is no longer needed and before final public hardening. Additional temporary developer actions may be placed in this modal while it exists. This extension does not change the frozen Investor tour or manual Help/Profile relaunch contract; FEAT-061 remains REVIEW.

In the browser console, enable with:

```js
localStorage.setItem('devOptions', 'true'); location.reload();
```

Disable with:

```js
localStorage.removeItem('devOptions'); location.reload();
```

Only the exact string `"true"` at the exact key `devOptions` renders the transparent 12×12 pixel, fixed top-left hotspot. Other values or an absent key render no entry point. Hover uses the pointer cursor; the accessible label is **Open developer options**. Click to open **Developer options**, then **Reset guided tour**. The action disables while pending, reports success or an inline failure, and asks the tester to reload after success. Close any active tour before resetting so it cannot save additional progress afterward.

`devOptions` is a UI discoverability gate only. It does not grant server privileges and is never checked for server authorization. Removing the key hides the UI; removing/disabling the server route is necessary to retire the API itself.

`POST /api/developer-options/guided-tour/reset` requires normal Sanctum authentication (including normal session CSRF handling), takes no target user/account ID, and uses only `request->user()`. All body/query fields, including any target identity, are rejected with HTTP 422 before mutation. The dedicated DeveloperOptions controller calls `GuidedTourService::resetFor`, which atomically deletes only that user's `stox_user_onboarding_state` row and recreates it through the normal service defaults. No migration is needed. It clears welcome prompt count, permanent dismissal, completion, current step and tour-in-progress, and restores the configured tour version. Response: HTTP 200 with `{ data: <default guided-tour payload> }`, identical to the next normal state read. Eligible Investors see the welcome prompt after reload; unauthenticated requests receive 401 and Admin requests receive 403 without mutating state. There are no elevated/admin actions.

Removal points: the developer-options route group/controller, `GuidedTourService::resetFor`, the shell's `DeveloperOptions` component/import, its CSS, and focused tests. Existing guided-tour APIs and manual relaunch remain unchanged.

Focused evidence: `tests/Feature/V8/DeveloperOptionsTest.php` covers authentication, fresh/repeated reset, clearing every state field, rejected body/query target identities, other-user isolation and Admin rejection. `tests/js/developerOptions.test.jsx` covers exact-value rendering, opening/closing, removal, unavailable storage, loading/success, and inline API/network errors. Local verification results are recorded below. No production data was changed and no deployment was performed.

### Local developer-options verification — 2026-10-02

- Focused backend: `php vendor/bin/phpunit --filter 'DeveloperOptionsTest|GuidedTourTest'` — **11 passed, 108 assertions**, both with in-memory SQLite and an isolated local database via `DB_CONNECTION=mysql`. Existing local PHP SQLite extensions were loaded through `PHP_INI_SCAN_DIR`; no sudo was used.
- Shared backend verifier: `./scripts/verify-ci.sh --backend`, using existing local PHP extensions and isolated MariaDB 10.11 via the MySQL driver — **2,066 tests total: 2,065 passed, 1 skipped, 13,197 assertions**; Python checks **7 passed, 8 skipped**; platform requirements, portability checks for **161 existing migrations**, fresh migration/seeding, and OpenAPI check all passed. No migration was added.
- Temporary Docker MySQL 8.4: `migrate:fresh` plus seeding completed successfully. The duplicate full PHPUnit run was intentionally stopped; **no local full MySQL 8.4 suite pass is claimed**. GitHub CI on master remains authoritative for exact MySQL 8.4 verification after a future push. The temporary `stox-feat061-verifier-mysql` container was removed during finalization.
- Focused frontend: `npx vitest run tests/js/developerOptions.test.jsx` — **15 passed**. The explicit Vitest include keeps this test in the normal regression suite.
- `npm run test:js` — **199 Node tests passed; 128 Vitest tests passed across 32 files**.
- `npm run typecheck` and `VITE_APP_BASE=/portfolio/build/ npm run build` — **PASS**. Build emitted a non-blocking bundle-size warning.
- Static documentation was regenerated by the build; `npm run docs:static:check` — **PASS, 53 topics**. Generated timestamp-only changes were excluded from this feature.
- `php artisan openapi:v1` and `php artisan openapi:v1 --check` — **PASS, 219 operations**; the canonical `/api/v1` document is unchanged because this temporary API uses `/api/developer-options`.
- `npx playwright install chromium chromium-headless-shell`, executable existence verification, and `npm run test:e2e:journeys` — **43 passed, 50 skipped, no failures**. The existing viewport-specific skips were preserved. The frontend wrapper's OS-dependency installation requires sudo, so application checks and browser installation ran directly using the existing system libraries.

### Post-reconciliation verification — 2026-10-02

Reconciled `feat061-dev-options` by fast-forwarding to `origin/master` at `7e0e34ebfc6332b5b8c64ae4ecd1f686beffcfb4`, then restoring all 11 feature files without conflicts. At the Product Owner's explicit direction, the previously passed broad checks above were retained as evidence and were not rerun.

- Focused backend `DeveloperOptionsTest|GuidedTourTest`, using in-memory SQLite with existing local PHP extensions — **11 passed, 108 assertions**.
- `npx vitest run tests/js/developerOptions.test.jsx` — **15 passed**.
- `npm run typecheck` — **PASS**.
- Working-tree and staged `git diff --check` — **PASS**.

Finalization is a local commit only; no push or deployment is authorized. Prompt files and generated timestamp-only documentation are excluded.

These local checks do not close the outstanding production/assistive-technology acceptance items above. **FEAT-061 remains REVIEW.**
