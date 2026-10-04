# V9-OPS-002 Implementation Audit

**Starting master SHA:** `764ba1f9` (already included OPS-003 at `02375709` and reconciled OPS-003 acceptance at `764ba1f9`).
**Baseline:** OPS-002 implementation from `ddd626ff`.
**Specification:** [V9-OPS-002 Automated API Failure GitHub Issue Reporting](../archive/specs/V9-OPS-002-Automated-API-Failure-GitHub-Issue-Reporting-Specification.md).
**Audit date:** 2026-10-04.

## Result

The frozen OPS-002 requirements are implemented on top of the OPS-003 shared GitHub issue reporter. This audit found and fixed three integration gaps: the frontend ingestion route referenced an undefined Laravel rate limiter; successful authenticated reports were explicitly discarded; and Axios relative request paths did not match the server's canonical `/api/...` path contract. Frontend observation now sends only same-origin StoX API failures, including non-2xx HTTP responses, and the server validates, sanitizes, applies endpoint status policy, persists and asynchronously queues them fail-open.

## Acceptance map

| Frozen area | Implementation and evidence |
|---|---|
| Classification and expected responses | `ExternalHttp` observes common outbound HTTP responses and transport failures. Laravel exception rendering observes unexpected 5xx responses. The default policy treats all 2xx statuses as success; global and normalized endpoint-specific expected statuses are configurable. `V9ApiFailureReportingTest` covers 200/201/202/204, route-specific expected 404, unexpected 404, and outbound 503. |
| Frontend StoX API ingestion | The Axios interceptor accepts same-origin paths under the configured `/api` base, excludes the report endpoint and auth routes, strips query strings by submitting only the path, and reports non-2xx/transport failures. The endpoint requires authenticated API middleware, throttles at 30 requests/minute, rejects non-StoX/external URL values, and re-normalizes all accepted fields. Feature tests cover authentication, throttling, path validation and sanitized persistence. |
| Fingerprints and local incidents | SHA-256 canonical fingerprints omit query values and normalize numeric IDs and exchange symbols. The unique fingerprint key and transactional occurrence update aggregate repeats. Tests cover fingerprint stability/distinction and two-symbol occurrence aggregation. |
| Privacy and trace/request IDs | Only method, normalized path, status, bounded safe message and bounded request ID are accepted from the browser. Credential assignments, bearer values, URLs, email addresses and labeled account/user identifiers are redacted. No bodies, headers, cookies, credentials or portfolio identifiers are persisted or sent to GitHub. |
| GitHub dedupe and recurrence | OPS-002 uses `CreateOrLinkGitHubIssueJob` and the exact `<!-- stox-api-failure:<fingerprint> -->` marker with the shared `GitHubIssueReporter` / `GitHubIssueClient`. `V9GitHubIssueReporterTest` covers exact marker reconciliation, open reuse, closed cooldown and recurrence without reopen, rolling shared rate admission, shared circuit/fail-open, ambiguous POST reconciliation, lock contention, missing labels, and disabled environments. |
| Async and failure isolation | Incident persistence precedes queue dispatch; dispatch and observation exceptions are caught and locally logged. The new queue failure test confirms the incident remains recorded and no job is dispatched when async configuration is invalid. Shared reporter tests confirm GitHub errors do not escape and the reporter transport carries the self-exclusion header. |
| Retention and operator visibility | `portfolio:purge-api-failure-incidents` removes stale local rows after the configured retention period; an acceptance test verifies old rows are pruned while recent rows remain. Incident records remain queryable through the database/diagnostic tooling as required; OPS-002 adds no broad Admin UI. |
| Provider and telemetry boundaries | Observation is centralized in `ExternalHttp`; provider retry/fallback outcomes are not replaced. OPS-002 does not alter FEAT-052 telemetry ownership or make telemetry a reporting dependency. GitHub and observation work remain fail-open. |

## OPS-003 shared infrastructure reuse

No shared GitHub infrastructure was duplicated or changed. OPS-002 continues to submit its fingerprint marker, sanitized title/body, labels and occurrence timestamp through the shared reporter. Therefore the OPS-003 global creation ceiling, circuit, database lock, durable binding/reconciliation, exact marker checks, closed issue handling, ambiguous POST hold and recursion guards apply across both epics. The reconciled OPS-003 full backend and focused shared-reporter evidence remain applicable; OPS-002 regression tests were rerun on this tree.

## Validation evidence

- Focused OPS-002 plus shared reporter PHPUnit suite: **22 tests, 114 assertions passed** on isolated MySQL 8.4 database `ops002_acceptance_final` at `127.0.0.1:3314`.
- After the final global-status environment parsing adjustment, the OPS-002 feature file was rerun: **10 tests, 59 assertions passed** on the same isolated MySQL database.
- Frontend JavaScript suite: **202 node tests and 142 Vitest tests passed** on the final source tree.
- Frontend typecheck: passed.
- Production frontend build with `VITE_APP_BASE=/portfolio/build/`: passed.
- Migration portability: passed for 169 migrations.
- Static documentation/assistant corpus: passed, 53 topics.
- OpenAPI `/api/v1` check: passed, 219 operations.
- `git diff --check`: passed after the audit and register edits.
- Full backend suite was not rerun. The supplied reconciled OPS-003 tree already has full backend evidence of 2,187 passed, 2 skipped and 13,901 assertions. This task changed OPS-002 observation/ingestion behavior and tests, without modifying shared GitHub reporter code; focused MySQL acceptance plus frontend checks are the scoped final gates.

## Runtime and release notes

- Production issue creation remains disabled unless `STOX_GITHUB_ISSUES_ENABLED=true` and the environment is listed in `STOX_GITHUB_AUTO_ISSUE_ENVIRONMENTS` (default: production).
- Runtime needs the configured repository and a server-side least-privilege GitHub Issues read/write token, plus an asynchronous queue connection/worker. Browser code receives no GitHub credential.
- Configure expected endpoint statuses with `STOX_API_FAILURE_EXPECTED_ENDPOINT_STATUSES` as a JSON object keyed by normalized endpoint path; `STOX_API_FAILURE_EXPECTED_STATUSES` remains available for global exceptions.
- The 30/minute ingestion limit is per authenticated user under Laravel's standard throttle middleware. Local incident capture remains enabled independently of GitHub creation.
- This audit verifies source and automated behavior only. It does not claim production secrets are installed, a live GitHub issue was created, or production deployment occurred. Normal verified CI/CD and post-deploy SHA health checks remain the release path.
