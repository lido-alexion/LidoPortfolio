# V5 FEAT-015 — Tax Reporting, Attribution, Benchmarks, and Risk

**Status:** DECIDED / FROZEN  
**Date:** 2026-09-07

## Problem
StoX needs coherent investment-performance, benchmark, attribution, risk and India-focused capital-gains tax reporting while preserving separation between accounting truth, tax-lot derivation, Portfolio/Strategy ownership, dividends and current authoritative historical data.

## Frozen behaviour

### Performance
- Cash-flow-adjusted investment performance is authoritative; **XIRR** is the primary money-weighted measure.
- **TWR** is also provided and is the canonical basis for benchmark comparison.
- Performance exists at Account, Portfolio and Strategy levels where underlying capital/ownership history is trustworthy.
- Account performance is independently computed from Account-level included portfolios; it is not an average of Portfolio returns.
- Paper/simulation portfolios are excluded from real Account performance by default; tax inclusion and performance inclusion are separate settings.
- Strategy XIRR is shown only where authoritative logical capital history exists; no synthetic Strategy cash account is invented.
- Cash is part of wealth; idle cash earns zero return unless explicit income exists, so cash drag is real.
- External flows are neutralized appropriately for TWR and dated as actual external flows for XIRR.
- Trading charges reduce wealth/performance but must not be double-counted as external flows.
- Loans/recalls/bridge principal are capital reallocations, not returns; no invented interest.
- Performance is daily/EOD in V5, not intraday.
- Current authoritative ledger/history is used; backdated corrections may rebuild and change current results with stale/incomplete status disclosed.

### Benchmarks
- Default benchmark: **NIFTY 50 TRI**.
- Platform maintains a Benchmark Catalogue with stable IDs, provenance and lifecycle; alternative supported benchmarks may be selected.
- Portfolio has a persisted primary benchmark plus optional analytical comparison benchmarks.
- Strategy inherits Portfolio default unless an explicit Strategy benchmark override exists.
- Account benchmark is computed independently for Account performance.
- Benchmark endpoint uses latest authoritative level on or before requested date; no interpolation.
- A selected benchmark applies consistently across the whole requested period; do not splice different benchmarks automatically.
- Use **Excess Return**, not unsupported claims of statistical/factor alpha.

### Risk metrics
- V5 core risk set: volatility, maximum drawdown and Sharpe ratio.
- Returns use daily observations.
- Volatility/Sharpe require at least 30 observations.
- Annualization defaults to 252 trading days and is platform-configurable.
- Sharpe risk-free assumption is versioned platform configuration with optional Account override.
- Maximum drawdown discloses the evaluated period.

### Attribution
- Attribution is reconciliation-based, not causal storytelling.
- Portfolio attribution includes Strategy dimensions and Unmanaged as a first-class residual/ownership category.
- Realized/unrealized attribution follows WAVG accounting/performance semantics, not tax FIFO lots.
- Actual trading charges are allocated proportionally where necessary and must reconcile to Portfolio results.
- Attribution applies over period A→B and exposes reconciliation residual/incomplete status rather than forcing unexplained amounts into a category.
- Corporate actions preserve performance continuity and must not create fake return.

### Tax model
- Tax reporting is India-focused investment capital-gains estimation, not an ITR/general tax engine or certified advice.
- Canonical accounting remains WAVG. Tax uses a **separate derived tax-lot model**.
- V5 tax-lot matching is deterministic **FIFO**; Investor cannot select another lot method.
- Tax follows physical Portfolio transaction history, not Strategy ownership/internal netting.
- Only realized disposals enter FY capital-gains tax; open unrealized gains are informational.
- In-kind transfers preserve tax-lot lineage and are not disposals.
- Corporate-action tax treatment uses explicit platform-controlled rules; unsupported cases are marked incomplete rather than guessed.
- Tax assumptions/config hierarchy is `Platform → Account → Portfolio` where override is permitted.
- Structural/legal rules remain platform-controlled and effective-versioned; Investor cannot program arbitrary statutory logic.
- Tax configuration is audited, versioned and effective-dated.
- Fees/charges have platform tax classifications.
- Estimated tax never changes canonical pre-tax Portfolio performance.
- V5 does not compute hypothetical liquidation tax as though unrealized holdings were sold.

### Losses and opening history
- Track calculated tax losses separately from confirmed carry-forward losses.
- Support explicit external/opening carry-forward loss records subject to platform set-off rules.
- Where pre-StoX acquisition lineage is missing, support **Opening Tax Lots** for tax-only continuity.
- Opening Tax Lots do not alter accounting WAVG, holdings history or performance truth.
- Completeness must expose limitations where original tax lineage cannot be reconstructed.

### Dividends
- Dividends are Account-level investment income evidence in V5; do not fabricate Portfolio/Strategy attribution when source data cannot support it.
- Support manual dividend recording plus broker-statement import using versioned platform import definitions.
- Import must be robustly deduplicated.
- Do not invent Investor-programmable mapping/import schemas in V5.
- Zerodha dividend statement support is conceptually in scope after validation against real sample format.
- Recorded dividend amount is used; V5 does not invent TDS/withholding fields where the source does not provide them.
- Account tax presents dividend income separately from STCG/LTCG under configured assumptions.

### Inclusion and what-if
- Account Tax has a canonical configured inclusion set.
- Paper/simulation portfolios are excluded from real Account tax by default.
- Tax inclusion and Performance inclusion are independent.
- Temporary **What-if** selection may calculate analytical Account Tax/Performance without mutating canonical inclusion configuration.
- Distinguish Configured calculation from What-if calculation clearly.

### Evidence and completeness
- Tax calculation evidence is immutable/lightweight for reports/exports; the current screen may recalculate from current authoritative data.
- Performance/benchmark/tax evidence retains relevant assumptions, versions and completeness.
- Completeness states: `Complete | Estimate with limitations | Incomplete` (exact UI wording may be normalized while preserving semantics).
- Historical data corrections/backfills may change current recalculation; preserved calculation/report evidence remains auditable.

### Tax exports
- Provide tax-oriented CSV exports for realized gains/lots, assumptions/evidence, losses/dividends and relevant FY summary datasets.
- Export security/cutoff semantics should reuse FEAT-013 rather than creating another export subsystem.

## Architecture
- Accounting WAVG ledger remains authoritative for holdings/performance accounting.
- Tax lots are a separate deterministic derived layer from physical Portfolio transactions.
- Benchmark Catalogue and tax/risk assumptions use stable versioned configuration/provenance.
- Daily aligned holdings+cash/value/completeness data consumes FEAT-013/F014/F015 historical valuation foundations.
- Attribution must reconcile to authoritative Portfolio economics, not maintain an independent wealth ledger.

## UX
- Provide Account/Portfolio/Strategy Performance views where applicable.
- Portfolio performance includes XIRR/TWR, benchmark/Excess Return, risk and attribution with completeness disclosure.
- Account Tax presents FY realized STCG/LTCG, losses/carry-forward context, dividends, assumptions, completeness and tax exports.
- Clearly distinguish real/configured results from What-if analysis.
- No claim of certified tax filing/advice.

## Acceptance criteria
1. XIRR and TWR produce reproducible cash-flow-adjusted results from authoritative daily/flow history.
2. TWR benchmark comparison defaults to NIFTY 50 TRI and uses one selected benchmark consistently across the period.
3. Risk metrics enforce daily-data/minimum-observation assumptions and disclose configuration/evidence.
4. Portfolio attribution reconciles across Strategy/Unmanaged/charges/residual without causal overclaiming.
5. Tax accounting uses FIFO derived lots while canonical Portfolio accounting remains WAVG.
6. Only realized disposals enter capital-gains tax; unrealized positions remain informational.
7. In-kind transfers preserve tax-lot lineage; unsupported corporate-action tax cases become incomplete rather than fabricated.
8. Opening Tax Lots can restore tax lineage without changing accounting/performance history.
9. Dividends are captured at Account level with deduplicated import/manual evidence and remain separate from STCG/LTCG.
10. Paper/simulation is excluded from real Account tax/performance by default and inclusion controls remain independent.
11. Configured vs What-if calculations are distinguishable and What-if does not mutate canonical settings.
12. Tax/performance exports and evidence retain assumptions/version/completeness and use authorized FEAT-013 export semantics.

## Dependencies
- FEAT-013 cash/history/export/compare foundations.
- Existing transaction/WAVG/corporate-action/ownership history.
- FEAT-007/008 artifact identity/version evidence where Strategy-level results refer to artifact versions.
- FEAT-020 consumes these performance/risk/benchmark metrics for simulation results but Paper remains outside real Account tax by default.

## Non-goals
- ITR filing/general income-tax engine.
- Certified tax advice.
- Automatic tax optimization or tax-loss-harvesting recommendations.
- Hypothetical liquidation of unrealized positions for canonical tax.
- Intraday performance/risk.
- Factor-model/risk-adjusted alpha.
- Custom Investor-authored benchmark data series in V5.
- Strategy-level tax attribution.
- Investor-selectable lot-matching methods.
- Investor-programmable broker import schemas.
