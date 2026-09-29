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

The same-origin relay accepts bounded OTLP/HTTP JSON and forwards it only to the server-side `LIDO_TELEMETRY_BROWSER_RELAY_UPSTREAM` (default `http://127.0.0.1:4318/v1/traces`). It is fail-open, rate-limited, and excluded from browser FetchInstrumentation so it cannot create a telemetry loop. Collector export remains configured with JSON encoding and no compression.
