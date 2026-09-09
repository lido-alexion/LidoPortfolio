# StoX V7 Fundamental Data Integration & Support Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-053 — Fundamental Data Integration & Support |
| **Version target** | V7 |
| **Status** | DECIDED — product/architecture behaviour frozen for implementation planning |
| **Owner** | Architecture / Product |
| **Canonical path** | `specs/V7-Fundamental-Data-Integration-Specification.md` |
| **Related** | `specs/LidoPortfolio-V7-Wishlist.md`, `specs/architecture/domains/Indicator-Registry-Specification.md` |
| **Separate epic** | V4-FEAT-054 — Historical Fundamental Data Bootstrap |

---

## 1. Purpose

Add first-class company fundamental data to StoX so Screeners, Strategies, Evaluation, Recommendation, Discovery, Stock Details and later ML scoring can use financial information alongside existing technical/market data.

V7 introduces an ongoing, provider-independent fundamental-data capability with Yahoo Finance / `yfinance` as the **only provider adapter implemented in this epic**. Downstream StoX behaviour must not depend on Yahoo-specific field names or schemas.

The one-time population of deep historical fundamentals is **not** part of this epic. It is tracked separately as V4-FEAT-054 and may be performed by custom/offline scripts. StoX itself must support arbitrary imported history depth.

---

## 2. Scope

### In scope

- Provider abstraction for fundamental data acquisition.
- Yahoo / `yfinance` provider adapter.
- Curated provider-neutral canonical primary financial facts.
- Quarterly and annual statements.
- Consolidated-first statement basis.
- Revision/restatement history.
- Availability/publication semantics suitable for point-in-time analysis.
- Derived fundamental metrics, including sector-aware metrics for banks/NBFCs.
- Integration with the existing Indicator Registry.
- Screener, Strategy, Evaluation, Recommendation, Discovery and Exit Criteria use.
- Stock Details fundamentals UI.
- Admin operational UI for update status, coverage, errors and freshness configuration.
- Incremental/resumable updater with rate protection, prioritisation and idempotency.

### Out of scope

- One-time deep historical bootstrap/import procedure — V4-FEAT-054.
- CSV/manual Admin-entry provider adapters.
- Trendlyne adapter.
- Enterprise/commercial provider adapters.
- Shareholding/ownership data such as promoter/FII/DII holdings or pledge data.
- Market-wide/index aggregate fundamentals in Market Gates.
- Runtime formula engines or user-defined fundamental formulas.

---

## 3. Architectural principles

1. **Provider independence.** Provider-specific schemas terminate inside the acquisition adapter.
2. **Primary facts are canonical; derived metrics are StoX-owned.** Do not canonically persist provider-derived ratios merely because Yahoo exposes them.
3. **Historical correctness.** A historical evaluation must never use a value/revision that was unavailable at that evaluation date.
4. **Revisions are immutable history.** Restated values do not overwrite the historical fact that an earlier value was once known.
5. **No silent semantic substitution.** Annual, Quarterly, TTM, QoQ and YoY bases are distinct rule semantics; one basis never silently substitutes for another.
6. **Registry-first discoverability.** Fundamental indicators extend the existing Indicator Registry rather than create a parallel registry/framework.
7. **Freshness is a global data-governance concern.** It is controlled in Admin, not independently configured in every Screener or Strategy.
8. **Technical-only workflows are unaffected.** Missing/stale fundamentals never disqualify a stock from workflows that do not use fundamentals.

---

## 4. Provider abstraction

Define a provider-facing contract conceptually equivalent to:

```text
FundamentalDataProvider
  ├─ fetch quarterly statements/facts
  ├─ fetch annual statements/facts
  ├─ expose provider availability/publication metadata where available
  └─ normalize provider errors/status into StoX acquisition results
```

### V7 implementation

Only one adapter ships:

```text
YahooFundamentalDataProvider (yfinance)
```

Expected source capabilities include annual/quarterly Income Statement, Balance Sheet and Cash Flow data and selected directly reported company facts.

No Yahoo-specific field names may leak into downstream Strategy/Screener/Evaluation contracts.

---

## 5. Canonical primary facts

StoX stores a **curated**, provider-neutral set of primary financial facts rather than a dump of every provider field.

The canonical set should cover, where directly reported and economically meaningful:

- Revenue / operating income facts.
- Operating profit / EBIT / EBITDA where directly reported and appropriately mapped.
- Profit after tax / net income.
- EPS.
- Total assets.
- Total liabilities.
- Equity.
- Debt.
- Cash and cash equivalents.
- Operating cash flow.
- Investing cash flow.
- Financing cash flow.
- Capital expenditure where directly available.
- Shares outstanding.
- Dividend facts where directly reported.
- Required company/statement identity and period metadata.

The detailed field catalogue may be refined during implementation, but additions must preserve provider-neutral semantics.

### Financial institutions

Banks, NBFCs and other financial companies are supported from V7. Their canonical facts and derived metrics must be sector-aware; StoX must not force industrial-company concepts such as generic debt/equity or EBITDA where economically inappropriate.

---

## 6. Statement basis and reporting periods

### Consolidated vs standalone

- **Consolidated statements are canonical/preferred when available.**
- Standalone statements may be used only when consolidated data is unavailable.
- The basis used must be stored explicitly.
- StoX must not silently mix consolidated and standalone basis across periods in a series.

### Reporting periods

StoX respects each company’s actual source-reported financial periods. It does not normalize all companies into artificial calendar quarters.

- Quarterly data follows the reported quarter boundaries.
- Annual data follows the reported financial year.
- TTM means the latest four reported quarters appropriate to that company.

### Quarterly and annual coexist

Both quarterly and annual source statements are retained. Annual data is not assumed to equal the sum of four quarter records, because annual reports may be audited/restated and may differ from simple aggregation.

---

## 7. Availability, point-in-time semantics and revisions

Each canonical record/revision must preserve sufficient metadata to resolve what was knowable at a given date, including at minimum:

- financial period start/end or equivalent reported period identity;
- statement basis;
- actual publication/availability date where provider supplies one reliably;
- StoX first-fetch/import timestamp;
- provider/source identity;
- revision/version identity or equivalent immutable history metadata.

### Availability-date fallback

For ongoing/live acquisition:

1. Use provider publication/availability date when reliable.
2. If unavailable, use StoX first-fetch/import date as the availability date.

### Historical bootstrap exception

V4-FEAT-054 may import historical periods for which true publication dates are unavailable. Such records must receive a **deterministic conservative estimated availability date** derived from the period end.

- Quarterly and annual records may use different standard lags.
- Exact lag values are implementation constants/defaults, not user-facing controls.
- The policy must bias toward making data available **late rather than early**, minimising future leakage.

### Restatements

When a previously published period is revised:

- preserve the original value and its availability date;
- preserve the revised value and its later availability date;
- current/live evaluation uses the latest valid revision;
- historical evaluation uses the revision that was available at the historical evaluation date.

Identical duplicate payloads are not revisions and must be deduplicated.

---

## 8. Derived metrics engine

Derived metrics are calculated inside StoX from canonical primary facts and, where necessary, point-in-time market data.

V7 should provide a **reasonably comprehensive curated** set, covering where economically meaningful:

- valuation;
- profitability;
- growth;
- leverage;
- cash flow;
- efficiency;
- quality;
- sector-specific banking/NBFC measures.

Examples include P/E, P/B, ROE, ROCE, debt/equity, net debt, margins, revenue/profit growth, CAGR, free cash flow, FCF margin and related measures.

### Price-dependent metrics

Metrics such as P/E or P/B must not be frozen solely at quarterly-import time. Their calculation must combine the applicable point-in-time fundamental value with the market price applicable to the evaluation date.

This is mandatory for historical/backtest correctness.

### Mixed-input validity

A derived metric is eligible for live decision use only if **every freshness-governed input required by its calculation is valid**. Technical/static inputs do not impose an additional age-based freshness class.

---

## 9. Indicator Registry integration

Fundamental indicators extend the existing Indicator Registry defined by `specs/architecture/domains/Indicator-Registry-Specification.md`.

The Registry remains metadata/discovery, not the fundamental calculator.

Fundamental values intended for product consumption must expose appropriate registry metadata/capabilities so existing consumers can discover them through the Registry/façades rather than maintain separate hardcoded lists.

Relevant consumers include:

- Screener
- Strategy
- Evaluation
- Recommendation (indirectly through Strategy/Evaluation)
- Discovery
- Stock Details
- later ML Scoring Models (V4-FEAT-018)

### Period basis is explicit

A fundamental rule/indicator use must explicitly express its semantic basis where applicable, such as:

- Latest Quarter
- Annual
- TTM
- QoQ
- YoY

The requested basis is part of the rule definition. StoX never silently substitutes another basis.

---

## 10. Fundamental freshness model

Freshness is an **Admin-controlled global policy**, not a Screener/Strategy-level configuration.

### Freshness classes

Fundamental fields/indicators are mapped into three classes:

| Class | Behaviour |
|---|---|
| **Quarterly** | Age-based freshness enforced; global Admin threshold |
| **Annual** | Age-based freshness enforced; global Admin threshold |
| **Static / slow-moving** | No automatic age-based staleness; treated at par with technical data for rule validity |

Examples:

- quarter facts, TTM, QoQ and quarter-derived YoY → Quarterly freshness;
- annual facts and annual-derived metrics → Annual freshness;
- stable/slow-moving descriptive facts → Static/slow-moving.

### Global Admin defaults

- **Quarterly freshness:** 5 months.
- **Annual freshness:** 15 months.

Both values are editable in Admin in **months**.

Freshness enforcement itself cannot be disabled.

There are no per-indicator, per-Screener or per-Strategy freshness overrides.

### Availability-date test

A live value must be within its configured freshness threshold based on publication/availability date.

### Financial-period sanity guard

A second, system-defined deterministic guard must reject a record from live decision use when its underlying financial period is implausibly old for its cadence, even if its publication/import timestamp appears recent.

- This guard is not Admin-configurable.
- It protects against provider gaps, delayed/mislabelled data or freshly imported but genuinely outdated records.
- Failure is treated like stale data, not destructive invalidation.

### Visibility of stale data

Stale or sanity-guard-failed values remain visible in Investor Stock Details/history. They are simply ineligible for live Screener/Strategy/Exit evaluation. Admin diagnostics should distinguish normal staleness from sanity-guard rejection.

### Quarterly and Annual are independent

New quarterly results do not invalidate an otherwise-fresh Annual value. Annual rules continue to use the latest fresh Annual result.

### Latest available fresh period

For both Quarterly and Annual rules, StoX uses the **latest available record that still satisfies that cadence’s freshness policy**. It need not be the most recently expected period if an immediately previous period remains within the valid window.

### Historical evaluation

Current Admin freshness thresholds are **not applied retrospectively** to historical analysis/backtests.

Historical evaluation instead resolves:

- the data available at the evaluation date;
- the revision available at that date;
- the explicitly requested period basis.

This preserves point-in-time truth without imposing today’s live-age policy on past decisions.

---

## 11. Screener semantics

- Pure technical Screeners are completely unaffected by fundamental availability/freshness.
- If a Screener explicitly uses a fundamental condition, required data must be available and live-valid according to the global freshness policy for that condition’s basis.
- If any required fundamental condition cannot be evaluated because its metric is missing/stale, that stock **fails that Screener**.
- StoX must never silently drop/ignore the unavailable condition.
- There is no per-Screener freshness threshold or freshness toggle.

---

## 12. Strategy and scoring semantics

Strategies may use fundamental indicators both as:

- hard gates; and
- weighted scoring inputs.

For live decisions, required fundamental inputs must be valid under the global freshness rules.

### Hard gates

Missing/stale required fundamental data cannot satisfy the gate.

### Weighted scoring

If any configured fundamental scoring input is missing/stale, the Strategy’s fundamental score for that stock is **invalid**.

StoX must **not re-normalise the remaining weights**, because doing so would silently change the Strategy definition from stock to stock.

The same no-silent-renormalisation principle should apply to any other weighted ranking/scoring consumer using fundamental inputs.

---

## 13. Exit Criteria semantics

Fundamental indicators may be used in Exit Criteria.

A fundamental exit rule evaluates only when its required data is valid under the applicable global freshness rules.

Missing/stale data itself must **never manufacture or trigger an exit**.

---

## 14. Evaluation, Recommendation and evidence

Fundamentals become first-class decision inputs through existing StoX architecture.

Conceptual flow:

```text
Provider
  → normalize canonical primary facts
  → canonical storage + revisions
  → derived metrics engine
  → Indicator Registry / façades
  → Screener / Strategy / Evaluation
  → Recommendation / Discovery / UI
```

Recommendation logic must not bypass Strategy/Evaluation by directly querying fundamental tables.

Evaluation/evidence should persist the fundamental values and relevant definition/version context that contributed to a decision so completed evidence remains reproducible/immutable under existing StoX principles.

---

## 15. Market Gates

Company fundamentals are **not** used in Market Gates in V7.

Potential future market-wide fundamental aggregates such as index valuation/earnings aggregates are a separate enhancement.

---

## 16. Incremental acquisition and orchestration

The ongoing V7 updater is incremental. It focuses on newly available quarters/annual reports instead of rebuilding full history each run.

### Light restatement revalidation

When fetching a new period, also recheck the immediately preceding recent period(s) for corrections/restatements.

### Prioritisation order

Stocks are processed in this order:

1. Current holdings
2. Watchlist stocks
3. NIFTY 50
4. NIFTY 500
5. NIFTY Smallcap constituents
6. Other index constituents
7. Remaining NSE stocks
8. BSE-only stocks

Within a tier, prioritise:

1. never-fetched/missing coverage;
2. then stalest coverage first.

### Resilience

The updater must be:

- fail-safe;
- resumable/checkpointed;
- retryable with backoff;
- conservative and configurable in request rate;
- able to spread work across hours/days;
- tolerant of one-stock/provider failures without aborting the run.

Manual Admin-triggered runs obey the same rate/safety controls.

---

## 17. Request minimisation and idempotency

Deduplication must happen **before** provider calls wherever local state proves the request unnecessary.

Required layers:

1. **Queue dedupe** — do not schedule equivalent work twice.
2. **Pre-fetch coverage/freshness dedupe** — skip provider call when canonical local state proves no fetch is needed.
3. **Database idempotency/dedupe** — retries/repeated payloads cannot create duplicate canonical records.

Conceptual natural identity includes instrument + reported financial period + statement/basis + canonical fact, with revision/availability semantics layered on top.

- identical already-known payload → no-op;
- missing fact/revision → insert;
- genuine changed/restated fact → preserve new revision rather than destructively overwrite history.

---

## 18. Admin operations

Admin is responsible for operational data health.

The Admin UI should expose, at minimum:

- Quarterly freshness threshold (default 5 months);
- Annual freshness threshold (default 15 months);
- run status/progress/queue;
- latest successful attempt;
- per-stock coverage/status/history;
- stale/missing coverage;
- provider/rate-limit errors;
- skipped/deduplicated counts;
- success/failure summaries.

Admin actions should include:

- start update run;
- update a specific stock/universe;
- retry failed work;
- pause/stop safely;
- resume an interrupted run.

Operational source/provenance, revision detail and freshness diagnostics remain primarily Admin-facing.

---

## 19. Investor Stock Details UI

Add a Fundamentals area to Stock Details showing a clean investor-facing presentation:

- key fundamental metrics;
- Income Statement;
- Balance Sheet;
- Cash Flow;
- quarterly/annual toggle;
- historical trends for relevant measures such as revenue, PAT, ROE, ROCE, leverage and margins.

Stale values remain visible; decision eligibility is separate from display.

Do not clutter the investor view with provider ingestion internals, fetch errors, revision mechanics or operational diagnostics.

---

## 20. Historical bootstrap boundary (V4-FEAT-054)

V4-FEAT-053 must make the canonical model capable of storing and analysing **any historical depth** supplied to it.

However, deciding/fetching the initial deep historical dataset is a separate V7 epic:

**V4-FEAT-054 — Historical Fundamental Data Bootstrap**

That activity may use custom/offline scripts and may choose a different practical extraction mechanism. It must ultimately populate the same canonical model and obey the historical availability/revision semantics defined here.

---

## 21. Acceptance-level invariants

Implementation must preserve these invariants:

1. No Yahoo-specific schema leaks beyond the provider adapter/normalisation boundary.
2. Canonical storage contains curated primary facts, not an indiscriminate provider dump.
3. Derived ratios/metrics are StoX-owned calculations.
4. Consolidated basis is preferred; basis mixing is never silent.
5. Quarterly and annual source facts coexist.
6. Restatements preserve history.
7. Historical evaluations cannot see future revisions/data.
8. Fundamental indicators use the existing Indicator Registry.
9. Period basis is explicit and never silently substituted.
10. Live freshness is globally Admin-controlled: Quarterly 5 months / Annual 15 months by default.
11. Static/slow-moving facts have no age-based staleness and behave like technical data for validity.
12. Freshness cannot be disabled and has no Screener/Strategy overrides.
13. Pure technical workflows are unaffected by fundamental staleness.
14. Missing/stale required Screener fundamentals cause that stock to fail the explicit condition/Screener.
15. Weighted Strategy scoring never silently re-normalises around missing/stale configured fundamental inputs.
16. Missing/stale fundamentals never manufacture an exit.
17. Stale values remain visible to investors.
18. Historical backtests use point-in-time availability/revision semantics rather than today’s live freshness thresholds.
19. Pre-fetch dedupe minimises unnecessary provider calls.
20. One-time deep history loading remains separate under V4-FEAT-054.
