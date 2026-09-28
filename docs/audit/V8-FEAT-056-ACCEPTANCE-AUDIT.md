# FEAT-056 ML Lifecycle Automation / Deployment / Operations — acceptance audit

Status: **IN PROGRESS**

Evidence is mapped to `docs/archive/specs/V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`.

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Per-horizon bounded schedules | PASS locally | `MlLifecycleAutomationService`, `config/ml_lifecycle.php`, scheduled Artisan command and schedule tests |
| Manual and drift-triggered canonical queue path | PASS locally | `MlScoringService` inherited integration plus `MlRetrainJob`, queue and drift tests; integration remains preserved as mixed WIP pending final ownership commit |
| Same-horizon concurrency | PASS locally | Active-run checks and queue tests reject duplicate queued/running/cancelling runs |
| Durable run/progress state and SSE | PASS locally | `MlTrainingRunAdminService`, progress persistence, SSE controller and lifecycle tests; deployed worker/SSE runtime remains pending |
| Restart recovery | PASS locally | `MlTrainingRunRecoveryService` requeues stale running/cancelling runs with durable recovery evidence and test coverage |
| Bounded transient retry | PASS locally | `MlTrainingRunRetryService` persists bounded attempt/backoff and dispatches delayed retry |
| Terminal run-state vocabulary | PASS locally | Eligible runs persist `completed_eligible`, threshold failures persist `completed_rejected`, cancellation persists `cancelled`, and operational exceptions remain `failed`; lifecycle tests cover the eligible/rejected distinction |
| Cooperative cancellation | PASS locally | queued/running cancellation service and checkpoint assertions are covered; live worker cancellation remains pending |
| Explicit promotion / atomic rollback | PASS locally | existing promotion/rollback services and tests; full route/UI/runtime acceptance remains |
| No automatic promotion/rollback | PASS by tests | lifecycle automation only queues training and evaluates drift; promotion remains explicit Admin action |
| Retention and stale/superseded candidates | PASS locally | retention service and promotion review tests; production archive/runtime proof remains |
| Notifications and actionable failures | PASS locally | lifecycle notification tests and existing StoX notification boundary; deployed channel validation remains |
| Admin authorization/auditability | PASS locally | Admin route group and focused authorization/lifecycle tests |
| Durable stale-run recovery | PASS locally | `MlTrainingRunRecoveryService` requeues stale running runs after worker restart, finalizes stale cancellation requests without requeueing, and is invoked at lifecycle ticks; `MlLifecycleAutomationTest` covers both paths |
| Production queue/scheduler deployment | EXTERNAL VALIDATION PENDING | VPS worker, scheduler, queue restart and notification-provider runtime have not been claimed |

The epic remains **IN PROGRESS** until deployed worker/runtime evidence and the remaining lifecycle acceptance criteria are recorded. The preserved mixed scoring integration is now committed in `2900e50`; local lifecycle recovery, quality-rejection semantics and the broad Feature suite are green. No deployed worker/runtime success is claimed.
