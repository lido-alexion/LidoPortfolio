# Portfolio Analytics Specification

| Field | Value |
|-------|-------|
| **Document** | Portfolio Analytics Specification |
| **Version** | 1.1 |
| **Status** | Active (SD-031 / SD-032) |
| **Owner** | Architecture |

## Purpose

Produce **portfolio-wide** metrics only. Never recalculate stock Evaluation
scores or Market Analysis metrics.

## Owner

`App\Services\Analytics\PortfolioAnalyticsService`

## Outputs (examples)

- Portfolio value / P&L / XIRR (via PortfolioCalculationService)
- Diversification, concentration, largest position
- Cash available / reserved / utilisation
- Average Evaluation scores across holdings (read-only from Evaluation Engine)
- Allocation list

## Market context (SD-032)

Consumes Market Analysis Engine via `MarketAnalyticsService`:

``` json
"market_context": {
  "market_phase": "Bull",
  "sentiment_score": 82,
  "sentiment_label": "Very Bullish",
  "market_risk_label": "Low",
  "alignment_note": "..."
}
```

Portfolio reports should interpret performance in light of the current market
environment. Portfolio Analytics does **not** own market calculations.

## Caching

`portfolio_analytics_snapshots` (category `portfolio`), ~15 minute TTL.
