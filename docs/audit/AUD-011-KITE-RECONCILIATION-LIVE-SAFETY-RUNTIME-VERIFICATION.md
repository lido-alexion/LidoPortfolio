# AUD-011 - Kite Reconciliation / Holdings Block / Recovery Runtime Verification

## 1. Finding Recap

AUD-011 began as `RUNTIME_VERIFICATION_REQUIRED` because repository evidence
could not establish that deployed Kite reconciliation, execution blocking, and
recovery were operating against a real account. Read-only production evidence
on 2026-09-18 found one eligible live portfolio, immutable reconciliation
history, a real holdings/funds mismatch sequence, a later funds-only mismatch,
and a final green recovery. No broker order or production accounting state was
changed during this audit.

The final disposition remains `RUNTIME_VERIFICATION_REQUIRED`: the historical
mismatch and recovery path are proven, but the current Kite access token is
expired, so a fresh live snapshot and an order-safe deployed ExecutionGate
probe were not performed.

## 2. Frozen Contract

FEAT-040 defines reconciliation as read-only evidence and safety state. Kite
holdings/funds are compared with a point-in-time StoX portfolio snapshot;
reconciliation never creates adjustment transactions or silently changes
StoX accounting. Holdings and funds are independent axes. A supported holdings
mismatch blocks new Semi-Automatic/Automatic broker submissions; a funds-only
mismatch requires attention without setting that holdings block. A failed or
stale run preserves the last successful result and does not itself block.

A later successful matching reconciliation clears the holdings block and
resolves the deduplicated Action-required condition.

## 3. Production Broker / Portfolio Context

The production database contained three live portfolios for one sanitized
Investor: one `semi_automatic` portfolio and two `manual` portfolios. There
was one eligible live portfolio and no violation of the one-live-execution
portfolio invariant. The current portfolio is unblocked and fully reconciled.

The production Kite connection is present, but its access token expired on
2026-09-17 06:00 UTC. Its broker identifier and credentials are intentionally
not recorded here. Consequently, no fresh manual reconciliation was invoked.

## 4. Reconciliation Architecture

The deployed path is:

```text
portfolio:reconcile / POST /api/reconciliation
-> PortfolioReconciliationService::run()
-> Cache::lock('portfolio-reconciliation:{profile}', 60)
-> BrokerGateway::portfolioSnapshot()
-> point-in-time StoX holdings and cash snapshot
-> immutable PortfolioReconciliationRun
-> PortfolioProfile status/block fields
-> NotificationPublisher condition
-> ExecutionGate / LiveBrokerExecutionService
```

`KiteBrokerGateway::portfolioSnapshot()` uses read-only holdings, positions,
and equity-margin reads. The service compares the union of broker and StoX
instrument identities, supports ISIN matching, and limits blocking comparison
to active NSE-supported stocks. Unsupported broker instruments are retained as
informational evidence.

## 5. Current Reconciliation State

Production currently reports:

| Field | Result |
| --- | --- |
| Eligible live portfolios | 1 |
| Holdings status | `reconciled` |
| Funds status | `reconciled` |
| Overall status | `reconciled` |
| Execution blocked by reconciliation | `false` |
| Last successful reconciliation | 2026-09-16 16:16:19 UTC |
| Last failure | Broker session missing/expired |

The last failure is retained independently of the successful status. The
latest scheduled attempts on 2026-09-17 and 2026-09-18 were `sync_failed` with
unknown comparison axes; they did not overwrite the prior green status or set
the execution block.

## 6. Immutable Run History

Production contains 84 reconciliation runs. `PortfolioReconciliationRun`
rejects updates and deletes after creation, preserving trigger, timestamps,
status axes, snapshots, tolerances, discrepancies, and unsupported-instrument
evidence. The model-level immutability behavior is also covered by the
foundation suite.

The production history includes activation, manual, scheduled, and post-trade
triggers. A later run does not rewrite an earlier broker or StoX snapshot.

## 7. Holdings Comparison

The service compares the union of instruments on both sides, treating a
missing side as quantity zero. Quantity equality is exact after normalization;
comparable cost is checked against the configured holding-cost tolerance.
Production evidence contains real completed mismatch runs followed by a
matching holdings result. The production account's historical mismatch runs
were not manufactured for this audit.

## 8. Funds Comparison

Funds compare Kite current cash with StoX logical cash using the configured
global funds tolerance. The current repository defaults are ₹5 holding-cost
tolerance and ₹1 funds tolerance; the persisted run records retain the actual
tolerances used for each comparison.

Production run 81 demonstrates the independent-axis behavior: holdings were
`reconciled`, funds were `mismatch`, and overall status was
`attention_required`. The profile was therefore not holdings-blocked by that
funds-only result. No broker margin or unrelated collateral values are used as
the StoX funds comparison.

## 9. Execution Block Integration

`recordSuccessful()` sets `execution_blocked_by_reconciliation` only when
holdings status is `mismatch`. A fully matching later run clears it; a
funds-only mismatch does not set it. `recordFailure()` records a separate
`sync_failed` run and changes only `last_reconciliation_failure`, preserving
the prior status, timestamp, and block.

The static ExecutionGate and live execution suites prove that the final broker
submission path rejects `RECONCILIATION_HOLDINGS_MISMATCH` before the broker
gateway is called. A fresh deployed non-ordering gate probe was not run because
the current Kite connection is expired and no production order path was
invoked.

## 10. Mismatch Evidence / Drill

The production historical sequence supplies the safe mismatch drill:

```text
16 Sep 2026 15:59 UTC  holdings mismatch + funds mismatch
16 Sep 2026 16:13 UTC  holdings reconciled + funds mismatch
16 Sep 2026 16:16 UTC  holdings reconciled + funds reconciled
```

Runs 76-80 were manual attention-required runs with both axes mismatched. Run
81 proves a funds-only mismatch. Run 82 is the subsequent green run. The
associated production condition had seven occurrences and is now `resolved`,
with `resolved_at` matching the green recovery run. This proves real condition
deduplication and later resolution without creating a new discrepancy.

No current production UI/API action was used to clear the block manually.

## 11. Recovery Evidence

The final matching run cleared the profile's reconciliation block and resolved
the reconciliation condition. The sequence is consistent with the service
contract: StoX edits alone do not clear the block; a fresh successful matching
run does. The later scheduled provider failures did not undo or replace this
successful result.

The current profile state is therefore green and unblocked, but the audit does
not claim a new live recovery run after the expired token.

## 12. Notification Condition Lifecycle

The condition key is `portfolio:reconciliation:{profile_id}`. A mismatch
publishes or updates one Action-required condition containing the latest run
context; repeated mismatches increment the same source rather than creating
independent condition identities. A fully reconciled run resolves it. The
production condition history reports seven occurrences and a resolved state.

Generic notification delivery semantics remain owned by AUD-006. This audit
verifies only reconciliation condition creation, deduplication, and resolution.

## 13. Failed / Stale Run Semantics

Production scheduled failures are stored as `sync_failed` with unknown
holdings/funds/overall values. They preserve the last successful timestamp,
green status, and unblocked state. The latest production failure therefore did
not fabricate a mismatch or clear valid historical evidence.

The code also retains the provider error in `last_reconciliation_failure`.
This matches FEAT-040's distinction between stale/failed reconciliation and a
confirmed holdings mismatch. No stale-run defect was found.

## 14. Trigger Verification

The deployed scheduler registers `portfolio:reconcile` every five minutes in
the business timezone, with a post-close delay, TradingCalendar session gate,
and no-overlap window. The command skips non-equity sessions and prevents a
second scheduled run on the same portfolio/date.

Production history proves activation, manual, scheduled, and post-trade
triggers exist. Repository tests cover immediate activation reconciliation,
after-close scheduling, post-trade reconciliation, failure retention, and
manual API reachability. No fresh manual run was attempted because Kite access
was expired.

## 15. Concurrency / Ordering

`Cache::lock('portfolio-reconciliation:{profile}', 60)` rejects concurrent
runs for one portfolio. The scheduled command uses a per-day completed-run
check and the scheduler uses `withoutOverlapping(10)`. The controller returns
HTTP 409 for an active reconciliation. Foundation and execution tests cover
duplicate scheduled triggers and the active-run behavior.

`PortfolioReconciliationRun` immutability prevents older completion from
rewriting a newer run record. A deployed race drill was not run.

## 16. Mode Blackout

The execution-mode service statically enforces the FEAT-040 market blackout
using the configured business timezone and calendar. The focused execution
suite covers a representative blackout rejection and preserves Manual mode.
No production mode was changed for this audit.

## 17. UI / Operator Visibility

The current API exposes holdings, funds, overall status, execution-blocked
state, last successful timestamp, last failure, and the scoped run history.
Run detail exposes immutable evidence. The reconciliation path has no
Apply-Kite-values correction workflow; it remains diagnostic and safety-only.

Browser geometry and a fresh authenticated production API walkthrough were not
performed. API/controller behavior and scoped authorization are covered by the
repository feature tests.

## 18. Production Logs

Read-only production database evidence shows repeated scheduled Kite session
failures on 2026-09-15 through 2026-09-18, plus the successful manual mismatch
and recovery sequence on 2026-09-16. No broker credentials, account numbers,
full snapshots, or user payloads were copied into this document.

The expired current token is an operational provider-readiness condition, not
evidence that reconciliation mutates state or mishandles mismatch recovery.

## 19. Gap Register

| ID | Finding | Classification | Severity | Evidence |
| --- | --- | --- | --- | --- |
| REC-001 | Fresh Kite snapshot is unavailable because the deployed token is expired | `RUNTIME_VERIFICATION_REQUIRED` | High | Production connection metadata and recent `sync_failed` runs |
| REC-002 | A new deployed, order-safe ExecutionGate rejection probe was not run | `RUNTIME_VERIFICATION_REQUIRED` | High | Static final-gate tests pass; no production order path used |
| REC-003 | Historical mismatch -> funds-only -> fully reconciled recovery is present and condition resolved | `IMPLEMENTED` | High | Production runs 76-82 and resolved condition |
| REC-004 | Read-only comparison, independent axes, unsupported-instrument handling, and no-accounting-mutation rules | `IMPLEMENTED` | High | Service implementation and 54 focused feature tests |
| REC-005 | Concurrent-run lock, scheduled deduplication, immutable run records, and mode blackout | `IMPLEMENTED` | Medium | Service/command implementation and focused tests |
| REC-006 | Fresh browser/API operator walkthrough and deployed concurrency drill | `RUNTIME_VERIFICATION_REQUIRED` | Low | Repository API/tests exist; browser/race probe not performed |

No confirmed Critical or High behavioral defect was found. REC-001 and
REC-002 are evidence boundaries created by safe production constraints, not
claims that the implementation is unsafe.

## 20. Cross-Audit Evidence

- AUD-009: production scheduler and worker foundation is available; the
  reconciliation schedule is registered and has produced scheduled runs.
- AUD-010: `TradingCalendar` and IST session behavior are available to the
  reconciliation command; non-session days are skipped before portfolio work.
- AUD-015: static final broker-submission gate rejects a reconciliation
  holdings block without invoking the broker.
- AUD-006: reconciliation condition publication uses the current notification
  source/occurrence model; generic delivery remains owned by AUD-006.

## 21. Final AUD-011 Assessment

**Disposition: `RUNTIME_VERIFICATION_REQUIRED` (High severity, High confidence).**

Production proves one eligible live portfolio, read-only reconciliation run
history, real holdings/funds mismatch detection, independent funds-only
semantics, persisted recovery to green, deduplicated Action-required condition
resolution, scheduled failure retention, and no observed accounting mutation.

The audit remains runtime-verification-required because the current Kite token
is expired and therefore a fresh broker snapshot plus a deployed non-ordering
ExecutionGate probe could not be safely completed. No broker order was placed.

## 22. Open Questions

- After Kite credentials are renewed through the normal operational process,
  should one read-only manual reconciliation and one non-ordering gate probe be
  captured to close REC-001/REC-002?
- Is a persisted, operator-visible reconciliation run log sufficient, or is a
  separate provider-health metric desired beyond the current run history and
  `last_reconciliation_failure`?
