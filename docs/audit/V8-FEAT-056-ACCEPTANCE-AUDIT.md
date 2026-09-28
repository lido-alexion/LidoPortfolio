# FEAT-056 ML Lifecycle Automation / Deployment / Operations — acceptance audit

Status: **REVIEW — local lifecycle implementation and tests complete; deployed worker/runtime evidence pending**

Evidence is mapped to `docs/archive/specs/V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`.

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Per-horizon bounded schedules | PASS locally | `MlLifecycleAutomationService`, persisted `stox_ml_lifecycle_schedules`, bounded Admin schedule API/UI, `config/ml_lifecycle.php`, scheduled Artisan command and schedule tests |
| Manual and drift-triggered canonical queue path | PASS locally | `MlScoringService` integration, `MlRetrainJob`, queue and drift tests are committed; manual, scheduled and drift triggers share the canonical durable run path, and only drift runs may carry a drift-check reference |
| Same-horizon concurrency | PASS locally | A seeded `stox_ml_training_horizon_locks` row is locked transactionally before the active-run check and insert, so manual, scheduled and drift requests serialize across workers; queue tests cover duplicate rejection and trigger validation |
| Durable run/progress state and SSE | PASS locally | `MlTrainingRunAdminService`, progress persistence, SSE controller and lifecycle tests; deployed worker/SSE runtime remains pending |
| Restart recovery | PASS locally | `MlTrainingRunRecoveryService` requeues stale running/cancelling runs with durable recovery evidence and test coverage |
| Bounded transient retry | PASS locally | `MlTrainingRunRetryService` persists bounded attempt/backoff and dispatches delayed retry |
| Terminal run-state vocabulary | PASS locally | Eligible runs persist `completed_eligible`, threshold failures persist `completed_rejected`, cancellation persists `cancelled`, and operational exceptions remain `failed`; lifecycle tests cover the eligible/rejected distinction |
| Cooperative cancellation | PASS locally | queued/running cancellation service and checkpoint assertions are covered; live worker cancellation remains pending |
| Explicit promotion / atomic rollback | PASS locally | existing promotion/rollback services and tests; Admin dashboard now lists retained, artifact-valid versions with explicit rollback controls; full route/UI/runtime acceptance remains |
| No automatic promotion/rollback | PASS by tests | lifecycle automation only queues training and evaluates drift; promotion remains explicit Admin action |
| Retention and stale/superseded candidates | PASS locally | retention service and promotion review tests; production archive/runtime proof remains |
| Notifications and actionable failures | PASS locally | lifecycle notification tests and existing StoX notification boundary; deployed channel validation remains |
| Admin authorization/auditability | PASS locally | Admin route group and focused authorization/lifecycle tests |
| Durable stale-run recovery | PASS locally | `MlTrainingRunRecoveryService` requeues stale running runs after worker restart, finalizes stale cancellation requests without requeueing, and is invoked at lifecycle ticks; `MlLifecycleAutomationTest` covers both paths |
| Production queue/scheduler deployment | EXTERNAL VALIDATION PENDING | VPS worker, scheduler, queue restart and notification-provider runtime have not been claimed |

The epic is **REVIEW**. Local lifecycle implementation, recovery, retention, promotion/rollback boundaries, authorization, notifications and the broad Feature suite are green. Remaining evidence is limited to deployed worker/scheduler/queue restart, live cancellation/progress/SSE, notification-channel and production archive/runtime acceptance. No deployed worker/runtime success is claimed.
