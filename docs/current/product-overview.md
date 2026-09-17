# Product Overview

## 1. Product Purpose

StoX is a portfolio and trading operating system for Indian equity investing. It connects durable portfolio accounting, cached market data, discovery, deterministic strategies, recommendations, capital resolution, execution, analytics, notifications, knowledge, and administration into one governed product.

## 2. Core Product Model

| Scope | Owns |
| --- | --- |
| User | Identity, sessions/tokens, broker connection, TOTP, and automated-execution entitlement. |
| Portfolio profile | Holdings, ledger/cash, strategies, recommendations, notes/wiki, and most trading/analytical state. |
| Global/Admin | Security master, data quality, sync, system registries, and operational administration. |

Active portfolio is an authorization/business boundary, not only a UI selection. User-bound broker and execution security state is deliberately distinct from profile-owned investment state.

## 3. Investor Vs Admin

Investors own their investment resources. Admin operates global/system resources. Admin authority does not grant ownership of Investor portfolios, holdings, cash, strategies, recommendations, notes, or broker connection. See [Administration, Security, And API](./administration-security-api.md).

## 4. Canonical Decision-To-Execution Lifecycle

`market data -> discovery -> eligibility/evaluation -> strategy policy -> recommendation -> capital status -> review -> pending execution -> internal/broker execution -> fills -> accounting -> reconciliation -> analytics`

HOLD/WATCH are informational. Approval is not broker submission. Funding state does not rewrite an investment opinion. Execution cannot fabricate accounting state, and analytics cannot rewrite ledger truth.

## 5. Market/Data Dependency

StoX uses cached normalized market data, freshness and quality gates, and immutable dataset-attribution evidence. Historical analytics must not use future data. See [Market Data And Data Quality](./market-data-and-data-quality.md).

## 6. Strategy Model

Multiple enabled strategies may run concurrently per portfolio. Strategy ownership and exact strategy/version provenance remain explicit. Deterministic policy is authoritative; fundamentals/ML may enrich it but cannot silently override it. See [Strategy And Recommendations](./strategy-and-recommendations.md).

## 7. Capital And Accounting Model

Transactions are financial truth; holdings/snapshots are derived. One physical cash pool supports explicit reservations, capital resolution, lending, recall, bridge loans, and pending proceeds. Pending SELL proceeds are not automatically available broker cash. See [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md).

## 8. Execution Safety Model

Manual, semi-automatic, automatic, and paper modes remain constrained by user-bound broker identity, entitlement, TOTP, safety gates, windows, emergency halt, protections, and reconciliation. See [Execution, Broker, And Safety](./execution-broker-safety.md).

## 9. Analytics And Simulation Boundaries

Live accounting, historical backtests, replay, paper simulation, and read-only analytics are distinct evidence domains. Simulations do not create live broker or ledger state. See [Analytics, Review, And Backtesting](./analytics-review-backtesting.md).

## 10. Notification Boundary

**Notification delivery state never becomes business-domain state.** Delivery/read/failure does not alter recommendation, order, accounting, or alert-condition truth. See [Notifications, Calendar, And Alerts](./notifications-calendar-alerts.md).

## 11. Knowledge And Documentation Boundary

Notes/wiki are profile/user-owned; public wiki sharing is a narrow page capability. `docs/current` is the accepted engineering contract, while served help is generated product content. See [Knowledge And Documentation](./knowledge-and-documentation.md).

## 12. High-Level Architecture

Laravel provides APIs, persistence, scheduler/queues, integrations, and SPA fallback. React provides the SPA/navigation. Sanctum provides browser/token authentication. Cached market-data services and external providers feed analytical/trading workflows; Kite is the broker integration.

## 13. Domain Map

| Domain | Current document | Owns |
| --- | --- | --- |
| Frontend | [Frontend And Navigation](./frontend-and-navigation.md) | Shell, routing, page anatomy, responsive/shared UI. |
| Portfolio | [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md) | Ledger, holdings, cash, capital, ownership. |
| Market | [Market Data And Data Quality](./market-data-and-data-quality.md) | Security master, prices, sync, quality, datasets. |
| Discovery | [Discovery, Screeners, And Registries](./discovery-screeners-registries.md) | Screeners, candidates, discovery, registries. |
| Strategy | [Strategy And Recommendations](./strategy-and-recommendations.md) | Policy, evaluation, recommendations, capital status. |
| Execution | [Execution, Broker, And Safety](./execution-broker-safety.md) | Orders, Kite, safety, reconciliation. |
| Analytics | [Analytics, Review, And Backtesting](./analytics-review-backtesting.md) | Performance, review, backtest/replay/simulation. |
| Notifications | [Notifications, Calendar, And Alerts](./notifications-calendar-alerts.md) | Delivery, alerts, reminders, calendar. |
| Knowledge | [Knowledge And Documentation](./knowledge-and-documentation.md) | Notes, wiki, sharing, help. |
| Security | [Administration, Security, And API](./administration-security-api.md) | Auth, Admin boundary, tokens, TOTP, API governance. |
| Artifacts | [Trading Artifacts And Runtime Guide](./stox-trading-artifacts-ai-guide.md) | Artifact validation, versions, bindings, packages. |

## 14. Critical Cross-Domain Invariants

- Admin is not Investor owner; active profile is an authorization boundary.
- Missing data is not zero; screener pass is not recommendation.
- Approval is not execution; funding state is not investment opinion.
- Pending SELL proceeds are not broker cash; notification state is not domain state.
- Analytics/simulation are not ledger truth; historical runs retain provenance.
- AI artifacts receive no special trust; ML cannot silently override deterministic authority.

## 15. Historical Context

StoX evolved from LidoPortfolio. Legacy repository/database/CSS naming remains technical history; current product identity and contracts are StoX.
