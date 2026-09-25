# StoX User Journey Guide

## Purpose

This guide documents how a person uses StoX to complete real investment and trading workflows. It is intentionally written from the user's point of view rather than as an API, database, or implementation reference.

The primary focus is the non-trivial daily workflow:

**Screener -> Strategy -> Decision Pipeline -> Recommendation -> Review -> Pending Execution -> Broker/Manual Execution -> Transaction/Holding**

A screener match is candidate evidence, not a trade instruction. A strategy applies policy, position context, market gates, sizing and capital rules before StoX creates an actionable recommendation. Approving a recommendation authorizes the execution workflow; approval itself is not broker submission.

This documentation has three later uses in addition to helping a human user:

1. Review whether important user workflows are missing or unnecessarily difficult.
2. Define stable UI automation hooks such as `data-testid` attributes where required.
3. Become the functional basis for future Playwright/Selenium end-to-end tests.

The journeys therefore describe observable user intent, actions and expected results. They deliberately do **not** prescribe test selectors or automation implementation yet.

## How to use this guide

Each journey has a stable ID. Use the ID when discussing a workflow, reporting a usability problem, defining an automation case, or linking a future test.

Canonical application paths used by the guide include:

- Screeners: `/screeners`
- Screener Registry: `/screeners/registry`
- Strategy: `/strategy`
- Strategy Registry: `/strategy/registry`
- Recommendations: `/recommendations`
- Review: `/review`
- Pending Execution: `/transactions/pending`
- Transactions: `/transactions`
- Closed Transactions: `/transactions/closed`
- Holdings: `/holdings`
- Backtests: `/backtests`

UI labels can evolve. The route and user intent are more important than minor wording differences in a button label.

## Scenario index

### A. Screeners

| ID | Scenario | Detail |
| --- | --- | --- |
| SCR-01 | Create a screener from scratch | [Screeners](01-screeners.md#scr-01--create-a-screener-from-scratch) |
| SCR-02 | Create a multi-condition AND screener | [Screeners](01-screeners.md#scr-02--create-a-multi-condition-and-screener) |
| SCR-03 | Create nested AND/OR logic | [Screeners](01-screeners.md#scr-03--create-nested-andor-logic) |
| SCR-04 | Edit an existing screener | [Screeners](01-screeners.md#scr-04--edit-an-existing-screener) |
| SCR-05 | Validate a screener | [Screeners](01-screeners.md#scr-05--validate-a-screener) |
| SCR-06 | Run a screener and inspect matches | [Screeners](01-screeners.md#scr-06--run-a-screener-and-inspect-matches) |
| SCR-07 | Reuse/import an existing screener | [Screeners](01-screeners.md#scr-07--reuse-or-import-an-existing-screener) |
| SCR-08 | Retire/archive a screener | [Screeners](01-screeners.md#scr-08--retire-or-archive-a-screener) |
| SCR-09 | Diagnose why a stock matched or did not match | [Screeners](01-screeners.md#scr-09--diagnose-a-match-or-non-match) |

### B. Strategy creation and configuration

| ID | Scenario | Detail |
| --- | --- | --- |
| STR-01 | Create a strategy using an existing screener | [Strategies](02-strategies.md#str-01--create-a-strategy-using-an-existing-screener) |
| STR-02 | Create a strategy when the required screener does not exist | [Strategies](02-strategies.md#str-02--create-a-strategy-when-the-required-screener-does-not-exist) |
| STR-03 | Configure entry criteria | [Strategies](02-strategies.md#str-03--configure-entry-criteria) |
| STR-04 | Configure exit criteria | [Strategies](02-strategies.md#str-04--configure-exit-criteria) |
| STR-05 | Use separate entry and exit definitions | [Strategies](02-strategies.md#str-05--use-separate-entry-and-exit-definitions) |
| STR-06 | Configure factors, weights and thresholds | [Strategies](02-strategies.md#str-06--configure-factors-weights-and-thresholds) |
| STR-07 | Configure position sizing and portfolio limits | [Strategies](02-strategies.md#str-07--configure-position-sizing-and-portfolio-limits) |
| STR-08 | Configure stop-loss, trailing stop and exit policy | [Strategies](02-strategies.md#str-08--configure-stop-loss-trailing-stop-and-exit-policy) |
| STR-09 | Configure market gates | [Strategies](02-strategies.md#str-09--configure-market-gates) |
| STR-10 | Edit an existing strategy | [Strategies](02-strategies.md#str-10--edit-an-existing-strategy) |
| STR-11 | Change the screener without rebuilding the strategy | [Strategies](02-strategies.md#str-11--change-the-screener-without-rebuilding-the-strategy) |
| STR-12 | Change exit policy without changing entry policy | [Strategies](02-strategies.md#str-12--change-exit-policy-without-changing-entry-policy) |
| STR-13 | Enable a strategy | [Strategies](02-strategies.md#str-13--enable-a-strategy) |
| STR-14 | Archive/disable a strategy | [Strategies](02-strategies.md#str-14--archive-or-disable-a-strategy) |
| STR-15 | Operate multiple strategies concurrently | [Strategies](02-strategies.md#str-15--operate-multiple-strategies-concurrently) |
| STR-16 | Same stock used by multiple strategies | [Strategies](02-strategies.md#str-16--same-stock-used-by-multiple-strategies) |

### C. Recommendations and review

| ID | Scenario | Detail |
| --- | --- | --- |
| REC-01 | Generate recommendations through the decision pipeline | [Recommendations](03-recommendations-review.md#rec-01--generate-recommendations-through-the-decision-pipeline) |
| REC-02 | Review new recommendations | [Recommendations](03-recommendations-review.md#rec-02--review-new-recommendations) |
| REC-03 | Understand why StoX recommended an action | [Recommendations](03-recommendations-review.md#rec-03--understand-why-stox-recommended-an-action) |
| REC-04 | Understand OPEN/INCREASE/REDUCE/EXIT | [Recommendations](03-recommendations-review.md#rec-04--understand-open-increase-reduce-and-exit) |
| REC-05 | Understand WATCH/HOLD | [Recommendations](03-recommendations-review.md#rec-05--understand-watch-and-hold) |
| REC-06 | Preview a recommendation for one stock | [Recommendations](03-recommendations-review.md#rec-06--preview-a-recommendation-for-one-stock) |
| REC-07 | Approve a recommendation | [Recommendations](03-recommendations-review.md#rec-07--approve-a-recommendation) |
| REC-08 | Reject a recommendation | [Recommendations](03-recommendations-review.md#rec-08--reject-a-recommendation) |
| REC-09 | Defer and later reopen | [Recommendations](03-recommendations-review.md#rec-09--defer-and-later-reopen) |
| REC-10 | Handle partial funding | [Recommendations](03-recommendations-review.md#rec-10--handle-partial-funding) |
| REC-11 | Handle an unfunded recommendation | [Recommendations](03-recommendations-review.md#rec-11--handle-an-unfunded-recommendation) |
| REC-12 | Handle a superseded recommendation | [Recommendations](03-recommendations-review.md#rec-12--handle-a-superseded-recommendation) |

### D. Execution and transaction lifecycle

| ID | Scenario | Detail |
| --- | --- | --- |
| EXE-01 | Execute an approved recommendation manually | [Execution](04-execution-transactions.md#exe-01--execute-an-approved-recommendation-manually) |
| EXE-02 | Record a manually executed transaction | [Execution](04-execution-transactions.md#exe-02--record-a-manually-executed-transaction) |
| EXE-03 | Execute in Semi-Automatic mode | [Execution](04-execution-transactions.md#exe-03--execute-in-semi-automatic-mode) |
| EXE-04 | Authorize a Semi-Automatic execution | [Execution](04-execution-transactions.md#exe-04--authorize-a-semi-automatic-execution) |
| EXE-05 | Review Pending Execution | [Execution](04-execution-transactions.md#exe-05--review-pending-execution) |
| EXE-06 | Submit a BUY order | [Execution](04-execution-transactions.md#exe-06--submit-a-buy-order) |
| EXE-07 | Submit REDUCE/EXIT SELL order | [Execution](04-execution-transactions.md#exe-07--submit-a-reduce-or-exit-sell-order) |
| EXE-08 | Cancel before broker submission | [Execution](04-execution-transactions.md#exe-08--cancel-before-broker-submission) |
| EXE-09 | Cancel a submitted broker order | [Execution](04-execution-transactions.md#exe-09--cancel-a-submitted-broker-order) |
| EXE-10 | Handle unfilled/cancelled order | [Execution](04-execution-transactions.md#exe-10--handle-an-unfilled-or-cancelled-order) |
| EXE-11 | Handle partial fill | [Execution](04-execution-transactions.md#exe-11--handle-a-partial-fill) |
| EXE-12 | Handle broker rejection | [Execution](04-execution-transactions.md#exe-12--handle-broker-rejection) |
| EXE-13 | Reconcile uncertain broker state | [Execution](04-execution-transactions.md#exe-13--reconcile-uncertain-broker-state) |
| EXE-14 | Retry without creating a duplicate order | [Execution](04-execution-transactions.md#exe-14--retry-without-creating-a-duplicate-order) |
| EXE-15 | Verify transaction and holding after execution | [Execution](04-execution-transactions.md#exe-15--verify-transaction-and-holding-after-execution) |

### E. End-to-end journeys

| ID | Scenario | Detail |
| --- | --- | --- |
| E2E-01 | New idea -> new screener -> new strategy -> recommendation -> BUY | [End to end](05-end-to-end.md#e2e-01--new-idea-to-first-buy) |
| E2E-02 | Existing screener -> new strategy -> BUY | [End to end](05-end-to-end.md#e2e-02--existing-screener-to-buy) |
| E2E-03 | Existing holding -> exit signal -> SELL -> closed transaction | [End to end](05-end-to-end.md#e2e-03--exit-signal-to-closed-transaction) |
| E2E-04 | Modify strategy -> regenerate -> supersede old recommendation | [End to end](05-end-to-end.md#e2e-04--strategy-change-and-supersession) |
| E2E-05 | Approve -> cancel -> retry later | [End to end](05-end-to-end.md#e2e-05--approve-cancel-and-retry) |
| E2E-06 | Insufficient capital -> resolve funding -> execute | [End to end](05-end-to-end.md#e2e-06--insufficient-capital-to-execution) |
| E2E-07 | Same stock in two strategies -> one strategy exits | [End to end](05-end-to-end.md#e2e-07--same-stock-in-two-strategies) |

## Deferred/simple journey chapter

The first version intentionally prioritizes workflows that change investment policy, recommendation state, money, orders or holdings. A later chapter should cover simpler navigation and maintenance journeys such as Dashboard reading, Stock Explorer, Watchlist, alerts, notification history, ordinary settings, profile and portfolio switching.

## Documentation rules

When adding or changing a journey:

- Describe what the user is trying to achieve before describing controls.
- Prefer a realistic example over abstract placeholders.
- Explain the meaning of fields that affect investment or execution behavior.
- State the expected observable result after important actions.
- Keep screener selection, strategy policy, recommendation approval and broker execution conceptually separate.
- Never imply that saving a strategy automatically generates a recommendation.
- Never imply that approving a recommendation automatically means the broker accepted an order.
- Preserve strategy ownership when the same security appears under more than one strategy.
- Document failure/recovery paths when money, broker state or recommendation lifecycle can be affected.

## Source-of-truth references

This guide is a user-facing companion to the current contracts under `docs/current/`, especially:

- `discovery-screeners-registries.md`
- `strategy-and-recommendations.md`
- `execution-broker-safety.md`
- `portfolio-cash-accounting.md`
- `frontend-and-navigation.md`

If this guide conflicts with an implemented/current product contract, correct the guide or product inconsistency explicitly rather than silently inventing behavior.
