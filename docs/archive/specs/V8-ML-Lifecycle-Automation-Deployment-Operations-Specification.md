# StoX V8 ML Lifecycle Automation, Deployment & Operations Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-056 — ML Lifecycle Automation, Deployment & Operations |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Canonical path** | `docs/archive/specs/V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md` |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` |
| **Training/validation contract** | V4-FEAT-057 — ML Feature Engineering, Model Training & Validation |
| **Absorbs** | Former V4-FEAT-060 — ML Admin UI & Training Observability Refinement |
| **Primary implementation agent** | Codex |

---

## 1. Purpose

V4-FEAT-056 operationalizes the StoX ML lifecycle so routine retraining, candidate evaluation, review, promotion, rollback and production health visibility do not depend on shell access or manual orchestration.

The epic builds on the V7 lifecycle primitives already present: Admin-triggered training, explicit promotion, one active model per horizon, retained versions and rollback. V8 adds safe automation, persistent run orchestration, live progress, scheduling, retries, drift-triggered early retraining, promotion review, bounded retention, cancellation and action-oriented notifications.

FEAT-056 does **not** redefine feature engineering, model families, validation metrics, calibration or promotion-eligibility rules. Those are owned by the frozen FEAT-057 contract.

Canonical lifecycle:

```text
scheduled / manual / drift trigger
    -> same-horizon lock
    -> queued training run
    -> dataset/training/validation/calibration/evaluation
    -> FEAT-057 eligibility evidence
        -> rejected: retain current active model
        -> eligible: await explicit Admin promotion
    -> Admin promotion review + confirmation
    -> active model changes
    -> retained model history
    -> optional manual rollback
    -> production drift/health monitoring
```

StoX remains a personal-grade application. Prefer reliable, auditable mechanisms over enterprise workflow complexity.

---

## 2. Scope

### 2.1 In scope

- independently configurable scheduled retraining for 1m, 3m and 6m;
- bounded Admin schedule controls rather than free-form cron;
- manual retraining through the same canonical path;
- drift-triggered early retraining;
- persistent queued/background execution;
- one active/queued run per horizon;
- durable lifecycle/stage/progress state;
- SSE live progress for Admin;
- bounded retry/backoff for transient operational failures;
- safe cancellation at queue/run checkpoints;
- candidate eligibility state from FEAT-057 evidence;
- explicit manual promotion only;
- promotion review panel with evidence summary;
- explicit manual rollback only;
- bounded retained model artifacts/history;
- stale/superseded candidate handling;
- production model-health/drift visibility;
- action-oriented notifications;
- auditable run, promotion, rollback, cancellation and schedule history.

### 2.2 Out of scope

- automatic promotion of passing candidates;
- automatic rollback;
- unrestricted concurrent training;
- custom cron expression editing;
- ML-specific RBAC beyond existing Admin authorization;
- feature engineering/model-selection logic owned by FEAT-057;
- investor-facing ML research UI owned by FEAT-057;
- portfolio/strategy automation based on ML;
- enterprise approval chains or multi-person promotion workflow.

---

## 3. Frozen product decisions

| Decision | Frozen choice |
|---|---|
| 056-01 | Scheduled training/evaluation automated; production promotion remains explicit Admin action. |
| 056-02 | Retraining schedule configurable per horizon from bounded supported cadence options. |
| 056-03 | Transient operational failures receive limited automatic retries with bounded backoff and Admin escalation after exhaustion. |
| 056-04 | Drift may trigger early retraining; resulting candidate still requires full FEAT-057 validation and manual promotion. |
| 056-05 | Admin training progress delivered using SSE. |
| 056-06 | Retain active model plus a bounded recent rollback-capable history per horizon; older large artifacts may be pruned while retaining useful metadata/evidence. |
| 056-07 | Rollback is manual only. |
| 056-08 | Promotion uses an evidence-summary review panel and explicit confirmation. |
| 056-09 | Full lifecycle history remains in Admin UI; external notifications are reserved for actionable events. |
| 056-10 | Any existing StoX Admin may promote or roll back; no new ML-specific permission layer. |
| 056-11 | Admin may cancel queued runs immediately and running runs at safe checkpoints. |
| 056-12 | Eligible candidates remain promotable only until superseded or stale. |
| 056-13 | One queued/running training run per horizon regardless of scheduled/manual/drift trigger source. |

---

## 4. Ownership boundary with FEAT-057

### 4.1 FEAT-057 owns

- feature registry and feature-set versions;
- PIT-safe dataset semantics;
- model-family and hyperparameter candidates;
- classifier/regressor training contract;
- probability calibration;
- repeated chronological validation;
- promotion thresholds and eligibility evidence;
- active-model and deterministic-baseline comparison evidence;
- training-time drift reference baselines.

### 4.2 FEAT-056 owns

- when training is triggered;
- queue/run orchestration;
- progress/status persistence and delivery;
- operational retries and cancellation;
- candidate lifecycle state around the FEAT-057 result;
- Admin review and promotion execution;
- rollback execution;
- retained versions/artifact pruning;
- live drift/health monitoring against FEAT-057 baselines;
- lifecycle notifications and audit history.

FEAT-056 MUST consume FEAT-057 evidence rather than reimplement or weaken FEAT-057 gates.

---

## 5. Trigger model

Every run SHALL have exactly one trigger type:

- `scheduled`
- `manual`
- `drift`

All triggers enter the same canonical queue/training/evaluation path.

Trigger-specific behavior must not create separate training semantics.

### 5.1 Scheduled trigger

Each horizon has independently configurable scheduling:

- enabled/disabled;
- cadence selected from product-supported options;
- next scheduled execution visible;
- last scheduled execution visible.

Do not expose raw cron expressions.

Reasonable initial supported cadence families may include weekly, fortnightly, monthly and quarterly where appropriate. Exact defaults/options are implementation configuration and may differ by horizon.

### 5.2 Manual trigger

Admin may request retraining at any time unless the same horizon already has a queued/running run.

A manual trigger does not bypass:

- same-horizon locking;
- FEAT-057 validation;
- promotion eligibility;
- manual promotion requirement.

### 5.3 Drift trigger

Production drift monitoring may enqueue an early run when configured material thresholds are crossed.

Drift trigger requirements:

- debounce/cooldown repeated triggers;
- do not enqueue if same horizon already has a run;
- record the triggering drift evidence;
- never auto-promote the resulting candidate.

---

## 6. Persistent training-run lifecycle

Extend/reuse the existing ML training-run domain model rather than create an unrelated lifecycle store.

Minimum lifecycle states:

```text
queued
running
cancelling
cancelled
completed_eligible
completed_rejected
failed
```

Implementation may preserve compatible existing state names where practical, but external semantics must remain clear.

Minimum persisted run metadata:

- run ID;
- horizon;
- trigger type;
- requested/scheduled timestamp;
- triggering Admin where applicable;
- queue/start/finish timestamps;
- current stage;
- stage progress/message;
- retry attempt/count;
- cancellation request/actor/time;
- failure classification/code/message;
- dataset cutoff/version;
- feature-set version;
- produced model-version ID(s);
- FEAT-057 eligibility result;
- drift trigger context where applicable.

Run state must survive process restart/deploy.

---

## 7. Run stages and progress

At minimum expose these logical stages:

1. queued;
2. preparing dataset;
3. feature/preprocessing preparation;
4. training candidates;
5. validating;
6. calibrating;
7. evaluating FEAT-057 gates;
8. persisting artifacts/evidence;
9. completed / rejected / failed / cancelled.

Where multiple model-family challengers are evaluated, progress may include current candidate/family information without exposing low-value implementation noise.

Progress must be persistently queryable even if SSE is disconnected.

---

## 8. SSE progress delivery

Admin ML UI SHALL support SSE for near-real-time run updates.

SSE is presentation transport, not authoritative state.

Requirements:

- reconnect safely;
- client can always recover current state through ordinary REST/read endpoint;
- event payloads reference durable run IDs and state/stage;
- disconnect does not affect the training job;
- no WebSocket dependency is required;
- authorization enforced server-side for SSE connection.

Recommended event classes:

- run state changed;
- stage/progress changed;
- retry scheduled;
- cancellation acknowledged;
- run completed eligible;
- run completed rejected;
- run failed.

---

## 9. Same-horizon concurrency lock

There SHALL be at most one queued/running/cancelling training run per horizon.

This applies across all trigger sources.

Examples:

- scheduled 3m running -> manual 3m request rejected with clear conflict;
- drift 1m queued -> scheduled 1m occurrence does not enqueue duplicate work;
- 1m running and 6m running concurrently may be permitted if infrastructure capacity allows.

Locking must be database/distributed-safe rather than frontend-only.

---

## 10. Retry and failure handling

### 10.1 Retryable operational failures

Use bounded retry with backoff for failures reasonably considered transient, for example:

- temporary database/network failures;
- transient process/provider/runtime availability errors;
- queue-worker interruption where safe to retry.

Exact retry count/backoff is implementation configuration and must remain bounded.

### 10.2 Non-retryable outcomes

Do not retry merely because:

- FEAT-057 promotion gates failed;
- candidate underperformed the active model;
- candidate underperformed deterministic baseline evidence;
- validation produced a normal rejection.

A quality rejection is a successful lifecycle outcome: `completed_rejected`.

### 10.3 Exhaustion

After retry budget exhaustion:

- mark run failed;
- persist root/last failure information;
- retain current active model untouched;
- issue actionable Admin alert.

---

## 11. Cancellation

Admin may request cancellation.

### 11.1 Queued run

Cancel immediately before execution.

### 11.2 Running run

Cancellation is cooperative/best-effort at safe checkpoints.

Workers SHALL periodically inspect cancellation state between expensive phases/batches.

Rules:

- do not abruptly corrupt artifact/model persistence;
- partial/incomplete model artifacts cannot become eligible/promotable;
- final state becomes `cancelled`;
- cancellation reason/actor/time is audited;
- active production model is never affected.

---

## 12. Candidate lifecycle and freshness

A completed candidate is either:

- `eligible` according to FEAT-057 evidence; or
- `rejected`.

Eligible does not mean active.

### 12.1 Supersession

A candidate may be superseded by a newer valid candidate for the same horizon according to deterministic product rules.

The system SHALL make it clear which candidate is currently promotable.

### 12.2 Staleness

Promotion eligibility expires after a bounded freshness period.

Exact freshness duration may be horizon-specific and configuration-driven.

A stale candidate:

- remains inspectable;
- retains evidence/history;
- cannot be promoted;
- requires a new training run.

---

## 13. Promotion

Promotion is always explicit Admin action in V8.

No scheduled, drift-triggered or manual training run may automatically change the active model.

### 13.1 Promotion review panel

Before confirmation, show at minimum:

- horizon;
- candidate version/model family;
- feature-set version;
- training cutoff;
- candidate freshness status;
- primary FEAT-057 gate summary;
- candidate vs current active model;
- candidate vs deterministic StoX baseline;
- repeated-window/stability summary;
- calibration summary;
- secondary return-regression evidence;
- material warnings/limitations;
- current active model identity.

Avoid requiring Admin to inspect raw logs to make the promotion decision.

### 13.2 Promotion transaction

Promotion MUST be atomic/idempotent enough to guarantee one active model per horizon.

On success:

- previous active becomes retained rollback candidate;
- selected candidate becomes active;
- promotion actor/time/reason or optional note is recorded;
- relevant caches/runtime references are refreshed safely;
- promotion completion may produce an external informational notification.

A stale, rejected, cancelled, failed, incomplete or superseded candidate MUST NOT be promotable.

---

## 14. Rollback

Rollback is explicit Admin action only.

Admin selects one of the retained rollback-capable versions for the same horizon and confirms.

Requirements:

- show target version and current active version;
- verify retained artifact integrity before switch;
- switch atomically;
- record actor/time/versions;
- keep the displaced version in history subject to retention rules;
- notify on successful rollback where useful.

Drift/health deterioration may recommend or alert for rollback but MUST NOT execute rollback automatically.

---

## 15. Model retention

For each horizon always retain:

- current active model artifact;
- bounded recent promoted/rollback-capable model artifacts.

Keep lightweight historical metadata/evaluation evidence longer than large model artifacts where useful.

Implementation SHALL define configurable retention counts suitable for StoX's storage footprint.

Pruning requirements:

- never delete active artifact;
- never delete artifact currently targeted by an in-flight promotion/rollback operation;
- mark historical records when artifact has been pruned;
- audit/record pruning where appropriate;
- artifact integrity hash/provenance remains available for retained artifacts.

---

## 16. Production drift and model health

FEAT-057 supplies frozen training reference baselines. FEAT-056 monitors production behavior against them.

Initial monitoring should cover available baselines such as:

- feature distributions;
- feature missingness;
- score/probability distributions;
- class/outcome prevalence once observable;
- sector mix;
- regime mix.

Health states should remain simple, e.g.:

- healthy;
- warning;
- material drift / attention needed.

Thresholds, minimum sample sizes and cooldowns are versioned configuration.

Material drift may enqueue early retraining but cannot promote/rollback automatically.

---

## 17. Admin ML operations surface

The existing Admin ML area SHALL evolve into a single operational lifecycle surface.

Per horizon show at minimum:

- active model/version;
- latest candidate and status;
- schedule enabled/disabled;
- configured cadence;
- next scheduled run;
- latest scheduled/manual/drift runs;
- current run stage/progress;
- current retry/cancellation state;
- latest eligibility/rejection result;
- drift/health status;
- retained rollback-capable versions.

### 17.1 Run history

Provide recent runs with filters/detail for:

- horizon;
- trigger type;
- lifecycle state;
- date/time;
- duration;
- failure/rejection summary.

### 17.2 Controls

Admin controls:

- enable/disable schedule;
- select supported cadence;
- start manual retraining;
- cancel queued/running run;
- inspect evidence;
- promote eligible candidate;
- roll back to retained version.

No separate ML-specific role is introduced. Existing Admin authorization applies.

---

## 18. Notification policy

The Admin UI is the authoritative full lifecycle history.

External notification channels SHOULD be used when action or awareness is useful, especially:

- operational failure after retries exhausted;
- eligible candidate waiting for promotion;
- material production drift/model-health warning;
- successful promotion;
- successful rollback.

Do not create external notification noise for ordinary events such as:

- every scheduled start;
- routine stage transitions;
- normal quality rejection.

Notification delivery failure must not alter training/model lifecycle state.

Use the existing StoX notification architecture and its configured channels rather than introducing an ML-only notification subsystem.

---

## 19. Auditability

Persist an auditable record of:

- schedule changes;
- manual training requests;
- drift-trigger evidence;
- retries;
- cancellation request/completion;
- candidate eligibility/rejection;
- promotion;
- rollback;
- artifact pruning where material.

Admin actor identity is required for Admin-originated actions.

Automatic actions record their trigger source and relevant system context.

---

## 20. Recovery and restart behavior

The lifecycle must tolerate web/worker restart/deploy without losing authoritative state.

Implementation SHALL:

- persist run state before/around major stages;
- detect stale `running` leases/jobs after worker death;
- safely retry/resume/reclassify according to stage idempotency;
- avoid duplicate active models or duplicate candidate records;
- preserve cancellation requests;
- preserve schedule configuration.

A web-node restart must not terminate a queue-backed training run merely because an SSE client disconnected.

---

## 21. Suggested service boundaries

Exact class names may adapt to existing code, but responsibilities should remain separated conceptually:

- `MlTrainingScheduler` — resolves due horizon schedules;
- `MlTrainingOrchestrator` — creates/locks/dispatches canonical runs;
- `MlTrainingJob` / staged jobs — executes FEAT-057 pipeline;
- `MlRunProgressService` — durable stage/progress updates;
- `MlCandidateLifecycleService` — eligibility/freshness/supersession state;
- `MlPromotionService` — explicit activation transaction;
- `MlRollbackService` — explicit retained-version rollback;
- `MlModelRetentionService` — safe artifact pruning;
- `MlDriftMonitor` — live drift evaluation/early trigger;
- `MlLifecycleNotificationService` — action-oriented lifecycle events.

Prefer evolving V7 services/models where they already own these concerns rather than parallel replacements.

---

## 22. API direction

Keep APIs under the existing Admin ML namespace/conventions.

Required capabilities conceptually include:

- read horizon lifecycle summary;
- list/detail training runs;
- update schedule configuration;
- start manual run;
- request cancellation;
- SSE run-progress stream;
- inspect candidate promotion evidence;
- promote candidate;
- list retained versions;
- roll back model;
- read drift/health summary.

All mutation endpoints are Admin-only and server-authorized.

Mutation endpoints SHOULD be idempotent or conflict-safe for double-click/retry scenarios.

---

## 23. Security and safety rules

1. All lifecycle mutations are Admin-only.
2. Existing Admin role is sufficient; do not introduce ML-specific RBAC in V8.
3. Same-horizon locking is server/database enforced.
4. Candidate cannot self-promote.
5. Failed/rejected/cancelled/stale/superseded candidate cannot be promoted.
6. Artifact integrity must be checked before activation/rollback where existing artifact-hash support allows.
7. External notifications cannot become authoritative lifecycle state.
8. SSE endpoints require Admin authorization.
9. Training failures never deactivate the existing active model.
10. Drift never auto-promotes or auto-rolls back.

---

## 24. Implementation-level defaults

The following are architect/implementation parameters, not PO decisions, and may be tuned using runtime evidence without reopening this specification:

- exact default cadence per horizon;
- exact supported cadence option list;
- retry count and exponential/fixed backoff values;
- lease/heartbeat/stale-run timeout;
- SSE heartbeat/reconnect details;
- retained artifact count per horizon;
- candidate freshness window per horizon;
- drift thresholds/minimum samples/cooldowns;
- progress percentage weighting per stage;
- pruning interval;
- exact notification-channel mapping.

Changes must remain within the frozen product behavior above and be versioned/configured where material.

---

## 25. Acceptance criteria

1. Each 1m/3m/6m horizon has independently enabled/configurable bounded schedule controls.
2. Due schedules enqueue training without requiring an Admin session.
3. Manual and drift triggers use the same canonical run path as scheduled training.
4. Only one queued/running/cancelling run may exist per horizon.
5. Different horizons may run concurrently where infrastructure allows.
6. Admin can see durable run state and near-real-time SSE stage/progress updates.
7. SSE disconnect/reconnect does not affect training and current state can be recovered by REST/read API.
8. Transient operational failure retries only within bounded policy.
9. Exhausted operational failure becomes `failed`, retains current active model, and raises actionable Admin alert.
10. Normal FEAT-057 quality rejection becomes `completed_rejected`, is not retried merely to seek a pass, and does not alert as an operational incident.
11. Passing FEAT-057 evidence creates an eligible candidate but does not activate it automatically.
12. Eligible candidate causes an actionable Admin notification.
13. Promotion review shows candidate vs active model, deterministic baseline, stability/calibration and relevant warnings.
14. Admin must explicitly confirm promotion.
15. Promotion atomically leaves exactly one active model for the horizon.
16. Admin can manually roll back to an eligible retained prior version.
17. Drift warning can trigger early retraining but never automatic promotion or rollback.
18. Queued runs can be cancelled immediately; running runs cancel safely at checkpoints.
19. Cancelled/incomplete artifacts cannot be promoted.
20. Stale or superseded candidates remain inspectable but cannot be promoted.
21. Active plus bounded recent rollback-capable model artifacts are retained; safe pruning never removes the active artifact.
22. All Admin lifecycle actions are authorized and auditable.
23. Run state, schedule state and cancellation intent survive restart/deploy.
24. Admin UI exposes run history, schedules, active/candidate/retained versions, drift status and actionable failures without SSH/Tinker.
25. Existing V7 prediction/scoring behavior remains operational when no promotion occurs.

---

## 26. Definition of done

FEAT-056 is complete when:

- scheduled/manual/drift-triggered retraining is durably orchestrated;
- Admin can configure bounded schedules per horizon;
- same-horizon concurrency and cancellation are safe;
- retries distinguish operational failure from model-quality rejection;
- SSE progress and persistent history work;
- FEAT-057 evidence flows into candidate eligibility without duplication of validation logic;
- no candidate can activate automatically;
- promotion and rollback are explicit, reviewed, atomic and auditable;
- candidate staleness/supersession and bounded artifact retention work;
- production drift monitoring can trigger early retraining without changing active model;
- lifecycle notifications are actionable rather than noisy;
- tests cover success, rejection, failure, retry, cancellation, concurrency, promotion, rollback, stale candidate, drift trigger and authorization paths;
- documentation/runbooks explain schedule controls, candidate review, promotion, rollback and failure recovery;
- V8 register links this document as the authoritative FEAT-056 implementation contract.

---

## 27. Explicit non-goals

FEAT-056 does not make StoX an autonomous model-deployment platform.

Human Admin control remains deliberate at the production-model boundary:

```text
training/evaluation = automated
production activation = human decision
rollback = human decision
```

This boundary is authoritative for V8.
