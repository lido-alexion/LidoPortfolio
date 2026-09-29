# StoX V9 Historical Fundamentals Bootstrap Admin Operations Specification

| Field | Value |
|---|---|
| **Epic** | `V9-OPS-001` — Historical Fundamentals Bootstrap Admin Operations |
| **Version target** | V9 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Depends on** | V8 `V4-FEAT-054` historical-fundamentals bootstrap engine |

## 1. Product intent

Turn the V8 historical-fundamentals bootstrap engine into an Admin-operated, auditable operational capability without creating a parallel ingestion path. V9 adds control, scheduling, visibility, gap-management and operational recovery around the existing governed ingestion engine.

## 2. Access and authority

- Bootstrap/backfill operations are **Admin-only**.
- Normal investors may consume resulting historical data but cannot start, pause, cancel, retry, schedule or rerun ingestion.
- The UI must not allow direct manual editing of historical financial values.
- Provider/source selection remains governed by StoX's existing deterministic source-priority and quality rules; Admin does not manually override provider choice.

## 3. Run targeting

Admin may start:

- full eligible-universe backfills;
- selected-stock backfills;
- supported universe/index subsets;
- missing-only/gap-fill runs;
- bounded date/period scopes where supported;
- cadence-specific runs where supported;
- forced reruns of already-successful items.

No mandatory dry-run/preview is required before launch. Invalid or unsafe combinations must still be blocked deterministically.

## 4. Concurrency and lifecycle

- Only **one** bootstrap/backfill run may be active at a time.
- Additional runs may wait in queue.
- Active runs may be paused/resumed where the V8 engine supports safe pausing.
- Active runs may be **gracefully cancelled**: stop scheduling new work, preserve completed progress, finish/terminate in-flight work safely, and mark the run cancelled.
- Cancelled runs are terminal and are not resumed. Recovery uses a new targeted run over remaining gaps.
- Failed items from prior runs may be retried selectively in a new run.

## 5. Forced rerun and provenance

Admin may explicitly force-rerun previously successful items when mappings, source quality, derived logic, or source corrections justify it.

If a rerun produces different historical values:

- retain the prior value/provenance for audit;
- apply existing source-priority and quality rules;
- promote the rerun value only when valid under those rules;
- record when and why the active value changed.

The operations UI must never bypass governed ingestion quality rules.

## 6. Live progress and drill-down

Show both aggregate progress and drill-down detail.

At minimum:

- overall status;
- completed / failed / pending counts;
- current item being processed where meaningful;
- per-stock/per-period status;
- normalized failure reason where safe;
- source/provider provenance metadata;
- last progress/checkpoint update time.

Do not expose raw internal checkpoint/cursor structures in the UI. Do not expose raw provider payloads; normalize diagnostics instead.

## 7. Historical run history

Retain full historical run history with filtering and drill-down.

Each run must preserve an immutable configuration snapshot including:

- run ID;
- trigger type: manual / scheduled / retry / forced rerun / gap-driven;
- scope;
- date/cadence bounds;
- originating preset and/or schedule where applicable;
- preset/schedule snapshot version/configuration;
- missing-only / forced-rerun flags;
- initiating Admin or scheduler;
- scheduled time where applicable;
- started/completed timestamps;
- status and counts.

No dedicated run-to-run comparison feature is required.

## 8. Scheduling

Support a full recurring scheduler in the Admin UI.

Schedules may:

- run daily/weekly/monthly or through an equivalent recurring rule model;
- run indefinitely;
- stop on an optional end date;
- stop after an optional maximum occurrence count;
- be enabled/disabled without deletion;
- be edited while enabled, with changes applying only to future occurrences;
- be cloned;
- support **Run now** without changing the recurring schedule or next occurrence.

If a scheduled occurrence becomes due while another run is active, queue the occurrence and start it once the execution slot is available.

Queued/historical run instances preserve the configuration they were created with.

## 9. Presets

Support reusable named backfill presets.

- Presets store reusable scope/configuration, not execution history.
- Presets are shared across all Admins.
- Presets may be cloned.
- Presets may be used for manual runs or to create schedules.
- When a schedule is created from a preset, the schedule snapshots the preset configuration.
- Later preset edits do not silently alter existing schedules.
- Deleting a preset is blocked while any schedule references it.

## 10. Notifications

Use the V9 notification framework for meaningful operational outcomes, including:

- run failed;
- run completed with failures;
- scheduled occurrence could not start or was materially delayed;
- repeated provider/data-quality failures;
- optional successful completion for manually started runs.

Avoid notifying on every status transition.

## 11. Data-quality warnings

Track data-quality warnings separately from technical failures.

Examples:

- conflicting source values;
- suspicious period-over-period change;
- missing provenance;
- incomplete metric set;
- derived metric unavailable because prerequisites are missing.

A run may complete while carrying warnings.

Admin may acknowledge a warning with an optional note. Acknowledgement:

- does not alter underlying data;
- records Admin identity/time/note;
- removes the item from the unresolved-warning queue.

If the same issue appears in a later run, create a new warning occurrence; prior acknowledgement remains historical.

Bulk acknowledgement is supported with audit detail.

## 12. Coverage and gaps

Expose coverage by metric/cadence so Admin can judge whether bootstrap work is materially improving usable historical data.

Show, where supported:

- percentage of eligible stocks with coverage;
- missing periods by metric/cadence;
- unresolved gaps;
- accepted/expected-missing gaps.

Coverage gaps must have deterministic reason categories, such as:

- source unavailable;
- metric not reported;
- period not applicable;
- mapping missing;
- unsupported cadence;
- ingestion not yet attempted;
- ingestion attempted but failed;
- derivation prerequisite missing.

A coverage gap is not automatically a failure. It becomes a failure only where ingestion actually failed.

Admin may mark a gap as **accepted / expected missing** with an optional note. This:

- does not fabricate data;
- preserves the gap/reason/history;
- removes it from the unresolved-action queue;
- records who acknowledged it and when.

If StoX later determines the gap is fillable, automatically reopen it as actionable.

Support bulk acknowledgement of compatible gaps.

## 13. Gap-driven backfill

From coverage/failure views, Admin may select compatible items and launch a targeted backfill directly.

StoX should preconfigure the run using the selected stocks/periods/metrics as allowed by the underlying engine.

Bulk targeted launch is supported.

## 14. Operational health summary

The Admin operations page should include a compact top-level summary showing at least:

- active/queued run;
- last successful run;
- unresolved failures;
- unresolved data-quality warnings;
- current coverage/gap status;
- next scheduled run.

Do not expand this into a full analytics product.

## 15. Export

Admin may export operational run results, with CSV as the primary format.

Exportable data should include where applicable:

- run summary;
- failed items;
- unresolved gaps;
- per-stock/per-period status;
- warning/gap reason categories.

## 16. Implementation-level defaults

The following are implementation-level decisions and should use the recommended design unless repository constraints require otherwise:

- reuse the V8 FEAT-054 execution engine and persistence model where feasible;
- represent runs, schedules, presets, warning occurrences and gap acknowledgements with explicit durable records;
- use idempotent queue jobs and safe transactional boundaries;
- snapshot schedule/run configuration at creation time;
- preserve audit identity and timestamps for Admin actions;
- paginate/filter large run/gap datasets server-side;
- use stable run/schedule/preset identifiers;
- make scheduler processing resilient to restart and duplicate trigger delivery;
- enforce the single-active-run invariant at the backend, not only in UI;
- protect all Admin operations with existing StoX authorization.

## 17. Acceptance criteria

V9-OPS-001 is complete when:

- Admin-only manual full/targeted backfills work through the existing bootstrap engine;
- only one run is active at once and queued runs behave deterministically;
- pause/resume where supported and graceful cancel work safely;
- cancelled runs remain terminal and remaining work can be launched as a new targeted run;
- failed-item retry and forced rerun flows work without losing provenance;
- aggregate + drill-down run progress is available;
- full historical run history with immutable configuration snapshots exists;
- reusable shared presets and recurring schedules work, including clone, enable/disable, edit-future-only and Run now;
- scheduled overlap queues rather than running concurrently;
- coverage by metric/cadence is visible;
- deterministic gap reasons, accepted-missing workflow and automatic reopening are implemented;
- data-quality warnings are separated from failures and support audited acknowledgement;
- coverage/failure selections can launch targeted backfills;
- Admin notifications fire for meaningful outcomes;
- operational CSV export is available;
- no direct manual editing of historical fundamental values exists;
- provider selection remains deterministic and governed;
- raw provider payloads and raw checkpoint internals are not exposed in the normal Admin UI.

## 18. Frozen PO decisions

- Admin-only operations.
- Full universe + targeted subsets.
- No mandatory preview/dry-run.
- One active run at a time.
- Graceful cancellation.
- Cancelled runs are terminal; use new run for remaining gaps.
- Targeted retry of failed items.
- Explicit forced rerun of successful items.
- Preserve provenance and apply source/quality rules on changed rerun values.
- Aggregate + drill-down progress.
- No Admin provider override.
- Full recurring scheduler.
- Queue scheduled occurrences on overlap.
- Schedules can be enabled/disabled without deletion.
- Schedule edits affect future occurrences only.
- Admin notifications for meaningful outcomes.
- Full historical run history.
- No dedicated run comparison.
- Operational CSV export.
- No manual historical-value correction in the UI.
- Separate data-quality warnings from technical failures.
- Warning acknowledgement with optional note.
- Repeated warning occurrence reopens as new occurrence.
- Reusable named presets.
- Presets shared across Admins.
- Block preset deletion while referenced by schedules.
- Clone presets and schedules.
- Optional schedule end date or occurrence count.
- Schedule snapshots preset configuration at creation.
- Run now does not alter recurrence.
- Immutable historical run configuration snapshot is visible.
- Human-readable progress only; no raw checkpoint cursor UI.
- No raw provider payloads in UI.
- Compact operational health summary.
- Coverage by metric/cadence.
- Coverage-gap view can launch targeted backfill.
- Coverage gaps are not failures unless ingestion failed.
- Deterministic gap-reason classification.
- Admin may mark gaps accepted/expected missing.
- Accepted gaps reopen automatically if later fillable.
- Bulk acknowledgement supported.
- Bulk targeted backfill supported.
