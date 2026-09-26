# StoX V8 Guided Tour / Welcome Onboarding Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-061 — Guided Tour / Welcome Onboarding |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Canonical path** | `docs/archive/specs/V8-Guided-Tour-Welcome-Onboarding-Specification.md` |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` |
| **Primary audience** | Investor users only |
| **Primary implementation agent** | Codex |

---

## 1. Purpose

V4-FEAT-061 adds a lightweight guided onboarding experience for Investor users so they can discover the main StoX product journey using the real application UI.

The feature consists of:

- a welcome prompt shown on early eligible logins;
- a configuration-driven, multi-route guided tour;
- spotlight/dim-overlay presentation over existing UI elements;
- persistent per-user onboarding state;
- manual relaunch from Help/Profile.

The tour is explanatory. It does not replace help documentation, setup wizards, or normal product interactions.

---

## 2. Scope

### 2.1 In scope

- Investor-only welcome/tour eligibility;
- early-login welcome prompting;
- Begin tour / Skip for now / Don't show again actions;
- configurable prompt cap;
- manual relaunch from Help/Profile;
- multi-route tour navigation;
- fixed core Investor tour structure;
- real-UI spotlight targeting;
- Back / Next / Finish navigation;
- backend-persisted per-user onboarding state;
- resume from last interrupted step with restart option;
- automatic bounded-wait skip for missing targets;
- responsive positioning and shell/menu coordination;
- accessibility and keyboard behavior;
- lightweight telemetry for onboarding events.

### 2.2 Out of scope

- Admin guided tour;
- capability-specific or role-specific tour variants;
- fully interactive training where the user operates live actions during tour steps;
- third-party onboarding framework dependency unless implementation proves clearly superior;
- replacement for Help/documentation;
- workflow-specific setup wizards.

---

## 3. Frozen product decisions

| Decision | Frozen choice |
|---|---|
| 061-01 | Offer welcome tour on early eligible logins until completed, permanently dismissed, or prompt cap reached. |
| 061-02 | Users can manually relaunch the tour from Help/Profile at any time. |
| 061-03 | Tour may span multiple routes while preserving progress. |
| 061-04 | Initial tour covers the core StoX product journey, not every feature. |
| 061-05 | Fixed tour structure for Investor users only; Admin users do not get the feature. |
| 061-06 | Onboarding state is stored per user in the backend. |
| 061-07 | Interrupted tour resumes from the last step, with an option to restart from the beginning. |
| 061-08 | Highlighted UI is explanatory only; user does not operate live product actions during tour steps. |
| 061-09 | Missing targets are skipped automatically after a bounded wait. |
| 061-10 | Automatic welcome prompting stops after completion, permanent dismissal, or a small configurable number of skipped prompts. |

---

## 4. Eligibility

The feature is enabled only for Investor users.

Requirements:

- Admin users do not see the welcome modal;
- Admin users do not see the relaunch entry;
- Investor eligibility is determined from existing authenticated role/account context;
- eligibility logic is server/domain-backed where appropriate and must not depend only on a frontend check.

The tour itself is fixed for Investor users rather than dynamically branched by feature capability.

---

## 5. Welcome prompt

For an eligible Investor user who has not completed or permanently dismissed onboarding and has not reached the prompt cap, StoX may show a welcome modal on early logins.

Actions:

- **Begin tour** — starts or resumes the tour;
- **Skip for now** — dismisses the modal for the current login and increments the prompt/skip state;
- **Don't show again** — permanently suppresses automatic prompting.

Completing the tour also permanently suppresses automatic prompting.

Exact maximum prompt count is configurable and not a PO-level constant.

---

## 6. Manual relaunch

Investor users SHALL have a discoverable manual entry under Help/Profile to launch the tour later.

Manual relaunch remains available even after:

- tour completion;
- permanent dismissal;
- prompt-cap exhaustion.

If prior progress exists, manual relaunch should default to **Resume**, with a clear **Restart from beginning** option.

---

## 7. Tour structure

The initial tour covers the core StoX journey rather than every screen.

Expected areas include, subject to actual current navigation labels and implementation:

- application navigation shell;
- dashboard/home;
- portfolio;
- watchlist and/or stock detail;
- screener;
- recommendations/strategy-related discovery where relevant to Investor UX;
- notifications;
- settings/help.

Exact step order and wording are implementation content and may be tuned without reopening the product contract.

Steps SHALL be configuration-driven rather than hard-coded across components.

Each step should define at least:

- stable step ID;
- route or route requirement;
- stable target selector/hook;
- title/body copy key;
- preferred tooltip placement;
- optional shell coordination action;
- optional responsive override.

---

## 8. Multi-route behavior

The tour may navigate across routes.

Requirements:

- current step/progress survives route transitions;
- route navigation is controlled by the tour engine;
- the engine waits for route readiness and target rendering;
- browser refresh/interruption can recover from persisted state where practical;
- a route failure or missing target must not trap the user.

The user is not required to click real navigation items to advance the tour.

---

## 9. Interaction model

Tour navigation controls are authoritative:

- Back;
- Next;
- Finish;
- Close.

Highlighted product controls are explanatory only during the active step.

The tour may internally open a menu, drawer, tab, or route required to expose a target, but user interaction with underlying live product actions should be blocked or safely ignored while the overlay is active.

This prevents accidental portfolio, strategy, recommendation, broker, or settings mutations during onboarding.

---

## 10. Missing target behavior

A target may be temporarily absent because of render timing, responsive layout, conditional content, or route state.

The engine SHALL:

1. wait for a bounded period;
2. retry target lookup during that period;
3. if still missing, mark the step skipped for that run;
4. continue to the next valid step.

Do not show a fatal error or trap the user.

Skipped-step telemetry may be recorded for diagnostics.

---

## 11. Persistence model

Backend state is authoritative per user.

Persist at minimum:

- welcome prompt/show count;
- permanent-dismissed flag/timestamp;
- tour completed flag/timestamp;
- last/current step ID for interrupted-tour resume;
- optional tour version.

A tour-version field is recommended so a materially redesigned future tour can selectively reset/resurface onboarding without corrupting previous state.

Client/local storage may be used only as a temporary UX optimization, not as the authoritative source.

---

## 12. Resume and restart

If a tour is interrupted before Finish:

- completion remains false;
- last valid step is retained;
- a later launch may resume from that step;
- user may explicitly restart from step 1.

If the stored step no longer exists in the current tour version, fall back safely to the first valid step.

---

## 13. Completion semantics

Tour completion occurs only when the user reaches and activates **Finish** on the last valid step.

Completion must not be set by:

- Skip for now;
- Don't show again;
- Close/Escape mid-tour;
- route change outside the tour;
- missing-target auto-skip unless the last valid step is actually finished.

Permanent dismissal and completion are distinct stored states, even though both suppress automatic prompting.

---

## 14. Presentation architecture

Prefer a small in-house implementation.

Recommended components:

- tour definition/config registry;
- tour manager/state machine;
- overlay/highlight layer;
- tooltip/popover presenter;
- shell coordinator for menus/drawers/routes;
- persistence API/service;
- telemetry hooks.

Use stable `data-*` or equivalent DOM hooks rather than brittle CSS-path selectors.

Tooltip position should be computed from target geometry with viewport-aware fallback placement.

---

## 15. Responsive behavior

The tour must work on supported desktop and mobile layouts.

Requirements:

- detect responsive target variants where necessary;
- open collapsed navigation/drawers before targeting;
- scroll targets into view;
- keep tooltip within viewport;
- gracefully skip targets unavailable in the current responsive layout;
- restore shell state after step transition/close where practical.

The tour remains one fixed Investor journey; responsive adaptations are implementation details, not separate product variants.

---

## 16. Accessibility

At minimum:

- tour controls are keyboard accessible;
- focus moves predictably into the active tour UI;
- Escape closes the tour unless a product-wide accessibility convention dictates otherwise;
- tooltip/dialog semantics use appropriate ARIA roles/labels;
- visible focus indicators are preserved;
- background interaction is blocked while the overlay is active;
- focus returns sensibly when the tour closes.

Exact focus-trap mechanics are implementation-level decisions.

---

## 17. Internationalization

All user-facing welcome/tour strings SHALL use the normal StoX i18n mechanism.

Step definitions should reference translation keys rather than embedding non-localizable copy throughout React components.

---

## 18. Telemetry

Telemetry must not be required for the tour to function.

Useful events include:

- welcome shown;
- Begin tour;
- Skip for now;
- Don't show again;
- tour started;
- tour resumed;
- tour restarted;
- step skipped because target missing;
- tour closed before completion;
- tour completed;
- manual relaunch.

Use the existing StoX telemetry/event path rather than creating a tour-only telemetry subsystem.

---

## 19. Suggested API direction

Authenticated Investor-facing capabilities conceptually include:

- read onboarding state;
- update prompt/dismiss state;
- update current step/progress;
- mark completed;
- reset/restart progress where allowed.

Admin-specific onboarding APIs are not required.

Mutations must apply only to the authenticated user's own onboarding state.

---

## 20. Security and safety rules

1. Tour is Investor-only.
2. Admin users do not receive or launch the tour.
3. A user may mutate only their own onboarding state.
4. Tour overlay must not permit accidental live product mutations during explanatory steps.
5. Route navigation performed by the tour must respect normal frontend/backend authorization.
6. Missing-target handling must fail soft.
7. Tour persistence must not contain financial or credential data.
8. Telemetry failure must not break onboarding.

---

## 21. Implementation-level defaults

The following may be tuned without reopening the frozen product decisions:

- exact welcome prompt cap;
- target wait timeout/retry interval;
- exact initial step order/copy;
- tooltip placement/fallback algorithm;
- animation durations;
- shell/menu restoration details;
- onboarding-state schema/table names;
- telemetry event names;
- tour versioning/reset strategy;
- exact Help/Profile placement.

---

## 22. Acceptance criteria

1. Admin users never receive the welcome tour or relaunch entry.
2. Eligible Investor users are offered the welcome prompt on early logins while below the prompt cap.
3. Welcome modal offers Begin tour, Skip for now, and Don't show again.
4. Skip for now does not mark the tour complete.
5. Don't show again suppresses future automatic prompts but does not prevent manual relaunch.
6. Completing the tour suppresses future automatic prompts.
7. Reaching the configurable prompt cap suppresses further automatic prompts.
8. Investor can manually relaunch from Help/Profile at any time.
9. Tour can move across multiple routes while retaining progress.
10. Tour uses real StoX UI targets, not duplicate mock controls.
11. Highlighted underlying controls are explanatory and cannot trigger unintended live actions.
12. Back, Next, Finish and Close work consistently.
13. Interrupted tour persists progress and can resume later.
14. User can restart from the beginning.
15. Missing target after bounded wait is skipped automatically and tour continues.
16. Responsive layouts can reveal/target appropriate UI without crashing.
17. Completion is recorded only on explicit Finish of the final valid step.
18. Backend persistence keeps onboarding behavior consistent across browsers/devices.
19. Tour strings use normal i18n.
20. Telemetry failure does not affect tour behavior.
21. User cannot mutate another user's onboarding state.
22. Temporary overlay/shell changes are cleaned up after close/finish.

---

## 23. Definition of done

FEAT-061 is complete when:

- Investor-only eligibility is enforced;
- early-login welcome prompting and prompt-cap behavior work;
- manual relaunch from Help/Profile works;
- configuration-driven multi-route tour works across the agreed core journey;
- progress persists in the backend and supports resume/restart;
- explanatory-only interaction prevents accidental product actions;
- missing targets skip safely;
- responsive/accessibility behavior is production-usable;
- i18n and telemetry hooks are integrated;
- tests cover eligibility, prompt suppression, resume/restart, route changes, missing targets, completion, dismissal, authorization and cleanup;
- V8 register links this document as the authoritative FEAT-061 implementation contract.

---

## 24. Explicit non-goals

FEAT-061 is not a generic tutorial platform and does not attempt to teach every StoX feature.

The authoritative V8 boundary is:

```text
Audience = Investor users only
Tour = fixed core journey
Interaction = explanatory
Progress = backend persisted
Navigation = multi-route
Recovery = resume/restart + skip missing target
```
