# StoX User Journeys — Screeners

[Back to journey index](README.md)

## Before using screeners

A screener answers a discovery question such as **“Which stocks currently satisfy these measurable conditions?”** It does not decide whether StoX should buy, increase, reduce or exit a position. Those decisions belong to strategy and recommendation processing.

StoX screener conditions use supported indicators or numeric constants. Groups can use AND/OR logic. Unsupported inputs should be rejected rather than guessed.

---

## SCR-01 — Create a screener from scratch

**Goal:** Create a reusable definition that finds stocks matching a measurable condition.

**Example:** Find stocks whose current price is above their 200-period moving average.

1. Open **Screeners** at `/screeners`.
2. Start creation of a new screener.
3. Enter a descriptive name, for example **Price Above MA200**. Prefer names that describe the rule rather than names such as “My Screener 1”.
4. Add the first condition.
5. Select the price/close indicator on one side.
6. Select the supported moving-average indicator and set its period to `200` on the other side.
7. Select the `>` comparison.
8. Review the definition and save it.
9. Validate the screener before depending on it in a strategy.

**Expected result:** A saved screener exists and its definition expresses the intended comparison. Saving the screener does not create a recommendation or broker order.

**Important:** If the stock does not have enough historical observations to calculate the requested indicator, StoX should treat the input as insufficient rather than fabricate a value.

---

## SCR-02 — Create a multi-condition AND screener

**Goal:** Require several conditions to be true at the same time.

**Example:** Price above MA200 **and** RSI(14) below 70.

1. Open `/screeners` and create a new screener.
2. Name it, for example **Momentum Entry — MA200 + RSI**.
3. Add condition 1: **Price > MA(200)**.
4. Add condition 2: **RSI(14) < 70**.
5. Put both conditions in an **AND** group.
6. Save and validate the definition.
7. Run or inspect the screener results.

**Meaning:** With AND, a stock becomes a match only when every condition in the group passes. A stock above MA200 with RSI 75 therefore does not match this example.

**Expected result:** The candidate set contains only securities satisfying both conditions with sufficient source data.

---

## SCR-03 — Create nested AND/OR logic

**Goal:** Express alternatives without creating several separate screeners.

**Example:** Require a long-term uptrend and allow either of two momentum conditions.

Conceptually:

`Price > MA200 AND (RSI < threshold OR another supported momentum condition passes)`

1. Create or edit the screener at `/screeners`.
2. Create the outer **AND** group.
3. Add the long-term trend condition.
4. Add a nested **OR** group.
5. Add the alternative momentum conditions inside the OR group.
6. Review the visual grouping carefully. Group placement changes the meaning of the rule.
7. Save and validate.
8. Run the screener and inspect examples near the boundaries to confirm that the logic means what you intended.

**Meaning:** AND requires all members of that group; OR requires at least one member. StoX supports bounded nesting and condition counts, so very large rules should be simplified rather than forced into an unreadable definition.

---

## SCR-04 — Edit an existing screener

**Goal:** Change discovery criteria without creating an unrelated screener.

1. Open `/screeners`.
2. Locate the intended screener. Confirm the name/identity before editing, especially when similarly named definitions exist.
3. Open the screener editor.
4. Change the required condition, parameter, threshold or group.
5. Validate the new definition.
6. Save the change.
7. Run the screener again when you need candidate evidence from the changed definition.

**Expected result:** Future runs use the appropriate saved/versioned definition. Historical evidence should remain attributable to the definition/version that originally produced it.

**Important:** Editing and saving a screener does not silently rewrite historical candidate evidence or automatically execute a strategy.

---

## SCR-05 — Validate a screener

**Goal:** Confirm that StoX can understand and safely evaluate the definition.

1. Open the screener in `/screeners`.
2. Use the available validation/save validation flow.
3. If validation succeeds, review the definition once more for business meaning. Syntactically valid is not the same as economically sensible.
4. If validation fails, read the reported condition/indicator/parameter problem.
5. Correct the definition and validate again.

Typical reasons for failure include an unsupported indicator, unsupported comparison, invalid parameter, malformed grouping or invalid dependency.

**Expected result:** A valid definition is ready for a screener run or strategy use. An invalid definition fails closed instead of being interpreted approximately.

---

## SCR-06 — Run a screener and inspect matches

**Goal:** See which securities currently satisfy the screener.

1. Open `/screeners` and select the screener.
2. Trigger the supported screener run, or use the normal scheduled pipeline if that is the intended workflow.
3. Wait for the run to complete rather than interpreting an in-progress/failed run as an empty result.
4. Inspect the candidate/match results and run metadata.
5. For an interesting stock, inspect the evidence showing which predicates/indicators matched.

**Expected result:** StoX persists a run and candidate evidence that can later be consumed by evaluation/strategy processing.

**Important distinction:** A match means **“this security satisfied this screener”**. It does not mean **BUY**. The strategy can still reject it, convert it to WATCH/HOLD, size it differently, or be blocked by market/data/capital policy.

---

## SCR-07 — Reuse or import an existing screener

**Goal:** Avoid rebuilding a definition that already exists.

1. Check `/screeners` and `/screeners/registry` for the required definition.
2. Inspect the screener's description, conditions and version rather than selecting it only by name.
3. If it is a reusable/shared/factory artifact, use the supported import/create/binding flow for the current portfolio.
4. Confirm that the resulting runtime screener belongs to or is valid for the intended portfolio/user scope.
5. Validate it before attaching it to strategy policy.

**Expected result:** The portfolio has a usable runtime screener based on the intended reusable definition without silently changing the source definition.

---

## SCR-08 — Retire or archive a screener

**Goal:** Stop using an obsolete definition without destroying historical evidence.

1. Open the screener management/registry surface.
2. Identify where the screener is currently used before retiring it.
3. If a live strategy depends on it, change the strategy to another valid screener first or otherwise resolve the dependency.
4. Use the supported archive/retire lifecycle action.
5. Confirm that it is no longer offered for new runtime use while historical runs remain understandable.

**Expected result:** Future use is prevented as defined by the lifecycle, while old recommendations/runs can still explain which screener/version produced their evidence.

---

## SCR-09 — Diagnose a match or non-match

**Goal:** Answer **“Why did this stock appear?”** or **“Why did this stock not appear?”**

1. Identify the exact screener and run being investigated.
2. Confirm the screener/version used by that run.
3. Inspect each condition and its indicator parameters.
4. Check whether enough historical bars existed for each indicator.
5. For volume-dependent rules, check whether valid volume history existed. Missing volume is not zero.
6. Check dataset freshness/data-quality status.
7. Check whether the security was active and inside the configured universe at the relevant time.
8. For historical runs, confirm that the investigation uses data available at that historical point rather than today's mutable state.
9. Distinguish three outcomes: a genuine condition failure, unavailable/insufficient input, and an actual processing failure.

**Expected result:** The user can identify the evidence or missing prerequisite that explains the result instead of treating every absence as “condition=false”.

---

## What comes next

After the required discovery rule exists, continue with [Strategy journeys](02-strategies.md). A common complete path is [E2E-01 — New idea to first BUY](05-end-to-end.md#e2e-01--new-idea-to-first-buy).
