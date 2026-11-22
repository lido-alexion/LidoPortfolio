# Draft PR #389 — Kite holdings onboarding readiness

## Result

The staging and import iteration is implemented locally in this worktree. The PR is **not ready to close**: legacy binary `.xls` files are not supported by the installed PHP runtime, and hosted CI has not run on these local changes. XLSX and Excel 2003 XML `.xls` files are supported. No production data was written and all feature tests used the fake broker gateway.

## Changed files

- `app/app/Http/Controllers/Api/KiteHoldingsOnboardingController.php`
- `app/app/Services/KiteConsoleTradebookParser.php`
- `app/resources/js/src/pages/KiteHoldingsOnboardingPage.jsx`
- `app/resources/js/src/data/appDocumentation.js`
- `app/tests/Feature/KiteHoldingsOnboardingTest.php`
- `app/tests/js/tos/kite-holdings-onboarding.test.jsx`
- `app/public/docs/README.txt`
- `app/public/docs/index.html`
- `app/public/docs/journeys/04-execution-transactions.html`
- `app/public/docs/stox-trading-artifacts-ai-guide.md`
- `app/public/docs/console-tradebook.html`
- `app/public/docs/kite-holdings-onboarding.html`
- `app/public/docs/zerodha-holdings-import.html`
- `docs/assistant-product-corpus.json`
- `docs/current/KITE-HOLDINGS-ONBOARDING.md`
- `docs/current/stox-trading-artifacts-ai-guide.md`
- `docs/current/PR-389-KITE-HOLDINGS-READINESS.md` (this report)

## Implementation and audit

- CSV/TXT, XLSX, and Excel 2003 XML `.xls` parsing is bounded by 10 MB upload size, 20,000 rows, 64 columns, 500 XLSX archive entries, and 25 MB expanded archive content. Parsing is value-only: formulas, macro projects, unsafe XML, and external workbook links are rejected; workbook formulas/macros are never executed.
- Trade rows require recognized symbol, side, quantity, price, and strict date fields. Invalid non-empty rows reject the upload. Zerodha `trade_timestamp` and `exchange_timestamp` columns are recognized. Imports merge only to staged currently held symbols, remove only provisional Kite opening entries for affected stocks, preserve manual lots, and audit the import.
- Repeated imports deduplicate by broker trade ID, or exact side/quantity/price/date/provenance when no broker ID exists. Identical no-ID executions remain indistinguishable; this limitation is documented.
- Profile scoping is enforced for reads and edits. Verification is per stock, rejects placeholder/future dates, uncovered sells, net quantity mismatch, and cost-basis mismatch. Existing profile transactions block commit. Commits recheck Kite quantity/cost, write through the transaction service in one database transaction, and cannot be edited, re-verified, or committed again after success.
- UI upload accepts the supported formats and editing a date explicitly clears its placeholder flag. Static help documents the wizard and import limits.

## Checks

- `OTEL_SDK_DISABLED=true php artisan test --filter=KiteHoldingsOnboardingTest` — passed, 10 tests / 64 assertions.
- `npx vitest run tests/js/tos/kite-holdings-onboarding.test.jsx` — passed, 1 test.
- `node --test tests/js/documentation-sync.test.mjs` — passed.
- `npm run typecheck` — passed.
- `npm run docs:static:check` — passed; static documentation contract current at 54 topics.
- Pint check and `git diff --check` — passed.
- `npm run build` — blocked by restricted network DNS lookup for `fonts.bunny.net` (`EAI_AGAIN`).
- Full local `node --test tests/js/*.test.mjs` attempt — did not finish before the 30-second timeout. The run reported `authenticatedProductionSmoke.test.mjs` failed and later tests remained pending; treat the full local JS gate as unverified.
- Canonical MySQL backend verifier and Playwright journey verifier were not run locally. The backend verifier uses MySQL; the frontend verifier installs browser/system dependencies. The targeted local feature tests used the available test database and do not replace those gates.

## Hosted CI observation

Read-only GitHub Actions inspection of PR workflow run [#45015227295](https://github.com/lido-alexion/LidoPortfolio/actions/runs/45015227295) (run 1688, created for the existing PR merge commit) showed:

- Python AI and FastMCP contracts — passed.
- PHPUnit (PHP 8.4) — passed.
- Frontend tests and production build — failed because `/portfolio/kite-onboarding silently fell back to overview` in `documentation-sync.test.mjs`.
- Health monitor and CI follow-up watcher jobs — skipped.

The missing documentation topic was added here, and the exact documentation sync test passes locally. That hosted run predates these worktree edits; the prohibition on Git writes means this iteration could not trigger a new hosted run.

## Blockers

1. **Legacy binary `.xls`:** the parser intentionally rejects OLE/BIFF `.xls` because this PHP runtime has no spreadsheet library for safely reading it. Excel 2003 XML `.xls` and `.xlsx` work. To satisfy binary `.xls` imports, add and lock a vetted reader dependency, or confirm that XLSX/Excel 2003 XML is the accepted scope.
2. **PR CI:** rerun required hosted CI after the worktree changes are published through the separately authorized Git workflow. The existing hosted run cannot validate this diff.
3. **Local build/full suite:** the production build requires the unreachable font host, and the complete local Node test process timed out. Run the canonical CI verifiers in a network-enabled CI environment.

## ACTION REQUIRED FROM YOU

Confirm whether legacy binary `.xls` imports must be supported. That determines whether the current bounded XML/XLSX implementation is acceptable or a vetted spreadsheet dependency must be added. No approval is needed for the local changes themselves.
