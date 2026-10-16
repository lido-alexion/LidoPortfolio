# UX004 Delivery Status

- Updated: 2026-10-16 07:50 IST (2026-10-16 02:20 UTC)
- Session: root-owned full delivery of V9-UX-004 Guided Production ML Acceptance Wizard
- Checkout: `/home/nitty/stox-v9-ux004-work`
- Branch / HEAD: `feat/v9-ux004-guided-wizard` / `a3ca3163995cd1eb2853b9973a6353540a9fe376` (implementation in DRAFT PR186)
- PR / hosted CI: [PR186](https://github.com/lido-alexion/LidoPortfolio/pull/186); CI1204 run [38839565958](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38839565958): Detect changed areas and Python contracts passed; canonical frontend and PHPUnit jobs still running; review list currently empty; no merge
- Active process: address root independent-review findings, strengthen click-path/UI browser coverage, self-review and prepare exact-head CI/review handoff
- Scope: UX004 only. UX001, VIZ001 and OPS001 remain other-thread work. No production ML actions/data/permissions/services were changed. V8 acceptance/campaign/backfill services remain domain authority.
- Ownership: UX004 ownership was verified on latest master and published through merged PR185 before implementation.

## Acceptance audit (frozen specification §18)

1. Admin guidance and consequences; seven-step flow/server-current step: implemented.
2. Discovery vs final preflight and exact reported source dates: implemented with persisted campaign/remediation lineage; endpoint chain covered.
3. Separate preview/apply, explicit confirmations and three-horizon candidate training: implemented; endpoint and UI tests cover gates.
4. Durable refresh/reload, hidden-tab polling, repeated poll recovery, uncertain outcomes and response ordering: implemented; covered in hook/component tests.
5. Running, blocked, failed, cancelled, quality-rejected and qualified outcomes: mapped from persisted records; failed/cancelled endpoint projection covered. Qualified freshness remains authoritative `evidence_current`.
6. Resume/cancel and recovery controls: only server-confirmed resumable operations are exposed; cancellation/recovery paths covered in scoped tests.
7. Candidate, active-model, schedule, promotion and drift decisions remain separate; no production mutation path was added.
8. Known blocker registry, unknown-code display, keyboard/touch help, responsive layout, docs/OpenAPI/journey updates and E2E-08 changes: implemented; local browser runner cannot launch Chromium under sandbox restrictions, so hosted E2E-08 desktop/mobile evidence remains pending.
9. Authorization, queue locks, idempotency and V8 evidence rules: preserved; read-only endpoint and isolated action-path tests cover scope.
10. CI parity, root independent review, exact-head hosted CI, merge, serialized deployment, exact-SHA health/smoke and register closure: pending; PR186 is draft and must remain unmerged until root review and CI pass.

## Current engineering work

- Read-only admin endpoint reconstructs wizard state from the latest persisted campaign sequence, explicit linked backfill lineage, supported sealed source families, saved evidence and existing report freshness. It does not dispatch jobs, probe Python or mutate settings.
- Source selection auto-selects one server-reported sealed source per exact required date so the real Step 3 → Step 4 transition is actionable.
- Campaign sequence migration resolves same-second creation ordering; final campaign history retains the exact applied remediation backfill id. The impossible timestamp comparison was removed.
- Final preflight remains complete through ready/training and later failure/cancellation maps to the relevant stage. Evidence freshness is passed through unchanged.
- Hook refreshes after successful/uncertain/error actions, preserves mutation errors until user review, retries transient active polls and ignores late responses.
- Status artifact is maintained in this checkout at each meaningful transition.
- Root review found the enabled Step 3 Continue control did not navigate because its handler checked a narrower predicate. Fixed `next()` to use the enabled-control predicate and added a click-through regression that selects the sealed source, enters Backfill, and runs the explicit safe preview.
- E2E-08 now exercises discovery → sealed source selection → Continue → Backfill → safe preview → keyboard-dismissed apply confirmation at mobile and desktop viewports; it asserts no apply or training action. Browser execution is pending hosted runner.

## Checks completed

- `OTEL_SDK_DISABLED=true ./vendor/bin/phpunit tests/Feature/V8/MlProductionAcceptanceTest.php --no-coverage`: passed, 31 tests / 250 assertions.
- Isolated endpoint-chain test passed, 53 assertions: actual sealed source → preview → apply → fresh linked preflight → ready → training → failed review, plus cancelled and blocked projection states. Queued jobs were intercepted by `Bus::fake()` in the isolated test database.
- `./node_modules/.bin/vitest run tests/js/tos/mlAcceptancePanel.test.jsx --reporter=dot`: passed, 14 tests.
- After the Step 3 correction, `./node_modules/.bin/vitest run tests/js/tos/mlAcceptancePanel.test.jsx --reporter=dot`: passed, 15 tests; TypeScript `--noEmit` passed.
- E2E-08 Playwright invocation reached Chromium launch but both viewports were blocked by sandbox `Operation not permitted`; no browser-layout claim is made from this local attempt. E2E file syntax and `git diff --check` passed.
- `./node_modules/.bin/tsc --noEmit`: passed.
- `php scripts/verify-migration-portability.php`: passed for 182 migrations, including the new sequence migration.
- `OTEL_SDK_DISABLED=true php artisan openapi:v1`: generated 222 operations; overlay includes optional final-preflight/lineage fields.
- `npm run docs:static`, `npm run journeys:metadata`, `npm run docs:static:check`: passed; 53 static topics and 129 journey topics.
- PHP syntax checks for changed backend services/controller/migration passed. `git diff --check` and E2E JavaScript syntax check passed after the latest changes.
- Browser E2E server previously failed to bind `127.0.0.1:4177` with sandbox `EPERM`; browser viewport/E2E-08 gate still needs a permitted hosted runner.

## Blockers and next owner

- Root continues self-review, focused regression reruns and exact acceptance audit. PR186 has no submitted reviews yet; root independent review must occur before implementation merge.
- PR186/CI1204 are open/running. Hosted canonical frontend/backend/browser gates and exact-head status are required; root handles host connector actions if this sandbox policy blocks them.
- Do not use production DB/runtime, perform production ML work, merge without exact-head CI, deploy outside the serialized workflow, or close the register until exact-SHA health and non-destructive smoke are complete.
