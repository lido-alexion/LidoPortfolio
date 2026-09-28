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
| Responsive positioning/scrolling | PARTIAL | Focused Chromium acceptance now passes **11/11**, including desktop, 1024×768 tablet, 390×844 mobile, full configured-route traversal, scrim behavior and the legacy investor welcome smoke; broader device coverage remains. |
| Keyboard/accessibility/focus return | PARTIAL | Welcome, resume and step dialogs now provide labelled descriptions, explicit live step announcements, initial focus, Escape handling, Tab containment, background scroll lock and manual-launch focus return; the 9/9 desktop/mobile/tablet Chromium journey covers these behaviors. Screen-reader audit and broader device traversal remain. |
| Normal i18n mechanism | PASS locally | `resources/js/src/i18n/index.js` and `messages.js` provide keyed translation lookup with locale fallback; all tour step, modal, overlay and Profile copy references translation keys rather than embedded literals. English is the current shipped dictionary; additional locales remain additive. |
| Telemetry non-blocking and existing path | PASS | `guidedTourTelemetry.js` posts to existing frontend log endpoint and swallows failures. |
| Refresh/interruption recovery | PASS locally | Backend state is authoritative; browser journeys prove both persisted-step resume/navigation and recovery after `page.reload()` while the tour is in progress. A real production session interruption remains external. |

## Verification executed

- Laravel Guided Tour tests passed in the prior V8 suite.
- JS suite: node tests **184/184** and Vitest **99/99** after missing-target regression coverage.
- Vite build, typecheck, static docs check: passed.
- Guided-tour copy/key coverage: **3/3** focused Node tests passed.
- Playwright journeys pass for the investor welcome modal → Begin → first guided-tour step, persisted-step resume, in-progress `page.reload()` recovery, scrim interception of highlighted navigation, and all 8 configured route steps on desktop Chromium/1024×768 tablet/390×844 mobile. Welcome/resume/step focus containment, labelled descriptions and Escape behavior are covered, and the journeys exposed and fixed a real modal-backdrop stacking defect. Screen-reader tooling, production session interruption, and broader device acceptance remain pending.

FEAT-061 remains **REVIEW**. Keyed i18n plus desktop/mobile journeys, persisted-step resume, in-progress refresh recovery, full configured-route traversal, manual-launch focus return and Tab containment are verified locally. Remaining evidence is screen-reader review, production session interruption, and broader live browser acceptance.
