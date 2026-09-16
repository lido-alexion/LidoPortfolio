# Dashboard Specification

| Field | Value |
|-------|-------|
| **Document** | Dashboard Specification |
| **Version** | 1.1 |
| **Status** | Active (SD-031 / SD-032) |

## Purpose

Answer: **How is my portfolio and the market performing?**

## Primary data

- Portfolio Analytics (value, P&L, diversification, cash utilisation, average scores)
- Market Analytics from **Market Analysis Engine** (SD-032):
  - Sentiment (0–100 + label)
  - Market Phase + explainability
  - Trend / strength, Momentum, Volatility, Risk
  - Current / max drawdown
  - Distance from 200 DMA and 52-week high
  - Breadth (V1 proxy)
  - Last updated / as-of date

## Must not

- Individual stock research deep-dives (use Watchlist)
- Discovery candidate lists (use Discovery)
- Position management actions (use Portfolio / Holdings)
- Recalculate market metrics in the UI — consume API payloads only

## UI

Portfolio summary cards · Portfolio analytics cards · **Market analytics section**
(with explainability) · Allocation · Growth charts · Alerts · Strategy summary (link)
