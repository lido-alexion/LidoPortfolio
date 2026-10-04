# Private AI runtime contract

Laravel is the authority for AI capabilities, prompts, provider-path configuration, budgets and inference evidence. The Python runtime is a private execution service and never connects to StoX MariaDB.

Both directions use `X-StoX-AI-Service-Key` and the `/internal/v1` namespace. Laravel calls Python at `/internal/v1/capabilities`, `/internal/v1/inference` and `/internal/v1/inference/stream`. Python reads the Laravel projection at `/api/internal/v1/ai-runtime/configuration`, reserves each provider attempt at `/api/internal/v1/ai-runtime/reservations`, and posts settlement and audit envelopes to `/api/internal/v1/ai-runtime/settlements` and `/api/internal/v1/ai-runtime/inference-events`.

The browser only calls authenticated Laravel routes. Laravel's SSE proxy at `/api/ai/assistant/stream` never sends a runtime address, service key, or provider credential to a client.

Every inference envelope carries a request ID, optional trace ID, capability, account/user context, input, stream preference and structured-output contract. Results contain normalized status/error, selected provider/model evidence, routing attempts, usage, prompt version and documentation provenance. `documentation_chat` returns `grounding_insufficient` when deterministic retrieval cannot support a response.

## Documentation assistant contract

Laravel projects configured overall, capability, path and user hard budgets. Each execution retains its own provider configuration; all routers in the runtime process share global and per-capability admission counters. Admin `PUT /api/admin/ai-platform/concurrency` sets `global_max_concurrency` live through the existing settings store (`STOX_AI_GLOBAL_MAX_CONCURRENCY` supplies the initial default); capability administration stores `max_concurrency`. Lowering limits drains active executions without replacing their counters. Older projections cannot overwrite newer limits.

Every provider attempt, including structured-output retries, requires a fresh Laravel reservation before execution. The private reservation payload contains UUID `id` and `request_id`, `capability_id`, `path_id`, exact `provider`/`model`, originating `user_id` where applicable, and a conservative `input_token_bound` (UTF-8 prompt bytes plus message overhead). Laravel checks the enabled canonical path and reserves its maximum input/output cost atomically against committed spend plus active reservations. The returned `max_output_tokens` is sent to the provider. Missing pricing, stale model identity or unavailable admission fails closed. Canonical fallback remains in order.

Provider-path `config.pricing` contains nonnegative `input_per_million` and `output_per_million` rates in the same currency as the hard budgets. `config.max_output_tokens` defaults to 4096 and is bounded at 100000. Only the explicitly deterministic test provider defaults to zero pricing. Provider-reported `estimated_cost` is diagnostic, never ledger authority.

Settlement contains reservation `id` and either token `usage` (`input_tokens`, `output_tokens`, optional diagnostic `estimated_cost`) or null when usage is unknown. Laravel uses the pricing captured at admission and records the actual cost, including any overrun. Repeated settlement is idempotent. A reservation expires after 15 minutes; `ai:reconcile-reservations` runs every minute and on configuration/admission access. Unknown execution is charged at its reserved maximum, never silently released. A later actual settlement reconciles that charge. Monthly spend rollover is serialized with admission and settlement; late settlements remain attached to their original accounting period. Reservation rows retain historical pricing, scope and settlement evidence across Python restarts.

Python persists generated settlement/audit envelopes before delivery in a local SQLite transport outbox (`STOX_AI_OUTBOX_PATH`, default `var/ai-delivery.sqlite3`). Provision a persistent private writable directory; ephemeral deployment storage is unsuitable. This queue holds delivery work only and is not an authoritative spend, audit or domain store. A lifespan worker retries batches of at most 50 with exponential backoff capped at 300 seconds. HTTP errors or invalid acknowledgements retain entries. Successful acknowledgements remove entries. `/health` exposes pending count and maximum attempts; failures also emit envelope IDs and error categories without payloads. Laravel deduplicates final audit events by request ID under the ledger lock, and pending settlements defer final audit acceptance. Successful events without an authoritative reservation are rejected. Audit cost aggregates all request attempts, including invalid-output attempts.

Declared capability and request JSON schemas are both enforced. Only local schema references are accepted. Missing required fields, invalid types or malformed JSON trigger one retry on the same path, then canonical failover; exhausted invalid outputs return `structured_output_invalid`. AI-002 typed planner/tool contracts remain gated separately.

The governed active documentation prompt receives a JSON input containing the question, bounded session history, safe visible-page context and retrieved evidence (stable source ID, title, path, section, snippet, relevance). The output contract is deliberately extractive: `{"extracts":[{"source_id":"…","quote":"…"}]}`. Every quote must be a nonempty exact excerpt of its identified retrieved snippet. Unknown citations, unsupported prose and empty evidence normalize to `grounding_insufficient`. This conservative contract cannot synthesize undocumented explanations. Provider streaming, when configured, is buffered for validation before any answer reaches the browser.

Only numbered user journeys and the generated approved product corpus are retrieved. `node app/scripts/generate-assistant-corpus.mjs` rebuilds product evidence from the maintained user-facing documentation registry; frontend builds run it automatically. Journey files are reread on retrieval so removed content cannot linger in process memory.

Authenticated browser endpoints:

- `POST /api/ai/assistant/stream`: question (4000 chars), up to six question/answer turns, and allowlisted page context (`route`, `topic`, `section`, up to 20 visible label/value pairs). Identity always comes from Laravel authentication.
- `POST /api/ai/assistant/feedback`: request UUID, helpful boolean, optional 500-character comment. Laravel requires ownership of the matching documentation inference record and stores feedback linked to that record.

Laravel parses SSE frames and projects only `message.start`, answer `message.delta`, and `message.completed`/`error` with request ID, normalized code/status, grounding state and safe source fields. Usage, provider/model/path, prompts, raw errors and routing traces stay private. UI state is memory-only and is reset on clear/unmount; page context never authorizes additional reads. Navigation uses allowlisted documentation links and existing deterministic journey destinations.

## Non-initializing domain projections

Readiness exposes separate `readWatchlistsForProfile`, strategy `readForProfile`, cash `readSummary` / `readAccountSummary`, and analytics `readForProfile` methods. They do not invoke UI lazy initialization. Account cash includes only the originating user's live profiles; missing profile cash yields `incomplete` with no synthetic total. Missing state returns `not_initialized` or `unavailable`. Analytical sector/beta/correlation fields carry explicit availability and null values, and missing holding prices prevent a measured portfolio valuation. Existing historical/performance and UI convenience methods remain outside this pure-read contract and must not be exposed as read tools without a separate audit.

## Governed AI-002 run and tool contract

The existing drawer has separate Documentation help and Account investigation/actions modes. Documentation conversation remains session-only. Laravel stores AI-002 operational runs in `stox_ai_agent_runs`; account identity is the originating StoX user ID, with an independently checked owned portfolio ID. Run history contains the objective, typed plan/parameters, preview, approval hash/time/expiry, observable tool trace, affected object IDs, per-step verification/status and recovery outcome. It contains no private model reasoning, credentials, provider prompts or conversation transcript.

Authenticated endpoints are `GET/POST /api/ai/assistant/runs`, `GET /api/ai/assistant/runs/{run}`, and `POST .../{run}/approve` / `reject`. Creation accepts `objective` and optionally an owned `retry_run_id`; retry uses that objective but creates a new run with fresh evidence and no approval. Approval accepts the exact `plan_hash` and requires `destructive_confirmation: true` for destructive steps. History is owner-scoped. Read endpoints require `portfolio:read`; approval also requires `portfolio:write`. These routes explicitly exclude the UI's lazy profile initializer; missing state returns `not_initialized` without creating a portfolio/watchlist.

Laravel issues a 15-minute random run delegation, stores only its digest, and sends it over the existing private service-key transport to `POST /internal/v1/agent/investigate`. Python domain tools can reach only `POST /api/internal/v1/ai-tools/call`. The gateway independently validates the service key, run delegation, current user/profile ownership, originating token existence/expiry/abilities and original scope ceiling on every call. Changing roles, revoking a token or removing an ability cannot elevate a run. No browser receives a service key or delegation. The internal envelope declares `operation` (`catalog`, `read`, `preview`, `complete`), `run_id`, `delegation`, and operation-specific typed fields. Unknown fields/tools fail closed; errors use `success: false, error: {code}`. Failed calls retain bounded operational audit evidence.

The FastMCP 4.x façade is in-process and scoped per request; it opens no MCP network listener and connects to no external MCP server. Explicit discriminated Pydantic contracts describe each tool's parameters and planner read/action lists. Read tools invoke Laravel projections; mutation façades stage deterministic approval previews. Only the authenticated Laravel approval control executes the stored group through the same policy/domain layer. Tool code has no domain database, arbitrary URL, shell or broker interface. The sole Python SQLite use remains the previously described delivery outbox.

Catalog:

- Reads: `portfolio.summary`, `portfolio.holdings`, `portfolio.analytics`, `cash.summary`, `watchlist.list`, `watchlist.items`, `strategy.list`, `screener.list`, `screener.runs`, `recommendations.list`, `artifact.list`, `preferences.read`, `dashboard.list`, `workflow.prepare`.
- Mutations: `watchlist.create`, `watchlist.rename`, `watchlist.add_stock`, `watchlist.remove_stock`, `watchlist.delete`, `strategy.create`, `strategy.update`, `screener.create`, `screener.update`, `artifact.update_draft`.
- Strategy/screener creation explicitly creates Library drafts, without publication, binding or activation. Legacy updates preserve exact object identity; factory/Library-managed runtime objects remain immutable through legacy tools. Draft updates retain the Library optimistic lock and never modify published versions.
- `screener.runs` reads stored run evidence. Updating disabled legacy screeners is deferred because the canonical registry derives enablement from artifact status and can otherwise re-enable an unchanged disabled object. Enabled legacy screener updates preserve lifecycle state. Launching new screener runs, scheduled data operations, preference/dashboard mutations, artifact publication/binding, and workflow persistence are individually deferred: those paths need additional side-effect previews or extraction of authoritative controller-owned validation. Existing deterministic UI paths remain available. `workflow.prepare` returns preparation guidance only, with broker execution explicitly unavailable. Recommendations are read-only.

The planner uses at most three planning iterations and eight distinct read calls, ten seconds per tool and 55 seconds total. Each inference uses shared `agent_planning` / `agent_synthesis` capabilities, governed prompts, schema validation, canonical routing, concurrency and atomic budget reservations. Both capabilities are registered with empty provider-path orders; an Admin must configure eligible priced routes before live use. Final synthesis receives only typed tool evidence. Laravel owns calculations; nested unavailable/incomplete/not-supported evidence is disclosed independently of model prose. History shows observable tool outcomes, never chain-of-thought.

Plans contain at most five mutations. Laravel validates each action and captures deterministic field changes and target-state fingerprints. Approval lasts five minutes and binds run/user/profile, exact ordered actions/parameters, preview and state. Execution locks the run/profile/target state, checks the hash and expiry, immediately re-reads all material snapshots, and rejects stale plans. The UI offers a fresh investigation/preview; approval never carries forward. Duplicate execution returns the stored outcome. Domain-service mutations use savepoints: an unexpected failed step rolls back that step, preserves earlier successful steps, marks remaining steps unattempted and stops. Each successful step is independently read back; verification failure stops execution and cannot be presented as success. An interrupted read run becomes recoverable failed history once delegation expires.

Validation commands: install `ai-runtime/requirements.lock` plus the editable runtime, run `python -m pytest ai-runtime/tests`, focused `AiAgentGatewayTest` / readiness tests, then `./scripts/verify-ci.sh --backend` and `--frontend`. The frontend gate includes AI-001/AI-002 Chromium journeys. Python contract tests require no paid provider or externally running MCP server.

## Embedded insights (AI-003)

Authenticated Laravel POST routes (portfolio:read) are `/api/ai/insights/stocks/{stock}`
and `/api/ai/insights/strategy`. Stock accepts optional `refresh` and `lookup_only`
booleans; strategy additionally requires structured `inputs`. Lookup never generates.
Identity and owned active portfolio come from authentication and X-Profile-Id, without
lazy business initialization. Watchlist state is never an evidence input.

Capabilities `stock_analysis_insight` and `strategy_designer` have versioned JSON
schemas in `docs/architecture/ai-schemas`. Admin configures ordered provider paths
through the existing platform; registration does not select an unapproved provider.
Provider streaming is requested and buffered by the shared adapter until final schema
validation. The runtime bounds embedded route execution to 45 seconds; Laravel waits
55 seconds and the browser 60 seconds. No business background job is created.

`stox_ai_insight_cache` stores successful final responses, provenance, versions,
fingerprint, generation/refresh-failure timestamps and the inference request ID audit
reference. Identity hashes normalized evidence and context, prompt content/version,
capability/schema versions and schema content. It excludes provider/model settings.
Global entries have null account/profile IDs; held-stock entries include account and
active portfolio; strategy entries are account-only. Failed refreshes preserve matching
cache. The public response excludes routing, model, provider and prompt metadata.

FEAT-062 retains successful interpretations in `stox_fundamental_ai_reuse`, keyed to
deterministic evidence. AI-003 reads only matching interpretations and never invokes
FEAT-062 generation. Missing evidence is disclosed in the stock response.

`POST /api/ai/insights/strategy/draft` additionally requires portfolio:write and an
owned strategy-result `fingerprint`. It creates an AI-002 run and stages exactly one
`strategy.create` preview. Existing `/api/ai/assistant/runs/{id}/approve` owns all
approval, stale-state, idempotency, mutation and verification behavior. Generation
itself never invokes the mutation service.

## Operational log triage (OPS-003)

`ops.log_error_triage` is a background capability with default concurrency one and
`app/config/ai-schemas/ops.log_error_triage.v1.json` as its output contract.
It requires an active governed prompt and uses shared routing, admission, budgets,
structured validation and audit. Its adapter request uses temperature zero.
Laravel sends only code-derived bounded frames, exception/diagnostic categories,
registered route identity, deploy SHA and exact evidence candidates. No user/account
context, request correlation header or arbitrary source message is forwarded.

Laravel additionally validates exact evidence membership and requires actionable
`code_bug`, default confidence >= 0.85, concrete app-frame evidence, stable bug
identity and no security flag before requesting the shared OPS-002 GitHub reporter.
Unknown/schema-invalid results fail closed. Prompt version, inference UUID and
selected path are retained locally alongside safe decisions and issue linkage.
See [the OPS-003 acceptance audit](../audits/V9-OPS-003-implementation-audit.md)
for queue, cache, recurrence, privacy, retention and Admin inspection contracts.
