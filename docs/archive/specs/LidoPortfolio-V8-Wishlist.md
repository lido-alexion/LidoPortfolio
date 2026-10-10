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
| V4-FEAT-052 | StoX OpenTelemetry / LidoTelemetry Integration | Instrument StoX React/browser, Laravel/backend, queues and scheduler with OpenTelemetry; propagate distributed trace context; add explicit StoX business telemetry and focused metrics; export through an OpenTelemetry Collector to the existing LidoTelemetry service; remain fail-open and privacy-safe. **Canonical implementation spec:** [`V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md`](V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md). | **ON HOLD** — A lighter telemetry version or Amplitude integration is in plan for future. Existing implementation and open acceptance evidence are documented in the [FEAT-052 test plan](../../testing/V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md); the 2026-10-06 passive 10-minute delivery sample showed zero queued/refused spans and no increase in the cumulative failed-span counter, but its **78,274** failures remain unexplained and no exact trace was correlated to the sink during that window. |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | Complete the V7 fundamentals foundation with canonical fact-gap filling, curated derived metrics, official NSE/BSE historical backfill with Yahoo fallback, resumable queue-backed bootstrap, investor Fundamentals UI, historical charts and Screener eligibility integration. **Canonical implementation spec:** [`V8-Historical-Fundamentals-Bootstrap-Specification.md`](V8-Historical-Fundamentals-Bootstrap-Specification.md). | **IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN**; PO-accepted stock/field coverage criteria passed on the 2026-10-05 persisted-fact snapshot and 2026-10-07 production-cache reconciliation (4,696/5,148 overall; 497/501 cached Nifty symbols with current facts; all 25 non-exempt fields pass). The PO accepted the cached constituent view under the relaxed 98% gate; no independent official constituent refresh was performed. Broader provider/deployed-runtime acceptance remains open; see [FEAT-054 audit](../../audit/V8-FEAT-054-ACCEPTANCE-AUDIT.md). |
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow with CAPTCHA and mandatory email verification, followed by Admin Create/Ignore/Reject review. Create reuses the existing secure invite flow; Ignore applies a configurable cooldown; Reject creates a reversible request ban. **Canonical implementation spec:** [`V8-Account-Access-Request-Admin-Approval-Specification.md`](V8-Account-Access-Request-Admin-Approval-Specification.md). | **IMPLEMENTED**; broader product testing in [FEAT-055 plan](../../testing/V8-FEAT-055-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-056 | ML Lifecycle Automation, Deployment & Operations | Consolidates former FEAT-056 + FEAT-060. Own the operational ML lifecycle: scheduled/manual/drift-triggered queued training, SSE progress, retries/cancellation, candidate lifecycle, explicit Admin promotion/rollback, bounded retained versions, production drift/health monitoring and actionable notifications. **Canonical implementation spec:** [`V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`](V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md). | **IMPLEMENTED**; deployed lifecycle acceptance remains open and deferred until FEAT-057 qualification and a dedicated controlled acceptance worker are available (see exact closure steps below and [FEAT-056 audit](../../audit/V8-FEAT-056-ACCEPTANCE-AUDIT.md)) |
| V4-FEAT-057 | ML Feature Engineering, Model Training & Validation | Consolidates former FEAT-057 + FEAT-058 + FEAT-059. Define and validate the combined point-in-time ML feature space across fundamentals, technicals, market/regime/breadth, sector-relative context and deterministic patterns; perform coverage/redundancy selection; retrain 1m/3m/6m candidates; and evaluate them using repeated chronological validation, calibrated promotion criteria, active-model comparison and the deterministic StoX baseline. **Canonical implementation spec:** [`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md). | **IMPLEMENTED**; production acceptance tracked in [FEAT-057 plan](../../testing/V8-FEAT-057-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-058 | ML Technical & Market Feature Engineering | **Merged into V4-FEAT-057.** Historical ID retained for traceability; no separate implementation epic remains. | MERGED / RETIRED |
| V4-FEAT-059 | ML Promotion-Threshold Calibration & Multi-Window Validation | **Merged into V4-FEAT-057.** Historical ID retained for traceability; validation/calibration is part of the consolidated training epic. | MERGED / RETIRED |
| V4-FEAT-060 | ML Admin UI & Training Observability Refinement | **Merged into V4-FEAT-056.** Historical ID retained for traceability; Admin ML operations/observability is part of the consolidated lifecycle epic. | MERGED / RETIRED |
| V4-FEAT-061 | Guided Tour / Welcome Onboarding | Add an Investor-only, configuration-driven multi-route guided tour with early-login welcome prompting, backend-persisted progress, resume/restart, explanatory-only spotlight steps, safe missing-target skipping and manual relaunch from Help/Profile. **Canonical implementation spec:** [`V8-Guided-Tour-Welcome-Onboarding-Specification.md`](V8-Guided-Tour-Welcome-Onboarding-Specification.md). | **IMPLEMENTED**; broader testing in [FEAT-061 plan](../../testing/V8-FEAT-061-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-062 | Fundamental Signals & AI Insights Engine | Use deterministic financial calculations first and AI second to surface material positive, negative, unusual and unresolved fundamental observations without turning visible tables into generic prose. **Canonical implementation spec:** [`V8-Fundamental-Signals-AI-Insights-Specification.md`](V8-Fundamental-Signals-AI-Insights-Specification.md). | **IMPLEMENTED**; real-provider and native screen-reader/device acceptance remains open (see [FEAT-062 audit](../../audit/V8-FEAT-062-ACCEPTANCE-AUDIT.md)) |
| V4-FEAT-064 | Core Investor Workflow & UX Simplification — Screener and Strategy | Simplify normal Screener/Strategy authoring while preserving immutable historical provenance, private account-scoped instances, definition-copy sharing and multiple concurrent Strategies. **Canonical implementation spec:** [`V8-Core-Investor-Workflow-UX-Simplification.md`](V8-Core-Investor-Workflow-UX-Simplification.md). | **IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN**; fail-closed exact-pin behavior and copy-on-write adoption shipped (PRs #72, #122, #123); production snapshots and four active binding pins reconciled on build 493. Remaining two-account, controlled membership-drift, and deployed assistive-technology checks are tracked in the [acceptance audit](../../audit/V8-FEAT-064-ACCEPTANCE-AUDIT.md). |

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

### 3.4 Implementation status and product disposition (updated 2026-10-09)

**ON HOLD — A lighter telemetry version or Amplitude integration is in plan for future.** Existing implementation evidence covers browser → HTTP parentage, database queue propagation, a natural cron root and child tasks, metrics receipt, and deployed Collector/producer privacy mitigations. The remaining broader privacy, actual asynchronous/business outage, sustained-delivery scenarios, and unexplained **78,274** failed spans remain acceptance evidence to resolve only if this epic is resumed. See the [acceptance audit](../../audit/V8-FEAT-052-ACCEPTANCE-AUDIT.md) and [functional test plan](../../testing/V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md).

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


### 5.4 Implementation status and remaining acceptance (updated 2026-10-09)

**Implementation state: IMPLEMENTED. Functional acceptance: OPEN and deferred until FEAT-057 qualifies and a dedicated controlled acceptance worker is available.** Local lifecycle implementation and feature-specific backend CI are complete. The CI-parity gate passed 2,242 PHPUnit tests (14,962 assertions; 2 skipped), migration portability for 172 migrations, Python checks (22 tests; 8 skipped), and the 219-operation OpenAPI check on commit `d1279fb74b56a2cf488ba82f7c305cb58b424f24`. See the [FEAT-056 audit](../../audit/V8-FEAT-056-ACCEPTANCE-AUDIT.md) for the acceptance disposition.

**Runtime checkpoint:** FEAT-057 production run 5 applied all 360 dates with a 93.4096% minimum mapping rate; run 4’s partial result and valid first-date boundary were preserved. The FEAT-057/FEAT-056 acceptance campaign `ee93e3a6-5740-4dea-bfef-c109abc90a69`, created on build 436 for cutoff 2026-10-01, did not complete preflight. After its worker was restarted on 2026-10-06, the job completed in under a second and correctly marked the campaign **failed** because its captured identity no longer matched production. The campaign captured build/commit `build-436-attempt-1-15037d9360189694f98cf5147a6656262f89961f`; the current runtime was build/commit `build-442-attempt-1-38107807be3293139732b71f397d106ee6e1ef1d`. The configuration fingerprint also changed; registry and adapter hash did not. Active model IDs and artifact hashes were unchanged. The campaign has no horizon results and must not be resumed or edited. The dedicated `ml-acceptance` worker is active and isolated; lifecycle schedules, global lifecycle, drift triggers and retention remain disabled/untouched.

Because active development is causing frequent deployments, **defer new acceptance campaigns and all runtime lifecycle tests until the production build and configuration have settled**. A campaign identity change during preflight invalidates that campaign; do not try to preserve or bypass its identity guard.

**Exact path to COMPLETE:**

1. **Wait for a stable production window.** After the other feature deployments are complete, confirm with the PO that no deployment/configuration change is planned during the acceptance window. Record the deployed build ID, commit SHA, registry version, configuration fingerprint and adapter hash. Confirm they match the live release and remain unchanged before starting the campaign.
2. **Recheck FEAT-057 data readiness.** Confirm run 5 still has 360/360 applied dates and the 93.4096% minimum mapping floor, and that its immutable first-date boundary still matches run 4. Do not alter either run. Review the current FEAT-057 readiness gates and confirm the cutoff 2026-10-01 remains valid.
3. **Start a fresh governed preflight on the stable build.** Use the authenticated Admin acceptance flow and the dedicated `ml-acceptance` queue. Create a new campaign for cutoff 2026-10-01 and record its ID and full runtime identity. Do **not** reuse campaign `ee93e3a6-5740-4dea-bfef-c109abc90a69`; it is failed and immutable. Verify the isolated worker is active and the queue is not carrying unrelated work. If the build or configuration changes, let the identity guard fail the campaign and wait for stability again; do not bypass the guard.
4. **Complete FEAT-057 qualification before FEAT-056 lifecycle acceptance.** Require a passed preflight for 1m, 3m and 6m; then run the governed real-adapter training/validation campaigns for all three horizons. Record point-in-time snapshots and coverage, dataset/profile hashes, partition coverage, chronological folds and purge/embargo, calibration, StoX baseline and active-model comparison, candidate eligibility, artifact checksum/reload, explainability and investor-facing behavior. Keep promotion out of this step. Update the [FEAT-057 audit](../../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md) and its plan before using the result as FEAT-056’s prerequisite.
5. **Run bounded FEAT-056 operational acceptance only after FEAT-057 qualifies.** Keep global automation and schedules disabled unless a separate explicit PO decision authorizes a controlled test. Through supported Admin/worker paths, verify manual and scheduled queue creation as permitted by the frozen spec, one active run per horizon, durable progress/SSE reconnect, cancellation of queued and running work, bounded retry, worker restart and stale-run recovery, and actionable notification delivery. Record run IDs, states, timestamps, logs and notification evidence; leave lifecycle configuration in its prior state.
6. **Verify safeguards with a non-active candidate/control.** Check artifact integrity and retention behavior without deleting or pruning the active artifact. Verify promotion/rollback review and audit controls using a safe candidate/control procedure; do not perform a production model activation or rollback solely as a test. Preserve exactly one active model and confirm all state afterward.
7. **Close with evidence.** Update the [FEAT-056 acceptance audit](../../audit/V8-FEAT-056-ACCEPTANCE-AUDIT.md) with stable build/config identity, FEAT-057 qualification reference, worker/queue evidence, each test result, artifact/notification evidence, and configuration restoration. Mark FEAT-056 **COMPLETE** only when all required checks pass. Keep lifecycle enablement as a separate explicit decision.

The operational procedures are in the [FEAT-056 runbook](../../current/ml-lifecycle-operations.md).
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

Production run 4 remains an immutable partial run (1/360 dates). Fresh governed run 5 completed the full 360-date apply under parser 5, with a 93.4096% minimum mapping rate; its first-date snapshot and membership digests match run 4's valid boundary. Campaign `ee93e3a6-5740-4dea-bfef-c109abc90a69` was created on build 436 for cutoff 2026-10-01 and is now **failed** because production identity changed to build 442 before preflight. It has no horizon results and must not be resumed; create a new governed campaign only after deployment/configuration stabilizes. Post-apply readiness, production 1m/3m/6m evidence and investor-facing explainability checks remain open. See the [functional acceptance plan](../../testing/V8-FEAT-057-FUNCTIONAL-TEST-PLAN.md) and [acceptance audit](../../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md).

## 6.3 Operational boundary

FEAT-057 owns feature engineering, training, calibration, validation and candidate-eligibility evidence. Scheduled/manual run orchestration, deployment/promotion execution, retained versions/rollback, lifecycle notifications and production drift monitoring remain owned by **V4-FEAT-056**.


## 6.5 Remaining before COMPLETE

FEAT-057 is **IMPLEMENTED**, but it is not yet **COMPLETE**. The following production acceptance work remains:

1. **Governed apply: complete.** Production run 5 applied 360/360 dates under parser 5 at a 93.4096% minimum mapping rate. The matching first boundary was idempotently preserved; run 4 remains unchanged. Verify details in the [FEAT-057 audit](../../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md).
2. **Pass current-build readiness.** The prior cutoff 2026-10-01 campaign on build 436 is failed after the production identity changed to build 442; it has no horizon evidence and must not be resumed. After deployments settle, create a fresh governed campaign on the stable current build using the supported worker. Confirm point-in-time membership, breadth, sector and price coverage plus FEAT-054 fundamental gates pass for every horizon. Do not train on partial coverage or current-universe fallback.
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

## 8. V4-FEAT-064 — Core Investor Workflow & UX Simplification

**Current disposition: IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN.** Implementation and production provenance adoption are complete. Broader deployed workflow checks remain tracked separately and do not block the implemented disposition.

The canonical implementation spec is [V8-Core-Investor-Workflow-UX-Simplification.md](V8-Core-Investor-Workflow-UX-Simplification.md). Local implementation, CI, browser evidence, production snapshot reconciliation, exact-pin adoption, and runtime proof are recorded in the [FEAT-064 acceptance audit](../../audit/V8-FEAT-064-ACCEPTANCE-AUDIT.md). Remaining checks are listed in the [FEAT-064 test plan](../../testing/V8-FEAT-064-FUNCTIONAL-TEST-PLAN.md).

### Completed implementation and production closure steps

1. PR #72 added and merged fail-closed exact-pin behavior, copy-on-write Strategy adoption, and regression coverage.
2. PRs #122 and #123 added guarded Screener snapshot recovery and corrected the Artisan option to --target-version; both are merged. PR #123 CI passed and production build 493 (commit b06f3fa7001f2848867de53dba60a92c15776f75) is live with no pending migrations.
3. Screener 4 v2 is immutable row 10, hash sha256:d3edf5394abd4066e1e894e73abbbe55057c69e0f0ee3915cf116028ffc9bae4, proven by exact immutable v3 row 9; v1 row 4 remains intact. Screener 5 v2 is immutable row 11, semantic hash sha256:092a4e0ad9d00d5796f0e97de6d4760e2e7db6c59e30c8ab42cf35a542271e02, proven by same-lineage published artifact version 39; v1 row 5 remains intact. The prior sha256:59d3e174bc606b42e291ff06c24301de334356d5175a9a74a5ab7b59245d6cdb was an artifact-envelope hash, not the semantic definition hash.
4. Supported binding upgrades created revisions 57, 58, 59, and 60 for bindings 42, 43, 44, and 47. Active Strategy versions 34, 35, 36, and 37 now pin respectively to Screener 4 v3 row 9, Screener 5 v2 row 11, Screener 4 v3 row 9, and Screener 4 v3 row 9. Earlier immutable Strategy version configuration and hashes, Screener v1 rows, and historical run/recommendation/transaction provenance were preserved.
5. Production readiness passed for all four active Strategy versions; exact-pinned Screener runs fed all four active Strategies, and controlled production decision-pipeline run 57 published 392 recommendations against the exact pins. No transaction or order was created; evidence is in the acceptance audit.

### Remaining functional acceptance

Keep the implemented disposition while tracking these broader checks as open: two-account privacy and definition-copy workflow; controlled membership-drift behavior against the deployed universe provider; and deployed-device keyboard/screen-reader acceptance. Do not change real holdings or create unintended trades.

Update the audit and this register with results when those checks are completed. Do not downgrade implementation status solely because broader functional acceptance remains open.
## 10. Boundary

V8 owns the StoX-side OpenTelemetry integration with the existing LidoTelemetry service: browser/backend/background instrumentation, trace propagation, explicit business telemetry, focused metrics, Collector export, privacy controls and fail-open behavior. V8 does **not** own LidoTelemetry product implementation.

V8 also owns the one-time historical fundamental bootstrap outcome, but does not change FEAT-053's ongoing provider-driven fundamental ingestion architecture.

V8 owns the account-access request workflow through Admin disposition and handoff into the existing secure user onboarding flow. It does **not** replace the existing invitation/token security model, establish open registration, or allow a guest request to grant any account privilege by itself.

V8 owns **FEAT-057 — ML Feature Engineering, Model Training & Validation** as the single feature-research/training boundary: fundamental, technical, market/regime, sector-relative and deterministic pattern features; PIT/coverage validation; feature selection; 1m/3m/6m retraining; repeated chronological validation; calibrated promotion eligibility; and comparison with the active model and deterministic StoX baseline. Former FEAT-058 and FEAT-059 are merged into FEAT-057.

V8 owns **FEAT-056 — ML Lifecycle Automation, Deployment & Operations** as the single operational ML boundary: scheduled/manual queued runs, lifecycle observability, Admin controls, candidate/deployment state, gated automatic/manual promotion, retained versions/rollback, drift/model-health visibility, informational lifecycle messaging and operational failure alerts. Former FEAT-060 is merged into FEAT-056.

V8 owns the Guided Tour / Welcome Onboarding experience: welcome eligibility/persistence, configuration-driven spotlight steps, shell coordination, responsive/accessibility behavior and onboarding telemetry. It does not replace setup workflows or long-form product documentation.

V8 FEAT-063, FEAT-065, and V9-DATA-002 have been retired from the active repository on 2026-10-06 by Product Owner direction. Their exclusive collectors, corpus/backfill surfaces, staging/delivery work, active specifications, and acceptance plans are removed. The shared ML training, feature engineering, production lifecycle, daily market data, and generic Parquet support remain governed by their owning active epics.
