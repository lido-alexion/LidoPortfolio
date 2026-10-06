# FEAT-052 OpenTelemetry / LidoTelemetry — acceptance audit

Status: **IMPLEMENTED — remaining functional scenarios tracked in [FEAT-052 functional test plan](../testing/V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md)**. Historical REVIEW/FAILED entries below describe checkpoints at their recorded dates; this header and final decision state are current.

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

### 2026-10-02 Collector DB-key rollout and fresh queue sink proof (12:35–12:41 UTC)

The authorized VPS operator ran the prepared privileged rollout. It reported a root-owned pre-change backup at `/var/backups/otelcol-contrib/config.yaml.feat052-db.20261002T123506Z` (SHA-256 `bcee54fd145f528a3438d9016995d6390d4c249413e77de3c73967eeaac8e8e5`) and installed config SHA-256 `802e4c47065d980c9338656fae92aa3ce7cd0c7fd08497987c48a751dd0ac3fe`. The rollout's ready result was followed by an independent read-only check: `otelcol-contrib` was active/enabled, installed config validated, the traces pipeline used `[memory_limiter, transform/privacy, batch]`, and the transform deleted `url.query`, `url.full`, `http.url`, `http.target`, `db.query.text`, and `db.statement`. LidoTelemetry `/up` returned HTTP 200. The pre-change backup is retained for rollback; rollback was not needed.

A fresh, read-only `about` job was queued through the database queue with the active official SDK trace context. An exact-trace, product, and production-environment lookup for trace `fd669e2cc497f607a0735b073f281e33` returned 12 spans received at 12:41:37–12:41:45 UTC. They included the sender `Command tinker` root and `stox.telemetry.collector_probe`, worker `process (anonymous)`, two `stox.queue.job` spans, child `Command about`, and `sql INSERT`, `sql SELECT`, and `sql DELETE`. The worker's parent linked to the sender, with queue and command spans linked to the worker. The SQL spans retained bounded keys `db.system.name`, `db.namespace`, `db.operation.name`, `server.port`, and `server.address`; both `db.query.text` and `db.statement` key-presence booleans were false on every returned span. No attribute values or query text were printed. **PASS for current Collector-export DB-key mitigation and this CLI → queue → worker receipt slice.**

The previous candidate-only wording is a historical checkpoint. Earlier ingested traces may still hold the old keys; this rollout does not erase them or establish redaction before Collector ingress. Browser SDK span receipt/browser → HTTP correlation, fresh scheduler propagation, broader attributes/events/logs privacy and exporter-outage fail-open remain open. FEAT-052 stays **REVIEW**, not COMPLETE.

### 2026-10-02 exact signed-in browser trace lookup (12:59–13:03 UTC)

The account owner supplied only the trace ID `020909bfda4311a5bb280e740d1d023a` from a signed-in browser request after Dashboard → Watchlist navigation. An indexed lookup constrained by StoX product, production environment and that exact trace ID found **183 spans** received between 12:59:47 and 13:03:57 UTC. These include `GET /api/watchlists`, `GET /api/watchlists/{watchlist}/items`, `GET /api/indexes`, repeated notification/execution polling, SQL and Eloquent spans. The 13 API entry spans all reference parent span ID `f323ad8a83582a4a` under the supplied trace; their child server/database spans are present. None of the 183 spans is the referenced parent; there is no root span or browser span in this exact trace. SQL span attribute-key lists remain bounded and exclude `db.query.text` and `db.statement`. No user data or attribute values were printed.

**PASS for browser-supplied trace context reaching StoX HTTP and for backend descendant receipt; NOT PASS for exported browser span and complete browser → HTTP parentage.** The missing parent is a real evidence gap, not an indexed-query timeout. Source review found that the deployed browser SDK registers `FetchInstrumentation`, while the shared StoX API client uses Axios/XHR and also supplies a synthetic route `traceparent`. This is a likely cause of the absent browser parent, subject to a focused code correction and a fresh deployed browser trace. The route-view span may use another trace ID and was not inferred from this lookup. FEAT-052 remains **REVIEW**; fresh scheduler propagation, broader privacy and exporter-outage fail-open are also open.

## Browser Axios/XHR correlation correction (local candidate, 2026-10-02)

The reported exact lookup for trace `020909bfda4311a5bb280e740d1d023a` found 183 server-side spans, with all 13 API roots referring to absent parent `f323ad8a83582a4a`. Browser source inspection found fetch-only automatic instrumentation and an Axios interceptor supplying the synthetic route context. The candidate adds `@opentelemetry/instrumentation-xml-http-request` pinned to `0.222.0`, matching fetch instrumentation. The installed XHR implementation creates a span at `open()` and injects headers at `send()` using `setRequestHeader`, which appends rather than replaces existing values. Axios now removes manual trace headers when browser SDK registration succeeds; its existing synthetic fallback remains when registration is disabled or fails. Route-view timing, visibility accounting, payload and flush behavior are unchanged.

Local integration coverage uses real Axios XHR, the browser SDK and JSON OTLP exporter against a local HTTP receiver. It checks that each API wire header has exactly one traceparent whose trace/span IDs match the exported CLIENT span, including removal of caller-supplied mixed-case trace headers. Telemetry/log requests are excluded; exported query/header/path markers are absent. Export-boundary filtering retains method, status and categorical browser failure attributes while omitting full URLs, arbitrary attributes, events, links and status messages. Registration tests cover idempotence, disabled configuration and setup failure cleanup.

This is **local evidence only**: no production files, deployment or sink lookup changed. It does not repair historical missing parents or prove fresh production browser receipt, Laravel parentage, real Chromium export under production policy, exporter-outage behavior, or browser-to-queue/scheduler propagation. Automatic requests can start independent traces; this correction does not introduce an SDK route parent or unify them with synthetic route-view traces. FEAT-052 remains **REVIEW** pending deployed acceptance and the other open gates above.

## Relay acknowledgement correction (local candidate, 2026-10-02)

Operator-provided production evidence (not re-probed in this change): at **13:49 UTC on 2026-10-02**, the production browser sent a privacy-safe JSON OTLP batch containing **three XHR spans** to `POST /api/telemetry/otlp/v1/traces` and received HTTP **202**. Exact indexed LidoTelemetry lookups for **all three trace IDs** returned no spans. The exact trace IDs were `8ffe1f3027ff44475e3ccf72191b5165`, `9062daf911c6401990ebf3d6bf47918e` and `5ff865e2118381f01d82dfdf0ff4cb43`. The GET request header for the middle trace named parent span `e39405de86ebbb54`; the supplied OTLP batch showed a different exported span ID `effa5bf452d8432a` for that trace. This one batch cannot establish complete client-to-server parentage or whether the header's span was in another export batch. Later synthetic **one-span and three-span** payloads of the same OTLP shape, sent through the same relay, received 202 and were received at the sink. A separate HTTP probe also reached the Collector/sink. The Collector had been active since **12:35 UTC**. These observations do not establish the cause or location of the earlier drop; they do not establish Collector downtime or a payload-shape rejection.

Source inspection found a definite acknowledgement defect: `relayTraces` ignored the upstream HTTP status, swallowed transport exceptions and returned 202 regardless. The local correction returns an empty 202 only for Collector 2xx. Invalid-request upstream statuses 400/413/415/422 map to an empty nonretryable 400; other upstream failures (including 5xx, 408/429, redirects and infrastructure/auth failures) and transport exceptions map to an empty retryable 503. No upstream body, headers or exception text is exposed or logged. The same-origin route, fixed server-configured upstream, request size/content checks and rate limit remain; redirects are now disabled to retain the fixed destination.

The dedicated relay timeout defaults to **2 seconds**, clamped to **0.1–5 seconds**, with one upstream attempt per request. This separates the telemetry-only request from the existing **0.15-second** business-export timeout. The old timeout is a possible failure condition to handle, **not a proven cause of the 13:49 loss**. Business exporters and investor paths retain fail-open behavior. The updated response contract and timeout are documented in `docs/telemetry.md`; generated OpenAPI covers `/api/v1`, not this legacy `/api/telemetry` route, and regeneration produced no change.

Local validation:

- Focused Laravel relay plus existing HTTP/business-event/route-view tests: **44 passed, 231 assertions**, using temporary PHP SQLite modules for the database-backed regression fixtures. The new coverage exercises a three-span OTLP batch, upstream success/error statuses, connection timeout and generic transport exceptions, empty responses without upstream headers, fixed destination, timeout clamps, disabled redirects, validation and business/HTTP failure isolation.
- `php artisan openapi:v1` and `php artisan openapi:v1 --check`: passed, 219 operations, no generated diff.
- `./scripts/verify-ci.sh --backend`: initially blocked by missing `pdo_sqlite`. Retried with test-only PHP modules and isolated database name `feat052_relay_ack_ci`: platform/dependency checks, portability check for 165 migrations, and Python suite (16 tests, 8 skipped) passed; MySQL migration setup stopped with local `root@localhost` access denied. **Full MySQL/backend CI parity did not pass**; the focused SQLite fixture run is additional evidence, not a replacement gate. No tests or verifier requirements were weakened.
- `git diff --check`: passed.

This candidate is committed locally only. No production files, push, merge or deployment were performed. Tests use mocked Collector responses; they do not prove actual browser retry timing, recovery after exhaustion, fresh production browser receipt, complete browser-to-HTTP parentage or downstream durability. Even a Collector 2xx is acceptance by that hop, not proof of sink indexing (including possible partial acceptance). Retries after ambiguous timeouts can duplicate delivery. Fresh deployed exact-trace checks and outage/recovery acceptance remain necessary. FEAT-052 remains **REVIEW**, with earlier privacy, scheduler and broader acceptance limitations unchanged.


## 2026-10-04 browser, scheduler and client-attribute privacy evidence

An indexed StoX/production lookup for the account-owner supplied trace `ceeb2852a6a7d997ac2da3bf83e62db8` found 13 spans. Browser `GET` span `39edb6f714b028df` is the parent of Laravel `GET /api/watchlists` span `92481d1b53137d73`, with linked descendants. **PASS for stored browser-to-HTTP parentage.** The supplied Network header named `ab4403ae05d3b856`, absent from this trace. Its relationship to that specific stored request is unresolved; do not claim an exact header/span identity match.

The Watchlist server span had `client.address`, `network.peer.address`, and `user_agent.original` keys. The authorized operator applied a Collector transform deleting these three keys in addition to the existing URL/SQL deletions. The script reported root-owned backup `/var/backups/otelcol-contrib/config.yaml.feat052-client.20261004T074644Z`; the backup hash was not readable by the unprivileged shell. Independently checked installed SHA-256 `27e7099837b6927b029b644ae145947a72acba0cc14faa025cdacfbf0de18980`, Collector active/config validation, and LidoTelemetry `/up` 200. A fresh synthetic OTLP span containing documentation-range IPs and a synthetic user agent reached the sink under trace `a0116d6cf2a8a54c1bcfa84721662fa5` at 07:50:17 UTC with only `stox.probe` retained. **PASS for Collector-to-sink filtering of these keys**, not producer ingress, historical data, or other surfaces.

An inert scheduling event dispatched the production start/finish listeners without executing scheduled business work. Exact trace `a4ed5eb9a35aa3d00d2bcb58930e59b6` contained a `Command tinker` parent and child `stox.scheduler.task` at the sink. **PASS for listener export only**; actual cron task root behavior remains unproven. In the CLI process, `TraceContext::effective(true)` used the official SDK command context instead of the separately generated `TraceContext::active()` root. Running `schedule:run` solely for evidence would also execute unrelated production tasks.

Collector self-metrics since restart showed both sent and failed spans: at approximately 07:50 UTC, `otelcol_exporter_sent_spans=4152`, `otelcol_exporter_send_failed_spans=60503`, retry queue size zero. The failure reason and pre-rollout baseline are unknown; do not attribute these failures to the privacy transform or declare overall delivery reliable. The exact privacy probe did reach the sink. FEAT-052 remains **REVIEW** pending failure-category diagnosis, an actual cron trace, exact browser header reconciliation, broader privacy, and outage behavior.


## 2026-10-04 Collector batch limit and 422 diagnosis

Privileged diagnostic category counts since the 13:16 IST Collector restart were dominated by HTTP 422 (506 matching log lines), with smaller timeout and other status counts. The current LidoTelemetry runtime reports `telemetry.ingestion.max_batch_size=500`; `IngestionService::assertBatchSize` rejects larger trace arrays with 422. The Collector config had `send_batch_size: 512` and no `send_batch_max_size`. The former is a send trigger, not a hard maximum. This mismatch is a strong explanation for the repeated 422s, although individual rejected request bodies were not inspected.

A candidate changed only the batch settings to `send_batch_size: 400` and `send_batch_max_size: 400`. Installed Collector 0.161.0 validation passed. The authorized operator ran the hash-checked rollout and reported root-owned backup `/var/backups/otelcol-contrib/config.yaml.feat052-batch.20261004T081329Z` and installed SHA-256 `3ec8b3b8997cd0d947c4b39f7dab21c43dbfacf5cd5e9bd5924ef69757765208`. Independent checks found Collector active and config validation passing. Exact StoX/production sink lookup found fresh synthetic probe `feat052.batch_limit_probe` under trace `5e0315bdbdb4bee601ee403e3211cced` at 08:14:07 UTC.

After restart, Collector `otelcol_exporter_sent_spans` rose from 3,719 to 39,697 by 08:18:44 UTC. The `send_failed_spans` series was absent in these samples, consistent with zero recorded failures since restart. The trace retry queue varied (4, 18, 5, then 80 batches); throughput/queue drainage under sustained load remains to be checked. **PASS for corrected hard batch limit, active Collector and fresh sink receipt; provisional improvement for export failures.** Do not mark sustained delivery or every 422 cause resolved from these bounded observations. FEAT-052 remains **REVIEW**.


## 2026-10-04 LidoTelemetry bulk-write release verification

Under high trace load the LidoTelemetry relational writer called `updateOrCreate` for every span, while the Collector retry queue oscillated (including 136 batches) even after the hard 400-span batch cap. An isolated LidoTelemetry change replaced that loop with 50-row upserts against the existing product/environment/trace/span identity. The focused 61-span duplicate/update test passed; the full isolated SQLite PHP suite passed 18 tests and 60 assertions. LidoTelemetry PR #4 passed its PHP, JS and SDK CI jobs plus the production workflow's verification job, including clean MySQL migration; PR package/deploy jobs were skipped.

PR #4 merged as `fae4a2537959d110acf2e050f887bf9d22f74dcb`. The live `/var/www/lidotelemetry/current/DEPLOYED_COMMIT` and release symlink reported that exact SHA at 09:04 UTC; the installed writer contains the 50-row chunk implementation, PHP-FPM and Collector were active, and `https://telemetry.stoxla.in/up` returned 200. The GitHub connector did not list a workflow run for the merge SHA, so this record uses the directly observed live release rather than asserting a particular workflow conclusion.

A fresh synthetic OTLP trace was accepted by the local Collector (200) and found in the exact indexed StoX/production sink lookup as `feat052.bulk_ingest_release_probe` under trace `366bee9cc87b8c62531189e2c0a19db6` at 09:04:30 UTC. Collector metrics at 09:04:51 UTC showed trace retry queue size 0, sent spans 555,812, and failed-span counter unchanged at 7,078 from the preceding sample. **PASS for the deployed build identity, health, and this end-to-end sink receipt; provisional improvement in throughput.** These counters are cumulative since Collector restart and do not prove long-term zero loss or distinguish causes of historical failures. Continue bounded queue/failure monitoring and the remaining browser-header, actual cron, broader privacy and outage checks. FEAT-052 stays **REVIEW**.

## 2026-10-04 exact browser request-header reconciliation and post-release delivery

For a signed-in `GET https://stoxla.in/api/watchlists`, the account owner supplied the **Request Headers** value `00-9769264e4ffb50ff522b5646efec9893-58f500aa2c2b604e-01`. An indexed lookup constrained by StoX product, production environment and exact trace ID found browser `GET` span `58f500aa2c2b604e`, Laravel `GET /api/watchlists` span `623a61c6fa1f3366` parented to it, and database/model descendants parented to the server span at 09:23:41 UTC. **PASS for exact Request Header → exported browser span → server span → backend descendant identity and parentage on this production request.**

The earlier value `00-9769264e4ffb50ff522b5646efec9893-59a42697372899e1-01` was confirmed by the account owner to be from **Response Headers**. StoX's HTTP middleware creates an outgoing response `traceparent` with a fresh child ID without exporting that ID as a span; its absence from the stored span set is therefore expected. The older trace `ceeb2852a6a7d997ac2da3bf83e62db8` still has its own unresolved header provenance, but is no longer needed to establish the exact production request-header link.

Post-bulk-write Collector metrics at 09:18 UTC showed trace queue 1, sent spans 745,418, and cumulative failed spans 7,478. The failed counter had risen 400 from the 09:04 sample, then remained unchanged while sent spans increased by more than 117,000. Privileged, category-only journal aggregation since 09:02 UTC returned one HTTP 400, one 413, two 500, three 502, and 26 timeout matching log lines; counts are log-line occurrences, not necessarily distinct failed batches or spans. The installed Collector still has `send_batch_size: 400` and `send_batch_max_size: 400`; LidoTelemetry Nginx has a 10 MiB request limit and PHP `post_max_size` is 8 MiB. One 413 is not enough to justify a further batch change without a bounded recurrence/size diagnosis. **Sustained zero-loss delivery is not established.**

The production cron invokes `schedule:run` every minute and includes an every-minute universe heartbeat task. A recent-name sink query was stopped after it remained slow on the large trace table; no actual cron trace ID was verified. The controlled listener-only probe remains separate. Broader privacy and outage fail-open acceptance remain open. FEAT-052 stays **REVIEW**.

## 2026-10-04/05 path and exception privacy continuation

A controlled 404 request with a synthetic token-like path segment reached LidoTelemetry under exact trace `326e13af525d717f153521e858cc59a9`; the sink's attribute keys included `url.path` and a marker-presence boolean was true. No real token was used or printed. This identified an additional export privacy gap beyond query, SQL and client-address keys.

The authorized operator ran a hash-checked Collector rollout, retaining root-owned backup `/var/backups/otelcol-contrib/config.yaml.feat052-path.20261004T094211Z`. Installed config SHA-256 `3cd7429b014f3be2ce2578e8457d9739dfd579f1af944e5a82481c2462d10d6d` validated on Collector 0.161.0; service active and sink `/up` 200. The transform removes `url.path`, `code.file.path`, `exception.message` and `exception.stacktrace` from span attributes, and both exception text keys from span-event attributes, while retaining earlier URL/SQL/IP/user-agent deletions. An isolated local Collector with the same transform accepted a synthetic span event (HTTP 200), emitted the span and safe exception type through its debug exporter, and did not emit the marker. A fresh production 404 trace `54afa56c82f13f2b90fec3a95e723862` reached the indexed sink with marker-presence false and both `url.path` and `code.file.path` absent. **PASS for this Collector export-boundary path and tested event-text filtering slice.** Historical data remains; producer ingress and other telemetry surfaces require separate review.

StoX PR #60 passed canonical PHP backend CI and was merged as `113355c18444be85d7f40b6c465d451076e957b9`. It changes the custom HTTP fallback to emit a matched Laravel route template only, omits raw exception messages, and replaces client-provided route-view path values with an allowlisted static `route_group` (unknown/dynamic route families become `other`). The live StoX build at 2026-10-05 02:49 UTC was `073ba812e8e2fd36360060f4ea9254fd3f687541`, and the installed controller contained `route_group`. A controlled direct controller invocation in that deployed runtime (not an authenticated browser HTTP request) supplied a synthetic `/invite/<marker>` route, returned 200, and exported exact trace `c199f3a7be6d91af2d3c2e5b2da5c547`. Its indexed `stox.ui.route_view` span had keys `route_group`, `wall_duration_ms`, and `active_duration_ms`; `route_group=other`, and marker-presence was false across all three spans in the trace. **PASS for deployed producer route-view sanitization in this controlled invocation.** The fallback branch and actual authenticated browser route-view request were covered by CI tests, not a production HTTP probe.

At 2026-10-05 02:50 UTC the Collector trace queue was 1, sent spans 2,429,960, and cumulative failed spans 1,257 since the 2026-10-04 09:42 UTC restart. At the earlier 02:49 sample the queue was 0, sent spans 2,429,193 and failures were also 1,257. StoX and LidoTelemetry `/up` both returned 200. The flat failed counter over this short interval does not establish sustained zero-loss delivery or explain prior failures. Actual cron root, broader producer/attribute/privacy surfaces, outage fail-open and sustained delivery remain open. FEAT-052 stays **REVIEW**.


## 2026-10-05 implementation decision and functional-test handoff

**Decision: FEAT-052 IMPLEMENTED.** This records deployed StoX producer and Collector integration, with verified browser-request parentage, CLI → database worker propagation, HTTP metric receipt, natural cron root/task spans, privacy mitigations and fail-open probes. The natural cron trace `a5cd8c5e18cdf304fd55026a6f8775ef` at 02:57 UTC contains root `Command schedule:run` and child `stox.scheduler.task` spans, including `universe-schedule-heartbeat`. Its sampled attribute-key inventory is bounded to function/line, database system/namespace/operation, HTTP method/status/body size, server address/port, task and URL scheme; prohibited URL path/query and SQL text keys were absent.

An isolated unreachable-exporter probe returned HTTP 200 for a read-only `GET /api/build-info` and completed a harmless sync `about` job, while SDK export errors were logged after the operations. At the later Collector sample, the trace queue was 0, sent spans were 2,505,056 and cumulative failed spans remained 1,257 over the short observed interval. These are bounded results, not proof of all business/async outage behavior or long-term zero-loss delivery.

The open broader privacy matrix, actual asynchronous-worker/business-flow outage, browser outage/recovery and sustained-delivery window are now explicit product functional testing scenarios in [`V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md`](../testing/V8-FEAT-052-FUNCTIONAL-TEST-PLAN.md). Their current state remains PARTIAL or OPEN until executed. This decision supersedes the historical REVIEW status statements above without rewriting their dated evidence or weakening the frozen specification.


## 2026-10-06 passive sustained-delivery observation

Read-only observation on StoX release `20261006173350-38107807be32` (commit `38107807be3293139732b71f397d106ee6e1ef1d`). The `otelcol-contrib` and `stoxla-queue` services were active; Collector metrics were scraped locally at three points, with label values redacted:

| UTC sample | Queue (summed) | Sent spans (approx.) | Accepted spans (approx.) | Failed spans (cumulative) | Refused spans |
|---|---:|---:|---:|---:|---:|
| 18:28:42 | 0 | 8,691,430 | 8,769,700 | 78,274 | 0 |
| 18:33:42 | 0 | 8,692,390 | 8,770,670 | 78,274 | 0 |
| 18:38:42 | 0 | 8,693,360 | 8,771,630 | 78,274 | 0 |

The Collector service start timestamp was 2026-10-04 15:12:12 IST. A sanitized journal aggregation found no error/warning/retry/timeout lines since that start or during the observation window. This supports a bounded 10-minute no-new-failure/queue-growth slice with active throughput, but the nonzero cumulative failed-span counter remains unexplained. The samples were not correlated to exact trace IDs at LidoTelemetry, and the counter values were read at rounded display precision. **FT-052-05 remains PARTIAL; do not mark sustained delivery complete from this observation alone.** The next check is to identify which exporter/pipeline contributes the cumulative failures, then correlate a fresh synthetic non-secret trace across StoX, Collector and LidoTelemetry with exact IDs and receipt times.
