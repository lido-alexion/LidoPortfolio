# Execution, Broker, And Safety

## Current Behaviour

StoX separates recommendation approval from order execution. Recommendations can become pending execution, but execution remains governed by explicit mode, entitlement, broker readiness, safety state, quote policy, user action, and reconciliation.

Execution supports paper/simulation workflows and live broker workflows. Kite is the implemented live broker integration. The broker callback is guest-safe through encrypted state because Kite returns from another domain and cannot depend on the SPA session cookie.

Safety controls include emergency halt, recovery, emergency Kite disconnect, cancel-open-orders-and-disconnect, quote policy, TOTP setup/verification/recovery/disable, automated-execution entitlement, and execution safety events.

Protective orders/position protections are separate from primary broker order submission. Protective GTT-style flows must not be accidentally cancelled when the user intends only to cancel primary open orders unless the specific action says so.

Portfolio reconciliation compares StoX portfolio/order/accounting state against broker reality and stores reconciliation runs.

## Technical Contract

Key API routes:

- Orders: `/api/v1/orders`, `/api/v1/orders/{id}/execute`, `/api/v1/orders/{id}/cancel`, `/api/v1/orders/{id}/reconcile`, `/api/v1/execution/submit-selected`
- Execution mode/state: `/api/v1/execution/mode`, `/api/v1/execution/state`, `/api/v1/execution/halt`, `/api/v1/execution/recover`, `/api/v1/execution/quote-policy`
- Broker: `/api/v1/broker/status`, `/api/v1/broker/kite/login-url`, `/api/v1/broker/kite/callback`, `/api/v1/broker/kite/session`, `/api/v1/broker/kite/disconnect`, emergency disconnect routes
- Protections: `/api/v1/protections`, `/api/v1/protections/{id}`, cancel/reconcile
- TOTP: `/api/v1/totp/*`
- Admin entitlement: `/api/v1/admin/users/{user}/automated-execution-entitlement`
- Reconciliation: `/api/reconciliation`, `/api/reconciliation/{run}`
- Paper simulation: `/api/portfolios/{portfolio}/simulation`, pause/resume

Primary models include `TradingOrder`, `OrderTransaction`, `ExecutionBatch`, `ExecutionDecision`, `ExecutionSafetyEvent`, `BrokerConnection`, `PositionProtection`, `PaperExecutionEvent`, `PaperSimulationEvent`, `PortfolioReconciliationRun`, `InternalExecutionTransfer`, and `TradingRecommendation`.

Primary services include `ExecutionSafetyService`, `AutomatedExecutionEntitlementService`, `BrokerConnectionService`, `KiteBrokerGateway`, `FakeBrokerGateway`, `KiteReadinessReminderService`, `PositionProtectionService`, `ProtectionTriggerPriceResolver`, `InternalRecommendationMatcher`, `InternalTransferValuationFinalizer`, `PortfolioReconciliationService`, `PaperSimulationProcessor`, and `PaperTradeExecutor`.

## Safety Rules

- Emergency halt blocks new StoX broker orders.
- Recovery must be explicit and should record actor/context.
- Kite disconnect clears live broker connectivity but must preserve audit history.
- Automated execution requires entitlement and mode readiness.
- Quote policy must be respected before order submission.
- TOTP protects execution-sensitive operations.
- Broker reconciliation should not invent accounting state without traceable order/transaction evidence.

## Debugging Sources

- Order not submitted: check recommendation state, execution mode, entitlement, TOTP, broker connection, emergency halt, quote policy, and validation errors.
- Kite callback/session issue: check encrypted state lifetime, user binding, broker credentials, and `KiteCallbackTest`.
- Reconciliation mismatch: check broker order IDs, order transactions, recommendation execution state, internal transfer valuations, and reconciliation run payload.

## Related Docs

- [Strategy And Recommendations](./strategy-and-recommendations.md)
- [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md)
- [Notifications, Calendar, And Alerts](./notifications-calendar-alerts.md)
- [Administration, Security, And API](./administration-security-api.md)

## Historical Context

Live trading specs were originally a separate subsystem. Current code implements an additive `/api/v1` Trading OS execution surface while legacy portfolio/accounting APIs remain in place.

