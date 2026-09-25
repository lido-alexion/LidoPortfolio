# StoX User Journeys — Strategies

[Back to journey index](README.md)

## Before configuring a strategy

A strategy is a named investment policy. It consumes discovery/screener evidence and combines it with factors, thresholds, position context, exit policy, sizing, market gates and capital rules. More than one strategy may be enabled for a portfolio at the same time.

**Saving strategy configuration changes policy only. It does not automatically generate, approve or execute a recommendation.**

---

## STR-01 — Create a strategy using an existing screener

**Goal:** Build investment policy around a discovery rule that already exists.

**Example:** Use **Momentum Entry — MA200 + RSI** as the candidate source for a momentum strategy.

1. Confirm the required screener exists and is valid under `/screeners` or `/screeners/registry`.
2. Open **Strategy** at `/strategy` or the management surface at `/strategy/registry`.
3. Start creation of a new strategy.
4. Give it a descriptive name, for example **Momentum Core**.
5. Select/bind the intended screener for entry eligibility.
6. Configure the remaining strategy policy: factors/weights, thresholds, exit policy, position sizing, limits, capital allocation and market gates as required.
7. Review the complete policy. A screener is only one input to the strategy.
8. Save the strategy.
9. Enable it when it is ready for live decision-pipeline runs.

**Expected result:** A distinct strategy exists with its own configuration/version and can participate in future pipeline runs when enabled.

---

## STR-02 — Create a strategy when the required screener does not exist

**Goal:** Turn a new investment idea into a strategy when discovery criteria must first be created.

**Example idea:** Consider a stock only when price is above MA200 and RSI(14) is below 70.

1. Go to `/screeners`.
2. Create **Momentum Entry — MA200 + RSI**.
3. Add **Price > MA(200)**.
4. Add **RSI(14) < 70**.
5. Combine the conditions with **AND**.
6. Save and validate the screener.
7. Optionally run it and inspect representative matches to confirm the rule behaves as intended.
8. Go to `/strategy` or `/strategy/registry`.
9. Create the new strategy.
10. Select the newly created screener as the relevant eligibility/discovery input.
11. Configure scoring, thresholds, exit policy, sizing, limits and market gates.
12. Save the strategy.
13. Enable it only after reviewing the complete policy.

**Expected result:** The discovery definition and investment policy remain separate, reusable concepts. A stock matching the new screener still requires strategy evaluation before it can become an actionable recommendation.

---

## STR-03 — Configure entry criteria

**Goal:** Define when a strategy is willing to initiate or add to a position.

1. Open the intended strategy at `/strategy`.
2. Confirm the strategy identity before changing policy.
3. Select the intended screener/eligibility inputs.
4. Configure relevant scoring factors and thresholds.
5. Configure any entry market gates.
6. Configure position sizing, minimum actionable amount, maximum position/holding constraints and stagger/cooldown behavior where exposed.
7. Save the configuration.
8. Run the decision pipeline later to consume the changed policy.

**Expected result:** Future evaluation can produce `OPEN_POSITION` when no strategy-owned position exists or `INCREASE_POSITION` when policy permits adding to that strategy's existing position.

---

## STR-04 — Configure exit criteria

**Goal:** Define when StoX should reduce or close a position owned by the strategy.

1. Open the strategy at `/strategy`.
2. Locate the exit-policy configuration.
3. Configure the supported exit conditions relevant to the strategy.
4. Configure stop-loss/trailing-stop/horizon rules where part of the intended policy.
5. Review precedence where several exit reasons could apply at once.
6. Save the strategy.
7. Allow a later pipeline run to evaluate existing strategy-owned holdings against the new policy.

**Expected result:** A qualifying owned position can later generate `REDUCE_POSITION` or `EXIT_POSITION` with an explainable primary reason.

**Important:** Entry market gates must not be treated as a reason to suppress a valid exit.

---

## STR-05 — Use separate entry and exit definitions

**Goal:** Use different logic for finding opportunities and deciding when an existing position should be reduced/closed.

1. Create/validate the entry discovery rule under `/screeners` if required.
2. Create/validate any separately modeled exit definition required by the supported strategy configuration.
3. Open the strategy at `/strategy`.
4. Bind/select the entry eligibility definition in the entry part of policy.
5. Configure the exit definition/policy separately.
6. Verify that the two have not accidentally been reversed.
7. Save and later run the pipeline.

**Expected result:** New-position discovery and owned-position exit policy can evolve independently while remaining part of one strategy.

---

## STR-06 — Configure factors, weights and thresholds

**Goal:** Control how qualifying candidates are scored and translated into policy outcomes.

1. Open the strategy.
2. Review the supported factor/indicator set.
3. Enable only factors the strategy intends to use.
4. Assign weights according to the intended importance. Disabled factors should not contribute.
5. Configure thresholds/labels in a valid order.
6. Review the normalized result shown by StoX where applicable.
7. Save the configuration.

**Meaning:** A factor weight changes how much that measurable factor contributes to the deterministic strategy score. A threshold maps a score/rule result to a label/policy outcome. Neither a label nor a high fit score is broker authorization.

---

## STR-07 — Configure position sizing and portfolio limits

**Goal:** Define how large a desired position may be without confusing desired size with available funding.

1. Open the strategy.
2. Configure target allocation/sizing policy.
3. Review maximum position size, maximum holdings and other portfolio constraints.
4. Review minimum actionable amount and whole-share behavior.
5. Configure staggered-entry/cooldown rules where required.
6. Save the strategy.

**Expected result:** Future recommendations can retain a desired target while separately reporting how much can actually be funded/executed. Lack of cash must not silently rewrite the investment opinion into WATCH/HOLD.

---

## STR-08 — Configure stop-loss, trailing stop and exit policy

**Goal:** Protect/manage an existing strategy-owned position using explicit exit rules.

1. Open the strategy.
2. Locate exit/risk controls.
3. Configure the intended stop-loss rule.
4. Configure trailing-stop behavior if used.
5. Configure horizon or other supported exit causes.
6. Review which reason should take precedence if multiple exit causes become true together.
7. Save the strategy.

**Expected result:** Later pipeline evaluation can generate one authoritative reduce/exit intent with explainable attribution.

---

## STR-09 — Configure market gates

**Goal:** Restrict new/additional entries under unsuitable market conditions without blocking legitimate exits.

1. Open the strategy.
2. Locate market-regime/gate configuration.
3. Configure how bullish/neutral/bearish conditions affect entry policy.
4. Save the strategy.
5. On a later recommendation, inspect whether the gate blocked or adjusted an otherwise valid entry.

**Expected behavior:** A blocked OPEN may become WATCH and a blocked INCREASE may become HOLD according to policy. REDUCE/EXIT remains eligible when an entry gate is closed.

---

## STR-10 — Edit an existing strategy

**Goal:** Change policy while preserving historical evidence of what earlier recommendations used.

1. Open `/strategy/registry` and select the intended strategy, or open it through `/strategy`.
2. Confirm its name/status and current configuration/version.
3. Make the required changes.
4. Review all affected policy sections, not only the edited field, when the change can interact with sizing/exit/capital rules.
5. Save.
6. Run the decision pipeline separately when new recommendations are desired.

**Expected result:** The new policy becomes available for future processing; prior recommendation evidence remains tied to the version/configuration that produced it.

---

## STR-11 — Change the screener without rebuilding the strategy

**Goal:** Change discovery eligibility while retaining the rest of the strategy policy.

1. Validate the replacement screener first.
2. Open the existing strategy.
3. Replace the current screener/binding with the intended definition.
4. Leave unrelated scoring, sizing and exit policy unchanged unless deliberately modifying them.
5. Save.
6. On the next pipeline run, verify candidate/recommendation evidence identifies the intended definition/version.

**Expected result:** The strategy remains the same runtime identity but future decisions use the new configured discovery input.

---

## STR-12 — Change exit policy without changing entry policy

**Goal:** Adjust how current holdings are managed while keeping entry selection stable.

1. Open the strategy.
2. Record/confirm the current entry screener and entry policy.
3. Modify only the required exit settings.
4. Review precedence with existing stop/trailing/horizon rules.
5. Save.
6. Run the pipeline later and inspect recommendations for strategy-owned holdings.

**Expected result:** Entry selection remains unchanged while future reduce/exit decisions use the new exit policy.

---

## STR-13 — Enable a strategy

**Goal:** Allow a completed strategy to participate in eligible decision-pipeline runs.

1. Open `/strategy/registry`.
2. Select the strategy.
3. Confirm its configuration is complete and valid.
4. Enable/activate it using the available lifecycle action.
5. Confirm its status is active/enabled.

**Expected result:** The strategy can run concurrently with other enabled strategies. Enabling one strategy does not require disabling another.

---

## STR-14 — Archive or disable a strategy

**Goal:** Stop future runtime use without erasing historical evidence.

1. Open `/strategy/registry`.
2. Select the strategy to retire.
3. Check for live recommendations, pending execution and owned holdings that need deliberate handling.
4. Use the supported disable/archive lifecycle action.
5. Confirm the strategy is no longer enabled for future runs.

**Expected result:** Historical recommendations/transactions remain attributable to the strategy. StoX protects lifecycle invariants such as the last-enabled-strategy rule where applicable.

---

## STR-15 — Operate multiple strategies concurrently

**Goal:** Allow independent investment hypotheses to run in the same portfolio.

1. Configure and validate each strategy independently.
2. Enable each required strategy in `/strategy/registry`.
3. Run the normal decision pipeline.
4. In `/recommendations`, inspect strategy identity on recommendations rather than treating all recommendations for a stock as one combined opinion.
5. Review/approve each actionable recommendation in its own strategy context.

**Expected result:** Each enabled strategy evaluates independently and retains its own configuration/version, recommendation evidence and holding ownership.

---

## STR-16 — Same stock used by multiple strategies

**Goal:** Correctly manage a security that two strategies independently own or recommend.

**Example:** Strategy A and Strategy B both own shares of the same company. Strategy A now generates EXIT while Strategy B remains HOLD.

1. Open the recommendation for Strategy A and confirm the strategy identity.
2. Inspect the strategy-owned position/quantity associated with Strategy A.
3. Confirm Strategy B's position remains separately attributable.
4. Review/approve Strategy A's EXIT if appropriate.
5. Execute only the quantity owned by Strategy A's holding episode.
6. After execution, verify Strategy B's holding remains intact.

**Expected result:** A strategy can reduce or exit only its own position. Same-security ownership must not blend basis, quantity, sizing, exit authority or recommendation evidence across strategies.

---

## What comes next

Once a strategy is configured and enabled, continue with [Recommendations and Review](03-recommendations-review.md).
