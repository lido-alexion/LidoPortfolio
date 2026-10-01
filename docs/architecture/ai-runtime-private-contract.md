# Private AI runtime contract

Laravel is the authority for AI capabilities, prompts, provider-path configuration, budgets and inference evidence. The Python runtime is a private execution service and never connects to StoX MariaDB.

Both directions use `X-StoX-AI-Service-Key` and the `/internal/v1` namespace. Laravel calls Python at `/internal/v1/capabilities`, `/internal/v1/inference` and `/internal/v1/inference/stream`. Python reads the Laravel projection at `/api/internal/v1/ai-runtime/configuration` and posts auditable results to `/api/internal/v1/ai-runtime/inference-events`.

The browser only calls authenticated Laravel routes. Laravel's SSE proxy at `/api/ai/assistant/stream` never sends a runtime address, service key, or provider credential to a client.

Every inference envelope carries a request ID, optional trace ID, capability, account/user context, input, stream preference and structured-output contract. Results contain normalized status/error, selected provider/model evidence, routing attempts, usage, prompt version and documentation provenance. `documentation_chat` returns `grounding_insufficient` when deterministic retrieval cannot support a response.

## Documentation assistant contract

Laravel projects configured (non-null) overall, capability, path and user hard budgets. Each execution loads its own snapshot and filters the canonical route before any provider call. Python does not update spend; the emitted event identifies applicable scopes and Laravel records all reported cost, including a crossing of a hard limit. Snapshot enforcement does not reserve future spend across concurrent requests.

The governed active documentation prompt receives a JSON input containing the question, bounded session history, safe visible-page context and retrieved evidence (stable source ID, title, path, section, snippet, relevance). The output contract is deliberately extractive: `{"extracts":[{"source_id":"…","quote":"…"}]}`. Every quote must be a nonempty exact excerpt of its identified retrieved snippet. Unknown citations, unsupported prose and empty evidence normalize to `grounding_insufficient`. This conservative contract cannot synthesize undocumented explanations. Provider streaming, when configured, is buffered for validation before any answer reaches the browser.

Only numbered user journeys and the generated approved product corpus are retrieved. `node app/scripts/generate-assistant-corpus.mjs` rebuilds product evidence from the maintained user-facing documentation registry; frontend builds run it automatically. Journey files are reread on retrieval so removed content cannot linger in process memory.

Authenticated browser endpoints:

- `POST /api/ai/assistant/stream`: question (4000 chars), up to six question/answer turns, and allowlisted page context (`route`, `topic`, `section`, up to 20 visible label/value pairs). Identity always comes from Laravel authentication.
- `POST /api/ai/assistant/feedback`: request UUID, helpful boolean, optional 500-character comment. Laravel requires ownership of the matching documentation inference record and stores feedback linked to that record.

Laravel parses SSE frames and projects only `message.start`, answer `message.delta`, and `message.completed`/`error` with request ID, normalized code/status, grounding state and safe source fields. Usage, provider/model/path, prompts, raw errors and routing traces stay private. UI state is memory-only and is reset on clear/unmount; page context never authorizes additional reads. Navigation uses allowlisted documentation links and existing deterministic journey destinations.
