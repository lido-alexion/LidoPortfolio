# Strategy Specification

| Field | Value |
|-------|-------|
| **Document** | Strategy Specification |
| **Version** | 1.1 |
| **Status** | Active (SD-027…SD-030 / SD-032) |
| **Canonical detail** | [`Strategy-Configuration-Specification.md`](./Strategy-Configuration-Specification.md) |

## Role

Strategy encodes investment philosophy: eligibility sources (Screeners),
scoring, thresholds, portfolio rules, capital allocation, exit rules, and
optional **market gates**.

## Market Analysis integration (SD-032)

Strategies may optionally reference Market Analysis Engine outputs via
`config.market_gates`:

| Field | Meaning |
|-------|---------|
| `enabled` | When true, Recommendation applies gates |
| `min_sentiment` | Block new entries below this sentiment |
| `allowed_phases` | Allow OPEN/INCREASE only in listed phases |
| `max_risk_raw` | Block / shrink when market raw risk exceeds |

Examples:

- Only buy when `Market Phase ∈ {Bull, Strong Bull}`
- Only buy when `Market Sentiment > 70`
- Reduce allocation when `Risk raw > 80`

Strategy never calculates market analytics; it only declares conditions.
Factory Momentum Strategy ships with `market_gates.enabled = false`.
