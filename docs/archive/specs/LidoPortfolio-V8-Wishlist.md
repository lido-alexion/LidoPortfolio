# LidoPortfolio / StoX V8 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V8 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V7-Wishlist.md` |

## 1. Purpose

V8 contains StoX follow-on data, ML, account/onboarding and telemetry-integration work that is intentionally kept outside the V7 closure gate.

For telemetry, V8 does **not** build the LidoTelemetry product. LidoTelemetry already exists as a separate independently deployed telemetry product/service. StoX V8 owns only the producer-side integration: OpenTelemetry instrumentation across browser/backend/background processing, a Collector gateway, explicit StoX business telemetry, metrics, trace propagation and export to LidoTelemetry.

V8 also contains the one-time Historical Fundamental Data Bootstrap, a guest-to-admin **Account Access Request** workflow that preserves admin-controlled onboarding, and a consolidated ML follow-on program with two coherent product boundaries: **ML Feature Engineering, Model Training & Validation** and **ML Lifecycle Automation, Deployment & Operations**.

V8 also includes a lightweight **Guided Tour / Welcome Onboarding** experience so new Investor users can discover the StoX shell and important workflows in context without introducing a heavyweight third-party onboarding dependency.

## 2. Current V8 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-052 | StoX OpenTelemetry / LidoTelemetry Integration | Instrument StoX React/browser, Laravel/backend, queues and scheduler with OpenTelemetry; propagate distributed trace context; add explicit StoX business telemetry and focused metrics; export through an OpenTelemetry Collector to the existing LidoTelemetry service; remain fail-open and privacy-safe. **Canonical implementation spec:** [`V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md`](V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | Complete the V7 fundamentals foundation with canonical fact-gap filling, curated derived metrics, official NSE/BSE historical backfill with Yahoo fallback, resumable queue-backed bootstrap, investor Fundamentals UI, historical charts and Screener eligibility integration. **Canonical implementation spec:** [`V8-Historical-Fundamentals-Bootstrap-Specification.md`](V8-Historical-Fundamentals-Bootstrap-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow with CAPTCHA and mandatory email verification, followed by Admin Create/Ignore/Reject review. Create reuses the existing secure invite flow; Ignore applies a configurable cooldown; Reject creates a reversible request ban. **Canonical implementation spec:** [`V8-Account-Access-Request-Admin-Approval-Specification.md`](V8-Account-Access-Request-Admin-Approval-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-056 | ML Lifecycle Automation, Deployment & Operations | Consolidates former FEAT-056 + FEAT-060. Own the operational ML lifecycle: scheduled/manual/drift-triggered queued training, SSE progress, retries/cancellation, candidate lifecycle, explicit Admin promotion/rollback, bounded retained versions, production drift/health monitoring and actionable notifications. **Canonical implementation spec:** [`V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`](V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-057 | ML Feature Engineering, Model Training & Validation | Consolidates former FEAT-057 + FEAT-058 + FEAT-059. Define and validate the combined point-in-time ML feature space across fundamentals, technicals, market/regime/breadth, sector-relative context and deterministic patterns; perform coverage/redundancy selection; retrain 1m/3m/6m candidates; and evaluate them using repeated chronological validation, calibrated promotion criteria, active-model comparison and the deterministic StoX baseline. **Canonical implementation spec:** [`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md). | FROZEN / IMPLEMENTATION-READY / DEPENDS ON V4-FEAT-054 |
| V4-FEAT-058 | ML Technical & Market Feature Engineering | **Merged into V4-FEAT-057.** Historical ID retained for traceability; no separate implementation epic remains. | MERGED / RETIRED |
| V4-FEAT-059 | ML Promotion-Threshold Calibration & Multi-Window Validation | **Merged into V4-FEAT-057.** Historical ID retained for traceability; validation/calibration is part of the consolidated training epic. | MERGED / RETIRED |
| V4-FEAT-060 | ML Admin UI & Training Observability Refinement | **Merged into V4-FEAT-056.** Historical ID retained for traceability; Admin ML operations/observability is part of the consolidated lifecycle epic. | MERGED / RETIRED |
| V4-FEAT-061 | Guided Tour / Welcome Onboarding | Add an Investor-only, configuration-driven multi-route guided tour with early-login welcome prompting, backend-persisted progress, resume/restart, explanatory-only spotlight steps, safe missing-target skipping and manual relaunch from Help/Profile. **Canonical implementation spec:** [`V8-Guided-Tour-Welcome-Onboarding-Specification.md`](V8-Guided-Tour-Welcome-Onboarding-Specification.md). | FROZEN / IMPLEMENTATION-READY |

## 3. V4-FEAT-052 — StoX OpenTelemetry / LidoTelemetry Integration

### 3.1 Frozen status

FEAT-052 is frozen and implementation-ready. The authoritative contract is:

[`V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md`](V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md)

The canonical specification defines OpenTelemetry instrumentation across the StoX React frontend, Laravel backend, queues and scheduler; distributed tracing; explicit StoX business telemetry; focused custom metrics; pseudonymous identity; privacy/redaction; Collector-based export to LidoTelemetry; no sampling; and strict fail-open behavior.

### 3.2 Product boundary

LidoTelemetry is an existing standalone telemetry product/service. StoX is only a telemetry producer in FEAT-052.

StoX owns instrumentation, trace propagation and export. LidoTelemetry owns receipt, storage, display, query and analysis. FEAT-052 does not redesign or implement the LidoTelemetry product.

The historical `V7-Telemetry-Platform.md` document remains reference material for LidoTelemetry product architecture, but is no longer the implementation contract for StoX FEAT-052.

### 3.3 Integration boundary

The intended flow is:

```text
StoX React/browser ----\
StoX Laravel/backend ---+--> OpenTelemetry --> OpenTelemetry Collector --> LidoTelemetry
StoX queues/scheduler --/
```

Telemetry is unsampled in V8, bounded, privacy-safe and non-blocking. LidoTelemetry or Collector failure must not fail StoX business execution.

## 4. V4-FEAT-055 — Account Access Request / Admin Approval Workflow

### 4.1 Frozen status

The FEAT-055 architecture and product decisions are frozen and implementation-ready. The authoritative contract is:

[`V8-Account-Access-Request-Admin-Approval-Specification.md`](V8-Account-Access-Request-Admin-Approval-Specification.md)

The canonical specification defines the public request form, CAPTCHA, mandatory email verification, normalized-email conflict handling, pending request lifecycle, Admin Create/Ignore/Reject behavior, Ignore cooldown, reversible Reject ban, prior-request history, applicant/Admin notifications, auditability and abuse controls.

### 4.2 Security boundary

FEAT-055 does **not** introduce open registration. A public request can become only a verified pending request. Actual account creation remains inside the existing Admin-controlled secure invitation/acceptance flow.

Admin **Create** issues the existing StoX invite; **Ignore** closes without ban but applies a bounded cooldown; **Reject** closes and request-bans the normalized email until an Admin explicitly clears it.

### 4.3 Applicant boundary

The public form collects only full name and email. Applicants receive verification and final-outcome emails, but there is no public request-status lookup and no exposure of internal Admin notes or ban mechanics.

## 5. V4-FEAT-056 — ML Lifecycle Automation, Deployment & Operations

### 5.1 Consolidation and frozen status

This epic **absorbs former V4-FEAT-060 — ML Admin UI & Training Observability Refinement**. FEAT-060 remains retired as a standalone implementation epic.

The FEAT-056 architecture and product decisions are frozen and implementation-ready. The authoritative contract is:

[`V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`](V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md)

The canonical specification defines scheduled/manual/drift-triggered retraining, bounded schedule configuration, persistent queued execution, same-horizon concurrency, SSE progress, bounded retries, safe cancellation, candidate freshness/supersession, explicit Admin promotion and rollback, promotion evidence review, bounded model retention, production drift/health monitoring, actionable notifications, recovery and auditability.

### 5.2 Automation boundary

Training and FEAT-057 evaluation may run automatically, but **production activation never does in V8**. A passing candidate becomes eligible and awaits explicit Admin promotion. Rollback is likewise explicit Admin action.

### 5.3 FEAT-057 boundary

FEAT-056 consumes the frozen FEAT-057 training/validation and candidate-eligibility evidence. It does not redefine feature engineering, model selection, calibration or promotion gates. FEAT-057 provides training-time drift baselines; FEAT-056 owns production drift monitoring against them.

## 6. V4-FEAT-057 — ML Feature Engineering, Model Training & Validation

### 6.1 Consolidation and frozen status

This epic **absorbs former V4-FEAT-058 — ML Technical & Market Feature Engineering** and **V4-FEAT-059 — ML Promotion-Threshold Calibration & Multi-Window Validation**. FEAT-058 and FEAT-059 remain retired as standalone implementation epics.

The FEAT-057 architecture and product decisions are now frozen and implementation-ready. The authoritative contract is:

[`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md)

The canonical specification defines the V8 feature registry/catalogue, horizon applicability, PIT-safe dataset construction, active-stock-only training universe, 1m/3m/6m sampling, model families, bounded tuning, classifier + secondary return regressor, probability calibration, repeated chronological validation, regime/breadth/sector context, candidate eligibility evidence, deterministic-baseline and active-model comparison, explainability, investor-facing ML outputs, Screener filter integration, Strategy boundary, reproducibility and drift-reference handoff to FEAT-056.

### 6.2 Dependency

FEAT-057 is implementation-ready but remains dependent on **V4-FEAT-054** for the final fundamental-inclusive feature-selection/training campaign. Technical/market/pattern work can proceed earlier, but final consolidated training must use the completed FEAT-054 PIT-safe historical fundamentals.

### 6.3 Operational boundary

FEAT-057 owns feature engineering, training, calibration, validation and candidate-eligibility evidence. Scheduled/manual run orchestration, deployment/promotion execution, retained versions/rollback, lifecycle notifications and production drift monitoring remain owned by **V4-FEAT-056**.

## 7. V4-FEAT-061 — Guided Tour / Welcome Onboarding

### 7.1 Frozen status

The FEAT-061 architecture and product decisions are frozen and implementation-ready. The authoritative contract is:

[`V8-Guided-Tour-Welcome-Onboarding-Specification.md`](V8-Guided-Tour-Welcome-Onboarding-Specification.md)

The canonical specification defines Investor-only eligibility, early-login welcome prompting, manual relaunch, a fixed core multi-route journey, backend-persisted resume/restart state, explanatory-only spotlight behavior, bounded-wait target skipping, responsive/accessibility behavior, i18n and telemetry.

### 7.2 Audience boundary

The feature is enabled for **Investor users only**. Admin users do not receive the welcome prompt and do not get the manual relaunch entry.

### 7.3 Interaction boundary

The tour explains the real StoX interface but does not ask users to operate live product actions. Tour navigation drives route/menu changes; missing targets are skipped safely rather than blocking onboarding.

## 8. Boundary

V8 owns the StoX-side OpenTelemetry integration with the existing LidoTelemetry service: browser/backend/background instrumentation, trace propagation, explicit business telemetry, focused metrics, Collector export, privacy controls and fail-open behavior. V8 does **not** own LidoTelemetry product implementation.

V8 also owns the one-time historical fundamental bootstrap outcome, but does not change FEAT-053's ongoing provider-driven fundamental ingestion architecture.

V8 owns the account-access request workflow through Admin disposition and handoff into the existing secure user onboarding flow. It does **not** replace the existing invitation/token security model, establish open registration, or allow a guest request to grant any account privilege by itself.

V8 owns **FEAT-057 — ML Feature Engineering, Model Training & Validation** as the single feature-research/training boundary: fundamental, technical, market/regime, sector-relative and deterministic pattern features; PIT/coverage validation; feature selection; 1m/3m/6m retraining; repeated chronological validation; calibrated promotion eligibility; and comparison with the active model and deterministic StoX baseline. Former FEAT-058 and FEAT-059 are merged into FEAT-057.

V8 owns **FEAT-056 — ML Lifecycle Automation, Deployment & Operations** as the single operational ML boundary: scheduled/manual queued runs, lifecycle observability, Admin controls, candidate/deployment state, gated automatic/manual promotion, retained versions/rollback, drift/model-health visibility, informational lifecycle messaging and operational failure alerts. Former FEAT-060 is merged into FEAT-056.

V8 also owns the Guided Tour / Welcome Onboarding experience: welcome eligibility/persistence, configuration-driven spotlight steps, shell coordination, responsive/accessibility behavior and onboarding telemetry. It does not replace setup workflows or long-form product documentation.
