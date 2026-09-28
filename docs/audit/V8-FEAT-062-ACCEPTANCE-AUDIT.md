# FEAT-062 Fundamental Signals & AI Insights — acceptance audit

Status: **IN PROGRESS**

This audit is evidence-based against `docs/archive/specs/V8-Fundamental-Signals-AI-Insights-Specification.md`. The deterministic signal and provider foundation exists, but the full initial catalogue, browser acceptance, and production provider validation are not yet complete.

## Evidence matrix

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Deterministic-first signal layer | PASS | `app/app/Services/Fundamentals/FundamentalSignalsService.php`; `FundamentalSignalsTest.php` |
| Initial growth/profitability and cash-flow signals | PARTIAL | ROE, revenue growth, same-period margin movement, FCF, OCF/net-income, earnings/OCF divergence and working-capital comparisons are covered; broader margin/earnings trend catalogue remains |
| Ambiguous CWIP/watch semantics | PASS | CWIP is a watch item, not a conclusion; deterministic follow-up checks identify disclosures to review |
| Evidence attached to surfaced signals | PASS | Stable signal key/category/direction/title/summary/evidence/basis/period/severity/confidence/provenance fields are returned while legacy headline/metric_values remain compatible |
| Leverage/debt and capital structure | PASS locally | Debt YoY movement and share-count dilution signals are covered by `FundamentalSignalsTest.php`; denominator/missing-data safeguards remain |
| Ownership/shareholding evidence | PASS when facts exist | Optional promoter/FII/DII/public/pledge catalogue keys produce neutral factual watch signals; absent ownership data remains unavailable |
| Comparison-aware evidence | PARTIAL | Same-period YoY comparison is implemented; sector context exists, while broader peer/history comparison rules remain to be audited |
| Provider-neutral Gemini/Codex boundary | PASS | `FundamentalInsightsAiProvider`, Gemini/Codex adapters and orchestrator |
| Primary/secondary failover | PASS locally | `FundamentalAiInsightsTest.php`; live provider validation remains external |
| Response schema and safety validation | PASS locally | `FundamentalInsightsResponseValidator`; bounded lists/fields, malformed/partial normalization, prohibited recommendation variants, and factual “holding” language coverage |
| Graceful dual-provider failure | PASS locally | deterministic payload remains available with `ai.status=unavailable` |
| Admin provider preference/diagnostics | PASS locally | `FundamentalAiAdminDiagnosticsTest.php`; deployed multi-worker audit remains |
| Usage limits and telemetry | PASS locally | `FundamentalAiUsageLimitTest.php`; provider cost/latency evidence is persisted locally |
| Follow-up evidence guidance | PASS locally | CWIP, receivables, inventory, cash-quality, debt, dilution and ownership signals map to investigation prompts; prompts are not conclusions |
| Data sufficiency | PARTIAL | Deterministic missing-evidence markers and low/medium/high rating are emitted; stale/fallback weighting and full catalogue coverage remain |
| Investor insights UI | PARTIAL | `FundamentalInsightsPage.jsx` and `FundamentalInsightsSignals.jsx` exist and render deterministic/AI/follow-up states; browser accessibility/mobile acceptance remains |
| Production provider/runtime proof | EXTERNAL VALIDATION PENDING | No real Gemini/Codex provider call is claimed in this environment |
| Recommendation prohibition and credential safety | PASS locally | prompt/validator tests and server-side configuration; external provider review remains |

## Verification

- Targeted FEAT-062 Laravel tests after the catalogue/input-boundary slices: **11/11, 38 assertions** for deterministic signals and **4/4, 18 assertions** for AI providers/boundary.
- Full V8 Laravel directory suite: **147/147, 547 assertions**.
- Frontend Node suite: **188/188**.
- Vitest: **99/99**.
- Build, typecheck, static docs check and `git diff --check`: passed.

## Next implementation slice

Complete the deterministic catalogue using canonical FEAT-054 facts: margin movement, earnings/cash-quality divergence, leverage/debt movement, dilution/ownership where data is available, and evidence-aware comparison rules. Add requirement-level tests before reconsidering this status.
