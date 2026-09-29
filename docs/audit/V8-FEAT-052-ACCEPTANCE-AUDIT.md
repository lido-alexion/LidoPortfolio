# FEAT-052 OpenTelemetry / LidoTelemetry — acceptance audit

Status: **REVIEW — local producer implementation is verified; PHP SDK, Collector and deployed runtime evidence remain pending**

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| StoX is an OpenTelemetry producer, not a replacement Collector | PASS locally | `LidoTelemetry`, fail-open OTLP/HTTP trace and metric exporters, and the deployment probe send to the configured Collector endpoint. |
| HTTP trace context and server spans | PASS locally | `LidoTelemetryHttpMiddleware`, `TraceContext`, and `LidoTelemetryHttpTest`; inbound `traceparent` is accepted and child context is emitted. |
| HTTP duration metrics | PASS locally | `OtlpHttpMetricsExporter` emits `stox.http.server.duration` with bounded method/status-class attributes; exporter failures are swallowed. |
| Business events and privacy filtering | PASS locally | `LidoTelemetryCatalog`, business-event tests, pseudonymous user identifier, and sensitive-key filtering. |
| Browser route-view telemetry | PASS locally | Existing route-view producer and JS tests; route duration is active-time aware and fail-open. |
| Official browser instrumentation | PASS locally / runtime pending | `otelBrowser.js` uses the official OpenTelemetry web SDK, OTLP trace exporter and fetch instrumentation when `VITE_LIDO_TELEMETRY_ENABLED` and an explicit endpoint are configured. It also records uncaught exceptions and unhandled promise rejections through bounded categorical/path attributes without exporting raw messages, exception objects or stacks; telemetry/log endpoints are excluded and setup remains fail-open. `otelBrowser.test.jsx` covers registration, raw-reason exclusion and setup failure. |
| Queue/scheduler propagation | PASS locally / deployed runtime pending | `TraceContext` is activated for HTTP requests, propagated into queue payloads, restored on `JobProcessing`, cleared on completion/failure, and independently scheduled tasks start fresh roots. `LidoTelemetryHttpTest` proves sync queue causal trace preservation; deployed worker/scheduler propagation remains to be exercised. |
| Official PHP/Laravel SDK instrumentation | PASS locally / runtime pending | Composer now declares `open-telemetry/opentelemetry-auto-laravel` 1.9.1, `open-telemetry/sdk` 1.15.0 and `open-telemetry/exporter-otlp` 1.4.0. With `ext-opentelemetry` loaded and standard `OTEL_*` settings enabled, Composer autoload registers Laravel HTTP/queue/worker/DB hooks; custom StoX HTTP spans are suppressed in that mode to prevent duplicates. The target runtime still needs the extension and Collector configuration. |
| No business-operation dependency on telemetry | PASS locally | Exporters use bounded timeouts and swallow transport/serialization failures; focused telemetry tests pass. |
| Collector and production runtime | EXTERNAL VALIDATION PENDING | Collector receipt, CORS for browser OTLP, production endpoint configuration, PHP extension installation, queue/worker propagation and deployed probe have not been run in the target environment. |

## Verification executed

- Focused telemetry: **12/12 passed**, 30 assertions.
- Browser Node suite: **190/190 passed**.
- Vitest: **101/101 passed**.
- Frontend build and typecheck: passed under Node **20.19.1** / npm **10.8.2**.
- `git diff --check`: passed.

## Status decision

FEAT-052 remains **REVIEW**. The browser SDK path now covers fetches, uncaught exceptions and unhandled promise rejections locally without enabling an endpoint by default. PHP automatic SDK instrumentation, Collector receipt, CORS and deployed runtime validation remain bounded follow-up work; no production success is claimed.
