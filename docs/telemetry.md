# StoX telemetry deployment

FEAT-052 keeps the Collector as the only authenticated path into LidoTelemetry. The browser must never receive the LidoTelemetry ingestion credential and must not target `127.0.0.1`.

For a production frontend build, set:

```dotenv
VITE_LIDO_TELEMETRY_ENABLED=true
VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT=/api/telemetry/otlp/v1/traces
VITE_LIDO_TELEMETRY_SERVICE_NAME=stox
VITE_LIDO_TELEMETRY_SERVICE_VERSION=${BUILD_ID}
VITE_LIDO_TELEMETRY_ENVIRONMENT=production
```

The same-origin relay accepts bounded OTLP/HTTP JSON and forwards it only to the server-side `LIDO_TELEMETRY_BROWSER_RELAY_UPSTREAM` (default `http://127.0.0.1:4318/v1/traces`). It is rate-limited and excluded from browser instrumentation so it cannot create a telemetry loop. Redirects are disabled to preserve the fixed upstream. The relay returns an empty 202 only after an upstream 2xx; this acknowledges Collector acceptance, not sink receipt. Upstream invalid-request statuses (400/413/415/422) become empty, nonretryable 400 responses. All other upstream failures, including 5xx, throttling, redirects and transport exceptions/timeouts, become empty retryable 503 responses for the official browser OTLP exporter. Existing local size/content checks retain 413/415/422 responses. No upstream body, headers or exception details are returned or logged.

`LIDO_TELEMETRY_BROWSER_RELAY_TIMEOUT` defaults to 2 seconds and is clamped to 0.1–5 seconds for both connection and total request timeout. There is one upstream attempt per relay request; browser retries remain bounded by the exporter. This dedicated telemetry timeout does not change the 0.15-second default for business exporters. Investor/business operations remain fail-open and do not depend on relay success. Collector export remains configured with JSON encoding and no compression.
