# FEAT-052 OpenTelemetry / LidoTelemetry — acceptance audit

Status: **REVIEW — observed production query-attribute leak mitigated at Collector export; broader runtime and privacy acceptance pending**

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

### 2026-10-02 Collector privacy remediation candidate (pre-rollout checkpoint)

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

### 2026-10-02 privileged Collector rollout and sink probe (07:57 UTC)

After the account owner unlocked sudo in a shared VPS terminal, the operator applied the previously validated candidate to the root-owned Collector config. A root-owned mode-0600 backup was retained at `/var/backups/otelcol-contrib/config.yaml.feat052.20261002T075715Z` (SHA-256 `ef841331cfd2702fae81ec08a95bddd56a4da6fdf265b6759e4be714c338d39a`). The candidate and installed config each passed `otelcol-contrib validate` (exit 0). The Collector restart and active check exited 0; subsequent read-only checks showed `active` and `enabled`, root:root mode 0644 config, traces processors `[memory_limiter, transform/privacy, batch]`, metrics unchanged at `[memory_limiter, batch]`, and LidoTelemetry `/up` HTTP 200. Rollback remains the pre-change backup; it was not needed.

A fresh non-secret synthetic `token` query marker and W3C parent span were sent to `GET /api/build-info` (HTTP 200; trace ID `1aa0e0422dd2d3557bdb93e6e5e40803`). An indexed, bounded lookup on the StoX product, production environment and exact trace ID returned **two spans**. The server `GET /api/build-info` span had the supplied parent; the child `POST` span was also received. For both spans, the marker-presence boolean was **false**, and none of `url.query`, `url.full`, `http.url`, or `http.target` was present in attribute keys. No attribute values, real credentials, or raw query data were printed in the inspection. **PASS for the observed HTTP sink-leak mitigation and parentage slice.**

The earlier FAILED finding and candidate-not-deployed wording above are historical checkpoints. Previously ingested spans are not erased. This Collector-side proof does not establish producer-side redaction before Collector ingress, other potentially sensitive attributes/events/logs, browser-to-HTTP/queue/scheduler propagation, metric receipt, CORS, or outage fail-open. FEAT-052 remains **REVIEW**, with those gates open; do not mark COMPLETE based on this bounded probe.

### 2026-10-02 production HTTP duration metric receipt (08:41 UTC)

Read-only indexed lookup in LidoTelemetry by StoX product, production environment, exact `stox.http.server.duration` metric name and a one-hour time bound returned five latest rows at 08:41:27–08:41:43 UTC, received by 08:41:29–08:41:45 UTC. Each was a positive `sum` value with only `service.name`, `deployment.environment`, `service.version`, `http.method` and `http.status_class` dimension keys. No dimension values or trace payloads were printed. **PASS for production HTTP duration metric receipt and bounded-dimension shape.** These aggregate rows do not prove one-to-one association with the earlier synthetic request.

A bounded latest-span sample exposed scheduler-related trace names, but the inspected scheduler trace was received at 06:05 UTC, before the 07:57 Collector change. Its historical URL/DB attribute keys are not evidence of post-change leakage or current scheduler propagation. A fresh controlled scheduler/queue trace, browser correlation, CORS, other privacy surfaces and exporter failure isolation remain open. FEAT-052 remains **REVIEW**.

### 2026-10-02 browser OTLP endpoint mismatch (08:49 UTC)

The production asset was built with browser tracing enabled and `VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT=/otel/v1/traces`. A benign signed-in browser navigation to Watchlist completed, but an indexed LidoTelemetry event lookup after that visit returned no rows; this alone does not prove that a trace was sent. The deployed StoX route list has no `/otel/v1/traces` route, and Nginx has no matching proxy location. A bounded empty POST to that URL returned HTTP 405. The existing same-origin relay is `POST /api/telemetry/otlp/v1/traces`; an empty JSON POST reached it and returned validation HTTP 422. No sensitive telemetry body was sent or printed.

The production deploy workflow's frontend verification and release packaging builds both pointed to the missing path. This branch changes both to the existing relay. **Browser receipt remains NOT YET PROVEN** until the change deploys and a fresh, correlated browser trace is observed at LidoTelemetry. The missing path is a concrete FEAT-052 browser export blocker; do not infer CORS behavior or complete the epic from the route check.

### 2026-10-02 relay receipt and deployed browser endpoint (09:14–10:23 UTC)

PR #33 merged as `e8877a89c7032d422d0d87c95c040032e81f3636` after all four PR CI jobs passed. Its own queued deploy run was superseded by later master activity. Production build `e343eea7890d3f8936d97f95f32ade01aa09d2e5` subsequently deployed successfully. The active `app-CdZNgAO8.js` asset contains `/api/telemetry/otlp/v1/traces` and does not contain the obsolete `/otel/v1/traces` path. **PASS for deployment of the corrected browser exporter configuration.**

A synthetic, non-secret OTLP JSON span was POSTed to the same-origin relay. StoX returned HTTP 202; indexed lookup by exact trace ID `9c6e64f039eae0a468b4578f8908134c` found `stox.browser.synthetic_relay_probe` in LidoTelemetry production at 09:14:49 UTC with only the expected `stox.browser.probe` attribute key. **PASS for relay → Collector → LidoTelemetry receipt of this synthetic trace.** The test was sent by a controlled client, so it does not prove the browser SDK exported a span.

A signed-in browser loaded the deployed Dashboard and navigated to Watchlist at approximately 10:23 UTC. The page rendered; no browser console error related to telemetry was observed. The initial indexed event-table lookup returned no StoX production rows, but source inspection shows `recordBusinessEvent` exports route views as `stox.ui.route_view` **spans**; that event-table query cannot assess route-view receipt. The browser trace ID was not captured. A production span lookup constrained by product, environment, span name and start time hit a five-second statement limit because the trace-span table has no suitable time index. **Browser span receipt, browser → HTTP parentage, and route-view event receipt remain NOT YET PROVEN.** Queue/scheduler propagation, broader privacy and outage fail-open remain open. FEAT-052 stays **REVIEW**.

### 2026-10-02 controlled production queue propagation (11:45 UTC)

The active database queue worker listened on `default` and had no pending jobs there. A read-only Laravel `about` command was queued with the active official OpenTelemetry context. The sink contained the worker's completed `Command about` span. Indexed LidoTelemetry lookup by exact StoX production trace ID `4eb0fe1a6139374d0002f9f1b85ab7c5` returned the sender `Command tinker` root and `stox.telemetry.collector_probe`, plus a worker `process (anonymous)` span, two `stox.queue.job` phase spans and a child `Command about` span under the same trace. The worker span's parent links to the sender span; the queue events and command link to the worker. **PASS for this controlled CLI → database queue → worker propagation and LidoTelemetry receipt slice.** No business or NSE work was dispatched; only span names, parent identifiers, receipt times and attribute keys were inspected.

Two earlier probes used a separate synthetic `TraceContext::active()` while the official SDK already held a valid CLI span. `TraceContext::effective(true)` preferred that SDK span, so the separately generated trace ID returned no rows. Those probes do not show a queue failure. The successful probe used the actual effective SDK context.

The received SQL spans included the `db.query.text` attribute key. No SQL attribute values were printed. Its presence leaves the broader privacy review open because query text may contain literals. A mode-0600 candidate copy of the installed Collector configuration adds `delete_key(span.attributes, "db.query.text")` and legacy `db.statement` deletion to the existing `transform/privacy`; installed `otelcol-contrib` 0.161.0 validation returned exit 0. **Candidate only:** the root-owned installed config and service have not yet changed for this DB-key addition. A privileged backup, atomic install, validation, restart, exact-trace probe and rollback check are required before marking this key mitigated.
