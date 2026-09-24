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
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow linked from Login. Human applicants submit the information required for admin onboarding behind CAPTCHA and abuse controls. Pending duplicates are deduplicated; admins review requests in User Management, receive operational notifications, and resolve them as **Create**, **Ignore**, or **Reject**. Reject creates a reversible email ban; Ignore closes the request without banning; Create hands off into the existing secure admin onboarding/invite flow with request details prefilled. | WISHLIST / NEEDS DESIGN |
| V4-FEAT-056 | ML Lifecycle Automation, Deployment & Operations | Consolidates former FEAT-056 + FEAT-060. Own the operational ML lifecycle: scheduled/manual queued training runs, run-state/progress observability, candidate evaluation, gated automatic/manual promotion, deployment, retained versions/rollback, drift/health visibility, Admin controls, lifecycle messaging and operational failure alerts. | WISHLIST / NEEDS DESIGN |
| V4-FEAT-057 | ML Feature Engineering, Model Training & Validation | Consolidates former FEAT-057 + FEAT-058 + FEAT-059. Define and validate the combined point-in-time ML feature space across fundamentals, technicals, market/regime/breadth, sector-relative context and deterministic patterns; perform coverage/redundancy selection; retrain 1m/3m/6m candidates; and evaluate them using repeated chronological validation, calibrated promotion criteria, active-model comparison and the deterministic StoX baseline. | WISHLIST / DEPENDS ON V4-FEAT-054 |
| V4-FEAT-058 | ML Technical & Market Feature Engineering | **Merged into V4-FEAT-057.** Historical ID retained for traceability; no separate implementation epic remains. | MERGED / RETIRED |
| V4-FEAT-059 | ML Promotion-Threshold Calibration & Multi-Window Validation | **Merged into V4-FEAT-057.** Historical ID retained for traceability; validation/calibration is part of the consolidated training epic. | MERGED / RETIRED |
| V4-FEAT-060 | ML Admin UI & Training Observability Refinement | **Merged into V4-FEAT-056.** Historical ID retained for traceability; Admin ML operations/observability is part of the consolidated lifecycle epic. | MERGED / RETIRED |
| V4-FEAT-061 | Guided Tour / Welcome Onboarding | Add a lightweight in-product guided tour for first-time/new users using real StoX UI elements, a configurable step engine, spotlight/dim overlays, tooltip navigation and a welcome entry modal. Persist completion/dismiss state per user, support responsive positioning and hidden-menu coordination, and keep the implementation small and in-house rather than adding a third-party tour framework. | WISHLIST / NEEDS DESIGN |

## 3. V4-FEAT-055 — Account Access Request / Admin Approval Workflow

### 3.1 Product intent

StoX remains **admin-controlled / invite-only** for actual account creation. This feature does **not** introduce open self-registration.

It adds a public way for a prospective user to ask an administrator for access:

```text
Login
  -> Request an account
      -> CAPTCHA + request form
          -> pending request
              -> Admin Create -> existing secure create/invite flow
              -> Admin Ignore -> closed; applicant may request again later
              -> Admin Reject -> closed + email banned
                                  -> Admin may later clear ban
```

The applicant never chooses their own role, receives admin privileges, or creates an account merely by submitting the request.

### 3.2 Guest request form

The Login page SHALL expose a clear **Request an account** link to a dedicated unauthenticated form.

The form SHALL collect the fields required by the current admin onboarding contract. Initial V8 design should at minimum support:

- full/display name;
- normalized email address;
- any additional non-secret fields that the eventual Admin **Create user** flow requires.

The request model and Admin create form SHOULD share a common field contract so they cannot silently drift apart as onboarding fields evolve.

The form MUST NOT collect:

- password;
- TOTP/recovery secrets;
- broker credentials;
- API tokens;
- requested Admin role or execution entitlement.

Password establishment remains inside the existing secure invitation/onboarding flow.

### 3.3 Human / abuse verification

Submission SHALL require server-verified CAPTCHA or an equivalent human-verification mechanism.

Design requirements:

- CAPTCHA validation occurs server-side;
- CAPTCHA response tokens are transient and are not persisted as applicant data;
- provider keys remain server-side;
- failure degrades explicitly rather than silently accepting unverified submissions;
- public endpoint remains rate-limited in addition to CAPTCHA;
- rate limits SHOULD consider normalized email and source/IP dimensions;
- repeated failed CAPTCHA/submission attempts must not create request rows.

The CAPTCHA implementation SHOULD be provider-abstracted so StoX is not permanently coupled to one vendor.

### 3.4 Identity normalization and duplicate handling

Email is the primary request identity and SHALL be normalized consistently with existing users/invites.

Before creating a new request, the system SHALL distinguish:

1. **existing StoX user** — do not create an access request; direct the person to normal login/password-recovery guidance;
2. **active/pending invitation already exists** — do not create a duplicate request; tell the applicant that onboarding is already in progress and to use/contact the administrator for the invitation;
3. **pending access request already exists** — do not create another row; return a clear, non-destructive "request already pending" message;
4. **email is on rejected/banned list** — do not create a request; return the product-defined blocked-request message;
5. **previous request was ignored/closed without ban** — a future request may be created normally;
6. **no conflict** — create a new pending request.

Duplicate protection SHOULD be enforced transactionally/database-backed where practical, not only by a frontend check.

### 3.5 Request lifecycle

Minimum lifecycle:

```text
pending
  -> created
  -> ignored
  -> rejected
```

Recommended semantic meaning:

- **pending** — awaiting Admin action;
- **created** — Admin chose Create and the request has been handed into the secure account onboarding flow;
- **ignored** — request closed without a ban; a new request from the same email is allowed later;
- **rejected** — request closed and the normalized email is placed on the rejected/banned list.

The design SHALL define whether `created` means "invite/onboarding issued" or "user account ultimately accepted." Preferred V8 semantics are:

- access request becomes `created` when Admin successfully initiates the existing invitation/account-create workflow;
- link the request to the resulting invite and, once accepted, to the resulting user where feasible;
- do not reopen the request merely because an invitation later expires.

All transitions must be auditable and idempotent.

### 3.6 Admin experience

User Management/Admin SHALL expose:

- pending request count/badge;
- request list;
- request detail;
- applicant name/email and submitted metadata;
- submitted timestamp;
- current status;
- prior request history for the same normalized email;
- ban/rejection state where applicable;
- Admin actor and decision timestamp/reason.

Admin actions:

#### Create

- opens the existing Admin user/invite creation flow;
- pre-populates all compatible request fields;
- allows Admin to review/edit allowed onboarding fields before committing;
- actual user creation/onboarding still uses the existing security model;
- success links the request to the generated invite/user workflow;
- concurrent double-click/two-admin resolution must not create duplicate users or invites.

#### Ignore

- closes the request;
- optionally records an Admin note/reason;
- does **not** ban the email;
- applicant may submit a future request;
- any future anti-spam cooldown, if introduced, must be explicit and configurable rather than silently treating Ignore as Reject.

#### Reject

- closes the request;
- adds the normalized email to a rejected/banned identity list;
- optionally records a reason/internal note;
- future requests for that identity are blocked until an Admin clears the ban.

### 3.7 Rejected / banned identities

The Admin UI SHALL provide a dedicated rejected/banned list with:

- normalized email;
- originating request;
- rejected by;
- rejected at;
- optional internal reason;
- **Clear ban / Allow future requests** action.

Clearing a ban SHALL:

- be explicit and confirmed;
- be audited;
- permit a future request;
- not resurrect the original rejected request automatically.

A ban applies to the **request workflow**, not to an already-existing StoX user account unless a separate account-administration action explicitly says otherwise.

### 3.8 Notifications

Creation of a new pending request SHALL notify administrators using the existing StoX notification architecture.

Initial channels:

- in-app/admin notification;
- Telegram;
- email where configured;
- other enabled notification channels supported by the notification subsystem.

Notification requirements:

- target the appropriate Admin recipients, not the applicant;
- include enough non-secret context to identify the request;
- link/deep-link to the Admin request detail where supported;
- deduplicate retries/redelivery so one request does not create notification storms;
- notification delivery failure must **not** delete or roll back a valid access request;
- channel delivery status remains notification state, not request-domain state.

### 3.9 Security and privacy

The public workflow SHALL preserve existing authentication/security boundaries.

At minimum:

- no open `POST /register` equivalent;
- no applicant-controlled role or entitlement;
- Admin-only list/detail/resolve/ban APIs;
- server-side authorization on every Admin action;
- normalized and validated input;
- no password or credential collection;
- CAPTCHA + rate limiting;
- CSRF/session rules appropriate to public vs Admin endpoints;
- request data retained only as long as product policy requires;
- sensitive internal rejection notes are never returned to the applicant;
- public responses should reveal only the minimum useful state needed for the submitting applicant.

### 3.10 Auditability and concurrency

Persist an immutable/auditable decision trail covering:

- request submitted;
- duplicate prevented where useful for operational metrics without storing unnecessary PII;
- Admin Create / Ignore / Reject;
- ban created;
- ban cleared;
- linked invite/user identifier when Create succeeds.

Two Admins acting concurrently on the same pending request SHALL result in one authoritative transition. Losing requests receive a clear "already resolved" response rather than producing duplicate side effects.

### 3.11 Operational/admin filters

Admin request list SHOULD support at least:

- status;
- submitted date range;
- email/name search;
- pending-only default view;
- rejected/banned view.

Useful counters:

- pending;
- created;
- ignored;
- rejected;
- banned identities.

### 3.12 Initial acceptance criteria

1. Guest can reach **Request an account** from Login without authenticating.
2. CAPTCHA failure creates no request.
3. Valid first submission creates exactly one pending request.
4. Repeating a pending request for the same normalized email creates no second pending request and returns a clear duplicate message.
5. Existing-user email creates no request and is directed to existing-account recovery/login guidance.
6. Existing pending invite creates no duplicate request.
7. Admin receives a visible pending request and notification.
8. Non-admin cannot list, inspect, resolve, reject, or unban requests.
9. **Ignore** closes the request without banning; a later request for that email is allowed.
10. **Reject** closes the request and blocks subsequent requests for that email.
11. Admin can clear the rejection ban; a later request is then allowed.
12. **Create** opens the existing secure user/invite onboarding flow with compatible fields prefilled.
13. Successful Create cannot create a duplicate account/invite even under concurrent Admin actions.
14. Applicant-supplied request data never sets Admin role, automated-execution entitlement, password, broker credentials, or other privileged state.
15. Notification-provider failure does not lose or revert the pending request.
16. Admin decision and ban/unban actions are auditable.

### 3.13 Open design decisions

The detailed V8 design should explicitly decide:

- CAPTCHA provider and fallback behavior;
- exact applicant fields beyond name/email;
- whether Ignore has a minimum resubmission cooldown;
- retention/anonymization period for old ignored/rejected requests;
- wording shown for banned identities;
- whether rejection reason is entirely internal or supports a separate applicant-facing generic reason;
- whether email confirmation/OTP is required before a request becomes pending;
- whether Admin Create hands off to the current invite flow only or a future direct-create workflow;
- whether a successful Create notification should also be sent to the applicant, separate from the invitation itself.

## 4. V4-FEAT-056 — ML Lifecycle Automation, Deployment & Operations

### 4.1 Consolidation status

This epic **absorbs former V4-FEAT-060 — ML Admin UI & Training Observability Refinement**. FEAT-060 is retired as a standalone implementation epic; its historical ID remains in the backlog table for traceability.

### 4.2 Product intent

Operationalize the complete ML lifecycle so routine model maintenance is safe, observable and does not depend on shell access or an Admin remembering every step.

The lifecycle is conceptually:

```text
scheduled / manual / optional health trigger
    -> queued training run
    -> granular run status and diagnostics
    -> candidate evaluation using FEAT-057 validation contract
    -> promotion gates
        -> pass: automatic or explicit manual promotion per policy
        -> fail: retain current active model
    -> deployment / retained version history
    -> rollback capability
    -> informational lifecycle messaging
    -> operational failures become Admin alerts
```

### 4.3 Consolidated scope

This epic SHALL cover together:

- independently configurable scheduled retraining for 1m/3m/6m;
- manual retraining using the same canonical path;
- queue/background execution rather than one opaque long-running HTTP request;
- same-horizon concurrency locks;
- persistent training-run lifecycle and progress/stage state;
- training dataset/version/cutoff/feature metadata visibility;
- candidate/rejected/active/retained model states;
- per-gate eligibility/rejection evidence;
- active-model and deterministic-baseline comparison evidence supplied by FEAT-057;
- gated automatic deployment for scheduled runs where product policy allows;
- explicit manual promotion and rollback controls;
- retained model/version history;
- drift/model-health context and optional early retraining triggers;
- next/last scheduled run visibility;
- informational messages for upcoming/successful/retained-current outcomes;
- Admin alerts only for operational failures requiring attention;
- auditable trigger, lifecycle, promotion, deployment and rollback history.

### 4.4 Core safety rules

1. Scheduled and manual runs MUST use the same canonical training/evaluation implementation.
2. Two same-horizon retrains MUST NOT run concurrently.
3. A successfully trained candidate that fails promotion gates is **not** an operational failure.
4. Automatic deployment MUST NOT bypass FEAT-057 validation/promotion eligibility.
5. Manual promotion/rollback remains available subject to existing authorization/safety rules.
6. Training/deployment state is authoritative domain state; notification delivery state is not.
7. Runtime failures must be visible through Admin UI without requiring SSH/Tinker.

### 4.5 Admin experience

The consolidated Admin ML surface SHOULD expose at minimum:

- active model/version by horizon;
- latest candidate/rejected version;
- next scheduled retraining;
- last scheduled and last manual run;
- current/last run stage, elapsed duration and failure state;
- dataset partition/cutoff/feature-set version metadata;
- observed promotion metrics and thresholds with pass/fail state;
- deterministic baseline and current-active-model comparison;
- retained versions and promotion history;
- rollback action;
- drift/model-health status;
- schedule enable/disable and supported configuration;
- recent informational lifecycle events;
- unresolved operational alerts.

### 4.6 Initial acceptance direction

The detailed design must ensure that scheduled retraining, queued execution, progress visibility, candidate eligibility, promotion/deployment, retained versions, rollback, drift, notifications and failure diagnostics work as one lifecycle rather than independent subsystems.

### 4.7 Open design decisions

To be resolved during the FEAT-056 design phase:

- default schedule/cadence by horizon;
- whether schedules are fixed or Admin-configurable;
- exact queue/job and persistent run-state model;
- polling versus SSE/WebSocket progress delivery;
- automatic-promotion policy for scheduled candidates;
- retry/backoff and consecutive-failure escalation;
- optional drift-triggered early retraining policy;
- notification timing/channel preferences;
- retained model/version policy;
- confirmation and authorization UX for promotion/rollback.

## 5. V4-FEAT-057 — ML Feature Engineering, Model Training & Validation

### 5.1 Consolidation status

This epic **absorbs former V4-FEAT-058 — ML Technical & Market Feature Engineering** and **V4-FEAT-059 — ML Promotion-Threshold Calibration & Multi-Window Validation**. FEAT-058 and FEAT-059 are retired as standalone implementation epics; their IDs remain in the backlog table for traceability.

### 5.2 Product intent

Build one coherent research/training pipeline that answers a single question: **which point-in-time-safe information should StoX models use, and does the resulting model demonstrably improve out-of-sample performance?**

The consolidated flow is:

```text
candidate feature universe
    -> PIT/coverage/data-quality validation
    -> redundancy and feature selection
    -> horizon-aware feature sets
    -> 1m / 3m / 6m training
    -> repeated chronological validation
    -> calibrated statistical + investment gates
    -> compare with active model + deterministic StoX baseline
    -> candidate eligibility evidence
```

### 5.3 Feature families

The detailed design SHALL evaluate a curated, versioned feature universe covering:

- **fundamentals** — growth, profitability, balance-sheet quality, cash-flow quality, valuation and trustworthy sector-specific metrics;
- **technical/trend** — returns, moving-average relationships/slopes, momentum and relative strength;
- **volatility/risk** — ATR/normalized ATR, realised volatility, drawdown/downside features, contraction/expansion;
- **volume/liquidity** — relative volume, volume trend and related data-quality-safe measures;
- **market context** — benchmark trend, breadth and regime features where historically reconstructable;
- **sector-relative context** — sector-relative strength/ranking where historical sector data is reliable;
- **deterministic patterns** — mathematically defined candlestick/chart/breakout/consolidation signals with versioned rules.

### 5.4 Feature governance and PIT safety

For every candidate feature, the implementation SHALL define or measure:

- exact formula/source inputs;
- timeframe/basis;
- point-in-time availability semantics;
- historical coverage;
- missingness;
- outlier/normalization handling where applicable;
- redundancy/correlation with other candidates;
- horizon-specific usefulness;
- deterministic feature-definition version/hash.

No feature may use future bars, later-published fundamentals, later revisions unavailable at the reference date, or historically unreconstructable universe/sector context.

### 5.5 Training and validation

The epic SHALL support 1m/3m/6m candidate retraining using the selected feature definition and evaluate candidates with:

- leakage-safe chronological train/validation/test separation;
- repeated/multi-window chronological validation rather than reliance on one favorable holdout;
- per-window plus aggregate metrics;
- class-prevalence-aware interpretation of PR-AUC;
- ROC-AUC/no-skill context;
- investment outcome metrics;
- benchmark-relative results;
- deterministic StoX baseline comparison;
- current active-model comparison over comparable periods;
- stability/dispersion across windows;
- horizon-aware promotion thresholds where supported by evidence.

Thresholds MUST NOT be lowered merely to make a preferred candidate pass.

### 5.6 Promotion boundary

FEAT-057 determines **candidate eligibility evidence**. It does not own recurring scheduling, deployment automation, operational messaging, run monitoring or rollback; those belong to FEAT-056.

A candidate produced here may remain non-active. Promotion/deployment consumes the evidence generated by this epic.

### 5.7 Dependency

FEAT-057 depends on FEAT-054 for materially improved historical fundamental coverage. Technical/market feature research can proceed independently, but the consolidated feature-selection/training decision should use the completed FEAT-054 dataset when fundamentals are included.

### 5.8 Initial acceptance direction

The detailed design must produce reproducible versioned feature sets, leakage-safe 1m/3m/6m datasets, repeated chronological evidence, calibrated promotion eligibility and direct comparison with both the active model and deterministic StoX baseline.

### 5.9 Open design decisions

To be resolved during the FEAT-057 design phase:

- breadth of the first candidate feature universe;
- common versus horizon-specific feature sets;
- minimum acceptable historical coverage;
- missing-value policy;
- outlier/winsorization policy;
- redundancy/feature-selection method;
- exact technical/pattern feature catalogue;
- reliable market breadth/sector-history boundaries;
- model-family scope;
- rolling-window count and spacing;
- horizon-specific promotion thresholds and stability rules;
- minimum improvement/evidence required versus active model and deterministic baseline.

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
