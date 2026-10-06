# LidoPortfolio / StoX V8 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V8 planning register |
| **Created** | 2026-09-09 |
| **Status** | FROZEN / IMPLEMENTATION-READY |
| **Canonical path** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V7-Wishlist.md` |

## 1. Purpose

V8 contains StoX follow-on data, ML, account/onboarding, telemetry-integration, investor-workflow and market-data work that is intentionally kept outside the V7 closure gate.

For telemetry, V8 does **not** build the LidoTelemetry product. LidoTelemetry already exists as a separate independently deployed telemetry product/service. StoX V8 owns only the producer-side integration: OpenTelemetry instrumentation across browser/backend/background processing, a Collector gateway, explicit StoX business telemetry, metrics, trace propagation and export to LidoTelemetry.

V8 also contains the one-time Historical Fundamental Data Bootstrap, a guest-to-admin **Account Access Request** workflow that preserves admin-controlled onboarding, a consolidated ML follow-on program, AI-assisted fundamental insight surfacing, a lightweight Investor guided tour, Screener/Strategy workflow simplification, and an intraday historical ML data platform.

## 2. Current V8 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-052 | StoX OpenTelemetry / LidoTelemetry Integration | Instrument StoX React/browser, Laravel/backend, queues and scheduler with OpenTelemetry; propagate distributed trace context; add explicit StoX business telemetry and focused metrics; export through an OpenTelemetry Collector to the existing LidoTelemetry service; remain fail-open and privacy-safe. **Canonical implementation spec:** [`V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md`](V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md). | **IMPLEMENTED**; functional testing tracked in [FEAT-052 test plan](../../testing/V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | Complete the V7 fundamentals foundation with canonical fact-gap filling, curated derived metrics, official NSE/BSE historical backfill with Yahoo fallback, resumable queue-backed bootstrap, investor Fundamentals UI, historical charts and Screener eligibility integration. **Canonical implementation spec:** [`V8-Historical-Fundamentals-Bootstrap-Specification.md`](V8-Historical-Fundamentals-Bootstrap-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow with CAPTCHA and mandatory email verification, followed by Admin Create/Ignore/Reject review. Create reuses the existing secure invite flow; Ignore applies a configurable cooldown; Reject creates a reversible request ban. **Canonical implementation spec:** [`V8-Account-Access-Request-Admin-Approval-Specification.md`](V8-Account-Access-Request-Admin-Approval-Specification.md). | **IMPLEMENTED**; broader product testing in [FEAT-055 plan](../../testing/V8-FEAT-055-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-056 | ML Lifecycle Automation, Deployment & Operations | Consolidates former FEAT-056 + FEAT-060. Own the operational ML lifecycle: scheduled/manual/drift-triggered queued training, SSE progress, retries/cancellation, candidate lifecycle, explicit Admin promotion/rollback, bounded retained versions, production drift/health monitoring and actionable notifications. **Canonical implementation spec:** [`V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`](V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md). | **REVIEW**; local implementation complete, deployed runtime acceptance pending in [FEAT-056 audit](../../audit/V8-FEAT-056-ACCEPTANCE-AUDIT.md) |
| V4-FEAT-057 | ML Feature Engineering, Model Training & Validation | Consolidates former FEAT-057 + FEAT-058 + FEAT-059. Define and validate the combined point-in-time ML feature space across fundamentals, technicals, market/regime/breadth, sector-relative context and deterministic patterns; perform coverage/redundancy selection; retrain 1m/3m/6m candidates; and evaluate them using repeated chronological validation, calibrated promotion criteria, active-model comparison and the deterministic StoX baseline. **Canonical implementation spec:** [`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md). | **IMPLEMENTED**; production acceptance tracked in [FEAT-057 plan](../../testing/V8-FEAT-057-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-058 | ML Technical & Market Feature Engineering | **Merged into V4-FEAT-057.** Historical ID retained for traceability; no separate implementation epic remains. | MERGED / RETIRED |
| V4-FEAT-059 | ML Promotion-Threshold Calibration & Multi-Window Validation | **Merged into V4-FEAT-057.** Historical ID retained for traceability; validation/calibration is part of the consolidated training epic. | MERGED / RETIRED |
| V4-FEAT-060 | ML Admin UI & Training Observability Refinement | **Merged into V4-FEAT-056.** Historical ID retained for traceability; Admin ML operations/observability is part of the consolidated lifecycle epic. | MERGED / RETIRED |
| V4-FEAT-061 | Guided Tour / Welcome Onboarding | Add an Investor-only, configuration-driven multi-route guided tour with early-login welcome prompting, backend-persisted progress, resume/restart, explanatory-only spotlight steps, safe missing-target skipping and manual relaunch from Help/Profile. **Canonical implementation spec:** [`V8-Guided-Tour-Welcome-Onboarding-Specification.md`](V8-Guided-Tour-Welcome-Onboarding-Specification.md). | **IMPLEMENTED**; broader testing in [FEAT-061 plan](../../testing/V8-FEAT-061-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-062 | Fundamental Signals & AI Insights Engine | Use deterministic financial calculations first and AI second to surface material positive, negative, unusual and unresolved fundamental observations without turning visible tables into generic prose. **Canonical implementation spec:** [`V8-Fundamental-Signals-AI-Insights-Specification.md`](V8-Fundamental-Signals-AI-Insights-Specification.md). | FROZEN / IMPLEMENTATION-READY / DEPENDS ON V4-FEAT-054 |
| V4-FEAT-064 | Core Investor Workflow & UX Simplification — Screener and Strategy | Simplify normal Screener/Strategy authoring while preserving immutable historical provenance, private account-scoped instances, definition-copy sharing and multiple concurrent Strategies. **Canonical implementation spec:** [`V8-Core-Investor-Workflow-UX-Simplification.md`](V8-Core-Investor-Workflow-UX-Simplification.md). | FROZEN / IMPLEMENTATION-READY |

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

### 3.4 Implementation status (2026-10-05)

**IMPLEMENTED.** Production evidence covers browser → HTTP parentage, database queue propagation, a natural cron root and child tasks, metrics receipt, and deployed Collector/producer privacy mitigations. The remaining broader privacy, actual asynchronous/business outage, and sustained-delivery scenarios are tracked as product functional testing in [`V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md`](../../testing/V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md). See the [acceptance audit](../../audit/V8-FEAT-052-ACCEPTANCE-AUDIT.md) for exact evidence and historical findings. Implementation status does not assert that those open functional scenarios have passed.

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

### 4.4 Implementation status (2026-10-05)

**IMPLEMENTED.** Production evidence includes prior verified pending requests and all three Admin decisions, invite linkage, cooldown and ban-clear records, an invalid-CAPTCHA no-write probe, a same-token race across independent processes on the deployed database cache, and recipient-confirmed delivery of five labeled feature-email templates. The [acceptance audit](../../audit/V8-FEAT-055-ACCEPTANCE-AUDIT.md) states the limits of each observation. Broader journeys and failure scenarios remain in the [functional test plan](../../testing/V8-FEAT-055-FUNCTIONAL-TEST-PLAN.md); implementation status does not assert those scenarios passed.

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


### 5.4 Implementation status and remaining acceptance (2026-10-05)

**State: REVIEW.** Local lifecycle implementation and feature-specific backend CI are complete. The CI-parity gate passed 2,242 PHPUnit tests (14,962 assertions; 2 skipped), migration portability for 172 migrations, Python checks (22 tests; 8 skipped), and the 219-operation OpenAPI check on commit `d1279fb74b56a2cf488ba82f7c305cb58b424f24`.

Production preflight was read-only. The deployed lifecycle flag, per-horizon schedules, retention and drift triggers are disabled; notifications are configured. The regular queue service is active for `notifications,default`, but a dedicated bounded lifecycle acceptance worker has not been evidenced. No lifecycle tick, queue action, model operation or production setting change was performed.

**Pending before COMPLETE:**

1. **Complete FEAT-057 qualification first.** Run 5 has completed the 360-date apply and preserved run 4's first boundary. A fresh cutoff 2026-10-01 preflight is queued on build 436; its data gates and 1m/3m/6m production evidence remain open. Passing FEAT-057 does not itself enable FEAT-056.
2. **Finish isolated runtime setup.** The dedicated `ml-acceptance` systemd worker is installed and enabled, but currently inactive. Its queue is isolated from the active `notifications,default` worker. Start it through the approved VPS operator path, then verify the current-build preflight completes. Keep lifecycle, schedules, drift triggers and retention disabled until qualification; do not alter the active model.
3. **Exercise queued lifecycle behavior safely.** Using a non-production horizon or safely prepared candidate, verify supported scheduler/manual queueing, one active run per horizon, durable progress and SSE reconnect, queued/running cancellation, worker restart and stale-run recovery, bounded transient retry, and actionable notification delivery. Record persisted run states and timestamps.
4. **Verify model and archive safeguards.** Demonstrate retention and artifact integrity without pruning the active artifact, plus explicit Admin promotion and rollback controls while preserving exactly one active model. Use a safe candidate/control procedure and do not perform a real rollback solely as a test.
5. **Record evidence and disposition.** Update the [FEAT-056 acceptance audit](../../audit/V8-FEAT-056-ACCEPTANCE-AUDIT.md) with build, configuration, worker, run, artifact and notification evidence. Keep FEAT-056 in REVIEW until all applicable checks pass; make lifecycle enablement a separate explicit decision.

The operational steps are in the [FEAT-056 runbook](../../current/ml-lifecycle-operations.md).
## 6. V4-FEAT-057 — ML Feature Engineering, Model Training & Validation

### 6.1 Consolidation and frozen status

This epic **absorbs former V4-FEAT-058 — ML Technical & Market Feature Engineering** and **V4-FEAT-059 — ML Promotion-Threshold Calibration & Multi-Window Validation**. FEAT-058 and FEAT-059 remain retired as standalone implementation epics.

The FEAT-057 architecture and product decisions are now frozen and implementation-ready. The authoritative contract is:

[`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md)

The canonical specification defines the V8 feature registry/catalogue, horizon applicability, PIT-safe dataset construction, active-stock-only training universe, 1m/3m/6m sampling, model families, bounded tuning, classifier + secondary return regressor, probability calibration, repeated chronological validation, regime/breadth/sector context, candidate eligibility evidence, deterministic-baseline and active-model comparison, explainability, investor-facing ML outputs, Screener filter integration, Strategy boundary, reproducibility and drift-reference handoff to FEAT-056.

### 6.2 Dependency

FEAT-057 is implementation-ready but remains dependent on **V4-FEAT-054** for the final fundamental-inclusive feature-selection/training campaign. Technical/market/pattern work can proceed earlier, but final consolidated training must use the completed FEAT-054 PIT-safe historical fundamentals.

### 6.4 Implementation status (2026-10-05)

**IMPLEMENTED.** FEAT-057 code is merged through PRs [#46](https://github.com/lido-alexion/LidoPortfolio/pull/46) and [#47](https://github.com/lido-alexion/LidoPortfolio/pull/47). The mapping resolver passes the frozen 90% floor in offline replay and production preview across all 360 campaign dates (93.4096% minimum). The merged apply correction is covered by backend CI. These results complete the repository implementation boundary, but do not establish production data/training acceptance.

Production run 4 remains an immutable partial run (1/360 dates). Fresh governed run 5 completed the full 360-date apply under parser 5, with a 93.4096% minimum mapping rate; its first-date snapshot and membership digests match run 4's valid boundary. A fresh current-build preflight campaign (cutoff 2026-10-01) is queued but has not run because the dedicated worker is inactive. Post-apply readiness, production 1m/3m/6m evidence and investor-facing explainability checks remain open. See the [functional acceptance plan](../../testing/V8-FEAT-057-FUNCTIONAL-TEST-PLAN.md) and [acceptance audit](../../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md).

## 6.3 Operational boundary

FEAT-057 owns feature engineering, training, calibration, validation and candidate-eligibility evidence. Scheduled/manual run orchestration, deployment/promotion execution, retained versions/rollback, lifecycle notifications and production drift monitoring remain owned by **V4-FEAT-056**.


## 6.5 Remaining before COMPLETE

FEAT-057 is **IMPLEMENTED**, but it is not yet **COMPLETE**. The following production acceptance work remains:

1. **Governed apply: complete.** Production run 5 applied 360/360 dates under parser 5 at a 93.4096% minimum mapping rate. The matching first boundary was idempotently preserved; run 4 remains unchanged. Verify details in the [FEAT-057 audit](../../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md).
2. **Pass current-build readiness.** The fresh cutoff 2026-10-01 campaign is queued against build 436, but the dedicated worker is inactive. Run its queued preflight using the supported worker; confirm point-in-time membership, breadth, sector and price coverage plus FEAT-054 fundamental gates pass for every horizon. Do not train on partial coverage or current-universe fallback.
3. **Produce production training and validation evidence.** After readiness passes, run the real-adapter 1m, 3m and 6m campaigns. Record dataset/profile identity, partition coverage, purge/embargo and preprocessing evidence, calibration, deterministic StoX baseline and active-model comparison (where an active model exists), candidate eligibility, archive integrity, artifact reload, and explainability results. Do not promote a candidate here; deployment and promotion remain FEAT-056 scope.
4. **Complete investor-facing acceptance.** Verify deployed scores and explanations match the pinned feature profile, expose provenance and meaningful drivers, and handle loading, unavailable and rejected states without stale data or current-universe fallback.
5. **Record disposition.** Update the [acceptance audit](../../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md) with evidence and exceptions. Mark FEAT-057 **COMPLETE** only after all applicable gates pass.

The bounded procedures and acceptance criteria are in the [functional acceptance plan](../../testing/V8-FEAT-057-FUNCTIONAL-TEST-PLAN.md).
## 7. V4-FEAT-061 — Guided Tour / Welcome Onboarding

### 7.1 Frozen status

The FEAT-061 architecture and product decisions are frozen and implementation-ready. The authoritative contract is:

[`V8-Guided-Tour-Welcome-Onboarding-Specification.md`](V8-Guided-Tour-Welcome-Onboarding-Specification.md)

The canonical specification defines Investor-only eligibility, early-login welcome prompting, manual relaunch, a fixed core multi-route journey, backend-persisted resume/restart state, explanatory-only spotlight behavior, bounded-wait target skipping, responsive/accessibility behavior, i18n and telemetry.

### 7.2 Audience boundary

The feature is enabled for **Investor users only**. Admin users do not receive the welcome prompt and do not get the manual relaunch entry.

### 7.3 Interaction boundary

The tour explains the real StoX interface but does not ask users to operate live product actions. Tour navigation drives route/menu changes; missing targets are skipped safely rather than blocking onboarding.

### 7.4 Implementation status (2026-10-05)

**IMPLEMENTED.** The deployed build includes the guided tour and temporary Developer options reset; local browser, API and accessibility suites passed. Production evidence covers persisted resume/Back/Next/Escape, Admin UI exclusion and reset denial, Investor body rejection and direct reset response. The [acceptance audit](../../audit/V8-FEAT-061-ACCEPTANCE-AUDIT.md) records the bounded evidence and restoration of two Investor tour states after a test-harness identity mistake. Full first-run, session, device and screen-reader journeys remain in the [functional test plan](../../testing/V8-FEAT-061-FUNCTIONAL-TEST-PLAN.md).

## 10. Boundary

V8 owns the StoX-side OpenTelemetry integration with the existing LidoTelemetry service: browser/backend/background instrumentation, trace propagation, explicit business telemetry, focused metrics, Collector export, privacy controls and fail-open behavior. V8 does **not** own LidoTelemetry product implementation.

V8 also owns the one-time historical fundamental bootstrap outcome, but does not change FEAT-053's ongoing provider-driven fundamental ingestion architecture.

V8 owns the account-access request workflow through Admin disposition and handoff into the existing secure user onboarding flow. It does **not** replace the existing invitation/token security model, establish open registration, or allow a guest request to grant any account privilege by itself.

V8 owns **FEAT-057 — ML Feature Engineering, Model Training & Validation** as the single feature-research/training boundary: fundamental, technical, market/regime, sector-relative and deterministic pattern features; PIT/coverage validation; feature selection; 1m/3m/6m retraining; repeated chronological validation; calibrated promotion eligibility; and comparison with the active model and deterministic StoX baseline. Former FEAT-058 and FEAT-059 are merged into FEAT-057.

V8 owns **FEAT-056 — ML Lifecycle Automation, Deployment & Operations** as the single operational ML boundary: scheduled/manual queued runs, lifecycle observability, Admin controls, candidate/deployment state, gated automatic/manual promotion, retained versions/rollback, drift/model-health visibility, informational lifecycle messaging and operational failure alerts. Former FEAT-060 is merged into FEAT-056.

V8 owns the Guided Tour / Welcome Onboarding experience: welcome eligibility/persistence, configuration-driven spotlight steps, shell coordination, responsive/accessibility behavior and onboarding telemetry. It does not replace setup workflows or long-form product documentation.

V8 FEAT-063, FEAT-065, and V9-DATA-002 have been retired from the active repository on 2026-10-06 by Product Owner direction. Their exclusive collectors, corpus/backfill surfaces, staging/delivery work, active specifications, and acceptance plans are removed. The shared ML training, feature engineering, production lifecycle, daily market data, and generic Parquet support remain governed by their owning active epics.
