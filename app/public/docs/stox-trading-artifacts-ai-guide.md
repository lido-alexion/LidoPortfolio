# StoX Trading Artifacts - AI Authoring Guide

## 1. Introduction

> **Audience:** AI agents and developers authoring portable Indicator / Screener / Strategy JSON **without** reading application source code.
>
> **Generated:** 2026-08-09T17:28:56.191Z
> **Deploy download:** `/docs/stox-trading-artifacts-ai-guide.md` (also linked from Screener Registry and Strategy Registry).
> **Repo copy:** `specs/architecture/domains/StoX-Trading-Artifacts-AI-Guide.md`

This guide is the **single authoritative specification** for authoring production-ready Trading Artifacts.

| Section | Role |
|---------|------|
| **AI Authoring Contract** | Normative constitution (MUST / SHOULD). Read first. |
| Hard Rules | Compact reminder table derived from the Contract. |
| Authoring Workflow | Recommended Validate -> Import sequence. |
| Indicator / Screener / Strategy Registry | Detailed reference catalogues and schemas. |
| Trading Cookbook | Philosophy + paired JSON recipes. |
| Complete Examples | Canonical end-to-end lifecycle. |
| Appendix | Runtime semantics and other detailed behaviour. |

**Document maintenance:** New authoring or behavioural constraints MUST be added to the AI Authoring Contract before detailed reference sections.

HTML mirrors: `/docs/ai-authoring-contract.html`, `/docs/authoring-trading-artifacts.html`, `/docs/indicator-registry.html`, `/docs/screener-registry.html`, `/docs/strategy-registry.html`, `/docs/trading-cookbook.html`, `/docs/trading-artifact-runtime.html`.

---

## 2. AI Authoring Contract (Normative Rules)

This Contract is the **constitution** of Trading Artifact authoring.

Keywords follow RFC 2119: **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, **MAY**.

Detailed schemas, catalogues, runtime maths, and JSON samples live in later sections. Do not invent behaviour absent from this Contract and those references.

**Maintenance rule:** Any new authoring or behavioural constraint MUST be added here before it is documented elsewhere in this guide.

---

### General Principles

1. AI MUST generate portable Trading Artifacts (JSON envelopes) consumable by Validate / Import.
2. AI MUST rely only on functionality documented in this guide.
3. AI MUST NOT invent undocumented features, operators, indicator ids, parameter names, or optional sections.
4. AI MUST prefer canonical registry identifiers over aliases.
5. AI MUST NOT read or depend on application source code to author artifacts.
6. AI SHOULD minimise unnecessary configuration.
7. AI SHOULD produce human-readable `name` and `slug` values.
8. AI SHOULD treat later guide sections as reference manuals subordinate to this Contract.

### Artifact Envelope

9. Every artifact MUST include `schema_version`.
10. `schema_version` MUST equal `"1.0"` for artifacts authored against this guide.
11. Every artifact MUST include `artifact_type` matching the payload (`indicator` | `screener` | `strategy`).
12. Artifact body fields MUST use the portable key `definition` (not database column names such as `definition_json` or `config_json`).
13. Every artifact MUST include `slug`.
14. Every artifact MUST include `name`.
15. Every artifact MUST include `metadata` as an object (empty object allowed).
16. Unknown top-level envelope fields SHOULD NOT be introduced.
17. `minimum_engine_version` MAY be included; it is exported for documentation and is not currently enforced on Validate/Import. See Runtime Semantics.
18. AI MUST NOT place Screener condition trees or Strategy scoring rows outside `definition`.

### Indicator Rules

19. Screeners MUST use only screenable Primary indicator ids from the Indicator Registry.
20. Strategies MUST use only strategy-scorable Composite keys in `scoring_model`.
21. No indicator id MUST be treated as both screenable and strategy-scorable.
22. Indicator ids MUST exactly match the Indicator Registry (canonical spelling).
23. Parameter names MUST exactly match the Indicator Registry for that indicator.
24. Unknown indicator ids MUST NOT be invented.
25. Unknown parameter names MUST NOT be invented.
26. Parameter values that are present MUST be numeric where the registry requires numbers.
27. Parameter values that are present MUST lie within catalogue min/max for that parameter.
28. Out-of-range parameter values MUST NOT be authored (Validate rejects; values are not clamped).
29. Screener `params` keys MAY be omitted; omitted keys resolve via TechnicalIndicatorService fallbacks. See Runtime Semantics / Indicator Registry.
30. AI SHOULD set Screener `params` explicitly so runtime values match author intent (catalogue UI defaults may differ from runtime fallbacks).
31. AI SHOULD NOT invent Indicator Registry shipping calculators; Indicator create/update drafts do not mutate release calculators.

### Screener Rules

32. Every Screener MUST contain `definition.root`.
33. `root` MUST be a `group` or `condition` node.
34. Every Screener MUST contain at least one `condition` somewhere under `root`.
35. Group operators MUST be exactly `AND` or `OR`.
36. Condition operators MUST be exactly one of: `gt`, `gte`, `lt`, `lte`, `eq`.
37. Unsupported operators MUST NOT be used (`neq`, `NOT`, `crosses_above`, `crosses_below`, `between`, `outside`, `contains`, `in`, and similar).
38. Each of `left` and `right` MUST be either an indicator operand or a constant operand.
39. Constant operands MUST use `{ "type": "constant", "value": <number> }`.
40. Constant values MUST be numeric.
41. Boolean operands MUST NOT be used.
42. String operands MUST NOT be used.
43. Null and date operands MUST NOT be used.
44. Indicator operands MUST use `{ "indicator": "<id>", "params": { ... } }` shape (params object MAY be empty or omit keys per rules 29–30).
45. Nesting depth MUST NOT exceed 4.
46. Condition count MUST NOT exceed 40.
47. `eq` MUST be treated as float equality with documented epsilon; prefer `gte`/`lte` for thresholds. See Runtime Semantics.
48. AI SHOULD express “between” as `AND` of `gte` and `lte`.
49. AI SHOULD express “outside” as `OR` of `lt` and `gt`.
50. Screener `slug` MUST be unique within the target portfolio (Import may suffix on collision).

### Strategy Rules

51. Strategies MUST reference Screeners via `screener_slug` and/or `screener_factory_key` in `eligibility_sources`.
52. Strategies MUST NOT embed Screener trees (`definition.root`, nested condition groups) inside Strategy JSON. See Strategy Registry.
53. `definition.scoring_model` MUST be present (alias `indicators` is accepted and normalised).
54. `scoring_model` MUST contain at least one enabled row with `weight > 0`.
55. Enabled scoring weights MUST total exactly 100 (±0.01).
56. Only strategy-scorable Composite keys MAY appear as scoring `key` values.
57. Unknown scoring keys MUST NOT be used.
58. Scoring row `enabled: false` MUST be treated as excluded from weight totals and contributions. See Runtime Semantics.
59. When both `minimum` and `maximum` are set on a scoring row, the pass band MUST be interpreted as `minimum <= value <= maximum`.
60. Failing a scoring `minimum` or `maximum` MUST NOT reject the stock from Recommendations; contribution is zero and weight still dilutes overall. See Runtime Semantics.
61. Multiple `eligibility_sources` MUST be interpreted as UNION of candidates (not intersection).
62. `priority` on eligibility sources MUST be treated as sort order only.
63. Optional sections `thresholds`, `portfolio_rules`, `exit_strategy`, and `market_gates` MAY be included when documented in Strategy Registry.
64. AI MUST NOT invent undocumented Strategy definition sections or exit rule keys.
65. Exit rule keys MUST be chosen from the documented set (`ma_breakdown`, `rs_weakening`, `trend_weakening`, `score_exit`, `max_loss`, `atr_stop`, `trailing_stop`, `screener_exit`).
66. `exit_strategy.mode` MUST be `any` or `all` when present.
67. AI SHOULD Export an existing Strategy before large edits to preserve thresholds, exits, and gates.

### Runtime Behaviour Rules (Authoring-Relevant)

68. AI MUST NOT assume failing one scoring factor hard-rejects the candidate. See Runtime Semantics §1.
69. AI MUST NOT assume eligibility INTERSECTION when multiple Screeners are listed. See Runtime Semantics §3.
70. AI MUST NOT assume `minimum_overall_score` is a hard Recommendation pipeline gate. See Runtime Semantics §4.
71. AI SHOULD assume market gates demote OPEN/INCREASE only and still allow EXIT/REDUCE. See Runtime Semantics §6.
72. AI SHOULD assume insufficient cash can demote OPEN/INCREASE to WATCH. See Runtime Semantics §7.
73. AI MUST NOT embed exit logic as Screener trees inside Strategy; use documented exit rule keys or `screener_exit` references.

### Import Rules

74. JSON MUST be Validated successfully before Import.
75. Import MUST NOT be performed on invalid or unvalidated JSON.
76. AI SHOULD Validate after every material edit.
77. AI SHOULD Import Screener before Strategy when the Strategy references that Screener by slug.
78. Strategy Import MUST be treated as creating a draft until Select activates it.
79. AI SHOULD prefer Export → edit → Validate → Import over authoring large optional sections from scratch.
80. AI SHOULD expect Import normalisation (aliases, weight redistribution, slug/name collision handling). See Runtime Semantics §12.
81. AI MUST NOT rely on unknown top-level Strategy definition keys surviving Import.

### Validation Rules

82. Validation errors MUST be resolved before Import.
83. Invalid JSON MUST NOT be imported.
84. Validation MUST precede every Import attempt.
85. AI MUST treat Validate `ok` as the sole gate that enables Import in Registry UI.
86. Editing JSON after Validate MUST be assumed to clear validation state.

### Portability Rules

87. JSON MUST remain portable across portfolios.
88. JSON MUST NOT depend on local database primary keys for cross-portfolio portability.
89. `screener_slug` or `screener_factory_key` SHOULD be preferred over `screener_id` in authored Strategy eligibility.
90. AI SHOULD declare `dependencies` when the envelope format includes them.
91. AI MUST NOT hard-code portfolio-specific absolute paths or environment secrets into artifacts.

### AI Authoring Best Practices

92. AI SHOULD prefer canonical indicator and scoring ids.
93. AI SHOULD NOT invent registry ids.
94. AI SHOULD NOT invent operators.
95. AI SHOULD NOT invent optional Strategy sections beyond those documented.
96. AI SHOULD omit Screener parameters only when the intended runtime fallback is known and acceptable.
97. AI SHOULD preserve existing metadata when editing an Exported artifact.
98. AI SHOULD generate distinct, descriptive `name` strings.
99. AI SHOULD use Trading Cookbook recipes when matching a documented investing style.
100. AI SHOULD note Cookbook approximations (for example Value without PE, Darvas/CANSLIM proxies) in `metadata` or names when relevant.
101. AI SHOULD keep one atomic concern per Screener where practical.
102. AI SHOULD keep Strategy scoring rows aligned to Evaluation composites the engine actually emits.

### Pre-Generation Checklist

Before emitting JSON, AI MUST verify:

- [ ] `schema_version` present and equal to `"1.0"`
- [ ] `artifact_type` correct (`screener` or `strategy`)
- [ ] `slug`, `name`, `metadata`, and `definition` present
- [ ] Field name is `definition` (not DB column names)
- [ ] Indicator ids exist in Indicator Registry for the intended use (screenable vs scorable)
- [ ] Parameter names match the registry; present values within min/max
- [ ] Screener operators are only `gt`/`gte`/`lt`/`lte`/`eq` and groups only `AND`/`OR`
- [ ] Operands are indicator or numeric constant only
- [ ] Nesting depth ≤ 4 and condition count ≤ 40
- [ ] Strategy has no embedded Screener trees
- [ ] Eligibility uses `screener_slug` / `screener_factory_key`
- [ ] Enabled `scoring_model` weights total exactly 100
- [ ] Scoring keys are strategy-scorable only
- [ ] Optional sections/keys are documented only
- [ ] JSON is portable (no reliance on local DB ids)
- [ ] Validate → Import sequence planned (Strategy remains draft until Select)

---

## 3. Hard Rules

Compact reminder. The **AI Authoring Contract** is authoritative if anything conflicts.

| Topic | Requirement |
|-------|-------------|
| Schema | `schema_version` MUST be `"1.0"`; body key MUST be `definition`. |
| Operators | Condition: `gt` `gte` `lt` `lte` `eq` only. Group: `AND` `OR` only. |
| Forbidden | `neq`, `NOT`, `crosses_*`, `between`, `outside`, string/boolean/null/date operands. |
| Operands | Indicator `{ indicator, params }` or constant `{ type:"constant", value:<number> }`. |
| Limits | Nesting depth ≤ 4; ≤ 40 conditions. |
| Dual-use | Screenable ≠ strategy-scorable. |
| Eligibility | `screener_slug` / `screener_factory_key` only — MUST NOT embed Screener trees. |
| Weights | Enabled `scoring_model` weights MUST total exactly 100. |
| Import | Validate MUST succeed before Import; Strategy Import is draft until Select. |
| Params | Present params MUST be in catalogue range; AI SHOULD set Screener params explicitly. |
| Eligibility set | Multiple sources = UNION. |
| Scoring gates | Min/max fail → contribution 0 (dilutes); stock is not rejected. |

---

## 4. Authoring Workflow

**Keyword:** `authoring-trading-artifacts`
**Aliases:** `artifact-authoring`, `ai-authoring-guide`, `trading-artifact-authoring`

**Summary:** End-to-end workflow for humans and AI: Indicator → Screener → Strategy → Validate → Import → Select.

**UI / docs route label:** `/docs/authoring-trading-artifacts.html`

Use this page as the **recommended authoring order** when building portable Trading Artifact JSON without reading application source code.

## Recommended workflow

1. **Read Indicator Registry** — learn screenable Primaries vs strategy-scorable Composites; copy exact ids and param names.
2. **Build a Screener** — only **screenable** indicator ids; operators `gt`/`gte`/`lt`/`lte`/`eq`; groups `AND`/`OR` only; numeric constants only.
3. **Validate** the Screener in Screener Registry (Import stays disabled until Validate returns `ok`).
4. **Import** the Screener; note the final `slug` (may be suffixed on collision).
5. **Build a Strategy** — `eligibility_sources` reference that `screener_slug` / `screener_factory_key` (never embed `definition.root`).
6. **Scoring keys** — only strategy-scorable composites (`relative_strength`, `momentum_score`, …). Prefer canonical keys over aliases.
7. **Weights** — enabled `scoring_model` weights must total **exactly 100**.
8. **Validate** Strategy → **Import** (creates **draft**) → **Select** to activate for Recommendations.

## Normative rules

All MUST / SHOULD authoring rules live in the **[AI Authoring Contract](ai-authoring-contract.html)**. This workflow page does not restate them.

## Topic map

- [AI Authoring Contract](ai-authoring-contract.html) — normative constitution
- [Indicator Registry](indicator-registry.html) — catalogue
- [Screener Registry](screener-registry.html) — operators, operands, examples
- [Strategy Registry](strategy-registry.html) — scoring + optional sections
- [Trading Artifact Runtime Semantics](trading-artifact-runtime.html) — scoring formula, eligibility UNION, thresholds, exits, gates
- [Trading Cookbook](trading-cookbook.html) — full recipes (philosophy + both JSONs)


Practical tip: treat this page as one step in a larger workflow, not an isolated screen. Make one change at a time, verify the downstream effect, and use linked pages to complete the loop (for example: discovery → evaluation → recommendation → pending execution → review).

### Controls

- **Validate then Import** — Both registries disable Import until Validate succeeds; editing JSON clears validation.
- **Select Strategy** — Only one active Strategy per portfolio drives Recommendations.
- **Typical flow** — Open this page, verify active portfolio context in the header, perform one meaningful action, then confirm the reflected change in list/cards/history before leaving.
- **Validation and errors** — Form and API validations are shown as inline errors or toast messages. Fix the first reported issue, retry, and re-check dependent sections that consume the same data.


### Concepts

- **Portable artifacts** — JSON envelopes move between portfolios; Screeners and Strategies reference each other by slug/factory_key and indicator registry ids.
- **Contract-first** — Read the AI Authoring Contract before authoring; treat registry pages as reference.
- **Active portfolio context** — Most data on this page is scoped by the selected portfolio profile; switching profile can completely change visible rows and metrics.
- **Data freshness** — Many analytics depend on cached daily OHLCV and scheduled sync jobs. If numbers look stale, refresh this page and verify sync status in admin tools.


### Related topics

- `ai-authoring-contract`
- `indicator-registry`
- `screener-registry`
- `strategy-registry`
- `trading-artifact-runtime`
- `trading-cookbook`
- `trading-os-flow`

---

## 5. Indicator Registry

**Keyword:** `indicator-registry`
**Aliases:** `indicators`, `indicator-catalogue`, `liquidity-score`, `tradability-score`

**Summary:** Complete Indicator catalogue — definitions, parameters (defaults/min/max), screenable vs strategy-scorable, formulas, and how to use each id in Screeners or Strategy.

**UI / docs route label:** `/settings/indicators`

The Indicator Registry is the **metadata source of truth** for every calculator StoX exposes (SD-033). Use `/settings/indicators` to search and filter by category, type, and status. Open any row for description, parameters, consumers, capabilities, a dependency tree, and a formula explanation. **Formula text is documentation only** — there is no formula editor and release-shipped calculators are not mutated from the UI.

This page is written for humans and AI agents: every registry **id**, what it means, defaults, ranges, and where you can use it.

## How to use indicators

| Consumer | Which ids | How you reference them |
|----------|-----------|------------------------|
| **Screener conditions** | `screenable` Primaries (39) | Operand `{ "indicator": "<id>", "params": {…} }` — see Screener Registry |
| **Strategy scoring** | `strategy_scorable` Composites (8) | `scoring_model[].key` — see Strategy Registry |
| **Evaluation facts** | Strategy deps + RS primaries | Computed by Evaluation; feed Strategy scores |
| **Stock Details / Dashboard** | Metrics + Liquidity/Tradability | Display analytics (not Strategy weights) |
| **Admin Registry UI** | All 62 | Browse metadata only |

**Important:** No indicator is both screenable and strategy-scorable. Screeners use price/volume Primaries (`ema`, `rsi`, …). Strategy uses score Composites (`momentum_score`, `trend_score`, …) that **depend on** Primaries.

## Types, status, and capabilities

| Concept | Values / meaning |
|---------|------------------|
| **Type** | `primary` = OHLCV calculator; `composite` = combines dependencies into a score; `metric` = descriptive analytics field |
| **Status** | `active` = live; `stub` = placeholder (constant/neutral until model ships); `planned` / `deprecated` reserved |
| **screenable** | May appear on the left/right of a Screener condition |
| **strategy_scorable** | May appear as a Strategy `scoring_model` key with weight |
| **needs_volume** | Requires volume bars; fails/null if volume missing |
| **supports_maximum** | Strategy may apply a **maximum** gate (used by `risk_score`) |
| **Units** | `price`, `percent`, `ratio`, `count`, `currency`, `score_0_100`, `none` |

**Uniqueness:** each indicator `id` (also used as artifact `slug`) is globally unique in the registry. Aliases (legacy Strategy keys) resolve to a canonical id.

## Parameter conventions (Screenable Primaries)

Screener params are numeric with **default / min / max / step**. Period-like params usually allow **1–400** (RSI/ATR period max **200**; Stochastic `smooth` max **50**; MACD `signal` max **100**; Bollinger `mult` **0.5–5** step **0.1**).

Strategy Composite params are `{ type, label, default }` (often no min/max in metadata) and are UI-persisted on the Strategy; Evaluation may still use trading_os defaults for some inputs (TD-19).

---

## Catalogue A — Screenable Primaries (for Screener JSON)

Use these ids in Screener `left` / `right` operands. Params shown as `name=default (min–max[, step])`.

### Price

| Id | Name | Meaning | Units | Params |
|----|------|---------|-------|--------|
| `close` | Close | Last traded / session closing price | price | — |
| `open` | Open | Session opening price | price | — |
| `high` | High | Session high | price | — |
| `low` | Low | Session low | price | — |
| `change_pct` | % Change | Percent change vs close `period` bars ago | percent | `period=1 (1–400)` |
| `high_n` | Highest high (N) | Highest high over N bars | price | `period=20 (1–400)` |
| `low_n` | Lowest low (N) | Lowest low over N bars | price | `period=20 (1–400)` |
| `high_52w` | 52-week high | Highest high over ~252 trading days | price | — |
| `low_52w` | 52-week low | Lowest low over ~252 trading days | price | — |
| `range_pct` | Range % (H-L)/C | Intraday range as % of close: `(high−low)/close×100` | percent | — |

### Trend

| Id | Name | Meaning | Units | Params |
|----|------|---------|-------|--------|
| `sma` | SMA | **Simple Moving Average** — arithmetic mean of close over `period` bars. Smooths price; slower than EMA. | price | `period=20 (1–400)` |
| `ema` | EMA | **Exponential Moving Average** — weighted average of close that reacts faster to recent prices than SMA. Common trend line (e.g. 50-day). | price | `period=50 (1–400)` |
| `price_vs_sma_pct` | Price vs SMA % | `(close − SMA) / SMA × 100` — how far price is above/below its SMA | percent | `period=20 (1–400)` |
| `price_vs_ema_pct` | Price vs EMA % | Same idea vs EMA | percent | `period=50 (1–400)` |
| `sma_spread_pct` | SMA spread % | Percent spread between fast and slow SMAs | percent | `fast=20`, `slow=50` (both 1–400) |
| `ema_spread_pct` | EMA spread % | Percent spread between fast and slow EMAs | percent | `fast=12`, `slow=26` (both 1–400) |

**Example Screener condition (close above 50-EMA):**

```json
{
  "type": "condition",
  "left": { "indicator": "close", "params": {} },
  "operator": "gt",
  "weight_factor": 1,
  "right": { "indicator": "ema", "params": { "period": 50 } }
}
```

### Momentum

| Id | Name | Meaning | Units | Params |
|----|------|---------|-------|--------|
| `rsi` | RSI | **Relative Strength Index** (Wilder) — momentum oscillator typically 0–100. Often >70 overbought, <30 oversold (heuristic). | percent | `period=14 (1–200)` |
| `roc` | ROC % | **Rate of Change** — percent change of close over `period` | percent | `period=12 (1–400)` |
| `stoch_k` | Stochastic %K | Close location in the high–low range over `period` (0–100 style) | percent | `period=14 (1–400)` |
| `stoch_d` | Stochastic %D | Smoothed %K | percent | `period=14 (1–400)`, `smooth=3 (1–50)` |
| `macd` | MACD line | **Moving Average Convergence Divergence** — EMA(fast) − EMA(slow) | price | `fast=12`, `slow=26` (1–400) |
| `macd_signal` | MACD signal | EMA of the MACD line | price | + `signal=9 (1–100)` |
| `macd_hist` | MACD histogram | MACD − signal (momentum of the MACD) | price | same as signal |

### Volatility

| Id | Name | Meaning | Units | Params |
|----|------|---------|-------|--------|
| `atr` | ATR | **Average True Range** — average of true range; volatility in price units | price | `period=14 (1–200)` |
| `bb_mid` | Bollinger mid | Middle Bollinger Band = SMA(`period`) | price | `period=20 (1–400)` |
| `bb_upper` | Bollinger upper | Mid + `mult` × stdev | price | `period=20`, `mult=2 (0.5–5, step 0.1)` |
| `bb_lower` | Bollinger lower | Mid − `mult` × stdev | price | same |
| `bb_pct_b` | Bollinger %B | Where close sits in the band (0 at lower, 1 at upper) | ratio | same |
| `bb_width_pct` | Bollinger width % | Band width as % of mid | percent | same |

### Volume (needs volume)

| Id | Name | Meaning | Units | Params |
|----|------|---------|-------|--------|
| `volume` | Volume | Session share volume | count | — |
| `volume_sma` | Volume SMA | SMA of volume | count | `period=20 (1–400)` |
| `volume_ratio` | Volume / Vol SMA | `volume / volume_sma` — >1 = above average activity | ratio | `period=20 (1–400)` |
| `average_volume` | Average Daily Volume | Mean share volume over N (same math as Volume SMA) | count | `period=20 (1–400)` |

### Liquidity (needs volume)

| Id | Name | Meaning | Units | Params |
|----|------|---------|-------|--------|
| `average_turnover` | Average Daily Turnover | SMA of `close × volume` (typical daily traded value) | currency | `period=20 (1–400)` |
| `relative_turnover` | Relative Turnover | Short ADT / longer baseline ADT; ~1.0 = in-line with own baseline | ratio | `period=20`, `baseline=60` (1–400) |

### Tradability / Risk heuristics

| Id | Name | Meaning | Units | Params |
|----|------|---------|-------|--------|
| `gap_frequency` | Gap Frequency | Rate of opening gaps vs prior close | ratio | `period=60`, `threshold_pct=1 (0.1–20, step 0.1)` |
| `gap_fill_ratio` | Gap Fill Ratio | Fraction of gaps that fill within `fill_window` | ratio | + `fill_window=5 (1–40)` |
| `circuit_frequency` | Circuit Frequency | Heuristic rate of circuit-like sessions (**not** exchange circuit feed) | ratio | `period=60`, `move_pct=9.5 (1–25)`, `range_pct=0.5 (0.05–5)` |
| `circuit_risk` | Circuit Risk | 0–100 severity from frequency + move size | score_0_100 | same move/range params |

---

## Catalogue B — Relative Strength Primaries (Evaluation inputs)

Not screenable. Used as Evaluation / analytics inputs (especially `relative_strength_3m` → Strategy `relative_strength`).

| Id | Name | Meaning | Units |
|----|------|---------|-------|
| `relative_strength_1m` | Relative Strength (1m) | Stock vs benchmark return ratio ~1 month | ratio |
| `relative_strength_3m` | Relative Strength (3m) | ~3 months — default Evaluation input for RS score | ratio |
| `relative_strength_6m` | Relative Strength (6m) | ~6 months | ratio |

---

## Catalogue C — Strategy-scorable Composites (for Strategy JSON)

Use these **keys** in `definition.scoring_model`. Values are **0–100** scores. Enabled weights must sum to **100**. Defaults below are Strategy UI defaults (weight / minimum / maximum). Split into two tables on the shared **Key** for print-friendly width.

### C1 — Identity and Strategy defaults

| Key | Aliases | Name | Meaning | Wt | Min | Max |
|-----|---------|------|---------|----|-----|-----|
| `relative_strength` | — | Relative Strength | Strength vs benchmark (long-leaning) | 35 | 80 | — |
| `momentum_score` | `momentum` | Momentum Score | RSI-based momentum strength | 15 | 70 | — |
| `trend_score` | `trend` | Trend Score | Price vs SMA stack | 20 | 70 | — |
| `breakout_score` | `pattern_bonus` | Breakout Score | Pattern/breakout evidence from Discovery | 10 | 75 | — |
| `volume_score` | `volume` | Volume Score | Volume vs recent average | 8 | 60 | — |
| `market_regime` | — | Market Regime | Broad market regime (**stub**) | 5 | 60 | — |
| `sector_strength` | — | Sector Strength | Sector RS (**stub**) | 4 | 60 | — |
| `risk_score` | `risk` | Risk Score | ATR-based risk; **higher = riskier** | 3 | 0 | **40** |

### C2 — Params, dependencies, and formula (same Key)

| Key | Params | Depends on | Formula (summary) |
|-----|--------|------------|-------------------|
| `relative_strength` | `lookback_days=90`; `benchmark=NIFTY50` | `relative_strength_3m` | RS3m ≥1.05→100; ≥1.0→70; else 30 |
| `momentum_score` | `rsi_period=14` | `rsi` | RSI in [45,70]→100; >70→55; <30→35; else 50 |
| `trend_score` | `sma_fast=20`; `sma_slow=50` | `close`, `sma` | close>fast>slow→100; close>fast→60; else 20 |
| `breakout_score` | — | `discovery_pattern_count` | min(100, 40+20×count); 0 if none |
| `volume_score` | `volume_sma_period=20` | `volume_ratio` | ≥1.2→100; ≥0.8→60; else 30 |
| `market_regime` | — | — | Constant **50** until model ships |
| `sector_strength` | — | — | Constant **50** until model ships |
| `risk_score` | `atr_period=14` | `atr`, `close` | clamp((atr/close×100)×10, 0, 100); supports **maximum** gate |

**Example Strategy scoring rows (weights = 100):**

```json
"scoring_model": [
  {
    "key": "relative_strength",
    "enabled": true,
    "weight": 50,
    "minimum": 70,
    "maximum": null,
    "parameters": {}
  },
  {
    "key": "momentum_score",
    "enabled": true,
    "weight": 50,
    "minimum": 60,
    "maximum": null,
    "parameters": {
      "rsi_period": 14
    }
  }
]
```

---

## Catalogue D — Liquidity / Tradability Composites

Active for Discovery / Dashboard / Stock Details / Screener consumers. **Not** strategy-scorable and **not** wired into Recommendation scoring.

### D1 — Identity

| Id | Name | Meaning | Units |
|----|------|---------|-------|
| `liquidity_score` | Liquidity Score | 0–100 liquidity quality | score_0_100 |
| `tradability_score` | Tradability Score | 0–100 ease of trading (higher = easier) | score_0_100 |

### D2 — Dependencies and formula (same Id)

| Id | Depends on | Formula (summary) |
|----|------------|-------------------|
| `liquidity_score` | `relative_turnover`, `average_turnover`, `average_volume` | Map RT/turnover/volume → 0–100; mean of available |
| `tradability_score` | gap + circuit primaries | Invert freqs / use fill; mean of available |

---

## Catalogue E — Discovery + Stock Analytics Metrics

| Id | Name | Meaning | Units |
|----|------|---------|-------|
| `discovery_pattern_count` | Discovery Pattern Count | Count of matched patterns on Discovery evidence (not a TI series) | count |
| `distance_52w_high_pct` | Distance from 52-week High % | How far latest close is below/above 52w high | percent |
| `distance_52w_low_pct` | Distance from 52-week Low % | Distance from 52w low | percent |
| `historical_volatility_pct` | Historical Volatility % | Annualised log-return volatility proxy | percent |
| `beta` | Beta (proxy) | Soft vol proxy — **not** formal regression beta | ratio |
| `trend_strength` | Trend Strength | Heuristic 0–100 from close vs SMA50/200 — **≠** Strategy `trend_score` | score_0_100 |
| `maximum_drawdown_pct` | Maximum Drawdown % | Peak-to-trough over loaded history | percent |
| `current_drawdown_pct` | Current Drawdown % | Peak to latest close | percent |
| `average_daily_volume_metric` | Average Daily Volume (analytics) | Descriptive ADV — distinct from Primary `average_volume` | count |
| `liquidity_rating` | Liquidity Rating | High / Medium / Low / Unknown from notional ADV | none |

---

## Quick definitions glossary (common acronyms)

| Term | Plain meaning |
|------|---------------|
| **SMA** | Simple Moving Average — equal-weight average of recent closes |
| **EMA** | Exponential Moving Average — recent closes weigh more; reacts faster than SMA |
| **RSI** | Relative Strength Index — 0–100 momentum oscillator from average gains vs losses |
| **MACD** | Moving Average Convergence Divergence — difference of two EMAs (+ signal + histogram) |
| **ATR** | Average True Range — typical bar range including gaps; volatility in price units |
| **Bollinger Bands** | SMA ± (multiplier × standard deviation); width expands/contracts with volatility |
| **ROC** | Rate of Change — percent price change over N bars |
| **Stochastic** | Where close sits in the recent high–low range (%K / smoothed %D) |
| **Relative Strength (RS)** | Here: stock performance vs a benchmark (not the RSI oscillator) |

## Artifact envelope (optional packaging)

Indicators can be exported as Trading Artifact envelopes (`artifact_type: "indicator"`) for packages. Release calculators stay immutable; create/update only drafts. Envelope needs `schema_version`, `artifact_type`, `slug` (= registry id), `name`, `metadata`, and `definition` with `registry_id` / `indicator_kind` (`primary` / `composite` / `metric`). No executable `code` / `script` / `formula` fields allowed.

## Recommended reading order for builders

1. Pick consumer: Screener condition vs Strategy weight.
2. Copy the correct id/key from the tables above (never invent ids).
3. Set params within min/max (or omit to use defaults).
4. For Strategy, ensure enabled weights sum to 100; for Screeners, build a valid `definition.root` tree.
5. Open Registry detail for dependency tree + full formula prose when unsure.

## Runtime notes (defaults vs catalogue)

Catalogue **default** values are editor/documentation defaults. If Screener JSON omits a param, `TechnicalIndicatorService` applies its own fallback (commonly `period ?? 20` for SMA/EMA and many others) - **not always** the catalogue default. See **Trading Artifact Runtime Semantics**. AI authors should always emit explicit `params`.

## Strategy parameter examples (complete rows)

When authoring Strategy `scoring_model` rows, put Composite params under `parameters`:

```json
{
  "key": "relative_strength",
  "enabled": true,
  "weight": 35,
  "minimum": 80,
  "maximum": null,
  "parameters": {
    "lookback_days": 90,
    "benchmark": "NIFTY50"
  }
}
```

```json
{
  "key": "trend_score",
  "enabled": true,
  "weight": 20,
  "minimum": 70,
  "maximum": null,
  "parameters": {
    "sma_fast": 20,
    "sma_slow": 50
  }
}
```

## Parameter naming convention

Use these **exact** param ids (do not invent synonyms like `length`, `window`, or `ma_period` unless listed):

| Family | Param ids | Used by (examples) |
|--------|-----------|--------------------|
| Periods | `period` | Most Primaries: `sma`, `ema`, `rsi`, `atr`, `volume_sma`, … |
| Dual MA | `fast`, `slow` | `sma_spread_pct`, `ema_spread_pct`, `macd*` |
| MACD signal | `signal` | `macd_signal`, `macd_hist` |
| Stochastic smooth | `smooth` | `stoch_d` |
| Band multiplier | `mult` | Bollinger `bb_*` |
| Windows | `lookback_days`, `fill_window`, `baseline` | Strategy RS; gap fill; relative turnover |
| Gap / circuit | `threshold_pct`, `move_pct`, `range_pct` | gap_* / circuit_* |
| Strategy composites | `rsi_period`, `sma_fast`, `sma_slow`, `volume_sma_period`, `atr_period`, `benchmark` | scoring_model parameters |

Screener operand params use Primary names (`period`, `fast`, `slow`, …). Strategy Composite params use the Composite’s own keys (`rsi_period`, not `period`, on `momentum_score`).



Practical tip: treat this page as one step in a larger workflow, not an isolated screen. Make one change at a time, verify the downstream effect, and use linked pages to complete the loop (for example: discovery → evaluation → recommendation → pending execution → review).

### Controls

- **Search** — Match against indicator id, display name, or description.
- **Category / Type / Status filters** — Narrow the catalogue (e.g. momentum primaries, stub composites, liquidity).
- **Indicator row** — Open the detail page for full metadata, parameters, consumers, and capabilities.
- **Dependency tree** — On detail, expand declared depends_on relationships recursively (e.g. momentum_score → rsi).
- **Formula explanation** — Read-only prose describing how the indicator is computed; not editable in the UI.
- **Catalogue guide** — This documentation topic — full id list, meanings, defaults, ranges, and Screener vs Strategy usage.
- **Typical flow** — Open this page, verify active portfolio context in the header, perform one meaningful action, then confirm the reflected change in list/cards/history before leaving.
- **Validation and errors** — Form and API validations are shown as inline errors or toast messages. Fix the first reported issue, retry, and re-check dependent sections that consume the same data.


### Concepts

- **Primary / Composite / Metric** — Primaries are OHLCV calculators (often screenable). Composites combine dependencies into scores (Strategy or Liquidity/Tradability). Metrics are descriptive Stock Analytics / Discovery fields.
- **Screenable vs strategy-scorable** — Screenable Primaries appear in Screener conditions. Strategy-scorable Composites appear in Strategy weights. No id is both. Liquidity/Tradability composites are intentionally not strategy-scorable.
- **EMA / SMA / RSI (plain language)** — SMA = equal-weight average of closes. EMA = exponential average (faster). RSI = 0–100 momentum from average gains vs losses. Relative Strength (Strategy) is stock vs benchmark — not the RSI oscillator.
- **Id uniqueness and aliases** — Registry id is unique. Strategy aliases: momentum→momentum_score, trend→trend_score, pattern_bonus→breakout_score, volume→volume_score, risk→risk_score. Prefer canonical keys in new JSON.
- **Parameter ranges** — Screener Primary params declare default/min/max/step (periods usually 1–400). Strategy Composite params declare type/label/default. Stay inside ranges — Validate/Import rejects out-of-range values (no silent clamp). Omitted Screener params use TechnicalIndicatorService fallbacks, which may differ from catalogue UI defaults.
- **Parameter naming convention** — Use catalogue param ids exactly: period/fast/slow/signal/mult for Primaries; lookback_days/rsi_period/sma_fast/benchmark etc. for Strategy Composites. Do not invent synonyms.
- **Circuit heuristics** — Circuit Frequency / Risk use OHLCV heuristics (large move + locked range), not official exchange circuit feeds.
- **Immutable calculators** — Shipping calculators are release-owned. Registry UI is read-only documentation; artifact drafts do not rewrite TechnicalIndicatorService math.
- **Active portfolio context** — Most data on this page is scoped by the selected portfolio profile; switching profile can completely change visible rows and metrics.
- **Data freshness** — Many analytics depend on cached daily OHLCV and scheduled sync jobs. If numbers look stale, refresh this page and verify sync status in admin tools.


### Related topics

- `settings`
- `screener`
- `screener-registry`
- `strategy`
- `strategy-registry`
- `authoring-trading-artifacts`
- `trading-artifact-runtime`
- `trading-cookbook`
- `data-quality-center`

---

## 6. Screener Registry

**Keyword:** `screener-registry`
**Aliases:** `screener-artifacts`, `screener-json`, `import-screener`

**Summary:** Import/export Screener JSON artifacts — mandatory fields, slug rules, condition tree shape, and version history.

**UI / docs route label:** `/screeners/registry`

The Screener Registry turns portfolio screeners into reusable Trading Artifacts. Each screener still uses the same condition tree the run engine executes. The registry adds slug, metadata, artifact_version, definition_hash, and version history.

Export downloads the Trading Artifact JSON envelope. **Validate** checks the envelope. **Import** stays disabled until validation succeeds, then creates a new screener in the active portfolio. Shared screeners from **your other portfolios** (same account) appear read-only and can be copied with Import copy — not visible to other users.

## Importing JSON — start here

If Validate or Import reports many field errors, you almost always missed a **mandatory** envelope field or built an empty/invalid `definition.root` tree. Use the minimum schema below, then expand.

### Minimum valid envelope (copy/paste starting point)

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "my_first_screener",
  "name": "My First Screener",
  "metadata": {
    "universe": "holdings",
    "description": "Close above 10 (example)"
  },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 10 }
        }
      ]
    }
  }
}
```

That JSON has every runtime-required field and at least one condition (Import will reject an empty tree even if Validate is lenient on empty groups).

### Mandatory vs optional fields

| Field | Required? | What to put |
|-------|-----------|-------------|
| `schema_version` | **Yes** | Always `"1.0"` |
| `artifact_type` | **Yes** | Always `"screener"` |
| `slug` | **Yes** | Stable id: lowercase letters, numbers, underscores only (e.g. `high_liquidity`). See **Slug** below |
| `name` | **Yes** | Human label shown in the UI (max 120 characters) |
| `metadata` | **Yes** | An object — may be `{}`, but prefer at least `universe` / `description` |
| `definition` | **Yes** | Object with required `root` condition tree |
| `definition.root` | **Yes** | A `group` or `condition` node; Import needs ≥1 condition |
| `artifact_id` | Optional | Leave out on create; export may include a local id |
| `artifact_version` | Optional | Integer ≥ 1; Import starts versions at 1 anyway |
| `definition_hash` | Optional | Recalculated on Import — do not invent this |
| `minimum_engine_version` | Optional | e.g. `"1.1.0"` |
| `dependencies` | Optional | Array of refs; export fills this |
| `validation` | Optional | Embedded hints; not executed as rules |

Note: the database column is `definition_json`, but the **import JSON field name is `definition`** (not `definition_json`).

### What each field means

**`schema_version`** — Which envelope contract this file uses. Must be `"1.0"`. Wrong or missing → `SCHEMA_VERSION_UNSUPPORTED` / empty schema errors.

**`artifact_type`** — Discriminator so the registry knows this is a Screener (not Strategy/Indicator). Must be `"screener"`.

**`slug`** — Machine-stable key for this screener inside a portfolio. Strategies and other artifacts can refer to a screener by slug. Use snake_case like `breakout_volume` or `minervini_trend_template`. Allowed characters after normalisation: `a-z`, `0-9`, `_` (spaces and punctuation become `_`). Keep it short, unique, and meaningful — do **not** put a sentence here; that belongs in `name` / `metadata.description`. If the slug already exists on Import, the system may suffix it (e.g. `_import_ab12`) so the import still succeeds.

**`name`** — Display title in lists and the editor. Example: `"Breakout with volume"`. If the name collides, Import may append `" (import)"`.

**`metadata`** — Descriptive object. Common keys:
- `universe` — maps to screener scope: `holdings` | `watchlist` | `all_equities` | `index` (aliases like `portfolio` → `holdings` are accepted)
- `description` / `intent` / `summary` — human prose (description/intent capped ~500 chars on save)
- `tags` — array of strings
- `status` — lifecycle hint: `draft` | `active` | `deprecated` | `archived`
- `origin` — provenance: `factory` | `user` | `imported` | `ai_assisted` | `fork` | `exported`
- `factory_key` — stable factory id when shipping a built-in (e.g. `high_liquidity`)

**`definition`** — The condition tree the Screener engine runs. Shape:

```json
{
  "root": {
    "type": "group",
    "op": "AND",
    "children": [ /* conditions or nested groups */ ]
  }
}
```

**Group node:** `type: "group"`, `op: "AND"` or `"OR"`, `children`: non-empty array on Import.

**Condition node:**
```json
{
  "type": "condition",
  "left": { "indicator": "close", "params": {} },
  "operator": "gt",
  "weight_factor": 1.0,
  "right": { "indicator": "sma", "params": { "period": 50 } }
}
```
- `operator`: `gt` | `gte` | `lt` | `lte` | `eq`
- `left` / `right`: either `{ "indicator": "<id>", "params": {…} }` or `{ "type": "constant", "value": <number> }`
- Indicator ids must be screenable catalogue ids (e.g. `close`, `sma`, `rsi`, `volume`) — unknown ids fail validation
- Optional `weight_factor` multiplies the right side (default `1`)
- Nesting depth max **4**; max **40** conditions

**`artifact_version` / `definition_hash`** — Versioning metadata. Export includes them; Import recomputes the hash and starts history for the new local copy.

### Recommended import workflow

1. Start from the minimum example above (or Export an existing working screener and edit a copy).
2. Paste into Registry → **Validate** and fix every listed path (`$.slug`, `$.definition.root`, …).
3. Use **Import** (enabled only after Validate succeeds).
4. Open the classic Screener editor to refine conditions visually if needed.

### Common validation / import errors

| Message / code | Likely cause |
|----------------|--------------|
| `slug is required` | Missing or blank `slug` |
| `name is required` | Missing or blank `name` |
| `definition object is required` | Used `definition_json` instead of `definition`, or omitted it |
| `definition_json.root is required` / root errors | Missing `definition.root` |
| `Screener needs at least one condition` | Empty `children` array |
| `Group op must be AND or OR` | Typo in `op` |
| `Invalid condition operator` | Use `gt`/`gte`/`lt`/`lte`/`eq` only |
| `Unknown indicator…` | Indicator id not in the Screener catalogue |
| Nesting / too many conditions | Depth > 4 or > 40 conditions |
| Slug already exists | Pick another slug or let Import rename |

## Runtime notes (params, missing data, eq)

See **Trading Artifact Runtime Semantics** (`trading-artifact-runtime`) for full behaviour. Short form:

- **Always set `params` explicitly** - omitted keys use TechnicalIndicatorService fallbacks (often `period: 20`), which may differ from Indicator Registry UI defaults (e.g. EMA catalogue default 50).
- Out-of-range params on Validate/Import -> **reject** (not clamp).
- Insufficient bars / missing volume (when required) -> stock **skipped**; null indicator on a leaf -> condition **false**.
- `eq` uses float epsilon (~1e-4 abs / 1e-6 relative).

## Complete operator catalogue

Condition `operator` values are an **exact enum**. Anything else fails Validate / Import.

### Comparison operators (condition nodes)

| Operator | Meaning | Example |
|----------|---------|--------|
| `gt` | left > (weight × right) | close above SMA |
| `gte` | left ≥ (weight × right) | close at or above 52w high × 0.75 |
| `lt` | left < (weight × right) | RSI below 30 |
| `lte` | left ≤ (weight × right) | volume ratio ≤ 0.8 |
| `eq` | left ≈ right (float equality) | rare; prefer inequalities |

### Logical operators (group nodes only)

| Operator | Where | Meaning |
|----------|-------|--------|
| `AND` | `group.op` | Every child must pass |
| `OR` | `group.op` | Any child may pass |

### Explicitly NOT supported

Do **not** invent these — they are **not** in the Screener catalogue or validators:

- Comparison: `neq`, `ne`, `!=`, `crosses_above`, `crosses_below`, `between`, `outside`, `contains`, `in`, `starts_with`, `ends_with`
- Logical: `NOT`, `NAND`, `XOR`, unary negation nodes
- Node types other than `group` and `condition`

Workarounds: express “not equal” as two OR’d inequalities when needed; express “between” as an AND of `gte` and `lte`; express “outside” as an OR of `lt` and `gt`.

## Operand types

Each of `left` and `right` must be **exactly one** of the shapes below.

### Indicator operand

```json
{
  "indicator": "ema",
  "params": { "period": 20 }
}
```

- `indicator` — required; must be a **screenable** Primary id from Indicator Registry
- `params` — object; **set keys explicitly** (recommended). Omitted keys use TechnicalIndicatorService fallbacks (often `period: 20`), which may differ from Indicator Registry UI catalogue defaults. Present values must be numeric within min/max
- `entity` — **left only**, optional. Default is the scanned stock (`stock` / omit). Allowed index entities: `NIFTY50`, `SENSEX`, `NIFTY100`, `NIFTY200`, `NIFTY500`, `NIFTYMIDCAP150`, `NIFTYSMLCAP250`. Right-hand side is always evaluated on the scanned stock (no `entity` on right).

### Constant operand

```json
{
  "type": "constant",
  "value": 100
}
```

- `type` must be the string `"constant"`
- `value` must be **numeric** (integer or float)

### Explicitly NOT supported as operands

| Type | Supported? |
|------|------------|
| number (via constant) | **Yes** |
| boolean | **No** |
| string | **No** |
| null | **No** |
| date / datetime | **No** |
| arrays / objects as value | **No** |

Optional on the condition: `weight_factor` (number, default `1`) multiplies the **right** side before compare (used by Minervini for “25% above low” as `close >= 1.25 × low_52w`).

## Complete example screeners

Each example is a full Trading Artifact envelope you can Validate → Import. Pick unique `slug` values in your portfolio. See also **Trading Cookbook** for paired Strategy recipes.

### Example 1 — Moving Average Breakout

Philosophy: price clears the 50-SMA with the 20-SMA stacked above it.

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "ma_breakout",
  "name": "Moving Average Breakout",
  "metadata": {
    "universe": "all_equities",
    "description": "Close above SMA50 with SMA20 > SMA50"
  },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "indicator": "sma", "params": { "period": 50 } }
        },
        {
          "type": "condition",
          "left": { "indicator": "sma_spread_pct", "params": { "fast": 20, "slow": 50 } },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 0 }
        }
      ]
    }
  }
}
```

### Example 2 — RSI Pullback

Philosophy: uptrend (close > SMA50) with RSI cooled into a buy zone.

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "rsi_pullback",
  "name": "RSI Pullback",
  "metadata": {
    "universe": "all_equities",
    "description": "Close above SMA50 and RSI between 40 and 55"
  },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "indicator": "sma", "params": { "period": 50 } }
        },
        {
          "type": "condition",
          "left": { "indicator": "rsi", "params": { "period": 14 } },
          "operator": "gte",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 40 }
        },
        {
          "type": "condition",
          "left": { "indicator": "rsi", "params": { "period": 14 } },
          "operator": "lte",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 55 }
        }
      ]
    }
  }
}
```

### Example 3 — Bollinger Squeeze (narrow band + near mid)

Philosophy: low band width with price near the middle band (compression before expansion).

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "bollinger_squeeze",
  "name": "Bollinger Squeeze",
  "metadata": {
    "universe": "all_equities",
    "description": "BB width % low and close near mid band"
  },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "bb_width_pct", "params": { "period": 20, "mult": 2 } },
          "operator": "lt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 8 }
        },
        {
          "type": "condition",
          "left": { "indicator": "bb_pct_b", "params": { "period": 20, "mult": 2 } },
          "operator": "gte",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 0.35 }
        },
        {
          "type": "condition",
          "left": { "indicator": "bb_pct_b", "params": { "period": 20, "mult": 2 } },
          "operator": "lte",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 0.65 }
        }
      ]
    }
  }
}
```

### Example 4 — High Volume Breakout

Philosophy: new N-day high with volume expansion.

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "high_volume_breakout",
  "name": "High Volume Breakout",
  "metadata": {
    "universe": "all_equities",
    "description": "Close at 20-day high with volume_ratio > 1.5"
  },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gte",
          "weight_factor": 1,
          "right": { "indicator": "high_n", "params": { "period": 20 } }
        },
        {
          "type": "condition",
          "left": { "indicator": "volume_ratio", "params": { "period": 20 } },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 1.5 }
        }
      ]
    }
  }
}
```

### Example 5 — Minervini Trend Template (factory)

Matches the shipped factory screener `minervini_trend_template` (Stage-2 trend template approximation). Importing this creates a **user copy**; the factory key remains on the seeded screener.

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "minervini_trend_template_copy",
  "name": "Minervini Trend Template",
  "metadata": {
    "universe": "all_equities",
    "description": "Price above SMA 50/150/200 stack; ≥25% above 52w low; within 25% of 52w high"
  },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "indicator": "sma", "params": { "period": 150 } }
        },
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "indicator": "sma", "params": { "period": 200 } }
        },
        {
          "type": "condition",
          "left": { "indicator": "sma_spread_pct", "params": { "fast": 150, "slow": 200 } },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 0 }
        },
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "indicator": "sma", "params": { "period": 50 } }
        },
        {
          "type": "condition",
          "left": { "indicator": "sma_spread_pct", "params": { "fast": 50, "slow": 150 } },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 0 }
        },
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gte",
          "weight_factor": 1.25,
          "right": { "indicator": "low_52w", "params": {} }
        },
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gte",
          "weight_factor": 0.75,
          "right": { "indicator": "high_52w", "params": {} }
        }
      ]
    }
  }
}
```

### Example 6 — Darvas Box (approximation)

Philosophy: close breaks the recent N-day high (box top) with supportive volume. StoX has no dedicated Darvas box indicator — this is a high_n breakout proxy.

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "darvas_box_proxy",
  "name": "Darvas Box Proxy",
  "metadata": {
    "universe": "all_equities",
    "description": "Close breaks 55-day high with volume_ratio > 1.2"
  },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "close", "params": {} },
          "operator": "gte",
          "weight_factor": 1,
          "right": { "indicator": "high_n", "params": { "period": 55 } }
        },
        {
          "type": "condition",
          "left": { "indicator": "volume_ratio", "params": { "period": 20 } },
          "operator": "gt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 1.2 }
        }
      ]
    }
  }
}
```



Practical tip: treat this page as one step in a larger workflow, not an isolated screen. Make one change at a time, verify the downstream effect, and use linked pages to complete the loop (for example: discovery → evaluation → recommendation → pending execution → review).

### Controls

- **Search / filters** — Filter by status, ownership (own vs shared), and origin (factory / user / shared).
- **Export JSON** — Download the Screener artifact envelope (schema_version, slug, name, metadata, definition.root, dependencies). Best template for a new import.
- **Validate** — Check pasted JSON against Trading Artifact Screener rules. On success, a green “Validated successfully” cue appears above Validate/Import and the JSON result panel still shows details. Import stays disabled until this reports ok. Editing the JSON clears validation.
- **Import** — Enabled only after successful Validate. Creates a new screener in this portfolio and shows a success toast (not an inline alert). Mandatory: schema_version, artifact_type, slug, name, metadata, definition.root with ≥1 condition.
- **Download AI authoring guide (.md)** — Download /docs/stox-trading-artifacts-ai-guide.md — consolidated Indicator + Screener + Strategy + Authoring + Cookbook Markdown for AI agents and offline authoring.
- **Import copy (shared)** — Copy a screener shared from one of your other portfolios into the active portfolio (same as Shared screens import).
- **Open editor** — Jump to the classic Screener editor to change conditions or run screens after import.
- **Version history** — On detail for owned screeners, list definition snapshots and change notes.
- **Typical flow** — Open this page, verify active portfolio context in the header, perform one meaningful action, then confirm the reflected change in list/cards/history before leaving.
- **Validation and errors** — Form and API validations are shown as inline errors or toast messages. Fix the first reported issue, retry, and re-check dependent sections that consume the same data.


### Concepts

- **Slug** — Stable machine id (snake_case: `my_breakout_screen`). Used for uniqueness and cross-artifact references. Not the display title — that is `name`. Only a–z, 0–9, and underscore after normalisation.
- **definition vs definition_json** — Import/export JSON uses the field `definition`. The database stores the same tree in column `definition_json`. Do not put `definition_json` in the envelope.
- **Mandatory envelope fields** — schema_version ("1.0"), artifact_type ("screener"), slug, name, metadata (object), and definition.root with at least one condition for a successful Import.
- **Operator enum** — Condition operators: gt, gte, lt, lte, eq only. Group ops: AND, OR only. NOT, neq, crosses_*, between, etc. are not supported.
- **Operand shapes** — Indicator `{ indicator, params }` or constant `{ type: "constant", value: <number> }`. No boolean/string/null/date operands.
- **No execution redesign** — Runs, schedules, and backtests still use ScreenerRunService and the existing definition tree.
- **Shared → Registry** — is_shared screeners from your other portfolios surface in the registry with ownership=shared; copying them creates a local owned artifact. Other users cannot see them.
- **Version bump** — Changing the condition tree (via editor or registry update) increments artifact_version and appends portfolio_screener_versions.
- **Active portfolio context** — Most data on this page is scoped by the selected portfolio profile; switching profile can completely change visible rows and metrics.
- **Data freshness** — Many analytics depend on cached daily OHLCV and scheduled sync jobs. If numbers look stale, refresh this page and verify sync status in admin tools.


### Related topics

- `screener`
- `screener-editor`
- `indicator-registry`
- `strategy-registry`
- `authoring-trading-artifacts`
- `trading-artifact-runtime`
- `trading-cookbook`
- `settings`

---

## 7. Strategy Registry

**Keyword:** `strategy-registry`
**Aliases:** `strategy-artifacts`, `strategy-json`, `import-strategy`, `select-strategy`

**Summary:** Import/export Strategy JSON artifacts — mandatory fields, uniqueness rules, eligibility refs, scoring_model weights, and Select one active strategy per portfolio.

**UI / docs route label:** `/strategy/registry`

The Strategy Registry turns portfolio strategies into reusable Trading Artifacts. Each portfolio still has **exactly one active Strategy** (selection). The registry adds slug, metadata, artifact_version, definition_hash, and version history on top of the same config the Recommendation engine already uses.

Export downloads the portable Trading Artifact JSON envelope. **Validate** checks the envelope. **Import** stays disabled until validation succeeds, then creates a **draft** — use **Select** to activate it (archives the previous active). Existing Minervini (`momentum_factory`) migrates automatically to slug `momentum_strategy` with eligibility linked to `minervini_trend_template`.

## Importing JSON — start here

If Validate or Import reports many field errors, you almost always missed a **mandatory** envelope field, forgot `scoring_model`, enabled weights that do not sum to 100, or embedded a Screener condition tree. Strategies reference Screeners by **slug / factory_key only** — never paste `definition.root` into a Strategy. Use the minimum schema below, then expand.

### Minimum valid envelope (copy/paste starting point)

```json
{
  "schema_version": "1.0",
  "artifact_type": "strategy",
  "slug": "my_first_strategy",
  "name": "My First Strategy",
  "metadata": {
    "scope": "portfolio",
    "status": "draft",
    "origin": "user",
    "description": "RS-only example strategy"
  },
  "definition": {
    "eligibility_sources": [
      {
        "screener_slug": "minervini_trend_template",
        "screener_factory_key": "minervini_trend_template",
        "enabled": true,
        "priority": 1
      }
    ],
    "scoring_model": [
      {
        "key": "relative_strength",
        "enabled": true,
        "weight": 100,
        "minimum": 70,
        "maximum": null,
        "parameters": {}
      }
    ]
  }
}
```

That JSON has every runtime-required field. Enabled weights sum to **100**. Eligibility points at a Screener by slug (import / seed that Screener in this portfolio first if it is missing — factory `minervini_trend_template` is auto-ensured for Minervini).

### Multi-factor scoring example

Enabled weights must still sum to 100:

```json
"scoring_model": [
  { "key": "relative_strength", "enabled": true, "weight": 50, "minimum": 70, "maximum": null, "parameters": {} },
  { "key": "momentum_score", "enabled": true, "weight": 50, "minimum": 60, "maximum": null, "parameters": {} }
]
```

### Mandatory vs optional fields

| Field | Required? | Unique? | What to put |
|-------|-----------|---------|-------------|
| `schema_version` | **Yes** | No | Always `"1.0"` |
| `artifact_type` | **Yes** | No | Always `"strategy"` |
| `slug` | **Yes** | **Yes** (per portfolio) | Stable id: `a-z`, `0-9`, `_` only (e.g. `swing_rs`). See **Slug** / **Uniqueness** below |
| `name` | **Yes** | Soft unique (per portfolio) | Human label shown in the UI; Import may append `" (import)"` on collision |
| `metadata` | **Yes** | No | An object — may be `{}`, but prefer `description` / `origin` / `status` |
| `definition` | **Yes** | No | Object with scoring + optional eligibility / editor sections |
| `definition.scoring_model` | **Yes** | Keys unique within the array | Non-empty array of scoring rows; **enabled** weights must sum to **100** (alias: `indicators`) |
| `definition.eligibility_sources` | Optional | Prefer unique screener refs | Array of Screener refs by `screener_slug` / `screener_factory_key` |
| `artifact_id` | Optional | Local DB id | Leave out on create; export may include a portfolio-local id |
| `artifact_version` | Optional | No | Integer ≥ 1; Import starts versions at 1 anyway |
| `definition_hash` | Optional | Content fingerprint | Recalculated on Import — do not invent this |
| `minimum_engine_version` | Optional | No | e.g. `"1.1.0"` |
| `dependencies` | Optional | No | Array of refs; export fills this |
| `validation` | Optional | No | Embedded hints; not executed as rules |

Note: the database stores strategy config in `config_json` on version rows, but the **import JSON field name is `definition`** (same idea as Screener’s `definition` vs `definition_json`).

**Forbidden:** `definition.root`, `definition.children`, or embedding `definition` / `root` inside an eligibility source — Strategies must not carry Screener trees.

### Uniqueness rules

| Field | Scope | Rule |
|-------|-------|------|
| `slug` | One portfolio | Must be unique among that portfolio’s strategies. On Import collision the system may suffix `_import_<hex>` (or create-path may use `_2`, `_3`, …). |
| `name` | One portfolio | Soft unique — Import may rename to `"… (import)"` if the display name already exists. |
| `metadata.factory_key` | Built-ins | Stable factory identity (e.g. `momentum_factory`). Not required for user imports. |
| Active selection | One portfolio | **Exactly one** strategy may be `active` / selected. Import always creates **draft**; **Select** activates and archives the previous active. |
| `scoring_model[].key` | One envelope | Each catalogue key should appear once; duplicates are collapsed when normalised. |
| `screener_slug` / `screener_factory_key` | Eligibility row | Identify a Screener in this portfolio (or a factory Screener the system can ensure). Not unique across strategies — many strategies may share the same Screener. |

### What each field means

**`schema_version`** — Which envelope contract this file uses. Must be `"1.0"`. Wrong or missing → `SCHEMA_VERSION_UNSUPPORTED` / empty schema errors.

**`artifact_type`** — Discriminator so the registry knows this is a Strategy (not Screener/Indicator). Must be `"strategy"`.

**`slug`** — Machine-stable key for this strategy inside a portfolio. Strategies are listed, exported, and selected by this id. Use snake_case like `swing_rs` or `momentum_strategy`. Allowed characters after normalisation: `a-z`, `0-9`, `_` (spaces and punctuation become `_`). Keep it short, unique, and meaningful — do **not** put a sentence here; that belongs in `name` / `metadata.description`. If the slug already exists on Import, the system may suffix it (e.g. `_import_ab12`) so the import still succeeds.

**`name`** — Display title in Registry lists and the Strategy editor. Example: `"Swing RS Strategy"`. If the name collides, Import may append `" (import)"`.

**`metadata`** — Descriptive object (required as an object; keys inside are optional):

| Key | Required? | Meaning / example |
|-----|-----------|-------------------|
| `scope` | Optional | Usually `"portfolio"` |
| `description` | Optional | Human prose, e.g. `"RS + momentum swing"` |
| `intent` | Optional | Why this strategy exists |
| `summary` | Optional | Short blurb (export often mirrors description) |
| `tags` | Optional | Array of strings, e.g. `["swing", "rs"]` |
| `status` | Optional | Hint: `draft` / `active` / `archived` — Import **always** stores draft regardless |
| `origin` | Optional | `factory` / `user` / `imported` / … |
| `factory_key` | Optional | Built-in id, e.g. `momentum_factory` |
| `is_selected` | Export-only | Whether this row is the active selection; Import ignores it — use **Select** |
| `storage` / `legacy_id` | Export-only | Internal pointers; leave out on hand-written JSON |

**`definition`** — Strategy runtime config. Validate requires scoring; eligibility is strongly recommended for a working Recommendations feed.

**`definition.eligibility_sources`** — Which Screeners feed candidates. Each source (do **not** embed condition trees):

```json
{
  "screener_slug": "minervini_trend_template",
  "screener_factory_key": "minervini_trend_template",
  "enabled": true,
  "priority": 1
}
```

| Field | Required? | Meaning |
|-------|-----------|--------|
| `screener_slug` | One of slug / factory_key / (local) screener_id | Portable Screener id (preferred) |
| `screener_factory_key` | One of the above | Factory Screener key (alias `factory_key` accepted) |
| `screener_id` | Avoid in packs | Portfolio-local DB id — export strips it; do not rely on it for portability |
| `enabled` | Optional | Default `true` |
| `priority` | Optional | Integer order (lower = sooner); default `1` |
| `min_artifact_version` | Optional | Minimum Screener artifact version if you pin one |
| `definition` / `root` | **Forbidden** | Embedding a Screener tree fails Validate / Import |

Empty `eligibility_sources` may pass Validate, but Recommendations will have no Screener feed until you add refs.

**`definition.scoring_model`** (alias **`indicators`**) — Weighted score rows:

```json
{
  "key": "relative_strength",
  "enabled": true,
  "weight": 50,
  "minimum": 70,
  "maximum": null,
  "parameters": {}
}
```

| Field | Required? | Meaning |
|-------|-----------|--------|
| `key` | **Yes** | Strategy-scorable catalogue id (see list below) |
| `enabled` | Strongly recommended | Only **enabled** rows count toward the weight sum |
| `weight` | **Yes** if enabled | Share of overall score; all enabled weights must sum to **100** (±0.01) |
| `minimum` | Optional | Soft/hard gate on that factor (number or `null`) |
| `maximum` | Optional | Upper gate when the catalogue supports it |
| `parameters` | Optional | Indicator-specific knobs object (defaults filled on normalise) |

Rules:
- At least one enabled row with positive weight is required
- Prefer canonical keys in new JSON (aliases like `momentum` → `momentum_score` may resolve)
- Unknown keys fail Validate with `STRATEGY_KEYS_REGISTRY`

**Strategy-scorable keys** (canonical): `relative_strength`, `momentum_score`, `trend_score`, `breakout_score`, `volume_score`, `market_regime`, `sector_strength`, `risk_score`.

**Other optional `definition` sections** (appear in Export / Strategy editor; preserved on Import via normalise; not hard-checked by Validate the same way as scoring):
- `thresholds` — label bands (e.g. Open / Increase / Hold / Watch cut-offs)
- `portfolio_rules` — position size %, concentration, etc.
- `exit_strategy` — exit rules (`enabled`, `mode`, `rules`)
- `market_gates` — market-regime gates
- Start from an Export of a working strategy when you need these filled in correctly.

**`artifact_version` / `definition_hash`** — Versioning metadata. Export includes them; Import recomputes the hash and starts history for the new local draft.

**`dependencies`** — Export lists `uses_screener` / `uses_indicator` refs for packaging. Optional on Import; resolved from eligibility + scoring keys.

### Recommended import workflow

1. Ensure referenced Screeners exist in this portfolio (Screener Registry → import Screener JSON, or use a factory screener like `minervini_trend_template`).
2. Start from the minimum example above (or Export an existing working strategy and edit a copy — best for thresholds / exits / gates).
3. Paste into Strategy Registry → **Validate** and fix every listed path (`$.slug`, `$.definition.scoring_model`, …).
4. Use **Import** (enabled only after Validate succeeds) — creates a **draft** (does not change Recommendations yet).
5. Click **Select** on the new row when you want it to drive Recommendations (archives the previous active).
6. Optionally open **Edit** on the active strategy (`/strategy`) to refine tabs visually and Save.

### Common validation / import errors

| Message / code | Likely cause |
|----------------|--------------|
| `slug is required` | Missing or blank `slug` |
| `name is required` | Missing or blank `name` |
| `definition object is required` | Omitted `definition` (or used a DB-only field name) |
| `metadata object is required` | Omitted `metadata` or not an object |
| `STRATEGY_SCORING_REQUIRED` | Missing `scoring_model` / `indicators` |
| `STRATEGY_WEIGHTS_SUM_100` | Enabled weights ≠ 100, or none enabled |
| `STRATEGY_KEYS_REGISTRY` / not strategy-scorable | Unknown or non-scorable `key` |
| `STRATEGY_ELIGIBILITY_REFS` | Source missing slug, factory_key, and screener_id |
| `STRATEGY_NO_EMBEDDED_SCREENER` | Put a Screener `root` / `children` tree on the Strategy |
| Must not embed Screener definitions | Eligibility row contains `definition` / `root` |
| Slug / name already exists | Pick another or let Import rename |

## Runtime notes (scoring, eligibility, thresholds)

See **Trading Artifact Runtime Semantics** (`trading-artifact-runtime`) for the full Recommendation pipeline. Short form:

- Overall ≈ weighted average of enabled factor scores; gated min/max -> contribution 0 but **weight still dilutes**.
- Multiple `eligibility_sources` = **UNION**; `priority` is order only.
- Thresholds use sequential if/else (not exclusive bands); score 82 with defaults is typically **WATCH** (not held) / **HOLD** (held).
- `market_gates` demote OPEN/INCREASE only; exits still run.
- Portfolio cash rules can demote unfunded buys to **WATCH**.

## Optional definition sections (fully usable)

These sections are **runtime-usable today**. Import preserves them via `normalizeConfig` (Validate focuses hard checks on eligibility + scoring — Export a working Strategy, then edit, for safest authoring). They are **not** “reserved / undocumented.”

### `thresholds` — recommendation label bands (0–100 scores)

| Key | Default | Meaning |
|-----|---------|--------|
| `minimum_overall_score` | 80 | Stored on Strategy UI; **not** currently applied as a hard pipeline gate |
| `open_position` | 85 | Open new long when overall score ≥ this |
| `increase_position` | 90 | Add to existing when score ≥ this |
| `watch` | 60 | Insight / watch band |
| `reduce_position` | 40 | Reduce when score ≤ this |
| `exit_position` | 20 | Exit when score ≤ this |
| `very_strong_high` | 95 | Very-strong high band |
| `very_strong_low` | 15 | Very-strong low band |

```json
"thresholds": {
  "minimum_overall_score": 80,
  "open_position": 85,
  "increase_position": 90,
  "reduce_position": 40,
  "exit_position": 20,
  "watch": 60,
  "very_strong_high": 95,
  "very_strong_low": 15
}
```

### `portfolio_rules` — size and cash caps (%)

| Key | Default | Meaning |
|-----|---------|--------|
| `max_position_size_pct` | 10 | Cap per position |
| `min_position_size_pct` | 2 | Floor when sizing |
| `default_position_size_pct` | 6 | Default clip size |
| `allocation_band_pct` | 1 | Band around target |
| `max_cash_deployment_pct` | 80 | Max capital deployed |
| `min_cash_reserve_pct` | 20 | Cash reserve |
| `max_new_positions_per_cycle` | 5 | New opens per cycle |
| `max_exposure_per_stock_pct` | 10 | Per-name exposure cap |

### `exit_strategy` — holding exit rules

```json
"exit_strategy": {
  "enabled": true,
  "mode": "any",
  "rules": [
    {
      "key": "ma_breakdown",
      "display_name": "Moving Average Breakdown",
      "enabled": true,
      "params": { "period": 50 }
    },
    {
      "key": "score_exit",
      "display_name": "Overall Score Exit",
      "enabled": true,
      "value": 20
    }
  ]
}
```

- `mode`: `any` (any matching enabled rule triggers; all matches recorded) or `all` (every enabled rule must match)
- Rule `key` values: `ma_breakdown`, `rs_weakening`, `trend_weakening`, `score_exit`, `max_loss`, `atr_stop`, `trailing_stop`, `screener_exit`
- Use `value` and/or `params` as appropriate to the key (see Export of Momentum Strategy for full defaults)

### `market_gates` — block new entries by market regime

```json
"market_gates": {
  "enabled": false,
  "min_sentiment": 45,
  "allowed_phases": [
    "Strong Bull",
    "Bull",
    "Consolidation",
    "Pullback",
    "Recovery"
  ],
  "max_risk_raw": 70
}
```

Also mergeable (same as Export): `capital_allocation`, `cash_rules`, `recommendation_behaviour`, `risk`.

## Complete Strategy examples

Each Strategy assumes you already imported (or seeded) a Screener whose **slug** matches `screener_slug`. Import creates a **draft** — **Select** to activate. Enabled weights sum to **100**.

### Strategy — Momentum (Minervini-style)

```json
{
  "schema_version": "1.0",
  "artifact_type": "strategy",
  "slug": "momentum_swing",
  "name": "Momentum Swing",
  "metadata": {
    "scope": "portfolio",
    "status": "draft",
    "origin": "user",
    "description": "RS + trend + momentum on Minervini eligibility"
  },
  "definition": {
    "eligibility_sources": [
      {
        "screener_slug": "minervini_trend_template",
        "screener_factory_key": "minervini_trend_template",
        "enabled": true,
        "priority": 1
      }
    ],
    "scoring_model": [
      {
        "key": "relative_strength",
        "enabled": true,
        "weight": 35,
        "minimum": 80,
        "maximum": null,
        "parameters": {
          "lookback_days": 90,
          "benchmark": "NIFTY50"
        }
      },
      {
        "key": "trend_score",
        "enabled": true,
        "weight": 25,
        "minimum": 70,
        "maximum": null,
        "parameters": {
          "sma_fast": 20,
          "sma_slow": 50
        }
      },
      {
        "key": "momentum_score",
        "enabled": true,
        "weight": 20,
        "minimum": 70,
        "maximum": null,
        "parameters": { "rsi_period": 14 }
      },
      {
        "key": "volume_score",
        "enabled": true,
        "weight": 10,
        "minimum": 60,
        "maximum": null,
        "parameters": { "volume_sma_period": 20 }
      },
      {
        "key": "breakout_score",
        "enabled": true,
        "weight": 10,
        "minimum": 75,
        "maximum": null,
        "parameters": {}
      }
    ],
    "thresholds": {
      "open_position": 85,
      "increase_position": 90,
      "watch": 60,
      "reduce_position": 40,
      "exit_position": 20
    }
  }
}
```

### Strategy — Growth (trend + RS heavy)

Eligibility: `ma_breakout` screener (import Example 1 first).

```json
{
  "schema_version": "1.0",
  "artifact_type": "strategy",
  "slug": "growth_trend",
  "name": "Growth Trend",
  "metadata": {
    "scope": "portfolio",
    "origin": "user",
    "description": "Growth: strong trend and RS on MA breakout names"
  },
  "definition": {
    "eligibility_sources": [
      {
        "screener_slug": "ma_breakout",
        "enabled": true,
        "priority": 1
      }
    ],
    "scoring_model": [
      { "key": "trend_score", "enabled": true, "weight": 40, "minimum": 70, "maximum": null, "parameters": { "sma_fast": 20, "sma_slow": 50 } },
      { "key": "relative_strength", "enabled": true, "weight": 40, "minimum": 80, "maximum": null, "parameters": { "lookback_days": 90, "benchmark": "NIFTY50" } },
      { "key": "momentum_score", "enabled": true, "weight": 20, "minimum": 60, "maximum": null, "parameters": { "rsi_period": 14 } }
    ]
  }
}
```

### Strategy — Value (lower risk weight, RS + trend)

Note: StoX Screeners have no fundamental PE/PB — “value” here means lower-risk / quality tilt after a defensive eligibility screen. Use a pullback or mean-reversion screener slug.

```json
{
  "schema_version": "1.0",
  "artifact_type": "strategy",
  "slug": "value_quality",
  "name": "Value Quality Tilt",
  "metadata": {
    "scope": "portfolio",
    "origin": "user",
    "description": "Quality tilt: RS + trend with risk capped"
  },
  "definition": {
    "eligibility_sources": [
      { "screener_slug": "rsi_pullback", "enabled": true, "priority": 1 }
    ],
    "scoring_model": [
      { "key": "relative_strength", "enabled": true, "weight": 30, "minimum": 70, "maximum": null, "parameters": {} },
      { "key": "trend_score", "enabled": true, "weight": 30, "minimum": 60, "maximum": null, "parameters": {} },
      { "key": "momentum_score", "enabled": true, "weight": 20, "minimum": 50, "maximum": null, "parameters": {} },
      { "key": "risk_score", "enabled": true, "weight": 20, "minimum": 0, "maximum": 40, "parameters": { "atr_period": 14 } }
    ]
  }
}
```

### Strategy — Swing (balanced)

```json
{
  "schema_version": "1.0",
  "artifact_type": "strategy",
  "slug": "swing_balanced",
  "name": "Swing Balanced",
  "metadata": { "scope": "portfolio", "origin": "user", "description": "Balanced swing scoring" },
  "definition": {
    "eligibility_sources": [
      { "screener_slug": "rsi_pullback", "enabled": true, "priority": 1 }
    ],
    "scoring_model": [
      { "key": "relative_strength", "enabled": true, "weight": 25, "minimum": 70, "maximum": null, "parameters": {} },
      { "key": "momentum_score", "enabled": true, "weight": 25, "minimum": 60, "maximum": null, "parameters": {} },
      { "key": "trend_score", "enabled": true, "weight": 25, "minimum": 60, "maximum": null, "parameters": {} },
      { "key": "volume_score", "enabled": true, "weight": 15, "minimum": 50, "maximum": null, "parameters": {} },
      { "key": "risk_score", "enabled": true, "weight": 10, "minimum": 0, "maximum": 45, "parameters": {} }
    ]
  }
}
```

### Strategy — Breakout

Eligibility: `high_volume_breakout`.

```json
{
  "schema_version": "1.0",
  "artifact_type": "strategy",
  "slug": "breakout_thrust",
  "name": "Breakout Thrust",
  "metadata": { "scope": "portfolio", "origin": "user", "description": "Breakout + volume + pattern evidence" },
  "definition": {
    "eligibility_sources": [
      { "screener_slug": "high_volume_breakout", "enabled": true, "priority": 1 }
    ],
    "scoring_model": [
      { "key": "breakout_score", "enabled": true, "weight": 30, "minimum": 75, "maximum": null, "parameters": {} },
      { "key": "volume_score", "enabled": true, "weight": 25, "minimum": 60, "maximum": null, "parameters": {} },
      { "key": "relative_strength", "enabled": true, "weight": 25, "minimum": 70, "maximum": null, "parameters": {} },
      { "key": "trend_score", "enabled": true, "weight": 20, "minimum": 60, "maximum": null, "parameters": {} }
    ]
  }
}
```



Practical tip: treat this page as one step in a larger workflow, not an isolated screen. Make one change at a time, verify the downstream effect, and use linked pages to complete the loop (for example: discovery → evaluation → recommendation → pending execution → review).

### Controls

- **Search / filters** — Filter by status (active/draft/archived) and origin (factory/user).
- **Select** — Make this strategy the portfolio’s only active strategy (archives the previous active). Success shows a toast notification. Recommendations use the selected strategy.
- **Export JSON** — Download the portable Trading Artifact envelope (schema_version, slug, name, metadata, definition with eligibility_sources + scoring_model, dependencies). Best template for a new import — includes thresholds/exits/gates when present.
- **Validate** — Check pasted JSON against Trading Artifact Strategy rules. On success, a green “Validated successfully” cue appears above Validate/Import and the JSON result panel still shows details. Import stays disabled until this reports ok. Editing the JSON clears validation.
- **Import** — Enabled only after successful Validate. Creates a draft strategy in this portfolio and shows a success toast (not an inline alert). Does not change Recommendations until Select. Mandatory: schema_version, artifact_type, slug, name, metadata, definition.scoring_model with enabled weights = 100.
- **Download AI authoring guide (.md)** — Download /docs/stox-trading-artifacts-ai-guide.md — consolidated Indicator + Screener + Strategy + Authoring + Cookbook Markdown for AI agents and offline authoring.
- **Edit active** — Jump to /strategy to edit the selected strategy’s tabs and Save.
- **Version history** — On detail for owned strategies, list definition snapshots and change notes. Draft definition-hash changes append versions; active editor Save remains in-place BC.
- **Typical flow** — Open this page, verify active portfolio context in the header, perform one meaningful action, then confirm the reflected change in list/cards/history before leaving.
- **Validation and errors** — Form and API validations are shown as inline errors or toast messages. Fix the first reported issue, retry, and re-check dependent sections that consume the same data.


### Concepts

- **Slug** — Stable machine id (snake_case: `swing_rs`). Unique per portfolio. Used for uniqueness and selection. Not the display title — that is `name`. Only a–z, 0–9, and underscore after normalisation.
- **Uniqueness** — slug is unique per portfolio (Import may suffix on collision). name is soft-unique (may get " (import)"). Exactly one active strategy per portfolio. scoring keys should appear once in scoring_model. Screener refs may be shared across strategies.
- **Exactly one active** — Selection rule: one STATUS_ACTIVE strategy per portfolio drives Recommendation scoring. Import always creates draft; Select activates.
- **No Screener duplication** — eligibility_sources reference Screeners by screener_slug / screener_factory_key. Condition trees stay on Screener Registry — never embed root/children on a Strategy.
- **definition vs config_json** — Import/export JSON uses the field `definition`. Version rows store the same config in `config_json`. Do not invent a `config_json` field on the envelope.
- **scoring_model vs indicators** — Portable JSON prefers scoring_model. The engine also accepts indicators as an alias; Import normalises both. Enabled weights must sum to 100.
- **Mandatory envelope fields** — schema_version ("1.0"), artifact_type ("strategy"), slug, name, metadata (object), and definition.scoring_model with enabled weights summing to 100.
- **Minervini auto-migrate** — ensureActive / seedFactoryStrategy backfills slug momentum_strategy, metadata, and Minervini screener eligibility links without overwriting user-edited scores.
- **Optional sections are live** — thresholds, portfolio_rules, exit_strategy, and market_gates are runtime-usable and Import-preserved — not reserved. Prefer Export-then-edit for full defaults.
- **Active portfolio context** — Most data on this page is scoped by the selected portfolio profile; switching profile can completely change visible rows and metrics.
- **Data freshness** — Many analytics depend on cached daily OHLCV and scheduled sync jobs. If numbers look stale, refresh this page and verify sync status in admin tools.


### Related topics

- `strategy`
- `screener-registry`
- `indicator-registry`
- `recommendations`
- `authoring-trading-artifacts`
- `trading-artifact-runtime`
- `trading-cookbook`
- `settings`

---

## 8. Trading Cookbook

**Keyword:** `trading-cookbook`
**Aliases:** `cookbook`, `investing-recipes`, `artifact-recipes`

**Summary:** Complete investing recipes: philosophy + Screener JSON + Strategy JSON for Minervini, breakout, pullback, mean reversion, and more.

**UI / docs route label:** `/docs/trading-cookbook.html`

Each recipe is designed so an AI or human can paste JSON into the registries. Screeners use only supported operators/operands; Strategies use only strategy-scorable keys with weights = 100. Import Screener first, then Strategy with matching `screener_slug`. Strategy Import creates a draft — Select to activate.

For operator/operand rules see [Screener Registry](screener-registry.html). For scoring keys see [Indicator Registry](indicator-registry.html) and [Strategy Registry](strategy-registry.html). Workflow: [Authoring Trading Artifacts](authoring-trading-artifacts.html).

---

## Recipe — Minervini (Trend Template + Momentum Strategy)

**Philosophy:** Stage-2 trend template eligibility; rank by relative strength, trend, and momentum.

**Why the rules:** Price above rising SMA stack filters for established uptrends; proximity to 52w high seeks leadership; RS/trend/momentum score continuation quality.

Use Screener Registry **Example 5** (`minervini_trend_template` factory or the copy envelope) plus Strategy Registry **Momentum** example (`screener_slug`: `minervini_trend_template`).

---

## Recipe — Breakout (High Volume)

**Philosophy:** Buy strength when price makes a fresh N-day high on expanding volume.

**Why:** `high_n` approximates a breakout level; `volume_ratio` confirms participation; Strategy weights `breakout_score` + `volume_score` + RS.

Screener: Registry Example 4 (`high_volume_breakout`). Strategy: Registry **Breakout** example.

---

## Recipe — Pullback (RSI)

**Philosophy:** Buy dips in an uptrend when RSI cools without breaking the 50-SMA.

**Why:** Trend filter (`close > sma50`) + RSI band (40–55) avoids catching knives and chasing extensions.

Screener: Example 2 (`rsi_pullback`). Strategy: **Swing Balanced** or **Value Quality** examples.

---

## Recipe — Mean Reversion (BB + RSI)

**Philosophy:** Fade stretched downside toward the mean when RSI is oversold and price is near the lower band.

**Screener JSON:**

```json
{
  "schema_version": "1.0",
  "artifact_type": "screener",
  "slug": "mean_reversion_oversold",
  "name": "Mean Reversion Oversold",
  "metadata": { "universe": "all_equities", "description": "RSI < 30 and bb_pct_b < 0.2" },
  "definition": {
    "root": {
      "type": "group",
      "op": "AND",
      "children": [
        {
          "type": "condition",
          "left": { "indicator": "rsi", "params": { "period": 14 } },
          "operator": "lt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 30 }
        },
        {
          "type": "condition",
          "left": { "indicator": "bb_pct_b", "params": { "period": 20, "mult": 2 } },
          "operator": "lt",
          "weight_factor": 1,
          "right": { "type": "constant", "value": 0.2 }
        }
      ]
    }
  }
}
```

**Strategy JSON:** prefer lower `risk_score` maximum and modest open thresholds; eligibility `mean_reversion_oversold`; weights e.g. momentum 30 / trend 30 / RS 20 / risk 20 (max 40).

---

## Recipe — 52-Week High / High Relative Strength

**Philosophy:** Leadership near highs. Note: raw RS ratios (`relative_strength_3m`) are **not screenable** — screen with price vs 52w high / SMA, then let Strategy `relative_strength` score the leaders.

**Screener:** close ≥ 0.95 × `high_52w` AND close > SMA50 (use `weight_factor` 0.95 on `high_52w` with `gte`).

**Strategy:** heavy `relative_strength` (40) + `trend_score` (30) + `momentum_score` (30).

---

## Recipe — Momentum Rotation

**Philosophy:** Keep capital in relative leaders; eligibility can be broad (`ma_breakout` or Minervini); scoring dominated by `relative_strength` + `momentum_score`.

Use Strategy **Growth** or **Momentum** examples; raise `relative_strength` weight to 40–50 and keep enabled weights at 100.

---

## Recipe — Darvas Box

**Philosophy:** Breakout from a consolidation “box.” StoX approximates with `high_n` + volume (Screener Example 6).

Pair with Strategy **Breakout** (`screener_slug`: `darvas_box_proxy`).

---

## Recipe — CANSLIM / Growth (approximation)

**Philosophy:** Classic CANSLIM needs earnings/sponsorship data StoX Screeners do not have. Approximate with Minervini-like trend template + volume expansion + RS-heavy Strategy.

Screener: Minervini Example 5 AND `volume_ratio` > 1.2 (add as another AND child). Strategy: Momentum example with `relative_strength` ≥ 35 weight.

---

## Recipe — Value Investing (limitation note)

**Philosophy:** Buy underpriced quality. StoX V1 has **no PE/PB/book** screenable indicators. Use pullback/mean-reversion eligibility + Strategy with capped `risk_score` (see Strategy **Value** example) and treat fundamental value as outside this artifact pack.


Practical tip: treat this page as one step in a larger workflow, not an isolated screen. Make one change at a time, verify the downstream effect, and use linked pages to complete the loop (for example: discovery → evaluation → recommendation → pending execution → review).

### Controls

- **Copy Screener then Strategy** — Always import eligibility Screener before Strategy so screener_slug resolves.
- **Select after Import** — Cookbook Strategies import as drafts until Select.
- **Typical flow** — Open this page, verify active portfolio context in the header, perform one meaningful action, then confirm the reflected change in list/cards/history before leaving.
- **Validation and errors** — Form and API validations are shown as inline errors or toast messages. Fix the first reported issue, retry, and re-check dependent sections that consume the same data.


### Concepts

- **Approximations** — Darvas/CANSLIM/Value recipes state where StoX lacks a native indicator and what proxy to use instead of inventing unsupported fields.
- **RS screening vs scoring** — Relative strength ratios are Evaluation/Strategy inputs; Screeners use price/volume Primaries, then Strategy relative_strength ranks.
- **Active portfolio context** — Most data on this page is scoped by the selected portfolio profile; switching profile can completely change visible rows and metrics.
- **Data freshness** — Many analytics depend on cached daily OHLCV and scheduled sync jobs. If numbers look stale, refresh this page and verify sync status in admin tools.


### Related topics

- `ai-authoring-contract`
- `authoring-trading-artifacts`
- `trading-artifact-runtime`
- `screener-registry`
- `strategy-registry`
- `indicator-registry`
- `trading-os-flow`

---

## 9. Complete Examples

## Canonical end-to-end lifecycle (Minervini-style)

1. Choose philosophy — trend template eligibility + RS/trend/momentum ranking.
2. Select indicators — Screenable: `close`, `sma`, `sma_spread_pct`, `low_52w`, `high_52w`. Scorable: `relative_strength`, `trend_score`, `momentum_score`, `volume_score`, `breakout_score`.
3. Build Screener — Screener Registry Minervini example or factory `minervini_trend_template`. Set all `params.period` explicitly.
4. Validate Screener until `ok`.
5. Import Screener; note final `slug`.
6. Create Strategy envelope `artifact_type: strategy` with unique slug/name.
7. Reference Screener via `eligibility_sources[].screener_slug` / `screener_factory_key`.
8. Configure scoring — enabled weights = 100; optional `minimum` gates and Composite `parameters`.
9. Configure thresholds — e.g. open 85 / watch 60 / exit 20 (or Export Momentum defaults).
10. Validate Strategy until `ok`.
11. Import Strategy (draft).
12. Select Strategy (sole active Strategy).
13. Engine execution — Discovery → Evaluation → eligibility UNION → weighted score → exits/gates/thresholds → capital → persist.
14. Final output — OPEN/INCREASE/REDUCE/EXIT and WATCH/HOLD on Recommendations, subject to cash and gates.

## Where full JSON lives

- Screener Registry — complete Screener envelopes (operators, operands, Minervini/Darvas/…).
- Strategy Registry — complete Strategy envelopes (scoring + optional sections).
- Trading Cookbook — philosophy + paired Screener/Strategy recipes.

AI MUST satisfy the AI Authoring Contract before emitting any example-based JSON.

---

## 10. Appendix

### A. Runtime Semantics (detailed reference)

Normative authoring implications appear in the Contract (Runtime Behaviour Rules). This appendix documents engine evaluation detail.

#### Trading Artifact Runtime Semantics

**Keyword:** `trading-artifact-runtime`

This section documents **runtime behaviour** of the Recommendation Engine and related services. Schema fields alone are not enough - AI authors need evaluation order, gating, and edge cases.

---

## 1. Strategy scoring semantics

**Where:** `StrategyConfigurationService::score()` during Recommendation generation (Evaluation emits 0-100 factor facts; Strategy applies weights).

### Formula

For each **enabled** scoring row with `weight > 0`:

1. Add `weight` to `totalWeight` (even if the factor later gates to zero).
2. Read the Evaluation fact for `key` (canonicalised aliases apply).
3. Compute `contribution`:
   - Missing / non-numeric fact -> `contribution = 0`, `gated = true`
   - `value < minimum` (when minimum set) -> `contribution = 0`, `gated = true`
   - `value > maximum` (when maximum set) -> `contribution = 0`, `gated = true`
   - Else: `normalized = clamp(value, 0, 100) / 100`
     - Special case **`risk_score`** with a maximum set: `normalized = 1 - value/100` (higher risk lowers contribution)
     - `contribution = round(normalized * weight, 4)`
4. `overall_score = round((earned / totalWeight) * 100, 4)`, then clamp to `[0, 100]`

When enabled weights sum to **100** and nothing is gated:

`overall ≈ Σ(weight × factor_score) / 100`

which is the same as a weight-normalised average:

`overall = Σ(weight × factor_score) / Σ(weight)`

### Rounding / precision

- Per-factor `contribution` and `overall_score`: **4 decimal places** (`round(..., 4)`).
- Display UIs may show fewer decimals; stored recommendation math uses the 4 d.p. overall.

### `enabled`, `minimum`, `maximum`

| Field | Behaviour |
|-------|-----------|
| `enabled: false` | Factor ignored - not in `totalWeight`, no contribution |
| `minimum: 70` | Soft gate: if fact `< 70`, contribution = **0** (weight still in denominator) |
| `maximum: 90` | Soft gate: if fact `> 90`, contribution = **0** (same dilution) |
| Both set | Pass band is **`minimum <= value <= maximum`** (inclusive). Outside -> gated zero |

**Important:** Failing min/max does **not** reject the stock from Recommendations. It zeros that factor and **dilutes** overall because weight remains in `totalWeight`. There is no "hard fail whole candidate" on a single gated factor.

---

## 2. Eligibility and decision pipeline

Daily / recommendation cycle (simplified):

```text
Data readiness
    |
    v
Discovery (patterns + recent screener hits / membership fallback)
    |
    v
Evaluation (factor facts 0-100 - no Strategy weights yet)
    |
    v
RecommendationGenerationPipeline
    |-- resolve eligibility_sources (UNION of enabled Screeners)
    |-- StrategyConfigurationService.score (scoring_model)
    |-- ExitStrategyEvaluator (owned holdings only)
    |-- buildMarketOpinion + decidePortfolioAction (thresholds)
    |-- market_gates demotion (OPEN/INCREASE only)
    |-- rankDrafts
    |-- allocateCapital (portfolio_rules + cash)
    |-- persistDrafts
```

| Strategy section | When it applies |
|------------------|-----------------|
| `eligibility_sources` | Filters which names may receive **new entry** actions; holdings always reviewed |
| `scoring_model` | Weighted overall score from Evaluation facts |
| `thresholds` | Opinion (bull/bear/neutral) + OPEN/WATCH/HOLD/INCREASE/REDUCE/EXIT |
| `exit_strategy` | Can force EXIT on **held** names |
| `market_gates` | After scoring: demote OPEN/INCREASE when blocked; size multipliers |
| `portfolio_rules` | Position % caps, cash reserve/deploy, max new positions; can demote unfunded buys to WATCH |
| `capital_allocation` | Score bands for allocation % |

Discovery itself is **not** limited to the active Strategy eligibility list (it merges recent screener hits broadly). Strategy eligibility is enforced in Recommendation.

---

## 3. Multiple eligibility screeners

- Mode: **UNION** (`screener_union`). A stock is eligible if it appears in **any** enabled source's latest completed run (within ~72 hours).
- **Not** intersection.
- `priority`: sort order only - does **not** change union semantics.
- Duplicate hits across screeners are **de-duplicated** by security id.
- Empty `eligibility_sources`: unrestricted (no screener filter for entries).
- Sources configured but no recent completed runs: pipeline may treat as unrestricted pending runs (no artificial empty set).
- **Holdings** are always considered for HOLD/REDUCE/EXIT even if not currently eligible for new entries.

---

## 4. Thresholds - runtime behaviour

Factory defaults (Momentum): `open_position=85`, `increase_position=90`, `watch=60`, `reduce_position=40`, `exit_position=20`.

### Example: overall score = 82 (not held)

- Opinion: **NEUTRAL** (not >= open 85, not <= exit 20), strength often **MODERATE** if >= watch 60.
- Action: **WATCH** (OPEN requires score >= open or strong-bull path).

### Example: overall score = 82 (held)

- Action: typically **HOLD** (not EXIT/REDUCE/INCREASE under default bands).

### Evaluation order / conflicts

Thresholds are **not** exclusive score bands with ties. Code uses sequential rules:

1. Market opinion: if `score >= open` -> bullish; else if `score <= exit` -> bearish; else neutral.
2. Portfolio action (not held): OPEN only if bullish/strong path or `score >= open`; else WATCH.
3. Portfolio action (held): EXIT if bearish/`score <= exit`; else REDUCE checks; else INCREASE if bullish and `score >= increase`; else HOLD.

Comparisons are **inclusive** on the side used (`>=` open/watch/increase, `<=` exit/reduce).

**Open overrides Watch** when open condition is met. Exit/reduce paths take precedence over hold/increase for holdings when those conditions fire.

`minimum_overall_score` is stored on Strategy config / UI but is **not** currently applied as a hard gate in the recommendation pipeline (use `open_position` / scoring mins instead).

---

## 5. Exit strategy - execution semantics

- Evaluated on **owned holdings** (and a screener-exit-only pass for holdings missing from the eval set).
- Runs each Recommendation cycle for held names.
- `mode: "any"` (default): if **any** enabled rule matches, exit triggers (all matches are recorded).
- `mode: "all"`: **every enabled rule** must match to trigger.
- Rules can use Strategy/Evaluation scores (`score_exit`, `rs_weakening`, `trend_weakening`), price/SMA/ATR facts, unrealized PnL, and **`screener_exit`** (registry screener hit lists by local screener id - not an embedded condition tree).
- Triggered exit overrides other actions to **EXIT**.

---

## 6. Market gates

When `market_gates.enabled` is true and sentiment/phase/risk checks fail entry:

- Scoring **still runs** (gates do not skip Evaluation/Strategy score).
- **OPEN / INCREASE** demoted to WATCH (not held) or HOLD (held).
- **EXIT / REDUCE / HOLD** still allowed - sells and holds are not blocked by entry gates.
- Gates may also shrink position size multipliers when partially allowing entry.

---

## 7. Portfolio rules

- Target size uses something like `min(max_position_size_pct, band_or_default_pct)` - **max always caps** default/band.
- `min_cash_reserve_pct` / `max_cash_deployment_pct` reduce available cash before allocation.
- If cash cannot fund an OPEN/INCREASE, the draft is typically demoted to **WATCH** (`ALLOCATION_UNFUNDED`) - i.e. rules can **suppress BUY actions**, not only leave a zero-qty BUY.
- `max_new_positions_per_cycle` limits how many new opens survive ranking/allocation.

---

## 8. Indicator parameter defaults (Screeners)

**Catalogue / UI defaults** (Indicator Registry tables) are what the editor shows when you add a condition (e.g. EMA catalogue default period **50**).

**Runtime** (`TechnicalIndicatorService`): if a param key is **omitted** from JSON, the service uses its own fallbacks - for many series including **`ema`/`sma` that fallback is `period ?? 20`**, not necessarily the catalogue UI default.

Therefore:

```json
{ "indicator": "ema" }
```

is **not guaranteed** identical to

```json
{ "indicator": "ema", "params": { "period": 50 } }
```

**AI best practice:** always set params explicitly for production artifacts.

---

## 9. Parameter validation

On Screener Validate / Import / save (`ScreenerDefinitionValidator`):

| Input | Result |
|-------|--------|
| Param present, non-numeric | **Reject** |
| Param present, outside catalogue min/max (e.g. period 0, period 500, mult 9) | **Reject** |
| Param **omitted** | Not range-checked; runtime fallback applies |

No silent clamp to catalogue max on Validate. Do not rely on runtime `max(1, period)` as a substitute for correct JSON.

---

## 10. Missing data

| Situation | Screener | Strategy score |
|-----------|----------|----------------|
| Insufficient OHLCV bars | Stock **skipped** (`insufficient_data`) - not a match |
| `needs_volume` but no volume | Stock **skipped** (`insufficient_volume`) |
| Indicator returns null on a condition | That condition is **false** (AND fails / OR may still pass) |
| Corporate action / DQ hold | Separate Data Quality guards may exclude names from pipelines |
| Missing Evaluation fact for a scoring key | Factor contribution **0**, gated; weight still dilutes overall |

---

## 11. Numeric precision and `eq`

- Screener `eq`: float compare with abs epsilon **1e-4**, else relative **1e-6** (`floatsEqual`). Prefer `gte`/`lte` for thresholds.
- Strategy overall: 4 decimal places as above.
- Cash/allocation amounts often rounded to 2-4 decimals in plan builders.

---

## 12. Import normalisation

### Strategy

- Aliases: `scoring_model` <-> `indicators`; eligibility `factory_key` <-> `screener_factory_key`.
- Scoring keys normalised to catalogue; unsupported keys dropped; missing catalogue rows filled from defaults; enabled weights redistributed toward 100 when needed by `normalizeConfig`.
- Known sections merged (`thresholds`, `portfolio_rules`, `market_gates`, `exit_strategy`, ...).
- Unknown **top-level** definition keys are generally **not** preserved (config rebuilt from known sections).
- Extra **nested** keys inside merged objects may survive `array_merge`.
- Slug/name collisions get suffix / `(import)` rename.
- Always Import as **draft**; activation is Select.

### Screener

- Validate tree; create with unique slug; definition stored as provided (after validation).
- Prefer exporting a working screener before large edits.

---

## 13. Version compatibility

| Field | Behaviour |
|-------|-----------|
| `schema_version` | Required. Major must be <= app major (`1.0` today). Empty or newer major -> `SCHEMA_VERSION_UNSUPPORTED` |
| `minimum_engine_version` | Exported for documentation / future use; **not currently enforced** on Validate/Import |

Ship `schema_version: "1.0"` on all envelopes.

---

## 14. Normative authoring rules

Authoring MUST / SHOULD rules live in the **AI Authoring Contract**. Do not treat this Runtime section as a second constitution.

---

## 15. Complete end-to-end walkthrough

See **Complete Examples** in the AI Authoring Guide (canonical Minervini-style lifecycle). Worked JSON lives in Screener/Strategy Registry examples and the Trading Cookbook.


Practical tip: treat this page as one step in a larger workflow, not an isolated screen. Make one change at a time, verify the downstream effect, and use linked pages to complete the loop (for example: discovery → evaluation → recommendation → pending execution → review).

### Controls

- **Use with AI guide download** — This topic is included in /docs/stox-trading-artifacts-ai-guide.md (Download AI authoring guide on Screener/Strategy Registry).
- **Typical flow** — Open this page, verify active portfolio context in the header, perform one meaningful action, then confirm the reflected change in list/cards/history before leaving.
- **Validation and errors** — Form and API validations are shown as inline errors or toast messages. Fix the first reported issue, retry, and re-check dependent sections that consume the same data.


### Concepts

- **Soft min/max gates** — Failing scoring minimum/maximum zeros that factor contribution but keeps its weight in the denominator - dilutes overall; does not reject the stock.
- **Eligibility UNION** — Multiple eligibility_sources OR together; priority is sort order only; holdings always reviewed.
- **Explicit Screener params** — Omitted params use TechnicalIndicatorService fallbacks (often period 20), which may differ from catalogue UI defaults (e.g. EMA 50).
- **Active portfolio context** — Most data on this page is scoped by the selected portfolio profile; switching profile can completely change visible rows and metrics.
- **Data freshness** — Many analytics depend on cached daily OHLCV and scheduled sync jobs. If numbers look stale, refresh this page and verify sync status in admin tools.


### B. Contract-first maintenance

1. Add or change a MUST/SHOULD rule in the AI Authoring Contract.
2. Update the relevant Registry / Runtime reference section.
3. Regenerate static docs (`npm run docs:static`).
4. Do not leave reference prose that contradicts the Contract.

_End of StoX Trading Artifacts AI Authoring Guide._
