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


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Collector and PHP producer configuration | PASS (configuration only) | Build `ef66133c`, VPS, 2026-10-01 18:25 UTC: `stoxla-queue` and `otelcol-contrib` active; `lido_telemetry.enabled=true`, traces/metrics endpoints configured, PHP `opentelemetry` extension loaded. No trace receipt was inferred. |
| Correlated browser → HTTP → queue and scheduler trace, LidoTelemetry visibility and bounded attributes | NOT YET RUN | Capture trace IDs privately; inspect Collector receipt and destination, latency/events and privacy. |
| Browser OTLP/CORS and exporter/Collector outage fail-open | NOT YET RUN | Controlled provider/outage exercise without affecting investor action. |


## 2026-10-02 production Collector and privacy check

Deployed build `ac6602ae2db35b52b68a7183c50f987a1b4d615f` had `lido_telemetry.enabled=true`, the official PHP SDK enabled, OTLP traces/metrics endpoints configured, and the Collector active. LidoTelemetry `/up` returned HTTP 200. A bounded public `GET /api/build-info` request with a synthetic W3C `traceparent` returned HTTP 200; indexed lookup in LidoTelemetry found a `GET /api/build-info` server span under the supplied parent and a child HTTP span, both received in the production environment. **PASS for this HTTP → Collector → LidoTelemetry receipt and parentage slice only.** Browser → HTTP, queue/scheduler propagation, metric receipt, failure isolation and UI visibility remain NOT YET RUN.

**FAILED privacy gate:** a second controlled request used a synthetic, non-secret `token` query marker. LidoTelemetry stored that marker in both `url.full` and `url.query` attributes of the server span. No real credential was used or printed. The installed `open-telemetry/opentelemetry-auto-laravel` 1.9.1 `Kernel` hook populates both attributes from the full request URL/query; its outbound `ClientRequestWatcher` also constructs `url.full` with the query. This conflicts with FEAT-052 §§12–13 and the privacy-safe export contract. Sanitize or omit query values for all server/outbound spans before export, verify with a synthetic marker, and then inspect captured attributes without exposing values. Do not mark FEAT-052 COMPLETE while this finding remains. Collector configuration is root-owned, so an infrastructure-side transform/restart is a separate privileged operation; code remediation can be developed and reviewed through CI first.

### 2026-10-02 Collector privacy remediation candidate (validated, not deployed)

A temporary mode-0600 copy of the running Collector configuration was parsed and amended only in memory/on disk for validation, then removed. On the installed `otelcol-contrib` 0.161.0, `validate --config <temporary-copy>` exited 0 with this processor inserted between `memory_limiter` and `batch` in the **traces** pipeline:

```yaml
processors:
  transform/privacy:
    error_mode: ignore
    trace_statements:
      - 'delete_key(span.attributes, "url.query")'
      - 'delete_key(span.attributes, "url.full")'
      - 'delete_key(span.attributes, "http.url")'
      - 'delete_key(span.attributes, "http.target")'
service:
  pipelines:
    traces:
      processors: [memory_limiter, transform/privacy, batch]
```

This candidate strips the observed server-span keys and corresponding outbound URL attributes before export. It has **not** been applied to the root-owned `/etc/otelcol-contrib/config.yaml`, and the service was not restarted. Validation proves config parsing only; it does not prove sanitized production receipt, prevent leakage through other attributes/events/logs, or erase previously ingested data. A privileged, coordinated rollout must preserve the existing receiver/exporter config, validate the resulting file, restart the Collector, and compare an indexed synthetic-marker trace in LidoTelemetry. Inspect attribute *keys* and marker-presence booleans without printing values, including server and outbound spans. Keep FEAT-052 **REVIEW with FAILED privacy gate** until that probe and broader browser/queue/scheduler/fail-open acceptance pass.
