# StoX V9 — AI Platform & Governance Specification

| Field | Value |
|---|---|
| **Epic** | `V4-FEAT-017` |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Role** | Parent/shared AI infrastructure and governance epic |
| **Children / consumers** | `V9-AI-001`, `V9-AI-002`, and later focused AI features |

## 1. Purpose

`V4-FEAT-017` is the shared StoX AI platform and governance layer. Focused AI features own their product-specific behavior, prompts, retrieval, user journeys, and action semantics; this epic owns the reusable inference, routing, configuration, governance, logging, budgeting, health, prompt-management, structured-output, streaming, and operational-control foundations they consume.

The platform must not silently override deterministic StoX Strategy behavior, artifact/versioning rules, authorization, broker/execution safeguards, or other deterministic product logic.

## 2. Architectural boundary

AI features call logical, code-defined **capabilities**, not provider SDKs directly. Each capability is registered centrally and resolves through one canonical ordered inference path.

High-level flow:

`Feature -> Capability Registry -> Ordered Inference Router -> Provider Adapter(s) -> Structured Result / Failure`

Provider-specific behavior remains inside adapters. Hosted and self-hosted/local endpoints are first-class paths under the same contract.

## 3. Capability registry

Capabilities are code-defined; Admin configures their operational behavior. Each capability declares at least:

- stable capability ID;
- owning feature/module;
- allowed prompt/template(s);
- input contract;
- output contract/schema where applicable;
- streaming allowed/not allowed;
- structured-output requirement;
- canonical ordered inference paths;
- timeout/retry defaults;
- applicable budget scopes;
- service class;
- whether user-facing clipboard fallback is permitted;
- whether governed tool/action access is permitted.

Admin must not create arbitrary unsupported capabilities from the UI.

## 4. Ordered inference routing

Each capability has **one canonical ordered inference path**. There is no separate normal route and budget route.

Example:

`ChatGPT -> Claude -> Gemini`

At request time, the router evaluates the canonical order and removes/skips any path currently ineligible because of state, including:

- disabled/manual hold;
- hard-budget exhaustion;
- unavailable/misconfigured runtime;
- circuit-breaker/degraded/unhealthy state;
- provider/model technical failure;
- timeout;
- rate limit;
- other explicit operational exclusion.

If Claude is budget-exhausted, the effective route becomes `ChatGPT -> Gemini`. If ChatGPT then fails technically, fallback is Gemini; Claude is not reconsidered until eligible again.

Primary/secondary/tertiary are derived views of the current effective order, not separately stored roles.

V9 does **not** introduce provider-specific privacy/data-class eligibility routing. Normal StoX authorization, account scoping, secret handling, and data-minimization rules still apply.

## 5. Provider adapter contract

All hosted and self-hosted/local inference paths implement a common adapter contract covering:

- normal request/response;
- first-class streaming where supported;
- cancellation/timeout;
- token usage metadata;
- cost metadata/estimation inputs;
- health/connectivity checks;
- structured errors/failure categories;
- model/provider capabilities;
- structured-output/schema support;
- retry/failover signals.

Features must not call provider SDKs directly when an equivalent shared capability exists.

## 6. Failure and failover semantics

The router exhausts the current effective ordered path and then returns an explicit structured degraded/failure result. It must never fabricate an AI result.

Transient failures such as timeout, network failure, rate limit, or temporary provider unavailability may advance to the next effective path.

Configuration/permanent failures mark the path unavailable/degraded and routing continues.

Malformed or schema-invalid output may be retried once where appropriate, then follows normal failover.

The calling feature may apply its own deterministic/manual fallback.

For user-facing capabilities such as classification, summarization, fundamental-analysis reports, and similar non-internal tasks, the infrastructure must expose sufficient prepared prompt/context on total failure so the feature can offer a **Copy prompt** recovery action. Internal orchestration/tool-selection prompts must not be exposed through this fallback. This preserves the existing StoX paste-ready external-AI fallback pattern.

## 7. Health, degradation, and circuit breaker

Repeated technical/performance problems may temporarily degrade a path so it is skipped by subsequent requests rather than imposing repeated latency/failures.

Admin-configurable thresholds with safe defaults include, where applicable:

- consecutive failures;
- error-rate threshold;
- timeout threshold;
- latency/performance threshold;
- cooldown duration;
- probe/recovery criteria.

The system periodically probes degraded paths and automatically restores them when recovery criteria are satisfied.

Admin may manually disable/hold a path. Manual hold overrides automatic recovery until explicitly released. Admin cannot force an actually unhealthy path to be treated as healthy. Hold/release actions are audited.

## 8. Admin inference control plane

StoX Admin provides full operational control per capability/path, including:

- route order;
- enable/disable/manual hold;
- provider/model selection;
- API credentials/secrets;
- endpoint/base URL;
- provider-specific settings;
- timeout/retry settings;
- circuit-breaker thresholds;
- connectivity test;
- safe test inference with synthetic/non-sensitive input;
- health/availability state;
- last success/error;
- effective route view;
- current budget state;
- audit history.

Routine configuration changes apply live where technically safe. If a provider/runtime requires reload/restart, the UI must explicitly show that requirement and whether runtime configuration is current.

Secrets are masked after save, excluded from logs, and audited only as add/replace/delete operations rather than by value.

## 9. Safe test inference

Admin can execute a non-production synthetic inference against a selected path. The result shows at least:

- provider/model;
- latency;
- token usage;
- estimated cost;
- raw response;
- error details where applicable.

Test inference does not alter production route selection and is recorded as an operational test event.

## 10. Inference logging and routing trace

Every inference call records operational metadata, including where applicable:

- capability and calling feature;
- account/user identifiers appropriate for audit/usage accounting;
- provider/model/path;
- complete ordered-path evaluation;
- skip reason for every skipped path;
- attempt/retry/failover sequence;
- timestamps and duration;
- token usage;
- estimated cost;
- outcome/error/failure category;
- final selected path.

Admin gets a full per-call explorer with filters/search and complete routing trace, e.g.:

`ChatGPT — skipped: hard budget exhausted`

`Claude — attempted: timeout`

`Gemini — selected: success`

## 11. Raw prompt/response logging

Operational metadata is always recorded. Full prompt/response capture is selectively configurable by capability/environment.

Where enabled, StoX Admin may view the **full stored prompt and full stored response**. Credentials/secrets must not be written into logs.

Default raw-content retention:

- age limit: **7 days**;
- maximum retained raw prompt/response entries: **1000**.

Both values are Admin-configurable.

A daily cleanup job:

1. removes raw prompt/response bodies older than configured retention;
2. if count still exceeds the configured maximum, prunes the oldest raw prompt/response logs first.

Operational metadata/audit history is governed separately and is not removed merely because raw content is pruned.

Admin can see active retention/count configuration and recent cleanup result.

There is no selective manual deletion of individual raw entries. Admin may perform **Purge all raw prompt/response logs**, requiring explicit confirmation. Purge retains operational metadata, is itself audited, and does not alter retention settings.

## 12. Cost estimation and hierarchical budgets

StoX tracks estimated inference cost wherever provider/model pricing is known or configured. Token counts remain observable but **budget enforcement is cost-based, not token-count-based**.

Aggregate cost views include at least:

- overall StoX AI;
- capability;
- provider/model/path;
- user/account where appropriate;
- time period.

Admin can maintain model pricing when automatic pricing is unavailable.

### 12.1 Budget scopes

Hierarchical cost budgets support:

- overall StoX AI budget;
- per-capability budget;
- per-path/provider-model budget;
- per-user budget.

### 12.2 Soft and hard limits

Each applicable scope has an absolute hard cost limit. Soft limit is configured as a percentage from `0–100` of the hard limit, guaranteeing `soft <= hard`.

For overall/capability/path budgets:

- first crossing of soft limit sends one Admin warning notification per budget period per scope;
- hard-limit exhaustion makes that scope/path ineligible for routing.

Per-user budgets do **not** raise soft-limit warnings.

When a user-level hard budget is reached, StoX does not globally disable the user’s AI access. Instead, that user’s effective route simply excludes paths that are no longer eligible under applicable budget/state rules and proceeds through the remaining canonical capability order.

Admin may reduce a hard limit below current-period spend; that scope becomes immediately exhausted/ineligible. Admin may restore eligibility before reset by increasing the hard limit above current spend, unless the scope is also under manual hold.

### 12.3 Budget period

All AI budgets share one global monthly reset convention. Admin chooses either:

- start-of-month reset; or
- end-of-month reset.

No arbitrary reset date is configured.

Exhausted scopes normally become eligible automatically at the next reset. Admin may place a scope on manual hold so it remains unavailable after reset until explicitly released. Hold/release is audited.

## 13. Notifications

Soft-limit warnings for eligible non-user scopes use the StoX notification framework and notify Admins. User-budget soft-limit crossings do not notify.

Prompt publish/activation/rollback notifications are sent through in-app alerts **and email to all Admins**.

## 14. Prompt/template registry and governance

StoX maintains a central versioned prompt/template registry containing at least:

- stable template/prompt ID;
- version;
- owning capability/feature;
- system/instruction template;
- input schema/contract;
- expected output/schema where applicable;
- active/inactive state;
- change history/audit.

Features reference registered prompt versions rather than scattering unmanaged prompt text throughout the codebase.

### 14.1 Admin editing

Admins may directly edit prompt text and publish new versions. This is a high-risk capability under **Advanced Administration / Danger Zone**.

Requirements:

- immutable historical versions;
- author, timestamp, old/new version, capability, and change note;
- explicit publish/activate action separate from draft editing;
- rollback to prior versions;
- active version clearly identified;
- draft edits never affect production until published/activated;
- no secret values embedded in prompts;
- explicit confirmation before publish/activate/rollback;
- all edit/publish/activate/deactivate/rollback events audited;
- alerts + emails to all Admins on publish/activation/rollback.

### 14.2 Approval rule

If more than one Admin is registered, prompt publish/activation/rollback requires approval by a **second distinct Admin**. The same Admin cannot satisfy both sides.

If exactly one Admin exists, that Admin may proceed alone after explicit Danger Zone confirmation.

This two-person approval rule applies to prompt governance only. Provider/model/routing/budget configuration remains single-Admin with normal audit controls.

## 15. Structured outputs

Structured output/schema validation is a first-class platform capability.

Where a capability declares an output schema, the platform should:

- request structured output where supported;
- validate the returned structure;
- classify malformed/non-compliant output distinctly;
- retry once where appropriate;
- then fail over through the remaining effective route if still invalid.

## 16. Streaming

Streaming is first-class in the shared adapter/router contract. Features can choose normal request/response or streaming mode according to their capability definition.

Streaming support must preserve routing/audit metadata, cancellation, failure handling, and final usage/cost accounting as far as providers expose it.

## 17. Service classes

StoX defines a small fixed set of AI service classes:

- **Interactive** — latency-sensitive user-facing chat/analysis;
- **Background** — reports, summaries, classifications, queued jobs;
- **Critical agentic** — governed reasoning tied to action workflows with stricter failure/confirmation semantics.

Service class may influence timeout, retry, queueing, concurrency, and observability, but does not create an alternative provider order.

## 18. Concurrency

Admin-configurable concurrency limits exist at least for:

- overall StoX AI traffic;
- individual capabilities.

The implementation should prevent background work from starving interactive traffic, surface saturation/queueing operationally, and preserve the canonical capability-specific path order.

## 19. Deterministic/AI boundary

StoX should not ask an LLM to perform deterministic work that StoX can calculate or validate reliably itself.

The shared platform enables inference, reasoning, synthesis, explanation, classification, extraction, and governed planning; it does not replace deterministic calculations, Strategy rules, authorization, validation, or broker/execution safeguards.

## 20. Child-epic relationship

`V9-AI-001` owns the read-only documentation-grounded chatbot experience and retrieval behavior.

`V9-AI-002` owns governed tool/action contracts, previews, confirmations, authorization, idempotency, action auditing, and recovery for agentic operations.

Both consume this platform rather than reimplementing provider routing, prompt governance, logging, budgeting, health, or adapter plumbing.

## 21. Acceptance criteria

The epic is implementation-complete when:

1. code-defined capabilities route through one shared capability/router/adapter architecture;
2. capability-specific canonical path ordering and deterministic ordered failover work;
3. hosted and self-hosted/local endpoints are supported under one adapter model;
4. full Admin operational configuration and health visibility exist;
5. circuit-breaker degradation/recovery and manual holds work;
6. full per-call routing trace is inspectable;
7. selective raw prompt/response logging, retention cleanup, max-count pruning, and purge-all behavior work;
8. estimated cost and hierarchical budgets enforce agreed soft/hard semantics;
9. monthly reset and optional manual holds work;
10. prompt registry/versioning, Danger Zone editing, notifications, audit, and conditional two-Admin approval work;
11. structured-output validation/failover works;
12. streaming is supported through the common contract;
13. service classes and configurable global/per-capability concurrency are enforced;
14. child AI features can consume the platform without direct provider-specific inference plumbing;
15. deterministic StoX Strategy/execution safeguards remain authoritative.
