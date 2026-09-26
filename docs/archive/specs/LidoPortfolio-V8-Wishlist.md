# LidoPortfolio / StoX V8 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V8 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V7-Wishlist.md` |

## 1. Purpose

V8 contains the **Standalone Telemetry Platform**, deferred follow-on data-bootstrap work that is intentionally kept outside the V7 closure gate, and selected account/onboarding improvements that do not alter the existing V1-V7 security boundaries.

The Telemetry Platform is separated from StoX product implementation because it is a separate, independently deployable, product-independent application. StoX is its first intended client, but building the Telemetry product and integrating StoX with it are separate roadmap concerns.

V8 also contains the one-time Historical Fundamental Data Bootstrap, a guest-to-admin **Account Access Request** workflow that preserves admin-controlled onboarding, and a consolidated ML follow-on program with two coherent product boundaries: **ML Feature Engineering, Model Training & Validation** and **ML Lifecycle Automation, Deployment & Operations**.

V8 also includes a lightweight **Guided Tour / Welcome Onboarding** experience so new users can discover the StoX shell and important workflows in context without introducing a heavyweight third-party onboarding dependency.

## 2. Current V8 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-052 | Standalone Telemetry Platform | Build the product-independent telemetry/analytics platform as a separate repository/application. Core architecture is already decided in `specs/V7-Telemetry-Platform.md`; the historical filename is retained for now, but this V8 register supersedes its earlier V7 roadmap placement. | CORE ARCHITECTURE DECIDED |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | Complete the V7 fundamentals foundation with canonical fact-gap filling, curated derived metrics, official NSE/BSE historical backfill with Yahoo fallback, resumable queue-backed bootstrap, investor Fundamentals UI, historical charts and Screener eligibility integration. **Canonical implementation spec:** [`V8-Historical-Fundamentals-Bootstrap-Specification.md`](V8-Historical-Fundamentals-Bootstrap-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow with CAPTCHA and mandatory email verification, followed by Admin Create/Ignore/Reject review. Create reuses the existing secure invite flow; Ignore applies a configurable cooldown; Reject creates a reversible request ban. **Canonical implementation spec:** [`V8-Account-Access-Request-Admin-Approval-Specification.md`](V8-Account-Access-Request-Admin-Approval-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-056 | ML Lifecycle Automation, Deployment & Operations | Consolidates former FEAT-056 + FEAT-060. Own the operational ML lifecycle: scheduled/manual/drift-triggered queued training, SSE progress, retries/cancellation, candidate lifecycle, explicit Admin promotion/rollback, bounded retained versions, production drift/health monitoring and actionable notifications. **Canonical implementation spec:** [`V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`](V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-057 | ML Feature Engineering, Model Training & Validation | Consolidates former FEAT-057 + FEAT-058 + FEAT-059. Define and validate the combined point-in-time ML feature space across fundamentals, technicals, market/regime/breadth, sector-relative context and deterministic patterns; perform coverage/redundancy selection; retrain 1m/3m/6m candidates; and evaluate them using repeated chronological validation, calibrated promotion criteria, active-model comparison and the deterministic StoX baseline. **Canonical implementation spec:** [`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md). | FROZEN / IMPLEMENTATION-READY / DEPENDS ON V4-FEAT-054 |
| V4-FEAT-058 | ML Technical & Market Feature Engineering | **Merged into V4-FEAT-057.** Historical ID retained for traceability; no separate implementation epic remains. | MERGED / RETIRED |
| V4-FEAT-059 | ML Promotion-Threshold Calibration & Multi-Window Validation | **Merged into V4-FEAT-057.** Historical ID retained for traceability; validation/calibration is part of the consolidated training epic. | MERGED / RETIRED |
| V4-FEAT-060 | ML Admin UI & Training Observability Refinement | **Merged into V4-FEAT-056.** Historical ID retained for traceability; Admin ML operations/observability is part of the consolidated lifecycle epic. | MERGED / RETIRED |
| V4-FEAT-061 | Guided Tour / Welcome Onboarding | Add a lightweight in-product guided tour for first-time/new users using real StoX UI elements, a configurable step engine, spotlight/dim overlays, tooltip navigation and a welcome entry modal. Persist completion/dismiss state per user, support responsive positioning and hidden-menu coordination, and keep the implementation small and in-house rather than adding a third-party tour framework. | WISHLIST / NEEDS DESIGN |

## 3. V4-FEAT-055 — Account Access Request / Admin Approval Workflow

### 3.1 Frozen status

The FEAT-055 architecture and product decisions are frozen and implementation-ready. The authoritative contract is:

[`V8-Account-Access-Request-Admin-Approval-Specification.md`](V8-Account-Access-Request-Admin-Approval-Specification.md)

The canonical specification defines the public request form, CAPTCHA, mandatory email verification, normalized-email conflict handling, pending request lifecycle, Admin Create/Ignore/Reject behavior, Ignore cooldown, reversible Reject ban, prior-request history, applicant/Admin notifications, auditability and abuse controls.

### 3.2 Security boundary

FEAT-055 does **not** introduce open registration. A public request can become only a verified pending request. Actual account creation remains inside the existing Admin-controlled secure invitation/acceptance flow.

Admin **Create** issues the existing StoX invite; **Ignore** closes without ban but applies a bounded cooldown; **Reject** closes and request-bans the normalized email until an Admin explicitly clears it.

### 3.3 Applicant boundary

The public form collects only full name and email. Applicants receive verification and final-outcome emails, but there is no public request-status lookup and no exposure of internal Admin notes or ban mechanics.

## 4. V4-FEAT-056 — ML Lifecycle Automation, Deployment & Operations

### 4.1 Consolidation and frozen status

This epic **absorbs former V4-FEAT-060 — ML Admin UI & Training Observability Refinement**. FEAT-060 remains retired as a standalone implementation epic.

The FEAT-056 architecture and product decisions are frozen and implementation-ready. The authoritative contract is:

[`V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`](V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md)

The canonical specification defines scheduled/manual/drift-triggered retraining, bounded schedule configuration, persistent queued execution, same-horizon concurrency, SSE progress, bounded retries, safe cancellation, candidate freshness/supersession, explicit Admin promotion and rollback, promotion evidence review, bounded model retention, production drift/health monitoring, actionable notifications, recovery and auditability.

### 4.2 Automation boundary

Training and FEAT-057 evaluation may run automatically, but **production activation never does in V8**. A passing candidate becomes eligible and awaits explicit Admin promotion. Rollback is likewise explicit Admin action.

### 4.3 FEAT-057 boundary

FEAT-056 consumes the frozen FEAT-057 training/validation and candidate-eligibility evidence. It does not redefine feature engineering, model selection, calibration or promotion gates. FEAT-057 provides training-time drift baselines; FEAT-056 owns production drift monitoring against them.

## 5. V4-FEAT-057 — ML Feature Engineering, Model Training & Validation

### 5.1 Consolidation and frozen status

This epic **absorbs former V4-FEAT-058 — ML Technical & Market Feature Engineering** and **V4-FEAT-059 — ML Promotion-Threshold Calibration & Multi-Window Validation**. FEAT-058 and FEAT-059 remain retired as standalone implementation epics.

The FEAT-057 architecture and product decisions are now frozen and implementation-ready. The authoritative contract is:

[`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md)

The canonical specification defines the V8 feature registry/catalogue, horizon applicability, PIT-safe dataset construction, active-stock-only training universe, 1m/3m/6m sampling, model families, bounded tuning, classifier + secondary return regressor, probability calibration, repeated chronological validation, regime/breadth/sector context, candidate eligibility evidence, deterministic-baseline and active-model comparison, explainability, investor-facing ML outputs, Screener filter integration, Strategy boundary, reproducibility and drift-reference handoff to FEAT-056.

### 5.2 Dependency

FEAT-057 is implementation-ready but remains dependent on **V4-FEAT-054** for the final fundamental-inclusive feature-selection/training campaign. Technical/market/pattern work can proceed earlier, but final consolidated training must use the completed FEAT-054 PIT-safe historical fundamentals.

### 5.3 Operational boundary

FEAT-057 owns feature engineering, training, calibration, validation and candidate-eligibility evidence. Scheduled/manual run orchestration, deployment/promotion execution, retained versions/rollback, lifecycle notifications and production drift monitoring remain owned by **V4-FEAT-056**.

## 6. V4-FEAT-061 — Guided Tour / Welcome Onboarding

### 6.1 Product intent

Add a lightweight first-run onboarding experience that explains StoX by highlighting the **real UI already on screen** rather than rendering a duplicate walkthrough UI.

The feature should have two related pieces:

- an optional welcome modal that offers **Begin tour** / **Skip**;
- an in-context guided tour that spotlights existing navigation, header/actions and other important product areas.

This is a guided-tour feature, not a replacement for setup wizards or long-form help content.

### 6.2 Architecture direction

Prefer a small in-house implementation over a third-party tour library.

Recommended structure:

- ordered, configuration-driven tour steps;
- manager/state machine holding current/previous step;
- presentation layer for dimming overlay, tooltip, Back/Next/Finish and close;
- stable DOM hooks on existing StoX UI targets;
- shell-level coordination for opening/closing hidden menus, drawers or profile panels required by a step;
- scoped persistence for welcome/tour state.

The tour SHALL target real UI elements using stable IDs/data attributes or equivalent selectors. Step order, copy, target selector, tooltip placement and shell coordination keys SHOULD be centralized in configuration.

### 6.3 Runtime behavior

Minimum behavior:

1. Opening the tour starts at the first configured step.
2. **Next** and **Back** move through ordered steps.
3. The active target is visually raised above a dimmed background; optional inner controls may receive a focus outline.
4. Non-active shell regions may be muted and/or pointer-blocked while the tour is active.
5. A step may request the shell to reveal hidden UI such as a collapsed navigation area or profile menu before highlighting it.
6. Missing target nodes must fail softly rather than crashing the application.
7. **Finish** on the last step marks the tour completed.
8. Closing mid-tour clears temporary styling but does **not** mark the tour completed.

A short delayed style application after step changes is acceptable to allow React-rendered menus/panels to appear before targeting.

### 6.4 Welcome and persistence

Persist onboarding state per StoX user, and per tenant/account as applicable.

At minimum retain:

- welcome modal show count;
- "don't show again" / permanent welcome dismissal;
- guided-tour completion.

The welcome modal SHOULD stop appearing after a small configurable number of displays, permanent dismissal, or tour completion.

Tour completion SHALL be recorded only when the user explicitly finishes the final step, not when the welcome modal is skipped or the tour is closed early.

### 6.5 Responsive and accessibility requirements

The implementation SHOULD:

- support narrow and wide StoX layouts;
- allow alternate tooltip placement at responsive breakpoints;
- avoid hard failure when a target is temporarily off-screen or not rendered;
- scroll targets into view where needed;
- provide keyboard-accessible controls and appropriate focus behavior;
- define Escape/close behavior explicitly;
- restore all temporary z-index, border, blur and pointer-event changes on close/finish.

Where practical, tooltip positioning SHOULD be derived from target geometry rather than relying only on fixed coordinates.

### 6.6 Internationalization and telemetry

Tour/welcome copy SHOULD use the normal StoX i18n mechanism.

At minimum capture:

- tour started;
- tour completed;
- welcome shown;
- welcome skipped/dismissed where useful.

Telemetry must not be required for the tour to function.

### 6.7 Initial acceptance criteria

1. Eligible first-time/new users can be offered a welcome modal with **Begin tour** and **Skip**.
2. The tour highlights existing StoX UI elements rather than duplicate mock controls.
3. Steps are configuration-driven and can be added/reordered without rewriting the tour engine.
4. Back, Next, Finish and close work correctly.
5. Hidden/collapsed shell UI can be opened for a step and cleaned up on transition/close.
6. Missing or slow-rendering target elements do not crash StoX.
7. Completion is persisted only after finishing the final step.
8. Skip/close does not incorrectly mark the tour complete.
9. Temporary styles and interaction blocking are fully cleared after close/finish.
10. The tour works across supported responsive layouts.
11. Tour strings are localizable.
12. Basic start/completion telemetry is emitted without becoming a functional dependency.

### 6.8 Open design decisions

The detailed V8 design should decide:

- which StoX areas belong in the initial tour and the final step order;
- exact eligibility/readiness gate for showing onboarding;
- storage key scope and welcome display cap;
- whether the tour can be relaunched manually from Help/Profile;
- tooltip positioning strategy;
- whether shell coordination uses React state/context/events rather than window messaging;
- accessibility details including focus trap and Escape behavior;
- whether tours may span routes, or remain shell/page-local.

## 7. Boundary

V8 owns the Telemetry product itself: ingestion, storage, analytics/query APIs, dashboards/explorer, SDKs, identity/correlation model, events/metrics/logs/traces, retention, export, deletion, administration and the other capabilities frozen in the Telemetry architecture specification.

V8 also owns the one-time historical fundamental bootstrap outcome, but does not change FEAT-053's ongoing provider-driven fundamental ingestion architecture.

V8 owns the account-access request workflow through Admin disposition and handoff into the existing secure user onboarding flow. It does **not** replace the existing invitation/token security model, establish open registration, or allow a guest request to grant any account privilege by itself.

V8 owns **FEAT-057 — ML Feature Engineering, Model Training & Validation** as the single feature-research/training boundary: fundamental, technical, market/regime, sector-relative and deterministic pattern features; PIT/coverage validation; feature selection; 1m/3m/6m retraining; repeated chronological validation; calibrated promotion eligibility; and comparison with the active model and deterministic StoX baseline. Former FEAT-058 and FEAT-059 are merged into FEAT-057.

V8 owns **FEAT-056 — ML Lifecycle Automation, Deployment & Operations** as the single operational ML boundary: scheduled/manual queued runs, lifecycle observability, Admin controls, candidate/deployment state, gated automatic/manual promotion, retained versions/rollback, drift/model-health visibility, informational lifecycle messaging and operational failure alerts. Former FEAT-060 is merged into FEAT-056.

V8 also owns the Guided Tour / Welcome Onboarding experience: welcome eligibility/persistence, configuration-driven spotlight steps, shell coordination, responsive/accessibility behavior and onboarding telemetry. It does not replace setup workflows or long-form product documentation.

V8 does **not** own StoX-specific Telemetry instrumentation/integration. That is planned as a separate V9 StoX epic.
