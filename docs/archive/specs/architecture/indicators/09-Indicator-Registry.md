# 09 — Indicator Registry (Target Architecture)

| Field | Value |
|-------|-------|
| **Document** | 09 — Indicator Registry |
| **Version** | 1.0 |
| **Status** | Accepted design (SD-033) — **foundation, façades, Admin UI, Liquidity/Tradability V1 implemented** |
| **Owner** | Architecture |
| **Depends On** | 03 Core Concepts, 04 System Architecture, 06 Engine Overview, [08 As-Built Analysis](./08-Indicator-Architecture-Analysis.md), [11 Trading Artifact Framework](./11-Trading-Artifact-Framework.md) |
| **Engine spec** | [../domains/Indicator-Registry-Specification.md](../domains/Indicator-Registry-Specification.md) |

---

# 1. Purpose

Define the **target** indicator architecture: a unified Indicator Registry that evolves today’s dual catalogues (`ScreenerCatalog` + `SupportedIndicators`) into one metadata/discovery layer while **preserving** calculation ownership.

**Framework placement (SD-034):** The Indicator Registry is the **Indicator specialization** of the [Trading Artifact Framework](./11-Trading-Artifact-Framework.md). Shared concerns (portable packages, cross-type dependencies, AI catalogue cards) are defined there; this document remains the Indicator type handbook.

This document is **architecture intent for the evolution**. As-built reality remains in [./08-Indicator-Architecture-Analysis.md](./08-Indicator-Architecture-Analysis.md) until implementation phases land.

---

# 2. Evolution, Not Replacement

```text
TODAY (as-built)                         TARGET (Registry)
─────────────────                        ─────────────────
ScreenerCatalog  ──┐                     Indicator Registry
SupportedIndicators┤  dual metadata  →   (single metadata SoT)
Stock/Market ad-hoc┘                            │
                                                ├─► ScreenerCatalog (façade)
                                                ├─► SupportedIndicators (façade)
                                                └─► Metric catalogue (façade)

TechnicalIndicatorService  ──────────── unchanged primary calculator
EvaluationEngine           ──────────── unchanged composite facts owner
StrategyConfigurationService::score ── unchanged weighted scoring
```

**Non-negotiables (SD-028 / SD-033):**

- No plugin runtime  
- No user formula editor  
- Indicators ship in application releases  

---

# 3. Target Architecture Diagram

```text
                         ┌──────────────────────────┐
                         │   Admin: Indicator       │
                         │   Registry UI            │
                         └────────────┬─────────────┘
                                      │ reads
                                      ▼
┌──────────────┐            ┌──────────────────────────┐
│ Market Data  │            │   Indicator Registry     │
│ (OHLCV, …)   │            │   metadata · deps ·      │
└──────┬───────┘            │   consumers · versions   │
       │                    └────────────┬─────────────┘
       │                                 │ discovery
       │                    ┌────────────┼────────────┐
       │                    ▼            ▼            ▼
       │              Screener     Strategy      Analytics
       │              (screenable) (scorable)    (metrics)
       │                    │            │            │
       ▼                    ▼            ▼            ▼
┌──────────────────┐  ┌───────────┐ ┌────────────┐ ┌─────────────┐
│ Technical        │  │ Screener  │ │ Evaluation │ │ Stock/Mkt/  │
│ IndicatorService │◄─┤ Evaluate  │ │ Engine     │ │ Portfolio   │
│ (+ RS Service)   │  └───────────┘ │ (composites)│ │ Analytics   │
└──────────────────┘                └──────┬─────┘ └─────────────┘
                                           │
                                           ▼
                                    Strategy::score
                                           │
                                           ▼
                                    Recommendation
```

---

# 4. Indicator Type System

| Type | Meaning | Calculator owner |
|------|---------|------------------|
| **Primary** | From market data / dedicated services | `TechnicalIndicatorService`, `RelativeStrengthService`, future dedicated services |
| **Composite** | From declared dependencies | `EvaluationEngine` (stock Strategy facts); `MarketAnalysisEngine` (market-level) |
| **Metric** | Descriptive analytics | Analytics services (SD-031); discoverable via Registry |

See engine spec §4–§5 for categories and full lists.

---

# 5. Registry Responsibilities

| Registry owns | Registry does not own |
|---------------|------------------------|
| Identity, labels, description | OHLCV fetch |
| Type, category, version | Screener condition trees |
| Parameters schema | Strategy weight normalisation |
| Dependencies + formula explanation | Recommendation decisions |
| Capability flags (screenable, …) | Alert holding-field registry (until opted-in) |
| Consumer declarations | Runtime code loading |

---

# 6. Consumer Discovery Flow

```text
Consumer (e.g. Screener Editor)
        │
        ▼
GET indicators?capability=screenable
        │
        ▼
Registry returns id, label, params, units, …
        │
        ▼
UI builds condition editor / Strategy table / Admin detail
```

After migration, Screener meta and Strategy catalogue endpoints are **projections** of the Registry.

---

# 7. Dependency & Formula Surfaces

Composites expose:

1. **Dependency tree** (Admin + API)  
2. **Formula explanation** (documentation prose; not executable)

Examples of planned trees:

```text
Liquidity Score                Tradability Score
├── Relative Turnover          ├── Gap Frequency
├── Average Daily Turnover     ├── Gap Fill Ratio
└── Average Daily Volume       └── Circuit Frequency
```

---

# 8. Admin UI Placement

```text
Admin
 └── Indicator Registry
      ├── List (filter by type / category / status / consumer)
      └── Detail
           ├── Metadata & capabilities
           ├── Parameters
           ├── Dependency tree
           ├── Formula explanation
           └── Consumers
```

---

# 9. Roadmap Summary

| Phase | Outcome |
|------:|---------|
| 0 | Specs + SD-033 (done with this document set) |
| 1 | Registry module seeded from catalogues; no behaviour change |
| 2 | Admin UI (read-only) |
| 3 | Consumers discover via Registry |
| 4–5 | Dependencies, explanations, definition versions on evidence |
| 6 | Planned Primaries + Liquidity / Tradability composites (code) |

**Separate track:** Strategy UI parameters → EvaluationEngine wiring (PB-054 / TD-19) — **not** Registry Phase 1.

---

# 10. Related Documents

- Full specification: [../domains/Indicator-Registry-Specification.md](../domains/Indicator-Registry-Specification.md)  
- Implementation plan: [./10-Indicator-Registry-Implementation-Plan.md](./10-Indicator-Registry-Implementation-Plan.md)  
- As-built analysis: [./08-Indicator-Architecture-Analysis.md](./08-Indicator-Architecture-Analysis.md)  
- Parent framework: [./11-Trading-Artifact-Framework.md](./11-Trading-Artifact-Framework.md) (SD-034)  
- Governance: [SD-033](../governance/SPECIFICATION_DECISIONS.md) · [SD-034](../governance/SPECIFICATION_DECISIONS.md)  
