# V8 FEAT-061 Guided Tour Acceptance Audit

Date: 2026-09-28
Status: **REVIEW — implementation evidence strong; broader browser/accessibility validation pending**

Authoritative contract: `docs/archive/specs/V8-Guided-Tour-Welcome-Onboarding-Specification.md`.

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Investor-only eligibility and Admin exclusion | PASS | `GuidedTourService::isEligible`, protected API, `GuidedTourTest::test_admin_cannot_use_investor_guided_tour_api`, frontend shell guard. |
| Early welcome prompt and configurable cap | PASS | Persisted prompt count/max config, Begin/Skip/Don't show again, backend tests. |
| Manual relaunch after completion/dismissal | PASS | Profile launch hook and `can_manual_relaunch`; Profile target exists. |
| Configuration-driven core multi-route journey | PASS | `investorTourSteps.js`, route-aware provider, stable `data-tour` hooks. |
| Backend persistence and own-user authorization | PASS | `portfolio_user_onboarding_state`, GuidedTour controller/service, API authorization tests. |
| Resume and explicit restart | PASS | `current_step_id`, `tour_in_progress`, resume modal, interrupted-tour test. |
| Completion only on explicit Finish | PASS | Backend `complete` action is separate; close/skip do not set completion. |
| Missing targets bounded wait and safe continuation | PASS | `waitForTarget`; provider now advances through every missing non-final step; final valid step still requires explicit Finish. |
| Explanatory-only underlying interaction | PASS (static) | Full scrim captures background pointer events; panel is the only interactive layer. Browser interaction proof pending. |
| Responsive positioning/scrolling | PARTIAL | Desktop Chromium welcome-to-first-step journey passes; mobile/responsive and full route traversal remain. |
| Keyboard/accessibility/focus return | PARTIAL | Desktop dialog semantics, pointer interaction, Escape, focus-on-panel, and background scroll lock exist; focus return and screen-reader/mobile audit remain. |
| Normal i18n mechanism | PASS locally | `resources/js/src/i18n/index.js` and `messages.js` provide keyed translation lookup with locale fallback; all tour step, modal, overlay and Profile copy references translation keys rather than embedded literals. English is the current shipped dictionary; additional locales remain additive. |
| Telemetry non-blocking and existing path | PASS | `guidedTourTelemetry.js` posts to existing frontend log endpoint and swallows failures. |
| Refresh/interruption recovery | PASS | Backend state is authoritative and provider resumes persisted step; desktop browser journey now proves welcome-to-tour transition. |

## Verification executed

- Laravel Guided Tour tests passed in the prior V8 suite.
- JS suite: node tests **184/184** and Vitest **99/99** after missing-target regression coverage.
- Vite build, typecheck, static docs check: passed.
- Guided-tour copy/key coverage: **3/3** focused Node tests passed.
- Playwright Chromium journey passes for the investor welcome modal → Begin → first guided-tour step. The journey also exposed and fixed a real modal-backdrop stacking defect. Mobile, accessibility tooling, refresh/resume, and full route traversal remain pending.

FEAT-061 remains **REVIEW**. Keyed i18n and a desktop Chromium journey are now verified locally. Remaining evidence is mobile/responsive, accessibility/focus-return, refresh/resume, and broader live browser acceptance.
