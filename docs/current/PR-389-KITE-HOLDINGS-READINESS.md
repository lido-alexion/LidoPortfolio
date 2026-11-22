# Draft PR #389 — Kite holdings onboarding readiness

## Result

The staging and import iteration is implemented locally in this worktree. The frozen requirement is CSV/Excel; CSV and modern XLSX satisfy that requirement. Legacy binary `.xls` is explicitly unsupported, with user-facing XLSX resave instructions. XLSX and Excel 2003 XML `.xls` are supported. No production data was written and all feature tests used the fake broker gateway.

## Changed files

- `app/app/Http/Controllers/Api/KiteHoldingsOnboardingController.php`
- `app/app/Services/KiteConsoleTradebookParser.php`
- `app/resources/js/src/pages/KiteHoldingsOnboardingPage.jsx`
- `app/resources/js/src/data/appDocumentation.js`
- `app/tests/Feature/KiteHoldingsOnboardingTest.php`
- `app/tests/js/tos/kite-holdings-onboarding.test.jsx`
- `app/public/docs/kite-holdings-onboarding.html`
- `docs/current/KITE-HOLDINGS-ONBOARDING.md`
- `docs/assistant-product-corpus.json`
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

- `OTEL_SDK_DISABLED=true php artisan test --filter=KiteHoldingsOnboardingTest` — passed, 14 tests / 91 assertions.
- `npx vitest run tests/js/tos/kite-holdings-onboarding.test.jsx` — passed, 1 test.
- `node --test tests/js/documentation-sync.test.mjs` — passed.
- `npm run typecheck` — passed.
- `npm run docs:static:check` — passed; static documentation contract current at 54 topics.
- Added regression coverage for broker quantity and FIFO remaining-cost reconciliation across multiple lots, multi-period repeat imports, preservation of a user-dated opening entry, selected-stock partial commit, commit rollback, and friendly legacy `.xls` rejection.
- PHP Pint check for the changed PHP files — passed. `git diff --check` was not run because the owner explicitly prohibited Git metadata operations.
- `npm run build` — blocked by restricted network DNS lookup for `fonts.bunny.net` (`EAI_AGAIN`).
- Full local `node --test tests/js/*.test.mjs` attempt — did not finish before the 30-second timeout. The run reported `authenticatedProductionSmoke.test.mjs` failed and later tests remained pending; treat the full local JS gate as unverified.
- `./scripts/verify-ci.sh --backend` — migration portability and Python contracts passed, but MySQL at `127.0.0.1:3306` was unavailable for migration/PHPUnit/OpenAPI gates.
- `./scripts/verify-ci.sh --frontend` — dependencies installed and the full Node suite began. It reproduced the `authenticatedProductionSmoke.test.mjs` failure and stopped producing progress for over two minutes; it was interrupted. Full frontend/build/Playwright parity remains unverified.

## Hosted CI observation

Read-only GitHub Actions inspection of PR workflow run [#45015227295](https://github.com/lido-alexion/LidoPortfolio/actions/runs/45015227295) (run 1688, created for the existing PR merge commit) showed:

- Python AI and FastMCP contracts — passed.
- PHPUnit (PHP 8.4) — passed.
- Frontend tests and production build — failed because `/portfolio/kite-onboarding silently fell back to overview` in `documentation-sync.test.mjs`.
- Health monitor and CI follow-up watcher jobs — skipped.

The missing documentation topic was added here, and the exact documentation sync test passes locally. That hosted run predates these worktree edits. Run `45020517337` could not be checked from this environment (`gh` is not installed, and the run page could not be fetched). The prohibition on Git writes means this iteration could not trigger a hosted run for these local changes.

## Blockers

1. **Backend CI parity:** migration portability and Python contract checks passed, but PHPUnit/migrations could not run because the local MySQL service at `127.0.0.1:3306` is unavailable.
2. **Frontend CI parity:** the canonical verifier installed dependencies and started the full Node suite. It reproduced the `authenticatedProductionSmoke.test.mjs` failure and stopped making progress in the Node test phase; it was interrupted after more than two minutes without output. The complete frontend gate remains unverified.
3. **Hosted PR CI:** run `45020517337` could not be inspected from this environment (`gh` is unavailable and the GitHub run page was not fetchable). This worktree was not published, as instructed, so CI feedback for these local changes remains outstanding.

## ACTION REQUIRED FROM YOU

- **Supervisor:** independently review and publish these local changes to PR #389. This is required before GitHub can run checks against this exact diff; the user explicitly reserved publish/merge/deploy actions to the supervisor.
- **Supervisor:** inspect hosted CI for the published SHA and report any failures. My local backend and frontend CI-parity gates were incomplete for the environment limits above.
- **None** for legacy binary `.xls` support: CSV and XLSX meet the frozen CSV/Excel scope, and unsupported binary `.xls` has direct resave guidance.
