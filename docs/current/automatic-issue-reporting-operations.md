# Automatic issue reporting operations

Tracking: [browser coverage #265](https://github.com/lido-alexion/LidoPortfolio/issues/265), [outbound coverage #266](https://github.com/lido-alexion/LidoPortfolio/issues/266), and [production provisioning #267](https://github.com/lido-alexion/LidoPortfolio/issues/267).

Use the existing Admin **Log error triage** read-only endpoint (`GET /api/admin/log-error-triages`) for aggregate state. It separates API issue reporting and AI log triage and exposes configuration booleans and incident counts by sync status. It does not return the GitHub token, queue names, or raw API incident rows from the reporting diagnostic. It cannot prove which processes consume a queue; verify the deployed worker command and its queue list separately. The default reporting queue is `log-triage`; server-side configuration may override it.

Browser runtime events use the separate authenticated `POST /api/ops/browser-errors` endpoint. It accepts only `react_render`, `uncaught_exception`, or `unhandled_rejection`, requires no selected portfolio, and stores a fixed untrusted-observation category plus server-derived build SHA. The shared GitHub job labels these as browser observations; it does not call AI triage or claim a code bug. Existing StoX API failure ingestion remains `/api/ops/api-failures` with its original endpoint/status/sanitized-message/request-correlation contract.

Interpret the states independently:

- `enabled=false` means local configuration has disabled that reporting path. Do not enable it as a diagnostic workaround.
- `token_configured=false` means automatic GitHub publication cannot authenticate. Provision the least-privilege repository Issues token through the approved secret process; never put it in the application database or browser.
- `records_by_sync_status.pending > 0` means incidents are stored but not yet synchronized. Check the configured durable queue and confirm a worker consumes it. A worker consuming only `default` will not process jobs on `log-triage`.
- `failed` indicates a recorded report failure; use aggregate diagnostics and protected logs to investigate without exporting incident contents.
- `linked` counts incidents linked to a GitHub issue; `cooldown` can represent a recurrence suppressed by the closed-issue policy. Neither means every newer occurrence was separately published.
- `worker_configuration_status=unknown_needs_operator_verification` deliberately does not claim that a worker is running.
- AI triage additionally requires `ai_runtime.enabled`, the `ops.log_error_triage` capability to be enabled, and a configured eligible route (`path_order`). AI is optional; GitHub issue reporting does not depend on it.

The production read-only investigation associated with #267 found API reporting and log triage disabled, no GitHub token configured, 56 API incidents pending, no triage rows, and only the `notifications,default` queues consumed by the existing worker. AI runtime was disabled and the triage capability had no route. Those are provisioning/operation prerequisites, not source defects and not authorization to change production settings. Source changes alone do not qualify #267 as resolved; closure requires approved provisioning and safe deployed validation.

## Outbound HTTP observation policy

The shared Laravel HTTP response/connection events cover both direct `Http` clients and the existing `ExternalHttp` wrapper. Eligible provider host roots are explicitly listed in `api_failure_reporting.observed_provider_hosts`: NSE and BSE market/history, holiday, corporate-action and source calls; Yahoo and Alpha Vantage prices/validation; and Kite public quote/instrument routes. Configured custom fundamental feeds are eligible only when their host is explicitly approved. A provider must be reviewed before adding its host. All HTTP methods can be observed where that provider/route is eligible; the observer does not change or retry the originating request.

GitHub, AI, telemetry, internal/app hosts, unsupported hosts, and explicit opt-outs are excluded. Kite account, session, holdings, positions, orders, trades, and margin routes are excluded. Telegram is not eligible because its credential appears in the URL path. Other sensitive route segments are excluded, query strings and bodies are never retained, and only allowlisted provider route vocabulary survives in stored endpoint identity; unknown segments become `{resource}`. Expected statuses still use the existing API failure policy.

## Provider failure evidence and correlation

Known NSE operation paths and the Yahoo chart family retain fixed, reviewed operation names; unknown path segments remain redacted. New issue titles use those fixed provider/operation labels where available. Symbols, date bounds and trace IDs do not enter fingerprints, so repeat requests for different instruments still aggregate. Distinct NSE operations have distinct endpoint identities rather than one ambiguous `/api/{resource}` incident.

Recognized Yahoo chart failures may include a fixed error enum derived from a bounded seekable JSON error body. Arbitrary error codes/descriptions, HTML, headers, raw URLs, queries and bodies are never stored or published. `chart_not_found` means only that Yahoo returned that code; it does not establish delisting. Evidence extraction preserves response stream position and never changes provider retries, fallback, return values, or exception handling.

Canonical price-history calls carry an in-process server-generated correlation ID, cleared in `finally` and never sent in an HTTP header. Only the ID, fixed operation/error enum, sanitized endpoint and ordinary HTTP status reach the public incident. The existing protected provider log records the same ID with the canonical symbol, provider symbol, exchange, required dates and timezones. This lets an operator reconstruct a future request from private evidence without exporting instrument-specific inputs to GitHub. Noncanonical lookup requests have no such private context. Logging/capture failures remain fail-open and independent of each other; expected endpoint/status policies also suppress protected error evidence. Reporting policy and queue/deduplication limits remain unchanged.

Protected correlation context follows the existing provider-log retention, currently **two days** in production. Public operation/error enums and correlation IDs remain with the incident, but exact private replay inputs are not promised after log rotation. Capture the relevant protected log evidence within that retention window; a longer retention policy is a separate reviewed operational choice. The implementation does not change log thresholds, retention or permissions.

The correlation/evidence change addresses the diagnostic gap tracked in #285. It cannot reconstruct request details already lost from historical issues #272, #273 or #277, and does not by itself prove those provider failures resolved.

## Approved operator provisioning checklist

The persistent production environment is `/var/www/stoxla/shared/.env`. An authorized operator must provision a repository-limited credential with Issues read/write through the established secret workflow; never paste it into chat, commit it, or expose it in `config:show` output. The variable is `STOX_GITHUB_TOKEN`; the target is `STOX_GITHUB_REPOSITORY=lido-alexion/LidoPortfolio`. Keep the existing token/credential purpose separate from CI-read or deployment credentials. See [GitHub fine-grained permission documentation](https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens).

For API/browser reporting, after credential and consumer readiness are verified, the operator may enable `STOX_GITHUB_ISSUES_ENABLED=true` through normal configuration/cache and service operations. AI is a separate opt-in: retain disabled AI triage until the shared runtime, `ops.log_error_triage` route, provider authorization and budgets are qualified. Do not toggle either switch merely to make diagnostics appear healthy.

A reviewed optional unit is provided at `deploy/systemd/stoxla-log-triage.service`. It is **not installed or enabled by a source merge**. Its worker explicitly selects the `log-triage` connection and queue (180-second visibility, 120-second job timeout) and does not alter the notifications/default worker. A privileged operator must review the local user/path assumptions, install/enable it via normal service management and arrange controlled restart after each normal release so it loads current code/config. The optional unit stops gracefully within the bounded job/stop timeout; it must not be used to interrupt unrelated queues. Installation, process existence and an empty queue are not proof of successful GitHub delivery.

Validate one explicitly bounded, clearly marked synthetic observation through capture, queue, optional classification and create-or-link, with a matching GitHub marker/receipt. Do not inject a real app failure, call broker/exchange/notification providers, or bulk-dispatch the historical pending backlog as a canary. Preserve pending records; review any historical replay separately under the normal dedupe/rate limits. Close #267 only after the intended reporting paths have real operational evidence; record any deliberately disabled optional path honestly.
