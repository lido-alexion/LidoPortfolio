# V9-OPS-002 — Automated API Failure GitHub Issue Reporting

| Field | Value |
|---|---|
| **Epic** | V9-OPS-002 |
| **Feature** | Automated API Failure GitHub Issue Reporting |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Category** | Operations / Reliability |
| **Parent register** | [`LidoPortfolio-V9-Wishlist.md`](LidoPortfolio-V9-Wishlist.md) |
| **Primary repository** | `lido-alexion/LidoPortfolio` |
| **Related V8 boundary** | `V4-FEAT-052` OpenTelemetry / LidoTelemetry remains telemetry owner; this epic does not replace or extend LidoTelemetry storage/analytics. |

## 1. Goal

Automatically create a GitHub issue when StoX encounters a materially unexpected API failure, while preventing duplicate issues for repeated occurrences of the same underlying failure.

The feature is intended to turn recurring production API failures into actionable engineering work without requiring manual log inspection, while remaining strictly fail-open: failure of GitHub, issue reporting, deduplication or the reporting queue must never block or alter the originating StoX business workflow.

The implementation SHALL centralize failure capture and issue reporting rather than adding direct GitHub calls throughout individual providers, controllers or React pages.

## 2. Product behavior

### 2.1 What constitutes an API failure

The user-facing requirement is "create a GitHub issue when an API returns a non-success status". For correctness, StoX SHALL interpret success through an endpoint policy rather than literal `status == 200`.

Default policy:

- every HTTP `2xx` response is successful;
- every non-`2xx` response is failure-eligible;
- transport-level failures without an HTTP response are also failure-eligible, including timeout, DNS failure, connection refusal/reset and TLS negotiation/validation failure;
- an endpoint/provider MAY declare additional expected statuses when they are part of normal application behavior;
- expected business responses MUST NOT create GitHub issues merely because they are non-`2xx`.

Examples of potentially expected responses that should be policy-classified rather than blindly reported include authentication/session expiry that already drives a normal reconnect flow, validation errors caused directly by user input, intentional `404` lookup semantics, and rate-limit responses already handled as a known bounded provider condition.

The implementation must preserve the default "report unexpected failure" behavior while allowing these explicit exceptions to avoid issue noise.

### 2.2 Scope

The failure-reporting framework SHALL support both of these API directions:

1. **StoX backend -> external/provider APIs**
   - NSE/BSE/Yahoo/Alpha Vantage/Kite and future HTTP providers;
   - common outbound HTTP infrastructure should be the primary interception point where possible;
   - existing provider-specific recovery/fallback behavior remains authoritative.

2. **Browser/client -> StoX Laravel APIs**
   - unexpected StoX API responses, especially `5xx`, should be reportable without requiring every React page to implement its own GitHub logic;
   - browser code MUST never possess GitHub credentials;
   - the client may emit a bounded failure event to Laravel where server-side response/error instrumentation cannot already identify the incident;
   - duplicate frontend observations of the same backend failure must converge to the same incident fingerprint where practical.

Purely internal non-HTTP exceptions are outside this epic unless they surface through an API request/response or an explicitly supported transport-failure adapter.

## 3. Architecture

### 3.1 High-level flow

```text
API request/response or transport exception
              |
              v
      Failure observation adapter
              |
              v
        ApiFailureReporter
              |
      +-------+---------+
      |                 |
 normalize/redact   classify policy
      |                 |
      +-------+---------+
              |
              v
      deterministic fingerprint
              |
              v
        local incident store
              |
      +-------+---------+
      |                 |
 known/open         new/unknown
      |                 |
 increment          queue async job
 occurrence              |
      |                   v
      |          GitHub duplicate check
      |                   |
      |          +--------+--------+
      |          |                 |
      |      matching issue     no match
      |          |                 |
      |      link/update       create issue
      |          |                 |
      +----------+--------+--------+
                         |
                         v
                 persist issue identity
```

### 3.2 Required components

Implementation SHOULD use equivalent Laravel classes/services with these responsibilities:

- `ApiFailureObservation` — normalized immutable failure DTO/value object;
- `ApiFailurePolicy` — determines whether an observation is reportable and which statuses are expected;
- `ApiFailureFingerprint` — produces the deterministic duplicate key;
- `ApiFailureReporter` — orchestrates normalization, local dedupe and asynchronous dispatch;
- `ApiFailureIncident` persistence — durable occurrence/dedupe state;
- `CreateOrLinkGitHubIssueJob` — queued GitHub interaction;
- `GitHubIssueClient` — least-privilege GitHub REST client;
- `ApiFailureRedactor` — strips secrets/PII and bounds payloads;
- observation adapters for backend outbound HTTP, Laravel API failures and optional frontend-observed failures.

Exact class names may differ, but responsibilities MUST remain separated. GitHub-specific code must not live directly inside provider services or the common HTTP transport wrapper.

## 4. Backend outbound HTTP integration

StoX already has common outbound HTTP infrastructure through `App\Support\ExternalHttp`. V9-OPS-002 SHALL use centralized observation there, or an equivalent Laravel HTTP-client response/event hook, for broad provider coverage.

The implementation must preserve existing provider behavior. In particular:

- existing retries/fallbacks remain intact;
- the reporter observes failures but does not replace provider error handling;
- provider calls that intentionally inspect a non-`2xx` response before falling back must still work exactly as before;
- issue reporting must be asynchronous and must not add synchronous GitHub latency to provider calls.

Where an outbound call bypasses the common client, implementation SHOULD migrate it to the common observation mechanism when safe, or attach an equivalent adapter. Do not perform broad unrelated HTTP refactors merely for coverage.

## 5. StoX API / frontend integration

### 5.1 Server-side first

Laravel SHOULD capture unexpected API failures server-side where possible because the server has authoritative request route, response status, exception class, correlation/trace ID and deployment context.

A middleware/exception-response observer may emit an `ApiFailureObservation` for eligible API responses.

Normal application `4xx` responses caused by authorization, validation or expected business rules are not automatically reportable unless an endpoint policy explicitly marks them anomalous.

Unexpected `5xx` responses are reportable by default.

### 5.2 Frontend supplemental observation

Some failures may be visible only in browser/network behavior. StoX MAY introduce a common frontend API wrapper/interceptor that submits a bounded diagnostic event to a Laravel internal endpoint.

Requirements:

- never send GitHub credentials to the browser;
- never include Authorization headers, cookies, access tokens or raw sensitive payloads;
- the internal reporting endpoint must be authenticated/rate-limited as appropriate;
- the server re-normalizes and re-redacts client observations before incident processing;
- telemetry/logging/reporting failure remains fail-open;
- do not modify every page independently if a reusable request layer can provide coverage.

## 6. Deterministic duplicate detection

### 6.1 Fingerprint

Every reportable incident SHALL have a deterministic fingerprint.

The canonical logical inputs are:

```text
environment
failure_direction
provider_or_service
http_method
normalized_endpoint_or_route
http_status_or_transport_failure_class
normalized_error_class_or_category
```

The fingerprint SHALL be a stable cryptographic hash such as SHA-256 over a canonical serialized representation of these values.

### 6.2 Normalization rules

The fingerprint MUST avoid volatile/request-specific values that would fragment one incident into many issues.

Do not include directly in the fingerprint:

- timestamps;
- request IDs / trace IDs;
- authenticated user/account IDs;
- stock symbols or instrument IDs unless the endpoint failure is demonstrably instrument-specific by design;
- query-string values whose variance is normal;
- access tokens, session IDs, cookies or credentials;
- raw request/response bodies.

Normalize route parameters where practical, for example:

```text
/api/stocks/RELIANCE/history -> /api/stocks/{stock}/history
/v8/finance/chart/INFY.NS   -> /v8/finance/chart/{symbol}
```

### 6.3 Local dedupe first

StoX SHALL maintain durable local incident state before contacting GitHub.

Minimum fields:

```text
id
fingerprint
environment
failure_direction
provider_or_service
method
normalized_endpoint
status_or_failure_class
error_category
first_seen_at
last_seen_at
occurrence_count
github_issue_number nullable
github_issue_url nullable
github_issue_state nullable
last_github_checked_at nullable
last_reported_at nullable
created_at
updated_at
```

A unique database constraint on `fingerprint` (or environment + fingerprint if environment is not already part of the fingerprint) SHALL prevent race-created duplicate local incidents.

Repeated observations update `last_seen_at` and increment `occurrence_count` atomically.

### 6.4 GitHub duplicate verification

Local dedupe is necessary but not sufficient. Before creating a new GitHub issue, the queued reporter SHALL search existing GitHub issues for the exact incident marker.

Every auto-created issue SHALL contain an invisible marker:

```html
<!-- stox-api-failure:<fingerprint> -->
```

The reporter must search open issues for that marker before creation.

If a matching open issue exists:

- do not create a new issue;
- link the local incident to that issue;
- update local occurrence state;
- optional issue comments/updates are allowed only under the bounded update policy defined below.

This second check protects against database restoration/loss, concurrent workers, deployment races and manually recreated incident rows.

## 7. Closed-issue recurrence policy

A closed historical issue must not suppress a genuinely recurring incident forever.

Frozen policy:

- if the matching GitHub issue is still open, reuse it;
- if it is closed and the same failure recurs within the configured quiet period, default **24 hours** from issue closure or last pre-close occurrence where closure time is unavailable, link the recurrence locally but do not automatically create a new issue;
- if it is closed and the same failure recurs after the default **24-hour quiet period**, create a new issue for the recurrence and link it to the prior issue in the body;
- automatic reopening of a human-closed GitHub issue is NOT required.

Implementation may cache GitHub issue state but must refresh it before deciding to create a recurrence issue when local state says the prior issue is closed/unknown.

## 8. GitHub issue content

### 8.1 Title

Use a concise deterministic title such as:

```text
[Auto] Yahoo chart API returning HTTP 429
[Auto] StoX /api/portfolio/summary returning HTTP 500
[Auto] NSE API request failing with TLS error
```

Do not include user/account identifiers or sensitive request values in the title.

### 8.2 Body

The body SHALL include only sanitized operational context useful for reproduction/triage:

- automatic-report banner;
- environment;
- provider/service;
- failure direction;
- method;
- normalized endpoint/route;
- HTTP status or transport failure class;
- normalized error category/class;
- first seen timestamp;
- latest seen timestamp at issue-creation time;
- occurrence count at issue-creation time;
- StoX component/class when available;
- bounded correlation/trace ID when safe;
- sanitized bounded error excerpt;
- current deployment commit/version when readily available;
- exact fingerprint marker.

The body MUST NOT contain:

- authorization headers;
- cookies;
- API tokens/keys;
- passwords;
- session IDs;
- raw request bodies containing user data;
- raw response payloads unless explicitly redacted and bounded;
- personal portfolio/account data;
- email addresses or other unnecessary PII.

### 8.3 Labels

Default labels SHOULD be configurable and may include:

```text
automated
api-failure
production
```

If a configured label does not exist, issue creation must still succeed without that label rather than failing the entire report.

## 9. Occurrence updates and noise control

Repeated failures must not generate repeated comments for every occurrence.

Frozen policy:

- local occurrence count increments for every observation;
- do not comment on the GitHub issue for every recurrence;
- at most one automatic recurrence comment per incident per **6 hours**;
- a recurrence comment, when emitted, should summarize new occurrence count, most recent timestamp and whether the status/error category materially changed;
- no automatic comment is required if only the local count changed and there is no operational value in updating GitHub.

Global creation safeguard:

- default maximum **10 newly created automatic issues per hour** across the application;
- configurable through environment/config;
- exceeding the cap suppresses creation but continues local incident recording;
- suppressed incidents can be retried/reconciled later;
- the cap must not affect normal StoX business traffic.

## 10. Asynchronous and fail-open behavior

GitHub issue interaction SHALL run asynchronously through the existing Laravel queue/job infrastructure or equivalent background execution.

The originating request/provider workflow must not wait for GitHub.

If any of the following fail:

- local incident write;
- queue dispatch;
- GitHub DNS/network/TLS;
- GitHub authentication;
- GitHub rate limit;
- duplicate search;
- issue creation;
- label assignment;

StoX MUST preserve the original application behavior and log/report the reporting failure through ordinary local operational logging.

GitHub reporting failure must never convert an otherwise recoverable provider/API error into a more severe StoX failure.

## 11. Recursion prevention

The GitHub client used by this feature MUST NOT itself feed its own HTTP failures back into V9-OPS-002.

At least one of these equivalent protections is required:

- dedicated HTTP client explicitly marked `skip_api_failure_reporting`;
- reporter context flag propagated through the common HTTP client;
- separate transport class outside the observed provider HTTP client.

This is a hard acceptance criterion.

## 12. Security and credentials

Use a dedicated least-privilege GitHub credential.

For the current personal deployment, a fine-grained GitHub token is acceptable with:

```text
Repository: lido-alexion/LidoPortfolio
Permission: Issues -> Read and write
```

No repository content/write/admin permission is required for runtime issue creation.

Credentials SHALL exist only server-side in environment/secret configuration.

Suggested configuration:

```text
STOX_GITHUB_ISSUES_ENABLED=true
STOX_GITHUB_REPOSITORY=lido-alexion/LidoPortfolio
STOX_GITHUB_TOKEN=...
STOX_GITHUB_AUTO_ISSUE_ENVIRONMENTS=production
STOX_GITHUB_AUTO_ISSUE_MAX_NEW_PER_HOUR=10
STOX_GITHUB_AUTO_ISSUE_COMMENT_COOLDOWN_HOURS=6
STOX_GITHUB_AUTO_ISSUE_RECURRENCE_COOLDOWN_HOURS=24
```

Exact variable names are implementation choices, but equivalent configuration must exist.

The token must never appear in logs, telemetry, exception payloads, GitHub issue content, browser bundles or API responses.

## 13. Environment policy

Frozen default:

- automatic GitHub issue creation is enabled for **production only**;
- local/dev/test environments record/test incident behavior without contacting GitHub unless explicitly opted in;
- automated tests must use fake/mock GitHub clients;
- no test suite may create real GitHub issues.

## 14. Relationship to V8 FEAT-052 telemetry

V4-FEAT-052 remains responsible for OpenTelemetry instrumentation/export to LidoTelemetry.

V9-OPS-002 is an operational incident automation consumer, not a telemetry backend.

Rules:

- do not duplicate LidoTelemetry storage or dashboards;
- where a trace ID already exists, it may be attached to the sanitized incident;
- V9-OPS-002 must work even if telemetry export is disabled or unavailable;
- telemetry failure must not trigger recursive GitHub issue creation unless it independently surfaces through an eligible API failure path;
- implementing this epic must not change FEAT-052 acceptance semantics while V8 remains under closure verification.

## 15. Endpoint policy and exclusions

Provide a code-defined/configurable policy registry so known expected responses can be suppressed without scattering conditionals throughout providers.

A policy may specify:

- service/provider identifier;
- route/endpoint pattern;
- expected success statuses;
- explicitly ignored statuses/categories;
- reportable transport exceptions;
- optional severity/category metadata.

Default remains `2xx = success; unexpected non-2xx/transport failure = reportable`.

The policy registry must not be abused to suppress genuine failures merely to reduce issue volume. Each exclusion should have a clear operational reason and test coverage.

## 16. Concurrency and race handling

Multiple workers may observe the same failure simultaneously.

Required protections:

1. database unique fingerprint constraint;
2. atomic upsert/increment for incident occurrence state;
3. queued creation job idempotency keyed by fingerprint;
4. GitHub marker search immediately before creation;
5. graceful handling if another worker creates/links the issue first.

Duplicate issues created because two workers raced are considered an implementation defect.

## 17. Admin/operator visibility

No large new Admin console is required for this epic.

Minimum operational visibility:

- incident rows are queryable through database/logging/diagnostic tooling;
- implementation SHOULD expose a small Admin read-only status surface if one naturally fits existing operational UI, showing recent incident fingerprint, service, endpoint, status, occurrence count and linked GitHub issue;
- runtime enable/disable is configuration-controlled;
- disabling GitHub issue creation must not disable local incident recording unless separately configured.

A full incident-management UI is explicitly out of scope.

## 18. Testing requirements

Automated coverage SHALL include at least:

### Classification

- `200`, `201`, `202`, `204` do not create incidents;
- unexpected `400/401/404/409/429` can be classified according to policy;
- unexpected `500/502/503` are reportable by default;
- timeout/DNS/TLS failures create transport-failure incidents;
- expected policy exceptions are suppressed.

### Fingerprinting

- same logical failure with different symbols/request IDs/timestamps produces the same fingerprint;
- materially different endpoint/status/error category produces a different fingerprint;
- secrets and volatile values are excluded.

### Local dedupe

- repeated observations increment one incident row;
- concurrent observations do not create duplicate incident rows.

### GitHub dedupe

- existing open marker prevents new issue creation;
- local row can recover/link to an issue that already exists in GitHub;
- concurrent create jobs still create at most one issue;
- closed issue recurrence follows the configured 24-hour default cooldown.

### Fail-open

- GitHub timeout/auth/rate-limit failure does not alter originating workflow;
- queue failure does not alter originating workflow;
- invalid/missing labels do not block issue creation;
- GitHub client's own failure never recursively creates another incident.

### Security

- Authorization/cookie/token values never reach stored incident detail or GitHub body;
- oversized response/request snippets are bounded/redacted;
- frontend cannot access GitHub token;
- tests never call real GitHub.

### Noise controls

- new-issue hourly limit works;
- repeated failures do not produce per-occurrence comments;
- comment cooldown is respected.

## 19. Acceptance criteria

V9-OPS-002 is complete when all of the following are true:

1. Unexpected backend outbound API failures can be centrally observed without provider-by-provider GitHub logic.
2. Unexpected StoX API failures can be observed server-side, with optional centralized frontend supplementation where needed.
3. Every reportable failure receives a stable deterministic fingerprint.
4. Repeated identical failures converge to one durable local incident.
5. Before issue creation, GitHub is searched for the exact fingerprint marker.
6. An existing open matching issue prevents duplicate issue creation.
7. Closed-issue recurrence follows the frozen 24-hour default quiet-period policy.
8. GitHub interactions are asynchronous and never block the originating API/business workflow.
9. GitHub/reporting failures are fail-open.
10. Reporter HTTP failures cannot recursively report themselves.
11. No secrets, credentials, portfolio/account data or unnecessary PII are placed in GitHub issues.
12. Production-only creation is the default; tests use fakes and never create real issues.
13. Global issue-creation and issue-comment noise controls are enforced.
14. Existing provider retry/fallback semantics remain unchanged.
15. V8 FEAT-052 telemetry ownership/boundary remains unchanged.
16. Focused automated tests cover classification, fingerprinting, local/GitHub dedupe, races, recurrence, redaction, fail-open behavior and recursion prevention.

## 20. Explicitly out of scope

- replacing logging, OpenTelemetry or LidoTelemetry;
- creating GitHub issues for every application exception regardless of API context;
- automatic code fixes or pull requests;
- automatic issue closing based on recovery;
- automatic reopening of human-closed issues;
- sending full request/response bodies to GitHub;
- exposing GitHub credentials to the browser;
- general GitHub project/issue management UI inside StoX;
- using an LLM to decide whether an incident is a duplicate.

Duplicate detection is deterministic, not AI-based.

## 21. Implementation sequencing

This epic is additive operational infrastructure and may be implemented while V8 production/configuration verification continues, provided implementation does not refactor or alter protected V8 feature semantics merely to gain observation coverage.

Recommended order:

1. incident schema + fingerprint/redaction/policy services;
2. local durable dedupe and tests;
3. GitHub client + queued create/link job using fake GitHub tests;
4. backend outbound HTTP observation integration;
5. Laravel API response/exception observation;
6. optional centralized frontend supplemental observer if gaps remain;
7. production configuration/token setup;
8. controlled production smoke test using an explicitly generated safe test failure or test-mode reporter path;
9. verify dedupe by repeating the same test failure and confirming no second issue is created.

## 22. Frozen architectural decisions

The following are frozen and require no further PO decision:

- Epic ID: `V9-OPS-002`.
- Report unexpected API failures, not literal `status != 200`; default success is HTTP `2xx`.
- Support HTTP transport failures with no response.
- Centralize reporting rather than embedding GitHub calls in providers/pages.
- Use deterministic SHA-256-style incident fingerprints.
- Use both local durable dedupe and GitHub marker-based duplicate verification.
- Production-only automatic issue creation by default.
- GitHub interaction is queued/asynchronous and fail-open.
- GitHub reporter transport is excluded from its own observation path.
- Fine-grained token with Issues read/write only is sufficient for current deployment.
- Never place secrets/credentials/private portfolio data in issues.
- Maximum 10 newly created automatic issues/hour by default.
- Maximum one automatic recurrence comment per incident per 6 hours.
- Closed issue recurrence may create a new issue only after the configured 24-hour default quiet period.
- No LLM duplicate detection.

**Document state: FROZEN / IMPLEMENTATION-READY.**
