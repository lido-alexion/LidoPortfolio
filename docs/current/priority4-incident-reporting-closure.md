# Priority 4 incident reporting closure evidence

**Branch:** `fix/priority4-incidents-20261113`\
**Reviewed source:** `bdc433324e7ba73574699c216be1a3b996e4f680`\
**Status:** Source review and isolated local verification complete. Production replay and affected-account acceptance remain gated; P4 is not closed.

## Scope and evidence boundary

This record covers the guarded replay command, API incident report sanitization, reporter deduplication safeguards, queue routing, and the outstanding notification-overlay acceptance for #39. It does not close or absorb #272, #273, #277, or #283.

The supplied operations snapshot reports 56 old pending incidents, 8 `circuit_open`, 2 `cooldown`, 3 linked, and a production worker consuming `log-triage`. Treat those as supplied snapshot facts, not current state rechecked in this pass. No production query, worker inspection, GitHub request, incident dispatch, notification send, or account session was performed here. No incident/GitHub state was mutated.

## Source review findings

- `ops:reconcile-api-failure-incidents` is read-only by default. Dispatch requires `--execute`, reporting enabled for the active environment, and a configured durable queue driver. Positive limits are capped at 10; candidates must be pending, older than the requested age, and have no prior GitHub check, issue fields, or matching GitHub binding. Ordering is deterministic by last-seen time and ID.
- API incidents are normalized before persistence. The redactor removes common credential forms, URLs, email addresses, and account/user identifiers from summaries; structured data is not published as a raw payload. Endpoint normalization strips query strings and volatile identifiers. GitHub issue bodies are assembled from the normalized incident fields and exact fingerprint marker.
- `CreateOrLinkGitHubIssueJob` delegates to the existing reporter. The reporter searches the exact marker and uses its shared lock, rate ceiling, circuit, cooldown, binding history, and `creation_unknown` hold. A failed or ambiguous create is not a reason to blindly replay.
- Both initial observation and manual reconciliation route jobs through the configured reporter connection and queue. The reviewed service unit explicitly consumes `log-triage`. Source wiring is verified; live process/configuration alignment remains an operator check.

No proven in-scope source defect was found. No application code change was made.

## Local verification

Commands were run from `app/` under the repository PHPUnit `testing` configuration (`sqlite :memory:`, array cache, test environment). Queue dispatch was faked in the reconciliation test, and GitHub HTTP was faked in the reporter suite. These tests did not contact production or GitHub.

| Command | Result | Evidence |
|---|---|---|
| `php artisan test --filter=test_manual_api_incident_reconciliation_is_dry_run_bounded_and_excludes_linked_or_ambiguous_rows` | Passed, 1 test / 6 assertions | Dry run dispatches no job; execute path with limit 1 dispatches only the eligible row and excludes linked, closed-bound, ambiguous-bound, and recent rows. |
| `OTEL_SDK_DISABLED=true php artisan test --filter=V9ApiFailureReportingTest` | Passed, 21 tests / 151 assertions | Includes summary sanitization, safe endpoint handling, reporting behavior, queue failure fail-open behavior, and replay selection. |
| `OTEL_SDK_DISABLED=true php artisan test --filter=V9GitHubIssueReporterTest` | Passed, 12 tests / 55 assertions | Exact marker matching, existing binding, cooldown, rate ceiling, shared circuit, global lock, and ambiguous-create hold. |

The first test was executed once separately as a dry-run-specific check. The test itself also uses a fake bus to verify the bounded execute branch; no queued job was sent to a real worker.

### Independent follow-up review (2026-11-14)

The source was re-read independently at `bdc433324e7ba73574699c216be1a3b996e4f680`. No provably unsafe implementation defect was found, so this review makes no application-code or test changes. In particular:

- Duplicate creation is guarded by exact hidden-marker search, a shared database lock, an existing binding/direct-issue fallback, and a durable `creation_unknown` hold after ambiguous creation. The reporter tests cover exact-marker mismatch, search-index lag with a known open binding, and repeated ambiguous reconciliation.
- Admission checks the shared open circuit before contacting GitHub, counts ambiguous POST attempts against the rolling-hour limit, and applies closed-issue cooldown before recurrence creation. Tests cover the circuit and recurrence cooldown; the reporter configuration sets the maximum new issues per hour to 10.
- Persisted/published API error text is redacted before storage. The regression suite checks credential and identity redaction and prevents reporter traffic from being observed recursively. This review found no raw provider response body passed into issue construction.
- #39 has both unit coverage for selected-detail request ownership/failure handling (`app/tests/js/tos/notification-history-detail.test.jsx`) and browser coverage for bell-pane viewport bounds, selecting an item, detail persistence on reload, horizontal overflow, and detail retry (`app/tests/e2e/comm001-notifications.spec.js`). Those browser fixtures are synthetic; they do not constitute affected-account acceptance.

Additional local test evidence from this follow-up:

| Command | Result | Boundary |
|---|---|---|
| `OTEL_SDK_DISABLED=true php artisan test --filter='V9ApiFailureReportingTest|V9GitHubIssueReporterTest'` | Passed, 33 tests / 206 assertions | Local PHPUnit test configuration; GitHub calls faked. |
| `php artisan test --filter=test_manual_api_incident_reconciliation_is_dry_run_bounded_and_excludes_linked_or_ambiguous_rows` | Passed, 1 test / 6 assertions | Local test DB and fake queue. OpenTelemetry emitted local exporter connection warnings after the passing result. |
| `npx vitest run tests/js/tos/notification-history-detail.test.jsx` | Not run; command stalled attempting package resolution and was stopped | This checkout has no `app/node_modules`; network is restricted. Frontend source coverage was inspected but not freshly executed. |

The repository root is a linked worktree whose shared `.git` metadata is read-only in this environment. `git fetch origin master` could not write `FETCH_HEAD`, so latest-origin reconciliation could not be completed here. No push, merge, PR, deploy, live request, or external mutation was made. The report remains an integration handoff on this branch; it is not CI evidence or a production approval receipt.

## Bounded first-production canary approval gate

Do not run production replay until every gate below is recorded against the exact deployed SHA:

1. The source commit has passed the repository's required CI and normal release workflow; the post-deploy health check reports that exact SHA. No manual deployment is authorized by this record.
2. The incident owner and production operator approve a **single-row** canary in the change/incident record. This is explicit approval for one historical incident to create or link a GitHub issue; approval is not implied by this report.
3. Read-only production checks confirm reporting is intentionally enabled, the token is present without exposing it, the configured queue is durable, and a healthy worker consumes that exact connection/queue. Record queue health and worker command without credentials or incident payloads.
4. Review one candidate's sanitized fingerprint, direction, component, endpoint, status/category, age, and occurrence count. Verify no issue number/URL/state, no previous GitHub check, and no binding of any state. Exclude any row that is ambiguous, bound, or otherwise requires owner triage.
5. Run the command in dry-run mode with `--limit=1` and the approved age threshold. Proceed only if it selects exactly one reviewed row. The execute query re-evaluates the eligibility predicates; run it once with `--execute --limit=1` and the same threshold. Do not schedule or loop it.
6. Observe only that row through the configured queue and reporter. Within the operator's declared observation window (maximum 10 minutes), verify one terminal result: linked to one exact-marker issue, or safely held as cooldown/ambiguous/failure without duplicate creation. Capture sanitized status/count evidence and the resulting issue number only if one was created. Do not treat queue drain alone as success.
7. Stop the canary immediately if more than one row is selected/dispatched, the candidate changes, the worker route is wrong, duplicate markers/issues appear, status remains pending beyond the window, or the reporter circuit opens. Do not continue to a second row until the cause is understood and the incident owner approves a new bounded step.

### Rollback and containment

The replay is an additive queue dispatch and has no safe bulk rollback. If it misbehaves, stop further manual replay invocations, preserve incident rows and binding history, and let the existing bounded job retry/ambiguity safeguards stand. Do not delete an issue or binding, clear the circuit, manually retry an ambiguous create, or disable unrelated notification/default workers. If a source regression is demonstrated, revert through the normal reviewed release process and verify the deployed SHA; do not hot-edit production. Reconcile any already-created issue by exact marker and owner review before taking an issue action.

## #39 affected-account acceptance

The remaining acceptance is the notification bell pane and selected notification detail/reload path on the deployed release. It must be completed by the affected account owner or an operator already authorized for that account, in that person's existing session. No impersonation or credential sharing.

1. Record date/time, deployed release SHA, browser, and viewport dimensions. Use the owner's existing authenticated session.
2. From the header bell, open an already-present notification. Do not send, generate, retry, mark all read, or otherwise create notification activity for this check. Prefer an already-read item. If opening the only suitable unread item would change its state, obtain the owner's consent to that normal read action first; otherwise stop and request an appropriate existing case.
3. Verify the bell pane remains within the viewport at the affected viewport width, text wraps/readably displays, and there is no horizontal overflow. Select the existing notification and confirm the detail corresponds to that same item, with no blank or error pane.
4. Reload the same notification-history URL while staying in the authorized session. Confirm the selected detail loads again and remains tied to the same item. Close the detail and verify the page/list remains usable.
5. Record pass/fail for pane bounds, detail selection, reload persistence, and page usability. On failure, capture only sanitized error class/request correlation and viewport/SHA; exclude notification text, account identifiers, cookies, and tokens.

Close #39 only after the authorized tester records a pass against the deployed SHA, or leave it open with a reproducible sanitized failure. Local source/test coverage is not affected-account acceptance.

## Separate issue tracks

- **#272:** unresolved historical Yahoo 404; do not infer cause without validated symbol/lifecycle or reproducible request evidence.
- **#273:** unresolved historical Yahoo 400; retain as a separate provider evidence track.
- **#277:** partially addressed warm-up reporting behavior; keep separate until remaining observations are attributed to a known route or confirmed as warm-up only.
- **#283:** unresolved BSE-only history/listing/lifecycle reconciliation; missing history does not prove delisting or justify fabricated bars or retirement.

Do not close these issues based on incident replay, notification acceptance, adjacent fixes, or a linked issue marker alone.
