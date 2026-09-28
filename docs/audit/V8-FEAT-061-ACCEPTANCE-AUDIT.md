# V8 FEAT-061 Guided Tour Acceptance Audit

Date: 2026-09-28
Status: **REVIEW — implementation evidence strong; browser/accessibility and i18n validation pending**

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
| Responsive positioning/scrolling | PARTIAL | Scroll and viewport-constrained tooltip width exist; real desktop/mobile browser journey still pending. |
| Keyboard/accessibility/focus return | PARTIAL | Escape, dialog semantics, focus-on-panel, background scroll lock exist; focus return and screen-reader/mobile audit remain. |
| Normal i18n mechanism | PARTIAL | No established frontend i18n subsystem was found; tour strings are currently component/config literals. This is a repository capability gap requiring product-compatible localization wiring. |
| Telemetry non-blocking and existing path | PASS | `guidedTourTelemetry.js` posts to existing frontend log endpoint and swallows failures. |
| Refresh/interruption recovery | PASS | Backend state is authoritative and provider resumes persisted step; browser refresh journey still needs Playwright execution. |

## Verification executed

- Laravel Guided Tour tests passed in the prior V8 suite.
- JS suite: node tests **184/184** and Vitest **99/99** after missing-target regression coverage.
- Vite build, typecheck, static docs check: passed.
- No browser/Playwright execution was available in this environment.

FEAT-061 remains **REVIEW**. The remaining items are validation/capability work, not a PO decision: execute a supported browser journey, complete accessibility/focus review, and resolve or explicitly document the repository’s lack of an i18n mechanism.
