# StoX V9 — Final Cross-Spec Audit & Implementation Sequence

| Field | Value |
|---|---|
| **Version** | V9 |
| **Document type** | Final architecture/specification audit and implementation sequencing contract |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Original audit date** | 2026-09-28 |
| **Latest reconciliation** | 2026-09-30 — V9-OPS-002 incorporated |

## 1. Purpose

This document records the cross-specification audit of the frozen StoX V9 backlog and defines the dependency-aware implementation sequence.

Where this document clarifies ambiguous cross-epic wording, the clarification is normative and must be read together with the underlying frozen epic specifications. It does not supersede explicit frozen PO product decisions.

The canonical backlog remains [`LidoPortfolio-V9-Wishlist.md`](LidoPortfolio-V9-Wishlist.md). Later frozen additions are reconciled here so this sequence does not lag the register.

## 2. Audited V9 scope

The current registered V9 scope is:

1. `V9-UX-001` — User Journey Automation Readiness & E2E Automation
2. `V9-UX-002` — User Journey Typeahead / “How Do I?” Search
3. `V9-COMM-001` — StoX Email Notifications & Account Lifecycle Messaging
4. `V9-OPS-001` — Historical Fundamentals Bootstrap Admin Operations
5. `V9-OPS-002` — Automated API Failure GitHub Issue Reporting
6. `V9-DATA-001` — Data Export Framework
7. `V9-DATA-002` — VPS Historical Data Staging and Delivery
8. `V9-UX-003` — Customizable Summary Fields & Dashboard Layouts
9. `V9-VIZ-001` — Combo Chart Support
10. `V4-FEAT-017` — AI Platform & Governance
11. `V9-AI-001` — Documentation-Grounded StoX Chatbot
12. `V9-AI-002` — Agentic StoX Assistant / MCP Action Layer
13. `V9-AI-003` — Embedded AI Insights & Prompt Execution

All registered items have frozen implementation-ready specifications. `V9-DATA-002` additionally depends on compatible implementation of its separately versioned `SKR-001` StoX-Kite-Rain companion protocol.

## 3. Audit result

**Result: PASS — the registered V9 specification set is implementation-ready.**

No unresolved PO decision is known in the registered V9 backlog.

Implementation must still respect V8 closure state. “Implementation-ready” means the epic contract is frozen; it does not mean every epic should be started before the V8 subsystem it extends has completed production/configuration acceptance.

## 4. Normative cross-epic reconciliations

### 4.1 V9-UX-001 starts first but closes last

`V9-UX-001` is intentionally split:

- foundation first: journey governance, stable IDs/metadata, E2E harness, deterministic test data, CI/nightly structure and traceability;
- continuous maintenance while V9 features land;
- final closure only after implemented V9 journeys have been reconciled and automated.

### 4.2 Notification framework is shared infrastructure

`V9-COMM-001` owns the canonical StoX notification-event model and email/in-app notification framework. Other V9 epics must not create parallel generic notification stacks.

This includes operational notifications from OPS-001/DATA work and AI governance alerts where their specifications require notification behavior.

### 4.3 V9-OPS-002 is incident automation, not telemetry or notification delivery

`V9-OPS-002` creates durable engineering work items for unexpected API failures. It is deliberately separate from:

- V8 `V4-FEAT-052` OpenTelemetry/LidoTelemetry, which remains the telemetry/observability owner; and
- `V9-COMM-001`, which remains the user/admin notification framework.

OPS-002 may reuse safe trace/correlation IDs and emit reporter lifecycle telemetry through existing FEAT-052 abstractions, but neither FEAT-052 nor COMM-001 is a hard runtime dependency for issue creation.

OPS-002 must be implemented as an **additive observer/reporting layer** while V8 remains in closure. It must not rewrite provider retry/fallback semantics, domain error behavior, authentication behavior or financial/business workflows merely to centralize reporting.

### 4.4 OPS-002 status semantics and duplicate contract

OPS-002 does not treat “non-200” literally as failure. Default success is HTTP `2xx`, with provider/operation-specific expected-status policy for legitimate non-2xx control flow.

Unexpected failures use deterministic low-cardinality fingerprints. Duplicate protection is two-level:

1. local unique fingerprint/upsert; and
2. GitHub search/reconciliation through the exact marker `<!-- stox-api-failure:<fingerprint> -->`.

Closed issues are not auto-reopened. A recurring fingerprint creates a new incident generation only after the configured cooldown (default 24 hours), referencing the prior issue.

### 4.5 OPS-002 security/failure isolation

GitHub reporting is asynchronous, queue-driven and fail-open. GitHub failure must never change the original StoX operation outcome.

The reporter’s own GitHub HTTP client is excluded from failure observation so it cannot recursively create incidents about itself.

Only allowlisted sanitized diagnostics may enter incident persistence or GitHub. Tokens, authorization headers, cookies, raw bodies, user/account identifiers and secrets must not be included.

### 4.6 Historical fundamentals dependency

`V9-OPS-001` extends the existing V8 `V4-FEAT-054` bootstrap engine and must not create a second ingestion engine. Substantive OPS-001 implementation should wait until FEAT-054 provider/deployed-runtime acceptance is sufficiently stable.

### 4.7 Historical minute-data dependency

`V9-DATA-002` changes the acquisition/staging/delivery topology around V8 `V4-FEAT-065` but does not replace its canonical corpus contract.

FEAT-065 remains authoritative for Parquet/DuckDB/Polars corpus semantics; the Mac remains canonical. DATA-002 should not be mixed into FEAT-065 live-Kite/full-corpus acceptance in a way that obscures whether failures come from V8 corpus acquisition or the new V9 transfer topology.

### 4.8 Data Export vs operational export

`V9-DATA-001` is the reusable investor-facing export framework. OPS-specific exports should reuse its primitives where practical rather than creating a second generic export stack.

### 4.9 Combo chart export integration

`V9-VIZ-001` should expose canonical chart-series data through `V9-DATA-001` when both are present. Export is data-only unless a future frozen epic explicitly adds image export.

### 4.10 AI platform and AI feature sequencing

`V4-FEAT-017` owns shared AI provider/routing/governance infrastructure. `V9-AI-001/002/003` must not bypass it with direct provider integrations.

AI-002 extends AI-001 with governed account tools. AI-003 reuses the shared platform and uses AI-002 for Strategy draft mutation rather than adding another mutation framework.

Laravel remains authoritative for auth/domain/business writes; Python owns inference/RAG/orchestration; browser never calls Python directly; Python does not directly access StoX MariaDB.

### 4.11 Deterministic computation remains authoritative

Across AI features, deterministic StoX services remain responsible for calculations that StoX can calculate reliably. LLMs may plan, explain, classify, synthesize and interpret but must not replace deterministic portfolio/strategy/authorization/execution logic.

## 5. Dependency graph

### Hard/strong dependencies

- `V9-UX-002` -> `V9-UX-001` foundation metadata/governance.
- `V9-OPS-001` -> V8 `V4-FEAT-054` engine.
- `V9-OPS-001` notifications -> `V9-COMM-001` where notifications are required.
- `V9-DATA-001` background completion/failure notifications -> `V9-COMM-001` where notification behavior is required.
- `V9-DATA-002` -> stable V8 FEAT-065 corpus contract + compatible `SKR-001` protocol implementation.
- `V4-FEAT-017` governance/admin notification acceptance -> `V9-COMM-001` notification framework.
- `V9-AI-001` -> `V4-FEAT-017` + maintained journey/help corpus.
- `V9-AI-002` -> `V4-FEAT-017` + `V9-AI-001` assistant foundation.
- `V9-AI-003` -> `V4-FEAT-017`; Strategy draft creation additionally integrates with `V9-AI-002`.
- final V9 release acceptance -> final `V9-UX-001` journey/E2E closure.

### Independent/additive dependency characteristics

- `V9-OPS-002` has no hard dependency on COMM-001, AI, FEAT-052 closure or DATA work.
- OPS-002 may start early provided it preserves existing V8 behavior and does not use GitHub reporting to redefine V8 error semantics.
- OPS-002 frontend coverage may progressively expand as common frontend request abstractions are consolidated; backend outbound HTTP reporting remains independently valuable.

## 6. Recommended implementation sequence

The implementation agent may parallelize independent work while preserving the following gates.

### Phase 0 — Release foundation

**0A. V9-UX-001 foundation slice**

Establish journey governance, stable IDs/metadata, E2E harness, deterministic test-data conventions, CI/nightly structure and traceability. Keep UX-001 open.

### Phase 1 — Additive shared infrastructure

**1A. V9-COMM-001 generic notification infrastructure**

Build canonical notification primitives. While V8 FEAT-055 remains under production acceptance, avoid rewriting its account-lifecycle behavior merely to finish V9 integration.

**1B. V9-OPS-002 — Automated API Failure GitHub Issue Reporting**

May proceed early and in parallel because it is an additive operational observer. Implement local incident model, classifier/sanitizer/fingerprinting, backend outbound observation, isolated queued GitHub adapter/deduplication, rate/circuit/recurrence rules, frontend ingestion path and minimal Admin visibility.

During V8 closure, do not alter protected V8 provider/domain behavior merely to obtain reporting coverage.

**1C. V9-DATA-001 — Data Export Framework**

Establish reusable export primitives.

### Phase 2 — Deterministic/user-facing features safe after prerequisites

- `V9-UX-002` after UX-001 metadata foundation.
- `V9-UX-003` largely independent.
- `V9-VIZ-001` renderer/UI groundwork can proceed; final data integrations must respect unfinished V8 provider/data acceptance.
- `V9-OPS-001` after FEAT-054 provider/deployed-runtime behavior is sufficiently verified.

### Phase 3 — V9 historical-data topology

**V9-DATA-002 + SKR-001**

Begin substantive integration after FEAT-065 live-Kite/full-corpus/handoff acceptance is stable enough that V8 corpus behavior can be distinguished from V9 staging/transfer behavior.

### Phase 4 — Shared AI platform

**V4-FEAT-017**

Implement shared provider/routing/governance infrastructure according to the frozen AI architecture. Isolated groundwork may start earlier; acceptance integrations should use stable COMM/telemetry/domain contracts.

### Phase 5 — Read-only AI

**V9-AI-001**

Documentation-grounded chatbot on the shared AI platform.

### Phase 6 — Governed agentic actions

**V9-AI-002**

Bind governed tools only to stable StoX domain services and authorization contracts.

### Phase 7 — Embedded AI execution

**V9-AI-003**

Use shared inference infrastructure and the AI-002 mutation path for Strategy draft creation. Do not duplicate V8 FEAT-062 fundamental insight logic.

### Phase 8 — Release closure

**V9-UX-001 final closure + release audit**

Reconcile/automate all resulting V9 journeys and verify every epic against its frozen acceptance criteria.

## 7. Parallelization guidance

Safe parallelism is encouraged:

- UX-001 foundation, OPS-002 and generic COMM infrastructure may run concurrently.
- DATA-001 and UX-003 are largely independent of V8 production provider verification.
- UX-002 follows only the UX-001 metadata foundation.
- VIZ renderer/preset infrastructure can progress before final data-provider acceptance.
- OPS-001 waits for the V8 FEAT-054 engine acceptance boundary.
- DATA-002 waits for the V8 FEAT-065 live-corpus acceptance boundary.
- AI platform isolated groundwork may begin before all deterministic epics finish, but feature AI work must use the shared platform and stable domain contracts.

## 8. Implementation-agent rules

The implementation agent should:

1. treat each frozen epic spec plus this sequence as authoritative;
2. preserve current working V8 behavior unless a frozen V9 spec explicitly changes it;
3. reuse existing components/services rather than create parallel frameworks;
4. keep new operational observers fail-open;
5. never weaken safety, authorization, financial execution, deterministic-computation, privacy or audit requirements;
6. add tests alongside each implementation slice;
7. update journeys/E2E coverage alongside user-visible V9 changes;
8. use implementation judgment for technical details and escalate only genuine product conflicts;
9. during V8 closure, avoid changes that make V8 production/configuration verification evidence ambiguous.

## 9. V9 implementation-ready declaration

The V9 register and linked specifications are frozen and implementation-ready, including the later additions:

- `V9-AI-003` — Embedded AI Insights & Prompt Execution;
- `V9-DATA-002` — VPS Historical Data Staging and Delivery + `SKR-001` companion;
- `V9-COMM-001` access-request resilience companion;
- `V9-OPS-002` — Automated API Failure GitHub Issue Reporting.

**StoX V9 remains FROZEN / IMPLEMENTATION-READY.**

Implementation order must follow dependency and V8-closure gates rather than interpreting “implementation-ready” as permission to overlap unfinished V8 acceptance indiscriminately.