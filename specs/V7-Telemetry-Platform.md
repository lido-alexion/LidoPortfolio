# V7 — Standalone Telemetry Platform

| Field | Value |
|---|---|
| **Feature ID** | `V4-FEAT-052` |
| **Version target** | V7 |
| **Document type** | Standalone product architecture specification |
| **Status** | CORE ARCHITECTURE DECIDED; implementation details may evolve |
| **Created** | 2026-09-09 |
| **Canonical path** | `specs/V7-Telemetry-Platform.md` |
| **Origin** | Moved out of StoX V6 planning after architecture deliberation |

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

The V7 goal is **functional, lightweight, simple, and product-independent**. Enterprise-grade additions are deliberately deferred unless required for correctness or difficult to retrofit later.

## 2. Scope

V7 Telemetry covers both:

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

V7 is single-owner and multi-product. Organizational/workspace tenancy is intentionally deferred.

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

There is no mandatory event registry or pre-approval workflow in V7.

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

There is no automatic semantic mapping between product-specific metadata names in V7.

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

### 6.3 First-class analytics entities

Initial materialized/queryable entities include:

- User
- AnonymousIdentity
- Session
- View
- ViewDurationSummary

No separate mutable user-profile property store exists in V7. Segmentation attributes remain event metadata so historical context is preserved.

## 7. Context metadata propagation

SDKs support persistent contextual metadata so products do not repeat common attributes manually on every event.

Suggested precedence:

```text
application context
    ↓
session context
    ↓
view context
    ↓
event-specific metadata
```

More specific context wins on conflicts. Every emitted event stores the fully resolved metadata snapshot at event time; historical events never change when context later changes.

## 8. Correlation

Events, Metrics, Logs, and Traces share a common correlation model where applicable.

Possible correlation fields include:

- product/environment
- user/anonymous identity
- session
- trace/span IDs
- application `correlation_id`
- domain/workflow identifiers

Correlation IDs are producer-owned. The originating application generates/propagates causal context. Telemetry preserves/indexes it but does not fabricate authoritative causal relationships after ingestion.

## 9. Ordering and time quality

Producer occurrence time is authoritative for chronology.

Telemetry stores:

- `occurred_at` — producer time
- `received_at` — Telemetry ingestion time
- optional monotonic per-session `sequence_number`

Analytics ordering preference:

1. session sequence where available
2. `occurred_at`
3. `received_at` as fallback/tie-breaker

Events with suspicious clock skew are accepted but quality-flagged. The platform does not silently rewrite producer timestamps.

## 10. Delivery semantics

Telemetry targets **at-least-once delivery with deduplication**.

- producers generate stable `event_id`
- retries preserve `event_id`
- duplicate delivery is idempotently ignored/merged at ingestion
- telemetry must never block business workflows

Observability/analytics failures remain isolated from application business behavior. Audit requirements of source products remain separate from telemetry.

### 10.1 Browser delivery

Browser SDK supports:

- unbatched delivery by default
- configurable batching
- bounded persistent offline buffering using browser persistence such as IndexedDB
- retry after network/service recovery
- original session/order/timestamp preservation

Restarting a browser never creates false session continuity; old buffered events retain their original session identity and a newly opened tab creates a new session.

### 10.2 Server-side delivery

Server SDK uses a bounded asynchronous durable queue. Business requests do not wait for Telemetry delivery.

Queued telemetry survives application/process restarts and preserves original event identity, timestamp, and correlation context.

Exhausted delivery is diagnostic rather than a business failure.

### 10.3 Diagnostics

SDK delivery/storage/buffer problems are diagnostic-only by default. SDKs expose health/status callbacks or diagnostic state for developers/admin tooling; ordinary end users are not interrupted.

## 11. SDKs and instrumentation

Telemetry provides official SDKs plus raw HTTP APIs.

Initial SDK priority:

- JavaScript/TypeScript browser SDK
- server-side SDK for the primary backend stack

SDK responsibilities include:

- event ID generation
- per-tab session IDs
- anonymous identity handling
- user identity attachment
- retries/idempotency support
- configurable batching, default off
- view/navigation lifecycle instrumentation
- visibility/heartbeat handling
- correlation propagation
- context metadata merging
- privacy-safe payload construction

SDKs remain thin clients; analytics semantics stay in the Telemetry service.

### 11.1 Automatic instrumentation

Browser SDK uses a hybrid model.

Automatically captured safe baseline:

- session lifecycle
- page/route/view navigation
- visibility changes
- heartbeat
- basic technical page-performance signals

Explicit instrumentation required for:

- button/action clicks
- business/domain events
- workflow events
- custom product metadata

No indiscriminate DOM scraping or automatic capture of arbitrary user-entered content.

### 11.2 Remote configuration

SDKs support product/environment-specific remote telemetry configuration, including appropriate settings such as:

- heartbeat interval
- batching behavior
- automatic navigation tracking
- enabled signal families
- metadata allow/deny policy

If remote configuration is unavailable, SDKs fall back to safe local/default settings. Remote telemetry configuration must never control source-product business behavior.

Sampling is **not** part of the first version; accepted telemetry is collected at 100% unless later architecture explicitly introduces sampling.

## 12. Ingestion and authentication

Each registered product receives one or more **product-specific ingestion credentials**.

Ingestion credentials are write-only and independently created, rotated, revoked, and disabled.

`product_id` and environment are derived/validated from trusted credential configuration rather than blindly accepted from producer payloads.

## 13. Read/query and management access

Read/query credentials are separate from ingestion credentials.

Telemetry UI uses its own local authentication and does not depend on StoX authentication.

Initial internal roles:

- **Admin** — manage products, environments, credentials, retention/configuration, users/roles
- **Analyst** — query/explore telemetry, build saved analyses/dashboards, export
- **Viewer** — read permitted analytics/dashboards/reports

Query/management APIs use API tokens/personal access tokens rather than interactive session cookies.

Token authorization is the intersection of:

- owner/service role
- explicit scopes
- permitted product set
- permitted environment set

Possible scopes include:

- `events:read`
- `analytics:read`
- `exports:create`
- `products:manage`
- `credentials:manage`

Cross-product analytics requires explicit access to every participating product/environment.

## 14. Storage architecture

The first acceptance version uses a **relational store with structured/common columns + JSON metadata**.

The database layer must be deliberately isolated so it can later be replaced independently by an analytics-oriented store such as ClickHouse.

Required architectural boundary:

```text
API / Analytics UI
        ↓
Telemetry Application Services
        ↓
Telemetry Storage Abstraction
        ↓
Relational Adapter       ← initial version
```

Later:

```text
Telemetry Storage Abstraction
        ↓
Analytics/Columnar Adapter
```

Rules:

- controllers do not query event tables directly
- analytics logic does not scatter DB-specific SQL across the application
- ingestion writes through storage interfaces
- query/aggregation uses a separate analytics/query abstraction
- DB-specific JSON/index logic stays inside adapters/repositories
- event DTO/domain models remain storage-neutral
- event IDs remain stable across migrations
- canonical events are append-only
- derived analytics can be rebuilt

Recommended logical separation:

```text
EventWriter
  append(event)
  appendBatch(events)

TelemetryQueryService
  search(...)
  aggregate(...)
  groupBy(...)
  timeSeries(...)
  sessions(...)
  funnels(...)
```

## 15. Retention

Retention uses platform defaults with optional per-product/per-environment overrides within administrator-defined limits.

Initial policy direction:

- raw telemetry: short retention, roughly 30–90 days
- aggregated analytics: longer retention, roughly 12–24 months

Exact defaults remain deployment policy rather than immutable product semantics.

Audit/business records are separate and are not governed by ordinary telemetry retention.

## 16. Derived analytics

Derived analytics are materialized for responsiveness, especially while using a relational backend.

Possible materialized data:

- session summaries
- view-duration summaries
- hourly/daily aggregates
- common metadata breakdowns
- retention/cohort summaries
- funnel summaries

Derived records are not the source of truth and must be rebuildable from the canonical event stream, subject to deletion tombstones.

## 17. Analytics query model

Telemetry exposes a product-independent **generic declarative analytics query API**.

Supported concepts include:

- product/environment scope
- signal family
- time range
- filters
- metadata-path predicates
- groupings/dimensions
- built-in aggregations
- unique counts
- time buckets
- sorting/pagination

The query language is controlled/declarative. Arbitrary SQL or storage-engine-specific expressions are not exposed.

Specialized concepts such as funnels, sessions, retention, journeys, and saved reports are higher-level definitions over the generic query engine.

User-defined computed formula language is out of scope for the first version. Built-in aggregations/rates are sufficient initially.

## 18. Saved analytics and dashboards

Saved analytics definitions are first-class persisted objects, separate from their computed/materialized results.

Examples:

- saved filters/query configurations
- reports
- breakdowns
- funnels
- retention analyses
- cohorts
- dashboard definitions

Definitions can be rerun against newer data.

Definitions are product-scoped by default but may explicitly span multiple products where compatible fields/events are deliberately selected. There is no automatic semantic metadata mapping across products in V7.

### 18.1 Built-in dashboards

Telemetry UI includes generic product-independent built-in dashboards such as:

- Overview
- Product usage
- Navigation/views
- Sessions
- Funnels
- Errors/reliability
- Latency/performance
- Operational health

### 18.2 Custom dashboards

Custom dashboards use moderate widget-based composition:

- add/remove widgets
- use saved analyses
- reorder
- resize within sensible constraints
- apply filters/date ranges
- save multiple dashboards

A full free-form dashboard design canvas is out of scope.

## 19. Explorer

Telemetry UI includes a generic explorer for ad-hoc investigation.

Capabilities include:

- product/environment filter
- signal family
- time range
- event/log/metric name
- user/session/correlation identifiers
- arbitrary metadata filters
- safe structured-field search
- sorting/grouping
- raw detail inspection
- save current query as reusable analysis/report

## 20. Raw and aggregated API access

Authorized query consumers can access both:

- raw individual events / filtered event streams
- aggregates/time-series
- sessions/views
- funnels
- journeys/path analysis
- metadata breakdowns

Raw access is separately permissioned and subject to product/environment scope and privacy policy.

The Query/Analytics APIs are a first-class external product surface, not merely backend endpoints for the Telemetry UI.

## 21. Export

Data export is first-class.

Initial export targets:

- raw events
- filtered event sets
- aggregates
- saved report results
- session/view summaries

Initial formats:

- CSV
- JSON/NDJSON

Export passes through authorization/query layers and never grants direct database access.

Scheduled report delivery is deferred from the first version.

## 22. Near-real-time behavior

Newly ingested events should become queryable quickly. Initial target is approximately **within one minute**, without a sub-second guarantee.

Session/view summaries and common aggregates should refresh frequently enough for operational/product exploration.

## 23. Deletion and privacy operations

Targeted data deletion is first-class.

Supported scopes should include:

- product
- environment
- product-scoped user ID
- anonymous identity
- session
- date/time range

Deletion uses **hard delete + minimal tombstone**.

The underlying telemetry payload is physically removed. A minimal deletion/audit record remains only for governance/rebuild safety and must not preserve deleted payload data.

Rebuild/materialization jobs must honor deletion tombstones so deleted telemetry cannot reappear.

## 24. Telemetry platform audit

Telemetry maintains its own immutable administrative/security audit trail, separate from Events/Metrics/Logs/Traces.

Audit should cover at minimum:

- authentication success/failure
- user/role changes
- product/environment configuration changes
- ingestion/query credential lifecycle
- API token lifecycle
- retention-policy changes
- deletion operations
- exports
- sensitive raw-event access
- individual-user drill-down
- important configuration changes

There is no dedicated Audit Explorer UI in the first version; audit remains accessible through backend/admin APIs.

## 25. Product management

Product administration is available through both:

- Telemetry Admin UI
- authenticated management API

Initial management capabilities:

- create/update/disable products
- manage environments
- create/rotate/revoke ingestion credentials
- create/rotate/revoke query credentials/tokens
- manage product-level telemetry configuration
- manage retention overrides

## 26. Explicitly deferred enterprise/product-expansion features

The following are intentionally not required for the first acceptance version:

- organization/workspace multi-tenancy
- customer/enterprise isolation model
- enterprise SSO/federation
- complex RBAC/ABAC beyond Admin/Analyst/Viewer + token scopes
- automatic cross-product identity resolution
- automatic metadata semantic mapping
- user-profile enrichment store
- alerting/anomaly engine
- scheduled report delivery
- custom formula/expression language
- sampling
- full dashboard design canvas
- dedicated audit explorer
- ClickHouse/other analytics-native primary DB
- broad event-schema registry/approval workflow
- feature-complete cloning of Amplitude/Grafana or another vendor

These may be layered on later without redefining the core product/event model.

## 27. StoX integration boundary

StoX is only the first client of the Telemetry product.

StoX integration should consist of:

- SDK dependency/instrumentation
- product/environment credentials/configuration
- explicit StoX-specific business events/metadata
- optional query/API consumption or authenticated deep-link/embedding later

Telemetry itself lives in a **separate repository and independently deployable application**.

StoX V6 must not implement the Telemetry platform as an internal module.

## 28. First acceptance objective

The first usable Telemetry product should prove the complete core loop:

```text
Register Product
      ↓
Create ingestion credential
      ↓
Instrument application / SDK
      ↓
Send telemetry
      ↓
Store immutable signals/events
      ↓
Track sessions + views + active/wall time
      ↓
Explore/filter/group/query
      ↓
Build basic analytics + dashboards
      ↓
Consume through UI or Query API
```

Acceptance should optimize for correctness, product independence, understandable analytics, low operational weight, and clean storage boundaries rather than enterprise feature breadth.
