# FEAT-062 Fundamental Signals & AI Insights — acceptance audit

Status: **IN PROGRESS**

This audit is evidence-based against `docs/archive/specs/V8-Fundamental-Signals-AI-Insights-Specification.md`. The deterministic signal and provider foundation exists. The detailed frozen catalogue mapping is in `docs/audit/V8-FEAT-062-SIGNAL-MATRIX.md`; its remaining PARTIAL rows, browser acceptance, and production provider validation are not yet complete.

## Evidence matrix

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Deterministic-first signal layer | PASS | `app/app/Services/Fundamentals/FundamentalSignalsService.php`; `FundamentalSignalsTest.php` |
| Initial growth/profitability and cash-flow signals | PARTIAL | ROE, revenue growth, same-period operating/EBIT/net margin movement, FCF, OCF/net-income, earnings/OCF divergence and working-capital comparisons are covered; the frozen catalogue still needs a formal requirement-to-key matrix and any remaining mandated metrics |
| Ambiguous CWIP/watch semantics | PASS | CWIP is a watch item, not a conclusion; deterministic follow-up checks identify disclosures to review |
| Evidence attached to surfaced signals | PASS | Stable signal key/category/direction/title/summary/evidence/basis/period/severity/confidence/provenance fields are returned while legacy headline/metric_values remain compatible |
| Leverage/debt and capital structure | PASS locally | Debt and derived net-debt YoY movement, elevated debt/EBITDA, thin interest coverage, and share-count dilution are covered with invalid-denominator omission; broader corporate-action context remains data-dependent |
| Ownership/shareholding evidence | PASS when facts exist | Optional promoter/FII/DII/public/pledge catalogue keys produce neutral factual watch signals; absent ownership data remains unavailable |
| Comparison-aware evidence | PARTIAL | Same-period YoY, growth acceleration, FCF/net-debt trends, earnings/revenue and debt/cash relationships, and dated ML-universe sector peer percentile evidence exist with explicit subject/comparison/delta/basis fields. The remaining frozen comparison-key matrix still requires closure; missing dated snapshots now return an explicit coverage state rather than current-peer fallback |
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

- Targeted FEAT-062 Laravel tests after the catalogue/input-boundary slices: **20/20, 83 assertions** across deterministic signals, sector comparisons, AI response handling, provider diagnostics and usage limits; AI provider/boundary suites remain covered by the existing focused tests.
- Full V8 Laravel directory suite: **161/161, 611 assertions**.
- Frontend Node suite: **188/188**.
- Vitest: **99/99**.
- Build, typecheck, static docs check and `git diff --check`: passed.

## Next implementation slice

Complete the PARTIAL rows in `V8-FEAT-062-SIGNAL-MATRIX.md` and remaining comparison keys, then perform browser acceptance and real-provider validation before reconsidering this status. Current status remains **IN PROGRESS** because mandatory catalogue implementation remains.
