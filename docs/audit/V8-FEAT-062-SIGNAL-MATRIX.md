# FEAT-062 deterministic signal-to-key matrix

This matrix maps the frozen initial catalogue in
`docs/archive/specs/V8-Fundamental-Signals-AI-Insights-Specification.md` to
the deterministic output contract. `PARTIAL` means the available source data
or required comparison has not yet been implemented as a dedicated signal;
it is not inferred from a related signal.

| Frozen requirement | Signal key(s) | Required inputs / basis | PIT requirement | Evidence | Status |
|---|---|---|---|---|---|
| Revenue growth acceleration/deceleration | `revenue_growth_accelerating`, `revenue_growth_decelerating` | Comparable quarterly YoY trend | availability date <= as-of | current/prior YoY and delta | PASS |
| Net-income/EPS divergence from revenue growth | `earnings_revenue_divergence` | Quarterly YoY growth pair | comparable periods | both growth values and delta | PASS |
| Operating-profit versus bottom-line divergence | — | Operating profit and net income comparable growth | comparable periods | — | PARTIAL |
| Abrupt margin expansion/compression | `operating_margin_movement_*`, `ebit_margin_movement_*`, `net_margin_movement_*` | Same-period YoY margins | comparable periods | current/prior margin and pp delta | PASS |
| ROE/ROA/ROCE movement against company history | `roe_strong`, `roe_weak`, `roe_movement_expanding`, `roe_movement_contracting`, `roe_movement_stable` | ROE same-period YoY movement with positive-equity denominator | availability date <= as-of; incompatible/missing periods unavailable | current/prior ROE and pp delta | PARTIAL — ROA/ROCE remain data-dependent |
| Other-income / exceptional-item dependence | — | Structured other-income/exceptional facts | availability date <= as-of | — | PARTIAL |
| OCF versus net income | `strong_ocf_vs_net_income`, `weak_ocf_vs_net_income` | TTM OCF and net income | availability date <= as-of | both values | PASS |
| Multi-period OCF/net-income deterioration or improvement | `earnings_cash_divergence`, `cash_quality_follow_up` | Quarterly YoY OCF/income divergence | comparable periods | both growth values | PARTIAL |
| Positive earnings with weak/negative OCF | `negative_fcf_with_profit`, `weak_ocf_vs_net_income` | TTM profit and cash measures | availability date <= as-of | both values | PASS |
| FCF direction and persistence | `fcf_improving`, `fcf_deteriorating` | Consecutive comparable FCF periods | comparable periods | latest/prior comparable YoY growth and trend delta | PASS |
| Capex intensity changes | — | Capex/revenue or capex/asset history | comparable periods | — | PARTIAL |
| Receivables faster than revenue | `receivables_growth_vs_revenue` | Quarterly YoY growth spread | comparable periods | both growth values and spread | PASS |
| Inventory faster than sales/revenue | `inventory_growth_vs_revenue` | Quarterly YoY growth spread | comparable periods | both growth values and spread | PASS |
| Working-capital absorption/release | — | Receivables/inventory/current-liability relationship | comparable periods | — | PARTIAL |
| Current-assets/current-liabilities changes | — | Balance-sheet history | comparable periods | — | PARTIAL |
| Debt versus equity/cash growth | `debt_cash_divergence` | Debt and OCF growth relationship | comparable periods | both growth values | PASS |
| Net-debt direction | `net_debt_increasing`, `net_debt_decreasing` | Derived debt less cash, same-period YoY | comparable periods; invalid prior net debt is unavailable | net-debt YoY percentage and basis | PASS |
| Debt/equity and net-debt/EBITDA | `leverage_elevated`, `debt_to_ebitda_elevated` | TTM ratios with safe denominators | availability date <= as-of | ratio and basis | PASS |
| Interest-coverage deterioration | `interest_coverage_thin` | TTM interest coverage | availability date <= as-of | ratio and basis | PASS |
| Liquidity/coverage changes | — | Liquidity/coverage history | comparable periods | — | PARTIAL |
| Material share-count dilution | `share_count_dilution`, `dilution_follow_up` | Quarterly YoY shares | comparable periods | growth and basis | PASS |
| CWIP growth/share/persistence | `cwip_expansion_ambiguous` | Annual CWIP growth and share of PPE | availability date <= as-of | growth/share and follow-up | PASS |
| Promoter/pledge/FII/DII/public changes | `ownership_*_movement` | Same-period ownership pairs | availability date <= as-of | current/prior/delta | PASS |
| Ownership changes combined with financial signals | — | Ownership plus related financial context | both sources PIT-safe | — | PARTIAL |
| Historical valuation-range deviation | — | Historical valuation distribution | price/fundamental as-of | — | PARTIAL |
| Reliable sector/peer percentile | `sector_roe_*`, `sector_leverage_above_peers`, `sector_pe_above_peers` | Dated sector snapshot and peer values | dated membership/snapshot required | subject/peer/delta/basis | PASS |
| Valuation divergence from earnings/cash | — | Valuation plus earnings/cash trend | both sources PIT-safe | — | PARTIAL |
| 52-week price context alone | intentionally none | Explicitly not a fundamental signal | n/a | n/a | NOT APPLICABLE — prohibited by frozen spec |

Every emitted signal is normalized by
`app/app/Services/Fundamentals/FundamentalSignalsService.php` with a stable
key, category, direction, summary, evidence, basis, period, severity,
confidence and provenance. The remaining `PARTIAL` rows are the next
implementation scope; they are not promoted to `PASS` by the existence of a
neighboring signal.
