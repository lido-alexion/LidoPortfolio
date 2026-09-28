# StoX V9 — AI Technical Architecture Specification

| Field | Value |
|---|---|
| **Version** | V9 |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Document type** | Normative technical architecture for StoX AI epics |
| **Applies to** | `V4-FEAT-017`, `V9-AI-001`, `V9-AI-002` |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Architecture owner** | Product / Architecture |

## 1. Purpose

This specification converts the frozen V9 AI product contracts into an explicit implementation architecture so the implementation agent does not need to infer major technology or service-boundary decisions.

The architecture deliberately keeps StoX's existing Laravel application as the authoritative business backend while introducing a separate Python AI runtime for capabilities that benefit materially from the Python AI/MCP ecosystem.

The core rule is:

> **Laravel owns StoX. Python owns AI execution. Python never becomes an alternative StoX backend.**

This document is normative for V9 implementation unless a repository/runtime constraint makes a specific low-level choice impossible. Any departure that changes the trust boundary, system-of-record ownership, authorization model, or provider/tool orchestration model requires architecture review.

## 2. Frozen technology choices

### 2.1 PHP/Laravel remains authoritative

StoX continues to use the existing Laravel backend for:

- authentication and session/account identity;
- authorization and role/policy enforcement;
- account scoping;
- database ownership and persistence;
- portfolio/holding/transaction domain logic;
- screeners, strategies, recommendations and artifacts;
- dashboards/preferences;
- deterministic calculations and derived-analysis services;
- mutation validation;
- mutation approval state;
- idempotency authority for StoX state changes;
- audit/business records;
- notifications/email;
- Admin configuration persistence and UI;
- existing Laravel queues and scheduled application jobs where appropriate.

The Python AI runtime must not duplicate these responsibilities.

### 2.2 Python owns the AI runtime

Introduce one separately deployed/supervised Python service, referred to in this document as **StoX AI Runtime**.

It owns:

- capability execution for AI features;
- inference-provider adapters;
- canonical ordered provider/model failover execution;
- model streaming integration;
- structured-output validation at the AI boundary;
- AI-specific retry/failover and circuit-breaker execution;
- RAG/index/retrieval for documentation-grounded AI;
- planner and synthesis loops;
- bounded tool-use orchestration;
- FastMCP server/client integration;
- Pydantic request/response/tool schemas;
- AI-side prompt assembly;
- emission of inference usage/cost/routing/log events back to the authoritative StoX persistence layer.

Python must remain replaceable as an AI execution layer without moving StoX business authority out of Laravel.

### 2.3 FastMCP

Use **FastMCP 4.x** as the preferred MCP framework for V9-AI-002.

Use FastMCP for:

- typed MCP tool declaration;
- tool schema publication;
- MCP protocol handling;
- tool dispatch integration;
- typed/Pydantic-compatible outputs;
- MCP transport/protocol plumbing;
- future-compatible tool catalog composition where useful.

Do **not** treat FastMCP as the StoX authorization, approval, business-policy, audit, or database layer. Those remain governed by StoX policy/domain services.

Pin FastMCP to an explicit compatible version range in the Python lock/dependency file. Do not silently float to a future major version.

### 2.4 Pydantic

Use modern **Pydantic** models as the preferred Python-side schema contract for:

- Laravel-to-Python AI requests;
- Python-to-Laravel internal tool requests/responses;
- provider adapter normalized results;
- structured LLM outputs;
- MCP tool inputs/outputs;
- planner/tool-plan structures;
- routing/log event payloads.

The same schemas should drive validation and tests; avoid maintaining parallel untyped dictionaries for core contracts.

### 2.5 LangChain / LangGraph

**Do not use LangChain or LangGraph as the foundational StoX AI orchestration framework in V9.**

Reason:

- StoX already has explicit frozen routing, budget, retry, permission, approval, failover and tool-state semantics;
- those semantics are product architecture and must remain visible/testable in StoX-owned code;
- hiding them inside a general agent framework would make behavior harder to audit and constrain;
- the required orchestration is bounded enough to implement directly with typed Python services.

LangChain components may be used selectively as leaf libraries only if a specific integration materially reduces implementation complexity. They must not own:

- provider ordering;
- budgets;
- circuit-breaker eligibility;
- authorization;
- mutation confirmation;
- stale-state checks;
- idempotency;
- tool allowlists;
- action-history semantics;
- retry policy that conflicts with the frozen StoX contract.

A selective LangChain dependency is optional, not architectural.

## 3. Preferred deployment topology

Initial V9 deployment should use the existing StoX VPS and run the AI runtime as an independent loopback/private service.

Preferred topology:

```text
Internet
   |
   v
nginx / StoX HTTPS endpoint
   |
   v
React SPA
   |
   v
Laravel / StoX API  --------------------------+
   |                                           |
   | owns auth/domain/DB/audit                 | private authenticated HTTP/SSE
   |                                           v
   |                                  StoX AI Runtime (Python)
   |                                           |
   |                                           +--> OpenAI-compatible providers
   |                                           +--> Anthropic/Claude adapter
   |                                           +--> Gemini adapter
   |                                           +--> local/self-hosted adapter
   |                                           +--> RAG/retrieval
   |                                           +--> FastMCP orchestration
   |                                           |
   +<----------- private StoX tool API --------+
   |
   v
MariaDB / Laravel domain services
```

### 3.1 Service isolation

Run the Python service under an independent system service/process supervisor, e.g. systemd, with:

- dedicated process/service identity where practical;
- independent restart policy;
- loopback/private bind only;
- health endpoint;
- structured application logs;
- explicit environment file/secret permissions;
- no direct public nginx route unless a later deliberate architecture change requires one.

Normal StoX non-AI functionality must remain available when the Python service is down.

### 3.2 Service port

Use an implementation-chosen loopback port, for example `127.0.0.1:<AI_PORT>`. The exact port is not a product contract. It must not be exposed directly to the internet.

## 4. Trust and authority boundary

### 4.1 Laravel is the security authority

The browser authenticates only to StoX/Laravel.

The browser must not authenticate directly to the Python AI runtime and must not receive credentials that allow direct AI-runtime access.

Laravel establishes the current:

- user ID;
- account/tenant ID;
- role/permissions;
- request context;
- approved mutation scope where applicable.

Python may use this delegated context for planning and pre-filtering, but **Laravel re-enforces authorization on every read or mutation tool execution**.

### 4.2 No direct Python database access

The Python AI runtime must **not** connect directly to StoX MariaDB for application/domain data.

Prohibited examples:

- direct SQL query of holdings from Python;
- direct Python insert/update of screeners or strategies;
- direct mutation of AI approval state;
- bypassing Laravel policies by reading tables directly.

All StoX domain reads/writes required by AI flow through typed, private Laravel APIs/domain gateways.

This preserves one business-rule implementation and prevents PHP/Python domain drift.

### 4.3 AI configuration persistence

Authoritative AI operational configuration should be persisted and administered through StoX/Laravel where practical, including:

- capability registry configuration overlay;
- path order;
- path enable/disable/holds;
- endpoint/model configuration;
- encrypted provider credentials/secrets;
- budgets/pricing;
- prompt versions/activation;
- logging-retention configuration;
- circuit-breaker settings;
- concurrency settings.

Python consumes an effective configuration projection through an internal configuration API/cache contract.

Provider secrets may be delivered to Python through a protected runtime configuration mechanism/API. They must never be returned to the browser in clear text or written to logs.

## 5. Laravel ↔ Python communication

### 5.1 Transport

Use **private authenticated HTTP** for request/response calls and **HTTP streaming/SSE** for streaming AI output.

Do not use shelling out to a Python process (`exec`, `shell_exec`, `python script.py`) for production request handling.

Do not use shared database tables as the primary RPC mechanism.

### 5.2 Preferred API shape

Illustrative internal endpoints on the Python service:

```text
POST /internal/v1/inference
POST /internal/v1/chat
POST /internal/v1/plan
POST /internal/v1/agent/run
GET  /internal/v1/health
GET  /internal/v1/capabilities
```

Exact route names are implementation-level, but version the internal contract from the start.

Streaming calls should use an endpoint capable of incremental event delivery, e.g.:

```text
POST /internal/v1/chat/stream
Content-Type: application/json
Response: text/event-stream
```

Laravel proxies/mediates the stream to the authenticated browser using the existing StoX API/session boundary.

### 5.3 Internal authentication

Laravel-to-Python calls require service authentication independent of end-user browser authentication.

Preferred initial approach on the single VPS:

- bind Python to loopback/private interface;
- use a strong shared service credential or signed short-lived internal token;
- include delegated user/account context in a signed request envelope;
- validate timestamp/expiry to reduce replay risk;
- propagate correlation/trace IDs.

A future network-distributed deployment may replace this with mTLS or workload identity without changing the domain contract.

### 5.4 Required request metadata

Every AI-runtime request should carry or derive:

- `request_id` / correlation ID;
- trace ID where available;
- StoX user ID;
- StoX account ID;
- capability ID;
- calling feature;
- service class;
- prompt/template version where resolved;
- optional page/session context;
- idempotency/agent-run identifiers where applicable.

Do not trust client-supplied authorization claims without Laravel validation/signing.

## 6. Preferred Python service structure

Use a modular structure similar to:

```text
ai-runtime/
  pyproject.toml
  uv.lock / equivalent lockfile
  stox_ai/
    app.py
    api/
      inference.py
      chat.py
      agent.py
      health.py
    capabilities/
      registry.py
      models.py
    routing/
      router.py
      eligibility.py
      circuit_breaker.py
      budgets.py
    providers/
      base.py
      openai_adapter.py
      anthropic_adapter.py
      gemini_adapter.py
      openai_compatible_adapter.py
    prompts/
      resolver.py
      renderer.py
    schemas/
      inference.py
      tools.py
      planner.py
      events.py
    retrieval/
      corpus.py
      chunking.py
      index.py
      retriever.py
    agent/
      planner.py
      orchestrator.py
      policy_client.py
      synthesis.py
    mcp/
      server.py
      tool_registry.py
      tools/
    clients/
      stox_backend.py
      config_client.py
      logging_client.py
    observability/
      logging.py
      metrics.py
```

Exact filenames may vary, but keep provider, routing, retrieval, tool, and Laravel-client concerns separated.

## 7. Provider adapter architecture

Define a StoX-owned abstract adapter interface rather than letting feature code call provider SDKs.

Illustrative contract:

```python
class InferenceProvider(Protocol):
    async def infer(self, request: InferenceRequest) -> InferenceResult: ...
    async def stream(self, request: InferenceRequest) -> AsyncIterator[InferenceEvent]: ...
    async def health(self) -> ProviderHealth: ...
    def capabilities(self) -> ProviderCapabilities: ...
```

Normalize provider-specific failures into StoX categories such as:

- authentication/configuration failure;
- timeout;
- rate limit;
- transient provider failure;
- network failure;
- malformed output;
- unsupported capability;
- cancellation.

The ordered router consumes only the normalized contract.

## 8. Capability routing implementation

Implement the frozen V4-FEAT-017 routing semantics explicitly in StoX-owned Python code.

Preferred flow:

```text
Capability request
   -> load capability definition
   -> load effective ordered paths
   -> evaluate global/capability/path/user budget eligibility
   -> evaluate enabled/configured/healthy/manual-hold state
   -> attempt first eligible path
   -> on eligible failover condition, advance
   -> record every skipped/attempted path
   -> return result or structured total-failure result
```

Do not implement separate quality and budget path orders.

Per-user budget/path eligibility must be evaluated in the current user context so exhaustion can remove one path for one user without globally disabling it.

## 9. RAG architecture for V9-AI-001

### 9.1 Location

Run the documentation-grounded retrieval pipeline inside the Python AI runtime.

### 9.2 Source corpus

Index only approved user-facing StoX sources defined by V9-AI-001, prioritizing:

1. authoritative `docs/user-journeys/` content;
2. approved user/product documentation;
3. approved user-facing reference/troubleshooting material.

Do not indiscriminately index internal engineering/planning documents into the user-facing chatbot corpus.

### 9.3 Retrieval implementation

The StoX documentation corpus is expected to remain modest in V9. Prefer a simple, inspectable retrieval stack before introducing a heavyweight distributed vector database.

Recommended progression:

1. deterministic metadata/keyword retrieval shared conceptually with V9-UX-002 where useful;
2. local embedding retrieval using a small supported embedding model if needed for semantic recall;
3. hybrid lexical + vector ranking;
4. reranking only if evaluation demonstrates a material benefit.

A dedicated external vector database is **not required by architecture** for V9. Use a local persistent vector/index implementation or existing relational/vector support where operationally sensible.

The implementation must preserve source IDs, section anchors and snippets so the final answer can cite the exact StoX sources used.

### 9.4 Index lifecycle

Provide deterministic index rebuild/update behavior tied to documentation changes/deployments. Stale removed documents must not remain retrievable indefinitely.

## 10. FastMCP tool architecture for V9-AI-002

### 10.1 FastMCP is a façade, not domain ownership

Preferred call path:

```text
LLM planner
  -> FastMCP tool definition
  -> Python deterministic orchestration/policy client
  -> Laravel private agent-tool endpoint
  -> Laravel authorization/policy
  -> Laravel domain service
  -> MariaDB
```

Never:

```text
LLM -> FastMCP -> direct SQL
```

### 10.2 Tool definition

Each MCP tool should map to one stable StoX capability and declare a typed Pydantic input/output contract.

Example conceptual tool:

```python
@mcp.tool
async def get_portfolio_diversification(portfolio_id: int) -> DiversificationResult:
    return await stox_backend.get_portfolio_diversification(portfolio_id)
```

The Python tool wrapper must not reimplement the portfolio calculation. Laravel's deterministic analysis service owns the calculation.

### 10.3 Read tools

Examples of preferred read tools:

- `portfolio.get_holdings`
- `portfolio.get_diversification_metrics`
- `portfolio.get_sector_allocation`
- `portfolio.get_concentration_metrics`
- `portfolio.get_performance_summary`
- `stock.get_fundamentals`
- `stock.get_historical_fundamentals`
- `benchmark.get_relative_performance`
- `screener.get`
- `strategy.get`
- `watchlist.get`

Prefer derived-analysis tools when StoX can deterministically compute the requested concept.

### 10.4 Mutation tools

Examples:

- `screener.create`
- `screener.update`
- `strategy.create`
- `strategy.update`
- `watchlist.add_stock`
- `watchlist.remove_stock`
- `dashboard.update_preferences`

Mutation tools ultimately call Laravel endpoints that enforce frozen approval scope, current-user authorization, validation and idempotency.

No broker order place/modify/cancel tools are exposed in V9.

### 10.5 Dynamic tool exposure

The Python orchestrator should construct the callable tool catalog per capability/request using:

- capability allowlist;
- current-user permissions supplied/confirmed by Laravel;
- tool side-effect classification;
- feature state.

Laravel still rechecks authorization on invocation.

## 11. Agent planning/orchestration

Implement a small explicit state machine in StoX-owned Python code rather than delegating execution semantics to a generic agent framework.

### 11.1 Read-only reasoning loop

Preferred states:

```text
RECEIVE_QUESTION
 -> PLAN_REQUIRED_EVIDENCE
 -> SELECT_ALLOWED_READ_TOOLS
 -> EXECUTE_READ_TOOLS
 -> EVALUATE_EVIDENCE
 -> [if insufficient and iteration budget remains] PLAN_ADDITIONAL_EVIDENCE
 -> SYNTHESIZE
 -> RETURN_ANSWER
```

Bound with configuration such as:

- max planning iterations;
- max tool calls;
- total wall-clock timeout;
- AI cost/budget enforcement;
- per-tool response-size limits.

### 11.2 Mutation loop

Preferred states:

```text
RECEIVE_REQUEST
 -> READ_CURRENT_STATE
 -> BUILD_TYPED_PLAN
 -> VALIDATE_PLAN
 -> PRESENT_PREVIEW
 -> WAIT_FOR_USER_APPROVAL
 -> RELOAD_CURRENT_STATE
 -> CHECK_STALENESS
 -> EXECUTE_APPROVED_TOOLS
 -> STOP_ON_FAILURE
 -> VERIFY_BY_READBACK
 -> RECORD_HISTORY/AUDIT
 -> RETURN_RESULT
```

The approved plan should receive a stable plan/run ID and a digest/hash of the material approved mutation scope so Python/Laravel can reject unapproved plan expansion.

## 12. Deterministic Laravel tool gateway

Add a private Laravel agent-tool API/gateway rather than exposing arbitrary existing REST endpoints directly to the model.

Preferred characteristics:

- route namespace clearly marked internal, e.g. `/internal/ai-tools/v1/...`;
- service-authenticated; not browser-callable;
- typed DTO/request validation;
- current user/account identity supplied through trusted internal envelope;
- normal Laravel policies/authorization re-enforced;
- explicit side-effect classification;
- idempotency key support for mutations;
- approved-plan/run ID for mutation calls;
- audit event creation;
- consistent structured error envelope;
- rate/safety limits where relevant.

The internal tool gateway should call existing domain/application services wherever possible rather than duplicating controller logic.

## 13. Streaming architecture

For user-facing chat:

```text
Browser
 -> Laravel authenticated endpoint
 -> Python streaming endpoint
 -> provider streaming API
 -> Python normalized events
 -> Laravel proxy
 -> Browser SSE stream
```

Preferred normalized events:

- `message.start`
- `message.delta`
- `source`
- `tool.trace` (safe user-facing trace only)
- `message.completed`
- `usage`
- `error`

Do not stream private chain-of-thought or raw internal planning prompts to the user.

Laravel should terminate/proxy the browser-facing stream so Python is never directly exposed publicly.

## 14. Background AI work

Use the appropriate existing queue boundary depending on job ownership:

- Laravel queues own StoX business jobs and trigger AI capabilities as needed;
- Python may maintain internal async execution for one inference/tool workflow;
- do not create an independent durable business-job truth in Python when Laravel already owns the business workflow.

If a future AI capability needs long-running durable AI jobs, persist canonical job state in Laravel and treat Python execution state as subordinate/recoverable.

## 15. Logging, audit and observability ownership

### 15.1 Canonical persistence

Prefer Laravel/MariaDB as the canonical store for:

- inference call metadata required by V4-FEAT-017;
- cost/usage ledger;
- routing attempt history;
- prompt/response retained logs;
- prompt governance audit;
- agent run/action history;
- mutation audit.

Python emits normalized events/records to Laravel or an agreed durable sink; it must not become the only place where audit-critical evidence exists.

### 15.2 Local runtime logs

Python service logs may contain operational diagnostics but must exclude secrets and should avoid raw prompts/responses unless explicitly required for transient debugging and consistent with the configured logging policy.

### 15.3 Trace propagation

Propagate a common trace/correlation ID across:

```text
Browser -> Laravel -> Python -> Provider -> Python -> Laravel tool gateway -> domain service
```

V8 OpenTelemetry remains the platform telemetry path; do not create a parallel V9 telemetry platform.

## 16. Prompt registry ownership

Laravel owns prompt/template persistence, version history, Admin editing, approval workflow and active-version selection.

Python obtains the active approved prompt version through the internal configuration/prompt API and performs rendering/execution.

Cache prompt definitions in Python only with explicit version identifiers and safe invalidation. Never allow an unapproved draft prompt to become effective because of stale cache behavior.

## 17. Budget ownership

Laravel owns authoritative budget configuration and spend ledger persistence.

Python enforces routing eligibility using an effective budget snapshot/decision service and reports actual/estimated usage after attempts.

Preferred rule:

- before an attempt, obtain/evaluate current eligibility;
- reserve or conservatively account for spend where needed to avoid high-concurrency overshoot;
- after completion, reconcile actual provider-reported tokens/cost estimate;
- use transactional/atomic Laravel-side logic for shared budget counters.

Do not keep authoritative monthly budget counters only in Python process memory.

## 18. Failure isolation

### Python unavailable

- non-AI StoX functions remain operational;
- AI UI shows a clear temporary-unavailable state;
- deterministic V9-UX-002 help remains available;
- feature-specific Copy Prompt fallback may be offered where permitted.

### Provider unavailable

Python applies the frozen canonical ordered failover/circuit-breaker semantics.

### Laravel internal tool endpoint unavailable

- read-only agent investigation reports unavailable evidence;
- mutations do not proceed;
- no direct DB fallback is allowed.

### Stream interruption

- terminate cleanly with a normalized error;
- retain attempt metadata;
- do not silently continue a mutation workflow after client disconnect unless the approved operation has already crossed a defined execution boundary and server-side completion is required for consistency;
- final state must remain queryable through action history/audit.

## 19. Security requirements

- Python binds privately/loopback in initial deployment.
- Browser cannot call Python directly.
- Internal Laravel/Python requests are authenticated and integrity-protected.
- Provider keys/secrets are never sent to the browser.
- Python never gets unrestricted database credentials for StoX domain tables.
- MCP tools never provide arbitrary SQL/shell/file execution.
- Tool input schemas reject unknown/unsafe fields where appropriate.
- Laravel rechecks permissions on every tool call.
- Mutation endpoints require valid approved-plan context.
- All destructive operations preserve the stronger confirmation semantics frozen in AI-002.
- AI-generated text is never treated as trusted executable code/configuration without deterministic validation.

## 20. API/error contract

Use a stable structured internal error model, for example:

```json
{
  "code": "AI_PROVIDER_TIMEOUT",
  "category": "transient_provider_failure",
  "retryable": true,
  "message": "Provider timed out",
  "details": {},
  "request_id": "..."
}
```

Do not make calling features parse provider-specific exception strings.

Tool errors should distinguish at least:

- unauthorized/forbidden;
- validation failed;
- stale state;
- approval required/invalid approval scope;
- not found;
- conflict;
- idempotency conflict;
- transient backend failure;
- permanent domain failure.

## 21. Testing architecture

### 21.1 Python unit tests

Cover:

- provider adapters with fakes;
- canonical routing/failover;
- budget eligibility filtering;
- circuit-breaker state transitions;
- prompt rendering;
- structured-output validation;
- planner state transitions;
- tool catalog filtering;
- RAG ranking/citation assembly;
- bounded read loops;
- mutation-plan deviation detection.

### 21.2 Laravel tests

Cover:

- internal service authentication;
- authorization per tool;
- plan/approval validation;
- idempotency;
- deterministic derived-analysis services;
- audit persistence;
- AI Admin configuration APIs;
- prompt governance;
- budget atomicity/ledger behavior.

### 21.3 Contract tests

Maintain contract tests across PHP/Python for every versioned internal schema. Prefer checked fixtures/OpenAPI/JSON Schema generation where practical so drift fails CI.

### 21.4 Integration tests

Run Laravel + Python together using fake inference providers and deterministic MCP/tool responses.

Test:

- streaming;
- provider failover;
- Python failure isolation;
- tool permission denial;
- stale mutation plan;
- partial failure;
- post-action readback;
- RAG grounding/citations.

Normal CI must not require paid external LLM availability.

## 22. Dependency management

Python runtime should have its own `pyproject.toml` and lockfile.

Recommended categories:

- ASGI framework/server (FastAPI/Starlette-compatible implementation is acceptable; exact choice may be implementation-level);
- `fastmcp` 4.x pinned;
- `pydantic` current compatible major;
- async HTTP client such as `httpx`;
- provider SDKs only where their adapter materially benefits;
- retrieval/embedding libraries kept minimal;
- test tooling (`pytest`, async test support).

Avoid importing a broad agent framework solely for convenience.

PHP dependencies remain in the existing Laravel Composer project.

## 23. Recommended ASGI/API framework

Preferred Python HTTP layer: **FastAPI** (or a directly compatible lightweight ASGI stack) because it aligns naturally with Pydantic contracts, async provider calls, streaming and generated API schemas.

This is a preferred architecture choice rather than a product requirement; if implementation selects another lightweight ASGI framework, it must preserve the same typed, async, streaming and OpenAPI/contract behavior.

## 24. Development and deployment layout

Preferred repository placement is within the same LidoPortfolio repository so PHP/Python contracts evolve atomically, for example:

```text
app/                 # existing Laravel application
ai-runtime/          # Python AI runtime
  pyproject.toml
  ...
docs/
```

Advantages:

- one commit can change both sides of an internal contract;
- CI can run contract/integration tests together;
- deployment release tags identify matching PHP/Python versions;
- implementation agent has one source tree and authoritative specs.

Do not create a separate repository for V9 unless there is a compelling operational reason discovered during implementation.

Deployment should package/restart the Python service together with the StoX release while keeping independent process health/restart behavior.

## 25. Configuration flow

Preferred runtime configuration flow:

```text
StoX Admin UI
  -> Laravel configuration API
  -> authoritative persistence/audit
  -> configuration version increments
  -> Python polls/refreshes or receives invalidation
  -> Python caches effective configuration by version
```

No frontend-only AI configuration state.

A Python `/health` or `/runtime-config-status` endpoint should expose safe metadata such as effective configuration version so Admin can detect drift.

## 26. Health checks

Python service should expose separate safe health concepts:

- process/liveness;
- readiness;
- configuration loaded;
- provider path health summary;
- retrieval/index readiness;
- MCP/tool gateway reachability.

Overall StoX health must distinguish **AI degraded** from **StoX unavailable**.

## 27. Concrete end-to-end flows

### 27.1 Documentation chat

```text
1. Browser asks question in StoX assistant.
2. Laravel authenticates user and sends capability request to Python.
3. Python retrieves user-journey/product-doc evidence.
4. Python resolves active prompt and effective inference route.
5. Provider generates grounded response, preferably streaming.
6. Python emits answer + citations + grounding metadata.
7. Laravel proxies response to browser and persists required inference metadata.
```

### 27.2 Portfolio reasoning

```text
User: "How diversified is my portfolio?"

1. Laravel authenticates and invokes AI-002 reasoning capability.
2. Python planner determines required evidence.
3. Python exposes/chooses authorized read tools.
4. FastMCP wrapper calls Laravel internal tool gateway.
5. Laravel authorizes user and calls deterministic portfolio-analysis services.
6. Laravel returns sector weights/concentration/etc. as typed results.
7. Python may request another bounded read tool if evidence is insufficient.
8. Python sends structured evidence + original question to synthesis model.
9. Final answer explains StoX-computed facts; it does not recalculate them independently.
10. User may expand safe tool trace.
```

### 27.3 Mutation workflow

```text
User: "Add ROCE > 18% to my Quality screener."

1. Laravel invokes agent capability.
2. Python reads current screener through governed read tool.
3. Python builds typed proposed mutation plan.
4. Laravel/policy validates that proposed tools are allowed.
5. Browser receives preview and asks user to approve.
6. Approval is persisted with plan/run ID and material-scope digest.
7. Python re-reads current state.
8. If stale materially: stop and re-plan.
9. Python invokes update tool through FastMCP/Laravel gateway with idempotency key and approved plan ID.
10. Laravel rechecks authorization + approval scope + validation, then mutates through domain service.
11. Python read-backs the screener.
12. Laravel records action history/audit and UI shows verified result.
```

## 28. What must not be implemented

The following architectural shortcuts are explicitly prohibited for V9:

- Python directly using StoX MariaDB as its domain API;
- browser directly calling Python AI service;
- model receiving arbitrary SQL or shell tools;
- LangChain/LangGraph silently controlling frozen StoX routing/approval semantics;
- FastMCP bypassing Laravel authorization/domain services;
- separate provider-routing implementations in PHP and Python;
- individual AI features directly calling provider SDKs;
- duplicate prompt registries in PHP and Python;
- duplicate budget ledgers in PHP and Python;
- durable agent mutation state existing only in Python memory;
- exposing private chain-of-thought in user traces;
- allowing broker trading tools in V9-AI-002.

## 29. Implementation order inside the AI wave

Preferred technical implementation order:

1. Create Python runtime skeleton, health endpoints, typed Laravel/Python contract and service auth.
2. Implement capability registry projection + provider adapter interface.
3. Implement ordered routing, normalized failures, usage/cost events and circuit breaker.
4. Implement streaming contract.
5. Implement prompt/config clients and Admin-backed configuration synchronization.
6. Implement logging/budget persistence integration.
7. Implement AI-001 retrieval/indexing and documentation-chat capability.
8. Implement FastMCP tool layer + Laravel internal agent-tool gateway.
9. Implement deterministic read-only planner/tool/synthesis loop.
10. Implement mutation planning/approval/idempotency/stale-state/verification flow.
11. Add full contract/integration/E2E coverage and production health wiring.

This technical sequence sits inside the release-level sequence defined in `V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md`.

## 30. Definition of done

This architecture is correctly implemented when:

- Laravel remains the only authoritative StoX domain/database/security boundary;
- a separately supervised Python AI runtime exists and is private from the browser/internet;
- Laravel/Python communication is versioned, typed and authenticated;
- Python has no direct StoX domain DB access;
- provider adapters and canonical routing live in the Python AI runtime;
- FastMCP 4.x implements the MCP façade for AI-002 while Laravel remains final tool authority;
- Pydantic contracts are used across core Python request/tool/result schemas;
- LangChain is not required for core orchestration;
- AI-001 RAG/retrieval runs in Python with inspectable StoX source provenance;
- AI-002 uses explicit bounded orchestration/state transitions, not unrestricted autonomous agents;
- inference/audit/budget/action evidence is durably persisted through StoX-owned storage;
- streaming works without exposing Python directly;
- non-AI StoX remains usable during AI-runtime failure;
- PHP/Python contract, authorization, failure and agent workflows are covered by automated tests.

## 31. Frozen architecture decisions summary

- **PHP/Laravel + Python hybrid architecture.**
- **Laravel is authoritative for business/domain/security/persistence.**
- **Python is authoritative only for AI execution/orchestration mechanics.**
- **Private HTTP + SSE between Laravel and Python.**
- **No direct Python access to StoX MariaDB/domain tables.**
- **Browser never calls Python directly.**
- **FastMCP 4.x is the preferred MCP framework.**
- **Pydantic is the preferred typed-schema layer in Python.**
- **FastAPI/lightweight ASGI is the preferred Python HTTP service pattern.**
- **LangChain/LangGraph are not core dependencies; selective leaf usage only if justified.**
- **RAG/document retrieval resides in Python AI runtime.**
- **No mandatory heavyweight vector database for V9.**
- **MCP tools call a private Laravel agent-tool gateway/domain services.**
- **Laravel re-authorizes every tool call.**
- **Deterministic calculations remain Laravel/StoX services.**
- **Agent loops are explicit bounded StoX-owned state machines.**
- **Canonical logs/budgets/prompt governance/action history persist through StoX/Laravel storage.**
- **Python service is failure-isolated from normal StoX.**
- **Same repository is preferred for PHP and Python to keep contracts atomic.**
