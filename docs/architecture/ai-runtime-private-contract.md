# Private AI runtime contract

Laravel is the authority for AI capabilities, prompts, provider-path configuration, budgets and inference evidence. The Python runtime is a private execution service and never connects to StoX MariaDB.

Both directions use `X-StoX-AI-Service-Key` and the `/internal/v1` namespace. Laravel calls Python at `/internal/v1/capabilities`, `/internal/v1/inference` and `/internal/v1/inference/stream`. Python reads the Laravel projection at `/api/internal/v1/ai-runtime/configuration` and posts auditable results to `/api/internal/v1/ai-runtime/inference-events`.

The browser only calls authenticated Laravel routes. Laravel's SSE proxy at `/api/ai/assistant/stream` never sends a runtime address, service key, or provider credential to a client.

Every inference envelope carries a request ID, optional trace ID, capability, account/user context, input, stream preference and structured-output contract. Results contain normalized status/error, selected provider/model evidence, routing attempts, usage, prompt version and documentation provenance. `documentation_chat` returns `grounding_insufficient` when deterministic retrieval cannot support a response.
