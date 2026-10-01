# StoX V9 Forward Data Collection, Recovery & Readiness

| Field | Value |
|---|---|
| Epic | **V9-DATA-003** |
| Status | **FROZEN / IMPLEMENTATION-READY** |
| Created | 2026-10-01 |
| Parent | [V9 register](LidoPortfolio-V9-Wishlist.md) |
| Intent | Keep required data current, recover missed work and prove completeness from persisted data |

## 1. Problem and outcome

A one-time historical backfill does not keep future ML reference dates covered. The 2026-10-01 audit reported no scheduled official NSE membership ingestion, fundamentals batch starvation and signed-age errors, absent company sectors, an unconfigured corporate-action feed, daily-price repair failures and zero-row live collection. These are audit observations to reproduce on the implementation revision, not permanent assertions about current production.

After implementation, completed trading sessions generate durable collection obligations automatically. Financial statements and classifications are checked regularly and stored when new information is published. Outages leave recoverable work rather than silently advancing completeness. Admin can see what is ready, missing, late, unavailable or blocked and why.

This epic is the collection/recovery contract and shared operational visibility layer. It must reuse existing domain ingestion, provenance and validation services. Reconcile the separately initiated forward-data remediation work before implementing; already-correct behavior needs evidence and integration, not duplicate code.

## 2. Ownership and scope

| Dataset | Domain engine / authoritative boundary | DATA-003 responsibility |
|---|---|---|
| Daily equity OHLCV | Existing market-data collection and data-quality services | Verify daily scheduling, universe fairness, gap repair and coverage |
| Daily benchmark/index prices | Existing index sync services | Expected-session coverage and recovery |
| Official NSE eligible-security evidence | FEAT-057 PIT membership and acceptance-source services | Campaign-independent acquisition, sealing, validation and governed forward materialization |
| Financial statements/facts | FEAT-054 ingestion; OPS-001 Admin backfill operations | Fair incremental polling, last-check tracking, freshness and backlog |
| Company sector classifications | Existing stock/ML classification services | Sourced effective-dated population and PIT-safe consumption |
| Corporate-action events | Existing data-quality/event approval and repair services | Working provider integration, event deduplication, late-event recovery and freshness |
| Historical 1-minute OHLCV | FEAT-065 + V9-DATA-002 | Observe collection/coverage and link to owner operations; do not add another collector |
| Prospective microstructure | FEAT-063 | Verify operational session coverage and alert; reuse owner recovery controls |

Production daily 1m/3m/6m readiness and minute-corpus research readiness are distinct profiles. Minute/microstructure deficits do not block the daily profile unless the authoritative feature registry explicitly consumes them. The fixed current-NIFTY-500 research universe never replaces the production `active_eligible_nse` evidence universe. Index-price collection is not company-sector population.

Out of scope: model training, feature-definition changes, auto-promotion, lifecycle/drift enablement, broker trades, historical constituent reconstruction for FEAT-065, Windows protocol changes and a second generic notification platform.

## 3. Architecture

Use Laravel for scheduling, durable work inventory, domain writes, authorization and Admin APIs. Existing Python minute/live collectors remain with their owners and report sanitized coverage through existing control planes. No LLM or new MCP subsystem is required.

Suggested components (names may adapt to repository conventions):

- `ForwardDataPlanner`: enumerate due obligations from calendar, dataset registry and persisted coverage.
- `ForwardDataDispatcher`: claim bounded due work and enqueue owner adapters.
- `ForwardDatasetAdapter`: delegate execute/inspect/recover to existing engines; never declare success merely from job exit.
- `ForwardDataCoverageService`: reconcile expected versus validated persisted data.
- `ForwardDataHealthService`: derive dataset/profile status and incidents.
- `ForwardDataAdminController`: paginated health, work, retries and audited controls.

Prefer jobs per dataset/session or stock-check unit. Retain owner queue constraints and rate limits. Long-running acceptance jobs must not occupy the ordinary collection queue. Do not nest an entire universe inside one HTTP request.

## 4. Durable work and persistence

Reuse owner work/checkpoint records where they already satisfy this contract. Add additive migrations only for missing shared bookkeeping. Suggested logical records:

1. Dataset definition/configuration: stable key, owner, profile, scope policy, cadence, grace, concurrency, recovery floor and configuration version.
2. Collection work: dataset, exchange, session/period, canonical instrument or non-null aggregate scope key, source policy version, status, attempts, `next_attempt_at`, lease owner/expiry, last error category and owner run/source IDs.
3. Provider check state: instrument/provider/dataset, last attempt, last successful check, last accepted change, next due, consecutive failures and sanitized error.
4. Coverage observation: expected/validated/missing/exempt counts, unknown identities, reason counts, last reconciliation, configuration/calendar versions and owner evidence pointers.

Use a unique deterministic work key over dataset/exchange/session-or-period/scope/policy. Avoid nullable unique-key components because MySQL permits multiple NULL entries. A successful check timestamp is independent of immutable fact `first_fetched_at`.

Work states: `pending`, `running`, `waiting_publication`, `retry_wait`, `succeeded`, `blocked_configuration`, `blocked_quality`, `exhausted`, `cancelled`. Failure reason codes include missing publication, unavailable provider, authentication required, rate limited, mapping missing, malformed source, quality rejection and persistence failure. A blocked/exhausted item remains an unresolved obligation until repaired or explicitly explained under owner rules.

Short transactions claim work, recording a fencing token. Provider calls happen outside transactions. Expired leases can be reclaimed safely; stale workers cannot commit with old tokens. Domain writes and success accounting are atomic where feasible; otherwise reconcile from owner evidence after crash. Duplicate delivery cannot duplicate rows, source versions, corrections or alerts.

## 5. Calendar, scheduling and bounded recovery

All market schedules use `Asia/Kolkata`; persist timestamps in UTC and trading dates as exchange-local dates. Reuse the authoritative exchange calendar including special sessions. Weekends alone are not a sufficient calendar. Unknown calendar state blocks planning and raises a configuration incident instead of inventing sessions.

Recommended configurable defaults:

| Operation | Cadence / default |
|---|---|
| Planner/dispatcher and stale-lease recovery | Every 15 minutes |
| Daily NSE evidence and daily price reconciliation | First eligible attempt 18:00 IST after a completed session |
| Coverage/health reconciliation | Hourly and after ingestion completion |
| Fundamentals due-stock dispatch | Hourly, batch 20 initially, continuously through bounded work |
| Successful fundamentals/sector provider checks | Due again after 7 days; provider constraints may require slower documented cadence |
| Corporate-action polling | Daily after close plus bounded overlapping recent-event queries |
| Minute/live collection | Owner schedules; DATA-002 default incremental 18:00 IST; FEAT-063 market-session lifecycle |

Keep existing owner schedules when valid; do not introduce competing writers. Default publication grace is until the next trading session's 12:00 IST; configure provider-specific overrides and expose them. During grace show waiting, not ready. Retry transient failures with bounded backoff and jitter; honor Retry-After. Exhaustion after a configurable default eight attempts raises an incident; subsequent daily sweeps or Admin retry can reopen an obligation without deleting history. Configuration/authentication failures avoid hot retries.

Planner priority: latest completed sessions first, then overdue stock checks, then bounded missed-session repair. Allocate a nonzero configurable repair share (recommended 25%) so newest work cannot starve gaps. Scan from each dataset's explicit forward collection start/recovery floor through the latest completed session; preserve obligations across outages. Seed this floor from audited historical handoff/known coverage or an explicit deployment configuration, not blindly from today's date. Historical work before the floor remains with backfill engines and is shown separately.

Retry unavailable dates rather than labeling an HTTP 404 as a holiday. No-data responses need owner-validated reasons. Listings, suspensions, delistings and non-traded securities require dataset-specific expectations; a bhavcopy is not proof that every listed security traded. Retain missing/exempt reason counts and denominator identity.

## 6. Official NSE source and membership collection

For each required completed NSE session, acquire official date-specific bhavcopy or accepted security-master evidence through the existing downloader and source path. Reuse legacy/UDiFF detection, bounded downloads, date checks, identity mapping, immutable hashes, parser version, sealing and mapping gates. Validate content rather than trusting filename or Content-Type. Reject HTML error pages, mismatched dates, malformed archives and conflicting source identities. Official source locations must be configured/allowlisted; do not add an unchecked remote-URL endpoint.

Reuse production tables including `stox_ml_acceptance_sources`, `stox_ml_universe_snapshot_backfill_runs`, `stox_ml_universe_snapshot_boundaries` and `stox_ml_universe_memberships`. Inspect actual columns before adding bookkeeping; do not recreate these tables.

Forward collection must be independent of a campaign ID. Reuse a common date-acquisition service below campaign/operator commands. Link each boundary to sealed source IDs/hashes, parser and mapping diagnostics. Missing rows or evidence remain `snapshot_unproven`/coverage blockers under existing acceptance logic. Today's master must never prove a past session.

For a newly missing forward boundary: automatically run the existing dry-run and materialize only when all existing quality/provenance gates pass, using the shared membership write lock and auditable system actor. This authorization applies to collecting new validated forward evidence, not changing acceptance thresholds. Existing/overlapping historical boundaries and changed source bytes follow the established preview/review/apply rules; queue a review rather than silently overwriting accepted history. Concurrent historical backfill and forward work must not race effective intervals or deadlock.

Do not hardcode the current campaign's 417 dates. Newly registered or remapped instruments must leave durable mapping repair work. Do not weaken the existing 90% gate; report unmapped identities even when a boundary passes that gate, and preserve the consumer's authoritative readiness decision.

## 7. Fundamentals incremental collection

Fix selection fairness across the eligible universe: due/never-checked stocks first, then oldest successful check, with a stable tie-breaker and claim protection. Repeated runs must advance beyond the first batch. Keep exchange identity/deduplication and eligible-universe semantics owned by FEAT-054; benchmark rows must not be fetched as companies.

Calculate elapsed age in the correct chronological direction or compare timestamps directly. Test installed Carbon/runtime behavior, future timestamps and timezone boundaries. Advance `last_successful_check_at` on a valid unchanged response. Do not change immutable first-fetch/availability evidence or create duplicate revisions when values did not change. An error or malformed empty response is not a successful unchanged check.

Preserve first-known availability dates, reporting periods, amendments, source priority and all canonical TTM/growth rules. Newly discovered historical statements must not become available to prior feature dates simply because their reporting period is old. Durable failures must not starve healthy due stocks. Daily activity is provider polling, not fabrication of daily financial statements.

OPS-001 retains full/targeted backfills, schedules, presets and gap acknowledgement UI. Share selection/check state and locks where appropriate; incremental collection must not fight an active bootstrap. Duplicate schedule delivery must remain harmless.

## 8. Effective-dated sectors

Choose and document a configured official exchange/index classification source where usable, otherwise a licensed/permitted existing provider. Demonstrate its schema, access and universe coverage before marking integration operational. Do not guess classifications or silently classify the whole universe from NIFTY 500 alone.

Persist canonical instrument, normalized taxonomy/version, original source classification, effective date, first-observed/available timestamp, source identity/hash and revision provenance. Unknown values remain explicitly unknown. A current classification may populate current display, but cannot be copied backward into historical feature rows.

PIT lookup must require both effective interval and availability at the observation date. If reliable historical availability is unavailable, retain current-only evidence and explicit historical unknowns. Missing sectors use authoritative unknown/missing-value handling; this epic does not make optional sectors a new training gate. Poll changes, cover newly eligible stocks and expose mapped/unknown/conflicting counts. Taxonomy changes are versioned, with no silent relabeling of accepted research datasets.

## 9. Corporate actions

Inspect the existing `services.data_quality.corporate_actions_feed_url` integration and event model. Implement/configure a demonstrably working allowed provider adapter rather than merely filling a URL whose schema the parser cannot read. Declare auth, environment variables, retention and source availability without committing secrets.

Persist/reuse event identity, stock identity, action type, announcement/availability, ex/record/effective dates, ratios/values, source and revision evidence. Use overlapping date queries or provider cursor plus durable checkpoints to find late events, updates and missed sessions. Identical events deduplicate; corrections preserve prior evidence.

Collection is automatic; portfolio/accounting or price-history corrections remain subject to existing approval/repair rules. Do not adjust investor holdings, rewrite accepted prices or auto-approve an action merely because an event was downloaded. Track unresolved mappings/reviews separately from fetch health. Distinguish a validated zero-event interval from an unconfigured/unreachable feed.

## 10. Prices, minute corpus and live collection

Reproduce and repair existing daily gap-fill failures using owner provider fallback, calendar and adjusted/unadjusted price contracts. Measure coverage from persisted rows across expected instruments/sessions. A successful batch with omitted stocks is incomplete. Corporate-action adjustments remain owner-governed; no new pricing formula.

For DATA-002, surface last complete session, failed windows, staged/acknowledged status and quota/backpressure from its owner. Collection and delivery are distinct: a disconnected Windows receiver does not erase acquired-data evidence. Preserve immutable READY batches and minute-corpus fixed-universe metadata.

For FEAT-063, inspect subscriptions, session state, auth, socket timeouts, finalized partition rows, expected instruments/minutes and finalization evidence. Service `active` is insufficient. Warn on zero rows during an expected open session after a configurable 15-minute grace and on missing/poor finalized sessions under FEAT-063 quality rules. Holidays/closed sessions must not cause false alerts. Never manufacture missed order-book observations from historical OHLCV; irreversible missing days stay visible.

## 11. Coverage, freshness and readiness

Expose for every dataset: enabled/configured state, scope and denominator, latest expected session, latest validated session, last attempted fetch, last successful provider check, last accepted change, coverage, unresolved count, oldest overdue work, backlog, quality failures, configuration version and owner evidence links.

Coverage and freshness are separate. Recent successful polling does not prove historical completeness; old statement values can be valid after a recent successful unchanged check. Healthy service/queue status is operational evidence only.

Dataset health: `ready`, `waiting_publication`, `degraded`, `blocked`, `disabled`, `unknown`. Required disabled/unconfigured/unknown datasets cannot count as ready. Summaries must show all reason codes instead of collapsing every failure to a stale timestamp. Profile readiness uses the authoritative registry and existing acceptance gates; no universal fabricated green flag and no new mandatory feature policy.

Derived ML features and matured labels are computed from validated source inputs through FEAT-057; they are not independent external feeds. Reconciliation must check prerequisites through each horizon's label end date and distinguish not-yet-mature labels from missing prices. Collection does not auto-start campaigns, training or scoring outside existing schedules.

## 12. Admin UI and educational text

Add an Admin Data Collection health page (or coherent section of existing data operations) with dataset cards, profile summary and paginated backlog/run detail. Reuse existing navigation/design patterns. Filters: dataset, state, date range and reason. Controls: Run due checks now, retry selected failures, inspect evidence, pause dispatch and resume. Pause preserves backlog and inflight safety; resume plans missed sessions. Configuration changes affect future work with audited versions.

Link to OPS-001 fundamentals operations, UX-004 acceptance wizard, DATA-002 corpus operations and FEAT-063 controls. Do not recreate their launch flows. Add a compact collection summary to the acceptance wizard through a read-only shared response without bypassing wizard gates.

Plain-language help (hover/focus tooltips and mobile-accessible info buttons):

- Freshness: "When StoX last successfully checked this source. Values may stay unchanged between reports."
- Coverage: "How much expected data is validated. A recent successful job can still leave gaps."
- Backlog: "Data checks or missing sessions waiting to be collected or repaired."
- Waiting for publication: "The source has not published a validated file yet. StoX will retry."
- Membership evidence: "A dated official file proving the eligible NSE securities for that session."
- Point-in-time: "Use only information that was available on the date being analysed."
- Microstructure gap: "Missed live order-book observations may be impossible to recreate later."

Admin-only controls use existing authorization and audited actor identity. Suggested read endpoints: dataset health list, dataset detail, paginated work list. Suggested mutations: bounded dispatch/retry/pause/resume through existing route/version conventions. Final paths must be added to OpenAPI and regenerated docs during implementation. HTTP returns durable run/work IDs; UI polls boundedly and safely resumes after refresh.

## 13. Alerts and operational settings

Persist incident state keyed by dataset/scope/reason. Notify Admins on required dataset missing beyond publication grace, configuration/auth failure, exhausted work, materially overdue stock-check sweep, zero-row live session or coverage regression. Aggregate bursts and send recovery notification when the same incident clears. Reuse COMM-001; use existing notification primitives during its staged implementation and integrate rather than create another mail stack. Reuse OPS-002 for genuine unexpected API failures and FEAT-052 telemetry where available; expected publication delays do not become code-bug reports.

Persist health/incidents even if external notification delivery fails. No secrets, raw provider bodies or account-private data in logs/alerts. Configurable settings include enabled state, forward start, timezone/calendar, publication grace, rate/concurrency caps, retry policy, stock-check age, repair share and alert delay. Avoid duplicate env names when an existing setting provides the same behavior.

## 14. Implementation and rollout sequence

1. Inventory actual registry inputs and current implementation; publish dataset/source/owner/schema mapping and reconcile concurrent remediation commits.
2. Add missing bookkeeping/migrations and regression tests for starvation/freshness; preserve immutable facts.
3. Implement planner/dispatcher and official membership forward adapter with shared locks and governed writes.
4. Verify sector/corporate-action source integrations and price repair; show unavailable integrations explicitly until operational.
5. Integrate minute/live owner health, coverage reconciliation, profile status, Admin UI and notifications.
6. Deploy through repository CI/CD. Enable collection after source/config/runtime checks, seed the recovery floor and reconcile gaps without restarting the separate historical campaign.
7. Record live evidence for completed sessions plus an outage/late-publication recovery scenario. Fixture-only success cannot close runtime acceptance.

Additive migrations use explicit short MySQL index names and portability checks. Preserve unrelated changes and all accepted historical sources/runs. No direct/manual production deployment or unreviewed service-control workaround. Collection activation must not enable training, promotion, lifecycle or drift.

## 15. Acceptance and meaningful tests

| ID | Required evidence |
|---|---|
| FDC-01 | Completed trading sessions automatically create campaign-independent obligations; special sessions/holidays and unknown calendar tested |
| FDC-02 | Duplicate triggers, crashes, expired leases and stale workers cannot duplicate or corrupt domain writes |
| FDC-03 | Outage catch-up and late publication retry retain unresolved sessions; newest work and gaps both progress |
| FDC-04 | Official legacy/UDiFF sources validate date/hash/parser/mapping; bad/HTML/wrong-date data cannot prove a boundary |
| FDC-05 | Forward membership and concurrent historical backfill share locks; changed historical evidence follows review |
| FDC-06 | More than two fundamentals batches progress through the due universe, include new stocks and remain fair with failures |
| FDC-07 | Old successful checks become due; unchanged valid response advances check time while facts/availability/revisions stay intact |
| FDC-08 | Sector classification has source/version/availability evidence; no future or current-only classification leaks backward |
| FDC-09 | Corporate-action feed works end to end, captures late/corrected events and deduplicates without automatic accounting repair |
| FDC-10 | Daily equity/index coverage reconciles persisted expected sessions; known gap failures have reproduced cause and verified recovery |
| FDC-11 | Minute/live health reports evidence, detects zero rows and distinguishes delivery backlog from collection loss |
| FDC-12 | Required unknown/unconfigured/incomplete data remains blocked/degraded even when jobs exit successfully |
| FDC-13 | Admin authorization, pagination, retry idempotency, pause/resume, refresh recovery and accessible help pass tests |
| FDC-14 | Incident aggregation, publication grace, recovery notifications and notification transport failure are tested |
| FDC-15 | No training/promotion/lifecycle/drift or investor portfolio mutations are introduced |
| FDC-16 | Production evidence includes source IDs/hashes, session/scope counts, provider check progression, recovery and exact deployed SHA |

Use deterministic clocks and provider fixtures for edge cases; use the canonical MySQL verifier for backend changes, frontend/browser verifier for UI and migration/OpenAPI checks where affected. Record coverage denominators and legitimate unavailable data; do not demand fabricated 100% provider availability. An implemented collector with a missing provider configuration is not operationally accepted. Update current market-data documentation and the implementation evidence ledger when behavior actually ships.

## 16. Frozen product defaults

- Automatic forward collection and bounded missed-work recovery; historical corrections keep owner review gates.
- Each dataset has its own cadence; statements are checked regularly rather than invented daily.
- Production eligible-NSE evidence and fixed NIFTY-500 research corpus remain separate.
- Existing owner services/tables/providers/notifications are reused; unavailable sources remain explicit blockers.
- Current-only sector information is useful now but cannot silently fill past classifications.
- Daily ML readiness and optional minute/live research health are separate.
- Admin sees freshness, validated coverage, backlog and actionable reasons in plain language.
- Collection is independent of training, model promotion and lifecycle/drift activation.
