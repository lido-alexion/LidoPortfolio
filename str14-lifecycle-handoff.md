# STR14 strategy lifecycle handoff

## Frozen authority

The controlling decision is [`docs/current/str14-po-frozen-decision-20261021.md`](docs/current/str14-po-frozen-decision-20261021.md), accepted by the Product Owner on 21 October 2026. Disable pauses new strategy output while existing work remains manageable; Archive retires only after owned obligations resolve; Restore returns to Disabled; zero enabled strategies is valid. No lifecycle action liquidates holdings, cancels orders, releases reservations, rewrites ownership, or creates financial state. Root retains independent acceptance, merge, and release authority.

## Existing record and ownership map

Eligibility is enforced by `StrategyArchiveEligibility` within the archive transaction after the strategy row is locked. Every count is limited to the caller's portfolio and the exact strategy owner:

| Frozen category | Existing authoritative records and matching | Zero/completed treatment | Existing resolution link |
| --- | --- | --- | --- |
| Owned holdings | `portfolio_holdings.profile_id` and either `strategy_id` or `Holding::ownerKeyFor(strategy_id)` | `quantity > 0` only | `/holdings` |
| Actionable recommendations | `portfolio_tos_recommendations.profile_id`; `strategy_version_id` joins `portfolio_tos_strategy_versions.strategy_id`; actionable action aliases and open actionable statuses | Historical/non-actionable statuses excluded | `/recommendations` |
| Pending executions | Same exact strategy-version ownership; pending execution or accepted state | Completed/cancelled states excluded | `/transactions/pending` |
| Pending/in-flight orders | `portfolio_tos_orders.profile_id`, linked recommendation in the owned version set; pending or `IN_FLIGHT_BROKER_STATUSES` | Terminal orders excluded | `/transactions/pending` |
| Reserved funds | Owned recommendations with reserved reservation state and positive `reserved_amount` | Zero reservations excluded | `/transactions/pending` |
| Capital requests | `portfolio_tos_capital_requests.profile_id`, strategy as borrower or lender; displayed, awaiting approval, or revalidation failed | Resolved requests excluded | `/cash` |
| Loans | Normal and recall bridge loans in profile; strategy is borrower or lender; positive `outstanding` | Zero outstanding excluded | `/cash` |
| Recalls | Active recall states, exact borrower/lender strategy and profile, positive `outstanding_recall_amount` | Resolved or zero amount excluded | `/cash` |
| Unsettled proceeds | `portfolio_tos_pending_sale_proceeds.profile_id` and exact `strategy_id`; pending/available with positive amount or required settlement amount | Settled/zero excluded | `/cash` |

Sibling strategies and other profiles do not block retirement. Recommendation ownership follows immutable version ownership rather than names or a broad profile-wide history scan. The implementation does not introduce financial-state tables or reinterpret unrelated historical records.

## Lifecycle and pipeline changes

- Added persisted archive timestamp/reason; archive requires a nonblank reason. The archive eligibility check and status/reason write are in one transaction with a strategy row lock. Disable and restore also lock the row. Restore always writes Disabled; explicit activation remains subject to readiness.
- Removed list/get/meta/create read-side factory reseeding for initialized portfolios. A zero-enabled portfolio stays zero-enabled. The decision pipeline and direct recommendation generation skip new evaluation/recommendation intent when no strategy is enabled. Generation locks and rechecks selected strategy rows before persisting recommendations to close the pause-after-selection race.
- Existing pending broker execution/reconciliation is retained. Current-state revalidation no longer cancels an otherwise valid owned pending recommendation solely because its strategy was disabled; ownership change and stock-state checks remain. Scheduled pipeline continues existing execution/reconciliation/settlement work independently of strategy evaluation.
- Archived configuration is read-only in API/editor; status controls separate Disable, Archive, Restore, and explicit Enable. UI displays backend blocker codes/counts/labels and existing navigation links. Removed the last-enabled count gate and reflected zero-enabled behavior in metadata.

## Changed surfaces

The package changes strategy lifecycle model/migration, archive eligibility and registry support, recommendation/evaluation pipeline behavior, pending execution revalidation, Strategy Registry/editor pages, API routes/OpenAPI overlays and generated JSON copies, focused PHP/component/journey tests, current strategy docs and STR-14 journey, in-app/static help, and this handoff. The exact frozen PO decision is preserved as a current document. Historical archived audit material was not edited.

## Qualification status and constraints

- Authorized local checks are limited to syntax, JSON shape/copy comparison, and diff hygiene. No DB connection, migration execution/portability script, package installation, browser, backend/frontend verifier, or local heavy suite has been run, per the Product Owner's hosted-only qualification instruction.
- `php artisan openapi:v1` was attempted and could not start because `app/vendor/autoload.php` is absent. OpenAPI operations were updated using the existing operation-overlay shape; the two checked-in OpenAPI JSON copies are synchronized and parse as JSON. Generator verification remains for hosted CI or root review.
- The feature branch was pushed to the configured SSH remote. Draft PR creation was attempted through the connected GitHub tool and rejected by its approval gate; this environment's approval policy does not permit that approval, and `gh` is not installed. No PR number or hosted Actions run exists yet. The branch is ready for the single requested draft PR once the approval gate is available.
- Meaningful test coverage was added for zero-enabled/last-disable, each mapped blocker, zero balances, an eligible archive, read-only archive and restore-to-disabled, no financial side effects on disable, profile/sibling isolation, and a deterministic pause-after-selection generation race. These tests have not been executed locally; hosted PHPUnit and frontend/browser CI results must be reviewed on the draft PR.
- No unresolved lifecycle data-model contradiction was found during implementation. Migration portability and full hosted behavior remain unqualified until required CI completes. No live account, provider, order, cash, or service operation was performed.

## PR #240 correction and post-master handoff — 21 October 2026

This section supersedes the prior publication/qualification bullets above. PR #240 is the existing draft at branch `feat/str14-strategy-lifecycle-20261021`. It was normally merged with the explicitly fetched `origin/master` head `5bc2159dbd9654b4f8227f1b60921456df9b4a48`; merge commit is `a9ffab0e`. No rebase, reset, force, or history rewrite was used. The merge included the already accepted master changes from PR #238 and #239; the only feature-branch untracked file retained is the previously accepted correction map.

### Correction implementation

- Backtest default version selection now follows the enabled strategy's persisted `active_version_id`, then a Disabled strategy's pointer, with latest-version fallback only when no usable pointer exists. Explicit version pinning remains unchanged. The original version-22 expectation remains intact, and a Disabled-pointer case is authored.
- Changed configuration saves preserve the locked strategy status while still creating and selecting the new immutable version. A feature case checks the strategy remains Disabled with zero enabled strategies, followed by a separate explicit activation request.
- The three old Archive prompt assertions now require the Retire reason prompt. Authored lifecycle assertions remain in place.
- `StrategyLifecycleAdmission` uses existing strategy rows as the serialization boundary. It sorts strategy IDs before locking, validates exact profile ownership, and rejects new blocker writes only for Archived owners. Disabled owners remain admitted for existing financial processing.
- Admission is applied before strategy-owned writes in recommendation reopen, manual/broker orders and fills, transaction ledger/holding writes and edits, internal transfers, bulk import, protection orders, ownership adoption/backfill, capital request/approval, recalls/bridge loans, recall liquidation, and proceeds scheduling/amount updates. Bilateral locks use ascending strategy ID. Existing reduction/settlement processing remains active for Disabled strategies. No financial state table or accounting policy was added.
- Authored MySQL feature coverage uses separate named connections for sequential writer-then-archive and archive-then-writer settlement rechecks, and checks bilateral lock ordering. The transactions do not overlap: each first operation commits before the second starts. These cases validate blocker rechecks and archived-write refusal, but do not prove contention/blocking on the shared strategy-row lock. Existing Symfony Process tests establish that child PHP processes are used elsewhere, but this repository has no established deterministic lock-wait observation pattern; no local DB execution is authorized to qualify a newly invented one. Genuine two-transaction overlap proof remains an explicit unresolved correction for root. Additional lifecycle cases cover order/capital admission and archived-write refusal. These tests are authored only; none of these suites were run locally.
- The current authored journey remains `docs/user-journeys/02-strategies.md`; the controlling policy remains the unchanged frozen decision above. The current canonical strategy document now explains the shared row barrier. No route, migration, or OpenAPI contract changed in this correction.

### Review and qualification status

- Static checks: `php -l` passed on all modified PHP files; `node --check` passed on the two changed JS source-contract tests; `git diff --check` passed.
- Full PHPUnit/MySQL, frontend source contracts, Vitest, typecheck/build, Playwright, migration portability, OpenAPI generation, and browser tests were not run locally under the hosted-only instruction.
- Canonical `npm run docs:static` was run using a temporary symlink to the installed dependencies in `/home/nitty/stox-v9-repair-configured-queue/app`; package.json and package-lock.json SHA-256 hashes matched exactly (`c7dce8c0…` and `9c18297f…`). The symlink was removed afterward. Generated outputs were written only by the canonical generator. No dependency installation occurred.
- Current `origin/master` at fetch was exactly `5bc2159…`; before publication the branch still requires a fresh normal fetch and comparison, then ordinary push to the same PR #240 branch. After push, record the exact PR head and hosted run, but leave terminal CI review and readiness/merge/release to root.
- No database, migration, browser, runtime, provider, account, order, cash, service, training, or production operation was performed. No workflow, shared checkpoint, PLAN, status, Second Brain, held/foreign PR, credential, infrastructure, or billing file was changed.

### First correction publication receipt

Commit `a648e1dbd7030ea462f1ee5d3b3c929efffde1fe` was pushed normally to the same PR #240 branch after a fresh fetch confirmed master `5bc2159…` and remote branch `6e51761…`. PR #240 remained draft, with base `5bc2159…`. Exact-head canonical CI run `39527269575` (run number 1469) was observed `in_progress`; no job logs or terminal result were polled. This receipt is for the implementation commit; a later handoff-only commit, if published, needs its own exact-head CI receipt.

### Root review correction — 21 October 2026

Root rejected head `69b3b83d52f22ecb223468a46336b16c9120dba6` because `StrategyArchiveAdmissionConcurrencyTest` committed each transaction before starting the next. At that head the test and handoff were corrected to describe only sequential rechecks. This was superseded by the deterministic overlapping transaction coverage below.

The generated-doc correction was completed through canonical `npm run docs:static`. The only available installed dependency tree was in the approved sibling checkout; its `app/package.json` and `app/package-lock.json` hashes exactly matched this worktree. A temporary `app/node_modules` symlink was removed immediately after generation. No generated output was edited manually and no package was installed.

The exact-head CI `39527321160` was pending at review time and was not polled. It may be superseded by this correction push. No local DB, browser, migration, verifier, or heavy test was run. PR remains draft for root's independent review and qualification decision.

### Root-resolved overlap test design — 21 October 2026

The feature test now leaves the parent MySQL transaction open after the real first operation acquires the strategy admission lock. A separate Symfony Process child boots Laravel on its own DB session, sets `SESSION innodb_lock_wait_timeout=1`, and calls the competing real service. The child exits with controlled code 20 and exact JSON only for MySQL SQLSTATE `HY000` / errno `1205`; unexpected completion, another SQL error, or another exception has a distinct nonzero exit. The parent waits for process completion without sleeping or releasing its lock. After the expected timeout, it commits and retries the operation to assert the terminal result.

Coverage includes writer-first `ExecutionEngine::recordOrder` followed by archive, writer-first `CapitalRequestService::createRequest` followed by archive, archive-first followed by a waiting capital request, and archive-first followed by a waiting `SaleProceedsAvailabilityService::scheduleForObligation`. Post-commit retries assert order/capital-request blockers in the first two cases and Archived rejection with no new request/proceeds in the latter cases. The child fixture is under `app/tests/Support`; no production route or command was added. Existing sequential outcome and ascending bilateral-lock tests remain intact. This is authored coverage only and was not executed locally; hosted MySQL CI must exercise it.

The exact test head before this publication was `689359b030b1d02664f76f6d862385c58cfbd3aa`. The prior unresolved overlap-proof blocker is removed from current qualification status, subject to root's independent review of the source and exact-head hosted CI. No local database or heavy suite was run.
