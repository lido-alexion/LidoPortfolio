# V8 — Standalone Telemetry Platform

| Field | Value |
|---|---|
| **Feature ID** | `V4-FEAT-052` |
| **Version target** | V8 |
| **Document type** | Standalone product architecture specification |
| **Status** | CORE ARCHITECTURE DECIDED; implementation details may evolve |
| **Created** | 2026-09-09 |
| **Canonical path** | `specs/V7-Telemetry-Platform.md` |
| **Origin** | Moved out of StoX V6 planning after architecture deliberation; subsequently moved from V7 to V8 because it is a standalone product rather than StoX-internal scope |

## 1. Product positioning

Telemetry is **not a StoX feature**. It is a separate, independently deployable application/product that StoX will use as its first producer/consumer.

The platform must remain product-independent so that other applications can integrate later without depending on StoX concepts, authentication, deployment, repository, or domain models.

Core positioning:

```text
StoX -----------\
Other App A -----+--> Telemetry Platform
Other App B -----/
```

Telemetry owns collection, storage, analytics, query APIs, dashboards, and its own UI. StoX only owns its instrumentation/integration.

The V8 goal is **functional, lightweight, simple, and product-independent**. Enterprise-grade additions are deliberately deferred unless required for correctness or difficult to retrofit later.

## 2. Scope

V8 Telemetry covers both:

1. **Operational telemetry / observability**
   - failures, retries, timeouts
   - service/API latency
   - scheduler/background-job health
   - operational metrics
   - structured logs
   - distributed traces
   - correlation across frontend/backend/service activity

2. **Product-usage analytics**
   - page/view navigation
   - tab/sub-view changes
   - explicit interactions/actions
   - workflows
   - sessions
   - active/visible dwell time
   - total wall-clock view lifespan
   - feature adoption
   - usage trends
   - funnels, journeys, retention/cohorts
   - metadata-driven segmentation

## 3. Core product hierarchy

V8 is single-owner and multi-product. Organizational/workspace tenancy is intentionally deferred.

```text
Telemetry Platform
  └── Product
      └── Environment
          └── Telemetry Signals / Analytics
```

Each product has:

- immutable internal `product_id`
- stable `product_key` such as `stox`
- mutable display `product_name`
- one or more environments such as `production`, `staging`, `development`, `test`

Environment is first-class and associated with credentials; producers do not get to impersonate another product/environment by merely changing payload fields.

A future organizational/multi-tenant layer may be added around this core without changing the event model.

## 4. Signal families

Telemetry recognizes four signal families:

```text
Product
├── Events
├── Metrics
├── Logs
└── Traces
```

### 4.1 Events

Events are the primary source for product analytics and application workflows.

Event names are flexible but follow a namespace convention:

```text
navigation.view_started
navigation.view_ended
interaction.button_clicked
workflow.started
workflow.completed
operational.api_failed
stox.recommendation_approved
```

There is no mandatory event registry or pre-approval workflow in V8.

### 4.2 Metrics

Metrics retain standard metric semantics rather than being represented merely as generic numeric events.

Initial metric types:

- counter
- gauge
- histogram
- timer/duration

Metrics support dimensions/labels/structured metadata. Native ingestion is supported, with Prometheus/OpenTelemetry-compatible adapters possible around it.

### 4.3 Logs

Logs are structured only. Canonical attributes may include:

- timestamp
- severity
- service/component
- message code/template
- correlation/trace IDs
- structured metadata

Arbitrary raw request/response bodies and uncontrolled free-form dumps are outside the contract.

### 4.4 Traces

Distributed tracing uses **OpenTelemetry-compatible ingestion**. Telemetry understands traces/spans as first-class operational signals but does not invent a competing trace transport.

OpenTelemetry is therefore an interoperability layer, **not the defining architecture of the Telemetry product**.

## 5. Event model

Events are immutable and append-only.

A common event envelope should include at least:

```text
event_id
product_id
environment
occurred_at
received_at
event_type
category
user_id nullable
anonymous_id nullable
session_id nullable
view_instance_id nullable
sequence_number nullable
correlation_id nullable
trace_id nullable
span_id nullable
metadata JSON
```

### 5.1 Identity

User identity is product-scoped. Telemetry does not automatically correlate the same human across multiple products.

Anonymous telemetry is supported. A product may generate `anonymous_id` before authentication and later emit an explicit identity-link event when a product-scoped authenticated `user_id` becomes known.

Telemetry never infers identity links automatically.

### 5.2 Metadata

Products may attach arbitrary structured metadata without registration.

Metadata supports product-specific context such as:

```text
plan = pro
subscription_tier = free
execution_mode = automatic
portfolio_type = live
region = india
viewport_class = desktop
```

The platform automatically discovers metadata keys and maintains an optional catalog with information such as:

- key
- observed type(s)
- product scope
- first seen
- last seen
- approximate cardinality where useful
- optional human-readable label/description

High-cardinality metadata is accepted and remains queryable, but may be classified and excluded from default segmentation suggestions.

There is no automatic semantic mapping between product-specific metadata names in V8.

### 5.3 Privacy boundary

Telemetry is pseudonymous/product-context oriented. The event model must not become a secondary store of arbitrary user content.

Explicitly excluded by default:

- note contents
- prompts
- search text
- transaction descriptions/free text
- secrets/tokens
- broker credentials
- arbitrary form contents
- uncontrolled request/response bodies

Server-side ingestion validation/redaction remains the policy boundary.

## 6. Sessions and views

Sessions are first-class analytics entities.

Each browser tab creates its own telemetry `session_id`. Multiple tabs from the same user therefore have separate sessions and cannot incorrectly close/update one another's active view.

Each logical page/tab/sub-view instance receives its own `view_instance_id`.

Typical sequence:

```text
navigation.view_started  (session S1, view V1)
navigation.visibility_hidden
navigation.visibility_visible
navigation.view_ended    (session S1, view V1)
navigation.view_started  (session S1, view V2)
```

`view_ended` is a new immutable event; it does not update the earlier `view_started` event.

### 6.1 Time-on-view

Two durations are retained/derived:

1. **Active/visible duration** — primary engagement metric; pauses while the browser tab is hidden/backgrounded.
2. **Wall-clock lifespan** — elapsed time from view start to end, including hidden periods.

Incomplete final views remain explicitly distinguishable from complete ones. Heartbeat-derived duration is estimated, not presented as exact.

### 6.2 Heartbeat

The browser SDK emits a lightweight periodic heartbeat while the application/tab is active/visible.

Purpose:

- abrupt tab/browser termination detection
- bounded estimation of otherwise incomplete final-view duration
- session liveness/abandonment detection

Initial engineering default may be approximately 60 seconds and can evolve/configure without becoming a product contract.
