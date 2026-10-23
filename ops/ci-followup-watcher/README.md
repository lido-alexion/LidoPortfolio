# CI follow-up watcher source package and operator runbook

This versioned package contains the reviewed watcher source, configuration lifecycle, evidence access helper, disabled sample configuration, and offline tests. It adds no activation helper, scheduler, service, credential, or deployment hook.

## Inspection

The accountable watcher owner first identifies the exact intended checkout and distinguishes process existence from useful work. Read the current process identity, command, start time, and recorded child/session ownership through approved read-only process inspection. Correlate that with the watcher heartbeat, audit tail, state receipt, and source/config revisions. Do not treat a PID alone, a log file, or process existence as proof that the process is current or productive.

The heartbeat and state report the effective loaded config revision and load time. Compare them with the current disk revision and target summary. `global_discovery` describes public runs returned by the repository query; it is not a dispatch allowlist. Only the explicit effective dispatch allowlist, after target policy and owned-worktree checks, controls eligible dispatch.

## Owner, pause, lease, and handoff

Before a source/config ownership handoff, the named owner records the requested change, current process/child ownership, effective and requested revisions, pending-work status, and remaining dependency in the authorized checkpoint. The owner establishes `PAUSED`, then acquires the existing cooperative `watcher.lock` lease before editing or publishing shared source. Confirm that the exact owned child is inactive before any owner action that requires it; do not infer ownership of another process. Keep the singleton and lease contract intact. Never unlink the lock or clear state to make ownership appear free.

The owner completes the bounded edit/review, records its outcome, releases lease ownership, and only then clears a pause that the same owner established. A handoff names the next owner, revisions, evidence locations, unresolved failures, and pending events. Watcher children do not publish, merge, or activate source.

## Configuration and dispatch

A valid config is validated before it becomes effective at the next serialized poll boundary under the existing lease, after any synchronous child work ends. Reload does not start a second dispatcher or reset event deduplication. Invalid, unreadable, or malformed edits retain the last valid in-memory config and produce a sanitized error receipt; correct the proposed file through the owner process and review the next boundary receipt. Do not reset state to force a reload.

A pending event is checked against the full current target identity and policy before dispatch. Removed, renamed, disabled, or unconfigured targets remain pending; a same-name target cannot inherit another target's authority. An empty worktree disables dispatch. A configured target is eligible only when it remains in the reviewed allowlist and points to its exact already-owned checkout. Public repository discovery, disk contents, and historical run data do not grant dispatch authority. Existing attempt caps, API backoff, exact-head checks, and report-only restrictions remain in force.

`config.sample.json` is illustrative only. It keeps the required PR 240 baseline entry, blanks every worktree, and disables every target. Do not copy it over a live config or start a watcher from this source package as part of source integration.

## Failure, rollback, and activation boundary

For an invalid config, retain the last valid effective revision and inspect only sanitized audit/state evidence. For API faults, preserve recorded backoff and pending work. A missing exact cached CI log is evidence-blocked; it does not justify a blind diagnosis launch or broader credentials. For child failure, retain the output/final receipt and use the recorded result; do not exceed existing attempt limits or launch a duplicate worker.

If source behavior is suspect, record the exact source SHA, config revisions, heartbeat/audit evidence, pending-event keys, and owning process/session. Root reviews the failure and identifies a known-good source through normal protected version control. Any rollback of a live checkout or process requires a genuinely permitted operator capability and the existing ownership/lease procedure. If that capability is unavailable, leave live source untouched, keep direct CI review available, and report activation as blocked. Do not retry previously denied restart, EOF, cancellation, STOPPED, signal, or direct-live-source actions by another route. Do not swap source under the old daemon or start a replacement dispatcher as a rollback substitute.

Source integration alone cannot update an older running Python process. AC02 remains blocked while the deployed daemon lacks this reload code; offline tests do not qualify a live revision. Any future activation must be separately root-reviewed and use only an explicitly permitted procedure.

## Verification

Run the standard-library fixtures from this directory with `python3 -m unittest discover -s tests -v`. Hosted CI runs this focused job only when `ops/ci-followup-watcher/**` changes. The package itself remains outside backend, frontend, VPS-health, and deployable scopes; edits to `.github/workflows/ci.yml` continue to use its original canonical classifier scopes and gates.

See [V9-OPS-004 implementation ledger](../../docs/audits/V9-OPS-004-implementation-audit.md) for acceptance evidence and remaining dependencies.
