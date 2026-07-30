# Indicator Registry — Diagrams (as implemented)

| Field | Value |
|-------|-------|
| **Document** | Indicator Registry diagrams |
| **Version** | 1.0 |
| **Date** | 2026-07-30 |
| **Companion** | [09-Indicator-Registry.md](./09-Indicator-Registry.md) · [13-Indicator-Lifecycle.md](./13-Indicator-Lifecycle.md) |

---

## 1. Class diagram (core)

```mermaid
classDiagram
    class IndicatorRegistry {
        +register(def)
        +get(id) IndicatorDefinition
        +filter(criteria) list
        +search(q, criteria) list
        +dependencyTree(id) dict
        +dependencyTreeDetailed(id) dict
        +validateDependencies() list
    }
    class IndicatorDefinition {
        +id
        +type
        +category
        +status
        +dependsOn
        +parameters
        +consumers
        +capabilities
        +formulaExplanation
        +toArray()
    }
    class IndicatorRegistryFactory {
        +make() IndicatorRegistry
    }
    class ScreenerPrimarySeed
    class StrategyCompositeSeed
    class ScreenerCatalogueProjector
    class StrategyCatalogueProjector
    class LiquidityTradabilityCalculator {
        +averageTurnoverSeries()
        +relativeTurnoverSeries()
        +gapFrequencySeries()
        +gapFillRatioSeries()
        +circuitFrequencySeries()
        +circuitRiskSeries()
        +liquidityScore()
        +tradabilityScore()
    }
    class TechnicalIndicatorService {
        +evaluate()
        +evaluateSeries()
    }
    class IndicatorRegistryController {
        +index()
        +meta()
        +show()
    }

    IndicatorRegistryFactory --> ScreenerPrimarySeed
    IndicatorRegistryFactory --> StrategyCompositeSeed
    IndicatorRegistryFactory --> IndicatorRegistry : builds
    IndicatorRegistry --> IndicatorDefinition : contains
    ScreenerCatalogueProjector --> IndicatorRegistry
    StrategyCatalogueProjector --> IndicatorRegistry
    TechnicalIndicatorService --> LiquidityTradabilityCalculator
    IndicatorRegistryController --> IndicatorRegistry
```

---

## 2. Component diagram

```mermaid
flowchart TB
    AdminUI["Admin UI\n/settings/indicators"]
    API["IndicatorRegistryController\n/api/v1/indicators*"]
    Reg["IndicatorRegistry"]
    FacS["ScreenerCatalog façade"]
    FacT["SupportedIndicators façade"]
    TI["TechnicalIndicatorService"]
    LTC["LiquidityTradabilityCalculator"]
    Eval["EvaluationEngine"]
    Strat["Strategy::score"]
    Rec["RecommendationEngine"]

    AdminUI --> API --> Reg
    FacS --> Reg
    FacT --> Reg
    TI --> LTC
    FacS -.->|screenable primaries| TI
    FacT -.->|strategy_scorable only| Eval
    Eval --> Strat --> Rec

    LTC -.->|composites available\nNOT wired| Eval
    LTC -.->|NOT wired| Rec
```

---

## 3. Registry / consumer diagram

```text
                    ┌─────────────────────────────┐
                    │     Indicator Registry      │
                    │  metadata · deps · version  │
                    └──────────────┬──────────────┘
           ┌───────────────┬───────┼────────┬─────────────┐
           ▼               ▼       ▼        ▼             ▼
      Screener        Discovery  Dashboard  Stock      Admin UI
   (screenable        (tagged    (tagged    Details    (read-only)
    primaries)         future)    future)   (tagged)
           │
           ▼
   TechnicalIndicatorService
           │
           └── LiquidityTradabilityCalculator

Strategy / Evaluation / Recommendation
  ← only existing strategy_scorable composites
  ← Liquidity/Tradability composites EXCLUDED
```

---

## 4. Dependency graph (Liquidity / Tradability)

```mermaid
flowchart BT
    AV[average_volume]
    AT[average_turnover]
    RT[relative_turnover]
    GF[gap_frequency]
    GFR[gap_fill_ratio]
    CF[circuit_frequency]
    CR[circuit_risk]
    LS[liquidity_score]
    TS[tradability_score]

    AV --> LS
    AT --> LS
    RT --> LS
    GF --> TS
    GFR --> TS
    CF --> TS
    CR --> TS
```

---

## 5. Indicator lifecycle (simplified)

```mermaid
stateDiagram-v2
    [*] --> planned
    planned --> stub: optional neutral calc
    planned --> active: calculator shipped
    stub --> active: real calc
    active --> deprecated: superseded
    deprecated --> [*]
```
