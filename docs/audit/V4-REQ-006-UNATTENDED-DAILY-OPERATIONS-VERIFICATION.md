# V4-REQ-006 — Unattended Daily Operations Verification

## 1. Finding Recap

V4-REQ-006 was previously `RUNTIME_VERIFICATION_REQUIRED` because repository evidence could not prove that the deployed scheduler, queue workers, daily pipeline, failure reporting, and recovery behavior operated together. This verification used the deployed StoX environment and the focused V4 assurance suites.

## 2. Accepted Contract

The frozen V4-FEAT-010 contract requires:

- the Daily Decision Pipeline to run unattended through Laravel `schedule:run`;
- one effective automatic pipeline run per portfolio calendar day, despite repeated scheduler invocations;
- pipeline, broker-reconciliation, and automatic-submit failures to remain visible in-app and through the existing Telegram operational-alert path;
- Laravel scheduling to be the sole production scheduler, with no separate cPanel one-shot pipeline;
- broker reconciliation and automatic submission to remain scheduled, non-overlapping operations.

The broader daily-operation evidence also requires auditable stage/run output, safe retries, queue consumption, and market-session-aware scheduling. It does not require every external provider or domain dependency to be healthy on every run; those failures must be explicit, fail safely, and remain recoverable.

## 3. Scheduled Operation Inventory

Production registration was inspected with `php artisan schedule:list`. The material inventory is:

| Operation | Cadence / timezone | Session dependency | Queue / protection | Evidence |
| --- | --- | --- | --- | --- |
| `portfolio:daily-sync` | Daily at configured time, `Asia/Kolkata` | NSE session-day gate | Synchronous command; sync-run record | `SyncRun`, alerts, logs |
| benchmark/index price sync | Daily with market sync, `Asia/Kolkata` | Market/session gate | Synchronous command | Sync/run evidence |
| `portfolio:decision-pipeline --trigger=scheduled` | Daily configured time, `Asia/Kolkata` | NSE session-day gate | `withoutOverlapping(45)` plus automatic lock and once-per-day guard | `PipelineRun`, settings guard, alerts |
| post-sync decision pipeline | After successful daily sync | Successful complete sync required | Same automatic lock/guard | Pipeline run and alert evidence |
| `tos:reconcile-broker-orders` | Every 5 minutes | Execution/calendar services | `withoutOverlapping(5)` | Reconciliation runs and alerts |
| `portfolio:reconcile` | Every 5 minutes | Execution/calendar services | `withoutOverlapping(10)` | Reconciliation runs |
| `tos:submit-automatic-orders` | Every 5 minutes | Execution safety/session gates | `withoutOverlapping(5)` | Order/execution records and alerts |
| notification reminders/delivery | Every minute/hourly plus profile slots | Profile/calendar rules | `notifications,default` worker; unique delivery jobs | Notification lifecycle records |
| replay/backtest/paper processors | Every 5 minutes | Simulation services | `withoutOverlapping(5)` | Run/checkpoint evidence |
| universe/history/fundamentals maintenance | Every minute/5 minutes/hourly | Due-window/session rules | Due gates and overlap locks where applicable | Sync runs, heartbeat, alerts |
| holiday/calendar/index/stock maintenance | Weekly, `Asia/Kolkata` | Provider/calendar rules | Scheduled commands | Sync records and provenance |
| operational alerts and cleanup | Hourly/daily | None or service-specific | Scheduled commands | `OperationalAlert` and logs |

The production schedule contained 35 registered events. Laravel `schedule:run` is the only active scheduler mechanism; no duplicate persistent scheduler was found in the production evidence previously collected under AUD-009.

## 4. Production Scheduler Evidence

- The production cron invokes Laravel `schedule:run` every minute.
- The scheduler heartbeat was current during inspection: `schedule_run_heartbeat_at = 2026-09-19T03:16:02+00:00`.
- `schedule:list` showed the daily pipeline, daily sync, reconciliation, automatic-submit, notification, calendar, maintenance, and simulation events.
- The scheduler business timezone is `Asia/Kolkata`; AUD-010 established the calendar/session behavior used by the session gates.
- The daily pipeline uses both Laravel overlap protection and a shared automatic execution lock. The per-day guard is stored in portfolio settings and is shared by scheduled and post-sync triggers.

## 5. Historical Production Execution

The read-only production window covered recent scheduler cycles and the preceding seven days:

- daily market-data sync: 4 successful runs in the window;
- stock-master sync: 1 successful run;
- recent price-history and universe maintenance cycles produced persisted `success`, `partial`, and `failed` states rather than disappearing;
- decision pipeline: 3 completed and 6 failed runs in the recent window;
- the recent failures were explicit artifact-readiness failures (`The Strategy immutable artifact binding is unavailable or blocked`) and were persisted as failed pipeline runs;
- the active `decision_pipeline_failed` critical operational alert recorded the failure and remained unresolved until recovery, rather than reporting false success;
- scheduled reconciliation attempts were persisted with their outcomes, including provider/sync failures and completed attention-required/reconciled outcomes;
- queue state was empty at inspection and failed-job count was zero.

The pipeline failure evidence is a safe failure-path result: the unattended operation did not silently proceed with an unavailable artifact binding. It is an artifact-readiness condition for the relevant audit/domain, not evidence that the scheduler stopped operating.

## 6. Idempotency, Retry, and Failure Visibility

Repository tests and production code establish:

- repeated automatic pipeline invocation is skipped after a successful automatic run for the portfolio calendar day;
- overlapping automatic invocations return safely without starting a second pipeline;
- partial multi-profile failure leaves the daily guard unset and retries only failed profiles;
- automatic submit is idempotent and does not create duplicate broker orders on a repeated command;
- daily sync only invokes the post-sync pipeline after a complete successful sync;
- pipeline, broker-reconcile, automatic-submit, daily-sync, and universe failures have persisted operational-alert keys, cooldown/deduplication behavior, and Telegram/in-app notification paths;
- sync runs retain start/end/status/failure/partial summaries;
- queued notification delivery is unique and is consumed by the production `notifications,default` worker.

The focused tests passed:

- `V4Feat010UnattendedOpsTest.php`, `ScheduleRegistrationTest.php`, `DecisionPipelineScheduleTest.php`, and `DecisionPipelineRetryVerificationTest.php`: **35 tests, 196 assertions**;
- `DailyMarketDataJobTest.php`, `SyncLogTest.php`, `AdminOperationalAlertTest.php`, and `AlertNotificationServiceTest.php`: **39 tests, 132 assertions**.

## 7. Queue, Calendar, and Safety Boundaries

AUD-009 established that the active worker consumes `notifications,default` and that the aged notification backlog was safely processed. AUD-010 established `Asia/Kolkata` session/holiday behavior. AUD-011/AUD-015 established that reconciliation, entitlement, ownership, emergency-halt, and final broker-submission safety gates are not bypassed by unattended scheduling. The schedule invokes those services; it does not create a parallel execution path around them.

## 8. Operational Limitations Recorded

The recent decision-pipeline failures remain visible and fail closed because the deployed artifact binding was unavailable or blocked. This requires separate artifact-readiness follow-up; it does not invalidate the unattended scheduling contract.

During this audit, the current runtime health helper also reported a newly created Laravel log file with `www-data:www-data 0644`, so the writable-log invariant was not green at that instant. The scheduler heartbeat and scheduled run evidence remained present. This is recorded as deployment/runtime hygiene evidence under AUD-009/AUTHR-002 boundaries, not silently treated as a successful health-check result here.

## 9. Final Assessment

`V4-REQ-006 = IMPLEMENTED`.

The deployed scheduler is live; material daily operations are registered; recent production execution is evidenced; overlap, once-per-day, retry, and duplicate protections are present; queue work is consumed; session/timezone gates are correct; failures are persisted and surfaced; and unattended execution remains behind the existing ownership and broker-safety boundaries.

No V4-specific implementation gap was found. Provider/artifact failures remain visible operational conditions rather than silent unattended-operation failures.
