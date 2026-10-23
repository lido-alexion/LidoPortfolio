# Historical fundamentals operations

Operator guide for the Admin controls for V9-OPS-001 historical fundamentals backfills. This is a documentation candidate; it does not claim the feature has shipped or passed CI.

## Authority and scope

Only Admins can operate these backfills. The controls run the existing governed ingestion process. They do not let an Admin edit historical financial values or select a provider. The full eligible-stock target covers the supported annual and quarterly history; stock targeting is also available. Some historical periods can remain unknown or unavailable. The UI does not narrow a stock run to a metric, cadence, or period.

There is no required preview step. Runs show aggregate state and counts, progress time, and stock-level annual and quarterly results, normalized reasons, source provenance, periods, and fact counts. Raw provider responses and internal checkpoints are not displayed.

## Run, retry, force, and cancel

Only one run executes at a time. A new request made while a run is active may wait in the queue. If the server reports a real conflict, the UI reports that no success was confirmed and refreshes state.

Failed items can be retried from a prior run. Retry creates a new run and preserves the original run and its results. A forced rerun requires explicit selected stocks and a reason, and may revisit successful targets. It remains subject to the existing source and quality rules.

Cancel a queued or running run with a reason. Cancellation is graceful: new stock work stops while completed work remains recorded. The cancelled run is terminal and cannot resume; launch a new targeted run for remaining work. The global pause control in Fundamental settings stops new work from being claimed; work already completed remains recorded. An occurrence shown as **Could not start** is a durable outcome to review. A transient delivery failure remains pending and can be retried. Older work is not implicitly resumed by opening this page.

If a write times out or its response is uncertain, check refreshed server state before retrying. The UI retains the same request identity for a safe retry, and does not automatically replay the mutation. A repeated action must not be treated as successful until its server receipt or resulting state is visible.

## Coverage and review

Coverage is shown by metric and cadence where available, with eligible-stock counts and applicability. Applicability can be unknown. A coverage gap is not automatically an ingestion failure; its reason describes what is known, such as an unavailable source, an unreported metric, an unsupported cadence, or an attempted ingestion failure.

Select compatible gaps to mark them expected missing, optionally adding a review note. This records an Admin acknowledgement without creating data. The acknowledgement history shows who reviewed the gap, when, and the note. Compatible actionable gaps can also launch a targeted stock backfill. The selection targets stocks; it does not restrict execution to the displayed metric, cadence, or period. Warnings are separate from gaps and can be acknowledged with audit detail.

## Presets and recurring schedules

Presets store reusable targets. When a schedule is created from a preset, it uses that saved configuration; later preset edits do not silently change the schedule. A schedule can also be configured directly. It starts disabled and supports daily, weekly, or monthly recurrence, interval, local wall-clock time, and an explicit timezone. An optional end date or occurrence limit can bound the schedule.

Enable or disable, clone, and edit a schedule as separate actions. Edits apply to future occurrences. **Run now** creates one run from the saved schedule configuration and leaves recurrence and the next occurrence unchanged. When due work cannot start, its occurrence remains visible for review; a transient delivery failure remains retryable. Schedule history is paginated and shows local time, timezone, status, linked run, and delivery state.

## Run history, CSV, and notifications

Run, job detail, gap, warning, preset, schedule, and occurrence lists are paginated. The run status filter applies to runs. CSV item status is a separate filter and controls which job rows are exported for the selected run; it does not change the selected run or its status. Use the export link on that run's row.

The existing Admin notification center reports meaningful outcomes such as failures, completed runs with failures, and materially delayed scheduled work. It does not send a notification for every status transition. The compact summary reports active or queued work, unresolved failures and warnings, coverage, and the next scheduled run when available.

## E2E acceptance annotations

The accompanying browser acceptance file uses Playwright annotations of type `ops001`, with the following epic acceptance IDs. The existing `journeyId` helper is reserved for IDs in the canonical investor-journey catalog; this operations suite does not extend or claim coverage of that catalog:

- `OPS001-01`: full and targeted run controls, explicit forced rerun, uncertain-write recovery, failed-item retry, and cancellation conflict followed by terminal cancellation.
- `OPS001-02`: compatible gap acknowledgement with note/audit history and a targeted run from an unresolved gap.
- `OPS001-03`: preset and disabled schedule creation, clone/enable, idempotent Run now recovery, paginated occurrence history, and run-specific CSV filtering.
