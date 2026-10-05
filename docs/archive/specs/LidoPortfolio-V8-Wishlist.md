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

V8 also contains the one-time Historical Fundamental Data Bootstrap, a guest-to-admin **Account Access Request** workflow that preserves admin-controlled onboarding, a consolidated ML follow-on program, AI-assisted fundamental insight surfacing, a lightweight Investor guided tour, prospective live microstructure collection, Screener/Strategy workflow simplification, and an intraday historical ML data platform.

## 2. Current V8 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-052 | StoX OpenTelemetry / LidoTelemetry Integration | Instrument StoX React/browser, Laravel/backend, queues and scheduler with OpenTelemetry; propagate distributed trace context; add explicit StoX business telemetry and focused metrics; export through an OpenTelemetry Collector to the existing LidoTelemetry service; remain fail-open and privacy-safe. **Canonical implementation spec:** [`V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md`](V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md). | **IMPLEMENTED**; functional testing tracked in [FEAT-052 test plan](../../testing/V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | Complete the V7 fundamentals foundation with canonical fact-gap filling, curated derived metrics, official NSE/BSE historical backfill with Yahoo fallback, resumable queue-backed bootstrap, investor Fundamentals UI, historical charts and Screener eligibility integration. **Canonical implementation spec:** [`V8-Historical-Fundamentals-Bootstrap-Specification.md`](V8-Historical-Fundamentals-Bootstrap-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow with CAPTCHA and mandatory email verification, followed by Admin Create/Ignore/Reject review. Create reuses the existing secure invite flow; Ignore applies a configurable cooldown; Reject creates a reversible request ban. **Canonical implementation spec:** [`V8-Account-Access-Request-Admin-Approval-Specification.md`](V8-Account-Access-Request-Admin-Approval-Specification.md). | **IMPLEMENTED**; broader product testing in [FEAT-055 plan](../../testing/V8-FEAT-055-FUNCTIONAL-TEST-PLAN.md) |
| V4-FEAT-056 | ML Lifecycle Automation, Deployment & Operations | Consolidates former FEAT-056 + FEAT-060. Own the operational ML lifecycle: scheduled/manual/drift-triggered queued training, SSE progress, retries/cancellation, candidate lifecycle, explicit Admin promotion/rollback, bounded retained versions, production drift/health monitoring and actionable notifications. **Canonical implementation spec:** [`V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`](V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-057 | ML Feature Engineering, Model Training & Validation | Consolidates former FEAT-057 + FEAT-058 + FEAT-059. Define and validate the combined point-in-time ML feature space across fundamentals, technicals, market/regime/breadth, sector-relative context and deterministic patterns; perform coverage/redundancy selection; retrain 1m/3m/6m candidates; and evaluate them using repeated chronological validation, calibrated promotion criteria, active-model comparison and the deterministic StoX baseline. **Canonical implementation spec:** [`V8-ML-Feature-Engineering-Training-Validation-Specification.md`](V8-ML-Feature-Engineering-Training-Validation-Specification.md). | FROZEN / IMPLEMENTATION-READY / DEPENDS ON V4-FEAT-054 |
| V4-FEAT-058 | ML Technical & Market Feature Engineering | **Merged into V4-FEAT-057.** Historical ID retained for traceability; no separate implementation epic remains. | MERGED / RETIRED |
| V4-FEAT-059 | ML Promotion-Threshold Calibration & Multi-Window Validation | **Merged into V4-FEAT-057.** Historical ID retained for traceability; validation/calibration is part of the consolidated training epic. | MERGED / RETIRED |
| V4-FEAT-060 | ML Admin UI & Training Observability Refinement | **Merged into V4-FEAT-056.** Historical ID retained for traceability; Admin ML operations/observability is part of the consolidated lifecycle epic. | MERGED / RETIRED |
| V4-FEAT-061 | Guided Tour / Welcome Onboarding | Add an Investor-only, configuration-driven multi-route guided tour with early-login welcome prompting, backend-persisted progress, resume/restart, explanatory-only spotlight steps, safe missing-target skipping and manual relaunch from Help/Profile. **Canonical implementation spec:** [`V8-Guided-Tour-Welcome-Onboarding-Specification.md`](V8-Guided-Tour-Welcome-Onboarding-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-062 | Fundamental Signals & AI Insights Engine | Use deterministic financial calculations first and AI second to surface material positive, negative, unusual and unresolved fundamental observations without turning visible tables into generic prose. **Canonical implementation spec:** [`V8-Fundamental-Signals-AI-Insights-Specification.md`](V8-Fundamental-Signals-AI-Insights-Specification.md). | FROZEN / IMPLEMENTATION-READY / DEPENDS ON V4-FEAT-054 |
| V4-FEAT-063 | Live Microstructure Data Collection | Prospectively collect Kite full-mode live market microstructure for the current NIFTY 500, aggregate to a full 1-minute Parquet schema, preserve explicit coverage quality, operate reliably on the StoX VPS, expose bounded Admin controls/status, and back up validated daily partitions. **Canonical implementation spec:** [`V8-Live-Microstructure-Data-Collection-Specification.md`](V8-Live-Microstructure-Data-Collection-Specification.md). | FROZEN / IMPLEMENTATION-READY / HIGH PRIORITY; **REVIEW** (deployed; live acceptance open) |
| V4-FEAT-064 | Core Investor Workflow & UX Simplification — Screener and Strategy | Simplify normal Screener/Strategy authoring while preserving immutable historical provenance, private account-scoped instances, definition-copy sharing and multiple concurrent Strategies. **Canonical implementation spec:** [`V8-Core-Investor-Workflow-UX-Simplification.md`](V8-Core-Investor-Workflow-UX-Simplification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-065 | Intraday ML Historical Data Platform | Build the historical 1-minute OHLCV research corpus for the current NIFTY 500 using Kite historical data, schema-versioned Parquet on the MacBook, DuckDB and Polars/Python; hand PIT-safe data to FEAT-057 for research/training. **Canonical implementation spec:** [`V8-Intraday-ML-Historical-Data-Platform-Specification.md`](V8-Intraday-ML-Historical-Data-Platform-Specification.md). | FROZEN / IMPLEMENTATION-READY |

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

## 8. V4-FEAT-063 — Live Microstructure Data Collection

### 8.1 Frozen status

FEAT-063 is frozen and implementation-ready. The authoritative contract is:

[`V8-Live-Microstructure-Data-Collection-Specification.md`](V8-Live-Microstructure-Data-Collection-Specification.md)

The canonical specification defines the always-on VPS collector, Kite full-mode subscription, full one-minute aggregate schema, bounded raw-tick recovery spool, explicit partial-coverage metadata, NSE calendar awareness, mobile authentication flow, automatic recovery, Admin operational alerts and controls, local Parquet storage, post-finalization backup, manual-stop semantics, universe refresh and failure recovery.

### 8.2 Priority

FEAT-063 is a **high-priority early-V8 item** because prospective microstructure observations cannot be reconstructed later at comparable depth. Historical OHLCV owned by FEAT-065 can be backfilled later; missed Dataset C trading days cannot.

### 8.3 Boundary

FEAT-063 owns prospective collection and durable minute-level microstructure data. It does not own historical OHLCV backfill, feature selection, ML training/evaluation or production model lifecycle.

### 8.4 Implementation progress (2026-10-04)

**State: REVIEW.** The frozen scope above is unchanged. The following records implementation and acceptance progress, not a change to the contract.

| Completed and evidenced | Pending before COMPLETE |
|---|---|
| VPS collector previously received paid Kite FULL-mode ticks for 499 instruments; 2026-09-29 finalized 15,213 rows with matching primary and same-VPS backup-copy partitions and an empty raw spool. | Prove an independent secondary-storage backup and restore. The matching copy on the same VPS is not disaster recovery. |
| PR [#56](https://github.com/lido-alexion/LidoPortfolio/pull/56) added the lightweight authenticated `/kite-connect` page, dashboard status card, configured-collector-user authorization, official Zerodha redirect, 09:00 IST trading-day Telegram reminder, 09:20 missing-packet alert, corrected UTC token expiry and rejection of zero-row finalization success. Focused PHP, Python and frontend checks passed; PR CI passed. | On the next trading day, the configured StoX user (production ID 1) must complete Kite login and verify reminder/link delivery, callback, page/dashboard status, WebSocket/recent packets, positive-row finalization; inspect the October 1 zero-row evidence and verify zero-row rejection in a controlled check. An authenticated second-user denial and alert recovery also need live acceptance. Missed live ticks cannot be reconstructed from historical OHLCV. |
| Squash commit `6a843cd4220fe240a1ca0bde13620f4c43e81274` was deployed by [production workflow](https://github.com/lido-alexion/LidoPortfolio/actions/runs/37190925152); backend/frontend/package/deploy and post-deploy health checks passed. The VPS release path ends in `20261004091938-6a843cd4220f`; an unsigned browser request to `/kite-connect` redirected to `/login`, and effective reminder configuration read `09:00`. | Exercise deployed Admin controls, persistent hold, restart/reconnect/resubscription and retries; inspect normal 15:30 IST WebSocket close `1006`; monitor another complete trading day. Deployment and an anonymous redirect do not establish live login or collection acceptance. |

Evidence and exact runbook: [`V8-FEAT-063-ACCEPTANCE-AUDIT.md`](../../audit/V8-FEAT-063-ACCEPTANCE-AUDIT.md) and [`microstructure-collector-operations.md`](../../current/microstructure-collector-operations.md).

## 9. V4-FEAT-065 — Intraday ML Historical Data Platform

### 9.1 Frozen status

FEAT-065 is frozen and implementation-ready. The authoritative contract is:

[`V8-Intraday-ML-Historical-Data-Platform-Specification.md`](V8-Intraday-ML-Historical-Data-Platform-Specification.md)

The canonical specification defines the current-NIFTY-500 fixed historical universe, up to eight years of Kite 1-minute OHLCV, selected market/sector indices, resumable/idempotent historical backfill, schema-versioned Parquet on the MacBook, DuckDB/Polars analytical access, point-in-time safety, optional non-blocking derivative enrichment and explicit handoff to FEAT-057.

### 9.2 Workstation and production boundary

The MacBook is the primary historical-data/research machine and may also run heavy offline FEAT-057 training/validation work. Approved/versioned model artifacts can then be deployed to the StoX VPS for production inference and FEAT-056 lifecycle management; the full historical corpus does not need to live on the VPS.

### 9.3 Backup boundary

FEAT-065 implements no automated backup system. The user will periodically copy the canonical Parquet corpus and essential metadata to an external disk manually. Temporary/reproducible working datasets need not be backed up.

## 10. Boundary

V8 owns the StoX-side OpenTelemetry integration with the existing LidoTelemetry service: browser/backend/background instrumentation, trace propagation, explicit business telemetry, focused metrics, Collector export, privacy controls and fail-open behavior. V8 does **not** own LidoTelemetry product implementation.

V8 also owns the one-time historical fundamental bootstrap outcome, but does not change FEAT-053's ongoing provider-driven fundamental ingestion architecture.

V8 owns the account-access request workflow through Admin disposition and handoff into the existing secure user onboarding flow. It does **not** replace the existing invitation/token security model, establish open registration, or allow a guest request to grant any account privilege by itself.

V8 owns **FEAT-057 — ML Feature Engineering, Model Training & Validation** as the single feature-research/training boundary: fundamental, technical, market/regime, sector-relative and deterministic pattern features; PIT/coverage validation; feature selection; 1m/3m/6m retraining; repeated chronological validation; calibrated promotion eligibility; and comparison with the active model and deterministic StoX baseline. Former FEAT-058 and FEAT-059 are merged into FEAT-057.

V8 owns **FEAT-056 — ML Lifecycle Automation, Deployment & Operations** as the single operational ML boundary: scheduled/manual queued runs, lifecycle observability, Admin controls, candidate/deployment state, gated automatic/manual promotion, retained versions/rollback, drift/model-health visibility, informational lifecycle messaging and operational failure alerts. Former FEAT-060 is merged into FEAT-056.

V8 owns the Guided Tour / Welcome Onboarding experience: welcome eligibility/persistence, configuration-driven spotlight steps, shell coordination, responsive/accessibility behavior and onboarding telemetry. It does not replace setup workflows or long-form product documentation.

V8 owns **FEAT-063 — Live Microstructure Data Collection** as the prospective market-data collection boundary: reliable Kite full-mode collection, minute aggregation, quality metadata, local Parquet persistence, operational controls/alerts, and daily backup. Historical 1-minute OHLCV backfill remains FEAT-065.

V8 owns **FEAT-065 — Intraday ML Historical Data Platform** as the historical/offline intraday-data boundary: Kite historical acquisition, current-NIFTY-500 fixed-universe 1-minute OHLCV, selected indices, quality/provenance, schema-versioned Parquet storage on the MacBook, resumable backfill and DuckDB/Polars access. Feature engineering/model training/validation belong to FEAT-057; production model lifecycle belongs to FEAT-056; prospective microstructure collection belongs to FEAT-063.