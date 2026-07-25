# Screener Specification

| Field | Value |
|-------|-------|
| **Document** | Screener Specification |
| **Version** | 1.0 |
| **Status** | Active (SD-030) |
| **Owner** | Architecture |

---

# 1. Purpose

A **Screener** is the application’s **sole eligibility engine**.

It selects stocks that satisfy a set of Conditions. It does **not** rank,
allocate capital, or generate recommendations.

``` text
Evaluation facts / OHLCV
        ↓
     Screener
        ↓
  Candidate stocks (hits)
```

Reusable by: Discovery, Strategies, Watchlists, Alerts, Daily Scan,
Backtesting (future), Automation (future).

---

# 2. Responsibilities

| Screener does | Screener does not |
|---------------|-------------------|
| Evaluate conditions | Rank stocks |
| Return eligible hits | Generate recommendations |
| Persist runs / hits | Allocate capital |
| Support schedules / backtests | Own Strategy scoring |

---

# 3. Condition model (V1)

V1 stores conditions as a **JSON definition tree** on
`portfolio_screeners.definition_json` (not normalized EAV tables).

``` text
root: group (AND|OR) | condition
condition: left operand  operator  (weight_factor × right operand)
```

Operands: indicators from `ScreenerCatalog` or constants.  
Operators: gt, gte, lt, lte, eq.  
Groups: nested AND/OR (depth/condition limits apply).

Editing conditions happens **only** in the Screener UI/module.

---

# 4. Factory screener example

**Minervini Trend Template** (`factory_key=minervini_trend_template`)

AND of:

- Close > SMA(150), Close > SMA(200)
- SMA(150) > SMA(200), Close > SMA(50), SMA(50) > SMA(150)
- Close ≥ 1.25 × 52-week low
- Close ≥ 0.75 × 52-week high

Code: `App\Engines\Strategy\MinerviniTrendTemplateScreener`.

---

# 5. Strategy consumption (SD-030)

Strategies **reference** Screeners by ID (`eligibility_sources` /
`portfolio_tos_strategy_screeners`). They never copy condition trees.

Recommendation Engine reads recent Screener run hits — it never
re-executes Screener condition logic.

---

# 6. APIs

Existing `/api/screeners*` (CRUD, run, continue, backtest, meta).  
Strategy assignment: `PUT /api/v1/strategy/screeners`,
`GET /api/v1/strategy/eligibility`.
