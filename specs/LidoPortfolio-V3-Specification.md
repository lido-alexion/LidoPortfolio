# Lido Portfolio V3 Specification

| Field | Value |
|-------|-------|
| **Title** | Lido Portfolio V3 Specification |
| **Status** | Review |
| **Version** | 0.27 |
| **Owner** | Product Specification / Architecture |
| **Last Updated** | 2026-08-19 |
| **Implementation Status** | Not started |
| **Depends On** | Frozen V3 product decisions (2026-08-14 through 2026-08-19), including OD-01–OD-24 and resolved **DEP-TRIM-K**, **DEP-FIT-BAND-10**, **DEP-PARTIAL-LEND**, **DEP-PARTIAL-ATOMIC**, **DEP-ADOPT-MERGE**, **DEP-WEAKEST-PRICE**, **DEP-WEAKEST-HISTORY**, and **DEP-RECALL-FLOOR**; Architecture Impact Report; V1/V2 as-built baseline in `implementation.md` |
| **Referenced By** | Future V3 implementation passes |
| **Related Specifications** | [architecture/domains/Strategy-Configuration-Specification.md](architecture/domains/Strategy-Configuration-Specification.md), [architecture/portfolio/Cash-Management-Specification.md](architecture/portfolio/Cash-Management-Specification.md), [architecture/domains/Recommendation-Engine-Specification.md](architecture/domains/Recommendation-Engine-Specification.md), [architecture/governance/SPECIFICATION_DECISIONS.md](architecture/governance/SPECIFICATION_DECISIONS.md) (SD-026, SD-029, SD-010) |

---

## Document control

This is the **authoritative V3 product and architecture specification**. It converts frozen product decisions into an implementation-ready contract.

- **V3 work must follow this document**, not V1 engine specs, where they conflict.
- V1/V2 specifications remain the historical record of shipped behaviour. They are **not** rewritten here.
- The Architecture Impact Report is the **as-built baseline**, not the V3 product requirement set. Implementation limitations (for example the V1 ~550-day OHLCV default `HISTORY_DEPTH_TARGET_DAYS`) are **not** V3 product caps. **OD-17 (frozen):** V3 has **no** maximum OHLCV history-depth ceiling.
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
| **Final ranking** | Ordering of opportunities for capital attention, based on return quality | A sort by fit score; OD-23 capital fill order |
| **Capital fill order (OD-23)** | Order in which a strategy’s own valid BUY/INCREASE recs receive capital when return-quality ranking is not computable | V3 return-quality ranking; a presentation of target amount as rank; a silent fallback to fit-as-rank |

V3 **rejects** ranking by `Strategic Score × Probability` when probability is derived from the same score, and **rejects** treating a 100% historical return and a 10% historical return as equivalent successes.

### 1.3 Architectural principles

1. **Portfolio is the account.** One portfolio is one real or paper trading account. Multiple portfolios may exist for hypothesis testing. Broker automation may later constrain how many portfolios can be live; V3 does not implement broker automation (SD-010 remains: manual execution).
2. **Many strategies may run concurrently in one portfolio.** V3 supersedes the V1 “exactly one active strategy per portfolio” product rule (SD-029).
3. **Separation of concerns.** Strategy identifies candidates, scores fit, sizes a *target* position from conviction, and emits strategy-specific BUY/SELL/HOLD/EXIT. Portfolio owns cash reserve (**OD-19** formula in §22), common stop-loss, common trailing stop, portfolio position/risk limits, and inter-strategy capital coordination.
4. **Ownership fences.** A strategy may not reduce or exit a holding it does not own. Manual holdings are unmanaged until adopted.
5. **A recommendation can be valid and unfunded or only partially funded.** Lack of full capital must not convert a BUY/INCREASE into WATCH. Partial funding is allowed (OD-05).
6. **Lending is always an explicit user decision.** Displaying options does not commit capital. The backend is authoritative at approval time.
7. **Raw daily `close_price` only** for portfolio stop-loss and trailing stop (**OD-14**). No `adjusted_close_price` for those calculations. No intraday low.
8. **Horizon is optional** and, when set, is measured in **calendar days** (OD-02). Absence of a horizon is not an expiry event.
9. **Do not turn current implementation limits into product requirements.**
10. **Multiple strategies may own the same stock** in one portfolio (OD-01). Position, cost, trailing, exit, allocation, attribution, and corporate-action **ownership** are per owner, not per symbol alone (OD-10). Holdings of the same stock MUST NOT be blended into one portfolio-level position for those purposes.
11. **Ranking statistics use the defined backtest corpus only** (OD-03). Live-trading history is not a ranking observation source.

### 1.4 As-built baseline (informational)

V1/V2 today: one active strategy drives generation; strategy `config_json` owns cash reserve, position caps, and exits; unfunded OPEN/INCREASE are demoted to WATCH; holdings have no strategy owner; trailing stop in the exit engine is an unrealized-% proxy; the holdings chart loads bars since first buy; stored OHLCV campaign default is ~550 days (`HISTORY_DEPTH_TARGET_DAYS`). **None of those are V3 requirements.** They are the starting point to change. **OD-17:** V3 does not replace 550 with another numeric ceiling; it requires **all available** OHLCV history.

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

- portfolio cash reserve (**OD-19**; not a strategy setting)
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

A holding has quantity, cost basis, owner (strategy or unmanaged), and when strategy-owned: **target amount** (source of truth, OD-12), derived whole-share quantity, filled amount/quantity, and entry date for trailing/stop calculations. Stop-loss **cost basis** is the weighted-average **actual execution** cost of the current ownership episode (**OD-13**), not first-fill price and not target amount. These fields MUST NOT be blended across owners of the same symbol. Target quantity is **not** the primary persisted unit.

**OD-10 (frozen):** corporate-action quantity follows the **parent holding’s owner**. A CA applicable to a holding identified by `(portfolio, stock, owner)` adjusts **that** owner’s position quantity. It MUST NOT first blend multiple owners of the same stock into one portfolio-level position. Unmanaged parent holdings stay unmanaged. OD-10 does not freeze CA mathematics or cost/trailing/target restatement. **OD-14 (frozen)** chooses raw `close_price` for SL/trailing comparison and the OD-12 reference price; that choice does **not** restatement-solve corporate actions.

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

**DEP-ADOPT-MERGE:** a holding bought with borrowed capital is owned by the **borrowing** strategy. The lender does **not** co-own that holding.

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

The portion of a strategy’s unused allocation that is eligible to be lent under §6 and §8, after the strategy’s **OD-24** minimum retained capital and the other frozen lending constraints. It is **not** “all cash the strategy could theoretically spend” and **not** “maximum available portfolio cash”. It is **not** the OD-19 portfolio cash reserve and **not** OD-20 Unallocated Cash.

Available-for-lending **percentage** and available-for-lending **absolute amount** are the OD-08 ranking keys for a prospective **lender** (a strategy). They MUST NOT be used to choose among outstanding **loans** (that is OD-09).

### 2.11 Lent capital

Capital of a lender strategy that has been **committed and transferred as a loan** to a borrower strategy. The lender records it as a **loan receivable**. It remains part of the lender’s virtual deployed/allocation **accounting claim** (lent capital), but it is **not** ownership of the borrower’s resulting holding (**DEP-ADOPT-MERGE**). It is not available for the lender’s new buys until returned.

An outstanding loan is a discrete committed amount with a lender, a borrower, a **commitment time**, and remaining principal. OD-09 uses commitment time (FIFO) to select among recall-eligible loans. That operation is not lender ranking.

### 2.12 Borrowed capital

Capital a borrower strategy has received via an approved loan. It is **deployable borrowed capital**: it increases the borrower’s immediately usable capital for the funded purpose and remains outstanding until returned (in atomic blocks). The borrower records the corresponding **loan obligation**.

Deployable borrowed capital is **not** a change to the configured strategy `allocation_pct` and MUST NOT be treated as permanently increasing that strategy’s policy share (**DEP-ADOPT-MERGE**). Investments funded with it are owned by the **borrowing** strategy.

### 2.13 Capital request

A user-visible request to borrow from **exactly one** eligible lender, typically to fund an unfunded recommendation or the unfunded remainder of a **PARTIALLY_FUNDED** recommendation (**DEP-PARTIAL-LEND**). When that request funds a PARTIALLY_FUNDED remainder, the requested **loan amount** is that remainder rounded **up** to the next ₹5,000 atomic loan block when it is not already a multiple (**DEP-PARTIAL-ATOMIC**), and is at least ₹5,000. A remainder below ₹5,000 does **not** make lending ineligible. Displaying a request does not reserve capital.

V3 does **not** allow one recommendation’s funding gap to be split across multiple lenders.

### 2.14 Capital approval

The user act that, after backend revalidation, commits a capital request. Only then is committed-to-lending / lent capital updated.

### 2.15 Exit event

The execution-level fact that a position (or the recommended quantity) is to be sold in full (EXIT) or in part (REDUCE). Broker/ledger execution is the same class of event regardless of why it was recommended.

### 2.16 Exit attribution

The ordered reason recorded for analytics, reporting, and historical evaluation. Precedence is defined in §13. Attribution is not a different broker order type.

### 2.17 Strategy minimum retained capital (OD-24)

The strategy-level accounting amount that MUST be retained as the protected “one opportunity” floor before unused allocation can be treated as available-for-lending. Formula in §5.5. The result is rounded to the **nearest integer rupee**. It is **not** a physical cash account, **not** a strategy bank/cash sub-account, **not** another portfolio cash reserve, and **not** `required_cash_reserve` (OD-19).

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
| Cash reserve (`portfolio_cash_reserve_pct` / `required_cash_reserve`, OD-19) | Portfolio |
| Stop-loss | Portfolio |
| Trailing stop | Portfolio |
| Portfolio-wide position/risk caps | Portfolio |
| Lending / recall coordination | Portfolio (between strategies) |
| Execution (manual ledger fill; future broker) | Portfolio / execution engine |

### 3.4 Strategy allocation percentage

Each enabled strategy has `allocation_pct`. Sum across enabled strategies = 100% of **investable capital** as defined in §22 (strategy-owned MV + investable cash, **excluding** unmanaged MV).

Changing allocation percentages is a user policy change. It does not by itself force immediate sells. Effects on lendable surplus and available capital are computed from the new policy at the next capital calculation. Forced de-risking because allocation was reduced is **not specified** here and must not be invented (if needed later, add an OPEN or a new decision).

### 3.5 Minimum and maximum holdings

- **Recommended minimum holdings:** advisory for **generation**. The engine MUST NOT open weak/low-quality names solely to hit the minimum. UI MAY warn when below the recommended minimum. The configured count is also the **OD-24** divisor for that strategy’s minimum retained capital (§5.5). OD-24 does **not** change this advisory generation rule. The divisor is **recommended** minimum holdings, **not** hard `max_holdings`.
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

For a strategy, historical outcomes are collected from the **defined backtest corpus only** (§4.2.1), bucketed by **fit level** (fit bands, §4.2.2). Each outcome has return, holding period, benchmark return, annualized/XIRR return, and a success flag (§19).

**OD-18 (frozen):** annualized return (CAGR/XIRR) MAY be used as **ranking evidence** only when that backtest holding period is **≥ 30 calendar days**. For holding periods **< 30 calendar days**, annualized return MUST NOT contribute to ranking. The outcome’s **simple return** remains a valid backtest outcome. OD-18 does **not** change the §19 success definition.

**OD-03 (frozen): BACKTESTS ONLY.** Ranking statistics MUST use the defined backtest corpus (§4.2.1). The user’s **live-trading history MUST NOT** be used as ranking observations. `ReviewEngine` live/ledger outcomes remain observational portfolio review and MUST NOT be fed into ranking.

The current `ReviewEngine` is **not** the V3 ranking engine. Extending the strategy backtest simulator so it can supply a sufficient, defined corpus is in scope for ranking.

#### 4.2.1 Defined backtest ranking corpus (OD-03)

The **ranking corpus** for a strategy version is the set of **unique historical trade/opportunity observations** drawn from that strategy version’s **latest completed backtest run**.

**Authoritative run selection (frozen):**

1. For each `strategy_version_id`, use the **latest completed** backtest run only.
2. That run MUST use the **maximum historical period** supported by the **unique historical market data** available to the system at run time (aligned with **OD-17**: all available OHLCV history; no V3 product-level depth ceiling).
3. **Previous completed backtest runs** for the same strategy version MUST **NOT** be combined with the latest run when they overlap the same historical period.
4. Re-running a backtest over the same historical period MUST **NOT** cause the same underlying observations to receive additional statistical weight.
5. The corpus is defined by **unique observations**, not by the number of times a backtest was executed.
6. If a later completed backtest **extends** the available historical period, that newer run becomes the authoritative corpus and MAY contain substantially more observations.

**Observation ageing (frozen):**

- Historical observations do **NOT** age out merely because they are old.
- Do **NOT** introduce observation-age decay, rolling-window expiry, or time-based exclusion of older backtest outcomes from the ranking corpus.
- More historical data is desirable because a larger population of unique observations reduces sampling error.

**Concepts that MUST NOT be conflated:**

| Concept | Meaning |
|---------|---------|
| **Historical data depth** | How much OHLCV / market history is available and simulated in the authoritative backtest run |
| **Number of unique observations** | Distinct trade/opportunity outcomes in the corpus (the statistical sample `n`) |
| **Number of backtest executions** | How many completed runs exist; only the latest authoritative run supplies ranking observations |

If no completed backtest exists for the strategy version, or the authoritative run has not yet produced a usable corpus, return-quality ranking is **not computable** and capital fill uses **OD-23**.

#### 4.2.2 Fit bands and adaptive sparsity (**DEP-FIT-BAND-10**, frozen)

Return-quality ranking groups backtest outcomes by **comparable strategy-fit level** using **10-point fit bands** as the normal/default granularity.

**Normal 10-point bands (frozen):**

- Fit score is on the strategy’s 0–100 scale.
- Default band width = **10 points**.
- Example band keys: `[0,10)`, `[10,20)`, …, `[90,100]` (exact boundary handling is an implementation detail; bands MUST be deterministic and MUST NOT overlap).
- A backtest outcome’s band is determined by the **fit score at entry** (or equivalent opportunity-time fit) for that observation.
- Strategy `capital_allocation.score_bands` are **conviction target-sizing** bands (§4.6). They are **not** ranking fit bands and MUST NOT be substituted for **DEP-FIT-BAND-10** banding.

**Adaptive sparsity handling (frozen):** when a normal 10-point band does not have sufficient observations for trimmed-mean ranking, apply the following **in order** for that sparse band:

1. **Neighboring-band merge (up to two expansions):** merge the sparse band with an **adjacent neighboring** band (implementation chooses left or right neighbor deterministically). Repeat merge expansion **at most twice** for that band lineage. Do **not** perform further band merging beyond this maximum.
2. **Minimum observation threshold reduction (after merges):** if the merged observation count is still below the normal minimum, reduce the required minimum **once** from **15 → 12**; if still below, reduce **once** from **12 → 10**.
3. **Hard floor:** do **not** reduce the minimum below **10**.
4. **Ineligibility:** if the observation count still cannot satisfy the minimum after the permitted merges and threshold reductions, the band (or merged band group) remains **statistically ineligible** for return-quality ranking. Capital fill for opportunities mapped to that fit level uses **OD-23** for that strategy; do **not** silently rank by fit.

**Persistence and audit (frozen):**

- The **resulting fit band** used for aggregation (including any merged band span) MUST be persisted as ranking/audit evidence for each aggregated return-quality result.
- The **adjustments applied** to reach eligibility (neighbor merges performed, threshold step used: 15, 12, or 10) MUST also be persisted as ranking/audit evidence.

**Ranking statistical confidence (frozen):**

- A **ranking statistical confidence** score MUST be calculated and preserved alongside each fit-band aggregated return-quality result.
- Confidence is a **diagnostic indicator** of the statistical reliability of that aggregated observation set.
- Confidence MUST decrease as statistical compromises are introduced, including:
  - neighbor-band merging;
  - lowering the observation threshold from 15 to 12;
  - lowering it further from 12 to 10.
- Confidence MUST **NOT** be treated as:
  - a probability of success;
  - a prediction of future returns;
  - an input to return-quality calculation;
  - a recommendation ranking key;
  - a capital allocation weight;
  - an eligibility gate (beyond the explicit minimum-`n` rules above);
  - an OD-23 tie-break.
- Confidence is **user-facing and audit-facing** at this stage only.

### 4.3 Return quality, not hit rate

Final ranking MUST be based on **expected / observed return quality** — the magnitude of returns associated with that fit level — not on how often outcomes were merely positive.

A 100% historical return and a 10% historical return are not equivalent successes.

When return quality for ranking uses annualized return / CAGR / XIRR, **OD-18** applies: those annualized figures are ranking evidence only if the backtest holding period is **≥ 30 calendar days**.

“Probability of success” (frequency of passing §19) MAY be reported as a diagnostic. It MUST NOT be the final ranking metric.

Success criteria MAY differ by strategy only in evaluation-window / horizon configuration (§4.5, §16, §20). The **broad** success definition in §19 is shared unless a future decision replaces it. V3 does **not** impose a single absolute return target (for example “must make 30%”) across strategies.

### 4.4 Aggregation

Mean, median, and trimmed mean are statistical choices.

**OD-04 (frozen):** where aggregation is required for fit-band expected return / ranking, use a **symmetric trimmed mean**:

- Trim **7%** from the **lower** tail.
- Trim **7%** from the **upper** tail.
- Trimming is **symmetric**.
- **Normal minimum sample size = 15** observations per fit band (before **DEP-FIT-BAND-10** adaptive sparsity handling).

If a fit band cannot become statistically eligible after the permitted **DEP-FIT-BAND-10** neighbor merges and minimum-threshold reductions (15 → 12 → 10; never below 10), trimmed-mean ranking for that band is **not eligible**. Do not compute a trimmed-mean rank from a smaller sample. Do not silently fall back to **ranking** by fit score (§4.6). Capital fill when return-quality ranking is not computable for an opportunity uses **OD-23** (descending target investment amount among that strategy’s own valid BUY/INCREASE recs). Fit MAY be used only as OD-23’s **second** tie-break (after target amount); it MUST NOT become the V3 ranking key.

**Procedure (normative):**

1. Resolve the opportunity’s fit band using **DEP-FIT-BAND-10** (normal 10-point band, then permitted neighbor merges and minimum-threshold reductions).
2. Collect the backtest return-quality observations for the **resulting** fit band (`n` unique observations from the authoritative corpus, §4.2.1).
3. After permitted neighbor merges, let `n` be the observation count for the resulting band. Determine the **effective minimum** `n_min`:
   - if `n ≥ 15`: `n_min = 15` (normal);
   - else if `n ≥ 12`: `n_min = 12` (one permitted threshold reduction from 15);
   - else if `n ≥ 10`: `n_min = 10` (second permitted threshold reduction from 12);
   - else: stop — return-quality ranking for this band is not eligible. Use **OD-23** for capital fill for opportunities mapped to this band. Do **not** compute `k` as a substitute for eligibility.
5. Sort the `n` observations in ascending order.
6. Compute the integer trim count `k` (**DEP-TRIM-K**, frozen):

```text
k = nearest_integer(0.07 × n)
```

`nearest_integer` means: round to the closest integer; **exact fractional .5 values MUST round upward** (toward +∞). This rule is **not** language/runtime `round()` and MUST NOT use banker's rounding (round-half-to-even) or any other .5 tie behaviour.

For this non-negative domain, the deterministic equivalent is:

```text
k = floor(0.07 × n + 0.5)
```

7. Remove **the same** `k` observations from the **lower** tail and **the same** `k` from the **upper** tail.
8. Compute the arithmetic mean of the remaining middle observations.
9. Persist the resulting fit band, applied **DEP-FIT-BAND-10** adjustments, effective `n_min`, and **ranking statistical confidence** (§4.2.2) as ranking/audit evidence.

Ranking eligibility (effective `n ≥ n_min` with `n_min ∈ {15, 12, 10}` per **DEP-FIT-BAND-10**) and trim-count calculation (`k` from 7% of `n`) are distinct. Eligibility does not change `k`; `k` does not replace the minimum-`n` gate.

**Normative examples** (`0.07 × n` → `k`):

| `n` | `0.07 × n` | `k` |
|-----|------------|-----|
| 15 | 1.05 | 1 |
| 20 | 1.40 | 1 |
| 25 | 1.75 | 2 |
| 30 | 2.10 | 2 |
| 35 | 2.45 | 2 |
| 50 | 3.50 | 4 |
| 100 | 7.00 | 7 |

`n = 50` is an exact-.5 case and MUST yield `k = 4` (round upward), not 3.

Trimming MUST be deterministic (same `n` and same sorted observations → same `k` and same mean).

### 4.5 Strategies without a fixed horizon

- Prefer **XIRR / annualized return** over the actual holding period (§20), subject to **OD-18**: annualized/XIRR ranking evidence requires backtest holding period **≥ 30 calendar days**.
- Do **not** penalise a holding merely because it was held a long time before the thesis began working (lifetime XIRR can be mediocre while recent/windowed performance is strong).
- Historical evaluation windows MUST be **strategy-configurable**.
- A configurable **evaluation window** (strategy-level) MAY be used for ranking current holdings and for weakest-position selection (§17, **OD-16**).

### 4.6 Ranking pipeline (normative)

```text
Fit score (eligibility + decision thresholds)
    ↓
Historical outcomes at comparable fit (**backtest corpus only**)
    ↓
Return-quality aggregation (trimmed mean of returns / annualized returns as specified; **OD-18:** annualized/CAGR/XIRR ranking evidence only if holding period ≥ 30 calendar days)
    ↓
Final ranking of opportunities
```

Capital allocation among a strategy’s own buy drafts, once ranked, still respects cash, max holdings, staggered entry, partial funding (OD-05), and portfolio caps. Allocator weights MUST follow **ranking order / return quality**, not raw fit score. (V1 `ScorePriorityCapitalAllocator` weighting by fit is **not** V3 behaviour.)

If return-quality ranking is **not computable** for an opportunity (no statistically eligible fit band after **DEP-FIT-BAND-10**, or authoritative backtest corpus unavailable per §4.2.1), the UI MUST present fit as fit, not as rank. Treating “rank = fit” as a silent fallback is forbidden. Fit MUST NOT be used as a replacement for return-quality ranking.

Conviction **target sizing** (score bands → target position, subject to caps) remains a strategy function and is **not** the same as ranking.

**OD-23 (frozen):** When return-quality ranking is **not computable** because the authoritative backtest corpus is unavailable or no fit band is statistically eligible for the opportunity (after **DEP-FIT-BAND-10**), capital **fill order** among that strategy’s own valid BUY/INCREASE recommendations MUST be **descending target investment amount / conviction amount** order (higher target amount first). This is a **capital fill order**, **not** V3 return-quality ranking. MUST NOT label or present target-amount order as the V3 ranking. MUST NOT emit or display a return-quality rank derived from this fill order.

When return-quality ranking **is** computable (authoritative corpus available and the opportunity’s fit band is statistically eligible per **DEP-FIT-BAND-10** and OD-04), the allocator MUST continue to follow **ranking order / return quality** as specified above. OD-23 does **not** change that ranked path.

When target investment amount / conviction amount is equal, break ties in this **exact** order:

1. **fit score** (higher first)
2. **alphabetical** order of the stock listing symbol (ascending)

The alphabetical tie-break MUST make the ordering deterministic (same inputs → same fill order).

OD-23 does **not** use **ranking statistical confidence** (§4.2.2) as a tie-break. OD-23 does **not** introduce a strategy-configurable conviction sub-score or any new strategy setting for fallback ordering.

OD-23 does **not** change OD-05 partial funding, OD-06 atomic reservation, reserve protection, or strategy allocation limits. Unfunded and partially funded BUY/INCREASE remain valid (not WATCH). OD-23 does **not** change **OD-24** or any **DEP-*** item.

---

## 5. Capital Allocation

### 5.1 Portfolio-level capital

See §22. Physical cash is one pool. Investable capital is what remains after the portfolio cash reserve (`required_cash_reserve` per **OD-19**) and after cash already reserved for pending-execution buys.

### 5.2 Strategy-level allocation percentages

Enabled strategies receive `allocation_pct` summing to 100% of investable capital.

**Strategy allocated capital** (virtual) =

`investable_capital × allocation_pct / 100`

**Strategy deployed capital** includes:

- market value of strategy-owned holdings
- cash reserved for that strategy’s pending-execution buys
- capital currently lent out (loan receivable; **not** ownership of the borrower’s resulting holding — **DEP-ADOPT-MERGE**)
- capital committed-to-lending (approved, not yet settled into lent — if those are distinct steps, both reduce availability)

Borrowed capital used to fund a buy becomes a **borrower-owned** holding. It does **not** become a lender-owned holding and does **not** change configured `allocation_pct`.

**Strategy unused allocation** =

`max(0, allocated − deployed)`

Unused allocation is the starting point for own buys and for lendable surplus. It is not automatically isolated cash. It is **not** the Cash UI **Unallocated Cash** bucket (**OD-20**). Unused allocation MAY contain capital above the **OD-24** retained floor; only the surplus above that floor can become available-for-lending (§8.2).

### 5.3 No permanent isolated cash bucket

A strategy does **not** receive a physical sub-account. All rupees sit in portfolio cash until a buy executes or a reservation is recorded. Allocation is a **constraint and accounting claim**. The Cash UI **Unallocated Cash** bucket (**OD-20**) is presentation-only over this same pool; it is not a strategy sub-account and not a new ledger.

### 5.4 Lending of unused allocation

A strategy MAY lend otherwise unused allocation to another strategy, subject to §6–§8. Lending is portfolio-level coordination, not a private side-deal outside the portfolio. **DEP-ADOPT-MERGE:** the borrower owns investments funded with the loan; the lender records a loan receivable. Lending does **not** change configured `allocation_pct`.

### 5.5 Minimum free capital (“one opportunity”) (OD-24)

**OD-24 (frozen):** For each strategy, the minimum retained capital MUST be:

```text
minimum_retained_capital =
    nearest_integer(
        strategy_capital_allocation
        /
        recommended_minimum_holdings
    )
```

Where:

- `strategy_capital_allocation` is the rupee amount currently allocated to that strategy from the portfolio’s **investable capital** (`investable_capital × allocation_pct`, §22). It is **not** strategy deployed capital, **not** portfolio NAV, **not** total portfolio cash, **not** `required_cash_reserve`, and **not** total portfolio value.
- `recommended_minimum_holdings` is that strategy’s configured recommended minimum number of holdings/opportunities (§3.5). It is **not** hard `max_holdings`.
- The result is the capital that the strategy MUST retain as its minimum free capital for **one future opportunity**, rounded to the **nearest integer rupee**.
- `nearest_integer` means: round to the closest integer; **exact fractional .5 values MUST round upward** (toward +∞). This rule is **not** language/runtime `round()` and MUST NOT use banker's rounding (round-half-to-even) or any other .5 tie behaviour. For this non-negative rupee domain, `floor(value + 0.5)` is the deterministic equivalent, where `value` is the non-negative division result.
- In this specification, `minimum_free_capital` (for example in §8.2) **is** `minimum_retained_capital`.

This is a **strategy-level accounting constraint**. It is **not** a physical cash account and MUST NOT create a strategy bank/cash sub-account. It does **not** create another portfolio cash reserve. It does **not** modify `required_cash_reserve` / OD-19. It does **not** modify `available_physical_cash`. It does **not** change the 100% strategy allocation rule. It does **not** change BUY funding, OD-05 partial funding, or OD-06 pending-execution reservation. It does **not** change OD-23 capital fill order or the return-quality ranking path when computable per **DEP-FIT-BAND-10** and OD-04. It is **not** derived from B1/B2 configuration, conviction, fit, target size, or ranking. It is **not** a percentage-based formula.

It applies to unused-allocation / lending eligibility accounting: the strategy MUST retain at least this amount before excess unused allocation can be considered available for lending. Lending MUST NOT consume the retained amount. OD-24 MUST NOT be used to hard-block external/broker withdrawals (OD-21).

Example (normative illustration):

- Investable capital = ₹10,00,000
- Momentum allocation = 75%
- Momentum `strategy_capital_allocation` = ₹7,50,000
- Recommended minimum holdings = 5

```text
minimum_retained_capital = nearest_integer(₹7,50,000 / 5)
                         = ₹1,50,000
```

Momentum must retain at least ₹1,50,000 as its protected “one opportunity” amount.

Further examples (normative):

```text
₹7,50,000 / 7 = ₹1,07,142.857...
minimum_retained_capital = ₹1,07,143

₹7,50,000 / 8 = ₹93,750
minimum_retained_capital = ₹93,750
```

**OD-05 (frozen): PARTIAL FUNDING IS ALLOWED** for deploying a strategy’s **own** available free capital into an OPEN/INCREASE opportunity:

- If desired/required capital exceeds currently available free capital, the allocator MAY use the available free capital to take a **partial** position.
- Example: desired allocation = ₹18,000, available free capital = ₹10,000 → allocate ₹10,000. Do **not** convert the opportunity to WATCH.
- Capital status remains a separate axis from opportunity direction (§23).
- Partial funding is the this-cycle executable amount; the persisted **target amount** is unchanged (OD-12). Remaining BUY/INCREASE still follows staggered-entry rules (§12) and OD-11. Do **not** reset the target to the amount funded this cycle.
- If available free capital is **zero**, the recommendation stays a valid OPEN/INCREASE with `UNFUNDED` and may enter the lending workflow (§7). It still MUST NOT become WATCH.

Partial funding does not waive portfolio reserve, pending-execution reservations, or ownership fences. The rupee size of the reserve is `required_cash_reserve` (**OD-19**); OD-05 does not change that formula.

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

**Lending / borrowing / recall** amounts MUST be integer multiples of ₹5,000. Lending cannot be split below the block. Borrowing cannot request less than one block (₹5,000). **DEP-PARTIAL-LEND** / **DEP-PARTIAL-ATOMIC:** ₹5,000 is the minimum **loan amount**, not a minimum funding requirement and not a minimum unfunded remainder. After partial own funding, the actual loan is the unfunded remainder rounded **up** to the next ₹5,000 block when it is not already an exact multiple.

**Interaction with OD-05:** if `atomic_allocation` is larger than available free capital, **partial funding is allowed**. Allocate the available free capital (this-cycle), keep OPEN/INCREASE, do not convert to WATCH. Own-capital partial amounts are **not** required to be re-ceiled through the 1% formula in a way that exceeds available cash (that would defeat partial funding).

**DEP-PARTIAL-LEND (frozen):** a PARTIALLY_FUNDED this-cycle slice with an unfunded remainder MUST be eligible to open a **same-cycle** lending request. Lending is not deferred merely because partial own-capital funding already occurred. A remainder smaller than one atomic block MUST NOT be treated as ineligible for lending, and the recommendation MUST NOT remain without a lending path merely because the requirement is below ₹5,000. If lending is approved, borrowed capital is available immediately for the intended funding/execution path. The persisted **target amount** is unchanged (OD-12). Lending remains an explicit user decision; approval is authoritative.

**DEP-PARTIAL-ATOMIC (frozen):** when that remainder is eligible for same-cycle lending, the **required** loan is the unfunded remainder. The unfunded remainder is the this-cycle required/target amount minus already allocated own capital (example: target ₹18,000, own ₹4,000 → remainder ₹14,000). It is **not** `atomic_allocation` minus own capital. The **actual** loan MUST be at least that remainder and MUST use the ₹5,000 atomic loan unit. If the remainder is not an exact multiple of ₹5,000, round the loan amount **UP** to the next ₹5,000 block. Do **not** reduce the loan below the remainder merely to preserve the atomic denomination. Do **not** reject lending merely because the remainder is below ₹5,000. This is a **loan-size** rule, not a minimum funding requirement. Excess borrowed capital is permitted and remains available after the intended execution.

```text
loan_amount = ceil(unfunded_remainder / 5000) × 5000
```

for `unfunded_remainder > 0`. This is **not** the OD-06 1% reservation formula.

Normative examples:

| Unfunded remainder | Loan |
|--------------------|------|
| ₹3,000 | ₹5,000 |
| ₹14,000 | ₹15,000 |
| ₹15,000 | ₹15,000 |

Worked example: own capital ₹4,000, target ₹18,000, unfunded remainder ₹14,000 → loan ₹15,000; excess borrowed capital ₹1,000 remains available.

### 5.7 Lending limits

Maximum lending (percentage of unused allocation and/or absolute amount) MUST be configurable at portfolio level. Lending cannot use the portfolio cash reserve (`required_cash_reserve`, **OD-19**). Lending cannot consume the strategy’s **OD-24** minimum retained capital. Lending cannot use cash reserved for pending execution or already committed-to-lending.

### 5.8 Allocation stability

The portfolio MUST NOT continuously rewrite strategy `allocation_pct` because the number of today’s signals changed. Signal volume affects recommendations and lending demand, not the policy split.

### 5.9 Capital fill order among a strategy’s own BUY/INCREASE recs (OD-23)

When return-quality ranking is computable (authoritative corpus available and opportunity fit band statistically eligible per **DEP-FIT-BAND-10** and OD-04), the allocator follows **ranking order / return quality** (§4.6). When it is **not** computable (corpus unavailable or no statistically eligible band), fill that strategy’s own valid BUY/INCREASE recommendations in **descending target investment amount / conviction amount** order, then OD-23 tie-breaks (fit → alphabetical symbol ascending). That sequence is **fill order**, not V3 ranking, and MUST NOT be labelled as ranking. Fit is not a fallback ranking key. OD-05, OD-06, reserve protection, and strategy allocation limits still apply.

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

- requested amount ≥ ₹5,000 (minimum **loan amount**; not a minimum remainder)
- requested amount is a multiple of ₹5,000
- for a PARTIALLY_FUNDED unfunded remainder, requested amount = `ceil(unfunded_remainder / 5000) × 5000` (**DEP-PARTIAL-ATOMIC**)
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

The previously frozen **14-day** figure remains applicable as the **shipped platform default**. It MUST NOT be replaced by a hard-coded non-configurable engine constant that ignores these settings. **14 calendar days is not a hard minimum/floor.**

Period length is in **calendar days** (consistent with OD-02).

Clock start: the timestamp of successful capital commitment (approval), unless a later settlement timestamp is introduced; do not invent a second clock.

**DEP-RECALL-FLOOR (frozen): OPTION B.** A portfolio-level recall-period override MAY be either **shorter or longer** than the platform’s shipped 14-calendar-day default. Do **not** introduce a separate minimum recall-period value. Do **not** clamp portfolio overrides to 14 days. Do **not** reinterpret 14 days as a minimum.

```text
effective_recall_period =
    portfolio_recall_period_override
    if configured
    else platform_default_recall_period
```

The portfolio override is authoritative when set. Examples (normative):

- No portfolio override → effective period = 14 days
- Portfolio override = 7 days → effective period = 7 days
- Portfolio override = 3 days → effective period = 3 days
- Portfolio override = 14 days → effective period = 14 days
- Portfolio override = 21 days → effective period = 21 days

### 6.6 Recall

A lender strategy MAY request return of borrowed capital when it needs capital for a new opportunity (replenishment, §6.7) or when the user initiates recall.

- Recall MUST respect the effective minimum lending period (§6.5). **OD-07** controls **when** a loan becomes recall-eligible.
- If multiple outstanding loans are recall-eligible, **which loan is selected** is **OD-09** (§6.7, §9.7): oldest eligible loan first (FIFO by commitment time). That is not lender ranking (OD-08).
- Return/recall **requires user approval by default**.
- A user-configurable option for **automatic return** MAY exist; it is off unless the user enables it. Frozen governance: default is approval required.
- Returning capital MAY require the borrower to exit one or more positions if the borrower lacks free cash to repay.
- Loan repayment is a **loan/capital** transaction. It does **not** transfer stock ownership from the borrower to the lender (**DEP-ADOPT-MERGE**).
- The borrower MUST release the **weakest eligible** positions first (§17).
- “Weakest” MUST NOT be defined as lowest lifetime XIRR, lowest total return, or oldest holding alone. The exact score is **OD-16** (§17).

### 6.7 Replenishment

When a strategy consumes its own immediately available capital (including its minimum-opportunity free capital) on a new opportunity, it SHOULD request the **minimum** required capital back from outstanding loans as soon as recall is eligible.

- First filter to loans that are eligible for recall under the existing V3 recall / effective-minimum-period rules (§6.5, OD-07).
- **OD-09 (frozen):** among those eligible outstanding **loans**, select the **oldest** first (**FIFO**) by **commitment time**. If two or more eligible loans have exactly the same commitment time, any tied loan may be selected (implementation may resolve the exact tie arbitrarily or deterministically). There is no additional business ranking after FIFO.
- Do **not** introduce largest-loan-first, smallest-loan-first, lender percentage, lender absolute lendable amount, borrower strength, or strategy ranking as loan-selection rules.
- **OD-06** still controls the **amount** recalled: request the minimum required atomic amount (`× 1.01` then ceil to ₹5,000), not exceeding the selected loan’s outstanding principal.
- Do **not** recall an entire loan merely because it is the oldest if only a smaller amount is required.
- If the selected loan’s outstanding is less than the remaining replenishment need, continue with the next oldest eligible loan (same FIFO order) until the OD-06 minimum is satisfied or no eligible loans remain. That continuation is FIFO order, not a size-based ranking rule.
- If a borrower must sell positions as a consequence, rank borrower-owned positions per §17 (**OD-16**).

**Example (normative illustration):** Momentum has ₹12,000 reserved as its minimum opportunity capacity, spends that ₹12,000 on a new opportunity, and the effective lending period has expired. Required back = ₹12,000 → × 1.01 = ₹12,120 → atomic ₹15,000, capped by outstanding principal. If several recall-eligible loans exist, tap the oldest by commitment time first (OD-09) for that ₹15,000 (or less if that loan’s outstanding is smaller).

### 6.8 Safeguards (normative list)

1. Effective lending / recall-eligibility period (platform default 14 calendar days, or a shorter or longer portfolio override; **DEP-RECALL-FLOOR**; OD-07)
2. Configurable lending limits
3. Minimum retained capital / one-opportunity floor (**OD-24**; not lendable)
4. Atomic capital block ₹5,000 with 1% margin then ceil (OD-06)
5. Explicit user approval (default for lend and recall)
6. Backend revalidation
7. No split of one request across lenders
8. No lending of portfolio reserve (`required_cash_reserve`, OD-19)
9. No lending of committed or reserved capital
10. No commitment on display
11. Partial own-capital funding does not convert OPEN/INCREASE to WATCH (OD-05)
12. Default **lender** ranking is available-for-lending % descending, then available-for-lending amount descending, then arbitrary exact tie (OD-08). FIFO is not a lender-ranking rule.
13. Replenishment **loan** selection among recall-eligible outstanding loans is FIFO by commitment time, then arbitrary exact tie (OD-09). Amount recalled remains OD-06.

### 6.9 Outstanding loans

- One capital request is fulfilled by **exactly one** lender and one ₹5,000-aligned amount (after OD-06).
- Sequential loans for **different** recommendations MAY exist (a borrower is not limited to a single lifetime loan).
- Combining two lenders to fund **one** recommendation is forbidden in V3.

### 6.10 Ownership of lending-funded investments (DEP-ADOPT-MERGE)

**DEP-ADOPT-MERGE (frozen): OPTION A — MERGE INTO BORROWER.**

When inter-strategy lending funds a recommendation:

1. The borrowed capital becomes **deployable borrowed capital** of the **borrowing** strategy for the funded opportunity.
2. The **borrowing** strategy owns the resulting investment/holding.
3. The **lending** strategy does **not** acquire ownership of the resulting holding.
4. The lending strategy records a **loan receivable**.
5. The borrowing strategy records the corresponding **loan obligation**.
6. Do **not** introduce fractional/split ownership of a holding based on own-vs-borrowed capital.
7. The physical cash model remains one portfolio-level cash pool. Do **not** create physical strategy bank accounts or cash buckets.
8. Loan repayment remains a loan/capital transaction; it does **not** transfer stock ownership.
9. Borrowed capital is additional deployable capital for the borrowing strategy, but it does **not** change the configured strategy allocation percentage.
10. Do **not** reinterpret this as permanently increasing the strategy’s configured allocation or as changing the portfolio’s allocation percentages.
11. OD-24 minimum retained capital remains unchanged.
12. OD-05, OD-06, **DEP-PARTIAL-LEND**, and **DEP-PARTIAL-ATOMIC** remain unchanged.

Example (normative):

Strategy A lends ₹15,000 to Strategy B.

- Strategy A: loan receivable = ₹15,000; no ownership of the resulting stock.
- Strategy B: receives ₹15,000 of deployable borrowed capital; the resulting ₹15,000 investment is owned by Strategy B; loan obligation = ₹15,000.

Do **not** model the resulting holding as jointly or fractionally owned by A and B.

This rule is **not** unmanaged-holding adoption and does **not** freeze unmanaged-adoption cost-basis merge when a destination strategy already owns the stock. That leftover remains unspecified.

---

## 7. Capital Request Workflow

### 7.1 Frozen rule

A recommendation that lacks sufficient **own** capital to fund the **full** this-cycle desired amount remains a **valid** recommendation. It MUST NOT be converted to WATCH because it lacks full funding (OD-05).

- If some own free capital is available: **partial funding** — allocate that capital, keep OPEN/INCREASE. Any unfunded remainder MUST be eligible for a **same-cycle** lending request (**DEP-PARTIAL-LEND**). Lending is not deferred merely because partial own-capital funding already occurred.
- If no own free capital is available: `UNFUNDED` OPEN/INCREASE; lending may be offered. A requirement below ₹5,000 MUST NOT by itself close the lending path.
- Direction (OPEN/INCREASE) and capital status are separate axes.

**DEP-PARTIAL-LEND (frozen):**

1. Same-cycle lending after partial own funding — **YES**. A `PARTIALLY_FUNDED` recommendation with an unfunded remainder MUST be eligible to open a same-cycle lending request.
2. When the loan is funded — **immediately after approval**. Borrowed capital MUST then be available for the intended funding/execution path.
3. Minimum **loan amount** — **₹5,000**. This is the minimum amount **borrowed** when the lending path is used. It is **not** a minimum funding requirement, **not** a minimum recommendation size, and **not** a minimum unfunded remainder.
4. Minimum requirement to use lending — **NONE**. If the unfunded remainder is below ₹5,000, lending is still allowed; the borrower takes a ₹5,000 loan.
5. Excess borrowed capital — **permitted** and remains available after the intended requirement is funded.

Examples (normative):

- Requirement ₹3,000 additional capital; lending selected → loan amount = ₹5,000 (**DEP-PARTIAL-ATOMIC**); ₹3,000 used for the requirement; remaining ₹2,000 is legitimate excess available capital. The recommendation MUST NOT remain UNFUNDED merely because the requirement is below ₹5,000.
- Own capital ₹4,000; target ₹18,000; unfunded remainder ₹14,000 → same-cycle lending (**DEP-PARTIAL-LEND**); actual loan = ₹15,000 (**DEP-PARTIAL-ATOMIC**); excess borrowed capital ₹1,000 remains available. If approved, the borrowed amount is immediately available.

OD-05, target amount (OD-12), and explicit user approval remain unchanged. Do not create a new cash account, loan account, allocation bucket, or accounting concept.

**DEP-PARTIAL-ATOMIC (frozen):** `loan_amount = ceil(unfunded_remainder / 5000) × 5000` for remainder > 0. Required loan = remainder. Remainder = this-cycle required/target amount minus allocated own capital (not `atomic_allocation` minus own). Actual loan ≥ remainder and is a ₹5,000 block. Do not reduce the loan below the remainder. Do not reject lending because the remainder is below ₹5,000. Not a minimum funding requirement. Not the OD-06 1% reservation formula.

### 7.2 End-to-end flow

```text
Strategy detects opportunity
    ↓
Strategy generates a normal recommendation (action remains OPEN / INCREASE)
    ↓
Apply staggered entry (§12) → this-cycle calculated_requirement (amount)
    (whole-share qty derived from latest raw close_price; min actionable §12.4)
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
    unfunded remainder MUST be eligible for a same-cycle lending request
      (DEP-PARTIAL-LEND; not deferred because partial own funding already occurred)
    unfunded remainder = this-cycle required/target amount − allocated own capital
      (not atomic_allocation − own; example ₹18,000 − ₹4,000 = ₹14,000)
    loan_amount = ceil(unfunded_remainder / 5000) × 5000 (DEP-PARTIAL-ATOMIC)
    if remainder < ₹5,000: still offer lending; loan amount = ₹5,000
    then continue to Find eligible lenders (same-cycle; DEP-PARTIAL-LEND)
If own free = 0:
    capital_status = UNFUNDED
    recommendation remains the same BUY/INCREASE action
    ↓
    Determine required borrowed capital for the unfunded gap
    ↓
    If the gap is below ₹5,000:
        lending path remains open; loan amount = ₹5,000 (minimum loan amount; DEP-PARTIAL-LEND)
        MUST NOT remain without a lending path merely because the requirement is below ₹5,000
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
    lender records loan receivable; borrower records loan obligation (DEP-ADOPT-MERGE)
    borrowed capital is available **immediately** for the intended funding/execution path (DEP-PARTIAL-LEND)
    capital_status = CAPITAL_COMMITTED then FUNDED for execution purposes
    ↓
Recommendation is capital-ready
    ↓
Normal execution workflow (user trade approval → pending_execution → cash
reservation for the buy → manual/broker fill)
    resulting holding is owned by the **borrowing** strategy (DEP-ADOPT-MERGE);
    lender does not acquire stock ownership
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

- Trade approval MUST NOT move a BUY to `pending_execution` while `capital_status` is `UNFUNDED` or awaiting lending. `PARTIALLY_FUNDED` and `FUNDED` MAY proceed for the allocated slice. Same-cycle lending for the unfunded remainder remains available (**DEP-PARTIAL-LEND**) and, once approved, borrowed capital is immediately available for the remaining funding/execution path.
- Once FUNDED, PARTIALLY_FUNDED, or CAPITAL_COMMITTED, reservation-on-trade-approval applies to the **reserved allocation amount** (atomic_allocation or partial own amount), using portfolio available cash. Borrowed capital is already reflected in accounting so that available cash checks succeed.
- Double reservation (lending commit + a second reservation of the same rupees without converting state) is forbidden. Implementation MUST keep a single authoritative reserved/lent ledger (§22, §28).
- After fill, unused reservation (including unused 1% margin) reverts to available capital.

---

## 8. Lender Selection

### 8.1 Eligibility filters (all must pass)

A strategy L is eligible to lend amount `A` to borrower B when:

1. L ≠ B
2. L is enabled in the portfolio
3. `A` ≥ ₹5,000 and `A` is a multiple of ₹5,000. For a PARTIALLY_FUNDED remainder, `A = ceil(unfunded_remainder / 5000) × 5000` (**DEP-PARTIAL-ATOMIC**). A remainder below ₹5,000 still requests `A` = ₹5,000. ₹5,000 is not a minimum remainder.
4. L’s **available-for-lending** ≥ `A`
5. After the loan, L would still hold its minimum retained capital (**OD-24**)
6. `A` does not include portfolio reserve (`required_cash_reserve`, OD-19)
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

`minimum_free_capital` is **`minimum_retained_capital` (OD-24)**: `nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)` (§5.5). Do **not** substitute `required_cash_reserve`, NAV, total cash, deployed capital, conviction, fit, target size, or ranking.

Do **not** apply the 1% execution margin to lendable surplus. The 1% applies to **requirements** (how much to reserve/borrow/recall), not to how much a lender can offer.

capped by configured lending limits.

**Unused allocation** is defined in §5.2. It is not “all portfolio cash”. Portfolio reserve is already excluded because unused allocation is computed from investable capital after `required_cash_reserve` (**OD-19**); do not subtract the reserve a second time here.

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

Only the surplus above minimum retained capital (**OD-24**), atomic-aligned, within limits, is lendable. Fully invested strategies have ~0 lendable.

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

Each loan is a discrete outstanding amount with a lender, borrower, **commitment time**, and remaining principal. Replenishment requests the minimum OD-06-aligned amount from loans that are past the **effective** minimum lending period (§6.5, OD-07). If sells are required, weakest borrower-owned positions first (§17, **OD-16**).

**OD-09 (frozen):** if multiple outstanding **loans** are eligible to repay a replenishment:

1. Filter to loans eligible for recall under §6.5 / OD-07.
2. Select the **oldest eligible outstanding loan first (FIFO)** by commitment time.
3. If two or more eligible loans have exactly the same commitment time, any tied loan may be selected. Implementation MAY resolve that exact tie arbitrarily or deterministically. There is no additional business ranking criterion after FIFO.

Do **not** introduce largest loan first, smallest loan first, lender percentage, lender absolute lendable amount, borrower strength, or strategy ranking as loan-selection rules.

Do **not** recall an entire loan merely because it is the oldest if only a smaller OD-06 amount is required. If the selected loan cannot cover the remaining need, continue in the same FIFO order with the next oldest eligible loan.

OD-07 controls **when** a loan becomes recall-eligible. OD-09 controls **which** eligible loan is selected. OD-08 (lender ranking) MUST NOT be used to choose among loans.

### 9.8 Do not churn policy allocation

Today’s signal count MUST NOT re-spread `allocation_pct`. Lending is the mechanism for **temporary** unused capacity. Permanent mix changes are user edits. **DEP-ADOPT-MERGE:** deployable borrowed capital does **not** rewrite configured allocation percentages.

---

## 10. Holdings Ownership

### 10.1 Every strategy-generated holding has an owner

When a recommendation (OPEN/INCREASE) is executed, the resulting lots/position MUST be tagged with that recommendation’s strategy. INCREASE adds to **that strategy’s** owned position in the stock, never to another strategy’s lot and never to unmanaged quantity.

**DEP-ADOPT-MERGE (frozen):** if that recommendation was funded in whole or in part by inter-strategy lending, the **borrowing** strategy still owns the resulting holding. Own-capital and borrowed-capital slices of the same execution MUST NOT create fractional or split ownership with the lender. The lender’s claim is the loan receivable, not a share of the stock.

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
- After adoption, that strategy owns it; **target amount** SHOULD be initialised from the adopted position’s current monetary value (remaining BUY/INCREASE = 0 unless the user/strategy later raises the target amount). **OD-15 (frozen):** adoption does **not** reset trailing/stop entry date; preserve the existing/original first-buy entry history. Detailed unmanaged-adoption cost-basis and multi-lot merge mechanics remain **unspecified** (this is **not** **DEP-ADOPT-MERGE**).
- Adoption MUST be explicit. No silent auto-adopt during generation.
- If the destination strategy **already** owns that stock, the unmanaged quantity is merged into that strategy’s holding. **OD-15** still applies to the entry-date principle (do not reset to adoption date). Detailed cost-basis merge mechanics remain **unspecified**.
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
| Trailing stop / stop-loss | Per owner’s holding. Trailing: own entry date and own high-close **window**. All owners use the same raw `close_price` column (**OD-14**); they do not choose different series. Stop-loss `entry_price`: weighted-average actual fill cost of that owner’s current ownership episode (OD-13), not first-fill, not blended across owners. |
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

**Normative quantity-ownership example** (illustrates attachment only; it does not freeze split mathematics or cost restatement):

Before: Strategy A = 50, Strategy B = 30, unmanaged = 0.

For a 2:1 split: Strategy A = 100, Strategy B = 60, unmanaged = 0.

**OD-10 does not decide** (leave unspecified):

- split / bonus mathematical formulas
- rights-issue calculation rules
- cost-basis / average-price restatement
- target / filled restatement
- trailing-high restatement
- stop-loss price restatement
- merger / demerger treatment (not specified in this document)

**OD-14 (frozen)** uses raw `close_price` for stop-loss comparison, trailing high, trailing current-close comparison, and the OD-12 recommendation/quantity reference price. `adjusted_close_price` is **not** used for those calculations. OD-14 does **not** freeze the restatement items above and MUST NOT be read as using adjusted close to compensate for corporate actions. This specification does not define what `adjusted_close_price` adjusts for.

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
7. Apply staggered entry (§12) to OPEN/INCREASE **amounts**, then derive whole-share quantity from the latest available raw `close_price` (OD-12 / OD-14). Suppress OPEN/INCREASE below the effective minimum actionable amount.
8. Apply BUY cooldown (§11.2, **OD-11**) to OPEN/INCREASE only (key = stock + **this** strategy). Another strategy’s BUY of the same stock does not consume, reset, or affect this strategy’s cooldown.
9. Enforce hard max holdings on new OPEN (this strategy’s name count only).
10. Compute ranking (§4) from the **authoritative backtest corpus** (§4.2.1) when return-quality ranking is computable for the opportunity; do not emit fit-as-rank; do not use live trades. When return-quality ranking is **not** computable (corpus unavailable or no statistically eligible fit band after **DEP-FIT-BAND-10**), do **not** emit a return-quality rank; capital fill among that strategy’s valid BUY/INCREASE recs uses **OD-23** (descending target investment amount / conviction amount, then fit → alphabetical symbol). Do not present that fill order as V3 ranking.
11. Compute own available capital; apply OD-05/OD-06. Set `FUNDED` / `PARTIALLY_FUNDED` / `UNFUNDED`. **Do not demote OPEN/INCREASE to WATCH** for capital reasons.
12. Persist recommendations. Cancel only **this strategy’s** stale open recs, subject to §11.2 (do not stale-replace a BUY for a pair that is in cooldown; do **not** cancel `pending_execution`; do not clear cooldown). Do **not** cancel other strategies’ recs.

Market gates MAY continue to demote OPEN/INCREASE to WATCH/HOLD as **market** policy (F098). That is not a capital demotion. Capital shortage uses UNFUNDED or PARTIALLY_FUNDED, not WATCH.

### 11.2 BUY cooldown (OD-11)

**OD-11 (frozen).** Primary purpose: prevent repeated BUY **recommendation churn** for the same stock and strategy. Secondary: space repeated capital deployment into that stock/strategy.

This is **not** the lending/borrowing recall period. **OD-07** remains unchanged (configurable; shipped default **14 calendar days**; portfolio override may be shorter or longer — **DEP-RECALL-FLOOR**). Do not use 14 days as the BUY cooldown.

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

Conviction → **target amount** (within diversification and portfolio caps). Quantity is **derived** from that amount using the latest available raw `close_price` and whole-share flooring (§12.3). The target amount MUST be persisted so later cycles can fill the remainder (§12). Do not persist target quantity as the source of truth.

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

Do not store target quantity as the authoritative target. A derived quantity MAY be stored for display if it is recomputed from `target_amount` and the latest available raw `close_price` when generating recommendations.

**Reference price (OD-12, clarified by OD-14):** for recommendation generation and all target/quantity calculations, use the stock’s **latest available raw daily `close_price`**. “Latest available daily closing price” in this specification means that raw `close_price`. Do **not** use `adjusted_close_price`, intraday price, expected execution price, previous execution price, broker quote, or estimated future price. Execution price is a separate execution concern and is not specified here. Small differences between last raw close and actual execution price MUST NOT alter recommendation-generation semantics. Do **not** add an execution-price band; OD-14 does not resolve execution-price behaviour.

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

Then derive whole-share quantity from remaining using the **latest available raw `close_price`**. If remaining ₹3,000 is below the effective minimum actionable amount, no INCREASE is generated; target stays ₹8,000.

Partial first entry (capital, OD-05): target ₹36,000, first-entry 50% = ₹18,000, own free = ₹10,000 → fund ~₹10,000 now; **target remains ₹36,000**. Subsequent INCREASE after OD-11 elapses uses `current_target_amount − filled_amount`.

If current target amount is below the amount already filled, there is **no** BUY/INCREASE for a gap. Existing REDUCE semantics remain governed by applicable recommendation rules. Do not invent an automatic reduce unless strategy reduce rules already fire.

Executed INCREASE fills (after OD-11) update the stop-loss weighted-average execution cost of the current ownership episode (**OD-13**). They do not reset `target_amount` (OD-12) and do not start a new ownership episode.

### 12.3 Whole-share quantity (OD-12)

Fractional shares are **not** supported.

When converting an amount into quantity, derive the maximum whole-share quantity whose notional value does not materially exceed the intended amount. Normal/default behaviour is to **FLOOR**:

```text
quantity = floor(intended_amount / latest_raw_close)
notional = quantity × latest_raw_close
residual = intended_amount − notional
```

where `latest_raw_close` is the latest available daily `close_price` (**OD-14**). Do **not** use `adjusted_close_price`.

Example: target or slice amount = ₹2,500, last raw close = ₹600 → 2500/600 = 4.166… → **quantity = 4**, notional = ₹2,400, residual = ₹100. Do **not** force a 5th share (₹3,000) merely to consume the amount.

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

- Evaluated on the latest raw daily **`close_price`**, not the intraday low and not `adjusted_close_price` (**OD-14**).
- **Portfolio-level** common control (not strategy `max_loss` as the account stop).
- Configurable percentage (today’s profile `default_stoploss_percent` is a migration source for **stop-loss % only**, not the V3 owner of strategy JSON, and **not** the trailing-stop seed — trailing seed is **OD-22**).

**OD-13 (frozen):** `entry_price` is the **weighted-average execution cost of actual fills** for the **current ownership episode**, per stock + owner. It is **not** the first-fill price.

```text
average_cost = (sum of actual executed fill value) / (sum of filled quantity)
stop_price   = average_cost × (1 − stop_loss_pct / 100)
```

**Hit when:** `latest raw daily close_price ≤ stop_price`

Only **actual executed fills** contribute to `average_cost`. Do **not** use latest raw close, `adjusted_close_price`, recommendation/reference price, atomic reservation (OD-06), unfunded amount, target amount (OD-12), or estimated execution price as the cost basis.

The first fill of the episode establishes the initial average cost. Each subsequent actual fill of the same episode (including INCREASE after OD-11, partial/multiple fills of one recommendation, and OD-05 partial-funded executions) **recalculates** the weighted average. Do **not** keep the stop anchored to the original first-fill price.

**Example:** 50 shares @ ₹100, later INCREASE 50 shares @ ₹120 → average cost = ₹110. With 10% stop-loss, stop = ₹99.

**Ownership episode:** average cost is for the current episode of that `(stock, owner)`. A new episode begins after the previous position has been **fully exited** and a new position is later established. Do not carry the prior episode’s average cost into the new position. Do not blend cost bases across strategy owners of the same stock (OD-01).

Adoption unmanaged-quantity merge mechanics and cost-basis merge remain **unspecified**. **DEP-ADOPT-MERGE** is the lending-funded ownership rule (merge into borrower) and does **not** resolve unmanaged-adoption cost basis. **OD-15 (frozen)** sets the entry-date principle for trailing/stop windows: adoption does **not** reset to adoption date; preserve original first-buy/existing ownership-history entry date. Corporate-action cost restatement remains unspecified under **OD-10**. Do not resolve those here. OD-14’s choice of raw `close_price` does **not** restate average cost, stop price, or trailing high after a corporate action.

### 14.2 Which holdings

Applies to **every open holding** in the portfolio: each strategy-owned lot and the unmanaged lot, **evaluated independently**. Same symbol, different owners → different **average costs** / highs / hits (OD-01, OD-13) because each owner has its own cost basis and its own trailing **window**. All owners use the **same** raw `close_price` column (**OD-14**); they do not choose different price series.

Rationale (frozen ownership split): stop-loss is a **portfolio** control, not a strategy control. Unmanaged means “not strategy-managed”, not “exempt from account risk”.

Strategy `max_loss` in V1 exit JSON MUST NOT remain the portfolio stop after migration (§25). Strategy-specific risk rules other than the common stop (for example ATR stop) may remain on the strategy.

### 14.3 Close series (OD-14)

**OD-14 (frozen):** portfolio stop-loss and trailing-stop calculations use raw daily **`close_price`** as the **single consistent** daily comparison series.

- Stop-loss hit test: latest raw `close_price` vs OD-13 `stop_price`.
- Trailing high: highest raw `close_price` since the applicable entry date.
- Trailing current-close comparison: latest raw `close_price`.
- OD-12 reference price for recommendation/quantity: latest available raw `close_price` (consistency clarification; OD-12 amount-based target is unchanged).

`adjusted_close_price` is **not** used for those calculations. Do **not** mix raw and adjusted series. Do **not** use `adjusted_close_price` as a shortcut to compensate for corporate actions. This specification does not define what `adjusted_close_price` adjusts for.

Execution/fill price remains separate from this comparison series. **OD-15 (frozen)** preserves existing/original first-buy entry history on adoption for trailing/stop windows.

---

## 15. Trailing Stop

### 15.1 Frozen behaviour

- Calculated from the **entry date** of the current ownership episode.
- **Raw daily `close_price` only** (no intraday high/low; no `adjusted_close_price`) (**OD-14**).
- Track the **highest raw `close_price`** on or after entry date.
- `trailing_stop = highest_raw_close_since_entry × (1 − trailing_pct / 100)`
- Hit when the **latest raw `close_price`** is **below** the trailing stop.

Use the **same** raw `close_price` series for the historical highest close and the latest comparison close. Do **not** mix raw and adjusted series.

This matches the V1 **presentation** formula in `HoldingPresentationService`, not the V1 `ExitStrategyEvaluator` unrealized-% proxy. V3 **forbids** the proxy as the trailing-stop definition.

Trailing stop does **not** use OD-13 `entry_price` / average cost. It uses highest raw `close_price` since the applicable **entry date**. OD-13 and OD-14 do not change that structure. OD-14 only names the column.

### 15.2 Ownership

Same scope as stop-loss: every open holding, **per owner**, including unmanaged. Do not compute a single trailing stop from a blended high across strategies.

Each owner has its own trailing **window** (own entry date → own high). All owners nevertheless use the **same** raw `close_price` column (**OD-14**). “Per owner close series” does **not** mean different owners may choose `close_price` vs `adjusted_close_price`.

Entry date for unmanaged = first buy date of the current position (as V1 presentation already computes). Entry date for strategy-owned = first fill of that ownership episode. **OD-15 (frozen):** on adoption from unmanaged, do **not** reset trailing/stop entry date to the adoption date; preserve the existing/original first-buy entry history so adoption does not create a new trailing/stop window.

**OD-15 (frozen):** On adoption, trailing/stop entry date keeps the existing/original first-buy entry history; adoption does **not** reset the window. This preserves risk/age continuity and does not treat administrative adoption as a new purchase.

**OD-02 interaction note:** OD-02 remains frozen (`entry_date + T` calendar days), but this specification does not add a new adoption-specific horizon decision beyond preserving trailing/stop entry-date continuity under OD-15. Any additional adoption-horizon merge detail remains unresolved unless separately frozen.

### 15.3 Percentage

Portfolio-configurable trailing percentage, independent of stop-loss percentage (they may be set equal by the user).

**OD-22 (frozen):** On V3 migration, seed the **portfolio-level** trailing percentage to **15%**. That 15% is a **migration seed / initial portfolio value**, not a permanently enforced platform default. After migration the user MAY change the portfolio trailing percentage to any supported value. Do **not** copy `default_stoploss_percent` into trailing. Do **not** derive the seed from existing strategy-level trailing/stop configuration (including as-built B1/B2 strategy JSON). Do **not** average, take max/min, or otherwise reconcile strategy-level percentages. Those strategy-level values are **ignored completely** for this migration. Trailing remains a portfolio control (§21), not a strategy setting.

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

Loan **selection** for replenishment is **OD-09** (FIFO by commitment time among recall-eligible loans). This section ranks **positions to sell**, not which loan to tap.

**OD-16 (frozen): weakest-position score**

When recall/replenishment requires selling borrower-owned positions to free cash, rank eligible positions by a **simple windowed percentage return**. The position with the **lowest** score is the weakest and MUST be sold first (subject to repeated application until the cash requirement is met — sell mechanics beyond ranking remain unspecified).

```text
window_return_pct =
    (current_reference_price − window_start_reference_price)
    / window_start_reference_price × 100
```

- **Evaluation window:** strategy-configurable, in **calendar days** (consistent with OD-02). Each borrower strategy supplies its own weakest-position evaluation window configuration.
- **Window start date:** the calendar date that is **N calendar days before** the evaluation date, where `N` is that strategy’s configured window length.
- **`window_start_reference_price` (DEP-WEAKEST-PRICE):** raw daily `close_price` from the latest available trading day **ON OR BEFORE** that window-start date. If the configured window-start date is not a trading day, walk **backward** to the latest available daily OHLCV record on or before that date. Do **not** look forward to the next trading day.
- **`current_reference_price` (DEP-WEAKEST-PRICE):** the latest available daily `close_price` at evaluation time.
- Do **not** use `adjusted_close_price` for OD-16. Do **not** use execution/fill price for either reference value.
- **Not** a composite score: do **not** combine momentum, fit score, thesis score, lifetime XIRR, holding age, volatility, or any OD-03 opportunity-ranking metric into this score.
- **Not** annualised XIRR. This is a recall/replenishment weakest-position metric, not general stock ranking and not OD-18 annualisation policy.
- This is **not** the OD-03 ranking corpus.

MUST NOT rank weakest as:

- lowest lifetime XIRR alone
- lowest total return alone
- oldest holding alone

**Agreed direction (preserved):**

- evaluate **recent / current** performance via the configured window
- strategy-specific evaluation windows are allowed
- windowed return captures recent weakness without penalising long-dormant names via lifetime metrics alone

**Tie-break (deterministic):** if two or more eligible borrower-owned positions have exactly the same `window_return_pct`, rank by **`stock_id` ascending**. No additional investment-quality tie metric.

**DEP-WEAKEST-PRICE (frozen): OPTION A — RAW CLOSE_PRICE.** OD-16 `current_reference_price` and `window_start_reference_price` are raw daily `close_price` as defined above. This is a separate freeze from **OD-14**. OD-14 remains the frozen rule for raw `close_price` in portfolio stop-loss, trailing stop, and OD-12 reference-price calculations only. DEP-WEAKEST-PRICE does **not** extend OD-14 into other calculations.

**DEP-WEAKEST-HISTORY (frozen): OPTION A — SHORTEN WINDOW.** When a borrower-owned position does not have sufficient historical daily OHLCV data to obtain the normal window-start reference price for the configured `N`-calendar-day window:

1. The position MUST remain eligible for weakest-position selection.
2. Use the maximum available history for that position, up to the configured `N`-calendar-day window.
3. Set `window_start_reference_price` to the earliest available valid daily raw `close_price` that exists within that available history.
4. Do **not** look forward beyond the available history to manufacture the configured window.
5. Do **not** use `adjusted_close_price`. Do **not** use execution/fill price.
6. Do **not** substitute lifetime return, fit, conviction, volatility, holding age, XIRR, or any other metric as a fallback.
7. The resulting return remains the same OD-16 `window_return_pct` formula.
8. Configured `N` remains the **maximum** evaluation window. A short-history position is evaluated over its available history; it is **not** excluded and MUST NOT block weakest-position selection.
9. The UI/backend MUST NOT represent the shortened period as though the full `N`-day history existed.
10. This fallback applies only when history is insufficient. It does **not** alter the normal OD-16 calculation when sufficient history exists, and it does **not** change the configured strategy window globally.

Example: configured window = 90 calendar days. Position A with 3 years of history uses the normal 90-calendar-day window. Position B with only 40 calendar days of usable history remains eligible and is evaluated over that available 40-calendar-day history, using the earliest available valid raw `close_price` in that history as `window_start_reference_price`.

Do **not** invent a minimum-history threshold beyond the availability of at least one valid start reference and the existing OD-16 calculation requirements (a valid `current_reference_price` and a valid `window_start_reference_price` for the formula). Lowest `window_return_pct` remains weakest. `stock_id` ascending tie-break, borrower-owned eligibility, OD-07, and OD-09 remain unchanged.

Eligible positions for forced repayment sells: **borrower-owned** holdings only (**OD-01**). Do not sell another strategy’s quantity or unmanaged quantity.

---

## 18. Historical Evaluation / Backtesting

### 18.1 Roles

| Term | Role |
|------|------|
| Fit score | Match to hypothesis now |
| Historical outcome | A completed or simulated completed investment at a fit level |
| Return | Simple holding-period return |
| Annualized return / XIRR | Time-aware return (§20). **OD-18:** used as **ranking evidence** only when backtest holding period ≥ 30 calendar days. Not the same as the §19 opportunity-cost success test. |
| Benchmark return | NIFTY 50 over the comparable period |
| Opportunity-cost threshold | Configured annualized hurdle, scaled to the period |
| Success | §19 boolean |
| Expected return | Aggregated return quality at a fit band (symmetric 7%/7% trimmed mean; normal `n_min = 15` with **DEP-FIT-BAND-10** permitted reductions to 12 or 10; backtest corpus only per §4.2.1; tail count `k = nearest_integer(0.07 × n)` with exact .5 rounding upward, **DEP-TRIM-K**; ranking statistical confidence persisted as diagnostic only) |
| Final ranking | Order by that expected/observed return quality — **not** live-trade outcomes, **not** fit score |

V1 strategy backtest (currently 15d–1y selectable ranges in the as-built UI, paper, market gates off) is the **simulator that must supply** the ranking corpus (OD-03). V3 requires the **latest completed** authoritative run to use **maximum available** historical market data (§4.2.1, **OD-17**); shipped range pickers and depth limits are **implementation limitations**, not V3 product caps. Live `ReviewEngine` / ledger sells MUST NOT be mixed into ranking observations.

### 18.2 History depth

**OD-17 (frozen):** V3 must support **all available OHLCV history** for each listed security. There is **no** maximum history-depth ceiling imposed by the V3 product specification.

- V3 does **not** define a fixed numeric OHLCV history-depth target (not 550 days, not 1,825 days, not any other invented day-count).
- V3 must support all history that is **actually available** for the security from the data provider, subject to the provider’s available history and the security’s listing/history start date.
- **5Y** must be supported when the security has 5Y of available history. 5Y is a **chart range**, not a storage ceiling.
- History **shorter** than 5Y is valid and is **not** an error. A security with less than 5 years of available history legitimately returns its complete available history.
- History **longer** than 5Y must remain available and MUST NOT be artificially truncated by a V3 product-level maximum. **All** must be able to represent that longer history.
- Provider limitations and listing/history start dates are **external availability** constraints, not V3 product history ceilings.
- Stored and/or on-demand provider history MAY be used; V3 does not impose a product-level day-count cap on either path.

The V1/as-built code default `HISTORY_DEPTH_TARGET_DAYS = 550` is an **implementation limitation only**. It is **not** a V3 product requirement, target, or equivalent maximum-depth constant. V3 has **no** equivalent maximum product-depth constant.

Pre-listing stocks and listings with short available history show whatever exists; that is not an error (§26).

### 18.3 Benchmark

Comparable-period **NIFTY 50** return (primary benchmark already in the product). Opportunity-cost comparison is a hurdle on annualized return, not a substitute for the NIFTY test.

---

## 19. Success Definition

An historical outcome is **successful** when **all** of the following hold:

1. Return is **positive**.
2. Return **beats NIFTY 50** over the **comparable period**.
3. **Annualized** return also beats the configured **opportunity-cost** threshold.

Do not impose a fixed absolute return target across all strategies (no global “must gain X%”).

**OD-18** governs when annualized return/CAGR/XIRR may be used as **ranking evidence**. It does **not** alter this §19 success definition or the §19.1 opportunity-cost formula.

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
| Weakest-position / windowed evaluation of **current** holdings (recall, §17) | **OD-16:** configured-window **simple percentage return** (`window_return_pct`); not lifetime XIRR / total return / age alone; not annualised XIRR; not the OD-03 ranking corpus |
| Opportunity ranking (fit-band expected return) | **Authoritative backtest corpus only** (OD-03, §4.2.1), **DEP-FIT-BAND-10** banding, trimmed mean per OD-04. Tail count `k` per **DEP-TRIM-K**. Ranking statistical confidence diagnostic only. **OD-18:** annualized return/CAGR/XIRR is ranking evidence only when the backtest holding period is **≥ 30 calendar days**. |
| Benchmark comparison | Simple return of NIFTY 50 over the same start/end dates (or nearest trading days) |

XIRR is appropriate when cash flows are dated (buys, sells, corporate actions). Simple return is appropriate for a single entry/exit over a defined horizon.

**OD-18 (frozen):** For ranking, annualized return/CAGR/XIRR must not be used as ranking evidence for backtest holding periods shorter than **30 calendar days**. This reuses the existing V1 backtest rule that refuses CAGR under 30 days, stated here as **30 calendar days** (not trading sessions). Holding periods **≥ 30 calendar days** MAY contribute annualized return/CAGR/XIRR as ranking evidence. Simple return of shorter (and longer) backtest outcomes remains a valid outcome. OD-18 does **not** change the §19 success definition or the opportunity-cost calculation (`T_years = calendar_days / 365`, OD-02). OD-16 weakest-position scoring remains non-annualised `window_return_pct`. When return-quality ranking is not computable (authoritative corpus missing or no statistically eligible fit band after **DEP-FIT-BAND-10**), capital fill order is **OD-23** (descending target investment amount / conviction amount among that strategy’s own valid BUY/INCREASE recs; not V3 ranking; not a fit-as-rank fallback), not OD-18.

---

## 21. Portfolio Risk and Common Controls

These MUST move **out** of strategy `config_json` and onto **portfolio** configuration:

| Control | Notes |
|---------|--------|
| Stop-loss % | §14 |
| Trailing stop % | §15. Portfolio-level. **OD-22:** migrate by seeding **15%**; ignore strategy-level values; user-editable after migration. |
| Portfolio cash reserve | Floor that is not invested and not lent; rupee amount = `required_cash_reserve` per **OD-19** (§22). **OD-21:** not a hard withdrawal block; a shortfall is a warning condition. |
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
| **Invested Amount** (`total_invested_amount`) | Actual money paid to acquire **currently held** investments (capital invested). Example: ₹80 was paid to acquire the currently held investment → Invested Amount = ₹80. This is **not** current market value and **not** available cash. |
| **Notional Portfolio Value** (`current_notional_portfolio_value`) | Current market value of the **currently held** investments. Example: ₹80 was invested and those holdings are now worth ₹100 → Invested Amount = ₹80, Notional Portfolio Value = ₹100. This is **not** invested cost/basis and **not** invested amount plus cash. |
| **Available cash** | Currently available unallocated physical portfolio cash. `available_physical_cash` remains §22.2. This quantity is **not** the OD-19 reserve base and is **not** the OD-20 named **Unallocated Cash** UI bucket. |
| **Unallocated Cash** | **OD-20 (frozen).** Named Cash-UI bucket for residual portfolio cash after `required_cash_reserve` that is not claimed by strategy unused-allocation accounting. **Presentation-only** over the existing single physical cash pool. Not a separate cash/bank/strategy account, not a new allocation %, not a new reserve, not a new lending pool, not a new withdrawal entitlement, and not a new accounting ledger. Distinct from Available cash / `available_physical_cash`, from pending-execution **Reserved cash** (OD-06), from post-fill reservation leftover, and from strategy unused allocation. Does **not** introduce a new residual-cash formula; it displays the residual already described by this §22 accounting. |
| **Portfolio cash reserve** (`required_cash_reserve`) | Rupee floor computed per **OD-19** below. Non-investable and non-lendable. Cannot be used for new buys or loans. Distinct from pending-execution **Reserved cash** (OD-06) and from **Unallocated Cash** (OD-20). **OD-21:** physical cash MAY fall below this floor after an external/broker withdrawal; that is a warning condition, not permission to invest or lend the reserve. |
| **Reserved cash** | Cash reserved for approved **pending-execution buys** (V1 reservation semantics), including OD-06 `atomic_allocation` (which may exceed the eventual fill). Unused reservation after reconciliation returns to available. Must not double-count with CAPITAL_COMMITTED / lent. Must **not** be relabelled as **Unallocated Cash** (OD-20). |
| **Investable capital** (strategy split base) | `(cash − required_cash_reserve − pending-execution reserved) + market value of strategy-owned holdings` only. Unmanaged holdings’ market value is **not** part of the 100% strategy split (it is residual account wealth the strategies do not claim). Including holdings MV in the split base is required so an invested strategy is not treated as having unused cash equal to its entire %. Cash-only split is **not** the V3 model. `required_cash_reserve` is the OD-19 rupee floor, not a second percentage. |
| **Strategy allocation** | `investable_capital × allocation_pct` |
| **Strategy minimum retained capital** (`minimum_retained_capital`, OD-24) | `nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)`. Nearest integer rupee; exact .5 rounds upward; not banker's rounding. Strategy-level accounting floor for one future opportunity. Not a physical sub-account, not OD-19, not OD-06 reserved cash, not Unallocated Cash (OD-20). Subtracted in available-for-lending (§8.2). Does not change `available_physical_cash`. |
| **Strategy available capital** | Capital the strategy may use **now** for new buys: unused allocation that is actually fundable from physical available cash, plus outstanding **borrowed** amounts (**deployable borrowed capital**), minus amounts it must not spend (already reserved). Cannot invade portfolio reserve (`required_cash_reserve`, OD-19). OD-24 does **not** change this BUY-funding definition. Borrowed capital does **not** change configured `allocation_pct` (**DEP-ADOPT-MERGE**). |
| **Committed-to-lending** | Approved but not yet reflected as lent, if those steps are split; otherwise 0 and lent updates on approval |
| **Capital currently lent** | Outstanding loan principal from this strategy as lender (**loan receivable**; not stock owned by the lender) |
| **Available-for-lending** | §8.2 |
| **Borrowed capital** | Outstanding loan principal to this strategy as borrower (**loan obligation**; **deployable borrowed capital**, not a change to configured `allocation_pct`). Investments funded with it are **borrower-owned** (**DEP-ADOPT-MERGE**). |
| **Capital required by pending recommendations** | Sum of unfunded BUY gaps (informational). Does **not** reserve cash |

**OD-19 (frozen): portfolio cash reserve unit / formula.**

There is **one** configurable portfolio-level percentage: `portfolio_cash_reserve_pct`. Do **not** define a separate percentage for Invested Amount and another for Notional Portfolio Value. The reserve is a **portfolio-level** control, not a strategy-level setting.

```text
reserve_base =
    MAX(
        total_invested_amount,
        current_notional_portfolio_value
    )

required_cash_reserve =
    reserve_base × portfolio_cash_reserve_pct
```

Equivalently:

```text
required_cash_reserve =
    MAX(
        total_invested_amount,
        current_notional_portfolio_value
    ) × portfolio_cash_reserve_pct
```

**Behaviour implied by the formula:**

- The reserve cannot fall below the configured percentage of actual invested capital merely because holdings have declined in market value.
- If current notional portfolio value rises above invested amount, the reserve requirement rises accordingly.
- `portfolio_cash_reserve_pct` is configurable at portfolio level.
- The reserve remains non-investable and non-lendable. Partial funding cannot invade it (OD-05).

**The reserve base is not any of the following:**

- a percentage of cash
- a percentage of portfolio NAV
- an absolute rupee configuration (the *result* is a rupee amount; the *configured* input is the single percentage)
- invested amount + available cash
- “total portfolio value” as invested amount + cash
- two different percentages combined

If this specification uses **portfolio NAV** elsewhere for accounting/reconciliation (§22.3), that existing NAV concept is **not** redefined here and is **not** the OD-19 reserve base.

**Examples (illustrative only).** The **20%** figure is an example of a configured `portfolio_cash_reserve_pct`. It is **not** a frozen V3 numeric default.

Example A — no appreciation:

- Invested Amount = ₹80
- Notional Portfolio Value = ₹80
- `portfolio_cash_reserve_pct` = 20%
- `reserve_base` = MAX(₹80, ₹80) = ₹80
- `required_cash_reserve` = ₹80 × 20% = ₹16

Example B — appreciation:

- Invested Amount = ₹80
- Notional Portfolio Value = ₹100
- `portfolio_cash_reserve_pct` = 20%
- `reserve_base` = MAX(₹80, ₹100) = ₹100
- `required_cash_reserve` = ₹100 × 20% = ₹20

Example C — decline:

- Invested Amount = ₹80
- Notional Portfolio Value = ₹60
- `portfolio_cash_reserve_pct` = 20%
- `reserve_base` = MAX(₹80, ₹60) = ₹80
- `required_cash_reserve` = ₹80 × 20% = ₹16

**Not decided by OD-19:** pending-execution reservation (OD-06); a new hard-coded numeric default for `portfolio_cash_reserve_pct`; recalculation cadence beyond existing capital-calculation rules; storage/schema representation. Strategy minimum retained capital / one opportunity is **OD-24** (frozen separately; `nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)`; not this reserve formula; not a second portfolio reserve). Named **Unallocated Cash** UI is **OD-20** (frozen separately; presentation-only; does not change this reserve formula). Withdrawal-into-reserve is **OD-21** (frozen separately: withdrawals MUST NOT be blocked merely because they would leave cash below `required_cash_reserve`; reserve remains non-investable and non-lendable; OD-24 MUST NOT hard-block those withdrawals).

### 22.2 Available physical cash

`available_physical_cash = max(0, total_cash − pending_execution_reservations)`

This formula is unchanged. **OD-24** does **not** alter `available_physical_cash`. Do **not** invent a reserve-adjusted withdrawal-cap formula. Pending-execution reserved cash remains excluded here. Withdrawals remain subject to `available_physical_cash` (they cannot spend pending-execution reserved cash). They MUST NOT be further blocked merely because they would leave cash below `required_cash_reserve` (**OD-21**). OD-24 MUST NOT be used to hard-block such external withdrawals.

**OD-21 (frozen): YES.** Withdrawals MUST NOT be blocked merely because they would cause physical portfolio cash to fall below `required_cash_reserve`.

“Withdraw” means taking/transferring cash out of the portfolio/broker cash account, typically to the user’s external bank account. It is **not** a transfer between LidoPortfolio virtual strategy allocations.

LidoPortfolio does not control the broker’s physical cash account. A user can withdraw or transfer money from the actual broker account outside LidoPortfolio. The application MUST represent the resulting cash position rather than pretending that such a withdrawal could not happen. Accounting MUST be allowed to show a **reserve shortfall** (physical cash below `required_cash_reserve`) after an external/manual broker withdrawal.

A reserve shortfall is a **warning/alert condition**, not a hard withdrawal restriction. The user MAY subsequently replenish broker/portfolio cash to restore the reserve.

The application MUST NOT terminate automatic executions or execution recommendations **solely** because portfolio cash is currently below `required_cash_reserve`. Existing independent eligibility rules (including that the reserve cannot be used for new buys or loans) remain unchanged.

Frozen independently of OD-21:

- Reserve (`required_cash_reserve`, OD-19) remains **non-investable and non-lendable**. OD-21 is **not** permission to invest or lend reserve cash. Partial funding still cannot invade the reserve for buys (OD-05).
- The **Unallocated Cash** UI bucket (OD-20) remains presentation-only and is **not** a withdrawal entitlement.
- Do not create a withdrawal account, withdrawal ledger, or new cash bucket.

V1 `CashManagementService` did not enforce strategy `min_cash_reserve_pct` on withdraw; OD-21 freezes that product outcome for V3 (allow the shortfall; warn; do not hard-block).

### 22.3 Consistency rules

- Sum of strategy allocated capital = investable capital as defined above (excludes unmanaged MV).
- Sum of strategy deployed (owned holdings MV + that strategy’s reserved buys + lent) reconciles to owned holdings MV + reserved + internal loans (loans cancel across lender/borrower).
- Internal loans do not change **total** portfolio cash; they change claims.
- Unmanaged MV + strategy allocated capital + (cash reserve + unallocated physical constraints) must be reconcilable to portfolio NAV; do not double-count unmanaged MV inside a strategy %. **Portfolio NAV** in this reconciliation is **not** the OD-19 reserve base. OD-19 uses `MAX(total_invested_amount, current_notional_portfolio_value)`, not NAV and not invested amount + available cash. The Cash UI **Unallocated Cash** bucket (**OD-20**) is a presentation of residual cash after `required_cash_reserve` that is not claimed by strategy unused-allocation accounting, as already described here; it does **not** add a new residual-cash formula or a new ledger line.

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
| `PARTIALLY_FUNDED` | Own free capital > 0 but less than `atomic_allocation`; executable amount is the available free capital (OD-05). Action remains OPEN/INCREASE. Any unfunded remainder MUST be eligible for a same-cycle lending request (**DEP-PARTIAL-LEND**). Remainder loan size is **DEP-PARTIAL-ATOMIC** (`ceil(remainder / 5000) × 5000`). |
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
- lendable amounts (after the **OD-24** retained floor and existing lending constraints)
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
| Strategy `config_json` | Copy scoring, eligibility, thresholds, market gates, strategy-specific exit rules. **Move** `min_cash_reserve_pct` to portfolio `portfolio_cash_reserve_pct` (**OD-19**), and move max deploy, portfolio max position (as portfolio defaults), cash reservation flags to **portfolio** settings. Remove common SL/trailing from strategy exits as *account* controls; keep other exit rules. Do not delete old JSON keys until code no longer reads them; ignore them once V3 owners exist. The moved cash-reserve percentage is applied with the OD-19 formula; do not keep interpreting it as a V1 percentage of cash. **OD-22:** do **not** move strategy-level trailing/stop percentages into the portfolio trailing seed; ignore those values (including as-built B1/B2). |
| Holdings | Unmanaged unless safe inference (§10.5). |
| Manual holdings | Unmanaged. |
| Recommendations | Keep. Map unfunded-as-WATCH historically as **legacy**; do not rewrite historical actions. New generates use §23. |
| Transactions | Keep `source` and `recommendation_id`. Backfill owner on new derived holdings only per §10.5. |
| Cash | Single account unchanged. Opening loans = none. |
| `default_stoploss_percent` | Becomes portfolio **stop-loss %** only. It is **not** copied into trailing %. |
| Portfolio trailing % | **OD-22 (frozen).** Seed the portfolio trailing percentage to **15%**. Existing strategy-level trailing/stop values (as-built B1/B2 code-level configuration) are **ignored completely**. Do not average, max, min, or otherwise reconcile strategy-level percentages. Do not copy stop-loss %. Do not leave trailing disabled as the migration outcome. 15% is a **migration seed**, not a frozen V3 platform default; the portfolio value remains editable after migration. |
| Factory Minervini | Remains a strategy; its former 20% cash reserve becomes a **portfolio** `portfolio_cash_reserve_pct` seed **if no portfolio reserve exists yet**. That 20% is a **migration seed** of the single OD-19 percentage, not a frozen V3 platform default and not 20% of cash or of NAV. |

No forced sells, no cash wipes, no dropping recommendation history.

---

## 26. Chart Enhancement

Holdings / stock performance chart (`PriceVolumeChart`):

| Range | V3 |
|-------|-----|
| 1M, 3M, 6M, 1Y | Remain |
| **5Y** | Add |
| **All** | Remain as a control, but meaning **changes** |

**All** = all **available stored (and, when synced, provider) history** for that stock, **not** V1 “since first buy of the current position” (**OD-17**). All is **not** limited to 5Y, **not** limited to 550 days, and **not** limited to any other V3 numeric ceiling. If available history is longer than 5Y, All MUST be able to represent that longer history.

V1 `GET /stocks/{id}/prices` via `priceHistoryForHolding` filters `price_date >= firstBuyDate`. That is an implementation behaviour to replace for this chart.

If the user selects 5Y (or any range) and less history exists, **show whatever is available**. No error solely because history is shorter. Keep the existing clamp hint pattern:

`Showing available data from {earliest} to {anchor} (less than selected range).`

Force-sync MAY fetch missing history from providers. Lack of data before the security’s listing/history start is success with a shorter series, not an error. Provider availability limits are external, not a V3 product-depth ceiling.

Explorer/index charts are out of scope unless they share the same control component; if they do, 5Y/All semantics SHOULD match.

---

## 27. API Contract Changes

Additive / evolving `/api` and `/api/v1` capabilities (shapes not frozen beyond capability). All remain Sanctum + active portfolio scoped.

| Capability | Notes |
|------------|--------|
| List/enable **multiple** strategies per portfolio | Registry `exactly_one_active_per_portfolio` removed |
| Per-strategy recommendation generate | `strategy_id` required or generate-all-enabled |
| Holdings include owner, unmanaged flag, **target amount** / filled | Multiple rows per stock when multiple owners (OD-01). CA quantity stays on the parent owner row (OD-10); not blended. Target is amount (OD-12); qty derived from latest raw `close_price` (OD-14). |
| `POST` adopt holding → one strategy | Quantity merges into that strategy’s existing holding if it already owns the name (§10.4). Unmanaged-adoption cost-basis merge remains unspecified. |
| Portfolio controls GET/PUT | `portfolio_cash_reserve_pct` (OD-19), SL, trailing, max position, lending policy, atomic block ₹5,000, 1% margin, platform/portfolio recall period, **minimum actionable BUY/INCREASE** (platform default ₹5,000 + portfolio override, OD-12). Do **not** expose a separately configured portfolio min-free-capital formula; **OD-24** is derived. |
| Strategy allocation % GET/PUT | sum-to-100 validation |
| Recommendation capital_status | FUNDED / PARTIALLY_FUNDED / UNFUNDED; never WATCH-for-cash |
| Eligible lenders for a rec | ranked by OD-08: lendable % descending, then lendable amount descending; exact ties arbitrary; PARTIALLY_FUNDED remainder loan size **DEP-PARTIAL-ATOMIC** (`ceil(remainder / 5000) × 5000`, no 1%); reservation amounts remain OD-06 1.01+ceil; lendable already respects **OD-24** |
| Approve/reject lending | atomic revalidation; on success, borrower owns the resulting holding; lender records a loan receivable (**DEP-ADOPT-MERGE**); `allocation_pct` unchanged |
| Recall request / approve | effective period = portfolio override if configured, else platform default 14 calendar days (**OD-07**, **DEP-RECALL-FLOOR**; override MAY be shorter or longer; 14 is not a hard floor); if several eligible loans, tap oldest by commitment time first (OD-09); amount OD-06 |
| Capital / loan ledger read APIs | include reservation vs invested reconciliation. **Unallocated Cash** (OD-20) MAY appear as a presentation figure derived from existing §22 accounting; it is not a new ledger or cash account. Derived **OD-24** `minimum_retained_capital` MAY be shown as an accounting figure; it is not a cash account. |
| Chart prices with `from`/`range=5y\|all` **without** since-buy clamp | `5y` remains a supported range. `all` = all available stored/provider history (**OD-17**); no V3 day-count ceiling. Existing `from`/`range` semantics remain. |
| Notifications hooks for capital/lending/recall | |

Existing generate/review/execute APIs remain for funded path. Breaking change: unfunded buys are no longer WATCH.

---

## 28. Database Model Changes

Conceptual (no migrations in this phase).

### 28.1 Portfolio settings / controls

Persist: `portfolio_cash_reserve_pct` (**OD-19**; one portfolio-level percentage; `required_cash_reserve` is computed, not a second configured unit), stop-loss %, trailing % (**OD-22:** migrate by seeding **15%** at portfolio level; ignore strategy-level B1/B2 values; 15% is a seed, not a locked platform default), portfolio max position, lending limits, opportunity-cost rate, optional auto-return flag, **platform default recall period** (shipped 14 calendar days), **portfolio recall override** (**DEP-RECALL-FLOOR:** MAY be shorter or longer than 14 days; 14 is not a hard floor), atomic block **₹5,000**, execution-price margin **1%**, **platform default minimum actionable BUY/INCREASE amount** (shipped **₹5,000**, OD-12), **portfolio minimum-actionable override**. Do **not** persist a separate portfolio **min-free-capital formula** or cash account for **OD-24**; that amount is computed as `nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)` (§5.5). Do **not** persist **Unallocated Cash** (OD-20) as a cash account, bank account, or allocation setting; it is presentation-only.

### 28.2 Strategy

- Allow multiple enabled strategies per `profile_id` (drop application-level exclusive active).
- `allocation_pct`
- Strategy-only config; strip portfolio keys from *meaning* (keys may linger unused).
- Optional horizon in **calendar days**, first_entry_pct (default 50%), buy_cooldown (**1 calendar day**, OD-11), min/max holdings. Recommended minimum holdings is the **OD-24** divisor; do not persist a separate min-free-capital rupee setting as the OD-24 source of truth. OD-24 rounds the division result to the nearest integer rupee (§5.5).

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

Constraints: amount ≥ ₹5,000 and a multiple of ₹5,000; for a PARTIALLY_FUNDED remainder, amount = `ceil(unfunded_remainder / 5000) × 5000` (**DEP-PARTIAL-ATOMIC**). Lender ≠ borrower; one lender per request.

The loan row is a **receivable/obligation**. It is **not** a holding-ownership split. Executed quantity funded by the loan is owned by the **borrower** (**DEP-ADOPT-MERGE**). Do not persist fractional lender/borrower stock ownership. `allocation_pct` is not rewritten by the loan.

`committed_at` is the OD-09 FIFO key for selecting among recall-eligible outstanding loans. Do not invent a separate loan-ranking column for lender % or loan size.

### 28.6 Exit attribution

On sell recommendation / transaction / exit_event: `primary_reason` enum (`strategy_exit`, `stop_loss`, `trailing_stop`, `horizon_expiry`), optional `evidence_json`.

### 28.7 Audit log

Append-only capital and ownership events (§31).

---

## 29. UI / UX Changes

| Surface | V3 |
|---------|-----|
| **Strategy page** | Eligibility, scoring, thresholds, strategy-specific exits, optional horizon, staggered first-entry % (default 50%), BUY cooldown **1 calendar day** (OD-11; not the OD-07 configurable lending recall, shipped default 14 calendar days), min/max holdings (recommended minimum is the **OD-24** divisor), conviction bands. **Remove** portfolio cash reserve, common SL/trailing, portfolio-wide cash rules. |
| **Strategy registry** | Enable multiple strategies; allocation % editor (sum 100). No exclusive activate. |
| **Portfolio settings** | `portfolio_cash_reserve_pct` (OD-19; one percentage; required reserve is computed from MAX(Invested Amount, Notional Portfolio Value)), SL, trailing (**OD-22:** initial migration seed **15%**, then user-editable; not copied from strategy B1/B2 values), portfolio caps, lending policy, recall period override (inherit platform 14-day default; override MAY be shorter or longer — **DEP-RECALL-FLOOR**; 14 is not a hard floor), opportunity cost, auto-return toggle (default off). Atomic block ₹5,000 and 1% margin are specified product values (display as policy, not as “invest the margin”). **Minimum actionable BUY/INCREASE** inherits platform ₹5,000 unless the portfolio overrides (OD-12; not a strategy setting; not OD-06). |
| **Holdings** | Owner / Unmanaged; **per-strategy rows** for the same stock (qty 50 vs 30); Adopt; **target amount** vs filled amount (qty derived from latest raw `close_price`, OD-12 / OD-14); do not offer strategy sell on unmanaged or on another strategy’s lot. Corporate-action quantity stays on the parent owner (OD-10); unmanaged CA stays unmanaged. Holdings funded by inter-strategy lending show as **borrower-owned**, not lender-owned or jointly owned (**DEP-ADOPT-MERGE**). |
| **Recommendations** | Unfunded or partially funded BUY visible with capital status; not WATCH. Lender list, including same-cycle lending for a PARTIALLY_FUNDED remainder (**DEP-PARTIAL-LEND**; no minimum remainder). Loan size = remainder rounded up to the next ₹5,000 block (**DEP-PARTIAL-ATOMIC**; ₹14,000 → ₹15,000). Approval. Lending-funded execution is **borrower-owned** (**DEP-ADOPT-MERGE**); do not show the lender as owner of that holding. BUY cooldown **1 calendar day** per stock+strategy (OD-11); does not cancel `pending_execution`. Return-quality ranking from authoritative backtest corpus when computable (**DEP-FIT-BAND-10**, OD-04); fit labelled as fit. Ranking statistical confidence shown as diagnostic only; MUST NOT drive rank or allocation. When ranking is not computable (corpus unavailable or no statistically eligible band), capital fill among that strategy’s own valid BUY/INCREASE recs follows **OD-23** (descending target investment amount / conviction amount, then fit → alphabetical symbol); that fill order MUST NOT be labelled or presented as V3 ranking. |
| **Cash** | Required reserve (`required_cash_reserve`, OD-19) vs available vs reserved (atomic allocation vs post-fill leftover) vs named **Unallocated Cash** (**OD-20**; presentation-only residual after reserve not claimed by unused-allocation) vs per-strategy allocated / deployed / **OD-24** retained floor / lendable / lent / borrowed. Unallocated Cash is **not** reserved cash, **not** post-fill reservation leftover, **not** strategy unused allocation, **not** the OD-24 retained floor, **not** a second cash pool, and **not** a withdrawal entitlement. **OD-24** retained capital is an accounting constraint (nearest integer rupees, §5.5), not a physical sub-account. **OD-21:** external/broker withdrawals MUST NOT be blocked merely because they would leave cash below `required_cash_reserve`, and MUST NOT be hard-blocked by OD-24. |
| **Dashboard** | When physical portfolio cash is below `required_cash_reserve` (**OD-21**), show a clear warning that the portfolio cash reserve is below the required level and that the user should replenish portfolio/broker cash. Do not invent layout, colour, widget, or workflow details beyond that message. This Dashboard warning is current V3 / **B3** scope. |
| **Lending / recall** | Eligible lenders only; default sort OD-08 (lendable % descending, then lendable amount descending; exact ties have no product significance). Approve; errors on stale; recall approval; show effective recall period (platform vs portfolio override). Replenishment among eligible loans: oldest commitment time first (OD-09); do not present FIFO as a lender sort. Approved lending is a loan receivable/obligation; it does **not** move the resulting holding to the lender or split ownership (**DEP-ADOPT-MERGE**). Recall/repayment does **not** transfer stock to the lender. |
| **Charts** | 5Y remains a range; All = all available history (**OD-17**), not capped at 5Y or any V3 day-count. Clamp message when available history is shorter than the selected range. |

**B4 wishlist (future; not current V3 / B3 UI).** A persistent application-wide critical alert/banner for critical portfolio conditions such as a cash-reserve shortfall. Possible future presentation could be a persistent red banner/footer/scrolling alert. That design, colour, placement, and mechanism are **not** frozen and MUST NOT be treated as a current Dashboard requirement. The current requirement is the §29 Dashboard warning only.

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
- lending-funded execution ownership (borrower owns the holding; lender receivable only) (**DEP-ADOPT-MERGE**)
- corporate-action quantity adjustments per parent owner (OD-10)
- exit primary attribution
- allocation % changes

Users and operators MUST be able to reconstruct who lent what to whom, when it became recallable, why an exit was attributed as it was, and that a lending-funded holding is owned by the borrower rather than the lender.

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
| 6 | Two trailing definitions | **Superseded.** Highest raw `close_price` from entry (§15, OD-14). Proxy forbidden. |
| 7 | Unordered exit any/all | **Superseded** for cross-mechanism attribution (§13.2). Strategy-internal any/all may remain. |
| 8 | Fit used as rank and allocator weight | **Superseded.** §4. Ranking from **authoritative backtest** trimmed mean (OD-03, OD-04, **DEP-FIT-BAND-10**), not live trades, not fit. When ranking is not computable, capital fill is **OD-23** (target-amount order), still not fit-as-rank and not presented as V3 ranking. |
| 9 | Single cash pool + profile-wide stale-cancel | Pool **kept**. Stale-cancel **scoped per strategy**. Lending added as virtual claims (loan receivable/obligation). Lending-funded holdings are **borrower-owned** (**DEP-ADOPT-MERGE**); the lender does not acquire the stock. |
| 10 | Chart since-buy, no 5Y, ~550d storage | **Superseded (OD-17).** Chart **5Y** and **All** specified. **All** = all available stored/provider history, not since-buy and not capped at 5Y. V1 ~550-day `HISTORY_DEPTH_TARGET_DAYS` is an as-built limitation only. V3 has **no** maximum history-depth ceiling. |
| 11 | Paper = backtest only | Multiple portfolios remain for hypothesis testing. Live exclusivity deferred to broker era. |

V1 recommendation evidence `capital_allocation.status = unfunded` with action WATCH is **legacy**. Clients MUST accept V3 capital_status on BUY actions.

---

## 33. Decision Log and Open Decisions

### 33.1 Frozen / resolved (OD-01 through OD-24)

OD-01 through OD-07 were recorded from the 2026-08-14 product-decision session. OD-08 through OD-11 were recorded from the 2026-08-15 product-decision session. OD-12 through OD-24 were recorded from the 2026-08-17 product-decision session. **DEP-FIT-BAND-10** and clarifications to **OD-03**, **OD-04**, and **OD-23** were recorded from the 2026-08-19 product-decision session. These IDs MUST NOT reappear in §33.2.

| ID | Topic | Decision | Status |
|----|--------|----------|--------|
| **OD-01** | Same stock owned by multiple strategies | **ALLOW.** Two or more strategies in one portfolio may independently own the same stock (e.g. A: RELIANCE 50, B: RELIANCE 30; portfolio 80). Do not model `(portfolio, stock)` as globally unique. Cost, trailing, exit, allocation, and attribution are per owner. | FROZEN |
| **OD-02** | Horizon / period `T` | **CALENDAR DAYS**, not trading sessions. `T = 30` means 30 calendar days. Opportunity-cost `T_years = calendar_days / 365`. | FROZEN |
| **OD-03** | Ranking corpus | **BACKTESTS ONLY.** Do not use the user’s live-trading history as ranking observations. **Authoritative corpus** = unique historical observations from the **latest completed** backtest for each `strategy_version_id`, using **maximum available** historical market data (§4.2.1). Do **not** combine overlapping prior completed runs; do **not** double-weight re-runs over the same period; observations do **not** age out. | FROZEN |
| **OD-04** | Trimmed mean | Symmetric **7% lower + 7% upper**. **Normal minimum sample size = 15** per fit band; **DEP-FIT-BAND-10** permits neighbor merges (max two) and threshold reductions 15→12→10 (never below 10). If still ineligible, trimmed-mean ranking is not eligible. | FROZEN |
| **OD-05** | Partial capital funding | **ALLOWED.** If desired capital exceeds available free capital, allocate the available free capital as a partial position. Do **not** convert OPEN/INCREASE to WATCH. Capital status is a separate axis. Consistent with staggered buys. | FROZEN |
| **OD-06** | Atomic block and execution margin | Atomic block = **₹5,000**. Apply **1%** margin then **ceil** to the next ₹5,000: `atomic_allocation = ceil((calculated_requirement × 1.01) / 5000) × 5000`. This is a **reservation/allocation**, not a mandate to invest the margin. Unused reservation reverts after execution. If atomic requirement exceeds free capital, OD-05 partial funding still applies. | FROZEN |
| **OD-07** | Recall frequency | **CONFIGURABLE** at platform default and portfolio override. Effective = override if set, else platform default. Shipped default **14 calendar days** remains applicable. Must not be a non-configurable hard-code that ignores settings. **DEP-RECALL-FLOOR** (frozen separately): a portfolio override MAY be shorter or longer than 14 days; 14 days is not a hard floor. | FROZEN |
| **OD-08** | Default lender selection after eligibility | **FROZEN.** Applies to selecting a prospective **lender** (a strategy) before/when a new loan is created — not to outstanding loans. After existing lender eligibility filters (§8.1), rank eligible lenders: (1) available-for-lending **percentage** descending; (2) if tied, available-for-lending **absolute amount** descending; (3) if both values are still exactly equal, **any** tied eligible lender may be selected (first remaining candidate, or arbitrary/random among exact ties). There is deliberately **no** third business ranking criterion and **no product significance** to which exactly-tied lender is selected. Do **not** use FIFO, oldest loan, largest loan, strategy age, raw portfolio cash, or any other business ranking rule for that third tie-break. Available-for-lending percentage remains the primary ranking metric already defined by this specification. Available-for-lending amount is the explicit secondary ranking criterion. | FROZEN |
| **OD-09** | Outstanding loan selection for replenishment | **FROZEN.** Operates on existing outstanding **loans**, not prospective lenders. When replenishment requires capital to be recalled and multiple outstanding loans are eligible: (1) filter to loans eligible for recall under existing V3 recall / effective-minimum-period rules (OD-07 / §6.5); (2) select the **oldest eligible outstanding loan first (FIFO)** by commitment time; (3) if two or more eligible loans have exactly the same commitment time, **any** tied loan may be selected (implementation may resolve arbitrarily or deterministically). There is deliberately **no** additional business ranking criterion after FIFO. Do **not** introduce largest loan first, smallest loan first, lender percentage, lender absolute lendable amount, borrower strength, or strategy ranking as loan-selection rules. OD-06 still controls the amount recalled: request the minimum required atomic amount; do **not** recall an entire loan merely because it is the oldest if only a smaller amount is required. OD-07 controls **when** a loan becomes recall-eligible; OD-09 controls **which** eligible loan is selected. If a borrower must sell positions as a consequence, position ranking is **OD-16** (§17); OD-09 does not rank positions. | FROZEN |
| **OD-10** | Corporate-action quantity ownership | **FROZEN.** Corporate actions follow the **parent holding’s owner**. For every strategy-owned or unmanaged position identified by `(portfolio, stock, owner)`, a corporate action applicable to that holding MUST adjust **that owner’s** position quantity. If the same stock has multiple strategy owners, the CA MUST NOT first blend those holdings into one portfolio-level position. Strategy-owned parent → CA remains with that owner. Unmanaged parent → CA remains unmanaged and MUST NOT be automatically assigned to a strategy. The governing rule is **parent-owner attachment**, not pro-rata allocation (pro-rata can differ for rounding, rights, broker-posted quantities, and other CA mechanics). OD-10 does **not** freeze split/bonus formulas, rights-issue calculations, cost-basis / average-price / target / filled / trailing-high / stop-loss restatement, or merger/demerger treatment. **OD-14** freezes raw `close_price` for SL/trailing comparison and the OD-12 reference price; that does **not** implicitly restatement-solve those CA leftovers and MUST NOT be read as using `adjusted_close_price` to compensate for corporate actions. | FROZEN |
| **OD-11** | BUY cooldown duration, unit, and clock | **FROZEN.** Duration = **1 calendar day** (not trading sessions). Key = `(stock, strategy)`. Applies to OPEN and INCREASE; does not suppress REDUCE, EXIT, or HOLD. Another strategy’s BUY of the same stock does not consume, reset, or affect this cooldown. Primary purpose: prevent BUY recommendation churn; secondary: space repeated capital deployment. A BUY recommendation **opportunity / generation cycle** starts the cooldown (Day 0 allowed, Day 1 suppressed, Day 2 elapsed). It does **not** start on fill, trade approval, or lending commitment. Fills do not reset it. It is **not** the OD-07 recall period (still configurable, shipped default 14 calendar days). Unapproved BUYs MUST NOT be regenerated during the window; stale-cancel MUST NOT clear cooldown; `pending_execution` MUST NOT be cancelled because of cooldown. First entry remains ~50% of current **target amount**; subsequent INCREASE = `max(0, current_target_amount − filled_amount)`, not a fixed second 50% tranche (OD-12). Target MAY change during cooldown; use latest target amount and filled amount when the window elapses. | FROZEN |
| **OD-12** | Staggered target primary unit | **FROZEN.** Staggered target primary unit = **monetary amount**. Quantity is derived from the **latest available raw daily `close_price`** (OD-14 consistency clarification of “latest daily closing price”) using whole-share rounding (default **floor**; do not materially overshoot). Rounding MUST NOT change the persisted target amount. Minimum actionable BUY/INCREASE amount is configurable at **platform** level with **portfolio-level** override; no strategy override; shipped platform default = **₹5,000**. The minimum applies to the this-cycle opportunity, not the overall target; remaining below the minimum suppresses OPEN/INCREASE without reducing the target. Subsequent INCREASE uses current target amount minus filled amount (OD-11). OD-06 atomic reservation and OD-05 partial funding MUST NOT replace or reset the target amount. Recommendation calculations use latest raw `close_price`, not execution price and not `adjusted_close_price`. | FROZEN |
| **OD-13** | Stop-loss reference price | **FROZEN.** Stop-loss `entry_price` = **weighted-average execution cost of actual fills** for the **current ownership episode**, per stock + strategy owner (not first-fill price). `average_cost = (sum of actual executed fill value) / (sum of filled quantity)`. Subsequent INCREASE fills (and other actual fills of the same episode) update the average; the stop is not anchored to the first fill. A new ownership episode (after full exit, then a new position) receives a new cost basis. Do not blend owners (OD-01). Do not use target amount, reservation, last close, or estimated price as cost basis. Trailing stop remains highest raw `close_price` since entry **date** (not OD-13). The hit-test comparison series is OD-14 (raw `close_price`). **OD-15** freezes adoption entry-date continuity for trailing/stop windows without changing OD-13 cost-basis rules. CA cost restatement and unmanaged-adoption cost-basis merge remain unspecified (OD-10). DEP-ADOPT-MERGE is the lending-funded ownership rule (merge into borrower) and does not resolve those leftovers. | FROZEN |
| **OD-14** | Daily comparison price series | **FROZEN.** Portfolio stop-loss and trailing-stop calculations use raw `close_price` as the single consistent daily comparison series. The latest raw `close_price` is also the OD-12 reference price for recommendation/quantity calculations. `adjusted_close_price` is not used for these calculations. Corporate-action restatement remains separately governed by OD-10 and is not implicitly resolved by OD-14. Per-owner isolation (OD-01) means each owner has its own trailing window/high; all owners use the same `close_price` column. Execution/fill price remains separate. OD-15 governs adoption entry-date continuity for trailing/stop windows. | FROZEN |
| **OD-15** | Adoption entry-date continuity for trailing/stop windows | **FROZEN.** For an unmanaged holding adopted into a strategy, adoption does **not** reset the trailing/stop `entry_date` window. Preserve the original first-buy / existing ownership-history entry date; adoption changes owner attribution but is not a new investment purchase. Trailing-high history therefore continues from existing entry history rather than restarting on adoption. This does **not** resolve cost-basis merge mechanics or complete merge behavior when the destination strategy already owns the stock; those remain unspecified. **DEP-ADOPT-MERGE** (frozen separately) is lending-funded holding ownership (merge into borrower), not unmanaged-adoption cost-basis merge. OD-13 cost basis and OD-14 raw `close_price` comparison series remain unchanged. | FROZEN |
| **OD-16** | Weakest-position score formula | **FROZEN.** When recall/replenishment requires selling borrower-owned positions, rank by simple configured-window percentage return: `window_return_pct = (current_reference_price − window_start_reference_price) / window_start_reference_price × 100`. Lowest score = weakest = sell first. Evaluation window is strategy-configurable in calendar days. Not lifetime XIRR, total return, age, fit, thesis, momentum, volatility, or OD-03 ranking. Not annualised XIRR. Tie-break: `stock_id` ascending on exact `window_return_pct` ties. Eligible universe: borrower-owned only (OD-01). Loan tap remains OD-09; recall timing OD-07; amount OD-06. Reference prices are **DEP-WEAKEST-PRICE** (frozen separately: Option A, raw `close_price`; latest available current close; window start = latest trading day on or before the window-start date, walk backward only). Insufficient history is **DEP-WEAKEST-HISTORY** (frozen separately: Option A, shorten to available history for that position only; do not exclude or block). OD-14 is unchanged and is not extended by these freezes. | FROZEN |
| **OD-17** | OHLCV history depth | **FROZEN.** V3 must support **all available OHLCV history** for each listed security. There is **no** maximum history-depth ceiling imposed by the V3 product specification. V3 does **not** define a fixed numeric depth target. 5Y must be supported when the listing has 5Y of available history; 5Y is a chart range, not a storage ceiling. History shorter than 5Y is valid. History longer than 5Y MUST remain available and MUST NOT be truncated by a V3 product-level maximum. **All** = all available stored/provider history. Provider and listing/history-start limits are external availability constraints, not V3 ceilings. The V1/as-built `HISTORY_DEPTH_TARGET_DAYS = 550` is **not** a V3 requirement. Do not introduce 1,825 days or any other invented numeric ceiling. | FROZEN |
| **OD-18** | Minimum period before annualized return is used in ranking | **FROZEN.** Annualized return (CAGR/XIRR) MAY be used as ranking evidence only when the backtest holding period is **≥ 30 calendar days**. For holding periods **< 30 calendar days**, annualized return MUST NOT contribute to ranking. Simple return remains a valid backtest outcome. Applies to the OD-03 backtest ranking corpus and ranking aggregation. Reuses the V1 backtest “refuses CAGR under 30 days” rule, stated as **30 calendar days** (not trading sessions). Does **not** modify the §19 success definition, OD-02 `T_years` / opportunity-cost formula, OD-04 trimmed mean, OD-16 weakest-position scoring, or OD-23 (fill order when ranking is not computable; OD-23 is frozen separately and is not a ranking rule). | FROZEN |
| **OD-19** | Portfolio cash reserve unit / formula | **FROZEN.** One configurable portfolio-level percentage: `portfolio_cash_reserve_pct`. `reserve_base = MAX(total_invested_amount, current_notional_portfolio_value)`. `required_cash_reserve = reserve_base × portfolio_cash_reserve_pct`. **Invested Amount** = actual capital paid to acquire currently held investments (not current market value). **Notional Portfolio Value** = current market value of currently held investments (not invested cost). Do **not** use % of cash, % of NAV, an absolute-₹ configuration, invested amount + available cash, or two separate percentages. The reserve remains a **portfolio-level** control, non-investable and non-lendable. Partial funding cannot invade it (OD-05). Does **not** freeze a numeric default for `portfolio_cash_reserve_pct`. Does **not** decide leftover unallocated-cash UI (**OD-20**, frozen separately as presentation-only), withdrawal-into-reserve (**OD-21**, frozen separately: withdrawals MUST NOT be blocked merely because they would leave cash below `required_cash_reserve`). Strategy minimum retained capital is **OD-24** (frozen separately: `nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)`; not this reserve; not a physical cash account). | FROZEN |
| **OD-20** | Unallocated cash UI | **FROZEN.** The Cash UI MUST display a named **Unallocated Cash** bucket representing residual portfolio cash after `required_cash_reserve` that is not claimed by strategy unused-allocation accounting. Presentation-only over the existing single physical portfolio cash pool. MUST NOT create a separate cash account, bank account, strategy-owned cash account, new allocation percentage, new reserve, new lending pool, new withdrawal entitlement, or new accounting ledger. MUST NOT invent a new residual-cash formula; it presents the residual already described by §22. Distinct from `required_cash_reserve` (OD-19), `available_physical_cash` / Available cash, pending-execution reserved cash (OD-06), post-fill unused reservation leftover, and strategy unused allocation. Does **not** decide withdrawal-into-reserve (**OD-21**, frozen separately). Strategy min-retained capital is **OD-24** (frozen separately; not Unallocated Cash). Allocation % still sums to 100% of investable capital. | FROZEN |
| **OD-21** | Withdrawal vs portfolio cash reserve | **FROZEN. YES.** Withdrawals MUST NOT be blocked merely because they would cause physical portfolio cash to fall below `required_cash_reserve`. “Withdraw” means taking/transferring cash out of the portfolio/broker cash account (typically to an external bank account), not a transfer between virtual strategy allocations. LidoPortfolio does not control the broker cash account; the application MUST represent a resulting **reserve shortfall** rather than pretending the withdrawal could not happen. A shortfall is a **warning/alert condition**, not a hard withdrawal restriction. The user MAY replenish broker/portfolio cash later. MUST NOT terminate automatic executions or execution recommendations **solely** because cash is below `required_cash_reserve`. MUST NOT invent a reserve-adjusted withdrawal-cap formula, withdrawal ledger, or new cash bucket. `available_physical_cash` and the OD-19 reserve formula are unchanged. Reserve remains non-investable and non-lendable; OD-21 is not permission to invest or lend reserve cash. Unallocated Cash (OD-20) remains presentation-only and is not a withdrawal entitlement. Dashboard MUST warn when cash is below `required_cash_reserve` and that the user should replenish (current V3 / B3). A persistent application-wide critical banner is **B4 wishlist**, not current UI. OD-24 MUST NOT be used to hard-block those external withdrawals. | FROZEN |
| **OD-22** | Migrating trailing % when only `default_stoploss_percent` exists | **FROZEN.** Option C: **fixed migration seed**. Portfolio-level trailing percentage is seeded to **15%** on V3 migration. Existing strategy-level trailing/stop values (as-built B1/B2 code-level configuration) are **ignored completely**. Do **not** derive, average, max, min, or otherwise reconcile strategy-level percentages into the portfolio seed. Do **not** copy `default_stoploss_percent` into trailing. Do **not** leave trailing disabled as the migration outcome. 15% is a **migration seed / initial portfolio value**, not a permanently enforced platform default. After migration the portfolio trailing % remains user-editable to any supported value. Trailing remains independent of stop-loss % (§15.3). Does **not** change the trailing-stop formula, OD-14 price series, OD-13 stop-loss cost basis, or OD-15 entry-date continuity. | FROZEN |
| **OD-23** | Capital fill order among a strategy’s own valid BUY/INCREASE recs when return-quality ranking is not computable | **FROZEN.** Applies when return-quality ranking is not computable because the authoritative backtest corpus is unavailable or no fit band is statistically eligible after **DEP-FIT-BAND-10**. Capital fill order MUST be **descending target investment amount / conviction amount** (higher first). This is a **capital fill order**, **not** V3 return-quality ranking. MUST NOT label or present target-amount order as the V3 ranking. MUST NOT change the normal ranked path when return-quality ranking **is** computable (allocator still follows ranking order / return quality). MUST NOT use fit as a replacement for return-quality ranking. Tie-break order MUST be: (1) **fit score** (higher first); (2) **alphabetical** order of the stock listing symbol (ascending). MUST NOT use ranking statistical confidence or a conviction sub-score. MUST NOT introduce a strategy-configurable conviction sub-score or new strategy setting. The alphabetical tie-break MUST make the ordering deterministic (same inputs → same fill order). OD-05 partial funding, OD-06 atomic reservation, reserve protection, and strategy allocation limits remain unchanged. Does **not** change **OD-24** or any **DEP-*** item. | FROZEN |
| **OD-24** | Minimum free capital / one opportunity | **FROZEN.** For each strategy, `minimum_retained_capital = nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)`. Round the division result to the **nearest integer rupee**. Exact fractional **.5** values MUST round **upward**. MUST NOT use banker's rounding or language/runtime `round()` if that can implement a different .5 tie. For this non-negative rupee domain, `floor(value + 0.5)` is the deterministic equivalent, where `value` is the non-negative division result. `strategy_capital_allocation` is the rupee amount currently allocated to that strategy from the portfolio’s investable capital. `recommended_minimum_holdings` is that strategy’s configured recommended minimum number of holdings/opportunities. The result is the strategy-level protected “one opportunity” amount. It is an accounting constraint, **not** a physical cash account, **not** a strategy bank/cash sub-account, **not** another portfolio cash reserve, and **not** `required_cash_reserve` (OD-19). It does **not** modify `available_physical_cash`, the 100% strategy allocation rule, BUY funding, OD-05 partial funding, OD-06 pending-execution reservation, OD-23 capital fill order, or the return-quality ranking path when computable per **DEP-FIT-BAND-10** and OD-04. It is **not** a percentage-based formula and MUST NOT use portfolio NAV, total portfolio cash, `required_cash_reserve`, total portfolio value, strategy deployed capital, B1/B2 configuration, conviction, fit, target size, or ranking as the base or inputs. It applies to unused-allocation / lending eligibility: the strategy MUST retain at least this amount before excess unused allocation can be considered available-for-lending. Lending MUST NOT consume the retained amount. OD-24 MUST NOT hard-block external/broker withdrawals (OD-21). Example: investable capital ₹10,00,000, Momentum 75% → allocation ₹7,50,000, recommended minimum holdings 5 → retain ₹1,50,000. Further examples: ₹7,50,000 / 7 → ₹1,07,143; ₹7,50,000 / 8 → ₹93,750. Does **not** resolve any **DEP-*** item. Does **not** change OD-19, OD-20, OD-21, OD-22, or OD-23. | FROZEN |

Note: before the 2026-08-14 session, **OD-05** meant “minimum free capital / one opportunity formula”. That topic was re-homed as **OD-24** and is now frozen as the formula above.

### 33.2 Open decisions

None of **OD-01 through OD-24** remain open. Do not invent new OD identifiers here. **DEP-TRIM-K**, **DEP-FIT-BAND-10**, **DEP-PARTIAL-LEND**, **DEP-PARTIAL-ATOMIC**, **DEP-ADOPT-MERGE**, **DEP-WEAKEST-PRICE**, **DEP-WEAKEST-HISTORY**, and **DEP-RECALL-FLOOR** are frozen (§33.3.1). There are **no** remaining open DEP-* rows in this specification.

### 33.3 Dependencies arising from frozen decisions (not invented rules)

These are ambiguities created or revealed by OD-01–OD-24. Unresolved rows are **not** silent product law. All DEP-* rows identified in this specification are now frozen in §33.3.1.

#### 33.3.1 Resolved / frozen dependencies

| ID | Topic | Decision | Status |
|----|--------|----------|--------|
| **DEP-TRIM-K** | Integer conversion of 7% × `n` to tail count `k` | **FROZEN.** `k = nearest_integer(0.07 × n)`. Exact fractional **.5** values MUST round **upward**. MUST NOT use banker's rounding or language/runtime `round()` if that can implement a different .5 tie. For this non-negative domain, `k = floor(0.07 × n + 0.5)` is the deterministic equivalent. The **same** `k` is removed from both tails. Does **not** change OD-04’s 7%/7% trimmed mean, the minimum-`n` eligibility gate (**DEP-FIT-BAND-10**), OD-03, OD-18, OD-23, or OD-24. Ranking eligibility and trim-count calculation remain distinct. Examples: n=15→k=1; n=20→k=1; n=25→k=2; n=30→k=2; n=35→k=2; n=50→k=4; n=100→k=7. | FROZEN |
| **DEP-FIT-BAND-10** | Fit-band construction and adaptive sparsity for return-quality ranking | **FROZEN.** Normal/default **10-point** fit bands on the 0–100 strategy fit scale. When sparse: merge with an adjacent neighboring band; **maximum two** neighbor merge expansions; then if still below minimum, reduce required observation count **once** 15→12 and **once** 12→10; **never below 10**; no further merges beyond the two-expansion cap. If still ineligible, band remains statistically ineligible (OD-23 for mapped opportunities). Persist resulting fit band and adjustments as ranking/audit evidence. Compute and persist **ranking statistical confidence** as diagnostic only; confidence MUST decrease with merges and threshold reductions; MUST NOT affect return-quality calculation, ranking order, allocation, eligibility, or OD-23 tie-breaks; MUST NOT be probability of success or future-return prediction. Strategy `score_bands` remain conviction sizing, not ranking bands. Does **not** change OD-04 trimmed-mean formula, **DEP-TRIM-K**, OD-03 corpus rules (§4.2.1), OD-18, OD-23 tie-break order, or OD-24. | FROZEN |
| **DEP-PARTIAL-LEND** | Same-cycle lending after PARTIALLY_FUNDED own capital | **FROZEN.** When a recommendation is PARTIALLY_FUNDED from own available capital and an unfunded remainder exists, that remainder MUST be eligible to open a **same-cycle** lending request. Lending is not deferred merely because partial own-capital funding already occurred. If lending is approved, borrowed capital MUST be available **immediately** for the intended funding/execution path. Minimum requirement to use lending = **NONE**. A remainder below ₹5,000 remains eligible. Excess borrowed capital above the requirement is permitted and remains available. Loan **size** for a non-multiple remainder is **DEP-PARTIAL-ATOMIC**. Example: ₹3,000 additional needed → eligible same-cycle; loan ₹5,000. Example: own ₹4,000, requirement ₹18,000, remainder ₹14,000 → eligible same-cycle; actual loan ₹15,000 (DEP-PARTIAL-ATOMIC). MUST NOT stay UNFUNDED merely because the requirement is below ₹5,000. OD-05, OD-12 target amount, and explicit user approval unchanged. Does **not** create a new cash/loan/allocation account. Does **not** reinterpret ₹5,000 as a minimum recommendation size or minimum remainder. Does **not** change OD-24. | FROZEN |
| **DEP-PARTIAL-ATOMIC** | Atomic loan size of a same-cycle remainder | **FROZEN.** Round the required borrowed amount **UP** to the next ₹5,000 atomic loan block. Required loan = unfunded remainder. Remainder = this-cycle required/target amount minus allocated own capital, **not** `atomic_allocation` minus own. Actual loan MUST be at least that remainder and MUST use the ₹5,000 atomic loan unit. If the remainder is not an exact multiple of ₹5,000, `loan_amount = ceil(unfunded_remainder / 5000) × 5000`. Exact multiples stay unchanged (₹15,000 → ₹15,000). Examples: ₹3,000 → ₹5,000; ₹14,000 → ₹15,000; ₹15,000 → ₹15,000. Worked example: own ₹4,000, target ₹18,000, remainder ₹14,000 → loan ₹15,000, excess ₹1,000 remains available. There is **no** minimum remainder; a ₹3,000 remainder still uses lending and results in a ₹5,000 loan. This is a **loan-size** rule, not a minimum funding requirement. Do **not** reduce the loan below the remainder merely to preserve denomination. Do **not** reject lending merely because the remainder is below ₹5,000. **DEP-PARTIAL-LEND** unchanged (same-cycle allowed; funds immediately on approval). Not the OD-06 1% reservation formula. Does **not** change OD-05, OD-06, OD-23, OD-24, DEP-TRIM-K, or other DEP-* rows. | FROZEN |
| **DEP-ADOPT-MERGE** | Ownership of investments funded by inter-strategy lending | **FROZEN. OPTION A — MERGE INTO BORROWER.** When inter-strategy lending funds a recommendation: (1) borrowed capital becomes **deployable borrowed capital** of the **borrowing** strategy for the funded opportunity; (2) the **borrowing** strategy owns the resulting investment/holding; (3) the **lending** strategy does **not** acquire ownership of the resulting holding; (4) the lender records a **loan receivable**; (5) the borrower records the corresponding **loan obligation**; (6) do **not** introduce fractional/split ownership of a holding based on own-vs-borrowed capital; (7) physical cash remains one portfolio-level cash pool — do **not** create physical strategy bank accounts or cash buckets; (8) loan repayment is a loan/capital transaction and does **not** transfer stock ownership; (9) borrowed capital is additional deployable capital for the borrower but does **not** change the configured strategy allocation percentage; (10) do **not** reinterpret this as permanently increasing configured allocation or changing portfolio allocation percentages. Example: Strategy A lends ₹15,000 to Strategy B → A has receivable ₹15,000 and no stock ownership; B has ₹15,000 deployable borrowed capital, owns the resulting ₹15,000 investment, and has obligation ₹15,000. Do **not** model the holding as jointly or fractionally owned by A and B. OD-24, OD-05, OD-06, **DEP-PARTIAL-LEND**, and **DEP-PARTIAL-ATOMIC** unchanged. This is **not** unmanaged-holding adoption and does **not** freeze unmanaged-adoption cost-basis merge. | FROZEN |
| **DEP-WEAKEST-PRICE** | OD-16 reference-price column and lookup | **FROZEN. OPTION A — RAW CLOSE_PRICE.** For OD-16 weakest-position window-return calculations: `current_reference_price` = latest available daily `close_price` at evaluation time. `window_start_reference_price` = `close_price` from the latest available trading day **ON OR BEFORE** the configured OD-16 window-start date. If that date is not a trading day, walk **backward** to the latest available daily OHLCV record on or before that date; do **not** look forward to the next trading day. Do **not** use `adjusted_close_price`. Do **not** use execution/fill price for either reference. The OD-16 formula, lowest-is-weakest ranking, `stock_id` ascending tie-break, borrower-owned eligibility, OD-06 amount, OD-07 recall timing, and OD-09 loan selection remain unchanged. This does **not** modify **OD-14** (raw `close_price` remains the frozen rule for portfolio stop-loss, trailing stop, and OD-12 reference-price calculations only). **DEP-WEAKEST-HISTORY** is frozen separately (Option A, shorten window for that position only). | FROZEN |
| **DEP-WEAKEST-HISTORY** | Insufficient OD-16 window history | **FROZEN. OPTION A — SHORTEN WINDOW.** When a borrower-owned position lacks sufficient historical daily OHLCV to obtain the normal window-start reference for the configured `N`-calendar-day window: the position remains eligible; use maximum available history up to `N`; `window_start_reference_price` = earliest available valid daily raw `close_price` in that available history; do **not** look forward to manufacture `N`; do **not** use `adjusted_close_price` or fill price; do **not** substitute lifetime return, fit, conviction, volatility, age, or XIRR. Same OD-16 `window_return_pct` formula. Configured `N` remains the maximum and is **not** globally rewritten. Short-history positions are not excluded and MUST NOT block selection. UI/backend MUST NOT present the shortened period as full `N`-day history. Fallback only when history is insufficient. No invented minimum-history threshold beyond at least one valid start reference and existing OD-16 calculation requirements. Example: N=90; 3Y history uses 90 days; 40-day history uses 40 days. Lowest `window_return_pct` remains weakest. `stock_id` ascending tie-break, borrower-owned eligibility, OD-06, OD-07, and OD-09 unchanged. **DEP-WEAKEST-PRICE** unchanged. | FROZEN |
| **DEP-RECALL-FLOOR** | Whether a portfolio recall override may be shorter than 14 calendar days | **FROZEN. OPTION B.** A portfolio-level recall-period override MAY be either **shorter or longer** than the platform’s shipped 14-calendar-day default. `effective_recall_period = portfolio_recall_period_override if configured else platform_default_recall_period`. Shipped platform default remains **14 calendar days**. 14 days is **not** a hard minimum/floor. Do **not** introduce a separate minimum recall-period value. Do **not** clamp portfolio overrides to 14 days. Do **not** reinterpret 14 days as a minimum. Two-level model unchanged: platform default, then portfolio override (authoritative when set). Examples: no override → 14; override 7 → 7; override 3 → 3; override 14 → 14; override 21 → 21. OD-07 otherwise unchanged (calendar days; clock start = successful capital commitment/approval; cannot recall before effective period). OD-09 FIFO, OD-06 amount, OD-16 weakest-position selection, default user approval, and optional auto-return (off by default) unchanged. | FROZEN |

#### 33.3.2 Remaining unresolved dependencies

None. All DEP-* rows identified in this specification are frozen in §33.3.1.

---

## 34. Implementation Sequence

Planning order for later passes. Do not implement in this phase.

1. **Schema & domain foundations** — portfolio controls storage; multiple enabled strategies; holding uniqueness `(profile, stock, owner)` (OD-01); CA quantity stays on the parent owner row (OD-10); adoption; `capital_status` including PARTIALLY_FUNDED; loan/request tables as receivable/obligation (**DEP-ADOPT-MERGE:** borrower owns lending-funded holdings; lender does not); exit attribution column.
2. **Generation scoping** — per-strategy generate; stale-cancel scoped; unmanaged/other-owner excluded from strategy exits; stop converting unfunded or partial BUY to WATCH (OD-05).
3. **Portfolio SL / trailing / precedence** — raw `close_price` (OD-14) for SL hit test, trailing high, and trailing current close; highest raw close from **that holding’s** entry date; SL `entry_price` = weighted-average **actual fill** cost of the current ownership episode (OD-13), updated on INCREASE fills; migrate config off strategy JSON. **OD-22:** seed portfolio trailing % to **15%**; ignore strategy-level B1/B2 values; do not copy stop-loss %. Do not use `adjusted_close_price` for these. Do not treat OD-14 as CA restatement (OD-10 leftovers).
4. **Staggered entry + BUY cooldown + partial fill** — persist **target amount** per owner (OD-12); derive whole-share qty from latest available raw `close_price` (floor; OD-14); first entry default 50% of current target amount; subsequent INCREASE = current target amount − filled amount; suppress OPEN/INCREASE below effective min actionable (platform ₹5,000 / portfolio override); BUY cooldown **1 calendar day** from the recommendation opportunity (OD-11); OD-05/OD-06 must not replace the target amount.
5. **Virtual allocation accounting** — % summing to 100; available vs lendable; `required_cash_reserve` per **OD-19** (`MAX(Invested Amount, Notional Portfolio Value) × portfolio_cash_reserve_pct`); **OD-24** `minimum_retained_capital = nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)` (nearest integer rupee; exact .5 rounds upward; not banker's rounding; strategy-level lending floor; not a physical sub-account; not OD-19); atomic reservation vs post-fill leftover; Cash UI named **Unallocated Cash** bucket (**OD-20**, presentation-only; not a new ledger or formula); no physical sub-accounts.
6. **Lending workflow** — eligibility; available-for-lending after **OD-24** retained floor; ranking by lendable % then lendable amount then arbitrary exact tie (OD-08); display without commit; OD-06 reservation amounts; **DEP-PARTIAL-LEND** same-cycle lending after PARTIALLY_FUNDED (no minimum remainder; funds immediately on approval); **DEP-PARTIAL-ATOMIC** `ceil(remainder / 5000) × 5000` loan size (₹14,000 → ₹15,000); **DEP-ADOPT-MERGE** merge into borrower (loan receivable/obligation; borrower owns the holding; `allocation_pct` unchanged); atomic approve; failure paths; audit. Do not apply FIFO to lenders. Do not lend the OD-24 retained amount.
7. **Recall / replenishment** — platform default 14 calendar days + portfolio override that MAY be shorter or longer (**OD-07**, **DEP-RECALL-FLOOR**; 14 days is not a hard floor); among eligible loans, oldest commitment time first (OD-09); amount OD-06 (not whole loan merely because it is oldest); approval default; weakest-position ranking per **OD-16** (`window_return_pct`, lowest first; tie-break `stock_id` ascending) using raw `close_price` (**DEP-WEAKEST-PRICE**: latest available current close; window start = latest trading day on or before the window-start date, walk backward only). **DEP-WEAKEST-HISTORY:** if a position lacks full `N` history, shorten to available history for that position only; do not exclude it or block selection.
8. **History depth & charts** — Support **all available** OHLCV history with **no** V3 product-level maximum history-depth ceiling (**OD-17**). 5Y range when 5Y exists; All = all available history (may exceed 5Y); clamp hint when shorter; V1 `HISTORY_DEPTH_TARGET_DAYS = 550` is as-built only.
9. **Historical ranking** — authoritative backtest corpus only (OD-03, §4.2.1); **DEP-FIT-BAND-10** 10-point bands with adaptive sparsity; 7%/7% trimmed mean (OD-04); tail count **`k = nearest_integer(0.07 × n)`** with exact .5 rounding upward (**DEP-TRIM-K**); ranking statistical confidence diagnostic only; annualized/CAGR/XIRR ranking evidence only if holding period **≥ 30 calendar days** (**OD-18**); never ship fit-as-rank; never mix live trades or overlapping prior runs. When return-quality ranking is not computable, capital fill among that strategy’s own valid BUY/INCREASE recs is **OD-23** (descending target investment amount / conviction amount, then fit → alphabetical symbol); do not present that fill order as V3 ranking.
10. **UI / notifications / help** — Strategy vs Portfolio settings split; per-owner holdings; capital states; Cash UI named **Unallocated Cash** (**OD-20**, presentation-only); Dashboard warning when cash is below `required_cash_reserve` (**OD-21**, B3); lender UX; contextual help sync when behaviour ships. Persistent application-wide critical banner remains **B4 wishlist**.
11. **Migration backfill** — unmanaged default; safe inference; do not merge distinct strategy lots; non-destructive JSON split; portfolio trailing % seed **15%** (**OD-22**), ignoring strategy-level trailing/stop values.
12. **Tests** — acceptance in §35, especially OD-01 same-symbol lots, OD-10 parent-owner CA attachment, OD-11 1-calendar-day BUY cooldown vs OD-07 recall, OD-12 target amount / whole-share floor / min actionable ₹5,000, OD-13 weighted-average fill cost vs first-fill, OD-14 raw `close_price` for SL/trailing/OD-12 reference, OD-16 weakest `window_return_pct` ranking and tie-break, OD-18 30-calendar-day annualized ranking gate, OD-19 `required_cash_reserve` formula vs % of cash/NAV, OD-20 named Unallocated Cash UI vs reserve/reserved/unused-allocation, OD-21 withdraw-into-reserve allowed with Dashboard shortfall warning (not a buy/lend permission), OD-22 portfolio trailing seed 15% vs copy-from-strategy or copy-from-SL, **OD-23** unranked fill order (target investment amount / conviction amount descending, then fit → alphabetical symbol; not presented as V3 ranking; no conviction sub-score; ranked path unchanged when return-quality ranking is computable), **DEP-FIT-BAND-10** 10-point bands with adaptive sparsity and ranking statistical confidence diagnostic only, authoritative backtest corpus = latest completed run per strategy version (§4.2.1; no overlapping-run merge; observations do not age out), **OD-24** `nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)` retained floor (nearest integer rupee; exact .5 upward; not banker's rounding) vs OD-19 / physical cash / BUY funding, **DEP-TRIM-K** `k = nearest_integer(0.07 × n)` with .5 upward (n=15→1, n=50→4, n=100→7; not banker's rounding), **DEP-PARTIAL-LEND** same-cycle lending after partial own funding (₹3,000 gap still loans ₹5,000; ₹14,000 remainder same-cycle), **DEP-PARTIAL-ATOMIC** remainder rounded up to next ₹5,000 (₹14,000 → ₹15,000 loan, ₹1,000 excess), **DEP-ADOPT-MERGE** lending-funded holding owned by borrower not lender (receivable/obligation; `allocation_pct` unchanged; no fractional own-vs-borrowed ownership), **DEP-WEAKEST-PRICE** OD-16 raw `close_price` (latest available current close; window start on or before, walk backward only; not `adjusted_close_price`; not fill price; OD-14 unchanged), **DEP-WEAKEST-HISTORY** insufficient history shortens to available history for that position only (N=90 with 40 days still eligible; do not exclude or block; do not rewrite configured N), **DEP-RECALL-FLOOR** portfolio recall override may be shorter or longer than the 14-day platform default (14 is not a hard floor), OD-05 partial vs WATCH, OD-06 reservation vs fill, OD-08 lender ranking vs OD-09 loan FIFO, §24 races.

---

## 35. Acceptance Criteria

### 35.1 Objective / ranking

- Fit score is stored and shown as fit.
- Final ranking, when present, is not a sort by fit alone and is not `fit × p(fit)`.
- Ranking observations come **only** from the **authoritative backtest** corpus (OD-03, §4.2.1): **latest completed** backtest per `strategy_version_id`, **maximum available** historical market data, **unique observations** only; overlapping prior completed runs are **not** combined; observations do **not** age out.
- Trimmed mean is symmetric 7% / 7% with normal minimum `n = 15` per fit band (**DEP-FIT-BAND-10** permits neighbor merges and reductions to 12 or 10; never below 10). If a band cannot become statistically eligible, trimmed-mean ranking is not computed for that band (OD-04).
- **DEP-FIT-BAND-10:** normal 10-point fit bands; up to two adjacent neighbor merges; then permitted minimum reductions 15→12→10; persist resulting band and adjustments; ranking statistical confidence decreases with compromises and is diagnostic only.
- **DEP-TRIM-K:** `k = nearest_integer(0.07 × n)`; exact .5 rounds upward; same `k` from both tails. Eligibility (effective `n_min` per **DEP-FIT-BAND-10**) and trim-count (`k`) remain distinct. Examples: n=15→k=1; n=20→k=1; n=25→k=2; n=30→k=2; n=35→k=2; n=50→k=4; n=100→k=7. Do not use banker's rounding.
- Ranking statistical confidence MUST NOT affect return-quality calculation, ranking order, capital allocation, eligibility, or OD-23 tie-breaks. It is not probability of success or a future-return prediction.
- A 100% outcome and a 10% outcome are not scored as equal successes.
- Success flags require positive return **and** NIFTY 50 beat **and** annualized opportunity-cost beat (§19; **OD-18 does not change this**).
- 12% (or configured `r`) is applied as annualized, scaled by **calendar** period length (`T_years = calendar_days / 365`).
- **OD-18:** backtest holding period **≥ 30 calendar days** → annualized return/CAGR/XIRR MAY be ranking evidence; **< 30 calendar days** → annualized return/CAGR/XIRR MUST NOT be ranking evidence. Simple return remains a valid outcome. Ranking corpus remains backtests only (OD-03). OD-04 trimmed mean unchanged. OD-16 weakest-position scoring unchanged.
- When return-quality ranking is not computable (corpus unavailable or no statistically eligible fit band), capital fill among that strategy’s own valid BUY/INCREASE recs is **OD-23**, not a return-quality rank and not fit-as-rank. See §35.16.

### 35.2 Multi-strategy

- Two or more strategies can be enabled in one portfolio and each generate recommendations.
- Generating strategy A does not cancel strategy B’s open recs.
- Strategy A cannot EXIT/REDUCE B’s owned holdings, including when both own the same stock.

### 35.3 Ownership

- Executed strategy buys are owned by that strategy.
- **DEP-ADOPT-MERGE:** a buy funded in whole or in part by inter-strategy lending is owned by the **borrowing** strategy. The lender records a loan receivable and does **not** own the resulting stock. Do not model joint or fractional own-vs-borrowed ownership.
- Example: A lends ₹15,000 to B; B’s resulting ₹15,000 investment is owned by B; A has receivable ₹15,000.
- Loan repayment does not transfer that stock to the lender.
- Deployable borrowed capital does **not** change configured `allocation_pct`.
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
- **DEP-PARTIAL-LEND:** that unfunded remainder MUST be eligible for a same-cycle lending request. Lending is not deferred because partial own funding already occurred. If approved, borrowed capital is available immediately.
- A remainder or additional requirement below ₹5,000 still may use lending. **DEP-PARTIAL-ATOMIC:** loan = remainder rounded **up** to the next ₹5,000 block. Example: ₹3,000 needed → borrow ₹5,000; ₹3,000 used; ₹2,000 excess remains available. The rec MUST NOT stay UNFUNDED merely because the requirement is below ₹5,000.
- Example: own ₹4,000, target ₹18,000, remainder ₹14,000 → same-cycle lending; loan ₹15,000; excess ₹1,000 remains available.
- It is never persisted as WATCH solely for cash.
- Telegram/actionability does not treat it as WATCH.
- Target amount is not reset to the funded slice (OD-12).

### 35.5 Staggered entry and target amount (OD-12)

- Persisted target is primarily **amount**, not quantity. Quantity is derived from the **latest available raw daily `close_price`** (OD-14).
- Fractional shares are not generated. Default conversion is **floor**; do not add a share that would materially overshoot the intended amount (example: ₹2,500 at ₹600 → 4 shares, notional ₹2,400).
- Whole-share rounding does **not** change the persisted target amount (target stays ₹2,500 in that example).
- First BUY ≈ configured first-entry % (default 50%) of **current target amount**, then OD-05/OD-06 may reduce this-cycle cash. The 50% rule is first-entry only.
- Subsequent INCREASE = `current_target_amount − filled_amount`. Example: target ₹10,000, filled ₹5,000; if target later ₹12,000 → remaining ₹7,000; if later ₹8,000 → remaining ₹3,000. Then convert remaining to whole shares at latest raw `close_price`.
- Target-amount changes during OD-11 cooldown are kept and used when the window elapses.
- If current target is below the filled amount, no BUY/INCREASE for a gap.
- Effective minimum actionable BUY/INCREASE: platform default **₹5,000**; portfolio inherits unless it overrides. No strategy override. Opportunity below the minimum → no new OPEN/INCREASE; **target is not reduced**.
- Do not emit zero-quantity or immaterial repeated BUY/INCREASE recs solely to consume whole-share residuals.
- OD-06 atomic reservation does not replace the target amount (example: requirement ₹18,000, reserve ₹20,000, target stays ₹18,000).
- OD-05 partial funding does not reset the target to the funded slice.
- Recommendation calculations use latest raw `close_price`, not execution price and not `adjusted_close_price`. Execution-price bands are out of OD-12.

### 35.6 Cooldown

- BUY cooldown is **1 calendar day** (OD-11): Day 0 BUY allowed; Day 1 same stock+strategy BUY rec suppressed; Day 2 elapsed.
- Starts on the BUY **recommendation opportunity / generation cycle**, not on fill, trade approval, or lending commitment.
- Fills do not reset cooldown. Stale-cancel does not clear cooldown. `pending_execution` is not cancelled because of cooldown.
- A second **new** BUY rec for the same **stock+strategy** is suppressed during the window, including regenerating an unapproved BUY on Day 1.
- Another strategy’s BUY of the same stock does not consume, reset, or affect this strategy’s cooldown.
- EXIT/REDUCE/HOLD for that pair are not suppressed.
- BUY cooldown is not the OD-07 lending recall period (configurable; shipped default 14 calendar days; portfolio override may be shorter or longer — **DEP-RECALL-FLOOR**).

### 35.7 Exits

- Primary attribution follows strategy exit > stop-loss > trailing > horizon.
- No horizon configured ⇒ no horizon expiry event.
- Horizon `T` is calendar days (OD-02).
- Trailing uses max raw `close_price` since **that holding’s** entry date × (1 − pct); not unrealized-% proxy; not blended across owners of the same symbol; **not** OD-13 average cost; **not** `adjusted_close_price`.
- Stop-loss compares latest raw `close_price` to OD-13 `stop_price`; not intraday low; per holding; **not** `adjusted_close_price`.
- Stop-loss `entry_price` is weighted-average **actual execution** cost of the current ownership episode (**OD-13**), not first-fill price, not target amount, not reservation, not latest close.
- First fill sets the initial average cost; a later INCREASE fill at a different price updates the average (example: 50@₹100 then 50@₹120 → ₹110; 10% stop → ₹99).
- Different strategy owners of the same stock keep independent cost bases and trailing windows (OD-01); all use the same raw `close_price` column (OD-14).
- After a full exit, a new position is a new ownership episode with a new cost basis.
- On adoption from unmanaged, trailing/stop entry date preserves existing/original first-buy entry history; adoption does not reset the window or create a new trailing/stop episode (OD-15).
- Raw `close_price` is used consistently for SL hit test, trailing high, trailing current close, and OD-12 reference price; raw and adjusted series are never mixed (OD-14).
- `adjusted_close_price` is not used for stop-loss, trailing stop, or OD-12 quantity derivation.
- OD-10 corporate-action quantity attachment is unchanged; OD-14 does not silently expand CA restatement scope.
- **OD-22:** portfolio trailing % is seeded to **15%** on migration; strategy-level trailing/stop values are ignored; 15% is not a locked platform default; the user may change the portfolio value afterwards. Trailing formula and OD-14 series are unchanged.

### 35.8 Lending and atomic capital

- Lender list contains only eligible strategies.
- Default lender ranking (**OD-08**) is available-for-lending **percentage** descending, then available-for-lending **amount** descending. Exact ties on both keys: any tied eligible lender may be selected; that choice has no product significance.
- Lender ranking MUST NOT use FIFO, oldest loan, largest loan, strategy age, or raw portfolio cash as a business sort key.
- Sort keys are **not** maximum available cash / the portfolio cash ledger.
- Display does not reduce lendable balances.
- Approval revalidation can fail; no commit on failure.
- Race A then B against the same lender: B fails if capital is gone.
- Loan/request amounts use OD-06 (×1.01 then ceil to ₹5,000) for **reservation** sizing. Examples: ₹23,700 → ₹25,000; ₹25,000 → ₹30,000; ₹19,000 → ₹20,000; ₹4,000 → ₹5,000. Same-cycle remainder **loan size** is **DEP-PARTIAL-ATOMIC**: `ceil(unfunded_remainder / 5000) × 5000` (₹3,000 → ₹5,000; ₹14,000 → ₹15,000; ₹15,000 → ₹15,000). There is no minimum remainder to use lending.
- Same-cycle lending after PARTIALLY_FUNDED is required to be eligible (**DEP-PARTIAL-LEND**). Approval makes borrowed capital immediately available. Excess borrowed above the requirement remains available (example: remainder ₹14,000, loan ₹15,000, excess ₹1,000).
- **DEP-ADOPT-MERGE:** that borrowed capital is deployable capital of the **borrower**. The resulting holding is borrower-owned. The lender has a loan receivable, not stock. `allocation_pct` is unchanged. Repayment does not transfer stock.
- The reserved atomic amount is not required to be fully invested; leftover reservation reverts after fill. The 1% is not an auto-invest instruction. That leftover is **not** **Unallocated Cash** (OD-20). DEP-PARTIAL-LEND excess borrowed capital is likewise available; it is not a new account.
- No multi-lender split of one request.
- Reserve (`required_cash_reserve`, **OD-19**), pending-execution reserved cash, and the strategy’s **OD-24** minimum retained capital cannot be lent.
- Recall before the **effective** period is rejected (**OD-07**). Effective period = portfolio override if configured, else the shipped platform default of 14 calendar days. A configured override MAY be shorter or longer than 14 days (**DEP-RECALL-FLOOR**; 14 is not a hard floor).
- When several outstanding loans are recall-eligible, replenishment taps the **oldest** by commitment time first (**OD-09**). Exact commitment-time ties: any tied loan may be selected. Do not rank loans by size, lender %, lender amount, borrower strength, or strategy ranking.
- Replenishment requests min needed (OD-06), not necessarily the full selected loan, including when that loan is the oldest.
- Default recall requires user approval.

### 35.9 Weakest position (OD-16)

- When recall/replenishment requires sells, rank **borrower-owned** positions only (OD-01).
- Weakest score = configured-window simple percentage return: `window_return_pct = (current_reference_price − window_start_reference_price) / window_start_reference_price × 100`.
- Lowest `window_return_pct` = weakest = sell first.
- Evaluation window is strategy-configurable in **calendar days**.
- Do **not** use lifetime XIRR alone, lifetime total return alone, or oldest holding alone.
- Do **not** use annualised XIRR, fit score, thesis score, momentum, volatility, or OD-03 opportunity ranking as the weakest score.
- Exact ties on `window_return_pct`: break by **`stock_id` ascending**.
- Loan selection remains OD-09 FIFO; recall timing OD-07; amount OD-06.
- **DEP-WEAKEST-PRICE:** `current_reference_price` = latest available daily `close_price` at evaluation time. `window_start_reference_price` = `close_price` from the latest available trading day **on or before** the configured window-start date (walk backward only; do not look forward). Do not use `adjusted_close_price`. Do not use execution/fill price. OD-14 is unchanged (SL/trailing/OD-12 only).
- **DEP-WEAKEST-HISTORY:** if a position lacks enough history for the configured `N`-day window, it remains eligible. Use maximum available history up to `N`. `window_start_reference_price` = earliest available valid daily raw `close_price` in that history. Do not exclude the position, do not block selection, do not rewrite configured `N` globally, and do not present the shortened period as full `N`-day history. Same `window_return_pct` formula. Example: N=90; 3Y history uses 90 days; 40-day history uses 40 days.

### 35.10 Charts

- 1M/3M/6M/1Y remain; **5Y** exists as a range (not a maximum).
- **All** = all available stored/provider history, not since-buy, **not** limited to 5Y, **not** limited to 550 days or any other V3 numeric ceiling (**OD-17**).
- History older than 5Y, when available, MUST remain representable under All and MUST NOT be artificially capped by a V3 product maximum.
- Short history + long requested range ⇒ available data + clamp hint; no error merely because the security has less than 5Y (or less than the selected range).
- Lack of history before listing/history start is a valid shorter series.

### 35.11 Migration

- Existing cash, transactions, and recs remain.
- No forced liquidation.
- Existing unique `(profile, stock)` holdings become unmanaged or safely inferred; they are not silently split into multi-strategy lots.
- **OD-22:** portfolio trailing % is seeded to **15%**; existing strategy-level trailing/stop values are ignored; 15% is not a locked platform default.

### 35.12 Audit

- Every lending approval, rejection, commitment, return, and exit primary reason is persisted and retrievable.

### 35.13 Portfolio cash reserve (OD-19)

- There is exactly **one** configurable portfolio-level percentage: `portfolio_cash_reserve_pct`. It is **not** a strategy setting.
- `required_cash_reserve = MAX(total_invested_amount, current_notional_portfolio_value) × portfolio_cash_reserve_pct`.
- **Invested Amount** is actual capital paid to acquire currently held investments, not current market value.
- **Notional Portfolio Value** is current market value of currently held investments, not invested cost.
- On decline of market value below invested amount, the reserve does not fall below the configured percentage of invested amount (example: invested ₹80, notional ₹60, 20% → reserve ₹16).
- On appreciation above invested amount, the reserve requirement rises with notional value (example: invested ₹80, notional ₹100, 20% → reserve ₹20).
- 20% in those examples is illustrative, not a frozen V3 numeric default.
- The reserve cannot be used for new buys or loans. Partial funding cannot invade it (OD-05).
- The reserve is **not** % of cash, **not** % of NAV, **not** an absolute-₹ configuration, and **not** invested amount + available cash.
- Withdrawal-into-reserve is **OD-21** (frozen: MUST NOT hard-block; warn on Dashboard; reserve remains non-investable and non-lendable). Named **Unallocated Cash** UI is **OD-20** (presentation-only; does not change this reserve formula). Strategy min-retained capital is **OD-24** (frozen separately; not this reserve).

### 35.14 Unallocated Cash UI (OD-20)

- The Cash UI MUST show a named **Unallocated Cash** bucket: residual portfolio cash after `required_cash_reserve` that is not claimed by strategy unused-allocation accounting.
- The bucket is **presentation-only** over the existing single physical cash pool.
- It MUST NOT create a separate cash account, bank account, strategy-owned cash account, new allocation %, new reserve, new lending pool, new withdrawal entitlement, or new accounting ledger.
- It MUST NOT introduce a new residual-cash formula; existing §22 accounting is unchanged.
- It is **not** `required_cash_reserve`, **not** `available_physical_cash`, **not** pending-execution reserved cash, **not** post-fill reservation leftover, and **not** strategy unused allocation.
- Withdrawal-into-reserve is **OD-21** (frozen separately; Unallocated Cash is still not a withdrawal entitlement). Strategy min-retained capital is **OD-24** (frozen separately; `nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)`; not Unallocated Cash; not a physical cash account).

### 35.15 Withdrawal vs cash reserve (OD-21)

- External/broker withdrawals MUST NOT be blocked merely because they would leave physical cash below `required_cash_reserve`.
- The application MUST represent a reserve shortfall in accounting rather than pretending the withdrawal could not happen.
- A shortfall is a **warning/alert**, not a hard withdrawal restriction. The user MAY replenish later.
- Dashboard MUST warn that the reserve is below the required level and that the user should replenish portfolio/broker cash (current V3 / **B3**). Do not invent layout, colour, or widget details.
- A persistent application-wide critical alert/banner is **B4 wishlist** only; it is not a current UI requirement.
- MUST NOT terminate automatic executions or execution recommendations **solely** because cash is below `required_cash_reserve`.
- Reserve remains non-investable and non-lendable. OD-21 is not permission to invest or lend reserve cash. Partial funding still cannot invade the reserve for buys (OD-05).
- `available_physical_cash` and the OD-19 reserve formula are unchanged. No reserve-adjusted withdrawal-cap formula. No withdrawal ledger or new cash bucket.
- Unallocated Cash remains presentation-only and is not a withdrawal entitlement (OD-20).
- OD-24 MUST NOT be used to hard-block such external withdrawals.

### 35.16 Capital fill order when ranking is not computable (OD-23)

- OD-23 applies only when return-quality ranking is not computable because the authoritative backtest corpus is unavailable or no fit band is statistically eligible after **DEP-FIT-BAND-10**.
- For each strategy’s own valid BUY/INCREASE recommendations, capital fill order MUST be **descending target investment amount / conviction amount** (higher first).
- This is a **capital fill order**, **not** V3 return-quality ranking. MUST NOT label or present target-amount order as the V3 ranking.
- When return-quality ranking **is** computable, the allocator still follows ranking order / return quality. OD-23 MUST NOT change that ranked path.
- Fit MUST NOT be used as a replacement for return-quality ranking. Fit remains labelled and stored as fit. Treating “rank = fit” remains forbidden.
- Tie-break order MUST be exactly: (1) **fit score** (higher first); (2) **alphabetical** order of the stock listing symbol (ascending).
- MUST NOT use ranking statistical confidence or a conviction sub-score as a tie-break. MUST NOT introduce a strategy-configurable conviction sub-score or new strategy setting.
- The alphabetical tie-break MUST make the ordering deterministic (same inputs → same fill order).
- OD-05 partial funding, OD-06 atomic reservation, reserve protection, and strategy allocation limits remain unchanged.
- OD-24 is frozen separately (§35.17) and is not changed by OD-23. **DEP-TRIM-K**, **DEP-FIT-BAND-10**, **DEP-PARTIAL-LEND**, **DEP-PARTIAL-ATOMIC**, **DEP-ADOPT-MERGE**, **DEP-WEAKEST-PRICE**, **DEP-WEAKEST-HISTORY**, and **DEP-RECALL-FLOOR** are frozen separately (§33.3.1). There are no remaining open DEP-* items.

### 35.17 Minimum retained capital / one opportunity (OD-24)

- For each strategy, `minimum_retained_capital = nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)`.
- Round the division result to the **nearest integer rupee**. Exact fractional **.5** values MUST round **upward**. MUST NOT use banker's rounding or language/runtime `round()` if that can implement a different .5 tie. For this non-negative rupee domain, `floor(value + 0.5)` is the deterministic equivalent, where `value` is the non-negative division result.
- `strategy_capital_allocation` is the rupee amount currently allocated from the portfolio’s investable capital, not deployed capital, not NAV, not total cash, not `required_cash_reserve`.
- `recommended_minimum_holdings` is the strategy’s configured recommended minimum holdings/opportunities, not hard `max_holdings`.
- The result is the strategy-level protected “one opportunity” amount. Example: investable capital ₹10,00,000, Momentum 75% → ₹7,50,000, recommended minimum holdings 5 → retain ₹1,50,000. Further examples: ₹7,50,000 / 7 → ₹1,07,143; ₹7,50,000 / 8 → ₹93,750.
- It is an accounting constraint, not a physical cash account, not a strategy bank/cash sub-account, and not another portfolio cash reserve.
- It does not modify `required_cash_reserve` (OD-19), `available_physical_cash`, the 100% allocation rule, BUY funding, OD-05, OD-06, OD-23, or the return-quality ranking path when computable per **DEP-FIT-BAND-10** and OD-04.
- Lending / available-for-lending MUST subtract this floor; lending MUST NOT consume it.
- OD-24 MUST NOT hard-block external/broker withdrawals (OD-21).
- **DEP-TRIM-K**, **DEP-PARTIAL-LEND**, **DEP-PARTIAL-ATOMIC**, **DEP-ADOPT-MERGE**, **DEP-WEAKEST-PRICE**, **DEP-WEAKEST-HISTORY**, and **DEP-RECALL-FLOOR** are frozen separately and are not changed by OD-24. There are no remaining open DEP-* items.

### 35.18 Same-cycle lending after partial own funding (DEP-PARTIAL-LEND)

- A PARTIALLY_FUNDED recommendation with an unfunded remainder MUST be eligible to open a same-cycle lending request.
- Lending is not deferred merely because partial own-capital funding already occurred.
- If lending is approved, borrowed capital MUST be available immediately for the intended funding/execution path.
- Minimum loan amount = ₹5,000 (amount borrowed). There is no minimum remainder or minimum requirement to use lending.
- Remainder ₹3,000 → loan ₹5,000 (**DEP-PARTIAL-ATOMIC**); ₹3,000 used; ₹2,000 excess available. MUST NOT stay UNFUNDED because the requirement is below ₹5,000.
- Own ₹4,000 + target ₹18,000 → remainder ₹14,000 same-cycle eligible; actual loan ₹15,000; excess ₹1,000; if approved, immediately available.
- Target amount is unchanged. Lending remains an explicit user decision. No new cash/loan/allocation account.
- Loan size is **DEP-PARTIAL-ATOMIC** (below).
- Ownership of the funded holding is **DEP-ADOPT-MERGE** (§35.20): merge into borrower.

### 35.19 Atomic remainder loan size (DEP-PARTIAL-ATOMIC)

- Required loan = unfunded remainder. Remainder = this-cycle required/target amount minus allocated own capital (not `atomic_allocation` minus own). Actual loan MUST be at least that remainder and MUST be a ₹5,000 atomic block.
- If the remainder is not an exact multiple of ₹5,000, round **UP** to the next ₹5,000: `loan_amount = ceil(unfunded_remainder / 5000) × 5000`.
- ₹3,000 → ₹5,000; ₹14,000 → ₹15,000; ₹15,000 → ₹15,000.
- Own ₹4,000, target ₹18,000, remainder ₹14,000 → loan ₹15,000, excess ₹1,000 remains available.
- No minimum remainder. Do not reject lending because the remainder is below ₹5,000. Do not reduce the loan below the remainder.
- This is a loan-size rule, not a minimum funding requirement, and not the OD-06 1% reservation formula.
- **DEP-PARTIAL-LEND** remains: same-cycle allowed; borrowed capital immediately available after approval.
- **DEP-ADOPT-MERGE** (below) governs ownership of the resulting holding.
- **DEP-WEAKEST-PRICE** is frozen separately (§35.21). **DEP-WEAKEST-HISTORY** is frozen separately (§35.22). **DEP-RECALL-FLOOR** is frozen separately (§35.23). There are no remaining open DEP-* items.

### 35.20 Lending-funded holding ownership (DEP-ADOPT-MERGE)

- OPTION A — MERGE INTO BORROWER.
- Borrowed capital is **deployable borrowed capital** of the borrowing strategy for the funded opportunity.
- The borrowing strategy owns the resulting investment/holding. The lending strategy does **not**.
- Lender records a loan receivable; borrower records the corresponding loan obligation.
- Do not introduce fractional/split ownership based on own-vs-borrowed capital.
- Physical cash remains one portfolio-level pool. No physical strategy bank accounts or cash buckets.
- Loan repayment is a loan/capital transaction and does not transfer stock ownership.
- Deployable borrowed capital does **not** change configured strategy `allocation_pct` and is not a permanent increase of that strategy’s policy share.
- Example: A lends ₹15,000 to B → A receivable ₹15,000, no stock; B deployable ₹15,000, owns the ₹15,000 investment, obligation ₹15,000.
- OD-24, OD-05, OD-06, DEP-PARTIAL-LEND, and DEP-PARTIAL-ATOMIC remain unchanged.
- Unmanaged-adoption cost-basis merge when the destination already owns the stock remains unspecified.
- **DEP-WEAKEST-PRICE** is frozen separately (§35.21). **DEP-WEAKEST-HISTORY** is frozen separately (§35.22). **DEP-RECALL-FLOOR** is frozen separately (§35.23).

### 35.21 OD-16 reference prices (DEP-WEAKEST-PRICE)

- OPTION A — RAW CLOSE_PRICE.
- `current_reference_price` = latest available daily `close_price` at evaluation time.
- `window_start_reference_price` = `close_price` from the latest available trading day **ON OR BEFORE** the configured OD-16 window-start date.
- If the window-start date is not a trading day, walk **backward** to the latest available daily OHLCV record on or before that date. Do **not** look forward.
- Do **not** use `adjusted_close_price`. Do **not** use execution/fill price for either reference.
- OD-16 formula, lowest-is-weakest ranking, and `stock_id` ascending tie-break are unchanged. OD-06 amount, OD-07 recall timing, and OD-09 loan selection are unchanged.
- OD-14 is unchanged: raw `close_price` for portfolio stop-loss, trailing stop, and OD-12 only. This freeze does not extend OD-14.
- **DEP-WEAKEST-HISTORY** is frozen separately (§35.22): shorten to available history for that position only.
- **DEP-RECALL-FLOOR** is frozen separately (§35.23): portfolio override MAY be shorter or longer than the 14-day platform default.

### 35.22 Insufficient OD-16 window history (DEP-WEAKEST-HISTORY)

- OPTION A — SHORTEN WINDOW.
- If a borrower-owned position lacks sufficient history for the configured `N`-calendar-day window, it MUST remain eligible.
- Use the maximum available history for that position, up to configured `N`.
- `window_start_reference_price` = earliest available valid daily raw `close_price` within that available history.
- Do not look forward to manufacture the configured window. Do not use `adjusted_close_price` or fill price. Do not substitute lifetime return, fit, conviction, volatility, age, or XIRR.
- Same OD-16 `window_return_pct` formula. Lowest score remains weakest. `stock_id` ascending tie-break unchanged.
- Configured `N` remains the maximum evaluation window and is not rewritten globally. Example: N=90; 3Y history uses 90 days; 40-day history uses 40 days and is not excluded.
- UI/backend MUST NOT represent the shortened period as though full `N`-day history existed.
- Fallback only when history is insufficient. Do not invent a minimum-history threshold beyond at least one valid start reference and existing OD-16 calculation requirements.
- OD-06, OD-07, OD-09, and **DEP-WEAKEST-PRICE** remain unchanged.
- **DEP-RECALL-FLOOR** is frozen separately (§35.23) and is not changed by this fallback.

### 35.23 Portfolio recall-period override vs 14-day default (DEP-RECALL-FLOOR)

- OPTION B — a portfolio-level recall-period override MAY be either **shorter or longer** than the platform’s shipped 14-calendar-day default.
- `effective_recall_period = portfolio_recall_period_override if configured else platform_default_recall_period`.
- The shipped platform default remains **14 calendar days**. 14 days is **not** a hard minimum/floor.
- Do **not** introduce a separate minimum recall-period value. Do **not** clamp portfolio overrides to 14 days. Do **not** reinterpret 14 days as a minimum.
- Two-level configuration remains: platform-level default, then portfolio-level override. The portfolio override is authoritative when set.
- Examples: no override → 14 days; override 7 → 7; override 3 → 3; override 14 → 14; override 21 → 21.
- OD-07 otherwise unchanged: period is calendar days; clock starts at successful capital commitment/approval; a loan cannot be recalled before the effective period expires.
- Recall still requires user approval by default. Automatic return remains optional and off by default.
- OD-09 FIFO among recall-eligible outstanding loans, OD-06 recalled amount, and OD-16 weakest-position selection remain unchanged. Borrower repayment/position-release behaviour remains unchanged.

---

## Appendix A — Terms not to conflate

| Do not confuse | With |
|----------------|------|
| Fit score | Final rank / expected return |
| OD-23 capital **fill order** (descending target investment amount / conviction amount when ranking is not computable) | V3 return-quality ranking / a label of that fill order as “rank” / a silent fallback to fit-as-rank |
| OD-23 fill-order tie-break **fit** | Fit used as the fallback V3 ranking key |
| OD-23 fill-order tie-break order (fit → alphabetical symbol ascending) | Ranking order when return-quality ranking **is** computable |
| **DEP-FIT-BAND-10** ranking fit bands | Strategy `score_bands` conviction target-sizing bands |
| Ranking statistical confidence (diagnostic) | Return-quality rank / probability of success / capital allocation weight / OD-23 tie-break |
| Ranking eligibility (effective `n_min` per **DEP-FIT-BAND-10**: 15, 12, or 10) | Trim-count `k` derived from 7% of `n` (**DEP-TRIM-K**) |
| **Historical data depth** (OHLCV available in authoritative backtest) | **Number of unique observations** in ranking corpus / **number of backtest executions** |
| **Latest completed** authoritative backtest per strategy version | Combining overlapping prior completed runs into one corpus |
| Ranking observations ageing out over time | Valid corpus observations from older history in the authoritative run |
| **DEP-TRIM-K** `k = nearest_integer(0.07 × n)` with exact .5 rounding **upward** | Floor-only `k` / ceil-only `k` / banker's (round-half-to-even) `round()` |
| **OD-24** `minimum_retained_capital` (`nearest_integer(strategy_capital_allocation / recommended_minimum_holdings)`) | `required_cash_reserve` (OD-19) / pending-execution reserved cash (OD-06) / Unallocated Cash (OD-20) / a physical strategy cash account |
| OD-24 nearest-integer **rupee** rounding (exact .5 upward; `floor(value + 0.5)` on the non-negative division result) | Banker's (round-half-to-even) rounding / leaving the division unrounded / applying this rounding to **DEP-TRIM-K** trim count `k` |
| OD-24 strategy-level one-opportunity **accounting floor** | Portfolio-level non-investable reserve / a change to `available_physical_cash` / a BUY-funding cap / OD-23 fill order |
| OD-24 divisor **recommended minimum holdings** | Hard `max_holdings` / `1 / max_holdings` position cap |
| Available-for-lending (unused allocation after OD-24 floor and other lending constraints) | Unused allocation in full / OD-19 reserve / Unallocated Cash |
| WATCH | Unfunded or partially funded BUY |
| Ranking observations | Live-trading history |
| Atomic allocation / reservation | Amount that must be invested (includes 1% margin) **or** the position **target amount** (OD-12) |
| Target **amount** (source of truth) | Derived whole-share quantity / executable notional |
| OD-12 minimum actionable BUY/INCREASE (platform ₹5,000 / portfolio override) | OD-06 atomic reservation block (₹5,000) |
| **DEP-PARTIAL-LEND** minimum **loan amount** ₹5,000 | A minimum unfunded remainder / minimum recommendation size / a bar that makes a sub-₹5,000 gap ineligible for lending |
| Same-cycle lending after PARTIALLY_FUNDED (**DEP-PARTIAL-LEND**) | Deferring the remainder to a later cycle merely because own capital already partial-funded |
| Unfunded remainder ₹14,000 | Actual loan ₹15,000 (**DEP-PARTIAL-ATOMIC** ceil to next ₹5,000 block) |
| Unfunded remainder (this-cycle required/target amount − allocated own capital) | `atomic_allocation` − own capital (OD-06 reservation gap) |
| **DEP-PARTIAL-ATOMIC** `ceil(remainder / 5000) × 5000` loan size | OD-06 1% reservation formula / a minimum funding requirement / rejecting remainder < ₹5,000 / lending a ₹14,000 remainder as a ₹14,000 loan |
| Excess borrowed capital above the this-cycle requirement (permitted, remains available) | A new cash account, loan account, or allocation bucket |
| Latest raw daily `close_price` (OD-12 reference / target qty; OD-14 SL/trailing comparison) | Execution price / `adjusted_close_price` |
| First-entry ~50% of current **target amount** | Subsequent INCREASE = current target amount − filled amount |
| Stop-loss `entry_price` (weighted-average **actual fill** cost, OD-13) | First-fill price / latest close / `adjusted_close_price` |
| Stop-loss average cost (OD-13) | Target amount / atomic reservation / last close (OD-12 / OD-06) |
| Trailing stop (highest raw `close_price` since **entry date**, OD-14) | Stop-loss (average cost × (1 − pct)) / `adjusted_close_price` |
| OD-22 15% portfolio trailing **migration seed** | A permanently enforced platform default / copy of `default_stoploss_percent` / strategy-level B1/B2 trailing values / average-max-min reconciliation of those values |
| Portfolio-level trailing % (OD-22) | A strategy-level trailing setting |
| Adoption of unmanaged holding into strategy | A new purchase that resets trailing/stop entry history |
| OD-15 adoption entry-date continuity | Unspecified unmanaged-adoption cost-basis / multi-lot merge mechanics (not **DEP-ADOPT-MERGE**) |
| **DEP-ADOPT-MERGE** (lending-funded holding owned by the **borrower**) | Lender-owned holding / joint or fractional own-vs-borrowed stock ownership |
| Deployable borrowed capital (**DEP-ADOPT-MERGE**) | Configured strategy `allocation_pct` / a permanent increase of the borrower’s policy share |
| Loan receivable (lender) / loan obligation (borrower) | Ownership of the resulting stock |
| Loan repayment (capital/loan transaction) | Transfer of the borrower’s stock to the lender |
| OD-16 weakest `window_return_pct` (recall/replenishment) | OD-03 opportunity ranking / annualised XIRR / fit / thesis / momentum |
| OD-18 30-calendar-day minimum for annualized-return **use in ranking** | §19 annualized opportunity-cost **success** test / OD-02 `T_years` / OD-16 non-annualised weakest return |
| **DEP-WEAKEST-PRICE** raw daily `close_price` for OD-16 references | `adjusted_close_price` / execution or fill price / looking **forward** to the next trading day after the window-start date |
| OD-16 raw `close_price` via **DEP-WEAKEST-PRICE** (latest available current close; window start on or before) | OD-14 SL/trailing/OD-12 series (same column, **separate** freeze; OD-14 is not extended) |
| OD-16 reference-price **column/lookup** (**DEP-WEAKEST-PRICE**, frozen) | Insufficient-history fallback (**DEP-WEAKEST-HISTORY**, frozen separately: shorten window) |
| **DEP-WEAKEST-HISTORY** shorten to available history for **that position only** | Changing the configured strategy window globally / excluding the position / blocking weakest-position selection / presenting shortened history as full `N` days |
| V3 all-available OHLCV history (OD-17; no product-depth ceiling) | V1/as-built `HISTORY_DEPTH_TARGET_DAYS = 550` / a fixed numeric V3 depth target / 5Y as a storage maximum |
| Unique `(portfolio, stock)` | V3 holding identity |
| Manual / IPO unmanaged quantity | Corporate-action quantity on an existing parent holding (OD-10) |
| Pro-rata CA allocation | Parent-owner / per-parent-holding CA attachment (OD-10) |
| OD-10 CA owner attachment | CA cost/trailing-high/stop restatement (not solved by OD-14) |
| Recommendation expiry (hours) | Strategy horizon |
| Horizon `T` | Trading sessions |
| BUY cooldown (1 calendar day, OD-11) | OD-07 lending recall (configurable; shipped default 14 calendar days; portfolio override may be shorter or longer — **DEP-RECALL-FLOOR**) |
| Shipped platform default recall period (14 calendar days) | A hard minimum/floor that clamps portfolio overrides to ≥ 14 days |
| Portfolio recall-period override (**DEP-RECALL-FLOOR**; MAY be shorter or longer than 14 days) | The platform default when no override is configured |
| BUY cooldown start (recommendation opportunity) | Fill / trade approval / lending commitment |
| V1 trailing proxy (unrealized %) | V3 trailing (highest raw `close_price`, OD-14) |
| Available cash | Available-for-lending |
| Available cash / `available_physical_cash` | Named **Unallocated Cash** UI bucket (OD-20; presentation-only residual after reserve not claimed by unused-allocation) |
| Named **Unallocated Cash** (OD-20) | `required_cash_reserve` / pending-execution reserved cash / post-fill reservation leftover / strategy unused allocation / a physical cash account |
| Named **Unallocated Cash** (OD-20 presentation) | A new residual-cash formula, ledger, lending pool, or withdrawal entitlement (**OD-21** allows external withdraw even if cash falls below reserve; that is not an Unallocated Cash entitlement) |
| External/broker withdrawal that leaves cash below `required_cash_reserve` (OD-21; warning, not a hard block) | Permission to invest or lend reserve cash (still forbidden) / automatic halt of executions or recommendations solely due to the shortfall |
| Current V3 / B3 Dashboard reserve-shortfall warning | B4 wishlist persistent application-wide critical banner (design not frozen) |
| Available cash / total cash / portfolio NAV | OD-19 reserve base (`MAX(Invested Amount, Notional Portfolio Value)`) |
| Invested Amount (actual capital paid for currently held investments, OD-19) | Notional Portfolio Value (current market value of currently held investments, OD-19) |
| `required_cash_reserve` (OD-19 rupee floor) | OD-06 pending-execution reserved cash / `atomic_allocation` |
| `portfolio_cash_reserve_pct` (one portfolio-level percentage, OD-19) | A second percentage for notional vs invested, or V1 strategy `min_cash_reserve_pct` as a cash-% rule |
| OD-19 illustrative 20% examples / Factory Minervini migration seed | A frozen V3 numeric default for `portfolio_cash_reserve_pct` |
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
- History depth default: `HISTORY_DEPTH_TARGET_DAYS` = 550 (**as-built / V1 implementation value only**; **not** a V3 product requirement. OD-17: V3 has no equivalent maximum product-depth constant.)
