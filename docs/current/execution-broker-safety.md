# Execution, Broker, And Safety

## 1. Purpose And Scope

This document is the authoritative current contract for turning an actionable StoX recommendation into a manual, simulated or live execution outcome. It owns execution intent, execution modes, broker order handling, Kite integration, protections, reconciliation, emergency controls, scheduled execution and execution evidence.

It distinguishes:

- **Required product contract** — accepted behavior StoX must provide.
- **Current implementation anchor** — the model, controller, service, command or test that currently implements or is intended to implement the behavior.

This document does not define recommendation scoring, capital-allocation policy, cash-ledger formulas, strategy ownership rules or backtest semantics. Those domains determine execution inputs and are documented in [Strategy And Recommendations](./strategy-and-recommendations.md), [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md), and [Analytics, Review, And Backtesting](./analytics-review-backtesting.md).

## 2. Execution Operating Model

The high-level path is:

`Recommendation → review/approval → pending execution → execution eligibility → internal matching where applicable → broker order → fill or partial fill → transaction/accounting update → recommendation progress/finalization → reconciliation`

Approval is not execution. An approval authorizes a recommendation to enter `pending_execution`; it does not bypass execution mode, entitlement, portfolio/account ownership, safety, calendar, broker readiness, quote, funds or reconciliation gates. Informational HOLD/WATCH recommendations do not enter an executable lifecycle.

Execution may be manual, semi-automatic, automatic, or simulated. Internal matching can fulfill opposing eligible recommendations within the same Investor/Kite account before residual broker submission. A live broker fill is then reconciled into StoX order, transaction and recommendation evidence rather than being assumed from the request alone.

Current implementation anchors: `TradingRecommendation`, `TradingOrder`, `OrderTransaction`, `ExecutionBatch`, `ExecutionDecision`, `LiveBrokerExecutionService`, `ExecutionEngine`, `RecommendationLifecycleService`, `TransactionWriteService`, and the V1 execution/recommendation controllers.

## 3. Execution Modes And Authority

| Mode | Submission authority | Unattended behavior | Required boundaries |
|---|---|---|---|
| Manual | Authenticated Investor explicitly executes the approved recommendation | Never submits automatically | Manual execution is still subject to authorization and current business validation; it does not require the live-broker automatic authority path |
| Semi-Automatic | Authenticated Investor explicitly selects approved intent for a shared account cycle | No unsolicited submission; only explicitly selected intent joins the cycle | Requires the sensitive-action/TOTP path where applicable, user/account ownership and broker readiness |
| Automatic | Scheduler coordinates eligible Automatic portfolios for an Investor/Kite account | May submit eligible approved intent without a per-order user action | Requires automated-execution entitlement, portfolio mode, broker readiness, safety/reconciliation/calendar/window gates and account scoping |
| Paper/simulation | Simulation processor or explicit simulated workflow | May progress durable simulated checkpoints; never submits to a live broker | Simulated events remain distinct from live brokerage and accounting evidence |

Manual, unapproved, foreign-user and unselected Semi-Automatic intent must never be admitted to an Automatic account cycle. Execution mode is portfolio-scoped; automated-execution entitlement is user-scoped and admin-managed. One Investor's entitlement or broker session must not authorize another user's portfolio.

Current anchors: `PortfolioProfile::execution_mode`, `ExecutionModeService`, `AutomatedExecutionEntitlementService`, `ExecutionController`, `AdminExecutionEntitlementController`, `LiveBrokerExecutionService`, and `PaperSimulationProcessor`.

## 4. Execution Readiness Gates

Before a new live broker order is submitted, StoX must check the applicable gates. The same state must be revalidated immediately before matching or broker submission so an earlier approval cannot authorize stale or foreign intent.

Required gates include:

- actionable recommendation type and execution-capable status;
- review/approval state and the applicable Manual, Semi-Automatic or Automatic authority;
- portfolio, user, Kite-account and strategy ownership boundaries;
- execution mode and automated-execution entitlement where Automatic behavior is requested;
- current stock validity and enabled, non-archived owning strategy;
- emergency halt state;
- Kite connection/session readiness and any required sensitive-action/TOTP verification;
- reconciliation state when it blocks execution;
- trade calendar, eligible session and configured primary-order window;
- quote policy and required live-quote/fallback availability;
- capital-resolved capacity, reservation and remaining target amount;
- current broker funds for residual BUY submission.

An inactive stock, disabled/archived strategy or strategy/profile ownership mismatch cancels unsubmitted intent with an audited reason and releases its reservation. An in-flight broker order remains governed by the broker order lifecycle rather than being retroactively rewritten as an unsubmitted cancellation.

Current anchors: `LiveBrokerExecutionService`, `ExecutionSafetyService`, `ExecutionGate`, `BrokerConnectionService`, `KiteBrokerGateway`, `RecommendationExecutionLifetime`, `RecommendationLendingCoordinator`, and `PortfolioReconciliationService`.

## 5. Recommendation And Execution-Intent Lifecycle

`TradingRecommendation` records the recommendation lifecycle. Relevant statuses are `pending_review`, `published` for informational recommendations, `pending_execution`, `executed`, `cancelled`, `expired`, `superseded`, `rejected`, `deferred` and `archived`. The legacy `accepted` status is retained only as a compatibility alias; approval maps to `pending_execution`.

Execution-capable lifecycle:

1. Generation creates an actionable recommendation with evidence, target/capital state and immutable execution anchor data.
2. Review approval enters `pending_execution`; rejection/defer remains non-executable.
3. Mode and readiness gates decide whether it can be manually selected, semi-automatically selected or automatically scheduled.
4. Eligible same-account opposing intent may be internally matched. Any residual progresses to a live broker order.
5. External fills reduce the remaining target gap. A partial fill does not make the recommendation complete.
6. Completion records transaction/accounting evidence and recommendation execution progress. Cancellation, expiry or supersession preserves an auditable reason and releases unconsumed reservation as applicable.
7. A materially different target for the same strategy and security supersedes prior live intent atomically, links old to new and releases the old reservation. An unchanged target does not reset the lifetime.

Mode changes reconcile unsubmitted current execution intent: changing to Manual cancels it with an audited reason; Automatic to Semi-Automatic invalidates automatic approval and returns it to review. Already submitted orders remain under the order lifecycle.

Current anchors: `TradingRecommendation`, `RecommendationLifecycleService`, `RecommendationSupersessionService`, `RecommendationExecutionLifetime`, `TradingOsRecommendationController`, and `RecommendationSupersessionServiceTest`.

## 6. Execution Window And Holiday-Aware Lifecycle

Current execution-intent rows persist an immutable IST generation anchor, first and second eligible NSE sessions, Day #2 cutoff, target amount, capital-resolved amount and remaining execution gap.

- New intent may submit only on its frozen first or second eligible NSE session.
- The primary live-order window is 09:15–15:30 IST.
- Weekends and active Trade Holidays are rejected by the same calendar gate.
- `tos:expire-execution-windows` refreshes still-future session windows, sends idempotent approaching-expiry notices and atomically expires unresolved gaps after the Day #2 cutoff.
- The generation anchor and an eligible date whose IST day has begun are immutable.
- A corrected future Trade Holiday may roll still-future Day #1/Day #2 dates; it must not rewrite the generation anchor or an already-begun eligible date.
- Expiry preserves in-flight broker order lifecycle. It does not fabricate an order cancellation or erase fill evidence.

Legacy rows may remain compatible during migration, but new behavior is defined by the above current execution-window contract.

Current anchors: `RecommendationExecutionLifetime`, `RecommendationExecutionNotificationService`, `ExpireRecommendationExecutionWindowsCommand`, `Calendar`/holiday services, and `RecommendationExecutionLifetimeTest`.

## 7. Internal Matching And Residual Execution

For eligible opposing intent in the same Investor/Kite account, StoX internally matches before external submission. Residual SELLs are submitted before residual BUYs, using oldest-first order where intent competes in the same account cycle.

Internal matching:

- writes paired zero-fee, no-cash ledger transactions;
- creates a durable provisional `InternalExecutionTransfer` record;
- records internal execution progress separately from external execution;
- does not treat a provisional internal transfer as a final external broker fill;
- waits for residual orders to become terminal before guarded finalization;
- restates both ledger sides and recommendation progress to residual weighted-average fill evidence when a residual fills;
- waits for official session close when there is no residual fill.

External fills reduce only the remaining monetary gap. Internal and external fulfillment must not be double counted, and completing one incremental broker order must not close a partially fulfilled recommendation.

Current anchors: `InternalRecommendationMatcher`, `InternalTransferValuationFinalizer`, `InternalExecutionTransfer`, `LiveBrokerExecutionService`, `ExecutionBatch`, and `OrderTransaction`.

## 8. Quantity And Funds Resolution

Execution uses the authoritative target amount, capital-resolved capacity, internal/external progress and remaining monetary gap. The next external quantity is floored to whole shares from the remaining gap using the stored reference close and is bounded by remaining capital.

Before each residual BUY placement, the Kite adapter refreshes the Investor account's current equity `available.live_balance`, falling back to Kite's documented `net` field. StoX further floors BUY quantity to those shared funds. An unavailable funds response blocks the attempt.

StoX must never assume pending SELL proceeds are available live broker cash. Internal matching and a submitted SELL do not themselves make those proceeds spendable by a subsequent BUY.

Kite's documented structured `MarginException` maps to `BROKER_INSUFFICIENT_FUNDS`. For that error only, BUY placement retries at most twice using successive whole-share floors of 95%. StoX does not parse messages and does not quantity-retry other or ambiguous broker failures.

Current anchors: `LiveBrokerExecutionService`, `KiteBrokerGateway`, `BrokerOrderRequest`, `BrokerSubmission`, `CommittedLendingExecutionAmounts`, and `LiveExecutionFeatureTest` quantity/funds cases.

## 9. Broker Order Lifecycle

`TradingOrder` is StoX's durable order record. It links profile, recommendation, security, side, quantity, price/type, submission key, execution decision, broker provider/order ID and pinned artifact evidence. Its local status includes pending/executed/cancelled; broker status includes submitted, open, partial, filled, rejected, cancelled and unknown.

Lifecycle:

1. Create a local intent/order with an idempotent submission key.
2. Submit through the broker gateway and persist broker acknowledgement/order ID where available.
3. Treat submitted, open, partial and unknown as in-flight; reconcile rather than blindly duplicate them.
4. Convert broker fills into `OrderTransaction` evidence and shared ledger/accounting writes.
5. Preserve partial fill quantity, average fill price, remaining gap and recommendation progress until final completion.
6. Record rejection/cancellation/ambiguous state without claiming execution.
7. Reconcile open orders and protections periodically; finalization uses durable order and fill evidence.

An order transaction relates a broker/order outcome to an accounting transaction. A recommendation may reference execution evidence, but a broker request alone is not accounting realization. Idempotency must protect repeated scheduler cycles, retries and reconciliation from duplicate order or cash effects.

Current anchors: `TradingOrder`, `OrderTransaction`, `ExecutionDecision`, `ExecutionEngine`, `LiveBrokerExecutionService`, `ReconcileBrokerOrdersCommand`, `KiteBrokerGateway`, and `LiveExecutionFeatureTest` lifecycle/idempotency cases.

## 10. Kite Integration

Kite is the current live broker integration. `BrokerConnection` stores the user-bound connection/session state; `BrokerConnectionService` owns connection lifecycle and `KiteBrokerGateway` translates supported order, funds and GTT operations.

Connection flow:

- StoX creates a provider login URL with encrypted, short-lived callback state bound to the initiating user and allowlisted return context.
- Kite returns through a guest-safe callback. Callback handling cannot rely only on a SPA cookie because the redirect originates from another domain.
- Callback validation rejects invalid/expired state before contacting Kite.
- Session/readiness status is exposed separately from recommendation approval.
- Disconnect clears live connectivity/credentials while preserving audit history.
- Automatic portfolios may receive at-most-once-per-date readiness reminders when Kite is unusable at the configured reminder time.

Current anchors: `BrokerController`, `BrokerConnection`, `BrokerConnectionService`, `KiteBrokerGateway`, `KiteReadinessReminderService`, `KiteCallbackTest`, and `KiteReadinessReminderTest`.

Kite order placement uses the dedicated `portfolio_broker_instruments` registry rather than mutating canonical Stock symbols. The daily `portfolio:sync-kite-instruments` command reconciles the NSE instrument master; a targeted refresh is allowed after a deterministic invalid-instrument rejection and can retry the same durable StoX submission once. Ambiguous or missing mappings block without guessing. StoX submits a regular broker order only when the current date is an equity trading session and the current time falls within the configured market open/close interval; all other times and dates are submitted as AMO. MARKET payloads use Kite `market_protection=-1`, and the persisted broker variety selects the matching cancellation endpoint.

## 11. Emergency Halt And Recovery

Emergency halt blocks new StoX live broker submissions. Recovery is explicit and records actor/context through execution safety events. Halting new submissions is distinct from cancelling existing broker orders.

- A halt does not retroactively erase submitted, open, partial or unknown broker orders; those require reconciliation and, where explicitly requested, cancellation handling.
- Emergency Kite disconnect halts and removes local broker connectivity/credential material while retaining audit history.
- Emergency cancel-open-orders-and-disconnect cancels primary open orders and disconnects. It must not unintentionally cancel protective GTT orders.
- Already submitted/in-flight orders continue through broker/reconciliation lifecycle even if a later halt blocks new submissions.

Current anchors: `ExecutionSafetyService`, `ExecutionSafetyEvent`, `ExecutionSafetyController`, `LiveBrokerExecutionService`, `KiteBrokerGateway`, and `LiveExecutionFeatureTest` halt/disconnect/cancel cases.

## 12. Position Protections And GTT

Position protections are protective target/stop instructions, not ordinary primary BUY/SELL orders. They are linked to the owned holding/profile and can be reconciled into fill evidence while preserving ownership attribution.

- A protection can represent a target or stop and uses strategy-derived target/stop prices.
- Protection lifecycle is independent of primary execution: pending, synchronizing, active, needs attention, reconciled, cancelled/rejected/unknown broker outcomes.
- At most one active protective form applies per position according to the current replacement rules; target and stop replacement must be explicit and auditable.
- Manual mode does not auto-place protection; Semi-Automatic requires explicit sensitive-action authority; Automatic may place a required stop after an eligible BUY fill.
- Protection reconciliation handles material position changes, partial GTT fills, full exits and broker ambiguity without duplicating GTTs.
- Generic emergency/open-order cleanup must leave protections intact unless the action explicitly includes protections.

Current anchors: `PositionProtection`, `PositionProtectionService`, `ProtectionTriggerPriceResolver`, `ProtectionController`, `BrokerGttRequest`, `BrokerGttSnapshot`, and `AdvancedOrdersFeatureTest`.

## 13. Reconciliation

Reconciliation compares StoX's observed portfolio/order/accounting evidence with Kite reality and records immutable `PortfolioReconciliationRun` history. It is an evidence and safety process; it must not fabricate ledger/accounting history to make a mismatch disappear.

The contract includes:

- holdings/instrument and exact-quantity comparison, including supported identifier normalization such as Kite series suffixes;
- broker funds/cash comparison and distinction between execution-blocking holding mismatches and attention-only cash residuals where supported;
- order/recommendation/fill evidence inspection;
- reconciliation of open primary orders and open protections;
- durable run payload/history, last-success preservation on failure and deduplicated discrepancy notifications;
- attention state and execution blocking for material holdings mismatches;
- verified recovery that resolves the corresponding discrepancy notification rather than silently clearing history.

Reconciliation may observe broker and StoX state, persist reconciliation evidence, update supported order/protection lifecycle facts and block unsafe new execution. It may not invent cash, holdings, transactions or fills without traceable broker/order evidence.

Current anchors: `PortfolioReconciliationService`, `PortfolioReconciliationRun`, `PortfolioReconciliationController`, `ReconcileBrokerOrdersCommand`, `portfolio:reconcile`, `V5PortfolioReconciliationFoundationTest`, and `LiveExecutionFeatureTest` reconciliation cases.

## 14. Failure And Recovery Semantics

Failure handling is state-specific, not generic retry:

| Condition | Required behavior |
|---|---|
| Broker unavailable or session expired | Block new submission, preserve pending intent/order evidence, expose readiness/reconnect path; do not fabricate a fill |
| `MarginException` / insufficient funds | Map to `BROKER_INSUFFICIENT_FUNDS`; retry BUY at most twice at successive 95% whole-share floors |
| Other rejection or ambiguous broker error | Do not quantity-retry; persist rejection/unknown evidence and reconcile before considering a duplicate action |
| Partial fill | Preserve in-flight order and remaining gap; later residual order can only use remaining target/capital/funds |
| Stale, inactive stock or invalid strategy/ownership | Cancel only unsubmitted intent with audited reason and release reservation; in-flight order remains lifecycle-governed |
| Halt during lifecycle | Block new submissions; do not imply primary or protection cancellation without an explicit cancellation action |
| Expired execution window | Atomically expire unresolved unsubmitted gap after Day #2 cutoff; preserve in-flight order lifecycle |
| Reconciliation mismatch | Persist run/attention evidence; block execution when the mismatch type is execution-blocking; do not repair accounting by inference |
| Protection broker ambiguity | Mark synchronizing/needs-attention as appropriate and avoid duplicate GTT placement |

Current anchors: `BrokerAmbiguousException`, `KiteBrokerGateway`, `ExecutionSafetyService`, `RecommendationExecutionLifetime`, `PositionProtectionService`, `PortfolioReconciliationService`, and the execution/protection/reconciliation test suites.

## 15. Critical Safety Invariants

- Approval never bypasses execution safety or broker readiness.
- No new live broker order is submitted while emergency halt is active.
- Submission revalidates authority, portfolio/user/account ownership, stock/strategy validity and current execution state immediately before matching or broker placement.
- Pending SELL proceeds are never assumed to be live broker funds for BUY sizing.
- Internal and external fulfillment are recorded separately and cannot be double counted.
- Every execution path remains attributable to recommendation, strategy, portfolio, user and broker/order evidence where applicable.
- Broker submission, retries, scheduled cycles and reconciliation are bounded and idempotent.
- A partial fill does not close an unresolved target gap.
- Protective GTT orders are distinct from ordinary primary-order cleanup.
- Reconciliation cannot fabricate accounting history.
- Simulated/paper execution must remain distinguishable from live broker/accounting evidence.

## 16. Data Model And Ownership

| Model | Current role and ownership boundary |
|---|---|
| `TradingRecommendation` | Portfolio-scoped actionable or informational recommendation; carries execution anchor, target/capital, reservation, lifecycle and artifact evidence; strategy/security ownership is part of execution validity |
| `TradingOrder` | Portfolio-scoped StoX order linked to one recommendation/security and optional broker ID; preserves local/broker lifecycle and idempotent submission identity |
| `OrderTransaction` | Durable bridge from order/fill outcome to shared accounting transaction evidence; prevents a broker request from being mistaken for a realized transaction |
| `ExecutionBatch` / `ExecutionDecision` | Account-cycle and decision evidence for coordinated selection/submission; supports audit and idempotent orchestration |
| `ExecutionSafetyEvent` | Actor/context evidence for halt, recover, quote-policy and safety-sensitive state changes |
| `BrokerConnection` | User-bound broker connection/session; not a transferable portfolio authorization token |
| `PositionProtection` | Profile/holding-owned GTT-style target/stop protection with separate broker and local state |
| `PortfolioReconciliationRun` | Immutable portfolio/broker comparison evidence and attention/blocking context |
| `InternalExecutionTransfer` | Durable provisional internal-match record, finalized only from supported residual/session evidence |
| `PaperExecutionEvent` / `PaperSimulationEvent` | Simulated execution evidence that must not be confused with live brokerage/accounting state |

User identity owns broker connection, entitlement and safety-sensitive authority. Portfolio/profile owns execution mode, holdings, recommendations, orders, protections and reconciliation scope. Strategy ownership constrains whether a recommendation can execute against a holding. Admin access may administer entitlement and operations but must not bypass Investor resource ownership boundaries.

## 17. API Contract

The additive Trading OS API is under `/api/v1`; legacy portfolio APIs remain where their feature domains require them.

| Area | Routes and ownership |
|---|---|
| Recommendation execution | pending execution and cancellation routes under `/api/v1/recommendations/*`; recommendation controller owns review/execution transition APIs |
| Orders | `GET/POST /api/v1/orders`, `POST /api/v1/orders/{id}/execute`, `/cancel`, `/reconcile`; execution controller owns local order actions |
| Mode and safety | `GET/PUT /api/v1/execution/mode`, `GET /state`, `POST /halt`, `/recover`, `PUT /quote-policy`, `POST /submit-selected`; execution/safety controllers enforce execution scopes |
| Broker/Kite | `/api/v1/broker/status`, `/kite/login-url`, guest-safe `/kite/callback`, `/kite/session`, `/kite/disconnect`, emergency disconnect and primary-open-order cancellation routes |
| Protections | `GET/POST /api/v1/protections`, `GET /{id}`, `POST /{id}/cancel`, `/reconcile` |
| TOTP and entitlement | `/api/v1/totp/*`; admin `PUT /api/v1/admin/users/{user}/automated-execution-entitlement` |
| Reconciliation | `GET /api/reconciliation`, `GET /api/reconciliation/{run}`; reconciliation controller exposes recorded runs |
| Paper execution | `/api/portfolios/{portfolio}/simulation` and pause/resume paths; simulation controller owns simulated workflow |

Sensitive API-token routes use explicit execution read/submit scopes. Controller route presence does not bypass portfolio access, role, TOTP, entitlement, safety or execution gate checks.

## 18. Services And Orchestration

Stable service ownership:

- `ExecutionSafetyService` — halt/recover, quote policy and safety event behavior.
- `AutomatedExecutionEntitlementService` — user-level authority for Automatic execution.
- `BrokerConnectionService` — Kite connection/session lifecycle.
- `KiteBrokerGateway` / `FakeBrokerGateway` — production adapter and deterministic test adapter.
- `LiveBrokerExecutionService` — account-scoped selection, gates, matching, broker submission and in-flight reconciliation orchestration.
- `InternalRecommendationMatcher` — same-account opposing intent matching.
- `InternalTransferValuationFinalizer` — guarded final valuation of provisional internal transfers.
- `RecommendationExecutionLifetime` / `RecommendationExecutionNotificationService` — session window, expiry and approaching-expiry notification behavior.
- `PositionProtectionService` / `ProtectionTriggerPriceResolver` — protective order lifecycle and target/stop price resolution.
- `PortfolioReconciliationService` — broker/StoX evidence comparison and run persistence.
- `PaperSimulationProcessor` / `PaperTradeExecutor` — durable simulated execution progression.
- `KiteReadinessReminderService` — Automatic-mode broker-readiness reminders.

Repository/query helpers may support reads, but orchestration belongs in the services/engines above rather than in frontend components or direct ledger writes.

## 19. Scheduling And Background Processing

Current Laravel scheduler behavior uses the application scheduler timezone and non-overlap guards:

- `tos:submit-automatic-orders` — every five minutes; coordinates eligible Automatic portfolios by user/account.
- `tos:reconcile-broker-orders` — every five minutes; reconciles in-flight primary orders and open protections.
- `portfolio:reconcile` — every five minutes; runs portfolio/broker reconciliation evidence checks.
- `tos:expire-execution-windows` — every minute; refreshes future windows, sends bounded expiry notices and expires due gaps.
- `tos:finalize-internal-transfer-valuations` — every five minutes; finalizes eligible provisional transfer valuations.
- `portfolio:send-kite-readiness-reminders` — scheduled readiness reminder path for unusable Automatic portfolios.
- `portfolio:process-paper-simulations` — every five minutes; advances bounded durable paper-simulation checkpoints.

Schedules must respect calendar/session eligibility, market window constraints and idempotency. A repeated scheduler tick must not duplicate a broker order, ledger effect, notification or reconciliation failure storm.

Current anchors: `app/routes/console.php`, the named command classes, `ScheduleRegistrationTest`, `DecisionPipelineScheduleTest`, and the relevant lifecycle tests.

## 20. Observability And Audit

Evidence must be sufficient to reconstruct why an execution did or did not occur. Preserve:

- recommendation generation, review, approval, cancellation, supersession and expiry evidence;
- execution batch/decision, mode and safety-event actor/context;
- broker provider/order IDs, broker status, fill quantity, weighted average price, submission key and last synchronization time;
- order transactions, shared accounting transaction linkage and internal-transfer finalization evidence;
- broker readiness, TOTP/entitlement decision and relevant validation/gate failure;
- immutable reconciliation runs, discrepancy/attention state and recovery evidence;
- protection lifecycle, broker GTT identity and needs-attention reason;
- delivery evidence for bounded readiness/expiry/discrepancy notifications;
- request correlation, system/frontend logs and operational alerts for unattended failures.

Current observability anchors include `ExecutionSafetyEvent`, `PortfolioReconciliationRun`, `SystemLogService`, `SyncLogService`, `AdminOperationalAlertService`, notification history and `/api/logs/frontend`.

## 21. Test And Verification Anchors

| Area | Meaningful test anchor | What it proves |
|---|---|---|
| Modes, entitlement and authority | `tests/Feature/Execution/LiveExecutionFeatureTest.php` | Manual default/no automatic submit, per-portfolio mode, user-scoped entitlement, TOTP and cross-user/API gate enforcement |
| Broker order lifecycle | `LiveExecutionFeatureTest` | Fill/reject/cancel/partial/ambiguous outcomes, idempotent reconciliation, in-flight blocking and reservation non-double-consumption |
| Funds and sizing | `LiveExecutionFeatureTest` | Remaining-gap target seeking, live shared-funds bound, 95% MarginException-only retry and no retry for non-margin rejection |
| Halt and emergency actions | `LiveExecutionFeatureTest` | Halt blocks new submissions, emergency disconnect removes local connectivity, primary-only emergency cancellation preserves protections |
| Quote policy | `LiveExecutionFeatureTest` | Strict live-quote block and documented fallback behavior |
| Kite callback/readiness | `KiteCallbackTest`, `KiteReadinessReminderTest` | Encrypted guest callback state, invalid-state rejection, Dashboard return target and once-per-day readiness reminders |
| Protections | `AdvancedOrdersFeatureTest` | Target/stop semantics, replacement, mode/TOTP controls, auto-stop after eligible fill, partial/full GTT handling and ambiguity non-duplication |
| Window/expiry/supersession | `tests/Unit/Execution/RecommendationExecutionLifetimeTest.php`, `RecommendationSupersessionServiceTest.php` | Two eligible sessions, holiday correction limits, in-flight preservation, idempotent warning and material-target supersession |
| Scheduler | `tests/Feature/ScheduleRegistrationTest.php` | Broker reconciliation and Automatic submission registration with non-overlap guards |
| Reconciliation | `V5PortfolioReconciliationFoundationTest` | Immutable evidence runs, exact-quantity blocking mismatch, cash-only attention, last-success preservation and notification dedupe/recovery |
| Paper execution | `V5PaperSimulationProcessorTest` | Idempotent checkpoint progression, wait-on-missing-price and partial-affordable progression without duplicate economics |

Test coverage gap — implementation audit follow-up:

- full production Kite connectivity, callback/session expiry and broker payload compatibility;
- real-account reconciliation mismatch/halt/recovery drill;
- end-to-end scheduled Automatic execution across production calendar and market hours;
- runtime verification of one-account coordination across multiple portfolios;
- complete UI discoverability/accessibility/responsive coverage for safety and protection controls;
- proof that every accepted gate remains enforced in every direct/API pathway.

## 22. Debugging Guide

| Symptom | Likely layers to inspect |
|---|---|
| Approved recommendation not submitted | Recommendation status, execution mode, entitlement, TOTP, halt, quote policy, broker readiness, calendar/window, reconciliation and `LiveBrokerExecutionService` gate result |
| Order stuck pending/open/unknown | `TradingOrder`, broker order ID/status, `OrderTransaction`, `ReconcileBrokerOrdersCommand`, broker gateway snapshot and idempotency/submission key |
| BUY unexpectedly resized or blocked | Remaining target/capital state, stored reference price, `available.live_balance`/`net`, MarginException mapping and quantity-floor logic |
| Duplicate broker order suspected | Submission key, `ExecutionBatch`/`ExecutionDecision`, in-flight status, retry path and reconciliation records; do not resubmit until state is resolved |
| Kite callback/login failure | Encrypted callback state lifetime/user binding, allowlisted return path, `BrokerConnectionService`, `KiteCallbackTest` and broker credentials |
| Execution blocked unexpectedly | Safety event/halt, mode, entitlement, TOTP, profile ownership, stock/strategy validity, calendar/window and reconciliation attention |
| Recommendation expired unexpectedly | Generation anchor, eligible session dates, calendar corrections, IST cutoff, `RecommendationExecutionLifetime` and expiry notification evidence |
| Reconciliation mismatch | `PortfolioReconciliationRun` payload, broker identifiers/ISIN mapping, exact quantities, orders/fills, cash residual classification and notification history |
| Protection missing or needs attention | `PositionProtection`, holding ownership/quantity, target/stop source, broker GTT ID/status, `PositionProtectionService` and `AdvancedOrdersFeatureTest` |
| Halt appears ineffective | `ExecutionSafetyEvent`, current safety state, direct versus in-flight order path, emergency action used and broker reconciliation history |

## 23. Implementation Alignment Notes

### Production Kite instrument evidence (2026-09)

The production VPS initially received HTTP 403 from Kite because its IPv6 egress (`2a02:4780:12:f241::1`) was not allowlisted; the corrected egress also has IPv4 `82.112.230.20`. After allowlisting, the diagnostic moved to normal HTTP 400 validation. Kite's NSE instrument master identified canonical StoX `SITINET` as `SITINET-BZ` (`instrument_token=7477761`, `exchange_token=29210`, name `SITI NETWORKS`). A direct production AMO test for one CNC BUY was accepted as order `2102434708964433920`, visible in Kite, then cancelled with final history `CANCELLED` and `filled_quantity=0`. This direct test bypassed `LiveBrokerExecutionService`; it proves connectivity and broker capability, not successful execution through the repaired normal StoX path. The latter remains a runtime verification item.

The following accepted contracts require verification under the V1-V7 implementation audit. This list does not label them defects and does not weaken the current contract:

- deployed Kite session, callback, funds and GTT behavior against real broker responses;
- account-scoped Automatic coordination across multiple portfolios and live calendar/session edges;
- reconciliation blocking/attention/halt-recovery behavior against production-like broker mismatches;
- end-to-end internal transfer finalization after residual partial/no-fill cases;
- full Emergency Halt, emergency cancellation and protection-preservation behavior in the running application;
- runtime entitlement, TOTP and ownership enforcement across all user/API paths;
- protection placement/reconciliation and mobile/desktop operator usability;
- production scheduler, queue/cron, notification and operational-alert delivery;
- final reachability of paper execution controls without confusion with live execution.

## 24. Historical Context

V1 established manual execution and ledger traceability. V4 added broker automation and advanced GTT protections. V5 added Dashboard readiness, holiday-aware two-session execution windows and portfolio reconciliation. V6 added further execution safety, trusted-token and operational controls. The current additive `/api/v1` execution surface coexists with legacy portfolio/accounting APIs; current behavior is defined by this document rather than release chronology.
