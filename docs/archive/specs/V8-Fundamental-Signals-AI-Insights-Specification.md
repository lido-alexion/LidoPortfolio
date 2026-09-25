# StoX V8 Fundamental Signals & AI Insights Engine Specification

| Field | Value |
|---|---|
| **Epic** | V4-FEAT-062 |
| **Status** | FROZEN / IMPLEMENTATION-READY |
| **Canonical path** | `specs/V8-Fundamental-Signals-AI-Insights-Specification.md` |
| **Parent register** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Depends on** | V4-FEAT-054 — Historical Fundamental Data Bootstrap |

## 1. Product intent

StoX SHALL use AI to surface **what deserves investor attention**, not to convert already-visible tables into prose.

The feature SHALL focus on four investor outcomes:

1. a concise one- or two-line fundamental summary;
2. material positive signals;
3. material negative/risk signals;
4. unusual or unresolved watch items with explicit follow-up checks.

Routine facts that are already obvious from the UI SHOULD NOT be repeated unless they are required to explain a surfaced signal.

Core principle:

> **If something is normal, do not mention it. If something is unusual, explain why it matters and what evidence supports the observation.**

The feature is an intelligent first-pass filter, not an all-in-one research terminal and not an investment-advice engine.

## 2. Architecture principle — deterministic first, LLM second

StoX SHALL calculate everything that can be calculated deterministically. The LLM SHALL spend its reasoning budget on interpretation, relationship detection, anomaly explanation and identifying missing evidence.

StoX-owned deterministic processing SHOULD include, where applicable:

- YoY/QoQ/TTM/annual changes;
- CAGR;
- margins and margin changes;
- valuation ratios;
- leverage and coverage ratios;
- cash-conversion relationships;
- receivables growth versus revenue growth;
- inventory growth versus sales/revenue growth;
- debt and cash movement;
- net-debt movement under the canonical StoX definition;
- CWIP movement and scale relationships;
- dilution/share-count movement;
- promoter/FII/DII/public holding changes;
- price position and technical calculations where data exists;
- deviations from company history;
- peer/sector percentiles when reliable comparison data exists;
- rule-based threshold breaches.

The LLM MUST NOT be relied upon as the authoritative calculator for financial ratios or arithmetic that StoX can compute itself.

## 3. Fundamental Signals Engine

The deterministic layer SHALL produce a normalized signal/evidence payload for the AI layer.

Each candidate signal SHOULD carry enough metadata to make the result auditable, for example:

```json
{
  "signal_key": "receivables_growth_vs_revenue",
  "metric_values": {
    "receivables_yoy_pct": 46.2,
    "revenue_yoy_pct": 8.1,
    "spread_pp": 38.1
  },
  "period": "FY2026",
  "historical_context": null,
  "peer_context": null,
  "deterministic_severity": null,
  "benchmark_available": false
}
```

The catalogue SHALL distinguish:

- **deterministic alerts** — rule/threshold/trend is sufficient to classify;
- **candidate observations** — mathematically notable but require interpretation;
- **context-only facts** — may help interpretation but are not themselves positive/negative;
- **unresolved hypotheses** — unusual pattern exists, but structured data is insufficient to conclude why.

## 4. Initial signal catalogue

The initial implementation SHALL cover at least the following families where source data is available.

### 4.1 Growth and profitability

- revenue growth acceleration/deceleration;
- net-income/EPS divergence from revenue growth;
- operating-profit versus bottom-line divergence;
- abrupt margin expansion/compression;
- ROE/ROA/ROCE movement against company history;
- unusual dependence on other income/exceptional items where identified by structured data.

### 4.2 Cash-flow quality

- OCF versus net income;
- multi-period OCF/net-income deterioration or improvement;
- positive earnings with weak/negative OCF;
- FCF direction and persistence;
- capex intensity changes;
- cash generation versus reported profit.

### 4.3 Working capital

- receivables growing materially faster than revenue;
- inventory growing materially faster than sales;
- working-capital absorption/release anomalies;
- current-assets/current-liabilities changes where historically meaningful.

### 4.4 Balance sheet and solvency

- debt growth versus equity/cash growth;
- net-debt direction;
- debt/equity and net-debt/EBITDA threshold/trend signals;
- interest-coverage deterioration;
- liquidity/coverage changes;
- material share-count dilution.

Thresholds MUST be sector-aware where sector economics materially change interpretation. A universal threshold MUST NOT be used simply because it is easy to implement.

### 4.5 Capital work-in-progress / expansion

Where CWIP is available, StoX SHALL calculate changes such as:

- YoY CWIP growth;
- CWIP as a share of fixed assets/asset base where meaningful;
- multi-year persistence;
- relationship to capex and subsequent commissioning where available.

The LLM MUST treat unusually high/rising CWIP as **ambiguous**, not automatically positive. It may represent future capacity, but may also reflect delayed projects, cost overruns, capital inefficiency or accounting-quality concerns.

The output SHOULD explicitly request follow-up evidence when the structured data cannot resolve the interpretation.

### 4.6 Ownership/governance

- promoter holding changes;
- promoter pledge where available;
- FII/DII/public changes;
- unusual ownership changes combined with other financial signals.

Ownership movement alone MUST NOT automatically be classified as a strength or risk without supporting context.

### 4.7 Valuation and market context

- deviation from company historical valuation range;
- reliable sector/peer percentile where available;
- valuation divergence from earnings/cash-flow trends;
- price context as supporting information only.

Being near a 52-week high or low MUST NOT, by itself, be classified as a fundamental strength or risk.

## 5. Comparison hierarchy

When deciding whether something is unusual, StoX/AI SHOULD prefer comparison evidence in this order:

1. the company's own historical distribution/trend;
2. reliable sector/industry peers;
3. deterministic domain thresholds where appropriate;
4. generic qualitative judgment only when clearly justified.

The LLM MUST NOT label a standalone ratio as strong/weak/cheap/expensive merely from model memory when the required comparison evidence is absent.

## 6. AI output contract

The default investor-facing AI response SHOULD be short and signal-oriented, not a long fundamental report.

Recommended normalized contract:

```json
{
  "summary": "",
  "positive_signals": [],
  "risk_signals": [],
  "watch_items": [],
  "follow_up_checks": [],
  "data_sufficiency": {
    "rating": "high|medium|low",
    "missing_information": []
  }
}
```

Requirements:

- `summary` SHOULD be no more than two concise sentences;
- omit routine observations;
- each surfaced signal SHALL cite the underlying metric/change in plain language;
- the model SHALL distinguish **detected fact**, **interpretation**, and **unresolved hypothesis**;
- unresolved hypotheses belong in `watch_items`, not definitive positive/risk buckets;
- `follow_up_checks` SHALL say what additional evidence could confirm or rule out the hypothesis;
- no Buy/Sell/Hold, target-price or future-price prediction;
- valid structured JSON is preferred for backend consumption and UI rendering.

## 7. Follow-up research guidance

StoX does not need to ingest every possible research artifact in V8. When structured data reveals an unusual but unresolved pattern, the LLM SHOULD guide the investor to specific evidence outside StoX.

Examples:

- high/rising CWIP → annual-report CWIP notes, capex disclosures, project commissioning timelines, investor presentations, earnings-call commentary, auditor remarks;
- receivables rising faster than revenue → ageing schedule, customer concentration, bad-debt provisions, related-party notes, auditor commentary;
- inventory rising while sales slow → inventory composition, obsolescence, channel/inventory commentary;
- unusual other income → note disclosures and recurring versus one-off classification;
- abrupt margin improvement → input-cost changes, segment mix, accounting-policy changes, exceptional items;
- sharp debt increase → borrowing purpose, acquisitions/capex, maturity/refinancing schedule;
- promoter/ownership changes → block deals, pledge changes, dilution, corporate actions;
- OCF persistently below earnings → working-capital movements, receivables, inventory and cash-flow notes.

Follow-up guidance SHALL be framed as **what to check**, not as a conclusion about misconduct or future performance.

## 8. AI provider abstraction and failover

The AI integration SHALL use a provider-neutral service boundary. Business logic, signal generation and UI code MUST NOT depend directly on Gemini- or Codex-specific request formats.

Conceptual structure:

```text
FundamentalSignalsService
        -> AIInsightsService
              -> AIProvider interface
                    -> GeminiProvider
                    -> CodexProvider
                    -> future providers
```

### 8.1 Initial provider preference

Initial default configuration:

- **Primary:** Gemini
- **Secondary:** Codex

Both providers remain configured/active when available. `primary` means **first preference**, not exclusive usage.

For every eligible AI-insights request:

```text
try configured primary provider
    -> success: return validated response
    -> eligible failure: try configured secondary provider
        -> success: return validated response
        -> failure: return AI-insights-unavailable state
```

If Admin changes Codex to primary, the same logic reverses automatically: Codex is attempted first and Gemini becomes the fallback.

### 8.2 Failover semantics

Failover SHOULD occur for provider/inference failures such as:

- rate-limit response;
- provider/model temporarily unavailable;
- configured model removed/disabled;
- timeout/network failure;
- transient provider server error;
- invalid/unparseable provider output after bounded repair/validation attempt.

Authentication/configuration failures SHALL be visible to Admin and MAY fail over, but MUST NOT be silently hidden operationally.

Business/input validation errors SHOULD NOT trigger repeated provider calls when the same invalid request would fail everywhere.

A request MUST NOT bounce indefinitely between providers. Maximum provider attempts for the initial V8 design: one normal attempt per configured provider, plus only explicitly bounded response-repair logic if implemented.

### 8.3 Admin controls

Admin SHALL be able to:

- see configured providers;
- see which provider is primary;
- switch primary between Gemini and Codex without code deployment;
- see provider enabled/configured/healthy state;
- see recent provider failures/failovers;
- see model/configuration identifier used per provider;
- test provider connectivity/inference with a safe diagnostic action where practical.

The primary-provider setting SHALL be persisted server-side and auditable.

Provider secrets/credentials MUST remain server-side and MUST NOT be returned to the browser.

### 8.4 Codex adapter boundary

The Codex path is a **redundant AI-provider path**, not a coupling of StoX business logic to an interactive CLI session.

Implementation MUST use a supported, automatable authentication/invocation mechanism available for Codex at implementation time. It MUST NOT depend on scraping an interactive UI, browser session, or brittle terminal automation. If no supported server-side Codex invocation mechanism is available at implementation time, the adapter SHALL remain disabled/unhealthy rather than implementing an unsafe workaround.

The provider interface SHALL allow a later OpenAI/frontier provider to replace or supplement the Codex adapter without changing the signals engine or UI contract.

## 9. Response validation and safety

Before rendering/persisting an AI result, StoX SHALL validate:

- JSON/schema validity;
- required fields;
- bounded field/list lengths;
- no provider error text masquerading as analysis;
- no prohibited recommendation fields;
- provider/model metadata captured separately from investor-visible analysis.

Where feasible, deterministic post-validation SHOULD flag contradictions between surfaced numeric claims and the authoritative signal payload.

The frontend SHALL render a clear non-alarming fallback such as:

> **AI insights are currently unavailable.**

when both configured providers fail. Core fundamentals, charts and deterministic data remain usable; AI failure MUST NOT break the stock-detail page.

## 10. Observability, persistence and cost controls

For each AI invocation, record operational metadata sufficient for support/cost analysis, including where available:

- user/tenant context;
- feature key (`fundamental_signals`);
- provider/model;
- whether provider was primary or fallback;
- failover reason;
- latency;
- input/output token usage;
- estimated/actual provider cost where obtainable;
- success/failure and response-validation status;
- prompt/schema version.

Do not log provider secrets.

Initial implementation SHOULD support server-side per-user/global usage limits and provider spend/rate controls without hard-coding those limits into the frontend.

## 11. Prompt/version governance

Prompts and schemas SHALL be versioned product artifacts.

The provider-neutral prompt SHALL enforce at minimum:

- use supplied facts/evidence only unless a future explicitly research-enabled mode says otherwise;
- no arithmetic where StoX already provides authoritative values;
- no unsupported qualitative labels;
- no causal claim from correlation alone;
- period/basis differences must be respected;
- neutral facts are not forced into strengths/risks;
- routine facts should be omitted;
- focus on unusual/material signals;
- state missing evidence and follow-up checks;
- no investment recommendation or future-price prediction.

Provider-specific wrappers MAY adapt formatting/schema capabilities, but SHALL preserve the same semantic contract.

## 12. Dependencies and sequencing

V4-FEAT-062 depends materially on V4-FEAT-054 because useful signals require trustworthy historical fundamental facts and derived metrics.

Recommended implementation sequence inside the epic:

1. finalize canonical signal catalogue and evidence model;
2. implement deterministic signals/calculations;
3. implement provider-neutral AI service/response schema;
4. implement Gemini provider;
5. implement Codex provider/fallback boundary;
6. implement Admin provider preference/health controls;
7. implement investor-facing summary/signals/watch/follow-up UI;
8. add observability, limits and regression benchmarks.

## 13. Initial acceptance criteria

1. StoX computes deterministic metrics/signals without relying on the LLM for arithmetic it can own.
2. Investor output defaults to a concise summary plus only material positive, risk and watch signals.
3. Routine/non-material fundamentals can result in no surfaced signal.
4. Every surfaced signal includes enough evidence to explain why it appeared.
5. Ambiguous patterns are presented as watch items/hypotheses rather than conclusions.
6. CWIP and similar ambiguous signals provide appropriate follow-up research guidance.
7. A standalone ratio is not classified positive/negative without sufficient comparison/rule evidence.
8. Primary provider defaults to Gemini; secondary defaults to Codex.
9. Admin can switch primary provider without deployment.
10. Whichever provider is primary is attempted first; an eligible failure automatically attempts the secondary provider.
11. Failure of both providers returns a graceful **AI insights are currently unavailable** state without breaking deterministic fundamentals.
12. Provider-specific implementation is hidden behind a common interface.
13. Invalid/unparseable AI output is not rendered as valid insight.
14. Provider/failover/model/prompt-version/latency/usage metadata is observable.
15. No AI output provides Buy/Sell/Hold, target-price or future-price prediction.
16. Credentials remain server-side.
17. Codex integration uses only a supported automatable invocation mechanism; unsupported UI/CLI-session scraping is not permitted.

## 14. Benchmark evidence / design rationale

The V8 design is informed by POC runs using the same Reliance Industries normalized fundamental payload across multiple LLM families.

Observed direction:

- Gemini Flash-Lite produced consistently usable structured fundamental interpretation under a strict prompt/schema;
- Gemma-class hosted inference was viable but showed weaker guardrail consistency in the tested configuration;
- frontier-model responses showed better second-order synthesis and analytical restraint, but routine structured interpretation remained reasonably handled by Gemini;
- increasing frontier-model thinking effort did not demonstrate a material advantage for this specific structured-data interpretation workload in the limited POC sample.

These POCs do **not** establish universal model rankings. They support the product decision to make Gemini the initial preferred provider while keeping the provider layer swappable and redundant.

## 15. Out of scope for initial V8 implementation

- autonomous web research or crawling;
- automatic ingestion of every annual report/investor call/presentation;
- AI-generated investment recommendations or target prices;
- replacing deterministic StoX analytics with LLM calculations;
- claiming misconduct/accounting manipulation from an unexplained anomaly;
- making Codex/one provider a permanent architectural dependency.
