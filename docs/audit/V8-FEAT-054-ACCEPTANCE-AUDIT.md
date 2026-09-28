# V8 FEAT-054 Acceptance Audit

Date: 2026-09-28
Status: **REVIEW — local implementation and automated verification complete; browser/provider runtime evidence pending**

Authoritative contract: `docs/archive/specs/V8-Historical-Fundamentals-Bootstrap-Specification.md`.

## Requirement matrix

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Canonical fact catalogue and normalization | PASS | `fundamentals_catalog.php`, `FundamentalDataService`, normalizer and historical-source tests. |
| Official NSE/BSE source priority with Yahoo fallback | PASS | `FundamentalHistoricalIngestService` ranks official sources before Yahoo; `NseOfficialFundamentalHistoricalTest`, `BseOfficialFundamentalHistoricalTest`, and bootstrap workflow tests pass. |
| Historical bootstrap for active equities | PASS | `FundamentalBootstrapService` creates bounded queue-backed one-stock jobs, persists progress/status, retries failures, and stores partial completion; `FundamentalBootstrapWorkflowTest`. |
| Idempotency and duplicate-period handling | PASS | `FundamentalDataService::storeFacts` revision identity/current-row semantics plus bootstrap rerun tests. |
| PIT availability and source metadata | PASS | API fact filtering, availability dates, provider/source metadata, snapshot provenance, and PIT Screener tests. |
| TTM exactly four comparable consecutive quarters | PASS | `FundamentalMetricCorrectnessTest` covers missing, valid, five-quarter/latest-four, and non-consecutive chains; bank/NBFC TTM continuity is also tested. |
| Quarterly YoY semantics | PASS | Same-period prior-year comparison is implemented and tested; missing/zero denominator remains unavailable. |
| FCF and denominator safety | PASS | Missing OCF/capex and non-positive EPS tests return unavailable rather than fabricated values. |
| Curated derived metrics and fixed summary | PASS | Snapshot exposes 12 fixed metrics across valuation, profitability, growth, leverage, cash flow, margins, and yield; market cap/EV/margins/dividend/FCF metrics preserve missing-input semantics. |
| Basic and collapsed Advanced statement tables | PASS | `FundamentalStatementTables.jsx` renders fixed Basic rows, all remaining persisted rows under collapsed Advanced, cadence toggle, responsive table, and inline YoY. |
| Per-user Advanced preference | PASS locally | Browser preference is namespaced by authenticated user ID, safe for anonymous/default state, and invalid storage falls back closed; browser multi-user session journey remains pending. |
| Definitions/tooltips and provenance | PASS locally | Summary definitions explain basis/formula without recommendations; API exposes provider, period, availability, currency and derived/source label metadata. |
| Historical charts and tables | PASS locally | Watchlist lazy-loads snapshot/history, quarterly/annual statement history, revenue/valuation series, and monthly/daily valuation frequency; sparse/no data remains unavailable. Browser visual acceptance remains pending. |
| Screener eligibility boundary | PASS | Selected `fund_*` operands use the canonical metric service, PIT values, and unavailable semantics; `FundamentalScreenerOperandTest` and related bank tests pass. |
| Authorization and account isolation | PASS | Authenticated stock fundamentals routes and existing portfolio/profile middleware; V8 API tests cover intended investor access and Admin boundaries. |
| Official upgrade/fallback auditability | PASS locally | Deterministic source ranking and canonical revision storage allow later official rows to replace lower-ranked fallback rows; live provider upgrade exercise remains external. |
| Production/runtime acceptance | EXTERNAL VALIDATION PENDING | Representative provider calls, real active-universe bootstrap duration, mobile/deployed queue/runtime proof remain to be exercised. Local Chromium now covers the Watchlist fundamentals summary, provenance, historical Basic/Advanced tables and user-scoped preference reload (1/1 focused journey). |

## Verification executed

- Focused fundamentals Laravel tests: **15 passed, 38 assertions** after this continuation.
- Existing V8 Laravel baseline remains green: **148 passed, 546 assertions** before the new focused additions.
- JS fundamentals display/preference tests pass; typecheck passes.
- Existing source-priority, Screener, bootstrap, historical API, and metric correctness tests pass.

## Status decision

FEAT-054 is **REVIEW** rather than COMPLETE because the repository evidence and focused desktop Chromium journey are complete for the deterministic implementation, but mobile visual acceptance and real provider/deployed bootstrap runtime validation remain external.
