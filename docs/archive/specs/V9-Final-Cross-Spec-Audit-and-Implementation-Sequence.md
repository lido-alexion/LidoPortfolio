# StoX V9 — Final Cross-Spec Audit & Implementation Sequence

| Field | Value |
|---|---|
| **Version** | V9 |
| **Document type** | Final architecture/specification audit and implementation sequencing contract |
| **Status** | **PO REVIEW — V9-DATA-002 WINDOWS RECEIVER REVISION** |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Original audit date** | 2026-09-28 |
| **Latest reconciliation** | 2026-10-01 — Windows receiver, NTFS archive and indefinite delivery catalog recorded for V9-DATA-002 |

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
6. `V9-OPS-003` — LLM Log Error Triage & GitHub Issue Reporting
7. `V9-DATA-001` — Data Export Framework
8. `V9-DATA-002` — VPS Historical Data Staging and Delivery
9. `V9-UX-003` — Customizable Summary Fields & Dashboard Layouts
10. `V9-UX-004` — Guided Production ML Acceptance Wizard
11. `V9-VIZ-001` — Combo Chart Support
12. `V4-FEAT-017` — AI Platform & Governance
13. `V9-AI-001` — Documentation-Grounded StoX Chatbot
14. `V9-AI-002` — Agentic StoX Assistant / MCP Action Layer
15. `V9-AI-003` — Embedded AI Insights & Prompt Execution
16. `V9-DATA-003` — Forward Data Collection, Recovery & Readiness

All registered items except `V9-DATA-002` retain their prior frozen implementation-ready status. `V9-DATA-002` is in PO review for the Windows receiver/archive revision and requires compatible implementation of the revised `SKR-001` protocol. `V9-OPS-003` remains gated on the required `V4-FEAT-017` shared AI core.

## 3. Audit result

**Result: PARTIAL — V9-DATA-002 and SKR-001 are under PO review for the Windows receiver/archive revision.**

All other V9 items retain their prior status. Authenticode signing/SmartScreen policy remains to be decided for the private Windows app.

Implementation must still respect V8 closure state and explicit V9 dependency gates. “Implementation-ready” means the epic contract is frozen; it does not mean every epic should be started before the subsystem it depends on is stable.

## 4. Normative cross-epic reconciliations

### 4.1 V9-UX-001 starts first but closes last

`V9-UX-001` is intentionally split:

- foundation first: journey governance, stable IDs/metadata, E2E harness, deterministic test data, CI/nightly structure and traceability;
- continuous maintenance while V9 features land;
- final closure only after implemented V9 journeys have been reconciled and automated.

### 4.2 Notification framework is shared infrastructure

`V9-COMM-001` owns the canonical StoX notification-event model and email/in-app notification framework. Other V9 epics must not create parallel generic notification stacks.

### 4.3 V9-OPS-002 is incident automation, not telemetry or notification delivery

`V9-OPS-002` creates durable engineering work items for unexpected API failures. It is deliberately separate from V8 `V4-FEAT-052` telemetry and from `V9-COMM-001` notification delivery.

OPS-002 may reuse safe trace/correlation IDs and emit reporter lifecycle telemetry through existing FEAT-052 abstractions, but neither FEAT-052 nor COMM-001 is a hard runtime dependency for issue creation.

OPS-002 must remain an additive observer/reporting layer while V8 remains in closure. It must not rewrite provider retry/fallback semantics, domain error behavior, authentication behavior or financial/business workflows merely to centralize reporting.

### 4.4 OPS-002 status semantics and duplicate contract

OPS-002 does not treat “non-200” literally as failure. Default success is HTTP `2xx`, with provider/operation-specific expected-status policy for legitimate non-2xx control flow.

Unexpected failures use deterministic low-cardinality fingerprints. Duplicate protection is two-level:

1. local unique fingerprint/upsert; and
2. GitHub search/reconciliation through the exact marker `<!-- stox-api-failure:<fingerprint> -->`.

Closed issues are not auto-reopened. A recurring fingerprint creates a new incident generation only after the configured cooldown, default 24 hours, referencing the prior issue.

### 4.5 OPS-002 security/failure isolation

GitHub reporting is asynchronous, queue-driven and fail-open. GitHub failure must never change the original StoX operation outcome.

The reporter’s own GitHub HTTP client is excluded from failure observation so it cannot recursively create incidents about itself.

Only allowlisted sanitized diagnostics may enter incident persistence or GitHub. Tokens, authorization headers, cookies, raw bodies, user/account identifiers and secrets must not be included.

### 4.6 V9-OPS-003 uses shared AI, not a second model path

`V9-OPS-003` performs AI-assisted triage only through a dedicated shared capability, conceptually `ops.log_error_triage`, registered in the `V4-FEAT-017` capability/routing/governance platform.

It must not call model providers directly from Laravel logging code and must reuse V4-FEAT-017 provider adapters, ordered routing, budgets, concurrency, structured outputs, prompt registry/versioning, tracing and failure isolation.

OPS-003 may be implemented once the required V4-FEAT-017 core is stable; it does **not** need to wait for `V9-AI-001`, `V9-AI-002` or `V9-AI-003` user-facing features.

### 4.7 OPS-003 classification and issue-creation gate

An ERROR log does not itself imply a code bug. The structured classifier must distinguish at least:

- `code_bug`;
- `external_dependency`;
- `configuration_or_environment`;
- `expected_operational_condition`;
- `data_quality_or_input`;
- `security_or_abuse_signal`;
- `uncertain`.

Automatic GitHub issue creation requires all frozen gates from the OPS-003 spec, including:

- classification = `code_bug`;
- confidence >= default `0.85` threshold;
- actionability = actionable;
- concrete evidence present;
- stable bug identity for fingerprinting;
- no security-sensitive classification;
- rate/circuit controls permit creation;
- no matching open GitHub issue.

`uncertain` and insufficient-evidence classifications must not create issues automatically.

### 4.8 OPS-003 sanitization, deduplication and recursion isolation

Sanitization must occur before log context leaves StoX for AI inference. Raw logs, request bodies, credentials, user-private values and secrets must not be sent to the model or GitHub.

OPS-003 uses a deterministic code-bug fingerprint and exact GitHub marker:

`<!-- stox-log-bug:<fingerprint> -->`

High-frequency duplicate log bursts must be debounced/aggregated so they do not produce one LLM call or one GitHub issue per occurrence.

The triage pipeline must exclude its own AI routing, queue, persistence and GitHub reporter failures from recursive automatic triage.

### 4.9 OPS-003 reuses OPS-002 GitHub infrastructure

OPS-003 must reuse or generalize OPS-002's GitHub credential, adapter, asynchronous queue discipline, issue-rate controls, circuit breaker, recurrence handling and exact-marker duplicate reconciliation.

There must not be a second GitHub token/configuration/transport stack for log-triage issues.

### 4.10 Historical fundamentals dependency

`V9-OPS-001` extends the existing V8 `V4-FEAT-054` bootstrap engine and must not create a second ingestion engine. Substantive OPS-001 implementation should wait until FEAT-054 provider/deployed-runtime acceptance is sufficiently stable.

### 4.11 Historical minute-data dependency

`V9-DATA-002` changes the acquisition/staging/delivery topology around V8 `V4-FEAT-065` but does not replace its canonical corpus contract.

FEAT-065 remains authoritative for Parquet/DuckDB/Polars corpus semantics; the Mac remains the eventual canonical research home. The Windows laptop is the first receiving/holding machine, with manual NTFS archiving and later verified handoff to Mac. V9-DATA-002 implements the delivery path needed to populate the corpus; it must preserve FEAT-065 semantics rather than waiting for the live-Kite/full-corpus acceptance that this delivery path enables.

### 4.12 Data Export vs operational export

`V9-DATA-001` is the reusable investor-facing export framework. OPS-specific exports should reuse its primitives where practical rather than creating a second generic export stack.

### 4.13 Combo chart export integration

`V9-VIZ-001` should expose canonical chart-series data through `V9-DATA-001` when both are present. Export is data-only unless a future frozen epic explicitly adds image export.

### 4.14 AI platform and AI feature sequencing

`V4-FEAT-017` owns shared AI provider/routing/governance infrastructure. `V9-AI-001/002/003` and operational AI consumers such as OPS-003 must not bypass it with direct provider integrations.

AI-002 extends AI-001 with governed account tools. AI-003 reuses the shared platform and uses AI-002 for Strategy draft mutation rather than adding another mutation framework.

Laravel remains authoritative for auth/domain/business writes; Python owns inference/RAG/orchestration; browser never calls Python directly; Python does not directly access StoX MariaDB.

### 4.15 Deterministic computation remains authoritative

Across AI features, deterministic StoX services remain responsible for calculations that StoX can calculate reliably. LLMs may plan, explain, classify, synthesize and interpret but must not replace deterministic portfolio/strategy/authorization/execution logic.

### 4.16 V9-UX-004 explains and sequences V8 production acceptance

`V9-UX-004` replaces the technical all-in-one acceptance panel with a deterministic seven-step wizard. It is a presentation, education and recovery layer over the implemented V8 production-acceptance services.

The wizard may add an aggregate read-only state endpoint, reason-code presentation registry and polling/recovery behavior. It must not duplicate or weaken source validation, point-in-time coverage, backfill safety, dataset preparation, training, qualification, promotion or lifecycle gates. Preflight remains a non-training readiness operation; candidate training, model promotion and lifecycle automation remain separate explicit Admin decisions.

### 4.17 Forward data collection ownership

`V9-DATA-003` owns ongoing acquisition obligations, bounded recovery and shared freshness/coverage evidence. It reuses FEAT-054 fundamentals, FEAT-057 PIT membership, daily market-data/data-quality services and the existing corporate-action approval path. OPS-001 adds Admin backfill operations over the same fundamentals engine. FEAT-065/DATA-002 retain minute-corpus ownership; FEAT-063 retains live microstructure ownership. Their optional research health cannot silently become a daily 1m/3m/6m readiness gate. Forward collection does not enable model training, promotion, lifecycle or drift. Existing remediation is reconciled, not implemented twice.

## 5. Dependency graph

### Hard/strong dependencies

- `V9-UX-002` -> `V9-UX-001` foundation metadata/governance.
- `V9-DATA-003` -> existing owner ingestion/provenance engines; notifications integrate COMM-001; minute/live monitoring integrates their owner evidence. Daily adapters do not depend on Windows delivery or AI.
- `V9-OPS-001` -> V8 `V4-FEAT-054` engine.
- `V9-OPS-001` notifications -> `V9-COMM-001` where notifications are required.
- `V9-DATA-001` background completion/failure notifications -> `V9-COMM-001` where notification behavior is required.
- `V9-DATA-002` -> stable V8 FEAT-065 corpus contract + compatible `SKR-001` protocol implementation.
- `V9-UX-004` -> implemented V8 production acceptance endpoints/domain + V9-UX-001 journey/E2E conventions.
- `V4-FEAT-017` governance/admin notification acceptance -> `V9-COMM-001` notification framework where required.
- `V9-OPS-003` -> `V4-FEAT-017` core capability registry/provider routing/structured output/prompt governance/budget/concurrency/failure isolation + shared OPS-002 GitHub reporting infrastructure.
- `V9-AI-001` -> `V4-FEAT-017` + maintained journey/help corpus.
- `V9-AI-002` -> `V4-FEAT-017` + `V9-AI-001` assistant foundation.
- `V9-AI-003` -> `V4-FEAT-017`; Strategy draft creation additionally integrates with `V9-AI-002`.
- final V9 release acceptance -> final `V9-UX-001` journey/E2E closure.

### Independent/additive dependency characteristics

- `V9-OPS-002` has no hard dependency on COMM-001, AI, FEAT-052 closure or DATA work.
- OPS-002 may start early provided it preserves existing V8 behavior.
- OPS-003 is independent of the user-facing AI epics after the required V4-FEAT-017 core and OPS-002 shared GitHub primitives are available.

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

**1C. V9-DATA-001 — Data Export Framework**

Establish reusable export primitives.

**1D. V9-DATA-003 — Forward Data Collection, Recovery & Readiness**

Prioritize durable planning and daily production-data adapters, including fair fundamentals refresh and official NSE provenance. Reconcile already-started remediation. Sector/corporate-action integrations must demonstrate real source operation. Reuse COMM notification primitives and owner coverage; complete minute/live monitoring when its owner evidence is available. Keep DATA-002 PO-review restrictions intact.

### Phase 2 — Deterministic/user-facing features safe after prerequisites

- `V9-UX-002` after UX-001 metadata foundation.
- `V9-UX-003` largely independent.
- `V9-UX-004` after the V8 production-acceptance domain is stable; it may proceed independently of AI and historical minute-data transfer work.
- `V9-VIZ-001` renderer/UI groundwork can proceed; final data integrations must respect unfinished V8 provider/data acceptance.
- `V9-OPS-001` after FEAT-054 provider/deployed-runtime behavior is sufficiently verified.

### Phase 3 — V9 historical-data topology

**V9-DATA-002 + SKR-001**

Begin against the frozen FEAT-065 corpus contract and the revised Windows receiver specification. V9-DATA-002 is an enabling path for the live corpus/handoff acceptance; do not gate its implementation on that future acceptance. Keep corpus semantics and V9 delivery behavior testable as separate boundaries.

### Phase 4 — Shared AI platform core

**V4-FEAT-017**

Implement at least the capability registry, provider adapters/routing, structured output validation, prompt registry/versioning, budget/concurrency controls, tracing and failure isolation required by downstream AI consumers.

### Phase 5 — Operational AI triage

**V9-OPS-003 — LLM Log Error Triage & GitHub Issue Reporting**

Once the V4-FEAT-017 core and OPS-002 GitHub primitives are stable:

- register `ops.log_error_triage`;
- add centralized ERROR-log observation;
- sanitize before inference;
- add deterministic prefiltering, debounce and decision caching;
- classify through structured AI output;
- create issues only for high-confidence actionable code bugs;
- reuse OPS-002 GitHub adapter/deduplication/rate controls;
- add minimal Admin triage visibility and acceptance tests.

OPS-003 may proceed in parallel with the later user-facing AI phases.

### Phase 6 — Read-only AI

**V9-AI-001**

Documentation-grounded chatbot on the shared AI platform.

### Phase 7 — Governed agentic actions

**V9-AI-002**

Bind governed tools only to stable StoX domain services and authorization contracts.

### Phase 8 — Embedded AI execution

**V9-AI-003**

Use shared inference infrastructure and the AI-002 mutation path for Strategy draft creation. Do not duplicate V8 FEAT-062 fundamental insight logic.

### Phase 9 — Release closure

**V9-UX-001 final closure + release audit**

Reconcile/automate all resulting V9 journeys and verify every epic against its frozen acceptance criteria.

## 7. Parallelization guidance

Safe parallelism is encouraged:

- UX-001 foundation, OPS-002 and generic COMM infrastructure may run concurrently.
- DATA-001 and UX-003 are largely independent of V8 production provider verification.
- UX-002 follows only the UX-001 metadata foundation.
- UX-004 depends on the existing V8 production-acceptance workflow and can proceed independently of AI epics.
- VIZ renderer/preset infrastructure can progress before final data-provider acceptance.
- OPS-001 waits for the V8 FEAT-054 engine acceptance boundary.
- DATA-002 can implement against the frozen FEAT-065 contract and is required to enable the planned live-corpus and handoff acceptance.
- AI platform isolated groundwork may begin before all deterministic epics finish.
- OPS-003 may start as soon as V4-FEAT-017 core + OPS-002 GitHub primitives are stable; it does not wait for AI-001/002/003.
- user-facing AI features must use the shared platform and stable domain contracts.

## 8. Implementation-agent rules

The implementation agent should:

1. treat each frozen epic spec plus this sequence as authoritative;
2. preserve current working V8 behavior unless a frozen V9 spec explicitly changes it;
3. reuse existing components/services rather than create parallel frameworks;
4. keep new operational observers and AI triage fail-open;
5. never weaken safety, authorization, financial execution, deterministic-computation, privacy or audit requirements;
6. sanitize operational context before sending it to any AI provider or GitHub;
7. add tests alongside each implementation slice;
8. update journeys/E2E coverage alongside user-visible V9 changes;
9. use implementation judgment for technical details and escalate only genuine product conflicts;
10. during V8 closure, avoid changes that make V8 production/configuration verification evidence ambiguous.

## 9. V9 implementation-ready declaration

The V9 register and linked specifications retain their per-epic statuses; V9-DATA-002/SKR-001 are currently under PO review for the Windows receiver/archive revision. Other frozen additions include:

- `V9-AI-003` — Embedded AI Insights & Prompt Execution;
- `V9-DATA-002` — VPS Historical Data Staging and Windows Delivery + revised `SKR-001` companion;
- `V9-COMM-001` access-request resilience companion;
- `V9-OPS-002` — Automated API Failure GitHub Issue Reporting;
- `V9-OPS-003` — LLM Log Error Triage & GitHub Issue Reporting, gated on V4-FEAT-017 core + OPS-002 GitHub primitives.
- `V9-UX-004` — Guided Production ML Acceptance Wizard, gated on the implemented V8 production-acceptance domain.

**StoX V9 remains FROZEN / IMPLEMENTATION-READY except V9-DATA-002, which is in PO REVIEW pending the Windows distribution/signing decision and final protocol acceptance.**

Implementation order must follow dependency and V8-closure gates rather than interpreting “implementation-ready” as permission to overlap unfinished V8 acceptance indiscriminately.
