# Strategy Configuration Specification

| Field | Value |
|-------|-------|
| **Document** | Strategy Configuration Specification |
| **Version** | 2.0 |
| **Status** | Active (SD-027 / SD-028 / SD-029 / SD-030) |
| **Owner** | Architecture |
| **Depends On** | Evaluation Engine, Screener, Recommendation Engine, Market Analysis Engine |

---

# 1. Purpose

Strategy is the **investment philosophy**: scoring, portfolio rules,
capital allocation, exits, and thresholds.

**Eligibility is not owned by Strategy.** Screeners select candidates;
Strategy decides what to do with them (SD-030).

``` text
Screeners → Candidate stocks → Strategy scoring / rules → Recommendations
```

---

# 2. Design Principles

- Fixed supported scoring indicators (SD-028) — no plugins / user-defined formulas.
- Default Minervini Strategy seeded once; fully editable in place (SD-029 amended).
- Strategies **consume Screeners by reference** — no duplicated eligibility rules (SD-030).
- Enabled scoring weights must sum to **exactly 100** (auto-normalised on save; relative proportions kept).
- One editable strategy per portfolio; Save updates in place.
- Recommendation Engine never evaluates raw Screener conditions.

---

# 3. Architecture

``` text
Market Data
    ↓
Evaluation Engine (facts only)
    ↓
Screeners (eligibility)
    ↓
Candidate stocks
    ↓
Strategy (scoring, portfolio, allocation, exit)
    ↓
Recommendation Engine
    ↓
Capital Allocation → Trade Recommendations
```

---

# 4. Strategy Sections

1. **Eligibility Sources** — one or more Screener IDs (enabled, priority). Union of hits.
2. **Scoring Model** — weighted catalogue factors (Relative Strength, Momentum, Trend, …).
3. **Recommendation Thresholds** — open / increase / reduce / exit / watch.
4. **Portfolio Rules** — size caps, cash deployment, averaging flags.
5. **Capital Allocation** — method, score bands, tie-break.
6. **Exit Strategy** — declarative exit rules on holdings (MA breakdown, RS/trend weakening, max loss, ATR stop, …).
7. **Cash Management** — reservation behaviour flags.

---

# 5. Configuration Model

Versioned `config_json` on Strategy Version:

- `eligibility_sources[]` — `{ screener_id, enabled, priority, … }` (no condition copy)
- `indicators` / `scoring_model` — scoring catalogue rows
- `thresholds`, `portfolio_rules`, `capital_allocation`, `cash_rules`
- `exit_strategy` — `{ enabled, mode, rules[] }`
- `recommendation_behaviour`

Junction table: `portfolio_tos_strategy_screeners` (version ↔ screener).

---

# 6. Default Strategy

| Field | Value |
|-------|-------|
| Name | Minervini Strategy |
| Eligibility | **Minervini Trend Template** Screener |
| Editable | Yes — Save updates config in place |

Scoring weights, thresholds, portfolio/capital/cash defaults: see SD-029 / `FactoryMomentumStrategy`.

Exit defaults: MA breakdown, RS/trend weakening, score exit, max loss (ATR/trailing/screener exit optional).

---

# 7. Weight Validation

Enabled scoring weights must equal **100** after normalisation. The UI shows the live total; if it is not 100, Save (and optional **Normalise now**) scales enabled weights proportionally to 100 (2 d.p., largest-remainder). Disabled factors keep unused stored weights. Save is blocked only when no enabled factor has a positive weight.

---

# 8. Persistence

One active strategy per portfolio. Save overwrites the active version’s `config_json` (no user-facing versions or Duplicate). Recommendations may still store `strategy_version_id` for FK stability and `strategy_name` in evidence.

---

# 9. APIs

| Method | Path | Purpose |
|--------|------|---------|
| GET/PUT | `/api/v1/strategy` | Active strategy (PUT saves in place) |
| PUT | `/api/v1/strategy/screeners` | Assign eligibility Screeners |
| GET | `/api/v1/strategy/eligibility` | Eligibility sources |
| GET | `/api/v1/strategy/scoring` | Scoring model |
| GET | `/api/v1/strategy/exit` | Exit strategy |
| GET | `/api/v1/strategy/catalogue` | Indicator catalogue |
| GET | `/api/v1/strategy/summary` | Dashboard card |
| GET | `/api/v1/strategy/indicators` | Scoring (BC) |
| GET | `/api/v1/strategy/thresholds` | Thresholds |
| GET | `/api/v1/strategy/portfolio-rules` | Portfolio rules |
| GET | `/api/v1/strategy/capital-allocation` | Capital allocation |
| GET | `/api/v1/strategy/recommendation-rules` | Behaviour |

---

# 10. UI

**Strategy** (`/strategy`): General · Eligibility Sources · Scoring Model ·
Thresholds · Portfolio Rules · Capital Allocation · Exit Strategy · **Market Gates** ·
Cash · Summary.

Eligibility: select existing Screeners, enable/priority, **View conditions**
link to Screener editor (read-only from Strategy).

Optional `market_gates` consume Market Analysis Engine outputs (SD-032) —
see [`Strategy-Specification.md`](./Strategy-Specification.md).

---

# 11. Example

1. Factory Screener **Minervini Trend Template** defines trend eligibility.
2. Factory **Momentum Strategy** references that Screener.
3. After Screener runs, Recommendation scores only eligible hits (plus holdings for exits).
4. Explainability includes Screener PASS/FAIL + scoring breakdown + exit status.

---

# 12. V1 Non-goals

Custom scripting, plugins, user-defined indicators, nested Boolean formula
language beyond Screener groups, AI-generated strategies.
