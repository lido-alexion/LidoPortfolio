# StoX V8 Historical Fundamentals Bootstrap, Derived Metrics & Investor Research Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-054 — Historical Fundamental Data Bootstrap |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Canonical path** | `specs/V8-Historical-Fundamentals-Bootstrap-Specification.md` |
| **Parent register** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Builds on** | `specs/V7-Fundamental-Data-Integration-Specification.md` |
| **Primary implementation agent** | Codex |

---

## 1. Purpose

V4-FEAT-054 completes and operationalizes the V7 fundamentals foundation for practical investor use.

The V7 implementation already provides the canonical fundamental-fact store, Yahoo / `yfinance` acquisition, quarterly and annual cadence support, point-in-time availability filtering, incremental freshness updates, basic derived metrics and a stock-level fundamentals API. FEAT-054 must **reuse and evolve that foundation** rather than build a parallel subsystem.

The V8 epic has three primary goals:

1. **Fill material gaps in the canonical fundamental dataset and metadata.**
2. **Define and expose a curated investor-grade derived-metric catalogue.**
3. **Backfill the maximum trustworthy historical fundamental data available for currently active StoX equities.**

V8 also makes the historical and derived data directly useful by:

- enhancing the selected-stock detail surface inside the existing Watchlist page;
- exposing historical tables and charts;
- making selected fundamental metrics available as Screener eligibility/filter conditions;
- providing a dedicated, resumable historical bootstrap workflow;
- providing lightweight read-only Admin bootstrap observability.

StoX is a **personal-grade investment application**, not an enterprise financial-data platform. Where a choice exists between materially greater implementation/operational complexity and a minor imperfection, this specification deliberately prefers the simpler design unless correctness would become misleading or unsafe.

---

## 2. Scope

### 2.1 In scope

- Expand the canonical mapped primary-fact catalogue.
- Separate quarterly and annual core/optional coverage expectations.
- Prefer consolidated statements and fall back to standalone when consolidated data is unavailable.
- Normalize Indian-listed-equity monetary values to raw INR internally.
- Default missing currency to INR for NSE/BSE-listed equities.
- Add official-source historical acquisition:
  - NSE for NSE / NSE+ canonical securities;
  - BSE for BSE-only canonical securities;
  - Yahoo / `yfinance` as automatic fact/period fallback.
- Preserve existing NSE-first canonical security identity and ISIN deduplication.
- Backfill the maximum available trustworthy history for **currently active** StoX equities.
- Record exact, estimated or non-PIT availability semantics for historical facts.
- Reconcile and upgrade weaker V7 Yahoo rows when better official evidence is found.
- Add a dedicated queue-backed historical bootstrap workflow with persisted progress.
- Add simple source-specific throttling and retry behavior.
- Add basic deterministic anomaly checks.
- Add a curated derived-metric service.
- Add historical metric series, including daily historical valuation series.
- Extend stock fundamentals APIs for multi-period statements and metric history.
- Add a tabbed selected-stock detail layout inside the existing Watchlist page.
- Add Fundamentals summary, history charts, Basic tables and collapsed Advanced tables.
- Reuse/generalize the existing price-chart interaction model.
- Add selected fundamental metrics to the existing Screener condition framework as **eligibility/filter operands only**.
- Add lightweight read-only Admin bootstrap status.
- Preserve existing V1-V7 technical workflows unless a Screener explicitly references fundamentals.

### 2.2 Out of scope

- Backfilling delisted/inactive historical issuers.
- Survivorship-bias elimination for ML/backtests.
- Reconstruction of every historical restatement/revision chronology.
- Full enterprise-grade filing reconciliation or financial-statement validation.
- Importing arbitrary unmapped provider fields.
- Persisting provider-derived ratios as canonical primary facts.
- User-configurable financial formulas.
- Fundamental metric scoring weights inside Screeners.
- Automatic Strategy behavior changes merely because fundamentals exist.
- Peer/sector comparison analytics.
- CSV/raw export from Fundamentals UI — deferred to V9.
- Customizable summary fields/card layouts — deferred to V9.
- Combo/multi-series chart overlays — deferred to V9.
- Full Admin-driven bootstrap controls — deferred to V9.

---

## 3. Frozen product decisions

The following are authoritative. Implementation must not reopen these unless a genuine blocker is discovered.

| Decision | Frozen choice |
|---|---|
| 054-01 | Curated investment-grade primary/derived catalogue. |
| 054-02 | Hybrid historical source strategy. |
| 054-03 | Maximum available trustworthy history. |
| 054-04 | Hybrid availability policy: exact where known, conservative deterministic estimate where defensible, otherwise non-PIT. |
| 054-05 | Dedicated historical bootstrap workflow in V8; full Admin operations deferred to V9. |
| 054-06 | Existing security-master semantics remain authoritative; NSE wins over BSE for the same ISIN. |
| 054-07 | Canonical primary facts + dynamically calculated metrics + optional rebuildable cache. |
| 054-08 | Metric formulas hard-coded/tested in service code. |
| 054-09 | Bootstrap currently active equities only. |
| 054-10 | Bootstrap starts only through explicit operator action. |
| 054-11 | Completion is source exhaustion + quality classification. |
| 054-12 | Separate quarterly and annual fact catalogues/coverage expectations. |
| 054-13 | Historical bootstrap stores latest known value only; do not reconstruct old revision chronology. |
| 054-14 | Automatic Yahoo fallback per missing official fact/period. |
| 054-15 | One bootstrap job per stock. |
| 054-16 | Missing derived-metric inputs produce unavailable; no hidden estimation. |
| 054-17 | Growth = same-period YoY; no QoQ in V8. |
| 054-18 | Prefer consolidated; fall back to standalone. |
| 054-19 | Missing currency defaults to INR for NSE/BSE-listed equities. |
| 054-20 | Normalize monetary facts to raw INR internally. |
| 054-21 | Growth compares only like-duration fiscal periods; irregular transition periods are not annualized. |
| 054-22 | Valuation metrics use evaluation-date market price. |
| 054-23 | Invalid/zero/negative denominators for economically meaningless ratios return unavailable. |
| 054-24 | TTM requires four complete consecutive comparable quarters. |
| 054-25 | 3-year CAGR only. |
| 054-26 | Add a small bank/NBFC-specific metric set. |
| 054-27 | Extend the existing fundamentals API rather than create a separate derived-metrics API family. |
| 054-28/29 | Fundamentals live in the existing selected-stock Watchlist detail surface, with snapshot + historical trends; no separate dashboard route. |
| 054-30 | No peer comparison in V8. |
| 054-31 | Selected-stock detail becomes tabbed. |
| 054-32 | Fundamentals tab is summary-first. |
| 054-33 | Selected fundamental metrics become available to Screeners. |
| 054-34 | Fundamentals are Screener eligibility/filter conditions only, not scoring. |
| 054-35 | Missing required fundamental metric causes the Screener condition to fail. |
| 054-36 | Reuse the existing Screener condition framework. |
| 054-37 | Different metric bases are separate metric names/IDs. |
| 054-38 | Historical charts include both raw facts and selected derived metrics. |
| 054-39 | Fundamental fact charts use explicit Annual / Quarterly cadence selection. |
| 054-40 | UI shows all persisted investor-facing fundamental data: Basic expanded + Advanced collapsed. |
| 054-41 | Investor UI shows minimal provenance, typically source label only. |
| 054-42 | Statement tables show multiple periods side by side. |
| 054-43 | Historical valuation series is available daily; user can change chart frequency. |
| 054-44 | Fundamentals charts default to monthly frequency. |
| 054-45 | Historical chart data lazy-loads. |
| 054-46 | Default chart range = 5 years. |
| 054-47 | Reuse/generalize existing price-chart infrastructure. |
| 054-48 | Historical valuation charts use adjusted prices. |
| 054-49 | One metric per chart in V8. |
| 054-50 | No data export in V8. |
| 054-51 | Summary uses fixed architect-defined fields in V8. |
| 054-52 | Derived metrics expose concise tooltip/help definitions/formulas. |
| 054-53 | No qualitative labels such as cheap/expensive/strong/weak. |
| 054-54 | Summary shows contextual prior-period/historical change where meaningful. |
| 054-55 | Summary density approximately 10-12 metrics. |
| 054-56 | One compact section-level freshness indicator. |
| 054-57 | Statement tables have their own Quarterly / Annual toggle. |
| 054-58 | Advanced expanded/collapsed state remembered per user. |
| 054-59 | Multi-period tables are responsive with horizontal scrolling as needed. |
| 054-60 | UI uses investor-friendly units such as ₹ Cr, %, x. |
| 054-61 | Statement table values include compact inline comparable-period change. |
| 054-62 | Table row order is fixed and architect-defined. |
| 054-63 | Fundamentals summary includes Market Cap and Enterprise Value. |
| 054-64 | Support key dividend metrics including Dividend Yield and Payout Ratio. |
| 054-65 | Latest summary prefers TTM/latest-period basis where economically appropriate. |
| 054-66 | Section-level freshness indicator is sufficient; no per-metric basis clutter. |
| 054-67 | Show compact annual/quarterly historical coverage indicator. |
| 054-68 | Combo chart support deferred to V9. |
| 054-69 | Reconcile/upgrade existing V7 Yahoo facts in place where possible. |
| 054-70 | Persist only explicitly mapped canonical facts. |
| 054-71 | Automatically upgrade lower-quality metadata when better evidence appears. |
| 054-72 | Basic deterministic sanity checks only. |
| 054-73 | Reject only suspicious fact; continue stock job and mark partial if needed. |
| 054-74 | Simple source-specific request throttling. |
| 054-75 | Targeted reruns supported. |
| 054-76 | Lightweight read-only Admin bootstrap status in V8. |
| 054-77 | Bootstrap progress/checkpoints survive restart/deploy. |
| 054-78 | Queue-backed one-stock jobs. |

---

## 4. Existing V7 foundation to preserve

Implementation must build on the existing V7 components rather than replace them wholesale:

- `stox_fundamental_facts` canonical primary-fact store;
- `FundamentalDataService` PIT fact lookup and current metric compatibility surface;
- `FundamentalUpdateService` ongoing incremental freshness updater;
- Yahoo / managed Python `yfinance` adapter;
- existing Admin fundamentals settings/update status;
- existing stock fundamentals API;
- existing Indicator/Screener architecture;
- existing Watchlist selected-stock research surface;
- existing cached market-price history;
- existing Laravel scheduler and persistent queue worker.

The V7 live updater remains the normal **ongoing freshness updater**. FEAT-054 historical bootstrap is a separate workflow.

---

## 5. V7 behavior that V8 must correct

These are implementation requirements, not optional refactors.

### 5.1 TTM correctness

Current V7 behavior can sum up to four available quarterly rows without proving that they are four consecutive comparable quarters.

V8 rule:

- TTM flow metrics require **exactly four distinct, consecutive, comparable quarters**.
- Missing quarter, duplicate quarter, cadence mismatch or materially irregular quarter duration => TTM unavailable.
- Do not fill a missing quarter from annual data.
- Do not produce 2Q/3Q pseudo-TTM values.

### 5.2 Quarterly growth correctness

Current V7 `growthMetric()` compares the latest two periods.

V8 quarterly YoY rule:

- compare latest quarter with the **same fiscal quarter one year earlier**;
- do not use immediately previous quarter;
- annual growth compares latest comparable FY against prior comparable FY;
- irregular transition periods are not annualized.

### 5.3 FCF missing-input correctness

Current logic can effectively treat missing capex as zero.

V8 rule:

```text
FCF = operating_cash_flow - abs(capital_expenditure)
```

but only when **both** inputs exist.

Missing OCF or capex => FCF unavailable.

### 5.4 Invalid ratio denominator correctness

Generic mathematical division is insufficient for investor ratios.

At minimum:

- EPS <= 0 => P/E unavailable;
- EBITDA <= 0 => EV/EBITDA unavailable;
- EBIT <= 0 => EV/EBIT unavailable where negative multiple would be economically misleading;
- equity <= 0 => ROE/P/B unavailable where applicable;
- denominator = 0 => ratio unavailable;
- current liabilities <= 0 => current ratio unavailable;
- finance cost <= 0 => interest coverage unavailable.

The raw source facts remain valid/stored even when a derived ratio is unavailable.

### 5.5 Better evidence must upgrade same-value facts

Current V7 same-value deduplication can prevent an official filing from upgrading weak Yahoo metadata.

V8 must support:

```text
Yahoo value = 100, first_fetch availability = 2026
NSE value   = 100, exact filing availability = 2023
=> retain one canonical fact value 100 but upgrade source/availability metadata to NSE/exact/2023
```

### 5.6 Provider basis honesty

Yahoo data must not be labelled `consolidated` merely because the current normalizer defaults it.

The canonical store must represent only a basis that the adapter can establish. Where provider basis cannot be proven, normalize using an explicit unknown/derived handling compatible with the schema, and only apply consolidated-first/standalone-fallback when source evidence supports it.

### 5.7 Full historical statement retrieval

The existing stock fundamentals response is not enough for multi-period tables.

V8 must expose complete period histories, not only the latest fact per key.

---

## 6. Canonical primary-fact catalogue

Create one code-owned catalogue, conceptually `FundamentalFactCatalog`, to centralize:

- canonical `fact_key`;
- investor label;
- statement group;
- monetary/per-share/percentage/count data type;
- quarterly core/optional classification;
- annual core/optional classification;
- Basic vs Advanced UI classification;
- sector applicability where needed.

Stable fact IDs use `snake_case`.

### 6.1 General corporate facts

#### Income statement

**Core quarterly + annual where source supports them:**

- `revenue`
- `operating_profit`
- `ebit`
- `ebitda`
- `profit_before_tax`
- `net_income`
- `eps`
- `eps_diluted`

**Extended / optional quarterly, expected annual when available:**

- `finance_cost`
- `tax_expense`

#### Balance sheet

**Core quarterly + annual:**

- `total_assets`
- `total_liabilities`
- `equity`
- `cash_and_equivalents`
- `debt`
- `long_term_debt`

**Extended / optional quarterly, expected annual when available:**

- `current_assets`
- `current_liabilities`
- `receivables`
- `inventory`
- `shares_outstanding`

#### Cash flow

- `operating_cash_flow`
- `investing_cash_flow`
- `financing_cash_flow`
- `capital_expenditure`
- `dividends_paid`

Cash-flow quarterly coverage is allowed to be partial when the source does not report it quarterly. Annual coverage remains the stronger expectation.

### 6.2 Banks / NBFCs

Where trustworthy mapped data exists, support a limited sector-specific set:

- `interest_income`
- `interest_expense`
- `net_interest_income`
- `gross_npa`
- `gross_npa_ratio`
- `net_npa`
- `net_npa_ratio`
- `capital_adequacy_ratio`
- `provisions`

Absence of these sector-specific facts does not by itself invalidate otherwise usable general fundamentals.

### 6.3 Unmapped provider fields

Do not persist arbitrary provider field names.

If a provider exposes a useful new field:

1. add an explicit canonical mapping;
2. add data type/group metadata;
3. add tests;
4. rerun targeted bootstrap if desired.

---

## 7. Canonical units and metadata

### 7.1 Currency

For NSE/BSE-listed Indian equities:

- preserve explicit source currency when available;
- if currency is absent, default to INR;
- investor-facing APIs should identify currency only when needed for formatting/context.

### 7.2 Monetary normalization

Canonical monetary values are stored in **raw INR**, not lakh/crore/million display units.

Examples:

- ₹1 lakh => `100000`
- ₹1 crore => `10000000`
- ₹123.4 crore => `1234000000`

UI formatting converts to investor-friendly display units.

### 7.3 Period metadata

Preserve when source provides it:

- `period_start`
- `period_end`
- reported fiscal period label
- cadence
- statement basis

Do not fabricate `period_start` when unavailable merely to satisfy the schema.

### 7.4 Availability quality

Add/normalize an explicit availability classification, conceptually:

- `exact`
- `estimated`
- `non_pit`

Semantics:

- **exact** — actual filing/publication/broadcast date is available;
- **estimated** — deterministic conservative availability date derived from reporting-period semantics;
- **non_pit** — data may be stored/displayed but cannot be safely used in historical PIT calculations.

The exact storage location may be a dedicated column or normalized `source_meta`; prefer a first-class column if migration cost is modest because it is a core query semantic.

### 7.5 Conservative estimated availability

When actual filing date is unavailable but reporting cadence and period are known, use one deterministic code-owned rule. The exact day offsets are an architect implementation detail, but must:

- be conservative;
- be documented in code/tests;
- never be presented as an actual filing date;
- remain stable across runs unless deliberately migrated.

---

## 8. Security-master and source precedence

StoX already deduplicates NSE/BSE listings by ISIN and keeps NSE as the canonical security when the same equity is dual listed.

### 8.1 Canonical exchange rule

```text
Same ISIN on NSE + BSE
=> one canonical StoX stock row
=> exchange = NSE
=> is_dual_listed = true
=> investor display = NSE+
```

BSE rows are canonical only for BSE-only securities.

### 8.2 Official source routing

```text
NSE / NSE+
  -> NSE official filings first
  -> Yahoo fallback per missing fact/period

BSE-only
  -> BSE official filings first
  -> Yahoo fallback per missing fact/period
```

Exceptional same-ISIN official conflict:

```text
NSE > BSE > Yahoo
```

Do not build a manual conflict-resolution workflow in V8.

---

## 9. Historical provider architecture

Introduce provider adapters behind a common historical-acquisition contract, conceptually:

```text
HistoricalFundamentalProvider
  fetchStockHistory(stock)
    -> normalized source statements/facts
    -> source availability metadata
    -> source basis metadata
    -> source unit metadata
```

Recommended implementations:

- `NseHistoricalFundamentalProvider`
- `BseHistoricalFundamentalProvider`
- existing Yahoo provider reused as fallback

Provider-specific schemas terminate inside the adapters/normalizers.

Adapters must return canonical candidates, not write database rows directly.

---

## 10. Source reconciliation and canonical persistence

The existing canonical store remains the source of truth.

For the semantic identity:

```text
stock
+ statement_type
+ cadence
+ statement_basis
+ fact_key
+ period_end
```

reconciliation compares incoming evidence with existing facts.

### 10.1 Source-quality ordering

Use:

```text
exact official
> estimated official
> Yahoo/fallback
```

Within official exchange conflict for a dual-listed ISIN:

```text
NSE > BSE
```

### 10.2 Same value

If incoming and existing canonical values are effectively equal:

- do not create a duplicate economic fact;
- upgrade provider/source metadata when incoming evidence is better;
- upgrade availability date/quality when incoming evidence is better;
- preserve earliest useful fetch/audit metadata as appropriate.

### 10.3 Different value

If values differ:

- higher-priority trustworthy source becomes canonical latest-known value;
- lower-priority conflicting value does not override it;
- preserve enough audit metadata to diagnose the replacement;
- do not build historical revision reconstruction from this bootstrap.

### 10.4 Revision compatibility

Existing revision columns may remain for V7 compatibility.

FEAT-054 does **not** attempt to reconstruct original/restated filing chronology for old periods. Historical bootstrap represents the best latest-known economic value plus the best availability semantics available.

Future live updates may continue using the established revision mechanism where appropriate.

---

## 11. Derived metric service

Create a dedicated code-owned service, conceptually `FundamentalMetricService`.

Do not create a runtime expression/formula engine.

The service is server-authoritative. Frontend code must not implement financial formulas.

### 11.1 Core valuation metrics

#### Market Cap

```text
market_cap = evaluation_date_adjusted_or_current_price * latest PIT shares_outstanding
```

Use the normal market price for present-day snapshot and PIT-compatible historical price for historical valuation series.

#### Enterprise Value

Personal-grade simplified definition:

```text
enterprise_value = market_cap + debt - cash_and_equivalents
```

Do not add minority interest/preferred stock complexity in V8 unless already available canonically.

#### P/E

```text
pe_ttm = evaluation_date_price / ttm_eps
```

Rules:

- TTM EPS must be valid;
- EPS <= 0 => unavailable.

#### P/B

```text
book_value_per_share = latest_equity / latest_shares_outstanding
pb_latest = evaluation_date_price / book_value_per_share
```

Equity <= 0 or shares <= 0 => unavailable.

#### EV/EBITDA

```text
ev_ebitda_ttm = enterprise_value / ttm_ebitda
```

EBITDA <= 0 => unavailable.

#### EV/EBIT

```text
ev_ebit_ttm = enterprise_value / ttm_ebit
```

EBIT <= 0 => unavailable.

#### Earnings Yield

```text
earnings_yield_ttm = ttm_eps / evaluation_date_price * 100
```

Price <= 0 or EPS <= 0 => unavailable.

#### FCF Yield

```text
fcf_yield_ttm = ttm_fcf / market_cap * 100
```

Market cap <= 0 => unavailable. Negative FCF may remain visible as a raw derived value, but do not force a misleading valuation label.

### 11.2 Profitability / quality

#### ROE

```text
roe_ttm = ttm_net_income / average_comparable_equity * 100
```

Use average opening/closing comparable equity when both are available; otherwise use latest comparable equity only if the fallback is explicitly implemented/tested. Equity <= 0 => unavailable.

#### ROA

```text
roa_ttm = ttm_net_income / average_comparable_total_assets * 100
```

#### ROCE

Use:

```text
capital_employed = equity + debt - cash_and_equivalents
roce_ttm = ttm_ebit / average_comparable_capital_employed * 100
```

Non-positive denominator => unavailable.

#### Operating Margin

```text
operating_margin_ttm = ttm_operating_profit / ttm_revenue * 100
```

#### EBITDA Margin

```text
ebitda_margin_ttm = ttm_ebitda / ttm_revenue * 100
```

#### Net Margin

```text
net_margin_ttm = ttm_net_income / ttm_revenue * 100
```

Revenue <= 0 => unavailable.

### 11.3 Solvency

#### Debt/Equity

```text
debt_equity_latest = latest_debt / latest_equity
```

Equity <= 0 => unavailable.

#### Net Debt

```text
net_debt_latest = latest_debt - latest_cash_and_equivalents
```

#### Net Debt/EBITDA

```text
net_debt_ebitda_ttm = latest_net_debt / ttm_ebitda
```

EBITDA <= 0 => unavailable.

#### Current Ratio

```text
current_ratio_latest = latest_current_assets / latest_current_liabilities
```

Current liabilities <= 0 => unavailable.

#### Interest Coverage

```text
interest_coverage_ttm = ttm_ebit / ttm_finance_cost
```

Finance cost <= 0 => unavailable.

### 11.4 Cash-quality metrics

#### Free Cash Flow

```text
fcf = operating_cash_flow - abs(capital_expenditure)
```

Both inputs mandatory.

#### FCF Margin

```text
fcf_margin_ttm = ttm_fcf / ttm_revenue * 100
```

#### OCF / Net Income

```text
ocf_net_income_ttm = ttm_operating_cash_flow / ttm_net_income
```

Non-positive net income => unavailable for the ratio.

### 11.5 Growth

Quarterly YoY:

- revenue
- net income
- EPS

compare latest quarter with same fiscal quarter previous year.

Annual YoY:

- revenue
- net income
- EPS

compare latest annual period with immediately prior comparable fiscal year.

Do not compare irregular transition periods.

### 11.6 3-year CAGR

Support only:

- revenue CAGR 3Y;
- net-income CAGR 3Y;
- EPS CAGR 3Y.

Use comparable annual periods only.

Do not add 5Y CAGR in V8.

### 11.7 Dividend metrics

#### Dividend Yield

Preferred derivation:

```text
dividend_per_share_ttm = abs(ttm_dividends_paid) / latest_shares_outstanding

dividend_yield_ttm = dividend_per_share_ttm / evaluation_date_price * 100
```

Only calculate where input semantics are trustworthy.

#### Payout Ratio

```text
payout_ratio_ttm = abs(ttm_dividends_paid) / ttm_net_income * 100
```

Net income <= 0 => unavailable.

### 11.8 Banks / NBFCs

Where mapped inputs exist, support a small sector-specific set such as:

- Net Interest Margin (only if source inputs permit a defensible formula or directly mapped trustworthy ratio);
- Gross NPA ratio;
- Net NPA ratio;
- Capital adequacy ratio;
- selected provisioning/asset-quality indicator where trustworthy.

Do not force industrial metrics such as EV/EBITDA, ROCE or Debt/Equity onto banks/NBFCs when they are economically inappropriate.

---

## 12. TTM engine

Create one shared TTM implementation used by APIs, UI history, Screeners and future ML.

### Requirements

A TTM flow is valid only when:

1. four distinct quarterly periods exist;
2. periods are consecutive/comparable;
3. the required fact exists in all four quarters;
4. every selected fact is PIT-eligible for the requested evaluation date;
5. there is no irregular period that makes the four-quarter sum misleading.

Do not silently substitute annual values.

The service should return structured unavailability reason codes where practical, e.g.:

- `missing_quarter`
- `missing_fact`
- `non_pit_input`
- `irregular_period`
- `invalid_denominator`

The investor UI may collapse these to `—`; APIs/tests should retain diagnostic reason where useful.

---

## 13. Historical valuation time series

Historical valuation metrics such as P/E and P/B must be available as a **daily derived series**.

For each trading date D:

```text
adjusted historical price on D
+
latest PIT-eligible fundamentals available on D
=> valuation metric on D
```

Rules:

- use adjusted prices for historical valuation charts;
- do not use future filings/restatements at date D;
- metric unavailable on dates lacking required inputs;
- no forward filling from non-PIT facts;
- chart frequency selection is presentation aggregation over the available daily series.

Do not persist the daily series as canonical truth. A rebuildable cache/materialization may be added only if profiling justifies it.

---

## 14. Historical bootstrap workflow

Introduce a dedicated `FundamentalBootstrapService` / command workflow independent of the normal incremental updater.

### 14.1 Start behavior

Bootstrap is **manual operator-triggered only** in V8.

Recommended command family, exact syntax architect-defined:

```text
stox:fundamentals-bootstrap --dry-run
stox:fundamentals-bootstrap --all
stox:fundamentals-bootstrap --stock=TCS
stox:fundamentals-bootstrap --stocks=TCS,INFY,RELIANCE
stox:fundamentals-bootstrap --status=failed
stox:fundamentals-bootstrap --status=complete_partial
```

No deployment hook automatically starts it.

### 14.2 Universe

Target only currently active StoX equity securities.

Benchmarks/index pseudo-stocks are excluded.

Inactive/delisted historical securities are out of scope.

### 14.3 Job granularity

One queued bootstrap job = one canonical stock.

The job internally handles:

- annual + quarterly;
- official source;
- Yahoo fallback;
- normalization;
- reconciliation;
- sanity checks;
- persistence;
- coverage classification.

### 14.4 Persistence model

Add conceptually:

- `stox_fundamental_bootstrap_runs`
- `stox_fundamental_bootstrap_jobs`

Minimum run fields:

- id
- trigger/source (`manual`)
- scope
- status
- requested stock count
- queued/running/completed/failed counts
- started_at
- completed_at
- created_by where applicable
- summary_json

Minimum stock-job fields:

- run_id
- stock_id
- status
- attempts
- started_at
- completed_at
- last_error
- quarterly_status
- annual_status
- earliest_period
- latest_period
- facts_inserted
- facts_upgraded
- facts_deduped
- facts_rejected
- anomalies_count
- coverage_json

Exact schema may vary, but restart-safe progress must be database persisted.

### 14.5 Job statuses

Use operational statuses as needed (`pending`, `running`, etc.) plus terminal quality classification:

- `complete_good`
- `complete_partial`
- `complete_no_data`
- `failed`

`complete_no_data` is not a technical failure.

### 14.6 Completion semantics

Do not impose an arbitrary minimum number of years.

A stock/cadence is complete when configured trustworthy sources are exhausted.

Quality classification considers:

- earliest/latest period;
- number of periods;
- core-fact coverage;
- optional-fact coverage;
- exact vs estimated vs non-PIT availability share;
- anomaly rejections;
- unresolved provider/mapping issues.

### 14.7 Resumability

Bootstrap progress must survive:

- SSH disconnect;
- queue worker restart;
- app deployment;
- transient provider failure.

Jobs are idempotent and may be rerun safely.

### 14.8 Targeted reruns

Support:

- one stock;
- explicit set of stocks;
- full active universe;
- status-based rerun (`failed`, `complete_partial`).

This allows mapping improvements without replaying the entire universe.

---

## 15. Source throttling and retries

Use simple source-specific throttling.

At minimum:

- configurable delay per provider;
- retry transient HTTP/network/rate-limit failures;
- bounded attempt count;
- respect existing Yahoo updater settings where reuse is sensible;
- provider failure does not corrupt already persisted facts;
- one provider failure may allow fallback where product rules permit.

Do not build adaptive rate algorithms in V8.

---

## 16. Basic anomaly checks

The bootstrap must reject obvious ingestion/mapping corruption without becoming a comprehensive accounting-validation engine.

Examples worth checking:

- impossible/non-finite numeric values;
- obvious unit-scale jumps (e.g. ~100x/1000x) inconsistent with nearby periods where not explained by mapping/unit metadata;
- percentage facts outside defensible hard bounds;
- shares outstanding near-zero or absurdly discontinuous without supporting evidence;
- quarterly data mapped into annual cadence or vice versa;
- invalid dates/period order;
- obviously malformed currency/unit conversion.

When one fact fails:

- reject that fact;
- continue processing the stock;
- increment anomaly/rejected counters;
- mark result `complete_partial` when the missing fact materially affects coverage.

Do not persist suspicious values merely with a warning flag.

---

## 17. Service architecture

Refactor responsibilities without breaking V7 consumers.

Recommended structure:

```text
FundamentalFactCatalog
  -> canonical fact definitions / labels / UI grouping

FundamentalFactService
  -> persistence
  -> PIT resolution
  -> source-quality reconciliation

FundamentalMetricService
  -> TTM
  -> ratios
  -> YoY
  -> CAGR
  -> valuation

FundamentalHistoryService
  -> multi-period statement history
  -> raw fact series
  -> derived metric series

FundamentalBootstrapService
  -> historical orchestration
  -> provider fallback
  -> coverage classification

FundamentalDataService
  -> compatibility façade for existing callers during migration
```

Do not force a big-bang removal of `FundamentalDataService` if compatibility wrappers reduce risk.

---

## 18. API contract

The existing stock fundamentals endpoint remains the primary entry point for current/snapshot fundamentals.

### 18.1 Snapshot

Conceptual endpoint:

```text
GET /api/v1/stocks/{stock}/fundamentals
```

Response should include:

- stock identity;
- `as_of`;
- latest reporting/freshness summary;
- compact annual/quarterly coverage summary;
- summary derived metrics;
- full supported metric snapshot where appropriate;
- minimal source labels;
- unavailability reason where useful.

The server resolves market price itself. The frontend should not need to pass `price` for normal investor use.

Backward compatibility for an existing optional `price` argument may be retained temporarily if tests/clients depend on it, but it must not be the main V8 path.

### 18.2 Multi-period statements

Add a history route or equivalent query contract, conceptually:

```text
GET /api/v1/stocks/{stock}/fundamentals/history?cadence=quarterly
GET /api/v1/stocks/{stock}/fundamentals/history?cadence=annual
```

Return period-major or metric-major data suitable for responsive multi-column tables.

Each cell/row must provide enough context for:

- display value;
- period;
- comparable-period change;
- minimal source label.

Do not expose debug-only hashes/internal IDs to investors.

### 18.3 Metric history

Conceptual route:

```text
GET /api/v1/stocks/{stock}/fundamentals/metrics/{metric}/history
```

Parameters:

- range, default `5y`;
- frequency/sampling, default monthly;
- basis/cadence only where the metric ID itself does not already encode it.

Return one metric series per request in V8.

Historical valuation endpoints may internally compute daily points and aggregate for requested presentation frequency.

### 18.4 API caching

Do not introduce authoritative derived storage.

Optional cache is permitted only when:

- rebuildable;
- keyed by stock/metric/as-of/range/frequency as appropriate;
- invalidated when relevant primary facts or market prices change.

Implement caching only when profiling demonstrates need.

---

## 19. Selected-stock UI integration

The existing Watchlist route remains the stock-detail entry surface:

```text
/watchlist/:symbol?
```

Non-watchlisted stocks must continue to be selectable/searchable and receive the same stock-detail tabs.

### 19.1 Tabbed layout

Refactor the selected-stock area into tabs, architect-defined labels/order approximately:

```text
Overview | Technicals | Fundamentals | Patterns
```

Do not create a separate Fundamentals navigation destination.

Existing watchlist membership actions, note behavior, selected-stock header and search remain available.

### 19.2 Fundamentals tab hierarchy

Order:

1. freshness + coverage context;
2. fixed 10-12 metric summary;
3. historical charts;
4. Basic financial data;
5. Advanced financial data (collapsed/remembered).

### 19.3 Freshness / coverage

Show one compact section-level freshness indicator such as:

- latest reported quarter/FY;
- last successful fundamentals update.

Show compact coverage such as:

```text
Quarterly history: 6.5 years
Annual history: 11 years
```

Do not show detailed ingestion diagnostics to investors.

### 19.4 Summary

Use approximately 10-12 fixed architect-defined fields.

General-company candidate set:

- Market Cap
- Enterprise Value
- P/E (TTM)
- P/B
- ROE (TTM)
- ROCE (TTM)
- Revenue Growth YoY — Quarterly
- EPS Growth YoY — Quarterly
- Net Margin (TTM)
- Debt/Equity
- FCF Yield
- Dividend Yield

The exact 10-12 rendered set may use responsive wrapping and sector-aware substitution.

Banks/NBFCs substitute appropriate metrics such as P/B, ROA/ROE, NIM, NPA ratios, capital adequacy where available, rather than forcing industrial ratios.

Summary rules:

- no qualitative labels;
- show contextual change where meaningful;
- no per-metric source/basis clutter;
- tooltip/help icon for formula/definition;
- unavailable => `—`, never fabricated zero.

### 19.5 Metric tooltips

Derived metric tooltip/help contains:

- short definition;
- formula;
- brief neutral interpretation where useful.

No large explanation panel in V8.

### 19.6 Investor-friendly formatting

Display examples:

- `₹1,234 Cr`
- `18.6%`
- `24.2x`

Canonical raw INR remains internal.

---

## 20. Statement tables

### 20.1 Basic section

Expanded by default.

Include important investor-facing facts grouped in fixed financial-statement order.

### 20.2 Advanced section

Collapsed by default but user expansion state is remembered per user.

Contains all remaining persisted investor-facing canonical facts and less-important metrics.

Product principle:

> If StoX persists a fundamental fact for investor analysis, the investor must have a reasonable way to inspect it in the stock-detail UI.

This does not require displaying internal hashes, run IDs or debug metadata.

### 20.3 Cadence toggle

Statement tables have their own:

```text
Quarterly | Annual
```

toggle independent of chart controls.

### 20.4 Multi-period layout

Display several periods side by side.

Desktop shows as many as fit naturally; narrow layouts use horizontal scrolling.

No sortable/reorderable financial rows in V8.

### 20.5 Inline change

Where meaningful, display compact comparable-period change with the value.

Examples:

```text
Revenue
Q1 FY27  ₹12,450 Cr  +8.2% YoY
```

Quarterly comparison = same quarter prior year.

Annual comparison = prior comparable FY.

Irregular periods => no growth value.

---

## 21. Chart architecture

Generalize the existing price-chart control infrastructure rather than duplicate it.

Recommended extraction:

```text
TimeSeriesChart
TimeSeriesChartControls
timeSeriesAggregation

PriceVolumeChart -> specialized wrapper
FundamentalMetricChart -> specialized wrapper
```

### 21.1 V8 chart rules

- one metric per chart;
- default range `5y`;
- default presentation frequency monthly;
- daily series available where appropriate;
- reuse existing range/frequency controls and familiar UX;
- chart data lazy-loads when relevant section/chart becomes visible/opened;
- raw facts and selected derived metrics may both be charted;
- annual and quarterly fact charts are not overlaid; use explicit cadence control;
- combo charts deferred to V9.

### 21.2 Frequency semantics

The current price chart uses row-bucket sampling. When generalizing, prefer true date-aware aggregation where needed so `1 month` represents calendar/month-end semantics rather than blindly assuming 30 rows.

Do not regress existing price-chart behavior while extracting shared controls.

---

## 22. Screener integration

Fundamental metrics extend the existing Screener condition framework.

Do not create a parallel Fundamental Screener type.

### 22.1 Metric IDs

Bases are explicit in IDs, for example:

```text
fundamental.pe_ttm
fundamental.pb_latest
fundamental.roe_ttm
fundamental.roa_ttm
fundamental.roce_ttm
fundamental.debt_equity_latest
fundamental.current_ratio_latest
fundamental.fcf_yield_ttm
fundamental.net_margin_ttm
fundamental.revenue_growth_yoy_quarterly
fundamental.revenue_growth_yoy_annual
fundamental.revenue_cagr_3y
fundamental.eps_growth_yoy_quarterly
fundamental.eps_growth_yoy_annual
fundamental.eps_cagr_3y
```

Use one stable naming convention.

### 22.2 Registry integration

Current Screener operands are projected from the Indicator Registry and evaluated through technical-indicator logic.

V8 should preserve one UI/catalogue surface but dispatch calculations by metric capability/type:

```text
Screener operand
  -> technical metric => existing TechnicalIndicatorService
  -> fundamental metric => FundamentalMetricService
```

Fundamental metrics should be discoverable in the existing catalogue/UI with a clear category such as `Fundamental`.

### 22.3 Eligibility only

FEAT-054 adds fundamentals only as boolean filter conditions.

Examples:

```text
P/E TTM <= 25
ROE TTM >= 15
Debt/Equity <= 0.5
Revenue Growth YoY Quarterly >= 10
```

Do not add new fundamental weighted-scoring semantics.

Existing `weight_factor` behavior should not be repurposed to create a fundamental score.

### 22.4 Missing metric

If a configured fundamental metric is unavailable:

```text
condition = false
```

Do not silently skip the condition.

### 22.5 Historical/backtest correctness

When Screeners are evaluated as-of a historical date, fundamental operands must resolve only PIT-eligible facts available at that date and the appropriate historical price for price-dependent metrics.

Technical-only Screeners remain unaffected by missing fundamentals.

---

## 23. Admin bootstrap visibility

V8 adds **read-only** Admin status, not a full operations console.

Show at minimum:

- latest bootstrap run;
- overall status;
- currently running/queued stock count;
- counts by terminal quality classification;
- failures and partials;
- basic annual/quarterly coverage summary;
- last error summary where useful.

V8 Admin UI does **not** need buttons for:

- start full bootstrap;
- pause/resume;
- retry individual job;
- rerun mappings;
- source-by-source operational controls.

Those are V9 wishlist scope.

---

## 24. Preferences

Advanced-section expanded/collapsed state is remembered per user.

For V8, use the simplest existing preference mechanism that fits:

- existing server-side user preference facility if already appropriate;
- otherwise a lightweight user-scoped browser persistence key.

Do not add a new complex preference subsystem solely for this state.

Summary-field customization is V9 scope.

---

## 25. Data-quality and availability rules

### 25.1 Missing means unavailable

Never substitute:

- EBIT for EBITDA;
- annual value for missing quarter;
- inferred balance-sheet component for a missing canonical fact;
- zero for missing fact.

### 25.2 Standalone fallback

For each company/period:

```text
consolidated available => use consolidated
else standalone available => use standalone
else unavailable
```

Do not mix consolidated and standalone inputs inside one derived metric for the same evaluation context unless a formula explicitly defines and tests such behavior (none are required in V8).

### 25.3 Sector applicability

If a metric is not economically appropriate for the stock sector/type, return unavailable rather than force it.

---

## 26. Performance principles

- Primary facts remain authoritative.
- Derived values are computed by shared services.
- Historical chart calls lazy-load.
- Do not precompute/store every daily valuation unless profiling proves necessary.
- If cache is introduced, it must be rebuildable and invalidated on relevant fact/price change.
- Bootstrap batching uses existing queue infrastructure and should not block web requests.
- Avoid N+1 fact/price queries in multi-metric snapshot/history endpoints.

---

## 27. Tests and acceptance criteria

Implementation is not complete until automated tests cover the following.

### 27.1 Canonical ingestion and reconciliation

1. Existing Yahoo fact + identical NSE value upgrades source/availability metadata without creating duplicate economic value.
2. Higher-priority official conflicting value replaces lower-priority Yahoo canonical value.
3. NSE official wins over BSE for same canonical ISIN.
4. BSE-only stock uses BSE official source.
5. Missing official fact falls back to Yahoo automatically.
6. Unmapped provider fields are ignored.
7. Missing currency for Indian-listed equity becomes INR.
8. Source crore/lakh/etc. values normalize to raw INR correctly.
9. Consolidated is preferred; standalone is used only when consolidated is unavailable.
10. Provider basis is not falsely labelled consolidated when not established.

### 27.2 PIT / availability

11. Exact publication date is PIT-eligible from that date only.
12. Estimated availability is PIT-eligible only from the deterministic conservative date.
13. Non-PIT fact is excluded from historical PIT metric/screener evaluation.
14. Historical evaluation never sees a future filing.

### 27.3 TTM and derived metrics

15. TTM succeeds with four valid consecutive quarters.
16. TTM is unavailable with one missing quarter.
17. TTM is unavailable with irregular/incomparable quarter sequence.
18. Missing capex makes FCF unavailable.
19. Negative EPS makes P/E unavailable.
20. Negative/non-positive EBITDA makes EV/EBITDA unavailable.
21. Non-positive equity makes ROE/PB unavailable where applicable.
22. Market cap/EV use evaluation-date price and latest PIT inputs.
23. Historical valuation uses adjusted price.
24. Dividend Yield/Payout Ratio follow defined missing/negative denominator rules.

### 27.4 Growth

25. Quarterly growth compares Qx against the same quarter prior year, not immediately previous quarter.
26. Annual growth compares comparable FY periods.
27. Irregular fiscal-year transition suppresses annual growth.
28. 3Y CAGR is available only with suitable comparable annual endpoints.

### 27.5 Bootstrap

29. Manual start creates one queued job per selected active stock.
30. Inactive stocks/benchmarks are excluded.
31. Restarted worker resumes persisted run/job state.
32. Rerunning same stock is idempotent.
33. Targeted stock rerun works.
34. Status-based partial/failed rerun works.
35. `complete_no_data` is terminal but not a technical failure.
36. Basic anomaly rejection skips only the bad fact and continues stock processing.
37. Partial coverage classification records anomaly/missing coverage.
38. Provider throttling/retry respects configured bounds.

### 27.6 APIs

39. Snapshot endpoint returns server-calculated derived metrics without requiring frontend formulas.
40. Multi-period history returns more than latest-period-only fact map.
41. Metric history returns requested one-metric series.
42. Default metric chart range is 5Y and default frequency monthly.
43. Historical valuation metric can return daily data when requested.

### 27.7 Investor UI

44. Watchlisted and non-watchlisted selected stocks both expose the same Fundamentals tab.
45. Selected-stock detail uses tabs without breaking existing watchlist actions/search.
46. Fundamentals summary shows ~10-12 fixed metrics with sector-aware substitutions.
47. No qualitative labels appear.
48. Metric tooltips show concise definition/formula.
49. Freshness and coverage indicators render compactly.
50. Basic section is expanded by default.
51. Advanced section contains remaining persisted investor facts.
52. Advanced expansion preference is remembered per user.
53. Quarterly/Annual table toggle is independent of chart controls.
54. Multi-period tables scroll responsively on narrow layouts.
55. Inline YoY/prior-FY change follows frozen comparison semantics.
56. Investor-friendly units format correctly.
57. Historical charts lazy-load.
58. One metric per chart; no V8 combo chart controls.

### 27.8 Screeners

59. Fundamental metric IDs appear in existing Screener catalogue/UI.
60. Fundamental conditions use `FundamentalMetricService`, not technical OHLCV calculation.
61. Missing fundamental metric makes that condition false.
62. Fundamental condition works with numeric constants/operators.
63. Historical Screener/backtest uses PIT-correct historical fundamentals/prices.
64. Technical-only Screeners remain unchanged.
65. No new fundamental scoring/ranking behavior is introduced.

### 27.9 Admin

66. Admin can inspect latest bootstrap run and status counts.
67. Admin can inspect failed/partial summary.
68. Investor cannot access Admin bootstrap status.
69. V8 Admin UI does not expose full start/pause/resume/retry operations.

---

## 28. Migration / implementation sequence

Codex should implement in small, reviewable stages.

### Stage 1 — Canonical model hardening

- add fact catalogue;
- add missing mapped fact keys;
- add availability-quality representation;
- fix basis/currency/unit normalization;
- implement quality-aware reconciliation;
- add tests.

### Stage 2 — Metric correctness

- extract/refactor shared metric service;
- fix TTM;
- fix YoY;
- fix FCF missing-input behavior;
- implement curated metric catalogue;
- implement sector applicability;
- add tests.

### Stage 3 — Historical official adapters

- NSE historical adapter;
- BSE historical adapter;
- fallback orchestration;
- sanity checks;
- provider throttling;
- tests with fixtures.

### Stage 4 — Bootstrap workflow

- run/job schema;
- queue job;
- CLI start/dry-run/targeted rerun;
- resumability/idempotency;
- coverage classification;
- read-only Admin status.

### Stage 5 — API/history

- snapshot improvements;
- multi-period statement history;
- metric history/daily valuation series;
- query optimization.

### Stage 6 — Watchlist stock-detail UI

- tab refactor;
- Fundamentals summary;
- Basic/Advanced tables;
- freshness/coverage;
- preference persistence;
- responsive behavior.

### Stage 7 — Shared chart refactor

- generalize time-series controls/components;
- preserve PriceVolumeChart behavior;
- add FundamentalMetricChart;
- lazy-load history;
- 5Y/monthly defaults.

### Stage 8 — Screener integration

- catalogue/registry exposure;
- evaluator dispatch;
- PIT historical support;
- UI compatibility;
- regression tests.

### Stage 9 — Runtime validation

- run dry-run coverage against production-like data;
- bootstrap a small representative stock set first;
- validate NSE+, BSE-only, industrial and bank/NBFC examples;
- inspect source upgrade behavior;
- verify UI and Screener output;
- then run broader active-universe bootstrap manually.

---

## 29. Non-regression requirements

FEAT-054 must not:

- change canonical stock identity rules;
- create duplicate BSE rows for NSE+ securities;
- bypass existing portfolio authorization boundaries;
- make fundamental absence affect technical-only Screeners;
- silently replace deterministic Strategy authority;
- treat notification/operational status as domain state;
- break existing Yahoo incremental freshness updates;
- require an Admin UI operation to run the V8 bootstrap;
- expose internal provenance/debug metadata prominently to investors;
- introduce user-defined formulas;
- introduce combo charts, export or summary customization that belong to V9.

---

## 30. V9 follow-on references

The V9 wishlist already carries follow-ons that are deliberately not part of FEAT-054:

1. **Historical Fundamentals Bootstrap Admin Operations** — full Admin start/pause/resume/retry/diagnostics UX.
2. **Data Export Framework** — export tables/chart series.
3. **Customizable Summary Fields & Dashboard Layouts** — investor-configurable cards/summary/layout.
4. **Combo Chart Support** — reusable multi-series/dual-axis comparison charts.

V8 should leave clean extension points but must not pre-implement these capabilities beyond what FEAT-054 requires.

---

## 31. Definition of done

V4-FEAT-054 is complete when:

1. the canonical fact catalogue and normalization gaps are implemented;
2. derived metrics obey the frozen formulas/missing-input semantics;
3. official NSE/BSE historical sources plus Yahoo fallback are implemented;
4. existing weak Yahoo history can be upgraded by better official evidence;
5. a resumable manual queue-backed bootstrap can process the active equity universe;
6. bootstrap completion/coverage is persisted and visible read-only in Admin;
7. snapshot, multi-period and historical metric APIs are implemented;
8. the selected-stock Watchlist detail exposes a functional Fundamentals tab with summary, charts, Basic and Advanced tables;
9. chart controls reuse/generalize the existing time-series UX;
10. selected fundamental metrics work as existing-framework Screener eligibility filters with PIT correctness;
11. all acceptance tests and V1-V7 non-regression tests are green;
12. representative runtime verification confirms official-source ingestion, Yahoo fallback, historical upgrade, investor UI and Screener behavior.

At that point FEAT-057 may safely consume the expanded historical fundamental dataset for ML feature design/retraining.
