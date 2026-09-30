# V9-OPS-002 — Automated API Failure GitHub Issue Reporting

| Field | Value |
|---|---|
| **Epic** | V9-OPS-002 |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Category** | Operations / Reliability |
| **Parent register** | [`LidoPortfolio-V9-Wishlist.md`](LidoPortfolio-V9-Wishlist.md) |
| **Related V8 boundary** | V4-FEAT-052 OpenTelemetry/LidoTelemetry remains the telemetry owner; this epic adds operational GitHub issue automation and does not replace telemetry. |

## 1. Goal

Automatically create actionable GitHub issues when StoX encounters unexpected API failures, while preventing duplicate issue spam and ensuring the reporting path can never break or materially delay the business workflow that experienced the failure.

The system SHALL cover two failure domains:

1. **StoX backend -> external APIs/providers**, including HTTP failures and transport failures such as timeout, DNS, TLS and connection errors.
2. **Browser -> StoX API**, for unexpected failed application API calls that reach the frontend request/reporting layer.

The feature is an operational reliability mechanism, not a replacement for logging, OpenTelemetry, LidoTelemetry, existing provider fallback logic, user-facing error handling or V9-COMM-001 notifications.

## 2. Core product rules

1. Automatic issue reporting SHALL be enabled/disabled by configuration and SHALL be disabled by default outside production unless explicitly enabled.
2. A response is considered successful according to the operation's accepted HTTP status contract, defaulting to **any 2xx status**, not only HTTP 200.
3. Expected business/control-flow responses SHALL NOT automatically become GitHub issues merely because they are non-2xx. Endpoint/provider policy may explicitly classify statuses such as expected 401/403/404/409/422/429 responses according to operational meaning.
4. Transport failures with no HTTP response are reportable when classified as unexpected operational failures.
5. Issue creation SHALL be asynchronous and fail-open. GitHub latency, outage, authentication failure, rate limiting or queue failure must never change the original StoX API/provider result.
6. GitHub reporting SHALL never recursively report failures caused by the GitHub reporter itself.
7. Secrets, credentials, cookies, authorization headers, private tokens, full request bodies, personal data and other sensitive values SHALL never be written to GitHub issues.
8. Duplicate occurrences of the same underlying failure SHALL converge on one open GitHub issue.
9. StoX SHALL retain a local occurrence record even when GitHub is unavailable, so operators can see that reporting failed and future retries can reconcile safely.

## 3. Existing architecture to reuse

StoX already centralizes a substantial portion of Laravel outbound HTTP traffic through `App\Support\ExternalHttp::client()`. The implementation should extend the common outbound HTTP path with reporting hooks rather than adding GitHub calls independently to every provider.

Existing provider/domain services remain responsible for their current retry/fallback/business behavior. This epic observes/classifies failures; it must not silently change provider selection, retries, exception semantics, status handling or financial/domain behavior.

Frontend API calls are not universally centralized today. The implementation SHALL establish or extend a common application request/error-reporting layer for reportable browser -> StoX API failures rather than duplicating GitHub logic across React pages.

## 4. Architecture

Canonical flow:

```text
External provider/API response or transport exception
                    |
                    v
             Failure Observer
                    |
                    v
          ApiFailureClassifier
                    |
          reportable? yes/no
                    |
                    v
           ApiFailureReporter
             |           |
             |           +--> local occurrence persistence
             v
       deterministic fingerprint
             |
             v
       queued GitHubIssueJob
             |
             v
        duplicate reconciliation
         |                 |
      duplicate            new
         |                 |
 update occurrence     create GitHub issue
```

Browser-side flow:

```text
React request layer
      |
unexpected StoX API failure
      |
redacted failure envelope
      |
POST internal failure-report endpoint
      |
ApiFailureReporter
```

The browser SHALL NOT call GitHub directly and SHALL never receive the GitHub credential.

## 5. Components

### 5.1 `ApiFailureClassifier`

Responsible for deciding whether an observed failure is operationally reportable.

Input should include, where available:

- environment;
- direction (`outbound_external` or `frontend_internal`);
- service/provider/component identity;
- HTTP method;
- normalized endpoint/template;
- HTTP status;
- exception class/category;
- bounded sanitized error classification/message;
- trace/request correlation identifier;
- explicitly configured accepted/expected statuses.

The classifier SHALL support policy overrides by provider/operation without forcing every caller to reimplement reporting.

### 5.2 `ApiFailureReporter`

Responsible for:

- sanitization;
- normalization;
- deterministic fingerprint calculation;
- local occurrence upsert;
- enqueueing GitHub reporting work when required;
- rate-limit/circuit-breaker checks around issue creation;
- fail-open behavior.

This service SHALL NOT own provider retry/fallback behavior.

### 5.3 `GitHubIssueReporter`

A dedicated adapter for GitHub Issues REST API operations.

Responsibilities:

- search/reconcile duplicates;
- create issues;
- optionally add bounded occurrence comments only where policy explicitly permits;
- record GitHub issue number/URL/state locally;
- handle GitHub authentication/rate-limit/server failures without throwing back into application workflows.

GitHub API calls SHALL use their own HTTP client/configuration path excluded from API-failure observation to prevent recursive issue creation.

### 5.4 Queue job

GitHub synchronization SHALL run in a queue job. Request/provider code may persist the local occurrence synchronously if inexpensive, but shall not wait for GitHub network access.

The queue job must be idempotent for a fingerprint/occurrence generation.

## 6. Failure fingerprint and normalization

The canonical fingerprint SHALL be deterministic and based on stable failure identity, conceptually:

```text
SHA-256(
    environment
  + direction
  + component/provider
  + HTTP method
  + normalized endpoint
  + HTTP status-or-transport-category
  + normalized error class/category
)
```

Fingerprint inputs SHALL deliberately exclude volatile/high-cardinality values such as:

- timestamp;
- trace/request ID;
- user/account ID;
- stock symbol when it is merely a path/query instance value;
- pagination values;
- provider request IDs;
- access tokens;
- query signatures;
- raw exception messages containing changing identifiers.

Endpoint normalization SHALL convert instance URLs to stable route templates where practical, for example:

```text
/v8/finance/chart/SBIN.NS
```

becomes conceptually:

```text
/v8/finance/chart/{symbol}
```

If the symbol itself materially defines a distinct operational failure class, an explicit classifier policy may retain it; high-cardinality fingerprinting must not be the default.

## 7. Duplicate prevention

Duplicate prevention SHALL use two levels.

### 7.1 Local deduplication

Persist a local record keyed by fingerprint with at least:

- fingerprint;
- environment;
- direction;
- component/provider;
- method;
- normalized endpoint;
- status/transport category;
- normalized error category;
- first seen timestamp;
- last seen timestamp;
- occurrence count;
- last trace/correlation ID where safe;
- GitHub issue number/URL when known;
- last known GitHub issue state;
- GitHub sync status/error;
- created/updated timestamps.

Concurrent occurrences SHALL use an atomic unique fingerprint constraint/upsert so multiple workers cannot create independent local incidents.

### 7.2 GitHub-side reconciliation

Every generated issue SHALL contain an invisible stable marker:

```html
<!-- stox-api-failure:<fingerprint> -->
```

Before creating a new issue, the reporter SHALL search/reconcile open issues for the exact fingerprint marker. If one exists, StoX SHALL bind the local incident to that issue rather than creating another.

This protects against database loss/restoration, multiple workers, manual local-record deletion and race conditions.

Duplicate detection must not depend only on the human-readable issue title.

## 8. Closed issue recurrence policy

Closing a GitHub issue represents operator resolution of that incident generation; it does not permanently suppress future occurrences.

If the same fingerprint recurs after its associated issue is closed:

1. record the new occurrence locally;
2. apply a configurable quiet/cooldown period, default **24 hours** from issue closure or last pre-close occurrence where closure time is unavailable;
3. after the cooldown, create a **new issue generation** for the recurring incident and link/reference the prior issue in the new issue body;
4. do not reopen a closed issue automatically.

This preserves historical resolution while avoiding immediate close/reopen loops.

## 9. GitHub issue content

Generated issues SHALL be concise and operationally useful.

Recommended title pattern:

```text
[Auto][API] <component> <operation> failing with <status/category>
```

Body SHALL include only sanitized fields such as:

- automated StoX operational report notice;
- environment;
- provider/component;
- operation/method;
- normalized endpoint;
- HTTP status or transport category;
- first seen;
- last seen;
- occurrence count at issue creation;
- bounded normalized error summary;
- safe trace/correlation ID when available;
- relevant StoX component/service name;
- previous issue reference for recurrence generations;
- invisible fingerprint marker.

Default labels:

- `automated`;
- `api-failure`;
- environment label such as `production` when configured/available.

Label creation/administration is not required by this epic; if a configured label does not exist, issue creation should degrade safely rather than fail entirely.

## 10. Occurrence updates after issue creation

Repeated occurrences SHALL update the local occurrence counter and timestamps.

To avoid GitHub notification spam, StoX SHALL NOT comment on the GitHub issue for every recurrence.

Implementation may add a bounded summary comment only when a meaningful escalation threshold is crossed, for example a configurable count/time threshold, but the default product behavior is **local aggregation without repeated GitHub comments**.

## 11. Reporting policy and noise controls

The reporter SHALL support a configurable policy layer with safe defaults:

- production enabled; non-production disabled unless explicitly enabled;
- default accepted HTTP statuses: 200-299;
- provider/operation-specific expected-status overrides;
- exclusion for health probes, telemetry upload, logging upload and the GitHub reporter itself where appropriate;
- global issue-creation ceiling, default **10 newly created automated issues per hour**;
- duplicate occurrences do not consume the new-issue ceiling;
- circuit breaker/backoff when GitHub repeatedly fails;
- local recording continues even while GitHub synchronization is suppressed.

An issue-creation ceiling is a safety guard, not a reason to discard failures. Suppressed incidents remain visible in local persistence for later reconciliation.

## 12. Frontend -> StoX API failures

The browser-side scope applies only to application API failures, not arbitrary third-party browser assets.

The frontend reporting layer SHALL:

- intercept unexpected failed StoX API calls through a common request/error abstraction where possible;
- avoid reporting intentionally handled validation/business responses;
- redact query/body/header data before sending a failure envelope;
- send the envelope to an authenticated internal StoX endpoint;
- use server-side classification/fingerprinting/GitHub synchronization after ingestion;
- avoid loops by excluding the failure-report endpoint itself;
- rate-limit client submissions and server ingestion.

Anonymous/public flows may be supported only where the server can apply strict abuse controls. Initial implementation may restrict browser reports to authenticated application sessions while backend outbound failures remain fully supported.

## 13. Security and secrets

GitHub authentication SHALL use a dedicated least-privilege credential stored only on the server.

For a fine-grained personal access token, minimum intended repository permission is:

```text
Repository: lido-alexion/LidoPortfolio
Issues: Read and write
```

No Contents/Administration permission is required for runtime issue creation.

Configuration example:

```text
STOX_GITHUB_ISSUES_ENABLED=true
STOX_GITHUB_REPOSITORY=lido-alexion/LidoPortfolio
STOX_GITHUB_TOKEN=<secret>
STOX_GITHUB_ISSUE_RATE_LIMIT_PER_HOUR=10
STOX_GITHUB_RECURRENCE_COOLDOWN_HOURS=24
```

The token SHALL never be exposed to the browser, logs, telemetry, exception messages, issue bodies or Admin APIs.

Request/response sanitization SHALL be allowlist-oriented: include known safe diagnostic fields rather than attempting to blacklist every possible secret field.

## 14. Relationship with V8 FEAT-052 telemetry

V4-FEAT-052 remains the canonical OpenTelemetry/LidoTelemetry instrumentation path.

V9-OPS-002 SHALL:

- reuse trace/correlation IDs where safely available;
- optionally emit reporting lifecycle telemetry through the established FEAT-052 abstraction;
- remain fail-open if telemetry is unavailable;
- not add a second telemetry datastore/dashboard/trace system;
- not require FEAT-052 production closure before basic GitHub issue reporting works.

A telemetry event/span and a GitHub issue serve different purposes: telemetry provides observability detail; GitHub provides durable actionable engineering work tracking.

## 15. Relationship with V9-COMM-001

GitHub issue creation is not an email/notification channel and SHALL NOT be implemented through V9-COMM-001.

However, a future Admin notification about reporter degradation may reuse the shared notification framework. Such notification is optional integration and must not make GitHub issue reporting depend on COMM-001 availability.

## 16. Admin/operational visibility

A minimal Admin operational view/API SHOULD expose:

- reporter enabled/disabled state (token never exposed);
- recent local incidents;
- fingerprint;
- component/provider;
- status/category;
- first/last seen;
- occurrence count;
- linked GitHub issue when present;
- GitHub sync status/error;
- rate-limit/circuit-breaker state.

The initial epic does not require a full incident-management UI. Operators may use GitHub as the primary incident work surface.

Admin SHALL NOT be able to view secret request/response payloads because they must not be persisted by this feature in the first place.

## 17. Data retention

Local incident records SHALL be retained for **90 days after the most recent occurrence**, unless needed longer because the incident remains linked to an open GitHub issue. Open-linked records may remain until the issue is closed plus the normal retention window.

Only sanitized normalized diagnostics are retained under this epic.

## 18. Failure behavior

The reporting subsystem itself is strictly non-critical.

The following SHALL NOT fail the original StoX request/job/provider operation beyond its pre-existing behavior:

- local incident persistence failure;
- queue dispatch failure;
- GitHub search failure;
- GitHub create failure;
- GitHub authentication failure;
- GitHub rate limiting;
- label failure;
- telemetry failure.

Reporter failures must be logged/observable through existing safe mechanisms without recursively entering the same GitHub reporting path.

## 19. Testing requirements

Automated tests SHALL cover at least:

1. 2xx accepted statuses do not report by default.
2. Configured expected non-2xx statuses do not report.
3. Unexpected HTTP failure produces one local fingerprint record.
4. Repeated identical failure increments occurrence count without creating duplicate local records.
5. Concurrent duplicate occurrences converge under a unique fingerprint constraint.
6. Endpoint instance values normalize to the same fingerprint where expected.
7. Transport timeout/DNS/TLS categories fingerprint without an HTTP status.
8. Sensitive headers/query/body values are absent from persisted incident data and GitHub payloads.
9. Queue/GitHub failure does not alter original application/provider behavior.
10. Exact existing GitHub fingerprint marker prevents duplicate issue creation.
11. Reporter HTTP calls are excluded from recursive reporting.
12. New-issue hourly safety ceiling suppresses creation but preserves local incidents.
13. Closed-issue recurrence honors cooldown then creates a new generation referencing the previous issue.
14. Frontend failure envelope is sanitized, authenticated/rate-limited and deduplicated server-side.
15. Internal failure-report endpoint cannot recursively report itself.
16. Feature-disabled mode performs no GitHub calls.
17. Non-production default does not create GitHub issues unless explicitly enabled.

## 20. Acceptance criteria

V9-OPS-002 is complete when:

1. unexpected backend external-API HTTP and transport failures can enter one centralized reporting path;
2. supported browser -> StoX API failures can enter the same server-side incident model;
3. accepted/expected response statuses are policy-driven and default 2xx success is respected;
4. deterministic fingerprints prevent high-cardinality issue spam;
5. local unique/upsert protection and GitHub fingerprint-marker reconciliation jointly prevent duplicate open issues;
6. GitHub issue creation is asynchronous, idempotent and fail-open;
7. GitHub reporter failures cannot recursively generate GitHub issues;
8. issue payloads and persisted records pass secret/PII redaction tests;
9. repeated occurrences aggregate locally without per-occurrence GitHub comments;
10. closed-issue recurrence produces a new incident generation only after the configured cooldown;
11. hourly issue-creation safety limits and GitHub circuit/backoff behavior work without discarding local incidents;
12. the runtime GitHub credential has least-privilege Issues access and is server-only;
13. existing provider fallback/retry/business behavior remains unchanged;
14. FEAT-052 telemetry ownership remains intact and V9-COMM-001 is not misused as the GitHub issue transport;
15. focused and regression tests are green.

## 21. Implementation sequencing

This epic is largely additive and may be implemented while V8 production/configuration acceptance continues, subject to one important rule: **do not rewrite protected V8 provider/domain behavior merely to obtain centralized reporting**.

Recommended implementation slices:

1. incident schema + classifier + sanitizer + fingerprinting;
2. local dedupe/upsert and retention mechanics;
3. backend outbound HTTP observation integration around `ExternalHttp` and explicitly identified bypass paths;
4. isolated GitHub adapter + queue + duplicate search/create reconciliation;
5. rate limit/circuit/backoff and recurrence generations;
6. frontend common request/error reporting path + internal ingestion endpoint;
7. minimal Admin visibility;
8. full acceptance/security/regression audit.

## 22. Frozen decisions

The following product/architecture decisions are frozen by this specification:

- epic ID `V9-OPS-002`;
- centralized reporting rather than per-provider GitHub code;
- default success contract is 2xx, not only 200;
- policy-based expected non-2xx exclusion;
- HTTP and transport failures supported;
- asynchronous fail-open GitHub reporting;
- local + GitHub-marker two-level deduplication;
- deterministic low-cardinality fingerprinting;
- no direct browser -> GitHub access;
- no secrets/private payloads in issues;
- no per-occurrence GitHub comment spam;
- closed issue recurrence creates a new issue generation after 24-hour default cooldown rather than auto-reopening;
- default maximum 10 new automated GitHub issues/hour;
- production enabled by configuration, non-production disabled by default;
- 90-day local incident retention after last occurrence, extended for open-linked incidents;
- GitHub issue reporting is separate from FEAT-052 telemetry and V9-COMM-001 notifications;
- implementation is allowed during V8 closure only as an additive observer/reporting layer that preserves existing V8 behavior.

**Document state: FROZEN / IMPLEMENTATION-READY.**