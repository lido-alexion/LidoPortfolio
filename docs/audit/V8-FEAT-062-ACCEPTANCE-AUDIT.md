# FEAT-062 Fundamental Signals & AI Insights — acceptance audit

Status: **REVIEW — implementation and automated verification complete; accessibility/real-provider validation pending**

This audit is evidence-based against `docs/archive/specs/V8-Fundamental-Signals-AI-Insights-Specification.md`. The deterministic catalogue mapping and PIT comparison implementation are complete for structured source facts; unavailable source facts remain explicitly unavailable. Local Chromium desktop/mobile browser acceptance is covered; screen-reader accessibility and production provider validation remain external evidence gates.

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
| Data sufficiency | PASS locally for implemented inputs | Missing evidence, sparse quarterly history, stale facts, fallback provider, and mixed-provider basis contribute explicit deterministic weighting/score; historical freshness is represented in provenance and confidence |
| Investor insights UI | PASS locally / external accessibility pending | `FundamentalInsightsPage.jsx`, `FundamentalInsightsSignals.jsx`, and `fundamental-insights-browser.spec.js`; deterministic evidence remains visible when AI is unavailable, API/provider failure renders a warning, and the evidence card remains within a 390px viewport |
| Production provider/runtime proof | EXTERNAL VALIDATION PENDING | No real Gemini/Codex provider call is claimed in this environment |
| Recommendation prohibition and credential safety | PASS locally | prompt/validator tests and server-side configuration; external provider review remains |

## Verification

- Targeted FEAT-062 Laravel tests after the catalogue/input-boundary slices: **31/31, 101 assertions** across deterministic signals, sector comparisons, AI response handling, provider diagnostics and usage limits; AI provider/boundary suites remain covered by the existing focused tests.
- Focused Chromium browser acceptance: **3/3 passed** locally with deterministic API mocks, covering deterministic evidence, AI/provider-unavailable degradation, and a 390px mobile viewport. Screen-reader/accessibility review and real-provider validation remain external.
- Full Feature suite: **1,344 passed / 1,345 total / 1 extension-only skip / 0 failures** under PHP CLI `memory_limit=512M` with the documented matching ML runtime.
- Frontend Node suite: **188/188**.
- Vitest: **99/99**.
- Build, typecheck, static docs check and `git diff --check`: passed.

## Next implementation slice

The deterministic catalogue matrix is complete for all frozen rows where structured source data is available; absent data produces no invented signal. Desktop and narrow-mobile browser acceptance now cover deterministic evidence and provider failure degradation. Screen-reader/accessibility review and real-provider validation remain external, so the epic is REVIEW, not COMPLETE.
