# StoX Golden Core Investor Workflows

**Date:** 2026-09-26  
**Status:** TARGET UX / GAP-ANALYSIS BASELINE — NOT YET IMPLEMENTED  
**Scope:** Screener → Strategy → Recommendation → Review → Execution → Transaction  
**Companion decision:** `2026-09-25-screener-ux-versioning-simplification.md`

## 1. Purpose

This document records the target, user-first workflow for the core day-to-day StoX investment journey before comparing it with the current implementation.

It is intentionally not a description of today's UI. It is the golden baseline against which current pages, user journeys and implementation will be compared.

Governing principles:

1. **Simplicity first.** Investors work with domain concepts, not artifact-framework bookkeeping.
2. **Auditability intact.** Historical decisions remain traceable to exact immutable Strategy/Screener definitions and evidence.
3. **No unexplained disabled actions.** A blocked action states why and gives the shortest resolution path.
4. **Resolve dependencies in context.** If a Strategy needs a Screener or execution needs Kite, the user can resolve that dependency without losing the parent task.
5. **No silent behavioral cascade.** Changing a Screener does not silently rewrite an existing Strategy dependency; historical recommendations never change because today's configuration changed.
6. **User intent and system state are different facts.** Approval is not execution; submission is not fill; fill is what creates realized transaction/accounting evidence.
7. **Technical identifiers are system-managed.** SemVer, UUIDs, artifact revisions, bindings and submission keys are not ordinary investor inputs.

## 2. Golden Journey Catalogue / Index

### A. Screener

| ID | Scenario |
|---|---|
| GJ-SCR-01 | Create a Screener |
| GJ-SCR-02 | Edit a Screener |
| GJ-SCR-03 | Test/run a Screener |
| GJ-SCR-04 | Duplicate a Screener |
| GJ-SCR-05 | Inspect Screener history |
| GJ-SCR-06 | Inspect the historical Screener used by a Recommendation |
| GJ-SCR-07 | Archive an unused Screener |
| GJ-SCR-08 | Attempt to archive a Screener that is still required |
| GJ-SCR-09 | Share a Screener definition without sharing a mutable instance |

### B. Strategy

| ID | Scenario |
|---|---|
| GJ-STR-01 | Create a Strategy using an existing Screener |
| GJ-STR-02 | Create a Strategy when the required Screener does not exist |
| GJ-STR-03 | Save an incomplete Strategy and continue setup later |
| GJ-STR-04 | Complete and enable a Strategy |
| GJ-STR-05 | Edit an enabled Strategy |
| GJ-STR-06 | Attempt to save an invalid edit to an enabled Strategy |
| GJ-STR-07 | Review and adopt a newer Screener definition in a Strategy |
| GJ-STR-08 | Disable a Strategy with no operational obligations |
| GJ-STR-09 | Attempt to disable a Strategy that still manages positions/live work |
| GJ-STR-10 | Archive a safely retired Strategy |
| GJ-STR-11 | Inspect Strategy history |
| GJ-STR-12 | Inspect the historical Strategy used by a Recommendation |
| GJ-STR-13 | Operate multiple enabled Strategies concurrently |

### C. Recommendations and Review

| ID | Scenario |
|---|---|
| GJ-REC-01 | Review a new actionable Recommendation |
| GJ-REC-02 | Understand why a Recommendation was generated |
| GJ-REC-03 | Approve a Recommendation |
| GJ-REC-04 | Reject a Recommendation |
| GJ-REC-05 | Defer a Recommendation |
| GJ-REC-06 | Reopen a previously reviewed Recommendation where allowed |
| GJ-REC-07 | Read an informational WATCH Recommendation |
| GJ-REC-08 | Read an informational HOLD Recommendation |
| GJ-REC-09 | Review a partially fundable Recommendation |
| GJ-REC-10 | Review an unfunded Recommendation |
| GJ-REC-11 | Follow a superseded Recommendation to its current replacement |
| GJ-REC-12 | Inspect an expired/stale Recommendation |
| GJ-REC-13 | Review an EXIT Recommendation scoped to one Strategy's owned position |

### D. Execution / Broker

| ID | Scenario |
|---|---|
| GJ-EXE-01 | Review approved work in Pending Execution |
| GJ-EXE-02 | Resolve a blocked execution prerequisite |
| GJ-EXE-03 | Connect Kite from within the execution journey and return |
| GJ-EXE-04 | Manually execute/record an approved recommendation where applicable |
| GJ-EXE-05 | Submit an approved recommendation in Semi-Automatic mode |
| GJ-EXE-06 | Allow eligible Automatic execution without per-order action |
| GJ-EXE-07 | Observe an order waiting at the broker |
| GJ-EXE-08 | Observe a partial fill |
| GJ-EXE-09 | Observe a completed fill |
| GJ-EXE-10 | Cancel an unfilled broker execution attempt |
| GJ-EXE-11 | Wait for broker cancellation confirmation |
| GJ-EXE-12 | Retry execution after a confirmed zero-fill cancellation/rejection |
| GJ-EXE-13 | Execute only the remaining quantity after partial fulfillment |
| GJ-EXE-14 | Cancel the underlying Recommendation separately from cancelling an order attempt |
| GJ-EXE-15 | Understand Emergency Halt while existing broker orders remain in-flight |

### E. Transactions / Audit

| ID | Scenario |
|---|---|
| GJ-TXN-01 | Review completed Transactions |
| GJ-TXN-02 | Trace a Transaction back to Order, Recommendation, Strategy and Screener |
| GJ-TXN-03 | Review Strategy ownership attribution for a Transaction |
| GJ-TXN-04 | Record a legitimate external/manual Transaction |

### F. Cross-Entity Journeys

| ID | Scenario |
|---|---|
| GJ-X-01 | Create Screener → create Strategy → enable Strategy |
| GJ-X-02 | Create Strategy → discover missing Screener → create Screener → return with it selected |
| GJ-X-03 | Edit Screener already referenced by Strategy → preserve Strategy pin → review update explicitly |
| GJ-X-04 | Run Strategy → Recommendation → approve → execute → fill → Transaction |
| GJ-X-05 | Recommendation → Why? → exact historical Strategy → exact historical Screener |
| GJ-X-06 | Transaction → Order → Recommendation → Strategy → Screener → evidence |
| GJ-X-07 | Approve → broker order → cancel attempt → retry without losing Recommendation reservation |
| GJ-X-08 | Partial fill → remaining execution → final Transaction/accounting completion |
| GJ-X-09 | Multiple Strategies independently recommend/own the same security |

## 3. Screener Golden Workflow

### Domain model

A Screener answers: **Which stocks satisfy these conditions?**

A user-created Screener is private and account-scoped. Sharing exposes/copies its definition, conditions and structure; it does not share the source user's mutable instance.

### Primary surface

`Screeners`

Primary actions:

- Create
- View
- Edit
- Test/Run
- Duplicate where supported
- Archive where safe

Secondary action:

- History

Artifact Library is not a mandatory path for ordinary Screener CRUD.

### Create — GJ-SCR-01

1. Open **Screeners**.
2. Select **Create Screener**.
3. Enter the Screener's investor-facing fields such as name, description and conditions.
4. Optionally test/run the definition.
5. Select **Save**.
6. StoX validates and performs internal immutable-version/provenance bookkeeping automatically.
7. The saved Screener immediately appears in the user's Screener list and is available for supported selection/use.

The user does not enter SemVer, UUID, artifact version, binding or publication metadata.

There is no persisted Screener Draft lifecycle. Unsaved browser/form edits are lost when abandoned.

### Edit — GJ-SCR-02

1. Open **Screeners**.
2. Open or directly select **Edit** on the Screener.
3. Change its conditions/definition.
4. Select **Save**.
5. StoX creates the next immutable internal version automatically and preserves the previous definition for audit/backtest/history.
6. The domain view shows the newly saved definition as current.

Existing Strategy versions pinned to the older Screener definition do not silently change.

### History / audit — GJ-SCR-05, GJ-SCR-06

History is secondary, primarily read-only audit UX. Historical Recommendations link directly to the exact Screener definition used at generation time.

If restore convenience is later supported, restore creates a new forward version copied from the historical definition; it does not reactivate/mutate the historical immutable record.

## 4. Strategy Golden Workflow

### Domain model

A Strategy answers: **How should StoX invest under this policy?**

The Strategy user experience owns configuration for the applicable supported concepts, including stock selection, scoring/ranking, entry policy, position sizing, exit rules, capital allocation and market conditions.

Multiple Strategies may be enabled concurrently. The obsolete single-active-Strategy rule must not influence the target UX.

### User-facing states

- **Setup Required** — persisted but incomplete/invalid for enablement.
- **Disabled** — complete/valid but not participating in normal new decision processing.
- **Enabled** — participates in applicable decision processing.
- **Archived** — safely retired and retained for history.

`Ready` may be displayed as validation information but is not required as a separate lifecycle state.

### Create with existing Screener — GJ-STR-01

1. Open **Strategies**.
2. Select **Create Strategy**.
3. Enter basic information.
4. Select an existing Screener for stock selection/entry eligibility.
5. Configure the supported scoring/ranking, sizing, exit, capital and market-policy sections.
6. Save at any point.
7. If incomplete, StoX persists the Strategy as **Setup Required** and lists every missing requirement with direct navigation.
8. When all mandatory validation passes, the Strategy is **Disabled** and can be explicitly enabled.
9. Select **Enable Strategy**.
10. Strategy becomes **Enabled**.

### Create missing Screener in context — GJ-STR-02 / GJ-X-02

1. While creating/editing Strategy, select **Create Screener** beside the Screener selector.
2. StoX preserves the unfinished Strategy context.
3. Complete the normal Screener create flow.
4. Save the Screener.
5. StoX returns to the Strategy editor.
6. The new Screener is already selected.
7. Continue Strategy setup without re-entering previous values.

### Edit enabled Strategy — GJ-STR-05

1. Open the Strategy and select **Edit**.
2. Modify configuration.
3. Select **Save**.
4. StoX validates the complete replacement configuration transactionally.
5. If valid, StoX creates the next immutable Strategy version and makes it current for future processing while historical Recommendations remain pinned to their original version.
6. Saving alone does not regenerate Recommendations.

### Invalid edit — GJ-STR-06

If an existing valid Strategy is edited into an invalid state, the invalid configuration does not replace the current operational version. StoX lists the validation errors and states that the previously valid configuration remains in force.

New/incomplete Strategies may remain persisted as **Setup Required**; this is domain readiness, not a generic Artifact Draft lifecycle.

### Screener update dependency — GJ-STR-07 / GJ-X-03

A Screener edit does not silently rewrite a published/current Strategy dependency. Strategy surfaces **Update available**, allows review of a human-readable diff and requires explicit adoption. Adoption creates the appropriate new Strategy version.

### Disable / archive — GJ-STR-08 through GJ-STR-10

A Strategy with no operational obligations may be disabled normally.

A Strategy that still owns positions/live operational work must not be casually disabled if doing so would stop required position/exit management. StoX explains the blocking obligations and links directly to them.

Archive is allowed only when the Strategy is safely retired according to operational rules; history remains preserved.

## 5. Recommendations And Review Golden Workflow

### Domain model

A Recommendation answers: **Given the Strategy and evidence at that time, what is StoX proposing?**

The Recommendation detail must make these immediately understandable:

1. What action?
2. Why?
3. Which Strategy?
4. How much / what quantity?
5. Funding/capital state?
6. What action is required from the investor?

### Primary surface

Recommendations should prioritize **Needs Your Attention**. Secondary groupings may include approved/pending execution, informational WATCH/HOLD and History.

### Review actionable Recommendation — GJ-REC-01

1. Open **Recommendations**.
2. Open an item under **Needs Your Attention**.
3. Review action, Strategy, amount/quantity, evidence, funding and generation time.
4. Select **Approve**, **Reject** or **Defer**.

### Why? — GJ-REC-02 / GJ-X-05

**Why this recommendation?** reconstructs the decision from pinned historical evidence, including the applicable Screener conditions, Strategy evaluation, position context, market policy and sizing/capital context.

The page provides **View Strategy used** and **View Screener used**, resolving the exact historical definitions rather than today's latest definitions.

### Approve — GJ-REC-03

Approval records the investment decision and moves eligible actionable intent toward `pending_execution`. Approval explicitly does **not** claim that a broker order has been placed or that a transaction occurred.

### Reject / Defer / Reopen — GJ-REC-04 through GJ-REC-06

Reject and Defer preserve history and may accept an optional investor note. Reopen is a secondary corrective action where the lifecycle allows it; executed accounting history is not erased by pretending execution never occurred.

### WATCH / HOLD — GJ-REC-07, GJ-REC-08

WATCH and HOLD are informational and do not present Approve/Reject execution actions.

### Funding — GJ-REC-09, GJ-REC-10

The Recommendation's desired target is distinct from currently available funding. Insufficient funding does not rewrite the investment opinion into WATCH. StoX clearly presents desired target, currently fundable amount and gap.

### Superseded / expired — GJ-REC-11, GJ-REC-12

A stale/superseded Recommendation is visibly non-actionable and, where possible, links to the current replacement. Historical evidence remains available.

### Strategy-owned exit — GJ-REC-13

An EXIT/REDUCE Recommendation clearly identifies the owning Strategy and the exact quantity attributable to that Strategy. Another Strategy's ownership of the same security is unaffected unless it has its own Recommendation.

## 6. Execution And Broker Golden Workflow

### Domain model

- **Recommendation:** proposed investment decision.
- **Approval:** authorization of that investment intent.
- **Order/Execution attempt:** instruction submitted/recorded for realization.
- **Fill:** broker evidence that quantity actually traded.
- **Transaction:** realized portfolio/accounting event derived from supported evidence.

Approval is not execution. Submission is not fill.

### Pending Execution — GJ-EXE-01

Pending Execution is an action queue of approved intent that is not fully realized. Each item displays its readiness.

If ready, provide the appropriate execution action for its mode.

If blocked, show the exact blocker and shortest resolution path rather than a mysteriously disabled button.

### Execution modes

Manual, Semi-Automatic, Automatic and Paper/Simulation preserve one conceptual lifecycle. The primary difference is submission authority and safety gates, not the meaning of Recommendation/Order/Fill/Transaction.

### Resolve dependency in context — GJ-EXE-02, GJ-EXE-03

If Kite connection or another resolvable prerequisite is missing, expose the resolution action directly from Pending Execution and return the user to the same task afterward.

### Broker submission — GJ-EXE-05, GJ-EXE-06

Before explicit submission where applicable, show a concise final order review. After submission, immediately display a submitted/in-flight state and remove the normal submit action to prevent accidental duplication.

### Open / partial / filled — GJ-EXE-07 through GJ-EXE-09

Broker acknowledgement/open state is not presented as a fill.

Partial fill shows requested quantity, filled quantity, remaining quantity and average fill price. Retry/residual execution uses only the remaining eligible gap.

Completed fill creates the supported transaction/accounting evidence and links to the resulting position/transaction.

### Cancel broker attempt — GJ-EXE-10, GJ-EXE-11

Cancelling an unfilled broker order cancels that execution attempt, not the underlying Recommendation.

A broker DELETE acknowledgement is not terminal cancellation. While broker confirmation is pending, display **Cancellation requested; waiting for broker confirmation** and acknowledge that the order may still fill.

After confirmed zero-fill cancellation, the Recommendation remains approved/pending execution and its applicable reservation remains available for retry.

### Retry — GJ-EXE-12, GJ-EXE-13

Retry creates/uses a new execution attempt according to the execution contract while preserving the Recommendation. Partial fulfillment retries only the remaining quantity/gap.

### Cancel Recommendation — GJ-EXE-14

Cancelling the Recommendation is a separate investor action from cancelling an individual broker attempt. It terminates the underlying approved intent and releases applicable unconsumed reservation according to lifecycle rules.

### Emergency Halt — GJ-EXE-15

Emergency Halt blocks new StoX live submissions but does not falsely claim that already submitted/open broker orders were cancelled. Existing in-flight work remains visible and must reconcile/cancel through the broker-order lifecycle.

## 7. Transactions And Audit Golden Workflow

### Transactions — GJ-TXN-01

Transactions are historical realized facts, not another recommendation/work queue. They show security, BUY/SELL, quantity, realized price/amount, time/date and Strategy ownership where applicable.

### Traceability — GJ-TXN-02 / GJ-X-06

A Transaction detail provides the natural audit chain:

`Transaction → Order/Execution evidence → Recommendation → exact Strategy version → exact Screener version → decision evidence`

Users should be able to follow this chain without knowing internal version IDs.

### Strategy ownership — GJ-TXN-03

When multiple Strategies own the same security, transaction detail explicitly identifies which Strategy-owned position changed.

### External/manual transaction — GJ-TXN-04

**Record Transaction** is reserved for legitimate activity that occurred outside the normal StoX Recommendation/Execution provenance and needs to be reflected in the portfolio. It must not be confused with **Execute Recommendation**.

## 8. Cross-Entity Golden Flows

### GJ-X-01 — New Screener to enabled Strategy

`Screeners → Create → Save → Strategies → Create → select Screener → configure → Save → resolve Setup Required → Enable`

### GJ-X-02 — Missing Screener during Strategy creation

`Create Strategy → Screener missing → Create Screener → Save → return to Strategy with Screener selected → continue`

### GJ-X-03 — Edit a Screener used by Strategy

`Edit Screener → Save new immutable definition → existing Strategy remains pinned → Strategy shows update available → review diff → explicitly adopt → new Strategy version`

### GJ-X-04 — End-to-end investment action

`Run/evaluate Strategy → Recommendation → Review → Approve → Pending Execution → readiness → submit/execute → broker fill → Transaction/accounting → Position`

### GJ-X-05 — Explain a historical recommendation

`Recommendation → Why? → evidence → View Strategy used → View Screener used`

All definitions resolve to the historical pinned versions used at that time.

### GJ-X-06 — Audit a realized transaction

`Transaction → execution/order evidence → Recommendation → Strategy used → Screener used → evidence`

### GJ-X-07 — Cancel and retry

`Approved Recommendation → broker order → Cancel Order → cancellation pending → broker confirms zero-fill cancellation → Recommendation remains pending execution → Retry OR separately Cancel Recommendation`

### GJ-X-08 — Partial fill

`Recommendation target → broker attempt → partial fill → realized partial transaction/progress → remaining gap → residual execution → final completion`

### GJ-X-09 — Same security, multiple Strategies

Each Strategy retains independent Recommendation and position ownership. An action by one Strategy changes only its owned position unless another Strategy independently produces its own action.

## 9. UX Rules For Gap Analysis

When comparing current StoX against this target, flag at least these classes of problems:

- **Navigation detour:** ordinary domain work forces the user into Artifact Library/Registry/Settings unnecessarily.
- **Technical leakage:** user must understand SemVer, UUID, artifact/binding/revision or other bookkeeping.
- **Hidden prerequisite:** action is disabled without an explicit reason and resolution route.
- **Context loss:** resolving a dependency loses the parent task or entered values.
- **State ambiguity:** user cannot tell whether an object is incomplete, disabled, enabled, submitted, filled, cancelled, etc.
- **Unsafe conflation:** approval appears to mean execution, submission appears to mean fill, or order cancellation appears to mean Recommendation cancellation.
- **Silent cascade:** changing one reusable definition silently changes an operational dependent object.
- **Audit break:** current definitions replace/obscure historical definitions used for prior decisions.
- **Multi-Strategy ambiguity:** security-level UX hides Strategy ownership.
- **Dead-end validation:** validation says something is wrong but does not tell the user how to fix it.
- **Unnecessary ceremony:** extra publish/activate/bind/version steps add no investor-domain decision.
- **Duplicate action risk:** submit/execute remains available while an equivalent broker attempt is already in flight.

## 10. Next Phase

Use this document together with the existing current user-journey baseline to perform a scenario-by-scenario **Current vs Golden** comparison.

For each journey record:

1. current route/pages/actions;
2. golden route/pages/actions;
3. pain points and defects;
4. functional behavior that must remain intact;
5. UX/contract/code changes required;
6. severity/priority;
7. automation/testability implications, including stable selectors later;
8. whether a prior spec decision must be superseded or clarified.

Do not implement UI changes merely from this document. First complete the gap analysis and freeze any remaining material PO decisions.