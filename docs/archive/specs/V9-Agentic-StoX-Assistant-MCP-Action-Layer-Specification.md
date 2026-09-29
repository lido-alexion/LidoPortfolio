# V9 Agentic StoX Assistant / MCP Action Layer Specification

| Field | Value |
|---|---|
| **Epic** | V9-AI-002 |
| **Status** | FROZEN / IMPLEMENTATION-READY |
| **Release** | V9 |
| **Depends on** | V4-FEAT-017 AI Platform & Governance; V9-AI-001 Documentation-Grounded StoX Chatbot |

## 1. Purpose

Extend the StoX assistant from documentation-grounded read-only help into a governed, tool-augmented assistant that can:

1. answer account-specific questions by planning and invoking authorized StoX read tools;
2. combine deterministic StoX analysis with LLM reasoning, explanation and summarization;
3. execute explicitly authorized StoX mutations through typed MCP-style tools;
4. preserve existing StoX authorization, deterministic strategy logic, artifact/versioning rules and execution safeguards.

This epic does not grant unrestricted database/API/UI access to the model and does not include broker order placement, modification or cancellation.

## 2. Product boundaries

### 2.1 In scope

- Broad governed StoX actions such as creating/updating screeners, strategies/artifacts, watchlists, preferences, dashboards, supported data operations and workflow preparation.
- Read-only account-aware reasoning such as portfolio diversification, sector concentration, valuation/fundamental summaries, benchmark comparisons and related questions.
- Tool-augmented planning, read-tool loops, deterministic derived-analysis tools and LLM synthesis.
- Typed MCP-style read and mutation tools.
- Plan preview, grouped confirmation, execution, verification, auditability, action history and recovery.

### 2.2 Explicitly out of scope

- Placing, modifying or cancelling broker orders.
- Scheduled or recurring autonomous agentic actions.
- External systems/tools such as GitHub, email, calendar or arbitrary third-party MCP tools.
- Arbitrary database mutation, unrestricted endpoint invocation or UI-driving automation as the primary action mechanism.

## 3. Governing principles

1. **Deterministic computation stays deterministic.** If StoX can calculate a value deterministically, the LLM must not recompute it from raw data when an appropriate StoX tool exists.
2. **LLM intelligence is used for planning, reasoning, interpretation and synthesis.**
3. **The current user's normal StoX authorization is authoritative.** The agent has no elevated service role.
4. **Every mutation requires explicit preview and approval.**
5. **Read-only investigation may proceed without confirmation, but remains bounded and fully traced.**
6. **The model never executes tools directly without deterministic policy/orchestration mediation.**
7. **Material deviation from an approved mutation plan requires a fresh preview and approval.**

## 4. Tool architecture

### 4.1 Common MCP-style contract

Every StoX tool exposes a stable typed contract including at least:

- tool ID;
- description/purpose;
- typed input schema;
- typed output schema;
- authorization requirements;
- validation rules;
- side-effect classification (`read` / `mutation` / `destructive`);
- confirmation requirement;
- idempotency behavior;
- audit metadata requirements;
- documented failure semantics.

Read and mutation tools share the same governed tool layer.

### 4.2 Dynamic tool exposure

The planner is shown only the tools that are relevant to the active capability and permitted for the current user. Unauthorized or irrelevant tools are not exposed as callable options.

### 4.3 Deterministic policy/orchestration layer

All tool calls are mediated by a deterministic layer that enforces:

- role and permission checks;
- capability/tool allowlists;
- approved-plan scope;
- confirmation state;
- input validation;
- side-effect classification;
- idempotency requirements;
- tool availability/health;
- stale-state checks;
- audit and trace requirements.

The model may propose a tool call, but the policy layer decides whether it is allowed to execute.

## 5. Read-only reasoning and summarization

### 5.1 Planner-driven data acquisition

For account-specific questions, the assistant first determines what evidence or tools are needed. Example:

User asks: **"How diversified is my portfolio?"**

A planner may determine that it needs holdings, sector allocation, top-position concentration, market-cap mix and benchmark context. It then invokes the minimum relevant authorized read/analysis tools and passes the resulting structured evidence to the LLM for interpretation and synthesis.

### 5.2 Autonomous bounded read-tool loops

Read-only investigations may iterate autonomously:

1. plan;
2. call authorized read tools;
3. inspect structured results;
4. request additional read tools if evidence is still insufficient;
5. synthesize a final answer.

These loops require no mutation confirmation, but are bounded by configured iteration/tool-call limits, timeouts, AI budgets, concurrency controls and normal authorization.

### 5.3 Derived-analysis preference

When deterministic StoX analytical tools exist, they are preferred over giving raw data to the LLM for calculation. Examples include:

- portfolio weights;
- sector allocation;
- top-N concentration;
- diversification/concentration measures;
- valuation aggregates;
- relative-performance calculations;
- benchmark comparisons;
- deterministic trend/metric summaries.

The LLM interprets these outputs rather than replacing StoX calculations.

### 5.4 Combining analyses

The planner may combine multiple deterministic analyses in one answer when needed. It should choose the minimum relevant tool set rather than running a fixed broad bundle for every question.

### 5.5 Missing/incomplete data

Answers must explicitly disclose relevant unavailable or incomplete data, state which parts of the requested analysis were weakened or omitted, and qualify whether the remaining conclusion is still sufficiently supported.

### 5.6 User-visible investigation trace

Read-only reasoning exposes a concise expandable trace of observable steps, for example:

- Read holdings
- Calculated sector exposure
- Retrieved fundamentals for relevant holdings
- Compared against benchmark
- Generated synthesis

The UI must not expose private model chain-of-thought. It shows only plan/tool actions, key evidence summaries and outcome-relevant trace metadata.

## 6. Mutation planning and approval

### 6.1 Grouped plan preview

Before any mutation, the assistant must show a concise approval preview containing:

- intended action set;
- affected StoX objects;
- meaningful tool/action steps;
- a short reason for each meaningful step;
- important consequences;
- validation results;
- warnings;
- destructive/reversibility information where applicable.

Raw MCP payloads should not be shown by default.

### 6.2 Grouped confirmation

The user confirms the proposed mutation plan as one approved scope. Individual confirmation before every tool call is not required once the grouped plan has been approved.

### 6.3 No silent plan expansion

The assistant may vary minor internal implementation details, but it may not add new mutations, affect additional objects or materially alter consequences without generating a revised plan and obtaining fresh user approval.

### 6.4 Drafts are still mutations

Creating or modifying drafts is a StoX state mutation and therefore requires the same preview and grouped approval flow.

### 6.5 Destructive actions

Deletion is permitted only where the current user is authorized and requires explicit destructive-action confirmation. The preview must identify:

- object(s) to be deleted;
- whether deletion is reversible;
- known dependencies/side effects;
- a safer archive/deactivate alternative where StoX supports one.

## 7. Execution semantics

### 7.1 Autonomous chaining after approval

After approval, the assistant may execute the necessary tool sequence autonomously as long as every step remains inside the approved scope.

### 7.2 Pre-execution stale-state check

Immediately before executing relevant mutations, the system re-reads current state. If nothing material changed, execution may continue. If state changed materially, execution stops, a fresh plan is generated against current state, and new approval is required.

### 7.3 Stop on unexpected partial failure

If an approved multi-step workflow partially fails:

- stop further mutation execution;
- report completed actions;
- report the failed action;
- report actions not yet attempted;
- report current resulting state;
- provide safe recovery/retry options.

Do not blindly continue and do not assume rollback is always possible.

### 7.4 Idempotency

Mutation tools must use idempotency keys or equivalent safeguards wherever feasible so retries cannot accidentally duplicate effects.

### 7.5 Post-execution verification

After successful mutations, the assistant must use read tools to verify the resulting state. The final response reports:

- what changed;
- whether verification matched the approved intent;
- any divergence or warning;
- whether further action is required.

## 8. Permissions and security

- Tool access is role-aware and account-scoped.
- Existing StoX authorization remains authoritative.
- The assistant has no elevated service role.
- If a user cannot perform an operation manually, the assistant cannot perform it on that user's behalf.
- Tools must enforce server-side authorization even when the planner filtered them correctly.
- External tools/systems are out of scope for V9-AI-002.

## 9. Action history and recovery

### 9.1 User-visible history

Maintain a user-visible history of agentic runs containing at least:

- original user request;
- approved plan;
- tools/actions executed;
- success/failure/partial-completion state;
- verification result;
- timestamps;
- affected StoX objects.

This complements deeper Admin/audit logs.

### 9.2 Retry from history

Retry must never blindly replay old tool calls. It must:

1. re-read current state;
2. determine what remains applicable;
3. build a fresh plan;
4. request fresh confirmation for any mutations;
5. execute under the same policy and verification rules.

## 10. Relationship to V9-AI-001

`V9-AI-001` remains the documentation-grounded read-only assistant for product help, visible-page explanations and navigation. `V9-AI-002` extends that assistant with governed tools and account-specific read reasoning.

In V9-AI-002, the assistant may independently query authorized StoX account data through read tools in order to answer questions. This later-phase capability intentionally supersedes the narrower V9-AI-001 rule that limited analysis to visible page data.

## 11. Relationship to V4-FEAT-017

All LLM calls, planning calls and synthesis calls use the shared AI Platform & Governance capabilities from `V4-FEAT-017`, including:

- capability-specific inference routing;
- provider/model failover;
- budgets and cost controls;
- logging and routing traces;
- prompt/template governance;
- structured output validation;
- service classes and concurrency controls;
- circuit breakers and health handling.

V9-AI-002 must not build independent provider/inference plumbing.

## 12. Trading/execution boundary

Broker trading actions remain explicitly excluded from the agentic action layer in V9. The assistant must not place, modify or cancel broker orders, bypass StoX execution safeguards, or silently convert reasoning into money-moving execution.

It may prepare non-executing workflows or explain relevant StoX state where otherwise allowed.

## 13. Acceptance criteria

The epic is complete when all of the following are true:

1. Typed governed read/mutation MCP-style tools exist for the agreed StoX scope.
2. Tool exposure respects current-user permissions and capability relevance.
3. The assistant can answer account-specific read-only questions using bounded planner/tool/synthesis loops.
4. Deterministic StoX analysis tools are preferred over LLM calculation where available.
5. Missing/incomplete evidence is surfaced explicitly.
6. Users can inspect a concise read-tool trace without exposing chain-of-thought.
7. Every mutation requires preview + grouped approval.
8. Any material plan deviation requires fresh approval.
9. Stale-state checks run before mutation execution.
10. Unexpected partial failure stops execution and reports exact state.
11. Mutation tools are idempotent where feasible.
12. Successful mutation workflows are verified by read-back.
13. Destructive actions receive stronger explicit confirmation.
14. Agentic run history is user-visible and auditable.
15. Retry from history creates a fresh plan against current state.
16. Scheduled/recurring autonomous mutations are absent.
17. Broker order placement/modification/cancellation is absent.
18. External MCP/tool integrations are absent from V9-AI-002.
19. All inference uses the shared V4-FEAT-017 platform.

## 14. Frozen product decisions summary

- Broad StoX actions are allowed; broker/trading actions are excluded.
- Mutations use grouped preview + confirmation.
- Material deviation requires fresh approval.
- Stop on unexpected partial failure.
- Idempotency is required where feasible.
- Use typed MCP-style contracts.
- Read and write tools share the governed tool layer.
- Tools inherit current-user permissions; no elevated agent role.
- Verify resulting state after mutation.
- Autonomous tool chaining is allowed only inside approved scope.
- Show concise planned steps and reasons before approval.
- Maintain user-visible agentic action history.
- Retry creates a fresh plan against current state.
- V9 agentic actions are interactive/on-demand only.
- Draft creation still requires confirmation.
- Destructive deletion requires explicit stronger confirmation.
- V9 uses StoX-internal tools only.
- All calls are mediated by deterministic policy/orchestration.
- Re-read current state immediately before mutations.
- Read-only reasoning supports bounded autonomous tool loops.
- Users can inspect concise read-tool traces.
- Prefer deterministic derived-analysis tools.
- Multiple derived analyses may be combined in one answer.
- Missing/incomplete data must be disclosed.
