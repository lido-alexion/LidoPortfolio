# FEAT-052 OpenTelemetry / LidoTelemetry — acceptance audit

Status: **REVIEW — local producer implementation is verified; PHP SDK, Collector and deployed runtime evidence remain pending**

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| StoX is an OpenTelemetry producer, not a replacement Collector | PASS locally | `LidoTelemetry`, fail-open OTLP/HTTP trace and metric exporters, and the deployment probe send to the configured Collector endpoint. |
| HTTP trace context and server spans | PASS locally | `LidoTelemetryHttpMiddleware`, `TraceContext`, and `LidoTelemetryHttpTest`; inbound `traceparent` is accepted and child context is emitted. |
| HTTP duration metrics | PASS locally | `OtlpHttpMetricsExporter` emits `stox.http.server.duration` with bounded method/status-class attributes; exporter failures are swallowed. |
| Business events and privacy filtering | PASS locally | `LidoTelemetryCatalog`, business-event tests, pseudonymous user identifier, and sensitive-key filtering. |
| Browser route-view telemetry | PASS locally | Existing route-view producer and JS tests; route duration is active-time aware and fail-open. |
| Official browser instrumentation | PASS locally / runtime pending | `otelBrowser.js` uses the official OpenTelemetry web SDK, OTLP trace exporter and fetch instrumentation when `VITE_LIDO_TELEMETRY_ENABLED` and an explicit endpoint are configured. Telemetry/log endpoints are excluded; build/typecheck/JS suites pass. |
| Queue/scheduler propagation | PARTIAL | Existing StoX lifecycle hooks and business events are covered locally; deployed worker/scheduler context propagation remains to be exercised. |
| Official PHP/Laravel SDK instrumentation | PARTIAL | The producer contract and fail-open exporters are implemented, but the PHP SDK is not yet wired into Laravel automatic HTTP/DB instrumentation. |
| No business-operation dependency on telemetry | PASS locally | Exporters use bounded timeouts and swallow transport/serialization failures; focused telemetry tests pass. |
| Collector and production runtime | EXTERNAL VALIDATION PENDING | Collector receipt, CORS for browser OTLP, production endpoint configuration, queue/worker propagation and deployed probe have not been run in the target environment. |

## Verification executed

- Focused telemetry: **12/12 passed**, 30 assertions.
- Browser Node suite: **188/188 passed**.
- Vitest: **99/99 passed**.
- Frontend build and typecheck: passed under Node **20.19.1** / npm **10.8.2**.
- `git diff --check`: passed.

## Status decision

FEAT-052 remains **REVIEW**. The browser SDK path closes the local browser-instrumentation implementation gap without enabling an endpoint by default. PHP automatic SDK instrumentation, Collector receipt, CORS, queue/scheduler propagation and deployed runtime validation remain bounded follow-up work; no production success is claimed.
