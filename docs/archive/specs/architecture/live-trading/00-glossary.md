# Live Trading Subsystem

**Specification:** 00-glossary.md  
**Version:** 1.0  
**Status:** Draft  
**Owner:** Product Specification  
**Subsystem:** Live Trading & Order Execution

---

# 1. Purpose

This document defines the terminology used throughout the Live Trading subsystem.

Every subsequent specification shall use these terms exactly as defined here.

The purpose of this glossary is to ensure that product discussions, implementation, documentation, and future enhancements all refer to concepts consistently.

---

# 2. Core Trading Concepts

## Strategy

A Strategy is the complete investment policy that determines how securities are evaluated, selected, sized, managed, and exited.

A Strategy consumes candidate securities and produces trading recommendations.

A Strategy does **not** communicate directly with brokers.

---

## Screener

A Screener identifies securities that satisfy a defined set of entry or exit conditions.

Screeners determine eligibility only.

They do not perform portfolio allocation, risk evaluation, or order execution.

---

## Recommendation

A Recommendation is the output produced by the Strategy Engine after evaluating one or more eligible securities.

Examples include:

- Open Position
- Increase Position
- Reduce Position
- Exit Position
- Hold
- Watch

Recommendations are advisory decisions.

Recommendations are **not** executable broker orders.

---

## Recommendation Cycle

A Recommendation Cycle represents one complete execution of the Strategy Engine for a portfolio on a specific trading day.

---

# 3. Execution Concepts

## Order Basket

An Order Basket is a temporary collection of executable orders created from one Recommendation Cycle.

The Order Basket exists only until it is executed, discarded, or regenerated.

---

## Order

An Order is an instruction requesting a broker to buy or sell a financial instrument.

Orders may be accepted, rejected, cancelled, modified, or executed.

An Order is **not** a Trade.

---

## Execution

Execution is the process of submitting one or more Orders to a Broker.

Execution may be manual, semi-automatic, supervised, or fully automatic depending on the configured Execution Mode.

---

## Broker

A Broker is an external trading platform capable of executing market orders on behalf of the user.

Examples include Zerodha and future broker integrations.

The Live Trading subsystem communicates only through the Broker Abstraction.

---

## Broker Connection

A Broker Connection represents an authenticated communication channel between StoX and a supported broker.

---

# 4. Portfolio Concepts

## Portfolio

A Portfolio represents a collection of holdings managed under a single investment objective.

Only one active Strategy may be associated with a Portfolio at any point in time.

---

## Holding

A Holding represents an open investment position currently owned by the Portfolio.

A Holding exists from the time a Buy Order is executed until the position is fully closed.

---

## Position

Position refers to the current ownership state of a security within a Portfolio.

A Position may be:

- Open
- Increased
- Reduced
- Closed

---

## Transaction

A Transaction represents a single completed broker execution.

Examples:

- Buy
- Sell

Each Transaction corresponds to one broker-confirmed execution.

---

## Trade

A Trade represents the complete lifecycle of a Position.

A Trade begins with the opening Buy Transaction and ends when the Position is fully closed.

A Trade may therefore consist of multiple Transactions.

Example:

Buy

↓

Buy More

↓

Partial Sell

↓

Final Sell

↓

Trade Completed

---

# 5. Automation Concepts

## Execution Mode

Execution Mode defines the amount of human participation required before broker execution.

Supported modes are defined in the Execution Modes specification.

---

## Automation

Automation refers to the ability of StoX to execute Orders without requiring manual execution of each individual trade.

Automation never bypasses security or risk controls.

---

## Trading Session

A Trading Session is a time-limited period during which automated execution is permitted after successful user authentication.

---

## Live Trading

Live Trading refers to execution against a real broker using real funds.

Live Trading is distinct from Paper Trading and Backtesting.

---

## Paper Trading

Paper Trading simulates real trading using live market data without placing broker orders.

Paper Trading is outside the scope of the current subsystem but supported by the architecture.

---

# 6. Risk Concepts

## Risk Engine

The Risk Engine evaluates whether proposed Orders satisfy configured safety policies before execution.

The Risk Engine has authority to reject or modify execution requests.

---

## Risk Policy

A Risk Policy is a configurable collection of limits and safety rules governing trading activity.

Risk Policies are independent of investment Strategies.

---

## Kill Switch

The Kill Switch is an emergency control that immediately prevents all automated broker execution.

The Kill Switch does not disconnect the broker.

It simply prevents further order submission.

---

# 7. Security Concepts

## Trading Permission

Trading Permission authorizes a user to access Live Trading capabilities.

Users without this permission shall not see Live Trading features.

---

## Trading PIN

A Trading PIN is a secondary authentication credential required for security-sensitive trading operations.

---

## Multi-Factor Authentication (MFA)

Multi-Factor Authentication requires additional verification beyond username and password before granting access to protected operations.

---

## TOTP

Time-Based One-Time Password (TOTP) is the preferred MFA mechanism used by StoX.

---

# 8. Monitoring Concepts

## Audit Log

The Audit Log is an immutable chronological record of security-sensitive and trading-related events.

Audit records cannot be modified by users.

---

## Notification

A Notification informs the user about significant trading, security, or operational events.

Notifications do not imply user approval.

---

## Alert

An Alert indicates that immediate user attention may be required.

Alerts represent exceptional conditions rather than routine notifications.

---

# 9. Specification Terms

## Shall

Mandatory requirement.

---

## Should

Strong recommendation.

---

## May

Optional capability.

---

## Future

Capability intentionally excluded from the current implementation but considered during architectural design.

---

# 10. Related Specifications

README.md

01-overview.md

02-broker-abstraction.md

03-security-and-authentication.md

04-order-lifecycle.md

05-execution-modes.md

06-risk-engine.md

07-automation-engine.md

08-monitoring-and-audit.md

09-notifications.md

10-roadmap.md

decisions.md
