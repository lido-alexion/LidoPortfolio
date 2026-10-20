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
