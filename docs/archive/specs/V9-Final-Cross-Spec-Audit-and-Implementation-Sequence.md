# StoX V9 — Final Cross-Spec Audit & Implementation Sequence

| Field | Value |
|---|---|
| **Version** | V9 |
| **Document type** | Final architecture/specification audit and implementation sequencing contract |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Audit date** | 2026-09-28 |

## 1. Purpose

This document records the final cross-specification audit of the frozen StoX V9 backlog and defines the dependency-aware implementation sequence.

It does not introduce a new product epic. It reconciles relationships between the already-frozen V9 specifications and provides the release-level ordering/gates that the implementation agent should follow.

Where this document clarifies ambiguous cross-epic wording, the clarification is normative and must be read together with the underlying frozen epic specifications. It does not supersede explicit frozen PO product decisions.

## 2. Audited V9 scope

The final audit covered all registered V9 work:

1. `V9-UX-001` — User Journey Automation Readiness & E2E Automation
2. `V9-UX-002` — User Journey Typeahead / “How Do I?” Search
3. `V9-COMM-001` — StoX Email Notifications & Account Lifecycle Messaging
4. `V9-OPS-001` — Historical Fundamentals Bootstrap Admin Operations
5. `V9-DATA-001` — Data Export Framework
6. `V9-UX-003` — Customizable Summary Fields & Dashboard Layouts
7. `V9-VIZ-001` — Combo Chart Support
8. `V4-FEAT-017` — AI Platform & Governance
9. `V9-AI-001` — Documentation-Grounded StoX Chatbot
10. `V9-AI-002` — Agentic StoX Assistant / MCP Action Layer

All ten items have dedicated frozen implementation-ready specifications.

## 3. Final audit result

**Result: PASS — V9 is specification-complete and implementation-ready.**

No unresolved PO decision remains in the registered V9 backlog.

The audit found no product-level contradiction requiring a new PO decision. The following cross-epic relationships required explicit reconciliation so implementation does not accidentally create duplicate frameworks or interpret sequencing incorrectly.

## 4. Normative cross-epic reconciliations

### 4.1 V9-UX-001 starts first but closes last

`V9-UX-001` remains the highest-priority V9 item, but its lifecycle is intentionally split:

- **Foundation phase first:** establish/refresh the authoritative journey corpus, conformance workflow, E2E harness, deterministic test data, CI conventions and traceability contract before dependent user-facing work relies on them.
- **Continuous phase during V9:** every new or materially changed V9 user journey must be documented/reconciled and automated as the corresponding feature lands.
- **Release-closure phase last:** `V9-UX-001` cannot be declared complete until all implemented V9 features have their applicable journeys, conformance review and required automation coverage.

This resolves the apparent tension between “UX-001 is first priority” and its rule that every real discovered journey becomes mandatory scope.

### 4.2 Notification framework is shared infrastructure

`V9-COMM-001` provides the canonical StoX notification-event model used by later V9 features rather than each feature implementing its own notification subsystem.

Consumers include at least:

- `V9-OPS-001` operational run/failure notifications;
- `V9-DATA-001` background export completion/failure notifications;
- `V4-FEAT-017` AI budget warnings and prompt-governance notifications;
- later feature-specific events where their frozen specs call for notifications.

AI prompt publish/activate/rollback alerts are **governance/admin notifications** delivered through the notification framework; they are not user-editable email-template behavior. Prompt templates under the AI platform and notification/email rendering templates under COMM-001 are distinct registries and must not be conflated.

Agentic V9-AI-002's prohibition on external email/MCP tools does not prohibit StoX's own internal notification framework from sending system-generated notifications.

### 4.3 Per-user AI budget fallback semantics

The frozen AI routing principle is one canonical ordered path per capability; there is no second “budget route”. Budget state simply removes ineligible paths from that canonical order.

To preserve the PO-approved user-specific fallback behavior, **user-level path eligibility must be evaluated per user for each inference path**. If a user has exhausted the applicable hard allowance for a path, that path is excluded only for that user while later eligible paths remain in canonical order.

Example canonical order:

`ChatGPT -> Claude -> Gemini`

- If Claude is exhausted for User A, User A's effective route is `ChatGPT -> Gemini`.
- If ChatGPT subsequently fails technically, User A proceeds directly to Gemini.
- Another user whose Claude allowance/state is still eligible continues to see the normal `ChatGPT -> Claude -> Gemini` order.

Overall/capability/path global hard limits continue to remove the affected scope globally. Per-user soft-limit warnings remain suppressed as frozen.

Implementation may model this through a user-path allowance/ledger or an equivalent normalized budget structure, but must preserve the above observable behavior.

### 4.4 Monthly AI reset convention

All AI budget scopes share one monthly reset boundary. The frozen Admin choice is represented as start-of-month vs end-of-month semantics; implementation must normalize this to a deterministic month-boundary rollover and must not introduce arbitrary per-budget reset dates.

### 4.5 AI-001 vs AI-002 read boundary

There is no conflict between:

- `V9-AI-001`: documentation-grounded assistance plus explanation of currently visible page data only; and
- `V9-AI-002`: authorized account-data retrieval through governed read tools for reasoning/summarization.

AI-002 is the later-phase extension and intentionally supersedes the narrower visible-data boundary only when the governed tool layer is active. The documentation-grounded behavior remains available independently.

### 4.6 Deterministic computation remains authoritative

Across AI-001/AI-002 and the shared platform:

- the LLM plans, interprets, explains, classifies, summarizes and synthesizes;
- deterministic StoX services calculate values that StoX can calculate reliably;
- AI-002 should prefer derived-analysis tools over asking the model to recompute portfolio weights, sector allocation, concentration, relative performance, valuation aggregates or similar deterministic metrics from raw data;
- Strategy, authorization and execution safeguards remain authoritative.

### 4.7 Data Export vs operational CSV export

`V9-DATA-001` is the reusable investor-facing export framework. `V9-OPS-001` includes an Admin operational CSV requirement for bootstrap run/gap/warning data.

Implementation should reuse the shared export primitives/adapters from V9-DATA-001 where practical rather than introducing a second generic export stack. OPS-specific datasets/authorization remain Admin-only and may expose only the operational scope defined by OPS-001.

The operational CSV requirement remains valid even if implemented through the shared framework.

### 4.8 Combo chart export integration

`V9-VIZ-001` adds preset combo charts; `V9-DATA-001` supports export of chart underlying data.

Combo-chart presets should register/export their canonical underlying series through the shared export framework when both epics are present. Export remains data-only; VIZ does not introduce image export or synthetic values.

### 4.9 Historical fundamentals dependency

`V9-OPS-001` operates the existing V8 `V4-FEAT-054` historical-fundamentals bootstrap engine; it must not create a parallel ingestion engine.

`V9-VIZ-001` consumes canonical historical price/fundamental data and does not depend on the V9 Admin operations UI being complete, provided the underlying V8 data engine/data are available.

### 4.10 Telemetry boundary

V8 `V4-FEAT-052` remains the owner of StoX OpenTelemetry instrumentation/export to LidoTelemetry. V9 features may emit telemetry through that established path but must not recreate telemetry storage/analytics/platform administration inside StoX.

## 5. Dependency graph

### Hard/strong dependencies

- `V9-UX-002` -> authoritative corpus/governance from `V9-UX-001` foundation.
- `V9-OPS-001` -> V8 `V4-FEAT-054` engine.
- `V9-OPS-001` notifications -> `V9-COMM-001`.
- `V9-DATA-001` background completion/failure notifications -> `V9-COMM-001`.
- `V4-FEAT-017` governance/budget/prompt notifications -> `V9-COMM-001` notification framework.
- `V9-AI-001` -> `V4-FEAT-017` and maintained journey/help corpus; deterministic fallback integrates with `V9-UX-002`.
- `V9-AI-002` -> `V4-FEAT-017` + `V9-AI-001` assistant UX/foundation.
- Final V9 release acceptance -> final `V9-UX-001` journey conformance/E2E closure.

### Reuse dependencies / preferred ordering

- `V9-OPS-001` operational export should reuse `V9-DATA-001` primitives where practical.
- `V9-VIZ-001` chart-series export should integrate with `V9-DATA-001`.
- AI-002 should expose governed tools only for V9 features that actually exist and whose authorization/domain services are stable.

## 6. Recommended implementation sequence

The implementation agent may parallelize independent work, but the following ordering/gates should be preserved.

### Phase 0 — Release foundation

**0A. V9-UX-001 foundation slice**

Start first. Establish:

- journey audit/conformance process;
- stable journey IDs/metadata contract;
- E2E harness and deterministic data conventions;
- CI/nightly structure;
- traceability expectations.

Do **not** close UX-001 yet.

### Phase 1 — Shared non-AI infrastructure

**1A. V9-COMM-001 — Notifications & account lifecycle messaging**

Implement early because OPS, DATA and AI platform consume its notification model.

**1B. V9-DATA-001 — Data Export Framework**

Establish reusable export primitives before OPS operational export and before final combo-chart export integration.

These can progress after the relevant COMM notification contracts are stable; not every COMM UI detail must block export core work.

### Phase 2 — Deterministic/user-facing feature layer

The following can proceed largely in parallel once their prerequisites are satisfied:

**2A. V9-UX-002 — Deterministic “How Do I?” search**

Depends on the maintained journey corpus/metadata foundation from UX-001.

**2B. V9-OPS-001 — Historical Fundamentals Admin Operations**

Depends on V8 FEAT-054; reuse COMM notifications and DATA export primitives.

**2C. V9-UX-003 — Dashboard customization**

Largely independent; add/update journeys and E2E coverage through UX-001 as it lands.

**2D. V9-VIZ-001 — Combo charts**

Consumes existing canonical historical data; integrate chart-data export with DATA framework and add relevant journey/E2E coverage.

### Phase 3 — Shared AI platform

**3A. V4-FEAT-017 — AI Platform & Governance**

Implement the capability registry, adapters, ordered routing, logging, budgets, prompt governance, Admin controls, circuit breakers, structured outputs, streaming, service classes and concurrency.

COMM notification integration must be available for required Admin alerts. This platform must be stable before feature AI epics depend on it.

### Phase 4 — Read-only AI experience

**4A. V9-AI-001 — Documentation-Grounded StoX Chatbot**

Requires the AI platform plus maintained documentation/journey corpus. Integrate explicit deterministic-help fallback with UX-002.

### Phase 5 — Tool-augmented reasoning and governed actions

**5A. V9-AI-002 — Agentic StoX Assistant / MCP Action Layer**

Implement last among substantive features so its governed read/mutation tools can bind to stable V9 domain services and authorization contracts.

Must preserve:

- deterministic read-analysis preference;
- bounded read-tool loops;
- mutation preview/approval;
- no broker order actions;
- no external MCP tools;
- stale-state checks, idempotency and verification.

### Phase 6 — Release closure

**6A. V9-UX-001 final closure**

After all V9 feature work is present:

- audit every resulting major V9 user journey;
- resolve all documented differences;
- add/refresh required E2E, mobile, visual and accessibility coverage;
- validate production smoke expectations;
- verify documentation-to-test traceability;
- close UX-001 only when its 100% agreed journey criteria are satisfied.

**6B. Final release audit**

Confirm all V9 epics meet their acceptance criteria and no frozen-spec deviation remains unresolved.

## 7. Parallelization guidance

Safe parallelism is encouraged where it does not violate dependencies:

- UX-001 foundation can run while COMM begins.
- After UX-001 corpus conventions stabilize, UX-002 can proceed independently of DATA/OPS/UX-003/VIZ.
- DATA, UX-003 and VIZ can largely proceed in parallel.
- OPS can proceed once the V8 engine is verified and shared notification/export contracts it needs are stable.
- AI platform can begin architecture/core adapter work while other deterministic features progress, but its notification-dependent acceptance items require COMM.
- AI-001 must not bypass the AI platform by directly integrating providers to get ahead of Phase 3.
- AI-002 should follow AI-001 and should bind only to stable governed StoX tool contracts.

## 8. Implementation-agent rules

The implementation agent should:

1. treat every frozen epic spec plus this audit/sequence document as authoritative;
2. use implementation-level judgment without seeking PO input unless a genuinely new product decision is encountered;
3. preserve existing working behavior unless a frozen spec explicitly changes it;
4. reuse existing components/services rather than creating parallel frameworks;
5. keep commits/work packages logically scoped and auditable;
6. update user journeys and automated coverage alongside feature delivery, not as an afterthought;
7. stop and surface only genuine frozen-spec conflicts or decisions that materially alter product behavior;
8. never silently weaken safety, authorization, financial execution, deterministic-computation or audit requirements to simplify implementation.

## 9. V9 implementation-ready declaration

With the above reconciliations and sequence, the V9 specification set is complete.

**StoX V9 is declared IMPLEMENTATION-READY.**

Implementation may begin automatically from the canonical V9 register and linked frozen specifications. No further PO planning gate is required unless implementation discovers a genuinely new material product decision or a direct contradiction not resolvable within the frozen contracts.
