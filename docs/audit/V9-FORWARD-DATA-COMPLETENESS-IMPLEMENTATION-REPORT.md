# StoX forward-data completeness — implementation report

**Audit baseline:** 2026-10-01
**Scope:** forward daily data readiness, not training, promotion, lifecycle or drift automation.

## Ownership and overlap resolution

| Concern | Owning contract | Reconciliation |
|---|---|---|
| Ongoing fundamentals acquisition, freshness and revisions | V8 FEAT-054 beneath V9-OPS-001 | Reuses the existing `FundamentalDataService`/`FundamentalUpdateService` engine. No second fundamentals ingestion path was introduced. |
| Historical minute corpus, sealing, repair and delivery | FEAT-065 / V9-DATA-002 | No minute-corpus collector, staging, source, run, seal, or repair contract was changed. The Mac remains canonical and V9-DATA-002 remains the delivery topology around FEAT-065. |
| Live microstructure | FEAT-063 | Remains a separate prospective collector and is not used as a daily ML readiness gate. |
| Daily ML readiness | StoX daily price/PIT membership/fundamental checks | Minute-corpus coverage is explicitly reported as optional/diagnostic and cannot make daily coverage complete. |

## Implemented closure items

### Official NSE membership provenance

`stox:forward-data` is scheduled independently of campaigns. It derives completed NSE session dates from the trading calendar, persists work obligations, applies publication grace, and delegates to `NseHistoricalUniverseArchiveProvider`. When no pre-staged file exists, the provider reuses the existing official NSE archive downloader against the allowlisted `nsearchives.nseindia.com` host, stages the date-specific archive, and then applies the existing safe extraction, filename/content date validation, parser validation, symbol mapping, and 90% mapping gate. The provider has no current-master fallback. Missing or late files remain in durable forward work and snapshot backfill state with bounded, persisted retry counts.

All membership writes continue through `MlHistoricalUniverseMembershipService::withWriteLock()` using the existing `ml-historical-membership-write` lock. Existing source paths, historical runs, snapshot boundaries, SHA/snapshot keys, and the separate download session's artifacts are preserved.

### Effective-dated sectors

Sector values are stored on each dated membership row as `sector_snapshot`; missing values remain `NULL` and are surfaced as unknown. Historical lookups use the effective-dated row only. Current `portfolio_stocks.sector` is not consulted by dated historical ingestion.

### Fundamentals under FEAT-054 / V9-OPS-001

Scheduled selection is fair across the eligible universe, ordered by oldest successful provider check rather than stock ID. Provider checks are durable per stock/cadence, including successful empty and unchanged responses. `first_fetched_at` remains immutable; `last_provider_checked_at` and the provider-check ledger track later checks. Availability dates and revision rows remain unchanged by refreshes. Signed elapsed-time comparison prevents future timestamps from being treated as overdue.

### Corporate actions

The established exchange-feed-to-pending-review path remains the only ingestion path. Feed requests now retry bounded transient failures, invalid dates are rejected instead of becoming `1970-01-01`, rights issues remain excluded, and the existing evidence/run/approval/repair workflow is preserved. Durable checkpoints record overlapping windows and payload hashes; production can enable provider window parameters without changing the event model.

### Daily-price recovery and optional intraday data

`stox:check-data-completeness` reports freshness, coverage, backlog and last successful ingestion for NSE membership, daily prices, fundamentals, corporate-action observations, FEAT-065 corpus owner status, and FEAT-063 live collector status. It alerts on missing sessions and incomplete eligible-universe coverage even when the acquisition process itself exits successfully. Intraday/microstructure is reported separately and never gates daily ML readiness.

## Verified runtime evidence

Local runtime evidence from the repository environment:

- `FundamentalUpdateLifecycleTest`: **19 passed**, including fair/durable provider-check behavior, unchanged responses, empty successful responses, bounded retry and terminal-run behavior.
- Membership/provider/corporate-action focused suite: **26 passed / 84 assertions**, including dated source validation, mapping failure, durable late-file retry/resume, effective-date coverage and exchange-feed rejection rules.
- Combined final focused rerun: **34 passed / 110 assertions**; migration portability: **152 migrations passed**.
- NSE snapshot provenance now records a content SHA-256 in diagnostics and uses it as the snapshot key; it is not derived from a mutable path.
- Official archive probe: `nsearchives.nseindia.com` returned a valid date-specific ZIP; the public `www.nseindia.com` homepage returned HTTP 403 and is not used by the acquisition path.
- PHP syntax checks passed for all new/changed services and commands.
- `git diff --check` passed.

Production VPS acquisition, official NSE publication availability, corporate-action endpoint reachability, and live daily-price recovery still require the deployed scheduler/health evidence to be attached at release time; local tests do not substitute for that external evidence.

## Explicit non-scope

No training campaign, model promotion, lifecycle schedule, drift automation, FEAT-065 minute-corpus implementation, V9-DATA-002 transfer protocol, or FEAT-063 live collector behavior was enabled or repurposed by this change.

## V9-DATA-003 acceptance matrix

| Criterion | Status | Implementation / evidence |
|---|---|---|
| FDC-01 | Partial | `ForwardDataPlanner` persists campaign-independent session obligations and uses the exchange calendar. Special-session and unknown-calendar production evidence remain required. |
| FDC-02 | Implemented locally | Durable identity, claim lease, expiry reclaim and owner commit/failure updates fenced by lease token and unexpired lease; stale-worker regression test passes. Production crash/reclaim evidence remains required. |
| FDC-03 | Implemented locally | Persisted waiting-publication/retry/exhausted states and bounded backoff; late-file/provider tests pass. Production outage replay remains external evidence. |
| FDC-04 | Implemented | Existing NSE parser/date/mapping gates plus SHA-256 snapshot identity reject invalid sources; archive/provider focused tests pass. |
| FDC-05 | Partial | Historical and forward membership paths share the existing write lock. Changed overlapping evidence still requires the acceptance preview/review/apply path rather than automatic replacement. |
| FDC-06 | Implemented locally | Fair oldest-check selection, durable provider-check state and bounded retries are covered by the fundamentals lifecycle suite. |
| FDC-07 | Implemented locally | Unchanged/empty successful responses advance provider-check state without changing first fetch, availability or revisions; 19 lifecycle tests pass. |
| FDC-08 | Partial | Effective-dated sectors retain configured `nse_mii_security_file` source, taxonomy/revision provenance and explicit unknowns. Production source schema/access and historical availability evidence remain required. |
| FDC-09 | Implemented locally | Existing review/evidence/repair flow, bounded HTTP retry, invalid-date rejection and rights exclusion are preserved; overlapping feed checkpoints and payload hashes cover late/corrected polling when provider window support is enabled. Production provider evidence remains required. |
| FDC-10 | Partial | Completeness reporting distinguishes persisted daily coverage/backlog, but a deployed recovered-session price/index run is still required to close the criterion. |
| FDC-11 | Implemented locally | Shared completeness output now embeds sanitized FEAT-065 checkpoint/corpus status and FEAT-063 collector/finalization/coverage status without treating either as daily readiness. Production owner evidence remains required. |
| FDC-12 | Implemented locally / Partial production | Health now includes required configuration datasets, explicit blocked/unknown/degraded reason codes, publication-grace exclusion, and command-level alerting even when acquisition exits successfully. Production configuration and incomplete-universe evidence remain required. |
| FDC-13 | Implemented locally / Partial production | Admin-only health, bounded pagination, idempotent retry, dispatch, pause/resume, refresh-error recovery and the dedicated accessible-help `/settings/forward-data` page are covered locally. Production browser/API evidence remains required. |
| FDC-14 | Implemented locally / Partial production | Publication grace is aggregated separately from due failures; completeness failure and recovery paths call the existing unattended alert/notification primitives; transport-failure reason codes and notification behavior are tested locally. Production incident/transport/recovery evidence remains required. |
| FDC-15 | Implemented | No training, promotion, lifecycle, drift, portfolio mutation or new feature policy was introduced. |
| FDC-16 | Partial | Local evidence is recorded below; deployed SHA, source IDs, production counts and an outage/late-publication replay must be attached after deployment. |

### Current verification evidence

- Focused final suite: **49 passed / 173 assertions** (including forward planner fencing, sector provenance and corporate-action checkpoint coverage).
- DATA-003 admin/health/alert/provider regression: **76 passed / 261 assertions** when combined with the prior forward-data, provider and alert suites.
- Node test runner: **198 passed**; TypeScript typecheck: passed. The Vitest phase and Vite production build are blocked by the host's Node **18.20.0**; the repository requires Node **20.19+ or 22.12+**.
- Forward planner tests cover fail-closed recovery-floor configuration, session obligation persistence, lease expiry reclaim and non-duplicate claiming.
- Migration portability: **156 migrations passed**.
- PHP lint and `git diff --check`: passed.
- Production evidence is intentionally not fabricated: local CI lacks the required OpenTelemetry extension and local scheduler inspection cannot connect to MySQL.
- Full application PHPUnit was attempted: 127 tests passed before three pre-existing dirty-worktree unit errors and a PHP 128 MiB route-loading fatal terminated the run. The repository `verify-ci.sh --all` gate stopped earlier because OpenTelemetry is unavailable; `--frontend` requires Node 20 while this host has Node 18.20.0.

### Production evidence register at release

The `cb80d10` CI/deployment runs were cancelled by the later `5465f83` documentation-only push. The later deployment workflow succeeded with all deployable jobs skipped, so it did not deploy this work. The live `/api/build-info` endpoint still reports deployed SHA `59acd30e5577ce1d71770a425e304034afbb0271` (run `36895836940`), not `cb80d10`. The public homepage returned HTTP 403, while the supported date-specific `nsearchives.nseindia.com` archive returned a valid ZIP; this local probe is not production scheduler evidence. No production sector source path or corporate-action feed URL is configured. Therefore no production source hashes, scheduler replay, coverage counts, batching progression, or recovery evidence is asserted here. The required evidence state for every criterion is recorded explicitly:

| Criterion | Production evidence state at release |
|---|---|
| FDC-01 | Pending deployed scheduler observation of completed, special and unknown-calendar sessions. |
| FDC-02 | Pending crash/lease-reclaim observation with stale-worker rejection. |
| FDC-03 | Pending outage catch-up and late-publication replay. |
| FDC-04 | Pending deployed official-source ID/hash/parser/mapping evidence. |
| FDC-05 | Pending concurrent historical-download lock evidence and review-gated changed-source evidence. |
| FDC-06 | Pending production multi-batch fairness/progress and bounded-retry evidence. |
| FDC-07 | Pending provider-check ledger evidence for stale, unchanged and empty responses. |
| FDC-08 | Pending production sector source schema/access, effective-date and unknown-row evidence. |
| FDC-09 | Pending late/corrected corporate-action window poll, checkpoint and approval/repair evidence. |
| FDC-10 | Pending recovered daily equity/index session counts and last-success timestamps. |
| FDC-11 | Pending FEAT-065 and FEAT-063 health payloads, including zero-row/delivery-loss distinctions. |
| FDC-12 | Local reason-code/blocked-state tests pass; pending deployed successful-exit/incomplete-universe alert evidence. |
| FDC-13 | Local authorization/pagination/idempotent retry/refresh tests pass; pending deployed Admin browser evidence. |
| FDC-14 | Local grace/alert/recovery tests pass; pending deployed transport-failure, recovery-notification and incident evidence. |
| FDC-15 | No production enablement evidence is required; scope inspection confirms training, promotion, lifecycle and drift remain disabled. |
| FDC-16 | Pending deployed SHA, source IDs, counts, provider progression and recovery replay bundle. |

Accordingly, criteria marked Partial above remain Partial until the corresponding production evidence is attached. The active production workflow is the VPS workflow in `.github/workflows/deploy-stoxla-production.yml`; the legacy cPanel packaging path was not used. No training, promotion, lifecycle, drift or ML feature-policy boundary was changed while implementing these health controls.

## Evidence addendum — 2026-10-02, continuation from `920eb6a`

- The active production release before this continuation was verified as `920eb6a19967493e38c281f15e193713cd142deb`; its build identity was `build-328-attempt-1-920eb6a19967493e38c281f15e193713cd142deb`.
- The managed Laravel queue resolved `/var/www/stoxla/artisan` to that release. The FEAT-063 microstructure collector remained a separate shared-runtime process. `stoxla-ml-acceptance.service` and `stoxla-scheduler.service` were inactive; no restart or schedule was issued. The historical-download handoff remains preserved: its preview covered 360 requested dates, but the apply run was cancelled after a release/digest mismatch and processed zero dates. Therefore no preview date is promoted to `forward_start_date`, and the earlier gap remains explicit.
- Read-only production counters at the handoff were: zero NSE snapshot boundaries, one cancelled snapshot-backfill run, 413 acceptance sources, zero forward collection work rows, no forward collection control row, and zero corporate-action feed checkpoints. These are evidence of incompleteness, not coverage.
- The official date-specific NSE archive probe returned HTTP 200 and a ZIP for 2026-10-01 containing the expected bhavcopy CSV. Probe SHA-256: `ccc5fb27872716bbcc99d2d87e522ab304f6620e11c3a5045c30e1ff25cbfb73`. The official equity master probe returned HTTP 200 with SHA-256 `95f0d731f5858f71e876c45377dcefa79bc7a8db1e8161d0c92320252af4aa8f`; it contains no sector field and is not treated as a sector source.
- The official NSE corporate-actions endpoint was verified to return JSON rows with `symbol`, `isin`, `subject`, `exDate`, and `recDate`; a live example was `SBC / INE04AK01010 / Bonus 1:2 / 10-Mar-2025`. The adapter now sends the required browser-like request headers, maps this schema into the existing pending-review queue, preserves the raw payload/evidence and checkpoint hash, rejects unsupported rights actions, and never applies accounting corrections automatically. Production checkpoint, late/corrected poll and approval/repair evidence are still absent, so FDC-09 remains Partial.
- The completeness model now reads the deployed `portfolio_data_quality_issues` model table through `DataQualityIssue`, fixing the production table-name mismatch without changing feature policy. The new regression and adapter tests pass locally: 11 tests / 80 assertions. Canonical backend/all verification is blocked by the host missing the CI-required OpenTelemetry PHP extension; frontend/all is additionally blocked by Node 18.20.0 while the repository requires Node 20.19+ or 22.12+. These gates are not weakened.

The next release must attach its exact deployed SHA, post-deploy completeness report, source/checkpoint identifiers, and bounded recovery evidence. Sector classification remains explicitly unknown because no suitable official/licensed sector schema and historical availability have been verified. FDC-01, FDC-03, FDC-05, FDC-08, FDC-09, FDC-10, FDC-11, FDC-12, FDC-13, FDC-14 and FDC-16 therefore remain Partial where the matrix requires production evidence; FDC-15 remains unchanged and all ML boundaries remain preserved.

## Runtime verification — deployed `c15a2f9`

- CI `36955157699` and deployment `36955157808` completed successfully. The active release was verified as `c15a2f980aaffd314ff305ec9d72467aad163604` (`build-329-attempt-1`).
- Scheduling is cron-driven: the application crontab runs `cd /var/www/stoxla && /usr/bin/php artisan schedule:run` every minute. There is no `stoxla-scheduler.service`; `schedule:list` shows `stox:forward-data` every 15 minutes, completeness/fundamentals hourly, and corporate-action sync at `:45`. The separate `stoxla-ml-acceptance.service` is inactive and was not restarted. Recent scheduler logs prove invocation but also show repeated nonzero exits for forward collection and completeness.
- The deployed completeness command now emits the full report and exits nonzero only for detected incompleteness: NSE membership coverage `0` with backlog `21`, daily-price coverage `99.8453%` with backlog `4`, fundamentals backlog `2585`, and FEAT-063 observed zero rows; FEAT-065 is disabled. The previous missing-table exception is resolved.
- The first live review-only corporate-action attempt exposed that passing an empty query array to Laravel removed the configured NSE `index=equities` query, producing the provider response `Missing index`. No approval, repair, or accounting correction occurred. The follow-up fix preserves the configured URL query and adds a regression assertion; production provider/checkpoint evidence remains pending that follow-up deployment.
- Recommended PO floor: `2026-10-01`, the first completed session with a verified date-specific official NSE archive probe and current daily-price evidence. Impact: it starts durable forward obligations at that floor while leaving all earlier sessions—including the cancelled historical handoff range—as explicit historical backlog; it does not claim any membership date covered until the archive is parsed, mapped, sealed, SHA-recorded, and committed under the shared lock. This floor should not be configured without PO acceptance of the backlog/recovery implications.
