# Indicator Registry Specification

| Field | Value |
|-------|-------|
| **Document** | Indicator Registry Specification |
| **Version** | 1.0 |
| **Status** | Accepted design (SD-033) — **partially implemented** (Registry, façades, Admin UI, Liquidity/Tradability V1 calculators; Strategy/Recommendation wiring deferred) |
| **Owner** | Architecture |
| **Depends On** | Screener Specification, Strategy Configuration, Evaluation Engine, Analytics Architecture (SD-031), Market Analysis Engine (SD-032), SD-028, Trading Artifact Framework (SD-034) |
| **As-built baseline** | [../indicators/08-Indicator-Architecture-Analysis.md](../indicators/08-Indicator-Architecture-Analysis.md) |
| **Architecture overview** | [../indicators/09-Indicator-Registry.md](../indicators/09-Indicator-Registry.md) |
| **Implementation plan** | [../indicators/10-Indicator-Registry-Implementation-Plan.md](../indicators/10-Indicator-Registry-Implementation-Plan.md) |

---

# 1. Purpose

Evolve the **existing** indicator architecture into a unified **Indicator Registry** — a single metadata and discovery layer — **without** replacing calculation engines or introducing plugins.

**Placement:** Indicator type specialization of the
[Trading Artifact Framework](./Trading-Artifact-Framework-Specification.md) (SD-034).

This specification defines:

- Registry responsibilities and boundaries
- Indicator types, categories, and metadata model
- Dependency and consumer models
- Relationship to `ScreenerCatalog`, `TechnicalIndicatorService`, `SupportedIndicators`, and `EvaluationEngine`
- Admin UI (Indicator Registry page)
- Planned new indicators (metadata only)
- Implementation notes: [../indicators/13-Indicator-Lifecycle.md](../indicators/13-Indicator-Lifecycle.md), [./Indicator-Registry-API.md](./Indicator-Registry-API.md), [../indicators/14-Indicator-Registry-Diagrams.md](../indicators/14-Indicator-Registry-Diagrams.md)
- Extensibility roadmap
- Explicit non-goals and known architectural bugs tracked separately

**Implementation status:** Documentation / design only. No code changes are implied by accepting this document until a release implements SD-033 phases.

---

# 2. Design Principles

1. **Preserve calculation architecture.**  
   `TechnicalIndicatorService` remains the primary OHLCV calculator.  
   `EvaluationEngine` remains responsible for Strategy composite **facts** (0–100 scores).  
   `StrategyConfigurationService::score()` remains responsible for weighted Strategy overall.

2. **No plugin architecture.**  
   Continue **SD-028**: indicators ship only via application releases. No runtime-loaded indicators, no user-defined expression engine, no EAV formula store.

3. **Registry is metadata + discovery, not a second calculator.**  
   The Registry does not recompute RSI or Momentum Score. It describes what exists, how it is configured, what it depends on, and who consumes it.

4. **Evolve catalogues; do not delete them.**  
   `ScreenerCatalog` and `SupportedIndicators` continue to exist. They shall **derive from or synchronize with** the Registry rather than duplicate authoritative metadata indefinitely.

5. **Configuration without a formula engine.**  
   Composite weights, thresholds, lookbacks, and enable/disable of components may be configuration-driven. Calculation **logic** stays in code.

6. **Explicit dependencies.**  
   Every Composite must declare `depends_on`. Metrics and Primaries declare dependencies only when they compose other registered IDs.

7. **Consumers query the Registry.**  
   Screener, Strategy, Dashboard, analytics surfaces, etc. discover availability and capabilities through the Registry instead of maintaining parallel hardcoded lists (after migration).

---

# 3. Relationship to Existing Components

```text
┌─────────────────────────────────────────────────────────────────┐
│                    Indicator Registry (NEW)                     │
│         Single source of truth — metadata & discovery           │
└────────────┬────────────────────┬───────────────────┬───────────┘
             │ derives / syncs    │ derives / syncs   │ documents
             ▼                    ▼                   ▼
      ScreenerCatalog      SupportedIndicators    Metric defs
      (screenable IDs)     (Strategy composites)  (analytics)
             │                    │
             ▼                    ▼
   TechnicalIndicatorService   EvaluationEngine
   (Primary calc)              (Composite facts)
             │                    │
             └────────┬───────────┘
                      ▼
              StrategyConfigurationService::score
                      ▼
              Recommendation / Dashboard / UI
```

| Component | Role after Registry | Must change? |
|-----------|---------------------|--------------|
| `TechnicalIndicatorService` | Unchanged calculator | Implement new Primaries when scheduled; keep `match` or optional calculators later |
| `ScreenerCatalog` | Façade / projection of Registry entries with `screenable=true` | Evolve to read Registry |
| `SupportedIndicators` | Façade / projection of Registry Composites used by Strategy | Evolve to read Registry |
| `EvaluationEngine` | Compute Composite facts per declared deps + code | New composites when scheduled; **not** Strategy-param wiring in this SD |
| `RelativeStrengthService` | Remains calculator for raw RS | Registry documents RS Primary |
| Market / Stock Analytics | Consume Registry for discoverability; keep own owners (SD-031/032) | Query Registry for meta; calc stays in engines/services |

---

# 4. Indicator Types

Formalize **three** types.

## 4.1 Primary

Calculated directly from market data (OHLCV, corporate actions, exchange events, or dedicated services such as Relative Strength).

**Examples (existing):** RSI, EMA, SMA, ATR, Volume, Volume SMA, MACD line, Close, High 52w, Relative Strength (raw 1m/3m/6m).

**Examples (planned — metadata only):** Average Daily Turnover, Relative Turnover, Gap Frequency, Gap Fill Ratio, Circuit Frequency, Circuit Risk, Average Daily Volume.

## 4.2 Composite

Calculated from one or more **Primary** and/or **Composite** indicators. Must declare dependencies. Scoring logic remains in code (`EvaluationEngine` or a dedicated composite calculator invoked by Evaluation).

**Examples (existing):** Momentum Score, Trend Score, Breakout Score, Volume Score, Risk Score, Market Regime, Sector Strength.

**Examples (planned — metadata only):** Liquidity Score, Tradability Score.

**Also (market-level composites):** Market Trend, Market Momentum, Market Volatility, Market Risk, Sentiment, Market Phase, Market Health (presentation aggregate) — registered as Composites with `market_level=true`.

## 4.3 Metric

Descriptive analytics that are **not** treated as Screener technical indicators or Strategy scoring factors by default, but must remain discoverable.

**Examples (existing / SD-031):** Distance from 52-week High/Low, Beta (proxy), Historical Volatility, Average Daily Volume (when used descriptively), Market Cap (when available), Liquidity rating labels.

Metrics may be `screenable=false` by default; product may promote a Metric to Primary later via a release if Screener support is required.

---

# 5. Indicator Categories

Future indicators SHALL fit one of these categories (extensible by release, not by runtime):

| Category ID | Label | Typical contents |
|-------------|-------|------------------|
| `trend` | Trend | SMA, EMA, trend scores, MA spreads |
| `momentum` | Momentum | RSI, ROC, Stochastic, MACD, momentum score |
| `volume` | Volume | Volume, volume SMA/ratio, volume score |
| `liquidity` | Liquidity | Turnover, relative turnover, liquidity score |
| `tradability` | Tradability | Gap/circuit stats, tradability score |
| `risk` | Risk | ATR-based risk, circuit risk, risk score |
| `volatility` | Volatility | ATR, Bollinger, HV |
| `relative_performance` | Relative Performance | Relative strength (raw and scored) |
| `market` | Market | Regime, breadth, sentiment, phase |
| `price` | Price | OHLC, change %, 52w high/low, range % |
| `descriptive` | Descriptive | Distances, beta, market cap, ratings |

`SupportedIndicators` categories today (Momentum, Trend, Volume, Market, Risk) map into this set.

---

# 6. Metadata Model

Each registered indicator SHALL define the following fields (names are logical; storage is code/config in early phases, not necessarily a DB table).

| Field | Required | Description |
|-------|----------|-------------|
| `id` | Yes | Stable internal string ID (e.g. `rsi`, `momentum_score`, `liquidity_score`) |
| `display_name` | Yes | UI label |
| `description` | Yes | Human explanation of purpose |
| `type` | Yes | `primary` \| `composite` \| `metric` |
| `category` | Yes | Category ID from §5 |
| `version` | Yes | Semver or integer schema version of **definition** (metadata + documented formula behaviour) |
| `depends_on` | Conditional | List of indicator IDs; **required** for `composite`; optional for others |
| `parameters` | Yes | Schema: id, label, type, default, min, max, step (may be empty) |
| `units` | Yes | e.g. `ratio`, `percent`, `price`, `score_0_100`, `count`, `currency`, `none` |
| `precision` | Yes | Suggested decimal places for display |
| `visible` | Yes | Shown in Admin Registry and docs |
| `screenable` | Yes | Eligible for Screener condition operands |
| `chartable` | Yes | Eligible for chart overlays / series |
| `sortable` | Yes | Eligible as sort key in tables |
| `filterable` | Yes | Eligible as filter dimension |
| `supports_history` | Yes | Full historical series can be produced |
| `market_level` | Yes | Applicable to benchmark / market context |
| `stock_level` | Yes | Applicable to individual securities |
| `portfolio_level` | Yes | Applicable as portfolio aggregate |
| `consumers` | Yes | Declared consumer IDs (see §8) |
| `status` | Yes | `active` \| `stub` \| `planned` \| `deprecated` |
| `formula_explanation` | Conditional | Required for composites (markdown/prose); documentation only |
| `aliases` | Optional | Legacy keys (e.g. `momentum` → `momentum_score`) |
| `capabilities` | Optional | Extra flags (e.g. `needs_volume`, `supports_maximum` for Strategy gates) |

### Capability flags (examples)

- `needs_volume` — requires volume field on bars  
- `supports_maximum` — Strategy scoring supports maximum gate (risk-style)  
- `strategy_scorable` — appears in Strategy scoring model  
- `evaluation_fact` — emitted in Evaluation evidence  

---

# 7. Dependency Model

## 7.1 Rules

1. Composites **must** declare `depends_on` as an ordered or unordered list of Registry IDs.
2. Dependencies may include Primaries, Metrics, or other Composites (DAG only — **no cycles**).
3. Stubs (e.g. current Market Regime / Sector Strength) declare `depends_on: []` and `status: stub` until real models ship.
4. The Admin UI and APIs expose the dependency tree for every Composite.
5. Changing a dependency list requires a **version bump** of the composite definition.

## 7.2 Existing composite dependencies (as-built → declared)

| Composite | Depends on |
|-----------|------------|
| `momentum_score` | `rsi` |
| `trend_score` | `close`, `sma` (parameterised fast/slow) |
| `volume_score` | `volume_ratio` |
| `risk_score` | `atr`, `close` |
| `breakout_score` | Discovery pattern evidence (logical id `discovery_pattern_count` if registered as Metric) |
| `relative_strength` (score) | `relative_strength_3m` (raw Primary from RelativeStrengthService) |
| `market_regime` | *(none — stub)* |
| `sector_strength` | *(none — stub)* |

## 7.3 Planned composite dependencies

### Liquidity Score (`liquidity_score`)

**Purpose:** Summarise how easily a stock can be traded relative to size and activity — for Strategy scoring and research, not eligibility alone.

**Depends on:**

```text
liquidity_score
├── relative_turnover
├── average_turnover      (Average Daily Turnover)
└── average_volume        (Average Daily Volume)
```

### Tradability Score (`tradability_score`)

**Purpose:** Summarise microstructure / event friction (gaps, circuits) that affect reliable execution and stop placement.

**Depends on:**

```text
tradability_score
├── gap_frequency
├── gap_fill_ratio
└── circuit_frequency
```

*(Optional later: also `circuit_risk`.)*

## 7.4 Visualization (Admin)

Every Composite detail view SHALL render a dependency tree (indented list or DAG). Example:

```text
Liquidity Score
├── Relative Turnover
├── Average Daily Turnover
└── Average Daily Volume
```

---

# 8. Consumer Model

## 8.1 Registered consumers

| Consumer ID | Surface |
|-------------|---------|
| `screener` | Screener editor, runs, backtests |
| `strategy` | Strategy scoring model / gates |
| `evaluation` | EvaluationEngine fact emission |
| `recommendation` | RecommendationGenerationPipeline (via Evaluation facts) |
| `discovery` | Discovery Engine (indirect via Screener hits / patterns) |
| `dashboard` | Dashboard portfolio + market widgets |
| `market_analytics` | Market Analysis Engine / Market Analytics APIs |
| `portfolio_analytics` | PortfolioAnalyticsService |
| `stock_details` | Stock / watchlist research analytics |
| `alerts` | Alert policies (only where Registry metrics/fields apply) |
| `admin_registry` | Admin Indicator Registry UI |

## 8.2 Discovery rule

Consumers SHALL obtain:

- available indicator IDs  
- metadata (labels, params, capabilities)  
- screenable / strategy_scorable subsets  

…from the **Registry** (or a thin façade that is Registry-backed), not from independently maintained duplicate lists.

**Migration:** Until code lands, `ScreenerCatalog` and `SupportedIndicators` remain the de-facto sources; they are the first façades to point at the Registry.

## 8.3 Alerts note

V1 Alerts use `HoldingFieldRegistry` (position/price fields). Alerts become Registry consumers only for fields/metrics that are registered and marked for alert use — not a wholesale merge of holding columns into technical indicators.

---

# 9. Formula Explanation

Every Composite SHALL expose `formula_explanation` (documentation).

- Initially **documentation only** — no formula editor, no expression language.
- Explain inputs, mapping bands / weights / thresholds at a conceptual level.
- Example (Momentum Score, as-built behaviour):

> Momentum Score maps RSI into a 0–100 fact: RSI in [45, 70] → 100; RSI > 70 → 55; RSI < 30 → 35; otherwise 50. Used by Strategy with configurable weight and minimum gate.

Planned composites document **intended** purpose and dependencies; exact numeric mapping is deferred until implementation.

---

# 10. Configuration Model

## 10.1 Allowed configuration

Where practical, Composites may expose config for:

- Component weights  
- Thresholds / bands  
- Lookback periods  
- Enable / disable of a component contribution  

Stored in Strategy `config_json` parameters and/or `trading_os` config, consistent with existing Strategy UI.

## 10.2 Forbidden

- Generic formula engines  
- User-editable arbitrary expressions  
- Runtime plugin loading of indicator code  

Calculation remains in application code (SD-028).

---

# 11. Versioning

| Concept | Rule |
|---------|------|
| `version` on Registry entry | Bumped when definition metadata, dependencies, or documented calculation behaviour changes |
| Evaluation evidence | Future: store `indicator_definition_versions` map (id → version) on evidence / recommendation snapshots |
| Backtests | Future: resolve series using the definition version active at as-of date or pinned in backtest config |
| Strategy versions | Continue to snapshot weights/gates; Registry versions complement, do not replace, Strategy version FKs |

---

# 12. Planned New Indicators (metadata only)

**Do not implement calculation formulas in the first Registry release unless explicitly scheduled.** Specs record purpose and dependencies only.

## 12.1 Primary (planned)

| ID | Display name | Category | Purpose |
|----|--------------|----------|---------|
| `average_turnover` | Average Daily Turnover | `liquidity` | Typical daily traded value (price × volume average) for liquidity assessment |
| `relative_turnover` | Relative Turnover | `liquidity` | Stock turnover vs peer/benchmark/universe baseline |
| `average_volume` | Average Daily Volume | `volume` / `liquidity` | Mean share volume over lookback (may alias/extend `volume_sma`) |
| `gap_frequency` | Gap Frequency | `tradability` | Rate of open gaps over lookback |
| `gap_fill_ratio` | Gap Fill Ratio | `tradability` | Fraction of gaps that fill within a defined window |
| `circuit_frequency` | Circuit Frequency | `tradability` | Rate of exchange circuit hits / limit moves |
| `circuit_risk` | Circuit Risk | `risk` | Severity/risk score derived from circuit behaviour |

All planned Primaries: `status: planned`, `screenable` TBD per release, `stock_level: true`.

## 12.2 Composite (planned)

| ID | Display name | Category | Depends on | Purpose |
|----|--------------|----------|------------|---------|
| `liquidity_score` | Liquidity Score | `liquidity` | `relative_turnover`, `average_turnover`, `average_volume` | 0–100 liquidity quality for research / Strategy |
| `tradability_score` | Tradability Score | `tradability` | `gap_frequency`, `gap_fill_ratio`, `circuit_frequency` | 0–100 execution friction / tradability quality |

---

# 13. Indicator Registry UI (Admin)

## 13.1 Navigation

**Admin → Indicator Registry**

(Exact route TBD at implementation, e.g. `/settings/indicators` or `/admin/indicators` — admin-only.)

## 13.2 List view

Columns / fields:

- Name (`display_name`)  
- Category  
- Type (`primary` / `composite` / `metric`)  
- Description (truncated)  
- Version  
- Status  
- Capabilities (icons/chips: Screenable, Chartable, Strategy, Stub, Planned)  
- Consumers (count or chips)  

Filters: type, category, status, screenable, strategy_scorable, consumer.

## 13.3 Detail view

On click, show:

- Full metadata (§6)  
- Parameters table  
- Dependencies tree (composites)  
- Formula explanation (composites)  
- Consumers list  
- Version / status  
- Links to related docs / Strategy / Screener guide topics  

## 13.4 Non-goals for V1 Registry UI

- Editing calculation formulas  
- Adding indicators from the UI (still release-shipped)  
- Running live recalculation of all universe values from this page (optional later)

---

# 14. APIs (design intent)

Future additive APIs (names indicative):

| Endpoint | Purpose |
|----------|---------|
| `GET /api/v1/indicators` | List Registry entries (filterable) |
| `GET /api/v1/indicators/{id}` | Detail + dependency tree + formula explanation |
| `GET /api/v1/indicators/meta` | Compact meta for consumers (may replace dual meta endpoints long-term) |

Existing:

- `GET /api/screeners/meta` — shall become Registry-backed for indicator portion  
- Strategy catalogue endpoint — shall become Registry-backed for scoring indicators  

---

# 15. Extensibility Roadmap

| Phase | Scope | Notes |
|------:|-------|-------|
| **0** | Specs + SD-033 (this document) | No code |
| **1** | Registry code module + seed from existing catalogues | Façades; no behaviour change |
| **2** | Admin Indicator Registry UI | Read-only first |
| **3** | Wire consumers to Registry discovery | Screener meta, Strategy catalogue |
| **4** | Declare dependencies + formula_explanation on all composites | Including stubs |
| **5** | Persist definition versions on Evaluation / Recommendation evidence | Backtest readiness |
| **6** | Implement planned Primaries + Liquidity / Tradability composites | Separate delivery; formulas in code |
| **Later** | Optional `IndicatorCalculator` extraction behind TI | Still release-shipped, not plugins |
| **Later** | User-defined Strategy composites as **weighted combinations of registered IDs only** | Still no free expressions (SD-028) |

**Out of band (not part of Registry phases):** Wire Strategy UI parameters into EvaluationEngine — see §16 / PB / TD.

---

# 16. Architectural Bug (tracked separately)

### Strategy parameters ignored by Evaluation

**Symptom:** Strategy scoring indicator `parameters` (e.g. `rsi_period`, `lookback_days`, `sma_fast` / `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`) are editable in Strategy UI and persisted in `config_json`, but `EvaluationEngine` reads periods from `config/trading_os.php` (`evaluation.*`) and ignores Strategy parameters.

**Decision:** Document and track as a **separate** architectural improvement. **Do not** fold the fix into Indicator Registry Phase 1–3.

**Tracking:** SD-033 companion backlog item **PB-054**; technical debt **TD-19**.

---

# 17. Acceptance Criteria (when implementation starts)

1. Registry is the authoritative metadata source for Primaries, Composites, and Metrics listed as `active` or `stub`.  
2. `ScreenerCatalog` / `SupportedIndicators` do not diverge — they project Registry data.  
3. Every Composite has `depends_on` + `formula_explanation`.  
4. Admin list + detail pages show metadata, dependency tree, consumers, version, status.  
5. No plugin loader; no user formula editor.  
6. Existing Screener runs, Evaluation, and Recommendation behaviour remain unchanged until an explicit behaviour-change release.  
7. Planned indicators exist in Registry as `planned` without requiring calculators.  
8. Strategy-param → Evaluation wiring is **not** required to close Registry Phase 1.

---

# 18. Related Documents

- [09 — Indicator Registry (Architecture)](../indicators/09-Indicator-Registry.md)  
- [08 — Indicator Architecture Analysis (As-Built)](../indicators/08-Indicator-Architecture-Analysis.md)  
- [Screener Specification](./Screener-Specification.md)  
- [Strategy Configuration Specification](./Strategy-Configuration-Specification.md)  
- [Evaluation Engine Specification](./Evaluation-Engine-Specification.md)  
- [Analytics Architecture Specification](../portfolio/Analytics-Architecture-Specification.md)  
- [Market Analysis Engine Specification](./Market-Analysis-Engine-Specification.md)  
- [SD-028 / SD-033](../governance/SPECIFICATION_DECISIONS.md)  
- [PRODUCT_BACKLOG](../governance/PRODUCT_BACKLOG.md) (PB-054, PB-055+)  
