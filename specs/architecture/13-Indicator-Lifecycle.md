# Indicator Lifecycle & Registry Implementation Notes

| Field | Value |
|-------|-------|
| **Document** | Indicator Lifecycle (as implemented) |
| **Version** | 1.0 |
| **Status** | Implemented (Epics 1–4 partial / Admin UI) |
| **Date** | 2026-07-30 |
| **Specs** | [Indicator-Registry-Specification.md](../engines/Indicator-Registry-Specification.md) · [09-Indicator-Registry.md](./09-Indicator-Registry.md) |

---

## 1. Lifecycle statuses

| Status | Meaning |
|--------|---------|
| `active` | Calculator (or descriptive metric) available; capabilities declare where it may be used |
| `stub` | Registered; calculation returns a neutral placeholder (e.g. market regime = 50) |
| `planned` | Metadata reserved; no calculator |
| `deprecated` | Kept for aliases/history; not offered to new consumers |

**Transition path:** `planned` → (`stub` optional) → `active` → `deprecated`.

Admin UI and `GET /api/v1/indicators` expose status; filters allow browsing by lifecycle stage.

---

## 2. Definition ownership

```text
Release ships code
        │
        ▼
ScreenerPrimarySeed / StrategyCompositeSeed / Factory metrics
        │
        ▼
IndicatorRegistryFactory → IndicatorRegistry (singleton)
        │
        ├─► ScreenerCatalog (façade / projector)
        ├─► SupportedIndicators (façade / projector; strategy_scorable only)
        └─► Admin UI + /api/v1/indicators*
```

- **No plugins / no formula editor** (SD-028).
- Formula explanation is **documentation only**.
- Calculators remain in `TechnicalIndicatorService`, `LiquidityTradabilityCalculator`, EvaluationEngine, analytics services.

---

## 3. Capability gates

| Capability | Effect |
|------------|--------|
| `screenable` | Appears in Screener catalogue / conditions |
| `strategy_scorable` | Appears in Strategy weights catalogue |
| `evaluation_fact` | Declared as Evaluation evidence fact (when wired) |
| `needs_volume` | Screener warns when volume missing |

Liquidity/Tradability **composites** are `active` but **`strategy_scorable=false`** so saved strategies and Recommendation stay unchanged.

---

## 4. Liquidity / Tradability (V1)

### Primaries (screenable)

| ID | Calculator |
|----|------------|
| `average_volume` | Volume SMA alias |
| `average_turnover` | SMA(close × volume) |
| `relative_turnover` | short ADV turnover / baseline ADV turnover (self-relative) |
| `gap_frequency` | Opening gaps / sessions |
| `gap_fill_ratio` | Filled gaps / gaps |
| `circuit_frequency` | OHLCV heuristic (large move + locked range) |
| `circuit_risk` | 0–100 from frequency + move size |

### Composites (Registry + calculator service; not Strategy)

| ID | Depends on |
|----|------------|
| `liquidity_score` | relative_turnover, average_turnover, average_volume |
| `tradability_score` | gap_frequency, gap_fill_ratio, circuit_frequency, **circuit_risk** |

Service: `App\Services\Indicators\LiquidityTradabilityCalculator`.

**Not wired into:** Recommendation Engine, Evaluation equal-weight ranking, Strategy factory defaults.

**Tagged consumers for future use:** Screener (primaries), Discovery, Dashboard, Stock Details, Admin Registry.

---

## 5. Data spike findings (Story 4.0)

| Indicator | OHLCV alone? | V1 decision |
|-----------|--------------|-------------|
| Average Daily Volume / Turnover | Yes | Active |
| Relative Turnover | Needs baseline | Self-relative short/long (universe/benchmark deferred) |
| Gap Frequency / Fill | Yes (open vs prior close) | Active |
| Circuit Frequency / Risk | No official feed | Heuristic stub-quality but `active` with documented limits |

---

## 6. Admin discovery

- UI: `/settings/indicators` and `/settings/indicators/:id` (admin-only)
- API: see [Indicator-Registry-API.md](../engines/Indicator-Registry-API.md)
