# AUD-011 - Kite Reconciliation / Holdings Block / Recovery Runtime Verification

> **Closure update — 2026-09-26:** Sections that describe the original 2026-09-18 evidence boundary are retained as chronology. Sections 21–22 and this notice are authoritative for the final disposition after reconciliation run 93, the controlled recommendation-786 exercise, the production Emergency Halt probe, recovery, remediation and deployment verification.

## 1. Finding Recap

AUD-011 began as `RUNTIME_VERIFICATION_REQUIRED` because repository evidence
could not establish that deployed Kite reconciliation, execution blocking, and
recovery were operating against a real account. Read-only production evidence
on 2026-09-18 found one eligible live portfolio, immutable reconciliation
history, a real holdings/funds mismatch sequence, a later funds-only mismatch,
and a final green recovery. No broker order or production accounting state was
changed during this audit.

The original disposition was `RUNTIME_VERIFICATION_REQUIRED`. It is now
`IMPLEMENTED`: later production evidence added successful reconciliation run
93, one real Semi-Automatic submission/cancellation lifecycle, an order-safe
Emergency Halt rejection before order creation, explicit recovery, and
deployment verification of the corrected recovery UX. Disruptive emergency
disconnect/cleanup paths are accepted from focused automated tests rather than
a live destructive exercise.

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

Production contained 84 reconciliation runs at the original inspection and later recorded successful run 93. `PortfolioReconciliationRun`
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
gateway is called. Later production evidence exercised the same deployed final
safety boundary under Emergency Halt: one request returned HTTP 423
`EXECUTION_EMERGENCY_HALT` before batch, decision, TradingOrder or broker
placement, with all financial and holding counts unchanged.

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

Later manual run 93 completed with holdings and funds reconciled and no
discrepancies outside tolerance. The controlled safety probe then activated
Emergency Halt, proved a blocked submission with zero side effects, and
recovered through the normal authenticated TOTP path. The final account
execution state is `normal`.

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
| REC-001 | Fresh reconciliation evidence was originally unavailable because the deployed token was expired | `IMPLEMENTED` | High | Successful production run 93 reconciled holdings and funds; later daily-session expiry is operational readiness, not an audit defect |
| REC-002 | A deployed order-safe ExecutionGate rejection probe was originally absent | `IMPLEMENTED` | High | One production request returned HTTP 423 `EXECUTION_EMERGENCY_HALT`; before/after counts prove zero order or financial side effects |
| REC-003 | Historical mismatch -> funds-only -> fully reconciled recovery is present and condition resolved | `IMPLEMENTED` | High | Production runs 76-82 and resolved condition |
| REC-004 | Read-only comparison, independent axes, unsupported-instrument handling, and no-accounting-mutation rules | `IMPLEMENTED` | High | Service implementation and 54 focused feature tests |
| REC-005 | Concurrent-run lock, scheduled deduplication, immutable run records, and mode blackout | `IMPLEMENTED` | Medium | Service/command implementation and focused tests |
| REC-006 | Fresh browser/API operator walkthrough and deployed concurrency drill | `RUNTIME_VERIFICATION_REQUIRED` | Low | Repository API/tests exist; browser/race probe not performed |

No confirmed Critical or High behavioral defect was found. REC-001 and
REC-002 are closed by the later bounded production evidence. REC-006 remains
normal low-risk operational assurance and does not block the requirement
verdict.

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

**Disposition: `IMPLEMENTED` (High confidence).**

Combined evidence now covers:

- real holdings/funds mismatch history, independent funds-only semantics,
  immutable evidence, deduplicated condition handling and recovery to green;
- successful production reconciliation run 93;
- recommendation 786 submitted once through the real Semi-Automatic path,
  producing exactly one new StoX order and one Kite order for that retry;
- confirmed zero-fill cancellation with recommendation status and ₹0.2800
  reservation retained under the frozen retry policy;
- Emergency Halt activation followed by exactly one request rejected with
  HTTP 423 `EXECUTION_EMERGENCY_HALT` before any batch, decision, order,
  broker placement or financial side effect;
- explicit authenticated TOTP recovery to `normal`, durable safety events,
  corrected recovery payload semantics, clear Kite-versus-StoX credential
  terminology, and successful deployment verification at
  `568af86fb0bb10a180c321236b0a1d4c371e87b8`.

The production exercise intentionally did not invoke emergency Kite disconnect
or cancel-and-disconnect. Those disruptive branches are accepted from focused
automated tests, while the non-destructive production probes establish the
shared final gate and recovery behavior. A later expired daily Kite session is
an operational readiness condition, not grounds to reopen this audit.

## 22. Follow-up Boundaries

- Renew the Kite daily session through normal login before the next broker
  operation.
- Keep provider-session monitoring and browser/concurrency assurance as routine
  operations; they do not block V6-REQ-001 closure.
- Do not run a destructive emergency disconnect/cleanup drill merely to
  duplicate focused automated-test evidence.
