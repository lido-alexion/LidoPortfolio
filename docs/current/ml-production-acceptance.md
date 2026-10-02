# Production ML acceptance amendment — 2026-09-30

Status: FROZEN normative amendment to FEAT-057 and FEAT-056. This document governs the production acceptance operations gap; the existing feature, PIT, validation and manual activation contracts remain authoritative.

## Corrections and boundaries

FEAT-057 PIT amendment §6 requires a fresh deployed 1m/3m/6m campaign before lifecycle enablement. Worker installation alone is insufficient; the earlier FEAT-056 runbook precondition is corrected accordingly. FEAT-065's Mac location applies to heavy offline research and its minute corpus, not this production acceptance exercise. This campaign runs in the deployed Laravel queue through the configured real Python adapter. Offline artifacts still require the existing controlled transfer, registration and explicit lifecycle review. Neither fixtures nor local campaigns constitute production acceptance.

Historical fundamentals must obey FEAT-054's four consecutive quarterly TTM and same-period-prior-year growth rules, including missing-value rules and the existing nonzero absolute prior-year denominator for growth. Current market-data contract §§18–20 requires missing fundamentals to remain unavailable; `FundamentalDataService::ttmFlowSum` and `growthMetric` supply the accepted calculation anchors. Independent review confirmed the historical builder's abbreviated sums and adjacent-quarter growth inconsistent with that contract. Aligning only historical TTM completeness and same-period growth, preserving the canonical 20-day period tolerance and negative-base growth convention, and bumping immutable registry identity is a correction, not a new training policy. No unrelated valuation or ratio formula is changed.

## Read-only report

Existing Sanctum session and StoX Admin authorization protect every acceptance API. GET reports never dispatch work, probe Python, backfill, create settings, mutate schedules or record acceptance. Reports expose build identity/report time, migration readiness, last queue/Python evidence with observation time, global lifecycle and per-horizon schedule/drift states. Missing, stale and unknown evidence are explicit blocking states. Table existence and a health endpoint do not prove runtime readiness.

Report reference dates come from the canonical builder's viable observations, not guessed calendar buckets. Each required date needs its own source boundary, validated contemporaneous date, provenance and >=90% canonical mapping diagnostics; interval coverage alone cannot pass. Membership coverage must be 100%. Report historical breadth presence and dated sector known/unknown counts independently. No current membership or sector fallback is allowed.

FEAT-054 evidence is computed from the actual canonical dataset rows by feature/reference date/partition, with relevant stock identities and PIT fact-period/availability provenance. Bootstrap status is reported independently and cannot substitute for usable feature coverage. Core fundamentals need usable historical values; unavailable optional features remain explicitly excluded under the existing adapter rules. Coverage is never fabricated from total fact counts.

## Private immutable source staging

Admin starts a manifest-bound upload for one dated NSE source at a time, then sends resumable offset-addressed chunks. Manifest version 1 pins source family, official filename, requested date, byte count and SHA-256. No server path or URL is accepted. Finalization is queued; HTTP never parses a historical source. Validation checks size/hash, official filename and content dates, parser headers and eligible contents. Validation does not require the master to map >=90%; mapping is evaluated separately during preview/backfill.

Only CSV or ZIP containing exactly one flat CSV is accepted. Reject directories, traversal, absolute paths, backslashes, links, encrypted entries, nested archives, multiple entries, excessive expansion and invalid checksums. Never use archive extraction to supplied paths. Limits: 16 MiB compressed/source, 32 MiB expanded, 100:1 expansion, 1 MiB chunks, 2 GiB reserved total, 4 incomplete uploads per Admin. Store beneath private Laravel storage outside the public root using generated identities; seal validated files read-only, preserve source hash and never replace a sealed object. Duplicate chunks must match existing bytes. Failed finalization is resumable only by a new upload; incomplete uploads may be explicitly cancelled. PIT evidence journals are capped at 512 MiB per horizon, and at most 100 campaigns are retained before further campaigns fail closed. Referenced sealed sources/evidence are retained; cancelled/failed unreferenced payloads may be removed, but audit metadata remains. Quotas fail closed rather than silently pruning evidence.

## Backfill

Reuse membership service, snapshot boundaries and `MlUniverseSnapshotBackfillRun`. An explicit dry-run queues bounded one-date work units, persists mapping/provenance preview, and performs no membership writes. Apply requires successful preview, pins its sealed sources and executes one date per queue job. Existing dates are never overwritten: matching provenance is idempotent; conflicting or unproven provenance blocks. A global distributed lock serializes membership writes. Durable cursor/results/retry state survives restart; Admin status/resume/cancel is supported. Cancellation is checked between units. Three date attempts with bounded backoff, then failed state; resume preserves completed units. A disconnected browser cannot start or cancel work implicitly.

## Campaign

Admin explicitly creates a persisted campaign with cutoff, build, registry/profile and configuration identities, then queued preflight prepares each horizon through the canonical dataset builder. Preflight records reference-date mapping gates, breadth/sector/fundamental coverage, exclusions, dataset hashes and blocking reasons. It must complete successfully before a separate explicit start queues linked canonical manual training runs for all three horizons under existing horizon locks. Input hashes/configuration identities must still match at training. One acceptance campaign may be active at a time. No alternate labels, thresholds, tuning, calibration or training implementation is introduced. The existing canonical training-universe selection remains unchanged; acceptance reports its actual rows and does not invent a membership fallback.

Each run persists existing feature selection/coverage, folds, calibration, baseline, candidate and artifact evidence. Campaign status links that evidence and records actual worker/adapter execution; a completed quality rejection may satisfy runtime execution, but missing evidence cannot. Production qualification requires the deployed build identity, production environment, real adapter evidence and all three complete horizons with unchanged active model identities. Campaign cancellation uses existing training cancellation. Resume never duplicates successful runs; failed training requires a fresh reviewed campaign.

## Runtime and lifecycle gate

Acceptance uses an asynchronous supported database/Redis queue with a visibility timeout greater than job timeout. Unknown queue-worker execution remains unknown until a queued preflight completes; adapter health is only an observation, not training acceptance. Long-running work uses bounded job timeouts and distributed locks; no synchronous/deferred fallback is permitted.

Lifecycle execution and schedule enablement require persisted production-qualified evidence no older than 30 days, matching the current build, registry and training configuration. Missing migration/evidence, stale evidence, changed identity or failed/cancelled campaigns fail closed. Acceptance never enables global lifecycle, schedules, drift triggers, promotion or rollback. Automatic promotion/rollback remain prohibited. Existing explicit Admin model controls remain separate.

All mutations retain actor/time, immutable identities and durable action history. APIs return allowlisted summaries, never secrets, raw provider payloads, host paths, subprocess stderr or unbounded unmapped identifier dumps. Session mutations use existing CSRF protection; no static or query-string tokens, public maintenance scripts or new ML roles. Admin request throttles and server-enforced quotas apply. Retention must preserve sources and evidence referenced by acceptance; no acceptance action prunes active/retained model artifacts.

## Legacy maintenance finding

`deploy/cpanel-run-universe-maintenance.php` is still copied by `deploy/prepare-upload.ps1` and referenced by `cpanel-schedule-diagnostic.php`; it appears deployable through legacy staging. Its static query token, unlimited execution and guard-clearing options warrant a separate removal/hardening issue. It is not an ML path. Deployed presence was not inspected, and no production changes are authorized here.

## Implemented operator/API path

Settings → ML Scoring includes the Production ML acceptance panel. `GET /api/v1/admin/ml/acceptance` reads the latest persisted evidence; before an asynchronous preflight has observed reference dates, those dates and coverage are explicitly unknown. Staleness and identity matching are reported independently.

- `POST /sources` creates a version-1 manifest reservation; `PUT /sources/{id}/chunks` accepts contiguous or identical duplicate base64 chunks. `POST /sources/{id}/finalize` queues validation; `/resume` requeues interrupted validation, and `/cancel` cancels incomplete uploads. GET source status/list is read-only.
- `POST /backfills/preview` pins sealed source IDs in the existing backfill-run model; `GET /backfills/{id}` reads status and evidence; POST `/apply`, `/resume`, `/cancel` are explicit actions. Apply rechecks source and mapped-membership hashes. Existing unproven/conflicting boundaries require separate provenance remediation; acceptance never overwrites them.
- `POST /campaigns` requests preflight with a cutoff. GET `/campaigns/{id}` reads linked horizons. POST `/start`, `/resume`, `/cancel` control work. Completed run IDs and preflight dataset hashes remain pinned. Failed training requires a fresh campaign.

All paths above are relative to `/api/v1/admin/ml/acceptance`. Request bodies are capped at 1,500,000 bytes and chunks at 1 MiB decoded. Build identity comes from the existing `bootstrap/build-info.json`; missing metadata cannot qualify. The existing runtime must separately provide supported queue visibility, worker timeout, distributed cache and the real Python adapter. No production configuration is changed by this implementation.

FEAT-054 reports aggregate usable/missing values by feature, partition and reference date, plus available PIT fact period/availability counts. A private hashed journal links actual stock IDs and available fact IDs to those rows; available facts alone do not assert a complete TTM or a usable feature. Bootstrap run state is separate. Missing evidence remains unknown. Acceptance-linked model artifacts are excluded from later bounded retention pruning.

Local verification is implementation evidence only. Production archive availability, historical coverage and runtime campaign completion require a separately authorized deployed operation. The legacy maintenance finding above is source inspection only; a separate cleanup issue is warranted, but this task does not inspect its deployed presence or execute it.

## Local verification — 2026-09-30

The resumed final verification passed PHP lint on all 29 task PHP files; 30 acceptance/recovery/retention tests (132 assertions); 177 ML/fundamentals/NSE feature tests (593 assertions), with the optional bounded real-adapter test subsequently run separately and passed (1 test, 1449 assertions) using the local Python environment. Python adapter unit tests passed 11/11. Frontend build, typecheck, static documentation contract (53 topics), documentation tests (5), acceptance panel tests (3), and journey metadata tests (2) passed. The acceptance suite exercises migration rollback/reapply only in isolated SQLite; no production migration was run. Vite reports a non-failing large-chunk warning.

Final review corrected source-list pagination: Admin can navigate beyond the first 25 sources, retain selections across pages, and explicitly resume queued validation on an older source. Local verification remains distinct from production qualification. Exact local commands and deployment-skip rationale are recorded in `implementation.md`.

## Dedicated queue amendment — 2026-10-01

Acceptance source validation, backfill, preflight, campaign completion and campaign-linked canonical training (including retry/recovery/resume) use connection **ml-acceptance**, queue **ml-acceptance**. The shipped database connection shares the existing jobs table but uses a distinct queue name and a 15,000-second reservation window. Job and dedicated worker timeout are 14,400 seconds; long operation locks outlive that timeout and expire before redelivery. The notifications/default connection retains its 90-second window and existing worker command. Never add ml-acceptance to that worker or make the dedicated connection the application default.

Readiness checks the dedicated connection's supported database/Redis driver, exact queue, visibility strictly greater than 14,400 seconds, and the actual default cache store driver (database/Redis). Configuration readiness does not prove service installation, backend connectivity, worker execution or Python execution. All participants must use the same distributed cache and lock namespace, including canonical horizon and historical membership locks. No local-cache/testing bypass qualifies.

A queued preflight records dedicated worker evidence only from an actual reservation on that connection and queue, with observation time and build/configuration identity. Direct service invocations and older timestamp-only evidence do not establish dedicated worker execution. Missing, older-than-30-days, future-dated or identity-mismatched worker evidence is unknown in the runtime report and cannot qualify a campaign. Python evidence still requires the real canonical adapter execution; starting systemd is not acceptance and GET remains read-only.

Install and operate the dedicated worker using [the VPS runbook](../../deploy/STOXLA-VPS-DEPLOY.md#dedicated-ml-acceptance-worker). Installation/start is a separate operator step after successful application deployment. No upload, preflight, backfill or training is implied by installing the service. Lifecycle, schedules, drift and retention remain disabled until separately authorized under the existing acceptance gates.


## Issue #18 historical-universe operations

Apply the `2026_10_02_000001_add_source_diagnostics_to_ml_universe_backfill_runs` migration through the verified release workflow before using registry `v8-registry-13` / parser `nse-pit-universe-parser-3`. Keep `STOXLA_ML_LIFECYCLE_ENABLED=false`, `STOXLA_ML_SCHEDULE_1M_ENABLED=false`, `STOXLA_ML_SCHEDULE_3M_ENABLED=false`, `STOXLA_ML_SCHEDULE_6M_ENABLED=false`, and `STOXLA_ML_DRIFT_TRIGGER_ENABLED=false`. Acceptance does not authorize promotion.

Use original dated NSE files, not hand-built JSON. Configure `STOXLA_ML_NSE_MII_PATH` with a private directory of actually available dated MII exports and `STOXLA_ML_NSE_BHAVCOPY_PATH` with original historical cash-market exports. An absent MII date falls back to cash bhavcopy; an invalid/misdated supplied file fails closed. Missing local cash files can be acquired with `STOX_FORWARD_DATA_OFFICIAL_SOURCE_ENABLED=true`, `STOX_FORWARD_DATA_OFFICIAL_SOURCE_BASE_URL=https://nsearchives.nseindia.com`, and `STOX_FORWARD_DATA_OFFICIAL_SOURCE_DIRECTORY` pointing to a private writable archive directory. The existing downloader chooses legacy archives before 2024-07-08 and UDiFF thereafter. MII remote availability is not inferred: obtaining authoritative dated MII exports remains an operator prerequisite when using that source. No source yields a current-universe substitute.

After approved release, from `app/`, the CLI backfill path for an exact reference-date set is:

```bash
php artisan ml:backfill-nse-universe --dates="$REFERENCE_DATES" --mii-path="$MII_DIRECTORY" --bhavcopy-path="$BHAVCOPY_DIRECTORY" --attempts=3
```

`REFERENCE_DATES` must be the comma-separated union of the fresh 1m/3m/6m campaign's required reference dates; the directory variables must point to private original NSE archives. Resume a failed run with the same command plus `--run-id=RUN_ID` after correcting its source/mapping problem. Completed dates are skipped. Alternatively `--from=YYYY-MM-DD --to=YYYY-MM-DD` selects dates present in StoX market history, excluding weekends/trade holidays; it does not manufacture sessions. For governed deployed acceptance use the existing Admin source staging/preview/apply flow and its digest checks; do not use CLI to bypass a failed or cancelled governed apply.

Audit `stox_ml_universe_snapshot_backfill_runs.source_diagnostics[date]` for source/date/version, source/mapped/unknown counts, unknown identifiers and mapping percentage, including rejected dates. Successful boundaries retain `quality_diagnostics`; membership source and snapshot hash match their boundary. Each snapshot must map at least 90%; empty/excluded-only responses fail. Existing immutable boundaries are not rewritten by reruns.

Production acceptance remains open until a fresh current-build campaign has 100% requested membership-date coverage, >=90% mapping on every date, nonzero historical breadth coverage, unknown/null sector values wherever dated classification is absent, and real Python adapter completion for 1m/3m/6m with persisted feature coverage/exclusions. Reconcile any prior cancelled governed run before starting new work. Local fixture tests establish correctness, not NSE historical availability or production coverage.

The legacy `ml:capture-universe-membership` command is restricted to today’s date. Past/future `--effective-from` values fail before membership or boundary writes; historical population must use dated-source backfill.
