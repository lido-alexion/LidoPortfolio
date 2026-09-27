# StoX V8 OpenTelemetry / LidoTelemetry Integration Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-052 — StoX OpenTelemetry / LidoTelemetry Integration |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Canonical path** | `docs/archive/specs/V8-StoX-OpenTelemetry-LidoTelemetry-Integration-Specification.md` |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` |
| **Telemetry backend** | Existing LidoTelemetry product/service |
| **Primary implementation agent** | Codex |

---

## 1. Purpose

V4-FEAT-052 integrates StoX with the existing standalone **LidoTelemetry** product by instrumenting StoX with **OpenTelemetry** and exporting StoX telemetry to LidoTelemetry.

StoX is only a telemetry producer in this epic. LidoTelemetry already exists as a separate product and is responsible for receiving, storing, displaying, querying and analysing telemetry data.

The implementation boundary is therefore:

```text
StoX React/browser ----\
StoX Laravel/backend ---+--> OpenTelemetry --> OpenTelemetry Collector --> LidoTelemetry
StoX queues/scheduler --/
```

This epic adds instrumentation and export capability to StoX. It does **not** redesign, extend or implement the LidoTelemetry product itself.

---

## 2. Corrected product boundary

### 2.1 StoX owns

- OpenTelemetry SDK/instrumentation integration in the React frontend;
- OpenTelemetry SDK/instrumentation integration in Laravel/backend runtime;
- queue/background-job instrumentation;
- scheduler instrumentation;
- outbound-service instrumentation;
- standard trace-context propagation;
- explicit StoX business telemetry;
- focused custom metrics;
- telemetry privacy/redaction rules;
- export to a common OpenTelemetry Collector;
- environment/service/resource attribution.

### 2.2 LidoTelemetry owns

The existing LidoTelemetry product remains outside this epic and owns, independently of StoX:

- ingestion service behavior after Collector export;
- telemetry persistence;
- search/query;
- dashboards/UI;
- analytics;
- retention;
- product management;
- LidoTelemetry authentication/authorization;
- any LidoTelemetry-specific data processing.

No LidoTelemetry product code is required to change merely to satisfy FEAT-052 unless an actual protocol compatibility gap is discovered during implementation.

### 2.3 Superseded historical interpretation

Earlier V7/V8 planning described FEAT-052 as building the standalone Telemetry platform itself. That interpretation is superseded for StoX V8.

`docs/archive/specs/V7-Telemetry-Platform.md` remains historical architecture/reference material for the LidoTelemetry product, but is **not** the implementation contract for this StoX epic.

---

## 3. OpenTelemetry signal scope

StoX SHALL use OpenTelemetry-native concepts and supported instrumentation rather than inventing a parallel proprietary telemetry framework.

The integration SHALL support the OpenTelemetry signal families relevant to the StoX runtimes, with V8 implementation focused on:

- **Traces** — distributed request/workflow execution and causal correlation;
- **Metrics** — automatic/runtime metrics where available plus a focused custom StoX metric set;
- **OpenTelemetry events/span events** — explicit meaningful StoX business lifecycle markers where appropriate;
- **Context propagation** — W3C/OpenTelemetry trace context across supported service boundaries.

Existing general application logs remain outside the OpenTelemetry export path for this epic. Exception information may still be recorded on spans as trace error/exception data.

Baggage, if used at all, must be tightly controlled and must never carry secrets, credentials, direct PII or sensitive financial content.

---

## 4. Frozen product decisions

| Decision | Frozen choice |
|---|---|
| 052-01 | Instrument frontend, backend and background processing. |
| 052-02 | Use an OpenTelemetry Collector as the common telemetry gateway. |
| 052-03 | Capture automatic technical telemetry plus explicit StoX business telemetry. |
| 052-04 | Explicit business telemetry covers major actions plus meaningful read/analysis activity, not every click. |
| 052-05 | Use a stable pseudonymous telemetry user ID instead of direct user PII. |
| 052-06 | Selectively allow non-sensitive domain identifiers needed for correlation/analysis. |
| 052-07 | Telemetry is strictly non-blocking and fail-open. |
| 052-08 | Use one common telemetry configuration model across environments; emitted telemetry still identifies its environment. |
| 052-09 | No sampling in V8; emit 100% of configured telemetry. |
| 052-10 | Use a central lightweight naming convention/catalog for StoX telemetry names and attributes. |
| 052-11 | Propagate end-to-end distributed trace context from browser through Laravel and downstream calls where supported. |
| 052-12 | Preserve causal linkage for background work; independently scheduled jobs create new root traces. |
| 052-13 | Include normalized SQL/query text where safely available, excluding bound values/secrets. |
| 052-14 | Capture normalized outbound API endpoint details, excluding secrets and payload bodies. |
| 052-15 | Keep existing application logs separate from OpenTelemetry. |
| 052-16 | Automatically record backend exceptions as trace errors with safe exception detail. |
| 052-17 | Capture supported frontend JavaScript errors and correlate where possible. |
| 052-18 | Add a focused custom StoX metric set. |
| 052-19 | Capture frontend route/view duration including active/visible versus wall-clock behavior where practical. |
| 052-20 | Automatically instrument every meaningful frontend SPA route/view transition. |

---

## 5. Runtime coverage

### 5.1 React/browser

Instrument the browser application for:

- meaningful SPA route/view transitions;
- frontend request spans;
- W3C trace-context propagation to StoX APIs;
- page/view lifecycle;
- active/visible duration where practical;
- wall-clock view duration;
- tab visibility changes needed to calculate active duration;
- uncaught JavaScript exceptions;
- unhandled promise rejections;
- important failed frontend API operations;
- explicit StoX business telemetry triggered from user-visible workflows.

Browser instrumentation must avoid automatic capture of arbitrary form input, raw DOM text, secrets or sensitive content.

### 5.2 Laravel/backend

Instrument the backend for:

- inbound HTTP requests;
- route/controller execution where supported by the chosen instrumentation approach;
- database activity;
- outbound HTTP/service calls;
- exception/error status;
- relevant framework/runtime operations supported by stable instrumentation;
- explicit domain/business spans and events where automatic instrumentation lacks StoX meaning.

### 5.3 Queues/background processing

Instrument:

- queued-job execution;
- retries;
- failures;
- durations;
- request/workflow causal context where available;
- links/correlation when a long-running or asynchronous operation should not remain a direct child span.

### 5.4 Scheduler

Each independently scheduled StoX job/run starts a new root trace and includes safe job/run metadata.

Relevant scheduled work includes, where applicable:

- market-data refresh;
- fundamentals/data import;
- recommendation generation;
- brokerage reconciliation;
- ML lifecycle work introduced by other epics;
- cleanup/maintenance work worth observing.

---

## 6. Distributed tracing

Use standard OpenTelemetry/W3C context propagation.

Preferred end-to-end flow:

```text
User action
   ↓
React/browser span
   ↓ traceparent
Laravel HTTP request
   ↓
DB / external provider spans
   ↓
queued/background work with propagated context or explicit link
```

Requirements:

- frontend-to-backend context propagation where browser security/CORS configuration permits;
- Laravel must continue the incoming trace rather than creating an unrelated trace when valid parent context exists;
- outbound HTTP calls propagate trace context only to destinations for which this is safe/appropriate;
- queued jobs preserve causal linkage where valid;
- independently scheduled jobs start new traces;
- trace correlation must never require business execution to wait on telemetry delivery.

---

## 7. Technical automatic instrumentation

Use stable/appropriate OpenTelemetry instrumentation packages for the current React/JavaScript and PHP/Laravel stacks.

Prefer automatic instrumentation for technical mechanics where it provides safe, accurate coverage, including:

- HTTP server requests;
- HTTP client calls;
- supported database activity;
- framework/runtime instrumentation;
- browser fetch/XHR where appropriate;
- browser document/navigation performance where appropriate.

Manual instrumentation is used where StoX-specific business semantics are required.

Do not duplicate the same operation with competing automatic and manual spans unless the manual span represents a distinct semantic layer.

---

## 8. Explicit StoX business telemetry

Business telemetry SHALL cover important state changes and meaningful analytical/read behavior, while avoiding indiscriminate click tracking.

Initial categories include:

### 8.1 Authentication/session workflow

Examples:

- login succeeded/failed at a safe categorical level;
- logout;
- authenticated session established/ended where useful.

Do not emit passwords, tokens or authentication secrets.

### 8.2 Navigation and analysis usage

Examples:

- stock viewed;
- portfolio/dashboard viewed;
- watchlist viewed;
- stock-detail area viewed;
- screener executed;
- recommendation viewed;
- strategy/recommendation workflow milestones;
- meaningful settings/help/navigation milestones.

### 8.3 Portfolio/watchlist

Examples:

- watchlist item added/removed;
- portfolio workflow invoked;
- relevant portfolio operation lifecycle.

### 8.4 Screener

Examples:

- screener executed;
- screener created/updated/deleted where applicable;
- execution succeeded/failed;
- result count as a metric/attribute where safe and useful.

### 8.5 Strategy/recommendation

Examples:

- strategy execution started/completed/failed;
- recommendation generated;
- recommendation viewed;
- recommendation accepted/rejected where applicable;
- recommendation execution lifecycle.

### 8.6 Trading/broker workflows

Examples:

- broker connection initiated/succeeded/failed/disconnected;
- order requested;
- order placement succeeded/failed;
- order cancellation requested/confirmed/failed;
- order filled/partially filled where StoX observes the transition;
- reconciliation lifecycle.

Never emit brokerage credentials, session secrets, API tokens, PIN/TOTP values or sensitive request/response bodies.

### 8.7 Data/provider/background workflows

Examples:

- market-data refresh started/completed/failed;
- provider request outcome;
- import/bootstrap run lifecycle;
- job retry/failure;
- scheduled workflow completion.

---

## 9. Telemetry naming convention

Maintain a lightweight central catalog/convention for explicit StoX telemetry.

Recommended pattern:

```text
stox.<domain>.<action>
```

Examples:

```text
stox.stock.viewed
stox.watchlist.item_added
stox.screener.executed
stox.recommendation.viewed
stox.recommendation.accepted
stox.order.placement_requested
stox.order.placed
stox.order.cancel_requested
stox.order.cancelled
stox.market_data.refresh_completed
```

Attribute names should likewise be centrally consistent, for example:

```text
stox.stock.symbol
stox.instrument.id
stox.portfolio.id
stox.strategy.id
stox.screener.id
stox.recommendation.id
stox.order.id
stox.provider.name
stox.job.name
stox.job.run_id
```

This catalog is a consistency mechanism, not a heavyweight schema-approval system.

---

## 10. Identity

StoX SHALL use a stable pseudonymous telemetry identifier per user.

Requirements:

- do not emit user email/name/phone merely for correlation;
- telemetry identity must remain stable enough for longitudinal analysis;
- identity generation/storage must be deterministic or persistently assigned by StoX according to implementation choice;
- changing user-facing profile data must not unnecessarily change the telemetry ID;
- anonymous/pre-auth telemetry may use an anonymous/session identifier where useful, but must not attempt unsafe identity inference.

---

## 11. Domain identifiers

Non-sensitive StoX domain identifiers may be included when they materially improve correlation, debugging or analysis.

Allowed examples include, subject to implementation review:

- stock symbol;
- instrument ID;
- portfolio ID;
- recommendation ID;
- strategy ID;
- screener ID;
- order ID;
- broker/provider name;
- job/run ID.

They must not be treated as permission to emit sensitive payloads.

High-cardinality identifiers belong primarily on traces/events, not as unrestricted metric labels.

---

## 12. Database instrumentation

Capture database operation information where supported and safe.

Allowed:

- DB system/name where non-sensitive;
- operation type;
- duration;
- success/failure;
- normalized/parameterized statement text where safely available.

Disallowed:

- bound parameter values;
- secrets;
- credentials;
- sensitive user content;
- raw SQL containing unredacted sensitive literals.

If safe normalization cannot be guaranteed for a query, omit statement text rather than leaking values.

---

## 13. Outbound HTTP/service instrumentation

Capture, where useful:

- destination/provider/service;
- HTTP method;
- normalized route/path/template where possible;
- response status;
- duration;
- retry/failure category;
- trace context where safe/appropriate.

Do not capture:

- Authorization headers;
- API keys/tokens;
- sensitive query-string values;
- full request/response bodies;
- brokerage credentials;
- arbitrary payload dumps.

---

## 14. Exception/error telemetry

### 14.1 Backend

For traced backend requests/jobs, record exceptions on the active span where supported:

- error status;
- exception class/type;
- safe exception message where appropriate;
- stack trace where supported and safe.

Sanitize messages if they may contain sensitive values.

### 14.2 Frontend

Capture supported browser-side failures such as:

- uncaught exceptions;
- unhandled promise rejections;
- meaningful failed frontend API operations;
- important route/render failures where instrumentation supports them.

Correlate to active trace/session context where possible.

---

## 15. Custom metrics

Add a focused, bounded set of StoX-specific metrics rather than instrumenting every business event as a metric.

Candidate metrics include:

- API latency histograms where not already adequately covered by automatic instrumentation;
- job duration;
- job failure count;
- job retry count;
- provider latency;
- provider failure count/rate;
- order placement success/failure count;
- recommendation generation count;
- screener execution count;
- queue depth where practical.

Metric attributes/labels must be low-cardinality. IDs such as order ID, recommendation ID, user ID or trace ID must not become metric dimensions.

---

## 16. Frontend route/view telemetry

Every meaningful SPA route/view transition should be automatically instrumented.

View telemetry should distinguish, where practical:

- route/view opened;
- route/view ended;
- active/visible duration;
- total wall-clock duration;
- tab visibility transitions.

A hidden/background tab should not be treated as continuously active engagement.

Exact heartbeat/timing algorithms are implementation-level details.

---

## 17. Existing application logs

Existing StoX application logging remains separate from OpenTelemetry in V8.

Therefore:

- current Laravel/application log sinks remain unchanged unless separately required;
- the general log stream is not exported to LidoTelemetry through FEAT-052;
- OpenTelemetry traces may still carry exception records and structured span events needed for telemetry;
- a future epic may integrate application logs if desired.

---

## 18. Collector architecture

StoX SHALL export telemetry through an **OpenTelemetry Collector** rather than coupling every runtime directly to LidoTelemetry transport details.

Collector responsibilities may include:

- OTLP reception;
- batching;
- bounded retry;
- redaction/filtering;
- resource enrichment;
- routing/export to LidoTelemetry;
- exporter credential isolation.

StoX business code must not know LidoTelemetry persistence/query/UI details.

The Collector configuration is infrastructure configuration, not business-domain code.

---

## 19. Configuration and environments

Use one common telemetry configuration model across StoX environments rather than materially different instrumentation rules per environment.

Telemetry records must still identify the source environment, for example:

```text
development
staging
production
test
```

Environment, service name/version and deployment/resource identity should use standard OpenTelemetry resource attributes where applicable.

Endpoint addresses, credentials and environment values may naturally differ through deployment configuration even though the instrumentation model remains common.

---

## 20. Sampling

V8 uses **no sampling**.

All telemetry that passes the configured instrumentation/privacy rules is emitted.

Sampling may be introduced later only if telemetry volume, performance or cost justifies it.

Do not build V8 business behavior around a future sampling assumption.

---

## 21. Non-blocking/fail-open requirement

Telemetry must never become a dependency of StoX business correctness.

If the SDK, Collector or LidoTelemetry is unavailable:

- HTTP/business requests continue;
- login continues;
- trading/order workflows continue;
- analysis/screener workflows continue;
- background jobs continue according to their own business logic;
- telemetry may retry/buffer only within bounded implementation limits;
- exhausted telemetry delivery is diagnostic only.

Telemetry initialization/export failures must not crash StoX startup unless the implementation cannot safely isolate them; such coupling must be designed out.

---

## 22. Privacy and redaction boundary

Never emit telemetry containing:

- passwords;
- API keys;
- access/refresh tokens;
- OAuth/broker session secrets;
- PIN/TOTP/OTP values;
- private keys;
- raw authentication headers;
- brokerage credentials;
- arbitrary request/response bodies;
- arbitrary form contents;
- direct user PII merely for convenience;
- uncontrolled free-form user content.

Implement a central attribute allow/deny/redaction policy where practical so safety does not depend solely on every call site remembering individual exclusions.

When uncertain whether an attribute is sensitive, omit it.

---

## 23. Performance requirements

Instrumentation must remain lightweight relative to StoX business processing.

Implementation should use:

- asynchronous/batched export where supported;
- bounded queues/buffers;
- fail-open behavior;
- no synchronous dependency on LidoTelemetry response for business workflows;
- no unbounded browser/local/server telemetry accumulation;
- no excessive high-cardinality metrics.

Exact queue sizes, batch sizes, retry timings and memory limits are implementation parameters.

---

## 24. Implementation-level decisions delegated to Codex/architecture

The following do not require further PO decisions as long as they preserve this contract:

- exact OpenTelemetry PHP packages;
- exact Laravel integration mechanism;
- exact OpenTelemetry JavaScript/browser packages;
- Collector deployment topology;
- OTLP HTTP vs gRPC where supported/appropriate;
- endpoint/credential environment variable names;
- span names consistent with semantic conventions;
- exact resource attributes;
- timing/retry/buffer limits;
- safe Collector redaction processors;
- exact pseudonymous telemetry-ID generation/storage mechanism;
- exact frontend view-duration implementation;
- exact custom metric names;
- test fixtures and mock exporters;
- instrumentation helper/wrapper structure.

Prefer official/stable OpenTelemetry semantic conventions and libraries where available.

---

## 25. Acceptance criteria

1. StoX emits OpenTelemetry from React/browser, Laravel/backend and relevant background processing.
2. An OpenTelemetry Collector is the common export gateway to LidoTelemetry.
3. LidoTelemetry product code is not reimplemented inside StoX.
4. Browser-to-Laravel traces correlate end-to-end where technically supported.
5. Laravel outbound DB/service work appears as correlated spans where supported.
6. Request-triggered background work preserves causal trace context/linkage where valid.
7. Independently scheduled jobs create their own root traces.
8. Every meaningful SPA route/view transition is automatically instrumented.
9. Frontend active/visible and wall-clock view duration can be distinguished where practical.
10. Supported frontend JavaScript errors are captured safely.
11. Backend exceptions mark traces as errors and include safe diagnostic detail.
12. Explicit StoX business telemetry covers major actions and meaningful analysis/read behavior.
13. Business telemetry uses a central naming convention/catalog.
14. A stable pseudonymous user identifier is used rather than direct PII.
15. Selected non-sensitive domain IDs can be correlated without becoming high-cardinality metric labels.
16. DB instrumentation never includes bound parameter values or unsafe raw literals.
17. Outbound HTTP instrumentation never emits auth headers, secrets or payload bodies.
18. A focused set of StoX custom metrics is present with low-cardinality dimensions.
19. Existing general application logs remain on their existing path.
20. Telemetry is unsampled in V8.
21. Telemetry failure cannot fail a StoX business workflow.
22. Telemetry uses bounded retry/buffering behavior.
23. Sensitive/credential fields are excluded by policy and tests.
24. Telemetry source environment/service identity is present on emitted telemetry.
25. Integration tests can verify export without requiring production LidoTelemetry availability.

---

## 26. Definition of done

FEAT-052 is complete when:

- OpenTelemetry is integrated into all agreed StoX runtime surfaces;
- automatic technical instrumentation is active and validated;
- explicit business instrumentation covers the agreed core StoX workflows;
- distributed tracing works across browser/backend/downstream/background boundaries where supported;
- focused metrics are exported;
- errors/exceptions are correlated safely;
- Collector export to LidoTelemetry is working;
- pseudonymous identity and privacy/redaction controls are enforced;
- no-sampling and fail-open semantics are verified;
- telemetry does not regress business flows when Collector/LidoTelemetry is unavailable;
- tests cover propagation, redaction, instrumentation helpers, exporter failure and representative business events;
- V8 register links this document as the authoritative FEAT-052 contract.

---

## 27. Explicit non-goals

FEAT-052 does **not**:

- build or redesign LidoTelemetry;
- add LidoTelemetry dashboards or analytics features;
- change LidoTelemetry storage;
- implement LidoTelemetry authentication;
- replace StoX application logging;
- capture every click/control interaction;
- export secrets/PII/free-form payloads;
- introduce sampling;
- make telemetry delivery business-critical.

The frozen V8 boundary is:

```text
StoX = telemetry producer
OpenTelemetry = instrumentation/propagation standard
OpenTelemetry Collector = common gateway
LidoTelemetry = existing telemetry receiver/analytics product
Business behavior = independent of telemetry availability
```
