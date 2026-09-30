# V9-OPS-003 — LLM Log Error Triage & GitHub Issue Reporting

| Field | Value |
|---|---|
| **Epic** | V9-OPS-003 |
| **Status** | **FROZEN / IMPLEMENTATION-READY AFTER V4-FEAT-017 CORE** |
| **Category** | Operations / Reliability / AI-assisted triage |
| **Parent register** | [`LidoPortfolio-V9-Wishlist.md`](LidoPortfolio-V9-Wishlist.md) |
| **Depends on** | `V4-FEAT-017` shared AI platform core; reuses `V9-OPS-002` GitHub issue adapter/deduplication primitives |
| **Related V8 boundary** | V4-FEAT-052 remains the OpenTelemetry/LidoTelemetry owner. This epic consumes application log/error events and does not replace telemetry or logging. |

## 1. Goal

When StoX records a genuine **ERROR-level application log event**, automatically perform bounded AI-assisted triage through the same governed StoX AI platform used by the V9 AI stories. If the AI classifies the event as a sufficiently confident **StoX code bug**, create a deduplicated GitHub issue containing sanitized technical evidence.

The system must reduce engineering detection latency without turning every operational/provider/configuration error into a GitHub bug and without creating an independent AI/provider integration outside `V4-FEAT-017`.

Canonical flow:

```text
StoX ERROR log event
      |
      v
LogErrorObserver
      |
      +--> deterministic exclusions / sampling / normalization
      |
      v
LogErrorTriageService
      |
      v
V4-FEAT-017 capability: ops.log_error_triage
      |
      v
structured AI classification
      |
      +--> expected / operational / external / configuration / uncertain
      |          -> persist triage only; no GitHub issue
      |
      +--> code_bug + confidence/evidence threshold met
                   |
                   v
            bug fingerprint
                   |
                   v
      V9-OPS-002 GitHub issue infrastructure
                   |
          duplicate reconciliation
            |             |
        existing          new
            |             |
        aggregate      create issue
```

## 2. Core product rules

1. Only application events at configured severity **ERROR or higher** enter AI triage by default. Warning/info/debug logs do not.
2. Logging itself remains synchronous and authoritative; AI triage and GitHub issue creation SHALL be asynchronous and fail-open.
3. The feature SHALL use the canonical V4-FEAT-017 AI capability/routing/governance path. It SHALL NOT call OpenAI, Claude, Gemini, Watsonx, or any other model directly from logging code.
4. A log error does **not** imply a code bug. The AI classifier must distinguish at least:
   - `code_bug`;
   - `external_dependency`;
   - `configuration_or_environment`;
   - `expected_operational_condition`;
   - `data_quality_or_input`;
   - `security_or_abuse_signal`;
   - `uncertain`.
5. Automatic GitHub issue creation occurs only for `code_bug` classifications that meet the configured confidence and evidence gates.
6. Default automatic-creation confidence threshold SHALL be **0.85**. This is configurable through Admin/runtime configuration but must not be silently lowered by implementation.
7. `uncertain` or insufficient-evidence results SHALL NOT automatically create a GitHub issue.
8. `security_or_abuse_signal` SHALL NOT be automatically posted to a normal public/shared GitHub issue because logs may contain security-sensitive context. Such events remain locally triaged and may later integrate with a dedicated security workflow.
9. Existing error handling, retries, provider fallback, user-facing behavior, logging, telemetry, financial/domain behavior and process exit semantics SHALL remain unchanged.
10. Secrets, tokens, credentials, cookies, authorization headers, private data, full user request bodies and other sensitive values SHALL never be sent to the AI provider or GitHub issue.
11. AI failure, budget exhaustion, provider outage, timeout, queue failure, GitHub failure or deduplication failure must never affect the original application workflow or the original log write.
12. The AI triage feature SHALL be separately enable/disable configurable and disabled by default outside production unless explicitly enabled.

## 3. Relationship with existing V9 architecture

### 3.1 V4-FEAT-017 — mandatory AI path

This epic SHALL register a dedicated AI capability, conceptually:

```text
ops.log_error_triage
```

The capability uses the shared V4-FEAT-017 infrastructure for:

- provider/model configuration;
- ordered routing/fallback;
- budgets and concurrency;
- structured output validation;
- tracing and inference audit;
- timeout/circuit-breaker behavior;
- prompt registry/versioning;
- prompt/response retention policy.

No independent AI SDK/provider configuration is permitted in the logging subsystem.

The capability is operational rather than user-facing. It should use a low-temperature deterministic configuration and a bounded context window suitable for classification rather than open-ended reasoning.

### 3.2 V9-OPS-002 — shared GitHub reporting primitives

V9-OPS-003 SHALL reuse, extract, or generalize the following V9-OPS-002 infrastructure rather than creating a second GitHub integration:

- `GitHubIssueReporter` adapter;
- GitHub credential/configuration;
- asynchronous queue discipline;
- GitHub API fail-open behavior;
- rate limiting/circuit breaker;
- local incident-to-GitHub binding model where compatible;
- exact marker-based duplicate reconciliation;
- redaction helpers;
- recurrence-generation handling.

OPS-003 may use a separate log-bug fingerprint/incident table or a generalized incident schema, but there must be one shared GitHub transport/adaptor implementation.

### 3.3 V4-FEAT-052 — telemetry boundary

V4-FEAT-052 remains responsible for telemetry instrumentation/export. OPS-003 may consume safe trace/correlation IDs and may emit triage lifecycle telemetry through the existing abstraction, but SHALL NOT create a second observability datastore or replace LidoTelemetry.

## 4. Error-event capture

Implementation SHOULD integrate with Laravel's logging/exception pipeline at a central point rather than modify individual catch blocks.

A normalized `LogErrorEnvelope` should include only safe/necessary fields such as:

- environment;
- timestamp;
- severity;
- logger/channel;
- exception class, if any;
- sanitized exception message;
- stable application component/service;
- normalized route/console command/job name when known;
- HTTP method and normalized route template when applicable;
- top bounded application stack frames;
- trace/request/job correlation ID where safe;
- deploy/build commit SHA/version;
- bounded sanitized structured context allowlist;
- occurrence count / recent recurrence metadata if already known.

The observer SHALL exclude the triage subsystem's own AI/GitHub/reporting/logging failures to prevent recursion.

## 5. Pre-AI deterministic filtering

AI should not be invoked for obviously non-actionable or explicitly excluded events.

Before inference, apply deterministic filters for at least:

- ERROR severity gate;
- configured ignored exception classes/messages;
- known expected operational conditions;
- health/telemetry/log-upload/reporting endpoints where appropriate;
- GitHub reporting failures;
- AI triage provider failures from the triage capability itself;
- duplicate bursts already awaiting/in-flight triage;
- malformed or context-free events that cannot safely provide useful evidence.

This layer reduces cost/noise but SHALL NOT contain broad heuristics that silently suppress unknown application exceptions merely to save model usage.

## 6. Sanitization before AI

Sanitization occurs **before** any content leaves the StoX process for the AI runtime/provider.

Use an allowlist-first model. At minimum redact/drop:

- `Authorization`, cookies and session identifiers;
- API keys/tokens/passwords/secrets;
- database connection credentials;
- signed URLs;
- raw request/response bodies unless an explicit safe structured subset exists;
- user emails, phone numbers and other direct personal identifiers unless strictly necessary and explicitly allowed (default: exclude);
- portfolio holdings/financial values that are not necessary to classify the software failure;
- full SQL bindings where they may contain user data;
- environment variables except explicit safe keys;
- file contents and uploaded documents.

Stack traces may be sent only after sanitization and SHALL be bounded to relevant application frames plus a small surrounding context.

Prompt/response logging under V4-FEAT-017 must respect the same redaction boundary.

## 7. AI classification contract

The AI capability SHALL return a strictly validated structured object, conceptually:

```json
{
  "classification": "code_bug",
  "confidence": 0.94,
  "summary": "Null access in PortfolioSummaryService when benchmark data is absent.",
  "evidence": [
    "Unhandled TypeError originates in application service code",
    "Stack frame points to PortfolioSummaryService.php"
  ],
  "suspected_component": "PortfolioSummaryService",
  "bug_kind": "null_handling",
  "actionability": "actionable",
  "safe_issue_title": "Portfolio summary crashes when benchmark data is absent"
}
```

Required classification enum:

```text
code_bug
external_dependency
configuration_or_environment
expected_operational_condition
data_quality_or_input
security_or_abuse_signal
uncertain
```

Required fields:

- `classification`;
- `confidence` from 0.0 to 1.0;
- bounded `summary`;
- bounded evidence list;
- suspected component when known;
- normalized bug kind when applicable;
- actionability (`actionable`, `not_actionable`, `insufficient_evidence`).

The model SHALL be instructed that:

- HTTP/provider outage, expired credentials, rate limits, DNS problems and missing deployment configuration are normally not StoX code bugs unless evidence shows application code mishandles them;
- an unhandled application exception, invalid state transition, invariant violation, undefined/null access, type mismatch, broken query/schema expectation or deterministic application logic failure may be a code bug;
- it must prefer `uncertain` over inventing a bug diagnosis;
- it must not infer missing facts from stack frames that are not present;
- it must never output secrets from supplied context.

Structured-output schema validation failure is treated as triage failure, not as `code_bug`.

## 8. Automatic issue gate

Automatic GitHub issue creation SHALL require all of:

1. classification exactly `code_bug`;
2. confidence >= configured threshold (default `0.85`);
3. actionability = `actionable`;
4. at least one concrete evidence item;
5. a stable suspected component or stable exception/route identity sufficient to fingerprint;
6. event is not classified/security-gated;
7. issue-creation rate/circuit-breaker limits allow creation;
8. duplicate reconciliation finds no active equivalent issue.

If any gate fails, persist the triage decision locally but create no GitHub issue.

## 9. Bug fingerprint and duplicate detection

A code-bug fingerprint SHALL be deterministic and lower-cardinality than the raw log message.

Conceptually:

```text
SHA-256(
    environment
  + suspected_component
  + normalized exception class
  + normalized top application stack frame
  + normalized route/job/command identity
  + normalized bug_kind
)
```

The implementation may refine the exact canonicalization but SHALL exclude volatile identifiers such as:

- timestamp;
- user/account ID;
- request ID/trace ID;
- stock symbol unless truly part of the bug identity;
- numeric database IDs;
- UUIDs;
- generated filenames;
- provider request IDs;
- raw changing exception values.

Generated GitHub issues SHALL include an exact invisible marker:

```html
<!-- stox-log-bug:<fingerprint> -->
```

Before creation, the shared GitHub reporter SHALL reconcile open issues by exact marker. Human-readable title similarity SHALL NOT be the primary duplicate mechanism.

If a matching open issue exists, bind/aggregate locally instead of creating another issue.

Closed recurrence follows the same generation/cooldown principle as OPS-002, defaulting to a 24-hour recurrence cooldown unless a shared configurable policy supersedes it.

## 10. Local triage persistence

Persist a sanitized triage record with at least:

- triage/event ID;
- fingerprint where available;
- first/last seen;
- occurrence count;
- environment;
- severity;
- exception class/category;
- normalized component/route/job/command;
- deploy SHA/version;
- AI capability/prompt version;
- provider/model path identifier as governed by V4-FEAT-017 visibility rules;
- structured classification;
- confidence;
- safe summary/evidence;
- triage status (`pending`, `classified`, `skipped`, `failed`);
- GitHub issue number/URL when created or reconciled;
- failure reason when inference/GitHub sync fails.

Do not persist unsanitized source log context in this feature-specific table.

Retention defaults to **90 days after the most recent occurrence**, except records linked to open GitHub issues may remain until issue closure plus the normal retention period.

## 11. Queueing, burst control and cost discipline

AI triage SHALL execute asynchronously.

To avoid paying for the same error burst repeatedly:

1. compute a deterministic pre-triage event signature using safe stable fields;
2. atomically aggregate identical/in-flight events for a configurable debounce window;
3. submit one representative sanitized envelope plus occurrence count to AI;
4. reuse the resulting classification for matching events within a bounded TTL unless the deploy SHA or material error signature changes.

Recommended defaults:

- debounce window: **5 minutes**;
- reusable triage decision TTL: **6 hours** for the same deploy SHA/signature;
- maximum concurrent operational-triage inference: bounded through V4-FEAT-017 capability concurrency;
- GitHub new-issue limit: reuse OPS-002's global automated issue ceiling unless a dedicated stricter limit is configured.

These values are implementation-configurable; the key product invariant is that high-frequency duplicate logs do not trigger one LLM call per occurrence.

## 12. GitHub issue content

Recommended title:

```text
[Auto][Bug] <safe AI-generated concise title>
```

Issue body SHALL state that the issue was generated from AI-assisted production error triage and include only sanitized evidence:

- environment;
- first/last seen;
- occurrence count at creation;
- deploy SHA/version;
- exception class;
- suspected component;
- normalized route/job/command;
- AI classification/confidence;
- concise AI summary;
- bounded evidence list;
- safe top application stack frames;
- trace/correlation ID when safe/useful;
- prior issue reference for recurrence generation;
- invisible `stox-log-bug` fingerprint marker.

Recommended labels:

```text
automated
code-bug
production
ai-triaged
```

If configured labels are absent, issue creation SHALL still proceed without them.

The GitHub issue SHALL NOT contain the full raw log event, raw prompt, raw model response, authorization data, user data or secrets.

## 13. AI uncertainty and human review

No GitHub issue is created automatically for `uncertain` triage.

The minimal Admin operational view/API SHOULD allow inspection of recent triage records by classification/confidence so an operator can identify false negatives or manually investigate uncertain events.

This epic does not require a full human labeling/training UI, but the stored final GitHub linkage and eventual operator issue closure provide later audit evidence for classifier quality.

The AI classification is advisory operational triage; it SHALL NOT mutate application code, deploy fixes, restart services, alter configuration, or take financial/domain actions.

## 14. Prompt governance

The operational classifier prompt SHALL live in the V4-FEAT-017 prompt registry and be versioned.

Prompt changes require the normal governance/audit path defined by V4-FEAT-017. The prompt SHALL explicitly include:

- classification enum definitions;
- conservative `code_bug` standard;
- preference for `uncertain` when evidence is incomplete;
- examples distinguishing code bug vs provider/configuration/expected conditions;
- no-secret-output rule;
- structured-output schema.

Production triage records SHALL retain the prompt version used so classification changes can be audited across deployments.

## 15. Failure and recursion isolation

The following failures SHALL never enter the same automatic log-triage loop:

- `ops.log_error_triage` provider/model/routing errors;
- V4-FEAT-017 budget or circuit-breaker errors generated by this capability;
- `GitHubIssueReporter` errors;
- triage queue failures generated by the triage pipeline itself;
- local triage persistence errors;
- reporter rate-limit/circuit-breaker logs.

These remain visible through normal logging/telemetry but are tagged/excluded from automatic self-triage to avoid infinite feedback loops.

If the AI platform is unavailable, errors remain logged and may be recorded locally as `triage_failed`/`pending_retry`; no GitHub bug issue is created solely because AI was unavailable.

## 16. Configuration

Conceptual configuration:

```text
STOX_LOG_AI_TRIAGE_ENABLED=true
STOX_LOG_AI_TRIAGE_MIN_LEVEL=error
STOX_LOG_AI_TRIAGE_CODE_BUG_CONFIDENCE=0.85
STOX_LOG_AI_TRIAGE_DEBOUNCE_SECONDS=300
STOX_LOG_AI_TRIAGE_DECISION_TTL_SECONDS=21600
```

Provider/model selection SHALL NOT be configured here. That remains entirely under the V4-FEAT-017 capability routing configuration for `ops.log_error_triage`.

GitHub repository/token configuration SHALL reuse the V9-OPS-002 reporter configuration rather than introduce a second token.

## 17. Admin/operational visibility

Minimal Admin status should expose:

- feature enabled/disabled;
- AI capability health/availability (without secrets);
- recent triage counts by classification;
- recent `code_bug` classifications and linked GitHub issues;
- uncertain/failed triage count;
- duplicate occurrence counts;
- current confidence threshold;
- debounce/decision-cache status;
- GitHub reporter/circuit state reused from OPS-002.

Admin must not display raw unsanitized logs through this feature.

## 18. Testing requirements

Automated tests SHALL cover at least:

1. warning/info logs do not enter triage by default;
2. ERROR logs enqueue triage without delaying/failing the original workflow;
3. excluded triage/GitHub errors cannot recursively enqueue themselves;
4. sanitizer removes tokens/cookies/auth/user/private payload fields before AI invocation;
5. structured classifier accepts only the defined enum/schema;
6. `code_bug` above threshold/actionable/evidence gates creates a GitHub reporting job;
7. `code_bug` below confidence threshold creates no issue;
8. `uncertain`, provider, configuration, expected-operational, data-quality and security classifications create no normal GitHub bug issue;
9. duplicate log bursts debounce to bounded inference calls;
10. cached triage decisions reuse classification only for compatible signature/deploy SHA;
11. deploy SHA/signature changes invalidate unsafe reuse;
12. fingerprint normalization removes request/user/UUID/numeric volatile values;
13. concurrent matching code-bug events converge to one local incident/open GitHub issue;
14. exact `stox-log-bug` marker prevents duplicate GitHub creation;
15. GitHub issue payload contains safe evidence and no raw log/prompt/model response/secrets;
16. AI provider outage/budget exhaustion/schema failure leaves original application behavior unchanged and creates no false bug issue;
17. feature-disabled mode performs no AI triage calls;
18. non-production default performs no automatic AI triage unless explicitly enabled;
19. prompt version and inference classification metadata are auditable;
20. shared OPS-002 GitHub credential/adapter is reused rather than duplicated.

## 19. Acceptance criteria

V9-OPS-003 is complete when:

1. centralized ERROR-level application events can enter a bounded asynchronous AI triage path;
2. pre-AI deterministic filtering and sanitization prevent obvious noise and sensitive-data leakage;
3. the classifier uses the shared V4-FEAT-017 capability/routing/governance infrastructure and no direct model integration exists in logging code;
4. structured classification reliably distinguishes `code_bug` from external/configuration/expected/data/security/uncertain classes;
5. automatic GitHub issue creation requires the frozen confidence/evidence/actionability gates;
6. high-frequency duplicate errors do not cause one LLM call or one GitHub issue per log occurrence;
7. deterministic bug fingerprints plus exact GitHub marker reconciliation prevent duplicate open issues;
8. OPS-002's GitHub adapter, credential, rate limiting and fail-open semantics are reused/generalized;
9. generated issues contain sufficient sanitized engineering evidence without raw logs/secrets/private data;
10. AI/GitHub/queue/persistence failure never alters original application behavior;
11. the triage pipeline cannot recursively triage its own failures;
12. FEAT-052 remains the telemetry owner and no parallel observability store is introduced;
13. prompt version, AI decision, confidence and GitHub linkage are auditable locally;
14. focused and regression tests are green.

## 20. Implementation sequencing

Unlike V9-OPS-002, this epic is **not** a green-lane feature before the shared AI platform core exists.

Implementation sequence:

1. `V9-OPS-002` shared GitHub reporting/deduplication infrastructure may be implemented independently and earlier.
2. `V4-FEAT-017` must first provide a stable operational capability registry, provider adapters/routing, structured outputs, prompt registry, budgets/concurrency and failure isolation.
3. Implement `ops.log_error_triage` as an operational capability on that shared path.
4. Add centralized log observer, sanitizer, debounce/cache, persistence and classifier orchestration.
5. Connect high-confidence `code_bug` results to the shared OPS-002 GitHub reporting infrastructure.
6. Add Admin visibility and acceptance tests.

This epic may be implemented before the user-facing AI epics (`V9-AI-001/002/003`) once the required V4-FEAT-017 core is stable. It must not bypass that dependency merely to ship earlier.

## 21. Non-goals

This epic does not:

- automatically fix code;
- create pull requests;
- deploy/restart services;
- change configuration;
- classify every warning/info event;
- replace human debugging;
- replace LidoTelemetry/OpenTelemetry;
- send raw logs to GitHub;
- expose logs to end users;
- create a second AI provider/routing subsystem;
- create a second GitHub credential/adapter;
- automatically publish security-sensitive incidents to normal GitHub issues.

**Document state: FROZEN / IMPLEMENTATION-READY AFTER V4-FEAT-017 CORE.**