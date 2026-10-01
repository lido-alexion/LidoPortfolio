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
| Per-user Advanced preference | PASS locally | Browser preference is namespaced by authenticated user ID, safe for anonymous/default state, invalid storage falls back closed, and the Chromium journey proves user A's expanded state is not inherited by user B. |
| Definitions/tooltips and provenance | PASS locally | Summary definitions explain basis/formula without recommendations; API exposes provider, period, availability, currency and derived/source label metadata. |
| Historical charts and tables | PASS locally | Watchlist lazy-loads snapshot/history, quarterly/annual statement history, revenue/valuation series, and monthly/daily valuation frequency; sparse/no data remains unavailable. Desktop and 390px Chromium visual journeys pass. |
| Screener eligibility boundary | PASS | Selected `fund_*` operands use the canonical metric service, PIT values, and unavailable semantics; `FundamentalScreenerOperandTest` and related bank tests pass. |
| Authorization and account isolation | PASS | Authenticated stock fundamentals routes and existing portfolio/profile middleware; V8 API tests cover intended investor access and Admin boundaries. |
| Official upgrade/fallback auditability | PASS locally | Deterministic source ranking and canonical revision storage allow later official rows to replace lower-ranked fallback rows; live provider upgrade exercise remains external. |
| Production/runtime acceptance | EXTERNAL VALIDATION PENDING | Representative provider calls and deployed queue/runtime proof remain to be exercised. Local Chromium now covers the Watchlist fundamentals summary, provenance, historical Basic/Advanced tables and user-scoped preference reload on desktop and a 390px touch viewport (3/3 focused journeys, including the two-user preference isolation case); the focused full WCAG 2A/2AA axe scan passes on the fundamentals page. |

## Verification executed

- Focused fundamentals Laravel tests: **15 passed, 38 assertions** after this continuation.
- Existing V8 Laravel baseline remains green: **148 passed, 546 assertions** before the new focused additions.
- JS fundamentals display/preference tests pass; typecheck passes.
- Existing source-priority, Screener, bootstrap, historical API, and metric correctness tests pass.

## Status decision

FEAT-054 is **REVIEW** rather than COMPLETE because the repository evidence and focused desktop/mobile Chromium journeys are complete for the deterministic implementation, but real provider and deployed bootstrap runtime validation remain external.


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Bounded production bootstrap, TCS/SBIN/LAURUSLABS | PASS (runtime/fallback slice) | Build `ef66133c`, VPS, 2026-10-01 18:20–18:23 UTC. Dry run resolved 3 active symbols. Run #1 TCS: 207 inserted, quarterly 107/107, annual 100/100, no rejected; run #2 LAURUSLABS 171 and SBIN 152 inserted, all three jobs `complete_good`, no failures. Seven distinct periods per job, earliest 2023-03-31, latest 2026-06-30. All 530 facts are Yahoo. |
| Official NSE/BSE acquisition, source priority and official metadata replacement | BLOCKED | Production has `nse_official_enabled=false`, `bse_official_enabled=false`, and neither feed URL configured. The repository adapters require an operator-configured canonical JSON feed. No official fact was fetched, so official precedence/revision upgrade is unproven. |
| Targeted-scope safety | FAILED (source defect) | `stockIdsFromSymbols` returns [] for an unknown symbol; `resolveStocks` treats [] as unrestricted, so `--stock=UNKNOWN` could run all active equities. Branch `audit/v8-closure-20261001` adds a guard and tests; not deployed. |
| Full active-equity coverage/source exhaustion and quality | NOT YET RUN | Before slice: 2,530 Yahoo facts on 19 stocks out of 5,140 effectively active non-benchmark equities; after slice, 530 additional facts on three stocks. Full campaign must be planned after provider remediation and queue/load review. |
| PIT fact dates, four-quarter TTM, YoY, deployed Basic/Advanced and history | NOT YET RUN | Inspect actual stored availability metadata/period continuity and authenticated deployed investor UI; successful job status alone does not establish these outcomes. |
