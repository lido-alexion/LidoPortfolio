# V8 FEAT-054 Acceptance Audit

Date: 2026-09-28
Status: **REVIEW — local implementation and automated verification complete; browser/provider runtime evidence pending**

Authoritative contract: `docs/archive/specs/V8-Historical-Fundamentals-Bootstrap-Specification.md`.

## Requirement matrix

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Canonical fact catalogue and normalization | PASS | `fundamentals_catalog.php`, `FundamentalDataService`, normalizer and historical-source tests. |
| Provider order (PO decision 2026-10-05) | PASS locally | Yahoo is primary; matching NSE/BSE source is queried only when Yahoo yields no usable rows. Automated fallback and non-query-on-Yahoo-success behavior are covered by focused tests. Exchange access remains gated pending authorization/feed access. |
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
| Source provenance and fallback auditability | PASS locally | Selected provider provenance is stored; source fallback is deterministic. This now follows the 2026-10-05 PO decision rather than the original official-first rule. |
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
| Targeted-scope safety guard and deployment | PASS (regression and deployed identity) | The 2026-10-01 finding was fixed: explicit `stock`/`stocks` scopes now reject empty or unmatched active-stock IDs before creating a run. Focused unknown-symbol/inactive-stock tests were included in PR #23; CI run `36925637515` passed. Squash commit `920eb6a` is an ancestor of production build `ac6602ae` (deployment run `36963314151` succeeded). No new full-universe job was created. |
| Live unknown-symbol rejection probe | NOT YET RUN | Exercise an explicit unknown symbol against the deployed command in a controlled dry-run and confirm no run/job is created; the CI regression and deployed SHA do not substitute for that production observation. |
| Full active-equity coverage/source exhaustion and quality | NOT YET RUN | Before slice: 2,530 Yahoo facts on 19 stocks out of 5,140 effectively active non-benchmark equities; after slice, 530 additional facts on three stocks. Full campaign must be planned after provider remediation and queue/load review. |
| PIT fact dates, four-quarter TTM, YoY, deployed Basic/Advanced and history | PARTIAL (deployed SBIN slice below) | The authenticated SBIN Watchlist slice verified provenance, Basic/Advanced tables, monthly P/B history, inline YoY and honest unavailability of sparse TTM. Separate daily/annual paths, period continuity across representative issuers, and screen-reader acceptance remain NOT YET RUN. |

### Authenticated deployed SBIN Watchlist — 2026-10-01 about 19:02 UTC

Cloud Chrome, signed-in Investor portfolio, build `ef66133c`: Watchlist → SBIN → Fundamentals rendered summary (Market cap ₹880,693.2 Cr, P/B 1.4x), Yahoo provenance, period 2026-06-30, 5 quarterly and 4 annual periods, monthly default P/B trend, Basic multi-period table with inline same-quarter YoY, and collapsed Advanced with 6 rows. Expanding Advanced displayed bank fields including interest income/expense and net interest income. P/E (TTM), ROE (TTM), cash-flow metrics were shown unavailable; the five quarterly dates have a gap, consistent with not fabricating TTM. **PASS for this deployed rendering slice**, while official-source, full-universe and separate daily/annual/screen-reader acceptance remain open.


### 2026-10-02 production reconciliation

The targeted-scope defect above is **resolved in code and deployed**, superseding its 2026-10-01 FAILED/not-deployed checkpoint. PR #23 (`920eb6a`) passed the changed-area, PHP 8.4 and frontend CI jobs in run `36925637515`. GitHub comparison establishes that live commit `ac6602ae2db35b52b68a7183c50f987a1b4d615f` is six commits ahead of `920eb6a` with no divergence; production build `build-332-attempt-1-ac6602ae2db3` returned that exact SHA on 2026-10-02, and deployment run `36963314151` passed backend, package and deploy jobs. The live negative-scope dry-run subsequently passed (bounded evidence below). Read-only DB inspection found the same two bootstrap runs and three jobs, with no wider bootstrap campaign. Official NSE/BSE adapters remain disabled and their feed URLs unset. **FEAT-054 stays REVIEW** pending official-source and coverage acceptance.

### 2026-10-02 bounded deployed negative-scope and fact-date check

On production release `20261002051219-046e67de2c4c`, `php artisan stox:fundamentals-bootstrap --dry-run --stock=STOX_V8_UNKNOWN_20261002 --no-interaction` exited 1 with `No stock matched the requested symbol scope.` Bootstrap run/job counts were 2/3 immediately before and after. This is a **PASS** for the live unknown-symbol rejection and absence of a new run/job; the earlier NOT YET RUN row records its historical checkpoint.

Read-only indexed inspection of the three bounded Yahoo fallback issuers found 530 facts in total: TCS (100 annual across four periods, 107 quarterly across five), SBIN (80 annual across four, 72 quarterly across five), and LAURUSLABS (104 annual across four, 67 quarterly across five). Annual periods span 2023-03-31 through 2026-03-31; quarterly periods span 2025-03-31 through 2026-06-30. All six groups had zero null `availability_date` and zero dates earlier than `period_end`. This checks stored fallback fact metadata for the slice; it does not prove official-source replacement, full-universe coverage, or PIT computation/rendering for every period. **FEAT-054 remains REVIEW.**


## Provider-order update — 2026-10-05

The product owner set Yahoo as the primary historical fundamentals source, with the matching NSE/BSE source used only when Yahoo returns no usable facts for that stock and cadence. This supersedes the original official-first/per-fact fallback rule in the specification. The implementation is sequential and does not query exchange sources after usable Yahoo output. NSE/BSE access remains disabled by default and requires authorization; current live provider acceptance is still pending.

Focused verification after the change: **38 tests, 522 assertions passed**, covering Yahoo precedence, skipping exchange requests when Yahoo has usable facts, NSE and BSE fallback, malformed-value handling, bootstrap anomaly tracking, metadata preservation, and PIT/metric regressions. PHP syntax checks and `git diff --check` passed.
