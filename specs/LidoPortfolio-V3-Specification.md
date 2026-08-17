# Lido Portfolio V3 Specification

| Field | Value |
|-------|-------|
| **Title** | Lido Portfolio V3 Specification |
| **Status** | Review |
| **Version** | 0.6 |
| **Owner** | Product Specification / Architecture |
| **Last Updated** | 2026-08-17 |
| **Implementation Status** | Not started |
| **Depends On** | Frozen V3 product decisions (2026-08-14 through 2026-08-17), including OD-01–OD-12; Architecture Impact Report; V1/V2 as-built baseline in `implementation.md` |
| **Referenced By** | Future V3 implementation passes |
| **Related Specifications** | [architecture/domains/Strategy-Configuration-Specification.md](architecture/domains/Strategy-Configuration-Specification.md), [architecture/portfolio/Cash-Management-Specification.md](architecture/portfolio/Cash-Management-Specification.md), [architecture/domains/Recommendation-Engine-Specification.md](architecture/domains/Recommendation-Engine-Specification.md), [architecture/governance/SPECIFICATION_DECISIONS.md](architecture/governance/SPECIFICATION_DECISIONS.md) (SD-026, SD-029, SD-010) |

---

## Document control

This is the **authoritative V3 product and architecture specification**. It converts frozen product decisions into an implementation-ready contract.

- **V3 work must follow this document**, not V1 engine specs, where they conflict.
- V1/V2 specifications remain the historical record of shipped behaviour. They are **not** rewritten here.
- The Architecture Impact Report is the **as-built baseline**, not the V3 product requirement set. Implementation limitations (for example the ~550-day OHLCV default) are **not** V3 product caps.
- Items marked **OPEN** are unresolved product decisions. Implementers must not invent a resolution.
- This specification does not implement code, schema, APIs, or UI.

**Precedence for V3 implementation:** this document > frozen V3 product decisions in the originating discussion > V1 governance (SD-xxx) > V1 engine specs > current code.

A future specification decision (SD-xxx) SHOULD formally supersede SD-029 (“exactly one active strategy per portfolio”) and the V1 unfunded→WATCH behaviour. Until that SD exists, this file is still the V3 source of truth.

---

## Contents

1. [V3 Objective and Guiding Principles](#1-v3-objective-and-guiding-principles)
2. [V3 Domain Model](#2-v3-domain-model)
3. [Portfolio and Strategy Architecture](#3-portfolio-and-strategy-architecture)
4. [Strategy Fit, Historical Evaluation and Ranking](#4-strategy-fit-historical-evaluation-and-ranking)
5. [Capital Allocation](#5-capital-allocation)
6. [Inter-Strategy Lending and Recall](#6-inter-strategy-lending-and-recall)
7. [Capital Request Workflow](#7-capital-request-workflow)
8. [Lender Selection](#8-lender-selection)
9. [Multiple Competing Strategies](#9-multiple-competing-strategies)
10. [Holdings Ownership](#10-holdings-ownership)
11. [Recommendation Generation](#11-recommendation-generation)
12. [Staggered Entry](#12-staggered-entry)
13. [Exit Model](#13-exit-model)
14. [Stop-Loss](#14-stop-loss)
15. [Trailing Stop](#15-trailing-stop)
16. [Strategy Horizon](#16-strategy-horizon)
17. [Weakest Position Selection](#17-weakest-position-selection)
18. [Historical Evaluation / Backtesting](#18-historical-evaluation--backtesting)
19. [Success Definition](#19-success-definition)
20. [Return Metrics](#20-return-metrics)
21. [Portfolio Risk and Common Controls](#21-portfolio-risk-and-common-controls)
22. [Cash Model](#22-cash-model)
23. [Recommendation Status Model](#23-recommendation-status-model)
24. [Concurrency and Revalidation](#24-concurrency-and-revalidation)
25. [Existing V1/V2 Migration](#25-existing-v1v2-migration)
26. [Chart Enhancement](#26-chart-enhancement)
27. [API Contract Changes](#27-api-contract-changes)
28. [Database Model Changes](#28-database-model-changes)
29. [UI / UX Changes](#29-ui--ux-changes)
30. [Notifications](#30-notifications)
31. [Auditability](#31-auditability)
32. [Backward Compatibility and Compatibility Risks](#32-backward-compatibility-and-compatibility-risks)
33. [Decision Log and Open Decisions](#33-decision-log-and-open-decisions)
34. [Implementation Sequence](#34-implementation-sequence)
35. [Acceptance Criteria](#35-acceptance-criteria)

---

## 1. V3 Objective and Guiding Principles

### 1.1 Ultimate objective

The ultimate objective of Lido Portfolio V3 is **improved investment returns**, not maximising strategy-fit scores, not maximising the number of recommendations, and not maximising capital utilisation for its own sake.

Strategies are **hypotheses / tools** for generating candidate opportunities. They are not the final optimisation objective. A high fit score is evidence that a stock matches the hypothesis. It is not proof that deploying capital will produce a good return.

### 1.2 Fit versus ranking versus outcome

| Concept | Meaning | Must not be treated as |
|---------|---------|------------------------|
| **Strategy fit score** | How well a stock matches the strategy’s scoring model right now | Final investment ranking; expected return; probability of success |
| **Historical outcome analysis** | How positions (or simulated positions) at comparable fit levels actually performed | A replacement for fit scoring |
| **Expected / observed return quality** | Magnitude-aware aggregation of historical returns (see §4, §19, §20) | Hit-rate / “probability of success” alone |
| **Final ranking** | Ordering of opportunities for capital attention, based on return quality | A sort by fit score |

V3 **rejects** ranking by `Strategic Score × Probability` when probability is derived from the same score, and **rejects** treating a 100% historical return and a 10% historical return as equivalent successes.

### 1.3 Architectural principles

1. **Portfolio is the account.** One portfolio is one real or paper trading account. Multiple portfolios may exist for hypothesis testing. Broker automation may later constrain how many portfolios can be live; V3 does not implement broker automation (SD-010 remains: manual execution).
2. **Many strategies may run concurrently in one portfolio.** V3 supersedes the V1 “exactly one active strategy per portfolio” product rule (SD-029).
3. **Separation of concerns.** Strategy identifies candidates, scores fit, sizes a *target* position from conviction, and emits strategy-specific BUY/SELL/HOLD/EXIT. Portfolio owns cash reserve, common stop-loss, common trailing stop, portfolio position/risk limits, and inter-strategy capital coordination.
4. **Ownership fences.** A strategy may not reduce or exit a holding it does not own. Manual holdings are unmanaged until adopted.
5. **A recommendation can be valid and unfunded or only partially funded.** Lack of full capital must not convert a BUY/INCREASE into WATCH. Partial funding is allowed (OD-05).
6. **Lending is always an explicit user decision.** Displaying options does not commit capital. The backend is authoritative at approval time.
7. **Daily close only** for portfolio stop-loss and trailing stop. No intraday low.
8. **Horizon is optional** and, when set, is measured in **calendar days** (OD-02). Absence of a horizon is not an expiry event.
9. **Do not turn current implementation limits into product requirements.**
10. **Multiple strategies may own the same stock** in one portfolio (OD-01). Position, cost, trailing, exit, allocation, attribution, and corporate-action **ownership** are per owner, not per symbol alone (OD-10). Holdings of the same stock MUST NOT be blended into one portfolio-level position for those purposes.
11. **Ranking statistics use the defined backtest corpus only** (OD-03). Live-trading history is not a ranking observation source.

### 1.4 As-built baseline (informational)

V1/V2 today: one active strategy drives generation; strategy `config_json` owns cash reserve, position caps, and exits; unfunded OPEN/INCREASE are demoted to WATCH; holdings have no strategy owner; trailing stop in the exit engine is an unrealized-% proxy; the holdings chart loads bars since first buy; stored OHLCV campaign default is ~550 days. **None of those are V3 requirements.** They are the starting point to change.

---

## 2. V3 Domain Model

### 2.1 Portfolio

An account-level container (`PortfolioProfile` today). It represents **one** real or paper trading account.

A portfolio owns:

- the cash ledger and a single physical cash pool
- holdings (strategy-owned and unmanaged)
- portfolio common controls (§21)
- the set of strategies that may operate in it
- inter-strategy lending coordination
- transactions, recommendations, and audit records

A user may have **multiple portfolios**. Each is an independent ledger. V3 does not require a live/paper schema flag. Future broker automation may allow only one live portfolio; that exclusivity is **out of V3 implementation scope** and is recorded as a future constraint, not a current engine rule.

### 2.2 Strategy

A named investment hypothesis attached to a portfolio. It is a tool for:

- identifying eligible candidates (via Screeners; SD-030 eligibility ownership is unchanged)
- computing **strategy fit**
- generating BUY / INCREASE / REDUCE / EXIT / HOLD recommendations
- determining **target position size** from conviction (within portfolio and strategy diversification caps)

A strategy does **not** own:

- portfolio cash reserve
- common stop-loss
- common trailing stop
- portfolio-wide position/risk policy
- inter-strategy lending policy (it participates; the portfolio coordinates)

Multiple strategies MAY be concurrently **enabled** in one portfolio. “Enabled” in V3 replaces V1 exclusive `STATUS_ACTIVE`.

### 2.3 Strategy version

An immutable-enough configuration snapshot (`TradingStrategyVersion` / `config_json`) referenced by recommendations, holdings adoption events, and evaluation. Saving a strategy still records which version produced a decision. V1 in-place save of the active version may continue as a persistence mechanic; V3 does not require a product-facing version-fork UI.

Strategy version configuration in V3 contains strategy-specific parameters only (eligibility, scoring, thresholds, strategy-specific exits, optional horizon, staggered-entry %, BUY cooldown of **1 calendar day** (OD-11), min/max holdings, conviction bands). It must **not** contain portfolio-wide cash/risk controls.

### 2.4 Holding

A position in a stock **owned by one owner** within a portfolio.

**OD-01 (frozen): ALLOW.** Two or more strategies in the same portfolio MAY independently own the same stock.

Example: Strategy A holds RELIANCE 50 shares and Strategy B holds RELIANCE 30 shares. The portfolio owns 80 shares in aggregate; ownership, cost basis, trailing stop, exits, target/filled, and P/L attribution remain **per strategy**.

V1 aggregates one row per `(profile, stock)`. That uniqueness **must not** be retained if it would prevent strategy-level ownership. V3 identity of a holding is:

- strategy-owned: `(portfolio, stock, strategy_id)`
- unmanaged: `(portfolio, stock, unmanaged)` — at most one unmanaged position per stock per portfolio (use a dedicated unmanaged owner key / non-null sentinel so the unique constraint is enforceable)

A holding has quantity, cost basis, owner (strategy or unmanaged), and when strategy-owned: **target amount** (source of truth, OD-12), derived whole-share quantity, filled amount/quantity, and entry date for trailing/stop calculations. These fields MUST NOT be blended across owners of the same symbol. Target quantity is **not** the primary persisted unit.

**OD-10 (frozen):** corporate-action quantity follows the **parent holding’s owner**. A CA applicable to a holding identified by `(portfolio, stock, owner)` adjusts **that** owner’s position quantity. It MUST NOT first blend multiple owners of the same stock into one portfolio-level position. Unmanaged parent holdings stay unmanaged. OD-10 does not freeze CA mathematics, cost/trailing/target restatement, or price-series choice (OD-14 remains OPEN).

### 2.5 Unmanaged holding

A holding that is **not** owned by any strategy.

- All newly created **manual** holdings start unmanaged.
- Existing V1/V2 holdings become unmanaged on migration unless ownership can be **safely inferred** (§10.5, §25).
- Unmanaged holdings are **not** subject to strategy exit/reduce/increase logic.
- Unmanaged holdings **are** subject to portfolio common stop-loss and trailing stop (§14, §15), because those are account-level controls, not strategy controls.
- An unmanaged holding may be **adopted** into exactly one strategy.

### 2.6 Strategy-owned holding

A holding whose owner is exactly one strategy. Only that strategy may emit REDUCE/EXIT/INCREASE against **its** quantity. Another strategy’s quantity in the same stock is a **different holding**.

Portfolio stop-loss, trailing stop, and that strategy’s own horizon expiry may also force EXIT on **that owner’s** position with the corresponding attribution. They MUST be evaluated per holding, not on the blended portfolio quantity of the symbol.

Gains and losses on the position are attributed internally to that owning strategy.

### 2.7 Recommendation

A strategy decision about one stock: action, security, target/quantity, fit score, ranking inputs, capital state, and evidence.

A recommendation is **valid** when the strategy’s decision logic produced it. Validity does **not** require that the strategy currently has enough cash to fund the full target or the full this-cycle slice.

WATCH remains a genuine informational action (not a BUY). WATCH must not be used as a synonym for “BUY but no cash” or “BUY but only partial cash”.

### 2.8 Portfolio cash

The single physical cash pool of the portfolio (ledger-backed, as today). Not subdivided into per-strategy bank accounts.

### 2.9 Strategy allocation

A **policy share** of the portfolio’s investable capital, expressed as a percentage. It is a virtual claim, not a physical sub-account.

Strategy allocations in a portfolio are percentage-based and **must sum to 100%** of investable capital (investable capital defined in §22). This matches the frozen rule that remaining investable capital is allocated among strategies.

Allocation percentages are **user-configured policy**. They are not automatically rewritten because today’s signal count changed.

### 2.10 Available-for-lending capital

The portion of a strategy’s unused allocation that is eligible to be lent under §6 and §8. It is **not** “all cash the strategy could theoretically spend” and **not** “maximum available portfolio cash”.

Available-for-lending **percentage** and available-for-lending **absolute amount** are the OD-08 ranking keys for a prospective **lender** (a strategy). They MUST NOT be used to choose among outstanding **loans** (that is OD-09).

### 2.11 Lent capital

Capital of a lender strategy that has been **committed and transferred as a loan** to a borrower strategy. It remains economically part of the lender’s allocation claim, but is not available for the lender’s new buys until returned.

An outstanding loan is a discrete committed amount with a lender, a borrower, a **commitment time**, and remaining principal. OD-09 uses commitment time (FIFO) to select among recall-eligible loans. That operation is not lender ranking.

### 2.12 Borrowed capital

Capital a borrower strategy has received via an approved loan. It increases the borrower’s immediately usable capital for the funded purpose and remains outstanding until returned (in atomic blocks).

### 2.13 Capital request

A user-visible request to borrow a specific atomic-aligned amount from **exactly one** eligible lender, typically to fund a specific unfunded recommendation. Displaying a request does not reserve capital.

V3 does **not** allow one recommendation’s funding gap to be split across multiple lenders.

### 2.14 Capital approval

The user act that, after backend revalidation, commits a capital request. Only then is committed-to-lending / lent capital updated.

### 2.15 Exit event

The execution-level fact that a position (or the recommended quantity) is to be sold in full (EXIT) or in part (REDUCE). Broker/ledger execution is the same class of event regardless of why it was recommended.

### 2.16 Exit attribution

The ordered reason recorded for analytics, reporting, and historical evaluation. Precedence is defined in §13. Attribution is not a different broker order type.

---

## 3. Portfolio and Strategy Architecture

### 3.1 Concurrent strategies

Multiple strategies MAY operate concurrently in one portfolio.

Each enabled strategy:

- has its own eligibility screeners, scoring model, thresholds, and strategy-specific exits
- generates its own recommendations
- owns only its own holdings (the same stock may also be owned by other strategies)
- receives a configured allocation percentage

The daily decision pipeline MUST run generation **per enabled strategy** (sequentially or otherwise), not once for a single “the” active strategy. Cancelling stale recommendations MUST be scoped to that strategy, not the whole portfolio.

V1 exclusive activate-and-archive-others is **removed** for V3. Enabling a second strategy must not archive the first. A strategy may still be disabled/archived by the user.

### 3.2 Portfolio as account-level container

Portfolio remains the isolation boundary for cash, holdings, transactions, and API scoping (`X-Profile-Id`). Strategies never span portfolios.

### 3.3 Ownership of decisions

| Concern | Owner |
|---------|--------|
| Candidate identification (Screener eligibility) | Strategy (via Screeners) |
| Fit score | Strategy |
| Strategy-specific EXIT/REDUCE/HOLD (score, RS, MA, screener exit, ATR stop, etc.) | Strategy |
| Target position from conviction | Strategy |
| Staggered entry and BUY cooldown | Strategy |
| Optional horizon | Strategy |
| Min holdings (recommended) / max holdings (hard) | Strategy |
| Allocation % | Portfolio policy, per strategy |
| Cash reserve | Portfolio |
| Stop-loss | Portfolio |
| Trailing stop | Portfolio |
| Portfolio-wide position/risk caps | Portfolio |
| Lending / recall coordination | Portfolio (between strategies) |
| Execution (manual ledger fill; future broker) | Portfolio / execution engine |

### 3.4 Strategy allocation percentage

Each enabled strategy has `allocation_pct`. Sum across enabled strategies = 100% of **investable capital** as defined in §22 (strategy-owned MV + investable cash, **excluding** unmanaged MV).

Changing allocation percentages is a user policy change. It does not by itself force immediate sells. Effects on lendable surplus and available capital are computed from the new policy at the next capital calculation. Forced de-risking because allocation was reduced is **not specified** here and must not be invented (if needed later, add an OPEN or a new decision).

### 3.5 Minimum and maximum holdings

- **Recommended minimum holdings:** advisory. The engine MUST NOT open weak/low-quality names solely to hit the minimum. UI MAY warn when below the recommended minimum.
- **Hard maximum holdings:** a hard cap on the number of **strategy-owned** open names for that strategy. Generation MUST NOT emit a new OPEN that would exceed it. INCREASE of an existing owned name is not a new holding.

Maximum position allocation **derives from diversification**: by default a single name MUST NOT exceed `1 / max_holdings` of **that strategy’s** allocated capital. An optional tighter explicit cap MAY be configured. Portfolio-level max position size, if configured, is a ceiling on **aggregate** portfolio exposure to that symbol (all strategy-owned lots plus unmanaged). The tighter applicable ceiling wins for that owner’s sizing. Strategy A’s RELIANCE and Strategy B’s RELIANCE count as **two** strategy holdings (one each) toward each strategy’s max-holdings cap, and as **one** symbol toward portfolio-level exposure.

### 3.6 Optional strategy horizon

A strategy MAY define a horizon. A strategy MAY omit a horizon.

- With a horizon: after the horizon elapses, horizon expiry is an eligible EXIT mechanism (§13, §16).
- Without a horizon: the strategy may remain invested indefinitely until strategy exit criteria, stop-loss, or trailing stop trigger.

No horizon MUST NOT be interpreted as an infinite mandatory expiry.

**OD-02 (frozen):** Horizon length `T` is measured in **calendar days**, not trading sessions. `T = 30` means 30 calendar days after the holding’s entry date (ownership-episode start). Opportunity-cost period length in §19.1 uses the same calendar-day convention (`T_years = calendar_days / 365`).

---

## 4. Strategy Fit, Historical Evaluation and Ranking

### 4.1 Fit score (unchanged meaning, changed use)

**Fit score** = how well this stock matches this strategy’s scoring model on the evaluation facts (V1 `StrategyConfigurationService::score()`, 0–100). Soft min/max gates and weight dilution remain valid scoring mechanics.

Fit score:

- MAY still gate OPEN/INCREASE/REDUCE/EXIT/WATCH **thresholds** (strategy-specific decision bands)
- MUST NOT be the final ranking key for which opportunities receive capital attention
- MUST NOT be multiplied by a probability derived from itself

### 4.2 Historical outcome analysis

For a strategy, historical outcomes are collected from the **defined backtest corpus only**, bucketed by **fit level** (fit bands). Each outcome has return, holding period, benchmark return, annualized/XIRR return, and a success flag (§19).

**OD-03 (frozen): BACKTESTS ONLY.** Ranking statistics MUST use the defined backtest corpus. The user’s **live-trading history MUST NOT** be used as ranking observations. `ReviewEngine` live/ledger outcomes remain observational portfolio review and MUST NOT be fed into ranking.

The current `ReviewEngine` is **not** the V3 ranking engine. Extending the strategy backtest simulator so it can supply a sufficient, defined corpus is in scope for ranking.

### 4.3 Return quality, not hit rate

Final ranking MUST be based on **expected / observed return quality** — the magnitude of returns associated with that fit level — not on how often outcomes were merely positive.

A 100% historical return and a 10% historical return are not equivalent successes.

“Probability of success” (frequency of passing §19) MAY be reported as a diagnostic. It MUST NOT be the final ranking metric.

Success criteria MAY differ by strategy only in evaluation-window / horizon configuration (§4.5, §16, §20). The **broad** success definition in §19 is shared unless a future decision replaces it. V3 does **not** impose a single absolute return target (for example “must make 30%”) across strategies.

### 4.4 Aggregation

Mean, median, and trimmed mean are statistical choices.

**OD-04 (frozen):** where aggregation is required for fit-band expected return / ranking, use a **symmetric trimmed mean**:

- Trim **7%** from the **lower** tail.
- Trim **7%** from the **upper** tail.
- Trimming is **symmetric**.
- **Minimum sample size = 15** observations.

If fewer than 15 observations are available for that fit band, the trimmed-mean ranking calculation is **not eligible**. Do not compute a trimmed-mean rank from a smaller sample. Do not silently fall back to ranking by fit score (§4.6).

**Procedure (normative):**

1. Collect the backtest return-quality observations for the fit band (`n` values).
2. If `n < 15`, stop: ranking for this band is not eligible.
3. Sort the `n` observations in ascending order.
4. Let `k` be the integer number of observations removed from **each** tail corresponding to 7% of `n`.
5. Compute the arithmetic mean of the remaining middle observations.

How 7% of `n` is converted to integer `k` (floor vs round vs ceil) was **not frozen** — see §33.3 dependency **DEP-TRIM-K**. Do not treat an invented `k` rule as product law; the 7%/7%/n≥15 rules above are product law.

Trimming MUST be deterministic once `k` is defined (same inputs → same output).

### 4.5 Strategies without a fixed horizon

- Prefer **XIRR / annualized return** over the actual holding period (§20).
- Do **not** penalise a holding merely because it was held a long time before the thesis began working (lifetime XIRR can be mediocre while recent/windowed performance is strong).
- Historical evaluation windows MUST be **strategy-configurable**.
- A configurable **rolling evaluation period** MAY be used for ranking current holdings and for weakest-position selection (§17).

### 4.6 Ranking pipeline (normative)

```text
Fit score (eligibility + decision thresholds)
    ↓
Historical outcomes at comparable fit (**backtest corpus only**)
    ↓
Return-quality aggregation (trimmed mean of returns / annualized returns as specified)
    ↓
Final ranking of opportunities
```

Capital allocation among a strategy’s own buy drafts, once ranked, still respects cash, max holdings, staggered entry, partial funding (OD-05), and portfolio caps. Allocator weights MUST follow **ranking order / return quality**, not raw fit score. (V1 `ScorePriorityCapitalAllocator` weighting by fit is **not** V3 behaviour.)

If trimmed-mean ranking is not eligible (`n < 15` for the relevant band, or backtest corpus not yet available), the UI MUST present fit as fit, not as rank. Treating “rank = fit” as a silent fallback is forbidden.

Conviction **target sizing** (score bands → target position, subject to caps) remains a strategy function and is **not** the same as ranking. When return-quality ranking is not yet computable, capital **fill order** among that strategy’s own valid BUYs is **OPEN (OD-23)** — implementers MUST NOT label fill-by-fit as V3 ranking.

---

## 5. Capital Allocation

### 5.1 Portfolio-level capital

See §22. Physical cash is one pool. Investable capital is what remains after the portfolio cash reserve (and after cash already reserved for pending-execution buys).

### 5.2 Strategy-level allocation percentages

Enabled strategies receive `allocation_pct` summing to 100% of investable capital.

**Strategy allocated capital** (virtual) =

`investable_capital × allocation_pct / 100`

**Strategy deployed capital** includes:

- market value of strategy-owned holdings
- cash reserved for that strategy’s pending-execution buys
- capital currently lent out
- capital committed-to-lending (approved, not yet settled into lent — if those are distinct steps, both reduce availability)

**Strategy unused allocation** =

`max(0, allocated − deployed)`

Unused allocation is the starting point for own buys and for lendable surplus. It is not automatically isolated cash.

### 5.3 No permanent isolated cash bucket

A strategy does **not** receive a physical sub-account. All rupees sit in portfolio cash until a buy executes or a reservation is recorded. Allocation is a **constraint and accounting claim**.

### 5.4 Lending of unused allocation

A strategy MAY lend otherwise unused allocation to another strategy, subject to §6–§8. Lending is portfolio-level coordination, not a private side-deal outside the portfolio.

### 5.5 Minimum free capital (“one opportunity”)

A strategy SHOULD retain sufficient **free** unused capital for at least one opportunity, according to configured policy.

That retained amount is **not lendable**.

The exact formula for “one opportunity” (for example one maximum diversified position vs one minimum position vs a configured rupee amount) remains **OPEN (OD-24)** — this was previously listed as OD-05 before that ID was reassigned to partial funding. The requirement that such a configurable minimum free amount exists, and that lending cannot consume it, is frozen.

**OD-05 (frozen): PARTIAL FUNDING IS ALLOWED** for deploying a strategy’s **own** available free capital into an OPEN/INCREASE opportunity:

- If desired/required capital exceeds currently available free capital, the allocator MAY use the available free capital to take a **partial** position.
- Example: desired allocation = ₹18,000, available free capital = ₹10,000 → allocate ₹10,000. Do **not** convert the opportunity to WATCH.
- Capital status remains a separate axis from opportunity direction (§23).
- Partial funding is the this-cycle executable amount; the persisted **target amount** is unchanged (OD-12). Remaining BUY/INCREASE still follows staggered-entry rules (§12) and OD-11. Do **not** reset the target to the amount funded this cycle.
- If available free capital is **zero**, the recommendation stays a valid OPEN/INCREASE with `UNFUNDED` and may enter the lending workflow (§7). It still MUST NOT become WATCH.

Partial funding does not waive portfolio reserve, pending-execution reservations, or ownership fences.

### 5.6 Atomic capital block and execution-price margin

**OD-06 (frozen):**

- Atomic capital block = **₹5,000**.
- Apply a **1% execution-price margin** before rounding to the atomic block.
- Round **UP** to the next ₹5,000 block.

```text
adjusted_requirement = calculated_requirement × 1.01
atomic_allocation     = ceil(adjusted_requirement / 5000) × 5000
```

Examples (normative):

| calculated_requirement | × 1.01 | atomic_allocation |
|------------------------|--------|-------------------|
| ₹23,700 | ₹23,937 | ₹25,000 |
| ₹25,000 | ₹25,250 | ₹30,000 |
| ₹19,000 | ₹19,190 | ₹20,000 |
| ₹4,000 | ₹4,040 | ₹5,000 |

**What atomic_allocation is:** a **capital reservation / allocation** amount. It is **not** necessarily the amount ultimately invested. It is **not** the position **target amount** (OD-12). Example: this-cycle requirement ₹18,000 → atomic reservation ₹20,000; the target remains ₹18,000 (or the persisted position target, whichever applies). Do not replace the target with the atomic amount.

**What the 1% margin is not:** it is **not** money that must automatically be invested. It exists so a reservation is large enough if execution occurs above the reference price.

**After execution / reconciliation:** unused reserved capital MUST remain or revert to available capital per §22. Actual fills may be above or below the reference price.

**Lending / borrowing / recall** amounts MUST be integer multiples of ₹5,000. Lending cannot be split below the block. Borrowing cannot request less than one block.

**Interaction with OD-05:** if `atomic_allocation` is larger than available free capital, **partial funding is allowed**. Allocate the available free capital (this-cycle), keep OPEN/INCREASE, do not convert to WATCH. Own-capital partial amounts are **not** required to be re-ceiled through the 1% formula in a way that exceeds available cash (that would defeat partial funding). Lending of a remainder still cannot be below ₹5,000.

A this-cycle gap that is entirely unfunded from own cash, and whose atomic_allocation is ≥ ₹5,000, may use the lending path. A remainder smaller than one atomic block cannot be filled by lending; the rec stays valid and the leftover stays on the persisted target for later cycles.

### 5.7 Lending limits

Maximum lending (percentage of unused allocation and/or absolute amount) MUST be configurable at portfolio level. Lending cannot use the portfolio cash reserve. Lending cannot use cash reserved for pending execution or already committed-to-lending.

### 5.8 Allocation stability

The portfolio MUST NOT continuously rewrite strategy `allocation_pct` because the number of today’s signals changed. Signal volume affects recommendations and lending demand, not the policy split.

---

## 6. Inter-Strategy Lending and Recall

### 6.1 Permission to lend

Lending is allowed. It is never automatic.

- Every lending request is presented to the user.
- There is **no** automatic exception when the rest of the portfolio is otherwise automated.
- The user always approves or rejects.

### 6.2 What the user sees

- Only **eligible** lenders are listed (§8).
- Ineligible strategies MUST NOT appear as selectable lenders.
- Default order after eligibility filtering (**OD-08**): available-for-lending **percentage** descending, then available-for-lending **absolute amount** descending; if both values are still exactly equal, any tied eligible lender may be selected.
- The user MAY select another **eligible** lender.
- The user MUST NOT be able to select an ineligible lender.

**Available-for-lending percentage** is the primary ranking metric. **Available-for-lending amount** is the secondary ranking metric (OD-08). Neither is “maximum available cash” / raw portfolio cash. FIFO / oldest loan is **not** a lender-ranking rule.

### 6.3 Commitment timing

- Generating or displaying lender options MUST NOT change committed-to-lending.
- Pending UI state MUST NOT reserve capital.
- Commitment happens **only** on approval, and only if backend revalidation succeeds.

### 6.4 Approval-time revalidation

The backend MUST revalidate lender capacity atomically at approval:

- requested amount ≥ ₹5,000 and a multiple of ₹5,000 (OD-06)
- lender still eligible
- available-for-lending ≥ requested amount
- portfolio reserve still protected
- no use of reserved/committed capital

If revalidation fails, approval MUST fail with an explicit error. The user MAY choose another currently eligible lender or reject the request. Frontend eligibility at display time is never authoritative.

Concurrent/stale requests MUST be allowed to fail safely (see §24).

### 6.5 Minimum lending period and recall frequency

Once capital is lent, it MUST NOT be recalled before the **effective** minimum lending / recall-eligibility period.

**OD-07 (frozen):** recall frequency is **configurable at two levels**:

1. Platform-level default
2. Portfolio-level override

```text
Effective period = Portfolio override if set, else Platform default
```

If a portfolio has no override, it inherits the platform setting.

The previously frozen **14-day** figure remains applicable as the **shipped platform default**. It MUST NOT be replaced by a hard-coded non-configurable engine constant that ignores these settings. The allowed platform default/range remains a configuration concern (not an additional frozen rupee/day number beyond the 14-day default).

Period length is in **calendar days** (consistent with OD-02).

Clock start: the timestamp of successful capital commitment (approval), unless a later settlement timestamp is introduced; do not invent a second clock.

Whether a portfolio override may be **shorter** than 14 calendar days is **not frozen** — see §33.3 **DEP-RECALL-FLOOR**.

### 6.6 Recall

A lender strategy MAY request return of borrowed capital when it needs capital for a new opportunity (replenishment, §6.7) or when the user initiates recall.

- Recall MUST respect the effective minimum lending period (§6.5). **OD-07** controls **when** a loan becomes recall-eligible.
- If multiple outstanding loans are recall-eligible, **which loan is selected** is **OD-09** (§6.7, §9.7): oldest eligible loan first (FIFO by commitment time). That is not lender ranking (OD-08).
- Return/recall **requires user approval by default**.
- A user-configurable option for **automatic return** MAY exist; it is off unless the user enables it. Frozen governance: default is approval required.
- Returning capital MAY require the borrower to exit one or more positions if the borrower lacks free cash to repay.
- The borrower MUST release the **weakest eligible** positions first (§17).
- “Weakest” MUST NOT be defined as lowest lifetime XIRR, lowest total return, or oldest holding alone. OD-16 remains OPEN; this section does not resolve it.

### 6.7 Replenishment

When a strategy consumes its own immediately available capital (including its minimum-opportunity free capital) on a new opportunity, it SHOULD request the **minimum** required capital back from outstanding loans as soon as recall is eligible.

- First filter to loans that are eligible for recall under the existing V3 recall / effective-minimum-period rules (§6.5, OD-07).
- **OD-09 (frozen):** among those eligible outstanding **loans**, select the **oldest** first (**FIFO**) by **commitment time**. If two or more eligible loans have exactly the same commitment time, any tied loan may be selected (implementation may resolve the exact tie arbitrarily or deterministically). There is no additional business ranking after FIFO.
- Do **not** introduce largest-loan-first, smallest-loan-first, lender percentage, lender absolute lendable amount, borrower strength, or strategy ranking as loan-selection rules.
- **OD-06** still controls the **amount** recalled: request the minimum required atomic amount (`× 1.01` then ceil to ₹5,000), not exceeding the selected loan’s outstanding principal.
- Do **not** recall an entire loan merely because it is the oldest if only a smaller amount is required.
- If the selected loan’s outstanding is less than the remaining replenishment need, continue with the next oldest eligible loan (same FIFO order) until the OD-06 minimum is satisfied or no eligible loans remain. That continuation is FIFO order, not a size-based ranking rule.
- If a borrower must sell positions as a consequence, §17 and **OD-16** remain applicable. Do not resolve OD-16 here.

**Example (normative illustration):** Momentum has ₹12,000 reserved as its minimum opportunity capacity, spends that ₹12,000 on a new opportunity, and the effective lending period has expired. Required back = ₹12,000 → × 1.01 = ₹12,120 → atomic ₹15,000, capped by outstanding principal. If several recall-eligible loans exist, tap the oldest by commitment time first (OD-09) for that ₹15,000 (or less if that loan’s outstanding is smaller).

### 6.8 Safeguards (normative list)

1. Effective minimum lending / recall-eligibility period (platform default 14 calendar days, portfolio override, OD-07)
2. Configurable lending limits
3. Minimum free capital (not lendable)
4. Atomic capital block ₹5,000 with 1% margin then ceil (OD-06)
5. Explicit user approval (default for lend and recall)
6. Backend revalidation
7. No split of one request across lenders
8. No lending of portfolio reserve
9. No lending of committed or reserved capital
10. No commitment on display
11. Partial own-capital funding does not convert OPEN/INCREASE to WATCH (OD-05)
12. Default **lender** ranking is available-for-lending % descending, then available-for-lending amount descending, then arbitrary exact tie (OD-08). FIFO is not a lender-ranking rule.
13. Replenishment **loan** selection among recall-eligible outstanding loans is FIFO by commitment time, then arbitrary exact tie (OD-09). Amount recalled remains OD-06.

### 6.9 Outstanding loans

- One capital request is fulfilled by **exactly one** lender and one ₹5,000-aligned amount (after OD-06).
- Sequential loans for **different** recommendations MAY exist (a borrower is not limited to a single lifetime loan).
- Combining two lenders to fund **one** recommendation is forbidden in V3.

---

## 7. Capital Request Workflow

### 7.1 Frozen rule

A recommendation that lacks sufficient **own** capital to fund the **full** this-cycle desired amount remains a **valid** recommendation. It MUST NOT be converted to WATCH because it lacks full funding (OD-05).

- If some own free capital is available: **partial funding** — allocate that capital, keep OPEN/INCREASE.
- If no own free capital is available: `UNFUNDED` OPEN/INCREASE; lending may be offered.
- Direction (OPEN/INCREASE) and capital status are separate axes.

### 7.2 End-to-end flow

```text
Strategy detects opportunity
    ↓
Strategy generates a normal recommendation (action remains OPEN / INCREASE)
    ↓
Apply staggered entry (§12) → this-cycle calculated_requirement (amount)
    (whole-share qty derived from latest daily close; min actionable §12.4)
    ↓
    If this-cycle opportunity < effective min actionable: no new OPEN/INCREASE
    (target amount unchanged)
    ↓
atomic_allocation = ceil((calculated_requirement × 1.01) / 5000) × 5000
    (reservation only; does not replace target amount)
    ↓
Calculate own available free capital
    ↓
If own free ≥ atomic_allocation:
    reserve atomic_allocation (not an instruction to invest the 1% margin)
    capital_status = FUNDED
If 0 < own free < atomic_allocation:
    allocate own free (partial position, OD-05)
    capital_status = PARTIALLY_FUNDED
    remaining **target amount** persists (§12); do not reset target to the funded slice
    optional lending path only for an unfunded remainder whose OD-06 size ≥ ₹5,000
      (whether that remainder opens a capital request this cycle: DEP-PARTIAL-LEND)
If own free = 0:
    capital_status = UNFUNDED
    recommendation remains the same BUY/INCREASE action
    ↓
    Determine required borrowed capital = atomic_allocation of the unfunded gap
    ↓
    If that amount < ₹5,000:
        remain UNFUNDED; no lending path
    ↓
    Find eligible lenders
    ↓
If none:
    remain UNFUNDED; no selectable lenders; user is informed
    ↓
If some:
    Rank eligible lenders (OD-08): available-for-lending % descending,
    then available-for-lending amount descending; exact ties arbitrary
    capital_status = AWAITING_LENDER_SELECTION
    Present options (no reservation)
    ↓
User selects lender and approves, or rejects lending
    ↓
If rejected:
    remain UNFUNDED (or PARTIALLY_FUNDED if own slice already allocated);
    rec stays valid; user may later retry or reject/defer the rec
    ↓
If approved:
    Backend revalidates atomically
    ↓
If revalidation fails:
    error; capital_status returns to AWAITING_LENDER_SELECTION or UNFUNDED
    with refreshed eligible-lender list; no commitment
    ↓
If revalidation succeeds:
    Commit lending amount (lent / borrowed / committed-to-lending updated)
    capital_status = CAPITAL_COMMITTED then FUNDED for execution purposes
    ↓
Recommendation is capital-ready
    ↓
Normal execution workflow (user trade approval → pending_execution → cash
reservation for the buy → manual/broker fill)
```

The reservation amount is `atomic_allocation` (or the partial own amount). Execution invests shares at the fill price; unused reservation reverts to available capital. The 1% margin is not an instruction to buy more shares.

Sells/exits never enter this lending path. They do not require borrow. BUY cooldown does not apply to REDUCE/EXIT/HOLD (**OD-11**). BUY cooldown is **not** the OD-07 lending recall period.

### 7.3 Required outcomes (no extra async invented)

| Situation | Required behaviour |
|-----------|-------------------|
| No eligible lender | Rec stays valid UNFUNDED. No lender UI choices. Notify capital required. Recompute lenders on next generation or explicit refresh — **not** via an unspecified background daemon. |
| User rejects lending | Rec stays valid UNFUNDED. Capital request = rejected (audited). Rec may still be deferred/rejected separately. |
| Selected lender becomes ineligible before approval | Approval fails revalidation. Refresh eligible list. User picks another eligible lender or rejects. |
| Selected lender no longer has enough lendable capital | Same as revalidation failure. |
| Borrower no longer needs the capital (rec cancelled, expired, rejected, or own cash appeared before commit) | In-flight request cancelled; nothing committed. If already committed but **not** executed, **release** the loan (return capital to lender). If already executed, loan remains until recall. |
| Multiple borrowers compete for the same lender | Both may *see* the lender at display time. First successful approval wins. Later approval fails revalidation (§24). |
| Capital becomes available after a delay (deposit, sale, returned loan, allocation change) | Rec remains UNFUNDED or PARTIALLY_FUNDED until a **recompute** (generation or explicit refresh) increases allocated own capital or offers lenders again. No silent auto-approve. |

Do not invent periodic jobs that auto-commit lending. Recompute points are: strategy generation, user refresh of the recommendation/capital panel, and approval-time revalidation.

### 7.4 Execution interaction

- Trade approval MUST NOT move a BUY to `pending_execution` while `capital_status` is `UNFUNDED` or awaiting lending. `PARTIALLY_FUNDED` and `FUNDED` MAY proceed for the allocated slice.
- Once FUNDED, PARTIALLY_FUNDED, or CAPITAL_COMMITTED, reservation-on-trade-approval applies to the **reserved allocation amount** (atomic_allocation or partial own amount), using portfolio available cash. Borrowed capital is already reflected in accounting so that available cash checks succeed.
- Double reservation (lending commit + a second reservation of the same rupees without converting state) is forbidden. Implementation MUST keep a single authoritative reserved/lent ledger (§22, §28).
- After fill, unused reservation (including unused 1% margin) reverts to available capital.

---

## 8. Lender Selection

### 8.1 Eligibility filters (all must pass)

A strategy L is eligible to lend amount `A` to borrower B when:

1. L ≠ B
2. L is enabled in the portfolio
3. `A` ≥ ₹5,000 and `A` is a multiple of ₹5,000 (OD-06)
4. L’s **available-for-lending** ≥ `A`
5. After the loan, L would still hold its minimum free capital
6. `A` does not include portfolio reserve
7. `A` does not include cash reserved for pending-execution or already committed-to-lending
8. Configurable lending limits would not be exceeded
9. L has no conflicting in-flight constraint that reduces lendable below `A` at **calculation** time (display); approval will re-check

Ineligible strategies MUST be omitted from the selection list entirely.

### 8.2 Available-for-lending calculation

```text
available_for_lending =
  floor_to_₹5000(
    max(0,
      unused_allocation
      − minimum_free_capital
      − already_lent
      − already_committed_to_lending
    )
  )
```

Do **not** apply the 1% execution margin to lendable surplus. The 1% applies to **requirements** (how much to reserve/borrow/recall), not to how much a lender can offer.

capped by configured lending limits.

**Unused allocation** is defined in §5.2. It is not “all portfolio cash”.

**Available-for-lending percentage** (OD-08 **primary** ranking key) =

`available_for_lending / allocated_capital` (0 if allocated_capital is 0)

**Available-for-lending amount** (OD-08 **secondary** ranking key) =

the same `available_for_lending` rupee figure from the formula above (already ₹5,000-aligned).

Neither key is maximum available cash / raw portfolio cash. Ranking MUST NOT use the portfolio cash ledger balance as a sort key. These keys rank **lenders**, not outstanding loans.

### 8.3 Committed-to-lending

Increases **only** when approval succeeds. Display, preview, and pending UI do not increment it.

### 8.4 User override

Among the eligible set, the user may pick any eligible lender. The default suggestion is the first lender after **OD-08** ranking (available-for-lending percentage descending, then available-for-lending amount descending; if both are still exactly equal, any tied eligible lender may be the default). Backend ignores any client-supplied ineligible lender id.

### 8.5 Backend revalidation

Repeat §8.1 against **current** balances inside a transaction. On failure, return a typed error (lender ineligible / insufficient lendable / stale request). Do not partially commit.

### 8.6 Default ranking (OD-08)

OD-08 applies to selecting a prospective **lender** before/when a new loan is created. It does not select an outstanding loan.

After §8.1 eligibility filters, rank eligible lenders in this exact order:

1. **Available-for-lending percentage** — descending
2. If tied, **available-for-lending absolute amount** — descending
3. If both values are still exactly equal, **any tied eligible lender may be selected**

There is deliberately **no** third business ranking criterion. The final exact tie MAY be implemented as the first remaining candidate after the two business sort keys, or as arbitrary/random selection among the exactly tied candidates. There is **no product significance** attached to which exactly-tied lender is selected.

Do **not** use FIFO, oldest loan, largest loan, strategy age, raw portfolio cash, or any other business ranking rule for that third tie-break.

---

## 9. Multiple Competing Strategies

### 9.1 Coexistence

Three, four, five or more enabled strategies each have an allocation %, own holdings, own generation, and own recommendations. They share one cash ledger and one set of portfolio risk controls.

### 9.2 How each strategy gets allocation

User sets percentages summing to 100%. Each strategy’s virtual allocated capital is `investable_capital × pct`. Holdings market value counts against the **owning** strategy’s deployed amount only. Two strategies holding RELIANCE contribute their own MV to themselves, not a blended book.

### 9.3 Unused allocation becomes lendable

Only the surplus above minimum free capital, atomic-aligned, within limits, is lendable. Fully invested strategies have ~0 lendable.

### 9.4 Borrower discovers lenders

From the unfunded recommendation, the system evaluates §8 against all other enabled strategies. The borrower does not pick from the full strategy list.

### 9.5 Lender ranking (OD-08)

**OD-08 and OD-09 are distinct operations.** Do not merge them into a single lender/loan ranking algorithm.

| Operation | Object | Frozen order |
|-----------|--------|----------------|
| **OD-08** default lender selection | Prospective **lender** (a strategy) | Eligibility → available-for-lending % descending → available-for-lending amount descending → arbitrary exact tie |
| **OD-09** replenishment / recall tap | Existing outstanding **loan** | Recall eligibility → FIFO by commitment time → arbitrary exact tie |

Sort eligible **lenders** as follows (**OD-08**, frozen):

1. Available-for-lending **percentage** descending
2. If tied, available-for-lending **absolute amount** descending
3. If both values are still exactly equal, any tied eligible lender may be selected (first remaining candidate, or arbitrary/random among the exact ties). No product significance attaches to which exactly-tied lender is selected.

Do **not** use FIFO, oldest loan, largest loan, strategy age, raw portfolio cash, or any other business ranking rule as a third lender-ranking criterion.

**Available-for-lending absolute amount** is the explicit OD-08 secondary ranking key. It is not raw portfolio cash.

This ranking MUST NOT be reused as the outstanding-loan tap order (§9.7).

### 9.6 Concurrent requests

See §24. Display is a snapshot. Approval is serialised per portfolio capital mutation.

### 9.7 Recall across many strategies (OD-09)

Each loan is a discrete outstanding amount with a lender, borrower, **commitment time**, and remaining principal. Replenishment requests the minimum OD-06-aligned amount from loans that are past the **effective** minimum lending period (§6.5, OD-07). If sells are required, weakest borrower-owned positions first (§17); the weakest-position **formula** remains **OPEN (OD-16)**.

**OD-09 (frozen):** if multiple outstanding **loans** are eligible to repay a replenishment:

1. Filter to loans eligible for recall under §6.5 / OD-07.
2. Select the **oldest eligible outstanding loan first (FIFO)** by commitment time.
3. If two or more eligible loans have exactly the same commitment time, any tied loan may be selected. Implementation MAY resolve that exact tie arbitrarily or deterministically. There is no additional business ranking criterion after FIFO.

Do **not** introduce largest loan first, smallest loan first, lender percentage, lender absolute lendable amount, borrower strength, or strategy ranking as loan-selection rules.

Do **not** recall an entire loan merely because it is the oldest if only a smaller OD-06 amount is required. If the selected loan cannot cover the remaining need, continue in the same FIFO order with the next oldest eligible loan.

OD-07 controls **when** a loan becomes recall-eligible. OD-09 controls **which** eligible loan is selected. OD-08 (lender ranking) MUST NOT be used to choose among loans.

### 9.8 Do not churn policy allocation

Today’s signal count MUST NOT re-spread `allocation_pct`. Lending is the mechanism for **temporary** unused capacity. Permanent mix changes are user edits.

---

## 10. Holdings Ownership

### 10.1 Every strategy-generated holding has an owner

When a recommendation (OPEN/INCREASE) is executed, the resulting lots/position MUST be tagged with that recommendation’s strategy. INCREASE adds to **that strategy’s** owned position in the stock, never to another strategy’s lot and never to unmanaged quantity.

**OD-01:** another strategy may already own the same stock; that is a separate holding.

### 10.2 Manual holdings are unmanaged

Transactions with `source = manual` create or add to **unmanaged** quantity until adoption.

Other **non-recommendation, non-corporate-action** sources (for example an IPO purchase that is not an adjustment of an existing parent holding) likewise create or add to **unmanaged** quantity until adoption.

**Corporate actions** (bonus / split / rights) are **not** manual adds. Their quantity follows the parent holding’s owner (**OD-10**, §10.7). Do not route CA quantity to unmanaged solely because the source is not a recommendation.

### 10.3 Unmanaged are not strategy-controlled

Strategy OPEN/INCREASE/REDUCE/EXIT/HOLD logic MUST ignore unmanaged quantity **and** other strategies’ quantity. A strategy MUST NOT sell another strategy’s quantity even when the symbol is the same.

Portfolio stop-loss and trailing stop **do** apply to unmanaged holdings (§14–§15).

### 10.4 Adoption

- User adopts an unmanaged holding into **exactly one** strategy.
- After adoption, that strategy owns it; **target amount** SHOULD be initialised from the adopted position’s current monetary value (remaining BUY/INCREASE = 0 unless the user/strategy later raises the target amount). Cost-basis / entry-date merge remains **DEP-ADOPT-MERGE**.
- Adoption MUST be explicit. No silent auto-adopt during generation.
- If the destination strategy **already** owns that stock, the unmanaged quantity is merged into that strategy’s holding. Cost-basis / entry-date merge rules are **not frozen** — see §33.3 **DEP-ADOPT-MERGE**.
- Adoption into a strategy does **not** disturb another strategy’s existing position in the same stock (OD-01).

### 10.5 Migration / backfill ownership

Existing holdings become **unmanaged**, unless ownership can be **safely inferred**:

**Safe inference (normative, conservative):** an open position MAY be tagged as owned by strategy S only if **every** buy lot that still contributes to the open quantity is linked to recommendations whose `strategy_version` belongs to S, and no contributing lot is manual/other. If mixed sources or missing `recommendation_id`, the holding stays unmanaged.

Do not infer ownership from “the portfolio currently has one active strategy”. Do not collapse two strategies’ lots in the same stock into one holding during migration.

### 10.6 Same stock, multiple strategies — FROZEN ALLOW (OD-01)

Two or more strategies MAY own the same stock independently.

| Layer | Rule |
|-------|------|
| Identity | Unique `(portfolio, stock, owner)` where owner is `strategy_id` or the unmanaged sentinel |
| Cost / qty / **target amount** / filled / entry | Per owner. Target is monetary (OD-12); quantity is derived. |
| Trailing stop / stop-loss | Per owner’s holding (own entry date and own high-close series) |
| Strategy EXIT/REDUCE/INCREASE | Only the owning strategy, only its quantity |
| Corporate-action quantity | Follows the **parent holding’s owner** (OD-10). Do not blend owners first. Do not use pro-rata as the governing rule. |
| Portfolio exposure cap | Aggregate symbol MV/qty across owners |
| Strategy max holdings | Each owner counts the name once for **itself** |
| Portfolio displayed total | Sum of owners (example: 50 + 30 = 80 RELIANCE) |

V1 unique `(profile_id, stock_id)` **conflicts** and MUST be replaced.

### 10.7 Corporate-action ownership (OD-10)

**OD-10 (frozen): corporate actions follow the parent holding’s owner.**

For every position identified by `(portfolio, stock, owner)`, a corporate action applicable to that holding MUST adjust **that owner’s** position quantity.

- Strategy-owned parent holding → CA quantity remains with **that** strategy owner.
- Unmanaged parent holding → CA quantity remains **unmanaged**. It MUST NOT be automatically assigned to a strategy.
- If the same stock has multiple strategy owners, the CA MUST NOT first blend those holdings into one portfolio-level position.

The governing rule is **parent-owner attachment**, not pro-rata allocation. Pro-rata and parent-owner attachment can differ for rounding, rights issues, broker-posted quantities, and other CA mechanics. Do **not** use pro-rata as the general CA allocation rule.

**Normative quantity-ownership example** (illustrates attachment only; it does not freeze split mathematics, cost restatement, or price series):

Before: Strategy A = 50, Strategy B = 30, unmanaged = 0.

For a 2:1 split: Strategy A = 100, Strategy B = 60, unmanaged = 0.

**OD-10 does not decide** (leave unspecified, or already OPEN elsewhere):

- split / bonus mathematical formulas
- rights-issue calculation rules
- cost-basis / average-price restatement
- target / filled restatement
- trailing-high restatement
- stop-loss price restatement
- `close_price` vs `adjusted_close_price` (**OD-14 remains OPEN**)
- merger / demerger treatment (not specified in this document)

---

## 11. Recommendation Generation

### 11.1 Per-strategy generation

For each enabled strategy:

1. Resolve eligibility (Screener union — SD-030 unchanged).
2. Score **fit** for eligible names and for that strategy’s owned holdings.
3. Apply strategy thresholds → provisional action (OPEN/INCREASE/REDUCE/EXIT/HOLD/WATCH).
4. Apply strategy-specific exit rules to **owned holdings only**. If triggered, action = EXIT (attribution = strategy exit).
5. Apply portfolio stop-loss / trailing stop / horizon to **this strategy’s owned holdings only**, each as its own position even if other strategies hold the same symbol (§13). Higher-precedence attribution wins when several would fire on the same cycle for **that** holding.
6. Ignore holdings not owned by this strategy (including unmanaged and other strategies’ lots of the same stock).
7. Apply staggered entry (§12) to OPEN/INCREASE **amounts**, then derive whole-share quantity from the latest daily close (OD-12). Suppress OPEN/INCREASE below the effective minimum actionable amount.
8. Apply BUY cooldown (§11.2, **OD-11**) to OPEN/INCREASE only (key = stock + **this** strategy). Another strategy’s BUY of the same stock does not consume, reset, or affect this strategy’s cooldown.
9. Enforce hard max holdings on new OPEN (this strategy’s name count only).
10. Compute ranking (§4) from the **backtest corpus** when `n ≥ 15`; do not emit fit-as-rank; do not use live trades.
11. Compute own available capital; apply OD-05/OD-06. Set `FUNDED` / `PARTIALLY_FUNDED` / `UNFUNDED`. **Do not demote OPEN/INCREASE to WATCH** for capital reasons.
12. Persist recommendations. Cancel only **this strategy’s** stale open recs, subject to §11.2 (do not stale-replace a BUY for a pair that is in cooldown; do **not** cancel `pending_execution`; do not clear cooldown). Do **not** cancel other strategies’ recs.

Market gates MAY continue to demote OPEN/INCREASE to WATCH/HOLD as **market** policy (F098). That is not a capital demotion. Capital shortage uses UNFUNDED or PARTIALLY_FUNDED, not WATCH.

### 11.2 BUY cooldown (OD-11)

**OD-11 (frozen).** Primary purpose: prevent repeated BUY **recommendation churn** for the same stock and strategy. Secondary: space repeated capital deployment into that stock/strategy.

This is **not** the lending/borrowing recall period. **OD-07** remains unchanged (configurable; shipped default **14 calendar days**). Do not use 14 days as the BUY cooldown.

**Scope**

- Key: `(stock, strategy)`.
- Applies to BUY-side actions: **OPEN** and **INCREASE**.
- Does **not** suppress **REDUCE**, **EXIT**, or **HOLD**.
- Another strategy buying the same stock has an independent cooldown and does not consume, reset, or otherwise affect this strategy’s cooldown (OD-01).

**Duration and unit:** **1 calendar day** (not trading sessions).

If a BUY recommendation opportunity occurs on calendar **Day 0**:

- **Day 0:** that BUY is allowed.
- **Day 1:** another BUY recommendation for the same stock+strategy is suppressed by cooldown.
- **Day 2:** cooldown has elapsed; a new BUY recommendation MAY be generated if all other eligibility rules pass.

**Start:** a BUY recommendation **opportunity / generation cycle** starts the one-calendar-day cooldown for that pair. Cooldown does **not** wait for broker fill, trade approval, or cash reservation. Partial fills and full fills do **not** reset, extend, or restart it. It is not a lending/borrowing clock.

After the Day 0 opportunity has been generated, **new** OPEN/INCREASE recommendations for that pair are suppressed until Day 2. An unapproved BUY MUST NOT simply be regenerated the next calendar day (Day 1) for the same pair.

**Lifecycle (normative)**

- Cooldown only suppresses **new** BUY recommendations during the active window.
- It MUST NOT cancel, reverse, or otherwise modify an already-approved / `pending_execution` / executing trade.
- Stale-recommendation cancellation MUST NOT clear, reset, or imply that cooldown has disappeared.
- While cooldown is active for a pair, generation MUST NOT persist a replacement OPEN/INCREASE for that pair (that is the churn OD-11 exists to prevent). If an unapproved OPEN/INCREASE for that pair still exists, do not stale-replace it with another BUY during the window.
- `pending_execution` remains governed by its existing lifecycle (§23, §24.3) and MUST NOT be cancelled merely because of cooldown.

### 11.3 Interaction with existing holdings

| Holding state | This strategy may |
|---------------|-------------------|
| Owned by this strategy | INCREASE / REDUCE / EXIT / HOLD per rules (this lot only) |
| Owned by another strategy | No REDUCE/EXIT/INCREASE of **their** lot. This strategy MAY OPEN (or INCREASE **its own** lot) in the same stock (OD-01). |
| Unmanaged | No strategy trade control; user may adopt into one strategy |

### 11.4 Target amount (OD-12)

Conviction → **target amount** (within diversification and portfolio caps). Quantity is **derived** from that amount using the latest available daily closing price and whole-share flooring (§12.3). The target amount MUST be persisted so later cycles can fill the remainder (§12). Do not persist target quantity as the source of truth.

---

## 12. Staggered Entry

### 12.1 Frozen behaviour

- The persisted target for a strategy-owned position is primarily a **monetary amount** (**OD-12**). Quantity is derived; it is not the primary stored unit.
- First entry is **approximately 50%** of the **current target amount** (default; strategy-configurable; if unset, use 50%).
- The 50% rule applies to the **first entry only**. It is **not** a fixed-size rule for subsequent INCREASEs.
- Later recommendation(s) may fill the remaining **amount** after cooldown (OD-11), using **current target amount** and the amount already represented by the **filled** position (§12.2).
- Target amount remains strategy/conviction-driven (and recapped if conviction/caps change). Target MAY change during an active BUY cooldown; when cooldown elapses, use the latest valid target amount and latest filled position. Do not freeze a second 50% tranche from the original target.

V3 is a personal-use product (possible later use by a small number of friends). Prefer simple, deterministic rules. Small monetary differences from whole-share rounding or from last-close vs later execution price are acceptable. Do not over-engineer penny-level precision, and do not simplify in a way that materially changes intended investment risk. Do **not** add an execution-price band here; execution price is a separate concern.

### 12.2 Surviving recommendation cycles

Target **amount** MUST be persisted on the **strategy-owned position** (or an equivalent strategy-position record), not only on a perishable recommendation row.

Minimum fields:

- `target_amount` (source of truth, OD-12)
- derived whole-share quantity (not primary)
- `filled_amount` / filled quantity (filled amount = monetary amount already represented by the filled position)
- `entry_date` (first fill of this ownership episode)

Do not store target quantity as the authoritative target. A derived quantity MAY be stored for display if it is recomputed from `target_amount` and the latest daily close when generating recommendations.

**Reference price (OD-12):** for recommendation generation and all target/quantity calculations, use the stock’s **latest available daily closing price**. Do **not** use intraday price, expected execution price, previous execution price, broker quote, or estimated future price. Execution price is a separate execution concern and is not specified here. Small differences between last close and actual execution price MUST NOT alter recommendation-generation semantics.

Each generation:

- `remaining_amount = max(0, current_target_amount − filled_amount)`
- If no position yet: this-cycle intended amount = `first_entry_pct × current_target_amount` (default 50%)
- If position exists and remaining_amount > 0 and BUY cooldown allows (**OD-11**): this-cycle intended amount = **remaining_amount** (not a preserved second 50% slice)
- If position exists and remaining_amount > 0 but BUY cooldown is active: do **not** emit a new INCREASE for that pair; target-amount changes still apply when cooldown elapses
- Convert this-cycle intended amount to whole-share quantity (§12.3)
- If the actionable opportunity is below the effective minimum (§12.4), do **not** emit OPEN/INCREASE; do **not** reduce `target_amount`
- Apply OD-06 to get `atomic_allocation` from `calculated_requirement` (the this-cycle intended amount). Atomic reservation does **not** replace `target_amount`
- Then OD-05: if own free capital is less than `atomic_allocation` but greater than zero, **partial-fund this cycle**. Do not WATCH. Do **not** reset `target_amount` to the funded slice

**First-entry example (amount):** target amount = ₹10,000 → first BUY intended ≈ ₹5,000. After that, the next BUY/INCREASE is not another ₹5,000 of the original ₹10,000.

If filled amount = ₹5,000 and, after cooldown, current target = ₹12,000 → remaining = ₹7,000.  
If filled amount = ₹5,000 and current target = ₹8,000 → remaining = ₹3,000.

Then derive whole-share quantity from remaining using the **latest daily close**. If remaining ₹3,000 is below the effective minimum actionable amount, no INCREASE is generated; target stays ₹8,000.

Partial first entry (capital, OD-05): target ₹36,000, first-entry 50% = ₹18,000, own free = ₹10,000 → fund ~₹10,000 now; **target remains ₹36,000**. Subsequent INCREASE after OD-11 elapses uses `current_target_amount − filled_amount`.

If current target amount is below the amount already filled, there is **no** BUY/INCREASE for a gap. Existing REDUCE semantics remain governed by applicable recommendation rules. Do not invent an automatic reduce unless strategy reduce rules already fire.

### 12.3 Whole-share quantity (OD-12)

Fractional shares are **not** supported.

When converting an amount into quantity, derive the maximum whole-share quantity whose notional value does not materially exceed the intended amount. Normal/default behaviour is to **FLOOR**:

```text
quantity = floor(intended_amount / latest_daily_close)
notional = quantity × latest_daily_close
residual = intended_amount − notional
```

Example: target or slice amount = ₹2,500, last close = ₹600 → 2500/600 = 4.166… → **quantity = 4**, notional = ₹2,400, residual = ₹100. Do **not** force a 5th share (₹3,000) merely to consume the amount.

Rounding to whole shares MUST **not** change the persisted `target_amount`. In the example the target remains ₹2,500; ₹100 is an unexecuted residual caused by whole-share constraints. Do not silently replace the target with ₹2,400.

Zero-quantity results MUST NOT be emitted as BUY/INCREASE recommendations.

### 12.4 Minimum actionable BUY/INCREASE amount (OD-12)

A configurable **minimum actionable amount** applies to the this-cycle BUY/INCREASE **opportunity**, not to the overall target amount.

```text
Effective minimum = Portfolio override if set, else Platform default
Shipped platform default = ₹5,000
```

No strategy-level override. Portfolio inherits the platform value unless it explicitly overrides.

If the remaining executable opportunity (after first-entry split or subsequent remaining, and after whole-share conversion as applicable) is **below** the effective minimum, do **not** generate a new OPEN/INCREASE. Do **not** reduce `target_amount`.

Example: target ₹10,000, filled ₹8,000, remaining ₹2,000, minimum ₹5,000 → no INCREASE; target stays ₹10,000. If target later becomes ₹14,000 and filled is still ₹8,000, remaining ₹6,000 ≥ ₹5,000 → INCREASE is actionable (subject to OD-11).

Do not create repeated recommendations merely to capture tiny residuals that cannot produce a meaningful transaction. Whole-share residuals below the minimum stay as unfilled target. Do not generate zero-quantity or immaterial BUY/INCREASE recs solely to consume those residuals.

This ₹5,000 default is **not** OD-06. OD-06’s ₹5,000 is the atomic **reservation** block. OD-12’s ₹5,000 is the minimum **actionable recommendation** amount. They happen to share a number; they are different mechanisms.

### 12.5 OD-05 and OD-06 vs target amount

- **OD-06** unchanged: `atomic_allocation` is reservation, not target. Example: requirement ₹18,000 → reserve ₹20,000; target stays ₹18,000 (this-cycle) / persisted position target is unchanged.
- **OD-05** unchanged: partial funding does not reset the target to the funded amount. Subsequent INCREASE remains subject to OD-11 and current target amount.

---

## 13. Exit Model

### 13.1 Mechanisms

1. **Strategy exit** — strategy-specific rules (score, RS/trend weakening, MA breakdown, ATR stop, screener exit, etc.)
2. **Stop-loss** — portfolio common control (§14)
3. **Trailing stop** — portfolio common control (§15)
4. **Horizon expiry** — only if that strategy has a horizon (§16)

All four produce the same execution-level EXIT (or REDUCE if a future rule allows partial portfolio stops — **not frozen**; V3 portfolio SL/trailing are full EXIT of the affected owned/unmanaged position).

V1 `ExitStrategyEvaluator` `any`/`all` combination remains valid **inside** strategy-specific rules only. It does **not** replace §13.2.

### 13.2 Attribution precedence (normative)

When more than one mechanism is true on the same evaluation:

1. Strategy exit
2. Stop-loss
3. Trailing stop
4. Horizon expiry

Record **one** primary attribution on the recommendation and on the resulting exit event / transaction. Lower-priority mechanisms MAY be listed as also-true in evidence, but primary attribution follows the list above.

Example: if strategy exit and trailing stop both true → primary = **strategy exit**.

### 13.3 No horizon

If the strategy has no horizon, mechanism 4 never fires. That is not an error.

### 13.4 Ownership

- Strategy exit and horizon expiry apply only to **that strategy’s owned** holdings (that owner’s quantity of the stock).
- Stop-loss and trailing stop apply as defined in §14–§15 to **each holding separately** (each strategy lot and the unmanaged lot). Hitting trailing on Strategy A’s RELIANCE does not by itself exit Strategy B’s RELIANCE.
- Corporate-action quantity follows the same per-owner identity (OD-10). Do not blend lots to apply a CA, then split the result.

### 13.5 Live ledger

V1 live `portfolio_transactions` has no `exit_reason`. V3 MUST persist primary attribution on the exit event (and SHOULD copy onto the sell transaction). Backtest `exit_reason` is not a substitute for live attribution.

Do **not** use the V1 trailing_stop unrealized-% proxy.

---

## 14. Stop-Loss

### 14.1 Frozen behaviour

- Evaluated on **daily closing price**, not the intraday low.
- **Portfolio-level** common control (not strategy `max_loss` as the account stop).
- Configurable percentage (today’s profile `default_stoploss_percent` is a migration source, not the V3 owner of strategy JSON).

**Hit when:** `latest_daily_close ≤ entry_price × (1 − stop_loss_pct/100)`  
(Entry price = average cost of the position being evaluated, unless a later decision defines first-fill price; do not invent a third definition. Using position average cost is the conservative reading of “from entry” for a stop from purchase.)

If average cost vs first-fill price must differ, that is **OPEN (OD-13)**. Until then, use **average cost** of the current ownership episode.

### 14.2 Which holdings

Applies to **every open holding** in the portfolio: each strategy-owned lot and the unmanaged lot, **evaluated independently**. Same symbol, different owners → different entry prices / highs / hits.

Rationale (frozen ownership split): stop-loss is a **portfolio** control, not a strategy control. Unmanaged means “not strategy-managed”, not “exempt from account risk”.

Strategy `max_loss` in V1 exit JSON MUST NOT remain the portfolio stop after migration (§25). Strategy-specific risk rules other than the common stop (for example ATR stop) may remain on the strategy.

### 14.3 Close series

Whether stop/trailing use `close_price` or `adjusted_close_price` is **OPEN (OD-14)**. Implementation must pick one series consistently once OD-14 is closed. Until then, do not mix series.

---

## 15. Trailing Stop

### 15.1 Frozen behaviour

- Calculated from the **entry date** of the current ownership episode.
- **Daily closing values only** (no intraday high/low).
- Track the **highest closing price** on or after entry date.
- `trailing_stop = highest_close_since_entry × (1 − trailing_pct / 100)`
- Hit when **latest daily close** is **below** the trailing stop.

This matches the V1 **presentation** formula in `HoldingPresentationService`, not the V1 `ExitStrategyEvaluator` unrealized-% proxy. V3 **forbids** the proxy as the trailing-stop definition.

### 15.2 Ownership

Same scope as stop-loss: every open holding, **per owner**, including unmanaged. Do not compute a single trailing stop from a blended high across strategies.

Entry date for unmanaged = first buy date of the current position (as V1 presentation already computes). Entry date for strategy-owned = first fill of that ownership episode (adoption date if adopted, not the original unmanaged buy, **unless** OD-15 says otherwise).

**OD-15:** On adoption, does trailing/stop entry date reset to adoption date or keep the original first buy? Not frozen. Until closed, **reset to adoption date** would change risk vs **keep original** would continue the unmanaged trail. Marked OPEN — do not implement a silent choice beyond storing enough data to support either.

### 15.3 Percentage

Portfolio-configurable trailing percentage, independent of stop-loss percentage (they may be set equal by the user).

---

## 16. Strategy Horizon

- Optional; strategy-configurable; not mandatory.
- **OD-02:** `T` is **calendar days**. Horizon `T = 30` expires at `entry_date + 30 calendar days` (not 30 trading sessions).
- If configured, when calendar days since entry ≥ `T`, horizon expiry is eligible to generate EXIT, subject to §13.2 (only if higher-precedence mechanisms did not already apply).
- If not configured, **no** horizon-based exit occurs. The strategy may stay invested indefinitely until other exits fire.

Recommendation TTL (`expiry_hours` on the recommendation row) is **not** a position horizon. Do not reuse recommendation expiry as horizon expiry.

---

## 17. Weakest Position Selection

Used when a borrower must free cash to return a loan.

Loan **selection** for replenishment is **OD-09** (FIFO by commitment time among recall-eligible loans). This section ranks **positions to sell**, not which loan to tap. Do not resolve OD-16 here.

MUST NOT rank weakest as:

- lowest lifetime XIRR, or
- lowest total return, or
- oldest holding

**Agreed direction:**

- use a **configurable evaluation window** where appropriate
- evaluate **recent / current** performance
- use **strategy-specific** evaluation rules / windows
- preserve positions whose **current thesis / recent strategy performance is strong**, even if lifetime XIRR is mediocre because the name was dormant for a long time

The exact weakest-position scoring formula is **OPEN (OD-16)**. Implementation MUST NOT ship an invented formula as if it were frozen. Until OD-16, recall that requires sells MUST block on user-selected positions or remain approval-only with user-chosen names — do not auto-pick with a fake score.

Eligible positions for forced repayment sells: **borrower-owned** holdings only.

---

## 18. Historical Evaluation / Backtesting

### 18.1 Roles

| Term | Role |
|------|------|
| Fit score | Match to hypothesis now |
| Historical outcome | A completed or simulated completed investment at a fit level |
| Return | Simple holding-period return |
| Annualized return / XIRR | Time-aware return (§20) |
| Benchmark return | NIFTY 50 over the comparable period |
| Opportunity-cost threshold | Configured annualized hurdle, scaled to the period |
| Success | §19 boolean |
| Expected return | Aggregated return quality at a fit band (symmetric 7%/7% trimmed mean, `n ≥ 15`, backtest corpus only) |
| Final ranking | Order by that expected/observed return quality — **not** live-trade outcomes, **not** fit score |

V1 strategy backtest (currently 15d–1y ranges, paper, market gates off) is the **simulator that must supply** the ranking corpus (OD-03). It is not automatically sufficient as shipped (range/depth limits are implementation limitations). Live `ReviewEngine` / ledger sells MUST NOT be mixed into ranking observations.

### 18.2 History depth

V3 product need: **multi-year** OHLCV for 5Y charts, long-horizon strategies, and historical evaluation.

The current code default `HISTORY_DEPTH_TARGET_DAYS = 550` is an **implementation limitation**, not a V3 cap. V3 requires deepening stored (and/or on-demand provider) history to support 5Y and all-available-history charts and multi-year evaluation. Exact target depth in days is **OPEN (OD-17)** as a number; “more than ~18 months, enough for 5Y when the listing has 5Y” is the product intent.

Pre-listing stocks show whatever exists; that is not an error (§26).

### 18.3 Benchmark

Comparable-period **NIFTY 50** return (primary benchmark already in the product). Opportunity-cost comparison is a hurdle on annualized return, not a substitute for the NIFTY test.

---

## 19. Success Definition

An historical outcome is **successful** when **all** of the following hold:

1. Return is **positive**.
2. Return **beats NIFTY 50** over the **comparable period**.
3. **Annualized** return also beats the configured **opportunity-cost** threshold.

Do not impose a fixed absolute return target across all strategies (no global “must gain X%”).

### 19.1 Opportunity cost

- Discussed default: **12% annualized**.
- MUST be **configurable** (portfolio or global settings). Do not hard-code 12% as the only possible value.
- Because it is annualized, the equivalent threshold for a period of length `T` years is:

`threshold_period = (1 + r)^(T) − 1`

where `r` is the configured annualized opportunity-cost rate (default 0.12).

**Do not** apply 12% as a flat percentage to a 3-week holding and to a 3-year holding alike.

**OD-02:** `T_years = calendar_days / 365`. Example: 30 calendar days → `T_years = 30/365`. Do not use trading-session counts (252) for this `T`.

---

## 20. Return Metrics

| Situation | Primary metric |
|-----------|----------------|
| Fixed-horizon strategy, outcome measured at horizon (or exit if earlier) | Simple return over that **calendar** horizon; also compute annualized for the opportunity-cost test using `T_years = calendar_days / 365` |
| No-horizon strategy, completed **backtest** holding | Holding-period simple return **and** XIRR / annualized return over the actual period |
| Weakest-position / windowed evaluation of **current** holdings (recall, §17) | Rolling or configured window return / XIRR — not lifetime XIRR as the sole score. This is **not** the OD-03 ranking corpus. |
| Opportunity ranking (fit-band expected return) | **Backtest corpus only** (OD-03), trimmed mean per OD-04 |
| Benchmark comparison | Simple return of NIFTY 50 over the same start/end dates (or nearest trading days) |

XIRR is appropriate when cash flows are dated (buys, sells, corporate actions). Simple return is appropriate for a single entry/exit over a defined horizon.

Do not annualise very short windows into explosive percentages as a success signal (V1 backtest already refuses CAGR under 30 days). A minimum holding period for annualization is **OPEN (OD-18)** if not reused from that backtest rule; until closed, do not treat 1-day fireworks as ranking evidence.

---

## 21. Portfolio Risk and Common Controls

These MUST move **out** of strategy `config_json` and onto **portfolio** configuration:

| Control | Notes |
|---------|--------|
| Stop-loss % | §14 |
| Trailing stop % | §15 |
| Portfolio cash reserve | Floor that is not invested and not lent (§22) |
| Portfolio max position size / exposure | Ceiling across the account |
| Other genuinely portfolio-wide risk limits identified in implementation | Must be classified as portfolio, not smuggled back into strategy JSON |

**Remain on strategy:** eligibility, scoring, thresholds, strategy-specific exits (not common SL/trailing), market gates, conviction/size bands **within** caps, staggered first-entry % (default 50%), BUY cooldown (**1 calendar day**, OD-11), optional horizon, recommended min / hard max holdings. Target **amount** is the position source of truth (OD-12); quantity is derived.

V1 Strategy UI tabs “Portfolio Rules”, “Cash Management”, and common stop/trailing inside “Exit Strategy” MUST be relocated conceptually to Portfolio settings (§29).

---

## 22. Cash Model

Physical cash remains **one** ledger-backed account per portfolio (V1 `portfolio_cash_accounts`). Do not create per-strategy bank accounts.

### 22.1 Quantities (normative definitions)

| Quantity | Definition |
|----------|------------|
| **Total portfolio cash** | Ledger balance |
| **Portfolio cash reserve** | Configured floor (percentage of cash and/or absolute — **OPEN (OD-19)** for the unit; the *existence* of a non-investable, non-lendable reserve is frozen). Cannot be used for new buys or loans. |
| **Reserved cash** | Cash reserved for approved **pending-execution buys** (V1 reservation semantics), including OD-06 `atomic_allocation` (which may exceed the eventual fill). Unused reservation after reconciliation returns to available. Must not double-count with CAPITAL_COMMITTED / lent. |
| **Investable capital** (strategy split base) | `(cash − reserve − pending-execution reserved) + market value of strategy-owned holdings` only. Unmanaged holdings’ market value is **not** part of the 100% strategy split (it is residual account wealth the strategies do not claim). Including holdings MV in the split base is required so an invested strategy is not treated as having unused cash equal to its entire %. Cash-only split is **not** the V3 model. |
| **Strategy allocation** | `investable_capital × allocation_pct` |
| **Strategy available capital** | Capital the strategy may use **now** for new buys: unused allocation that is actually fundable from physical available cash, plus outstanding **borrowed** amounts, minus amounts it must not spend (already reserved). Cannot invade portfolio reserve. |
| **Committed-to-lending** | Approved but not yet reflected as lent, if those steps are split; otherwise 0 and lent updates on approval |
| **Capital currently lent** | Outstanding loan principal from this strategy as lender |
| **Available-for-lending** | §8.2 |
| **Borrowed capital** | Outstanding loan principal to this strategy as borrower |
| **Capital required by pending recommendations** | Sum of unfunded BUY gaps (informational). Does **not** reserve cash |

### 22.2 Available physical cash

`available_physical_cash = max(0, total_cash − pending_execution_reservations)`

Withdrawals remain capped by available physical cash (V1). Portfolio reserve SHOULD also block withdrawals that would breach the reserve **OPEN (OD-21)** (V1 `CashManagementService` does not enforce strategy `min_cash_reserve_pct` on withdraw). Frozen: reserve cannot be lent or invested; whether the user may withdraw into the reserve is not frozen.

### 22.3 Consistency rules

- Sum of strategy allocated capital = investable capital as defined above (excludes unmanaged MV).
- Sum of strategy deployed (owned holdings MV + that strategy’s reserved buys + lent) reconciles to owned holdings MV + reserved + internal loans (loans cancel across lender/borrower).
- Internal loans do not change **total** portfolio cash; they change claims.
- Unmanaged MV + strategy allocated capital + (cash reserve + unallocated physical constraints) must be reconcilable to portfolio NAV; do not double-count unmanaged MV inside a strategy %.

---

## 23. Recommendation Status Model

Two axes. Do **not** overload WATCH.

### 23.1 Action (what the strategy decided)

`OPEN_POSITION` | `INCREASE_POSITION` | `REDUCE_POSITION` | `EXIT_POSITION` | `HOLD_POSITION` | `WATCH`

WATCH = informational, not a funded or unfunded BUY.

### 23.2 Review / execution status

Reuse V1 meanings where they still apply:

| Status | Meaning |
|--------|---------|
| `pending_review` | Awaiting user decision on the trade |
| `deferred` | User deferred |
| `rejected` | User rejected the recommendation |
| `pending_execution` | Trade approved, awaiting fill; buy-side cash reserved |
| `executed` | Linked ledger fill complete |
| `expired` | Past recommendation TTL or superseded expiry rules |
| `cancelled` | Cancelled (including stale generation for **this** strategy) |
| `published` | Informational publish (HOLD/WATCH) if still used |

### 23.3 Capital status (BUY/INCREASE only)

| Status | Meaning |
|--------|---------|
| `NOT_APPLICABLE` | REDUCE/EXIT/HOLD/WATCH |
| `FUNDED` | Own (and/or already borrowed) capital covers this-cycle `atomic_allocation` |
| `PARTIALLY_FUNDED` | Own free capital > 0 but less than `atomic_allocation`; executable amount is the available free capital (OD-05). Action remains OPEN/INCREASE. |
| `UNFUNDED` | Valid BUY/INCREASE; this-cycle allocated amount is 0; no active lender selection |
| `AWAITING_LENDER_SELECTION` | Eligible lenders exist; user has not chosen |
| `AWAITING_LENDING_APPROVAL` | User is in the approval step (if distinct from selection) |
| `CAPITAL_COMMITTED` | Loan approved and committed; moving to execution-ready |
| `FUNDING_FAILED` | Approval revalidation failed; not committed |

`UNFUNDED` / `AWAITING_*` correspond to the discussed CAPITAL_REQUIRED / AWAITING_CAPITAL product language. Implementation may use these enum names or aliases; product copy may say “Capital required”.

### 23.4 Illegal transitions

- OPEN/INCREASE + `WATCH` action because of cash (including partial cash) → **forbidden**
- `pending_execution` while capital_status is `UNFUNDED` or `AWAITING_*` → **forbidden**
- `CAPITAL_COMMITTED` without a persisted loan/audit row → **forbidden**

### 23.5 Capital request states

`displayed` (not reserved) → `awaiting_approval` → `committed` | `rejected` | `revalidation_failed` | `cancelled`

After commitment: `outstanding` → `partially_returned` | `returned` (atomic blocks).

---

## 24. Concurrency and Revalidation

### 24.1 Backend authority

The backend is authoritative for:

- capital availability
- lendable amounts
- lending approval
- committed-to-lending
- borrower capacity
- concurrent requests

Frontend snapshots are informational.

### 24.2 Normative race

Request A and Request B both show Momentum as lender because **at display time** both are eligible.

1. User approves A. Backend revalidates, commits, Momentum’s committed-to-lending / lent increases.
2. User approves B. Backend revalidates **current** Momentum lendable.
3. If insufficient, **B fails**. Do **not** silently commit a smaller loan than requested. User may pick another eligible lender, reject, or (for **own** capital) rely on OD-05 partial funding of the recommendation — that is a different path from shrinking an already-requested loan without a new request.

Serialize capital mutations per portfolio (transaction / lock). Do not rely on UI disabling the second button.

### 24.3 Stale generation

A second generate for the same strategy may cancel stale **unapproved** recs for that strategy. It MUST NOT silently drop another strategy’s recs or break outstanding loans. It MUST NOT cancel `pending_execution`. It MUST NOT clear or reset BUY cooldown (OD-11). While cooldown is active for a `(stock, strategy)` pair, it MUST NOT emit a new OPEN/INCREASE for that pair and MUST NOT stale-replace an existing unapproved BUY for that pair with another BUY.

---

## 25. Existing V1/V2 Migration

Non-destructive. Preserve data.

| Data | Migration behaviour |
|------|---------------------|
| Strategies | Keep rows. All previously `active` stay enabled. Drafts/archived unchanged. Multiple enabled allowed going forward. |
| Strategy `config_json` | Copy scoring, eligibility, thresholds, market gates, strategy-specific exit rules. **Move** `min_cash_reserve_pct`, max deploy, portfolio max position (as portfolio defaults), cash reservation flags to **portfolio** settings. Remove common SL/trailing from strategy exits as *account* controls; keep other exit rules. Do not delete old JSON keys until code no longer reads them; ignore them once V3 owners exist. |
| Holdings | Unmanaged unless safe inference (§10.5). |
| Manual holdings | Unmanaged. |
| Recommendations | Keep. Map unfunded-as-WATCH historically as **legacy**; do not rewrite historical actions. New generates use §23. |
| Transactions | Keep `source` and `recommendation_id`. Backfill owner on new derived holdings only per §10.5. |
| Cash | Single account unchanged. Opening loans = none. |
| `default_stoploss_percent` | Becomes portfolio stop-loss %. Trailing %: if only one number existed, **do not invent** a second; copy stop-loss % as initial trailing % **or** leave trailing disabled until the user sets it — **OPEN (OD-22)**. |
| Factory Minervini | Remains a strategy; its former 20% cash reserve becomes a **portfolio** default if no portfolio reserve exists yet. |

No forced sells, no cash wipes, no dropping recommendation history.

---

## 26. Chart Enhancement

Holdings / stock performance chart (`PriceVolumeChart`):

| Range | V3 |
|-------|-----|
| 1M, 3M, 6M, 1Y | Remain |
| **5Y** | Add |
| **All** | Remain as a control, but meaning **changes** |

**All** = all **available stored (and, when synced, provider) history** for that stock, **not** V1 “since first buy of the current position”.

V1 `GET /stocks/{id}/prices` via `priceHistoryForHolding` filters `price_date >= firstBuyDate`. That is an implementation behaviour to replace for this chart.

If the user selects 5Y (or any range) and less history exists, **show whatever is available**. No error solely because history is shorter. Keep the existing clamp hint pattern:

`Showing available data from {earliest} to {anchor} (less than selected range).`

Force-sync MAY fetch missing history from providers; lack of data before listing is success with shorter series.

Explorer/index charts are out of scope unless they share the same control component; if they do, 5Y/All semantics SHOULD match.

---

## 27. API Contract Changes

Additive / evolving `/api` and `/api/v1` capabilities (shapes not frozen beyond capability). All remain Sanctum + active portfolio scoped.

| Capability | Notes |
|------------|--------|
| List/enable **multiple** strategies per portfolio | Registry `exactly_one_active_per_portfolio` removed |
| Per-strategy recommendation generate | `strategy_id` required or generate-all-enabled |
| Holdings include owner, unmanaged flag, **target amount** / filled | Multiple rows per stock when multiple owners (OD-01). CA quantity stays on the parent owner row (OD-10); not blended. Target is amount (OD-12); qty derived from latest daily close. |
| `POST` adopt holding → one strategy | Merge if that strategy already owns the name (DEP-ADOPT-MERGE) |
| Portfolio controls GET/PUT | reserve, SL, trailing, max position, lending policy, atomic block ₹5,000, 1% margin, platform/portfolio recall period, **minimum actionable BUY/INCREASE** (platform default ₹5,000 + portfolio override, OD-12) |
| Strategy allocation % GET/PUT | sum-to-100 validation |
| Recommendation capital_status | FUNDED / PARTIALLY_FUNDED / UNFUNDED; never WATCH-for-cash |
| Eligible lenders for a rec | ranked by OD-08: lendable % descending, then lendable amount descending; exact ties arbitrary; amounts ₹5,000-aligned after 1.01 |
| Approve/reject lending | atomic revalidation |
| Recall request / approve | effective period = platform default (14d) or portfolio override (OD-07); if several eligible loans, tap oldest by commitment time first (OD-09); amount OD-06 |
| Capital / loan ledger read APIs | include reservation vs invested reconciliation |
| Chart prices with `from`/`range=5y\|all` **without** since-buy clamp | |
| Notifications hooks for capital/lending/recall | |

Existing generate/review/execute APIs remain for funded path. Breaking change: unfunded buys are no longer WATCH.

---

## 28. Database Model Changes

Conceptual (no migrations in this phase).

### 28.1 Portfolio settings / controls

Persist: cash reserve, stop-loss %, trailing %, portfolio max position, lending limits, min free-capital policy, opportunity-cost rate, optional auto-return flag, **platform default recall period** (shipped 14 calendar days), **portfolio recall override**, atomic block **₹5,000**, execution-price margin **1%**, **platform default minimum actionable BUY/INCREASE amount** (shipped **₹5,000**, OD-12), **portfolio minimum-actionable override**.

### 28.2 Strategy

- Allow multiple enabled strategies per `profile_id` (drop application-level exclusive active).
- `allocation_pct`
- Strategy-only config; strip portfolio keys from *meaning* (keys may linger unused).
- Optional horizon in **calendar days**, first_entry_pct (default 50%), buy_cooldown (**1 calendar day**, OD-11), min/max holdings.

### 28.3 Holdings / positions

- `strategy_id` nullable (null/sentinel = unmanaged)
- `target_amount` (source of truth, OD-12), derived whole-share quantity, filled amount/qty, `entry_date` (per owner)
- Unique `(profile_id, stock_id, owner_key)` — **not** `(profile_id, stock_id)` (OD-01)
- Adoption events table (who, when, from unmanaged, to strategy)
- Corporate-action quantity adjusts the **existing** owner row (OD-10); do not insert CA shares as a new unmanaged holding when a parent holding exists

### 28.4 Recommendations

- Existing `strategy_version_id` kept
- `capital_status`
- Do not require new action enum for unfunded BUY

### 28.5 Capital requests / loans

- request id, borrower_strategy_id, lender_strategy_id, recommendation_id, amount, status, created_at, approved_at, approved_by
- loan id, principal, outstanding, committed_at, min_recall_at
- returns: amount, at, request/loan id

Indexes: `(profile_id, status)`, `(lender_id, outstanding)`, `(borrower_id)`, `(recommendation_id)`.

Constraints: amount ≥ ₹5,000 and multiple of ₹5,000 after OD-06; lender ≠ borrower; one lender per request.

`committed_at` is the OD-09 FIFO key for selecting among recall-eligible outstanding loans. Do not invent a separate loan-ranking column for lender % or loan size.

### 28.6 Exit attribution

On sell recommendation / transaction / exit_event: `primary_reason` enum (`strategy_exit`, `stop_loss`, `trailing_stop`, `horizon_expiry`), optional `evidence_json`.

### 28.7 Audit log

Append-only capital and ownership events (§31).

---

## 29. UI / UX Changes

| Surface | V3 |
|---------|-----|
| **Strategy page** | Eligibility, scoring, thresholds, strategy-specific exits, optional horizon, staggered first-entry % (default 50%), BUY cooldown **1 calendar day** (OD-11; not the OD-07 14-day recall), min/max holdings, conviction bands. **Remove** portfolio cash reserve, common SL/trailing, portfolio-wide cash rules. |
| **Strategy registry** | Enable multiple strategies; allocation % editor (sum 100). No exclusive activate. |
| **Portfolio settings** | Reserve, SL, trailing, portfolio caps, lending policy, recall period override (inherit platform 14-day default), opportunity cost, auto-return toggle (default off). Atomic block ₹5,000 and 1% margin are specified product values (display as policy, not as “invest the margin”). **Minimum actionable BUY/INCREASE** inherits platform ₹5,000 unless the portfolio overrides (OD-12; not a strategy setting; not OD-06). |
| **Holdings** | Owner / Unmanaged; **per-strategy rows** for the same stock (qty 50 vs 30); Adopt; **target amount** vs filled amount (qty derived from latest daily close, OD-12); do not offer strategy sell on unmanaged or on another strategy’s lot. Corporate-action quantity stays on the parent owner (OD-10); unmanaged CA stays unmanaged. |
| **Recommendations** | Unfunded or partially funded BUY visible with capital status; not WATCH. Lender list. Approval. BUY cooldown **1 calendar day** per stock+strategy (OD-11); does not cancel `pending_execution`. Ranking from backtests when `n ≥ 15`; fit labelled as fit. |
| **Cash** | Reserve vs available vs reserved (atomic allocation vs post-fill leftover) vs per-strategy allocated / deployed / lendable / lent / borrowed. |
| **Lending / recall** | Eligible lenders only; default sort OD-08 (lendable % descending, then lendable amount descending; exact ties have no product significance). Approve; errors on stale; recall approval; show effective recall period (platform vs portfolio override). Replenishment among eligible loans: oldest commitment time first (OD-09); do not present FIFO as a lender sort. |
| **Charts** | 5Y; All = full available history; clamp message. |

---

## 30. Notifications

Telegram (and in-app) MUST be able to notify, at least:

| Event | Why |
|-------|-----|
| Capital required (valid unfunded or partially funded BUY) | Actionable; not a WATCH skip |
| Lending approval needed | User must act; never auto |
| Recall / return approval needed | Default governance |
| Lending/recall approval failed (stale capital) | User must pick another lender or reject |
| Capital committed / execution ready | Resume normal pending-execution path |
| Portfolio SL / trailing / horizon EXIT recs | Risk |

V1 skips Telegram for HOLD/WATCH. Unfunded OPEN/INCREASE are **actionable** and MUST NOT inherit that skip.

---

## 31. Auditability

Append-only, attributable (user id, timestamp, portfolio, strategies, amounts, recommendation ids):

- lending request created / displayed snapshot (optional) / approved / rejected / revalidation failed / cancelled
- capital commitment
- loan returns (amount, remaining), including which outstanding loan was selected (OD-09 FIFO by commitment time)
- recall requests and approvals
- holding adoption
- corporate-action quantity adjustments per parent owner (OD-10)
- exit primary attribution
- allocation % changes

Users and operators MUST be able to reconstruct who lent what to whom, when it became recallable, and why an exit was attributed as it was.

---

## 32. Backward Compatibility and Compatibility Risks

Conflict register from the Architecture Impact Report, with V3 resolution:

| # | V1/V2 conflict | V3 resolution |
|---|----------------|---------------|
| 1 | One active strategy (SD-029) | **Superseded.** Multiple enabled strategies. |
| 2 | Strategy owns `portfolio_rules` / cash / common exits | **Superseded.** Split §3 / §21. |
| 3 | Unfunded OPEN/INCREASE → WATCH | **Superseded.** Valid unfunded or partially funded BUY + capital_status (OD-05). |
| 4 | All holdings in-scope for the active strategy | **Superseded.** Ownership fences + unmanaged. |
| 5 | Unique `(profile, stock)` | **Superseded (OD-01).** Unique `(profile, stock, owner)`. Same symbol, multiple strategy lots allowed. |
| 6 | Two trailing definitions | **Superseded.** Daily highest close from entry (§15). Proxy forbidden. |
| 7 | Unordered exit any/all | **Superseded** for cross-mechanism attribution (§13.2). Strategy-internal any/all may remain. |
| 8 | Fit used as rank and allocator weight | **Superseded.** §4. Ranking from **backtest** trimmed mean (OD-03, OD-04), not live trades, not fit. |
| 9 | Single cash pool + profile-wide stale-cancel | Pool **kept**. Stale-cancel **scoped per strategy**. Lending added as virtual claims. |
| 10 | Chart since-buy, no 5Y, ~550d storage | Chart **All/5Y** specified. 550d is a limitation to lift (OD-17). |
| 11 | Paper = backtest only | Multiple portfolios remain for hypothesis testing. Live exclusivity deferred to broker era. |

V1 recommendation evidence `capital_allocation.status = unfunded` with action WATCH is **legacy**. Clients MUST accept V3 capital_status on BUY actions.

---

## 33. Decision Log and Open Decisions

### 33.1 Frozen / resolved (OD-01 through OD-12)

OD-01 through OD-07 were recorded from the 2026-08-14 product-decision session. OD-08 through OD-11 were recorded from the 2026-08-15 product-decision session. OD-12 was recorded from the 2026-08-17 product-decision session. These IDs MUST NOT reappear in §33.2.

| ID | Topic | Decision | Status |
|----|--------|----------|--------|
| **OD-01** | Same stock owned by multiple strategies | **ALLOW.** Two or more strategies in one portfolio may independently own the same stock (e.g. A: RELIANCE 50, B: RELIANCE 30; portfolio 80). Do not model `(portfolio, stock)` as globally unique. Cost, trailing, exit, allocation, and attribution are per owner. | FROZEN |
| **OD-02** | Horizon / period `T` | **CALENDAR DAYS**, not trading sessions. `T = 30` means 30 calendar days. Opportunity-cost `T_years = calendar_days / 365`. | FROZEN |
| **OD-03** | Ranking corpus | **BACKTESTS ONLY.** Do not use the user’s live-trading history as ranking observations. | FROZEN |
| **OD-04** | Trimmed mean | Symmetric **7% lower + 7% upper**. **Minimum sample size = 15**. If `n < 15`, trimmed-mean ranking is not eligible. | FROZEN |
| **OD-05** | Partial capital funding | **ALLOWED.** If desired capital exceeds available free capital, allocate the available free capital as a partial position. Do **not** convert OPEN/INCREASE to WATCH. Capital status is a separate axis. Consistent with staggered buys. | FROZEN |
| **OD-06** | Atomic block and execution margin | Atomic block = **₹5,000**. Apply **1%** margin then **ceil** to the next ₹5,000: `atomic_allocation = ceil((calculated_requirement × 1.01) / 5000) × 5000`. This is a **reservation/allocation**, not a mandate to invest the margin. Unused reservation reverts after execution. If atomic requirement exceeds free capital, OD-05 partial funding still applies. | FROZEN |
| **OD-07** | Recall frequency | **CONFIGURABLE** at platform default and portfolio override. Effective = override if set, else platform default. Shipped default **14 calendar days** remains applicable. Must not be a non-configurable hard-code that ignores settings. | FROZEN |
| **OD-08** | Default lender selection after eligibility | **FROZEN.** Applies to selecting a prospective **lender** (a strategy) before/when a new loan is created — not to outstanding loans. After existing lender eligibility filters (§8.1), rank eligible lenders: (1) available-for-lending **percentage** descending; (2) if tied, available-for-lending **absolute amount** descending; (3) if both values are still exactly equal, **any** tied eligible lender may be selected (first remaining candidate, or arbitrary/random among exact ties). There is deliberately **no** third business ranking criterion and **no product significance** to which exactly-tied lender is selected. Do **not** use FIFO, oldest loan, largest loan, strategy age, raw portfolio cash, or any other business ranking rule for that third tie-break. Available-for-lending percentage remains the primary ranking metric already defined by this specification. Available-for-lending amount is the explicit secondary ranking criterion. | FROZEN |
| **OD-09** | Outstanding loan selection for replenishment | **FROZEN.** Operates on existing outstanding **loans**, not prospective lenders. When replenishment requires capital to be recalled and multiple outstanding loans are eligible: (1) filter to loans eligible for recall under existing V3 recall / effective-minimum-period rules (OD-07 / §6.5); (2) select the **oldest eligible outstanding loan first (FIFO)** by commitment time; (3) if two or more eligible loans have exactly the same commitment time, **any** tied loan may be selected (implementation may resolve arbitrarily or deterministically). There is deliberately **no** additional business ranking criterion after FIFO. Do **not** introduce largest loan first, smallest loan first, lender percentage, lender absolute lendable amount, borrower strength, or strategy ranking as loan-selection rules. OD-06 still controls the amount recalled: request the minimum required atomic amount; do **not** recall an entire loan merely because it is the oldest if only a smaller amount is required. OD-07 controls **when** a loan becomes recall-eligible; OD-09 controls **which** eligible loan is selected. If a borrower must sell positions as a consequence, §17 and OD-16 remain applicable; this decision does not resolve OD-16. | FROZEN |
| **OD-10** | Corporate-action quantity ownership | **FROZEN.** Corporate actions follow the **parent holding’s owner**. For every strategy-owned or unmanaged position identified by `(portfolio, stock, owner)`, a corporate action applicable to that holding MUST adjust **that owner’s** position quantity. If the same stock has multiple strategy owners, the CA MUST NOT first blend those holdings into one portfolio-level position. Strategy-owned parent → CA remains with that owner. Unmanaged parent → CA remains unmanaged and MUST NOT be automatically assigned to a strategy. The governing rule is **parent-owner attachment**, not pro-rata allocation (pro-rata can differ for rounding, rights, broker-posted quantities, and other CA mechanics). OD-10 does **not** freeze split/bonus formulas, rights-issue calculations, cost-basis / average-price / target / filled / trailing-high / stop-loss restatement, `close_price` vs `adjusted_close_price` (**OD-14 remains OPEN**), or merger/demerger treatment. | FROZEN |
| **OD-11** | BUY cooldown duration, unit, and clock | **FROZEN.** Duration = **1 calendar day** (not trading sessions). Key = `(stock, strategy)`. Applies to OPEN and INCREASE; does not suppress REDUCE, EXIT, or HOLD. Another strategy’s BUY of the same stock does not consume, reset, or affect this cooldown. Primary purpose: prevent BUY recommendation churn; secondary: space repeated capital deployment. A BUY recommendation **opportunity / generation cycle** starts the cooldown (Day 0 allowed, Day 1 suppressed, Day 2 elapsed). It does **not** start on fill, trade approval, or lending commitment. Fills do not reset it. It is **not** the OD-07 recall period (still configurable, shipped default 14 calendar days). Unapproved BUYs MUST NOT be regenerated during the window; stale-cancel MUST NOT clear cooldown; `pending_execution` MUST NOT be cancelled because of cooldown. First entry remains ~50% of current **target amount**; subsequent INCREASE = `max(0, current_target_amount − filled_amount)`, not a fixed second 50% tranche (OD-12). Target MAY change during cooldown; use latest target amount and filled amount when the window elapses. | FROZEN |
| **OD-12** | Staggered target primary unit | **FROZEN.** Staggered target primary unit = **monetary amount**. Quantity is derived from the **latest daily closing price** using whole-share rounding (default **floor**; do not materially overshoot). Rounding MUST NOT change the persisted target amount. Minimum actionable BUY/INCREASE amount is configurable at **platform** level with **portfolio-level** override; no strategy override; shipped platform default = **₹5,000**. The minimum applies to the this-cycle opportunity, not the overall target; remaining below the minimum suppresses OPEN/INCREASE without reducing the target. Subsequent INCREASE uses current target amount minus filled amount (OD-11). OD-06 atomic reservation and OD-05 partial funding MUST NOT replace or reset the target amount. Recommendation calculations use latest daily close, not execution price. | FROZEN |

Note: before the 2026-08-14 session, **OD-05** meant “minimum free capital / one opportunity formula”. That topic was **not** decided there and is re-homed as **OD-24** so it is not lost.

### 33.2 Open decisions (OD-13 through OD-23, plus OD-24)

Do not invent resolutions.

| ID | Decision | Notes |
|----|----------|--------|
| **OD-13** | Stop-loss reference price: average cost vs first-fill price | Spec tentatively uses average cost until closed. Per owner (OD-01). |
| **OD-14** | `close_price` vs `adjusted_close_price` for SL/trailing | Must be consistent. Per owner high/close series. |
| **OD-15** | On adoption, reset trailing/stop entry date or keep original first buy? | |
| **OD-16** | Exact weakest-position score formula | Direction frozen; formula not. No fake auto-pick until closed. |
| **OD-17** | Numeric OHLCV depth target for V3 (must support 5Y when listed) | 550 is not the requirement. |
| **OD-18** | Minimum period before annualized return is used in ranking | Ranking corpus is backtests (OD-03). |
| **OD-19** | Cash reserve unit: % of cash, % of NAV, absolute ₹, or combination | Reserve existence frozen. |
| **OD-20** | Whether any residual cash after reserve that is not yet claimed by strategy unused-allocation accounting needs a named “unallocated cash” bucket in the UI | Accounting in §22 is specified; presentation of leftovers is not. |
| **OD-21** | May the user withdraw cash that would breach the portfolio reserve? | |
| **OD-22** | Migrating trailing % when only `default_stoploss_percent` exists | |
| **OD-23** | Capital fill order among a strategy’s own valid BUYs when return-quality ranking is not yet computable (`n < 15` or corpus missing) | Must not be shipped as “rank by fit”. |
| **OD-24** | Exact formula for “minimum free capital / one opportunity” | Existence of the non-lendable floor is frozen. Previously listed as OD-05 before that ID was reassigned to partial funding. |

### 33.3 Dependencies arising from frozen decisions (not invented rules)

These are ambiguities created or revealed by OD-01–OD-12. They are **not** silent product law.

| ID | Dependency | Why it exists |
|----|------------|----------------|
| **DEP-TRIM-K** | Integer conversion of 7% × `n` to tail count `k` (floor vs round vs ceil) | OD-04 froze 7%/7%/n≥15, not the integerisation of 7% of `n`. |
| **DEP-PARTIAL-LEND** | Whether a PARTIALLY_FUNDED this-cycle slice also opens a same-cycle lending request for the unfunded remainder | OD-05 allows partial own funding; lending remains for unfunded capital. Combining them on one rec was not specified. |
| **DEP-PARTIAL-ATOMIC** | Own-capital partial amounts below ₹5,000 (e.g. ₹4,000 free vs ₹18,000 desired) | OD-05 says allocate available free capital; OD-06 forbids lending below ₹5,000. Own-capital partial below one block is allowed; lending that remainder is not. |
| **DEP-ADOPT-MERGE** | Cost basis and entry date when adopting unmanaged quantity into a strategy that already owns the stock | Follows from OD-01 + adoption. |
| **DEP-RECALL-FLOOR** | Whether a portfolio override may set the effective recall period **shorter** than 14 calendar days | OD-07 makes period configurable; 14 days remains the shipped default. A numeric floor other than “platform range config” was not frozen. |

---

## 34. Implementation Sequence

Planning order for later passes. Do not implement in this phase.

1. **Schema & domain foundations** — portfolio controls storage; multiple enabled strategies; holding uniqueness `(profile, stock, owner)` (OD-01); CA quantity stays on the parent owner row (OD-10); adoption; `capital_status` including PARTIALLY_FUNDED; loan/request tables; exit attribution column.
2. **Generation scoping** — per-strategy generate; stale-cancel scoped; unmanaged/other-owner excluded from strategy exits; stop converting unfunded or partial BUY to WATCH (OD-05).
3. **Portfolio SL / trailing / precedence** — daily close, highest close from **that holding’s** entry; migrate config off strategy JSON.
4. **Staggered entry + BUY cooldown + partial fill** — persist **target amount** per owner (OD-12); derive whole-share qty from latest daily close (floor); first entry default 50% of current target amount; subsequent INCREASE = current target amount − filled amount; suppress OPEN/INCREASE below effective min actionable (platform ₹5,000 / portfolio override); BUY cooldown **1 calendar day** from the recommendation opportunity (OD-11); OD-05/OD-06 must not replace the target amount.
5. **Virtual allocation accounting** — % summing to 100; available vs lendable; atomic reservation vs post-fill leftover; no physical sub-accounts.
6. **Lending workflow** — eligibility; ranking by lendable % then lendable amount then arbitrary exact tie (OD-08); display without commit; OD-06 amounts; atomic approve; failure paths; audit. Do not apply FIFO to lenders.
7. **Recall / replenishment** — platform default 14 calendar days + portfolio override (OD-07); among eligible loans, oldest commitment time first (OD-09); amount OD-06 (not whole loan merely because it is oldest); approval default; weakest-position **blocked** until OD-16 (user-selected sells).
8. **History depth & charts** — 5Y + All = full available history; clamp hint; lift 550-day product assumption (OD-17).
9. **Historical ranking** — backtest corpus only (OD-03); 7%/7% trimmed mean, `n ≥ 15` (OD-04); never ship fit-as-rank; never mix live trades.
10. **UI / notifications / help** — Strategy vs Portfolio settings split; per-owner holdings; capital states; lender UX; contextual help sync when behaviour ships.
11. **Migration backfill** — unmanaged default; safe inference; do not merge distinct strategy lots; non-destructive JSON split.
12. **Tests** — acceptance in §35, especially OD-01 same-symbol lots, OD-10 parent-owner CA attachment, OD-11 1-calendar-day BUY cooldown vs OD-07 recall, OD-12 target amount / whole-share floor / min actionable ₹5,000, OD-05 partial vs WATCH, OD-06 reservation vs fill, OD-08 lender ranking vs OD-09 loan FIFO, §24 races.

---

## 35. Acceptance Criteria

### 35.1 Objective / ranking

- Fit score is stored and shown as fit.
- Final ranking, when present, is not a sort by fit alone and is not `fit × p(fit)`.
- Ranking observations come **only** from the defined **backtest** corpus (OD-03). Live trades are absent from that corpus.
- Trimmed mean is symmetric 7% / 7% with `n ≥ 15`. If `n < 15`, trimmed-mean ranking is not computed (OD-04).
- A 100% outcome and a 10% outcome are not scored as equal successes.
- Success flags require positive return **and** NIFTY 50 beat **and** annualized opportunity-cost beat.
- 12% (or configured `r`) is applied as annualized, scaled by **calendar** period length (`T_years = calendar_days / 365`).

### 35.2 Multi-strategy

- Two or more strategies can be enabled in one portfolio and each generate recommendations.
- Generating strategy A does not cancel strategy B’s open recs.
- Strategy A cannot EXIT/REDUCE B’s owned holdings, including when both own the same stock.

### 35.3 Ownership

- Executed strategy buys are owned by that strategy.
- Two strategies may hold the same stock with independent qty/cost/trailing (example 50 + 30 = 80).
- Holdings unique key is not `(profile, stock)` alone.
- Manual adds are unmanaged until adopted.
- Corporate-action quantity follows the parent holding’s owner (**OD-10**): strategy-owned parent stays with that owner; unmanaged parent stays unmanaged and is not auto-assigned to a strategy.
- Example: A = 50, B = 30, unmanaged = 0; after a 2:1 split the owned quantities are A = 100, B = 60, unmanaged = 0. This example illustrates **attachment**, not frozen split mathematics.
- Same-symbol owners MUST NOT be blended into one position in order to apply a CA. Pro-rata is not the governing CA rule.
- Adoption is into one strategy only and does not steal another strategy’s lot.
- Migration does not mass-assign ownership from “the old active strategy” without §10.5.

### 35.4 Unfunded and partial recommendation

- OPEN/INCREASE with **zero** own cash remains that action with `UNFUNDED` (or AWAITING_LENDER_SELECTION).
- OPEN/INCREASE with **some but not all** required capital is `PARTIALLY_FUNDED` (or equivalent evidence) and still OPEN/INCREASE (OD-05). Example: desired ₹18,000, free ₹10,000 → allocate ₹10,000.
- It is never persisted as WATCH solely for cash.
- Telegram/actionability does not treat it as WATCH.

### 35.5 Staggered entry and target amount (OD-12)

- Persisted target is primarily **amount**, not quantity. Quantity is derived from the **latest available daily closing price**.
- Fractional shares are not generated. Default conversion is **floor**; do not add a share that would materially overshoot the intended amount (example: ₹2,500 at ₹600 → 4 shares, notional ₹2,400).
- Whole-share rounding does **not** change the persisted target amount (target stays ₹2,500 in that example).
- First BUY ≈ configured first-entry % (default 50%) of **current target amount**, then OD-05/OD-06 may reduce this-cycle cash. The 50% rule is first-entry only.
- Subsequent INCREASE = `current_target_amount − filled_amount`. Example: target ₹10,000, filled ₹5,000; if target later ₹12,000 → remaining ₹7,000; if later ₹8,000 → remaining ₹3,000. Then convert remaining to whole shares at latest daily close.
- Target-amount changes during OD-11 cooldown are kept and used when the window elapses.
- If current target is below the filled amount, no BUY/INCREASE for a gap.
- Effective minimum actionable BUY/INCREASE: platform default **₹5,000**; portfolio inherits unless it overrides. No strategy override. Opportunity below the minimum → no new OPEN/INCREASE; **target is not reduced**.
- Do not emit zero-quantity or immaterial repeated BUY/INCREASE recs solely to consume whole-share residuals.
- OD-06 atomic reservation does not replace the target amount (example: requirement ₹18,000, reserve ₹20,000, target stays ₹18,000).
- OD-05 partial funding does not reset the target to the funded slice.
- Recommendation calculations use latest daily close, not execution price. Execution-price bands are out of OD-12.

### 35.6 Cooldown

- BUY cooldown is **1 calendar day** (OD-11): Day 0 BUY allowed; Day 1 same stock+strategy BUY rec suppressed; Day 2 elapsed.
- Starts on the BUY **recommendation opportunity / generation cycle**, not on fill, trade approval, or lending commitment.
- Fills do not reset cooldown. Stale-cancel does not clear cooldown. `pending_execution` is not cancelled because of cooldown.
- A second **new** BUY rec for the same **stock+strategy** is suppressed during the window, including regenerating an unapproved BUY on Day 1.
- Another strategy’s BUY of the same stock does not consume, reset, or affect this strategy’s cooldown.
- EXIT/REDUCE/HOLD for that pair are not suppressed.
- BUY cooldown is not the OD-07 14-calendar-day lending recall period.

### 35.7 Exits

- Primary attribution follows strategy exit > stop-loss > trailing > horizon.
- No horizon configured ⇒ no horizon expiry event.
- Horizon `T` is calendar days (OD-02).
- Trailing uses max daily close since **that holding’s** entry × (1 − pct); not unrealized-% proxy; not blended across owners of the same symbol.
- Stop-loss uses daily close, not intraday low; per holding.

### 35.8 Lending and atomic capital

- Lender list contains only eligible strategies.
- Default lender ranking (**OD-08**) is available-for-lending **percentage** descending, then available-for-lending **amount** descending. Exact ties on both keys: any tied eligible lender may be selected; that choice has no product significance.
- Lender ranking MUST NOT use FIFO, oldest loan, largest loan, strategy age, or raw portfolio cash as a business sort key.
- Sort keys are **not** maximum available cash / the portfolio cash ledger.
- Display does not reduce lendable balances.
- Approval revalidation can fail; no commit on failure.
- Race A then B against the same lender: B fails if capital is gone.
- Loan/request amounts use OD-06 (×1.01 then ceil to ₹5,000). Examples: ₹23,700 → ₹25,000; ₹25,000 → ₹30,000; ₹19,000 → ₹20,000; ₹4,000 → ₹5,000.
- The reserved atomic amount is not required to be fully invested; leftover reservation reverts after fill. The 1% is not an auto-invest instruction.
- No multi-lender split of one request.
- Reserve and pending-execution reserved cash cannot be lent.
- Recall before the **effective** period (platform default 14 calendar days, or portfolio override) is rejected (OD-07).
- When several outstanding loans are recall-eligible, replenishment taps the **oldest** by commitment time first (**OD-09**). Exact commitment-time ties: any tied loan may be selected. Do not rank loans by size, lender %, lender amount, borrower strength, or strategy ranking.
- Replenishment requests min needed (OD-06), not necessarily the full selected loan, including when that loan is the oldest.
- Default recall requires user approval.

### 35.9 Weakest position

- Auto-ranking does not use lifetime XIRR / total return / age alone.
- Until OD-16, no invented auto-score silently sells names.

### 35.10 Charts

- 1M/3M/6M/1Y remain; 5Y exists; All is full available history, not since-buy.
- Short history + long range ⇒ data shown + clamp message, no error.

### 35.11 Migration

- Existing cash, transactions, and recs remain.
- No forced liquidation.
- Existing unique `(profile, stock)` holdings become unmanaged or safely inferred; they are not silently split into multi-strategy lots.

### 35.12 Audit

- Every lending approval, rejection, commitment, return, and exit primary reason is persisted and retrievable.

---

## Appendix A — Terms not to conflate

| Do not confuse | With |
|----------------|------|
| Fit score | Final rank / expected return |
| WATCH | Unfunded or partially funded BUY |
| Ranking observations | Live-trading history |
| Atomic allocation / reservation | Amount that must be invested (includes 1% margin) **or** the position **target amount** (OD-12) |
| Target **amount** (source of truth) | Derived whole-share quantity / executable notional |
| OD-12 minimum actionable BUY/INCREASE (platform ₹5,000 / portfolio override) | OD-06 atomic reservation block (₹5,000) |
| Latest daily closing price (recommendation / target qty, OD-12) | Execution price |
| First-entry ~50% of current **target amount** | Subsequent INCREASE = current target amount − filled amount |
| Horizon `T` | Trading sessions |
| Unique `(portfolio, stock)` | V3 holding identity |
| Manual / IPO unmanaged quantity | Corporate-action quantity on an existing parent holding (OD-10) |
| Pro-rata CA allocation | Parent-owner / per-parent-holding CA attachment (OD-10) |
| OD-10 CA owner attachment | OD-14 `close_price` vs `adjusted_close_price` |
| Recommendation expiry (hours) | Strategy horizon |
| BUY cooldown (1 calendar day, OD-11) | OD-07 lending recall (shipped default 14 calendar days) |
| BUY cooldown start (recommendation opportunity) | Fill / trade approval / lending commitment |
| V1 trailing proxy (unrealized %) | V3 trailing (highest close) |
| Available cash | Available-for-lending |
| Available-for-lending **percentage** | Available-for-lending **amount** (both are OD-08 lender keys; amount is not raw portfolio cash) |
| Prospective **lender** (strategy) | Outstanding **loan** |
| OD-08 lender ranking (% then amount then arbitrary exact tie) | OD-09 loan tap (FIFO by commitment time then arbitrary exact tie) |
| FIFO / oldest loan | Lender selection |
| Strategy allocation % | A physical bank account |
| Displayed lenders | Committed loan |
| Portfolio stop-loss | Strategy-specific exit rules |
| No horizon | Mandatory infinite expiry |

## Appendix B — Current implementation pointers (baseline only)

Informational for implementers locating V1 code. Not requirements.

- One active strategy: `StrategyRegistrySupport::activate`
- Unfunded → WATCH: `RecommendationGenerationPipeline::applyCapitalOutcomes`
- Profile-wide stale cancel: `cancelStaleRecommendations`
- Trailing proxy: `ExitStrategyEvaluator` case `trailing_stop`
- Presentation trailing (V3-aligned formula): `HoldingPresentationService`
- Since-buy prices: `priceHistoryForHolding`
- Chart ranges: `priceVolumeChartTypes.js` (`all`, `1m`, `3m`, `6m`, `1y`)
- History depth default: `HISTORY_DEPTH_TARGET_DAYS` = 550
