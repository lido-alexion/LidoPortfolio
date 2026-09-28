# FEAT-062 Fundamental Signals & AI Insights — acceptance audit

Status: **IN PROGRESS**

This audit is evidence-based against `docs/archive/specs/V8-Fundamental-Signals-AI-Insights-Specification.md`. The deterministic signal and provider foundation exists. The detailed frozen catalogue mapping is in `docs/audit/V8-FEAT-062-SIGNAL-MATRIX.md`; its remaining PARTIAL rows, browser acceptance, and production provider validation are not yet complete.

## Evidence matrix

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Deterministic-first signal layer | PASS | `app/app/Services/Fundamentals/FundamentalSignalsService.php`; `FundamentalSignalsTest.php` |
| Initial growth/profitability and cash-flow signals | PASS where source data exists | ROE/ROA/ROCE movement, operating-profit/bottom-line divergence, other-income/exceptional dependence, capex intensity, persistent earnings/cash divergence and working-capital balance-sheet/liquidity signals use PIT comparable facts; unavailable source facts remain explicitly absent |
| Ambiguous CWIP/watch semantics | PASS | CWIP is a watch item, not a conclusion; deterministic follow-up checks identify disclosures to review |
| Evidence attached to surfaced signals | PASS | Stable signal key/category/direction/title/summary/evidence/basis/period/severity/confidence/provenance fields are returned while legacy headline/metric_values remain compatible |
| Leverage/debt and capital structure | PASS locally | Debt and derived net-debt YoY movement, elevated debt/EBITDA, thin interest coverage, and share-count dilution are covered with invalid-denominator omission; broader corporate-action context remains data-dependent |
| Ownership/shareholding evidence | PASS when facts exist | Optional promoter/FII/DII/public/pledge catalogue keys produce neutral factual watch signals; aligned promoter/debt movement adds contextual watch evidence; absent ownership data remains unavailable |
| Comparison-aware evidence | PASS where source data exists | Same-period YoY, multi-period persistence, operating-profit/bottom-line, working-capital/liquidity, capex intensity, ownership/debt context, valuation/earnings-cash divergence, PIT historical P/E range, FCF/net-debt trends, earnings/revenue and debt/cash relationships, and dated ML-universe sector peer percentile evidence expose explicit subject/comparison/delta/basis fields |
| Provider-neutral Gemini/Codex boundary | PASS | `FundamentalInsightsAiProvider`, Gemini/Codex adapters and orchestrator |
| Primary/secondary failover | PASS locally | `FundamentalAiInsightsTest.php`; live provider validation remains external |
| Response schema and safety validation | PASS locally | `FundamentalInsightsResponseValidator`; bounded lists/fields, malformed/partial normalization, prohibited recommendation variants, and factual “holding” language coverage |
| Graceful dual-provider failure | PASS locally | deterministic payload remains available with `ai.status=unavailable` |
| Admin provider preference/diagnostics | PASS locally | `FundamentalAiAdminDiagnosticsTest.php`; deployed multi-worker audit remains |
| Usage limits and telemetry | PASS locally | `FundamentalAiUsageLimitTest.php`; provider cost/latency evidence is persisted locally |
| Follow-up evidence guidance | PASS locally | CWIP, receivables, inventory, cash-quality, debt, dilution and ownership signals map to investigation prompts; prompts are not conclusions |
| Data sufficiency | PASS locally for implemented inputs | Missing evidence, sparse quarterly history, stale facts, fallback provider, and mixed-provider basis contribute explicit deterministic weighting/score; historical freshness policy and the full frozen catalogue matrix remain bounded gaps |
| Investor insights UI | PARTIAL | `FundamentalInsightsPage.jsx` and `FundamentalInsightsSignals.jsx` exist and render deterministic/AI/follow-up states; browser accessibility/mobile acceptance remains |
| Production provider/runtime proof | EXTERNAL VALIDATION PENDING | No real Gemini/Codex provider call is claimed in this environment |
| Recommendation prohibition and credential safety | PASS locally | prompt/validator tests and server-side configuration; external provider review remains |

## Verification

- Targeted FEAT-062 Laravel tests after the catalogue/input-boundary slices: **31/31, 101 assertions** across deterministic signals, sector comparisons, AI response handling, provider diagnostics and usage limits; AI provider/boundary suites remain covered by the existing focused tests.
- Full V8 Laravel directory suite: **161/161, 611 assertions**.
- Frontend Node suite: **188/188**.
- Vitest: **99/99**.
- Build, typecheck, static docs check and `git diff --check`: passed.

## Next implementation slice

The deterministic catalogue matrix is now complete for all frozen rows where structured source data is available; absent data produces no invented signal. Remaining acceptance work is browser accessibility/mobile validation and real-provider validation. Status can move to REVIEW once those bounded external checks are recorded.
