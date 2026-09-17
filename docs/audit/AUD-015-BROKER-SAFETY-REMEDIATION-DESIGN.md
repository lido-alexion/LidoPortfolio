# AUD-015 Broker Safety Remediation Design

## 1. Finding Recap

AUD-015 found that the regular live-order admission paths call
`ExecutionGate::assertCanSubmitBroker()` before a batch or cycle performs
matching, quote/funds resolution, sizing, and local order preparation. The
eventual external call is made later by
`LiveBrokerExecutionService::submitOne()` through
`BrokerGateway::placeOrder()`, without repeating the full current authority
and safety checks.

This is a static time-of-check/time-of-use concern, not evidence that an
unsafe order has reached Kite. It conflicts with the accepted contract in
`docs/current/execution-broker-safety.md`, "Execution Readiness Gates" and
"Critical Safety Invariants", which requires current authority and safety
state immediately before broker submission.

## 2. Current Submission Paths

| Path | Entry point | Final broker call | Gate before admission | Final gate | Notes |
| --- | --- | --- | --- | --- | --- |
| Semi-automatic regular order | `POST /api/v1/execution/submit-selected` -> `ExecutionController::submitSelected()` -> `LiveBrokerExecutionService::submitSelected()` | `LiveBrokerExecutionService::submitOne()` -> `BrokerGateway::placeOrder()` | `ExecutionGate::assertCanSubmitBroker(..., TRIGGER_SEMI_AUTOMATIC, TOTP/recovery proof)` | No complete final gate | The primary AUD-015 path. |
| Scheduled automatic regular order | `SubmitAutomaticBrokerOrdersCommand` -> `submitAutomaticForUser()` -> `submitInvestorCycle()` | `submitOne()` -> `placeOrder()` | `assertCanSubmitBroker(..., TRIGGER_AUTOMATIC)` per eligible profile | No complete final gate | Batch matching and residual execution occur after admission. |
| Direct automatic profile execution | `LiveBrokerExecutionService::submitAutomaticForProfile()` | `submitOne()` -> `placeOrder()` | `assertCanSubmitBroker(..., TRIGGER_AUTOMATIC)` | No complete final gate | Used by application/test orchestration. |
| Insufficient-funds retry | Recursive `submitOne(..., quantityOverride, retry + 1)` after `BROKER_INSUFFICIENT_FUNDS` | Another `placeOrder()` | Initial admission only; current-state checks repeat as part of `submitOne()` | No complete final gate | Current retry limit is two reduced retries after the initial attempt. Each retry is a new external submission. |
| Manual order execution | `ExecutionController::ordersExecute()` -> `ExecutionEngine::executeOrder()` | None | N/A | N/A | This is local execution/accounting, not a live broker-placement path. |
| Explicit protection / GTT placement | `POST /api/v1/protections` -> `PositionProtectionService::place()` | `KiteBrokerGateway::placeGtt()` or `modifyGtt()` | `ExecutionGate::assertCanSubmitBroker(..., TRIGGER_SEMI_AUTOMATIC, proof)` | No final gate observed | A real external broker side effect, but separate from normal market-order submission. |
| Automatic protection after BUY fill | `LiveBrokerExecutionService::maybeAutoProtectAfterBuy()` -> `PositionProtectionService::afterAutomaticBuyFill()` | `placeGtt()` or `modifyGtt()` | Automatic admission gate before protection workflow | No final gate observed | Separate protection policy/lifecycle boundary. |
| Protection synchronization and maintenance | Fill, corporate-action, adoption, and reconciliation paths -> `PositionProtectionService::synchronize()` | May call `placeGtt()` or `modifyGtt()` | Not uniformly gated | No final gate observed | Whether emergency halt/readiness should block protective-order maintenance requires a separate product decision. |

`KiteBrokerGateway::placeOrder()` is the adapter implementation of the
regular-order call, and `FakeBrokerGateway::placeOrder()` is test support.
No other production regular-order placement call was found.

`cancelGtt()` is an external cancellation action, not new order submission;
it is intentionally outside this finding's final-new-submission boundary.

## 3. Current Gate Anatomy

`ExecutionGate::assertCanSubmitBroker()` is implemented in
`app/app/Engines/Execution/ExecutionGate.php` and currently checks the
following, in this order.

| Gate check | Current source | Time-sensitive? | Side effects? | Must revalidate before regular broker submission? |
| --- | --- | --- | --- | --- |
| User owns portfolio profile | `assertPortfolioOwner($user, $profile)` | Usually stable, but profile/user association is an authority boundary | No | Yes, using fresh ownership state where practical. |
| Profile is not paper | `$profile->isPaper()` | Can change through profile configuration | No | Yes. |
| Reconciliation has not blocked execution | `$profile->execution_blocked_by_reconciliation` | Yes; a reconciliation run can set it after admission | No | Yes. |
| Emergency halt is not active | `($user->fresh() ?? $user)->executionIsHalted()` | Yes; an operator can halt execution at any time | Fresh user read only | Yes. |
| Automated execution entitlement exists | `assertEntitled($user)` | Yes; an Admin can revoke it | No | Yes. |
| Profile execution mode permits trigger | `$profile->executionMode()` and trigger comparison | Yes, though mode changes have separate constraints | No | Yes. |
| TOTP is active | `$user->totpIsActive()` | Yes; it can be disabled or reset | No | Yes. |
| Semi-automatic sensitive-action proof is recent and valid | `TotpService::assertRecentVerification()` | The proof is security-sensitive, but is established at admission | Yes. Verification advances the TOTP counter; recovery-code verification consumes and persists a recovery code and writes an audit event. | No repeat of the challenge/proof. The final check must preserve the admission proof rather than consume it again. |
| Broker connection is usable | `BrokerConnectionService::status($user)` | Yes; connection/session can be removed or expire | Read-only database/service status lookup | Yes. |

The gate does not itself validate several order-specific conditions. Those
are currently owned by `LiveBrokerExecutionService::submitOne()` and related
services and must remain there rather than be pushed into a generic
connection gateway:

| Order-specific condition | Current anchor | Final-time treatment |
| --- | --- | --- |
| Recommendation lifecycle, lending/capital readiness, and pending-execution transition | `LiveBrokerExecutionService::ensurePendingExecution()` and recommendation/lending services | Re-read/revalidate for the concrete recommendation before placement. |
| Stock active/tradable and strategy/profile/enabled state | `passesCurrentStateRevalidation()` | Revalidate against fresh records before placement. |
| Execution lifetime/session window | `RecommendationExecutionLifetime::isExecutionOpportunity()` | Recheck at final time because a cutoff can pass during processing. |
| Quote/reference-price policy and quantity | `quantityFor()` | Keep existing resolution close to placement; do not introduce an unconditional duplicate provider call without a separately approved policy decision. |
| Current BUY funds and bounded resize | `quantityFor()` and MarginException retry branch | Resolve before each attempt; retry must also pass the final safety validation. |

### Side-effect conclusion

The existing public `assertCanSubmitBroker()` cannot safely be called again
unchanged from `submitOne()`. A valid semi-automatic request supplies one
TOTP or recovery proof at admission. Repeating the existing call would
attempt another verification: the same TOTP counter can be rejected and a
recovery code can be consumed twice. It would therefore create false
failures and duplicate security audit events.

Aside from that proof validation, the gate is predominantly validation and
readiness lookup. Its reusable policy should be extracted into a pure,
fresh-state final-validation method; the admission method should retain the
semi-automatic proof challenge exactly once.

## 4. Current Race Window

The regular order flow is materially:

```text
admission
  -> ExecutionGate::assertCanSubmitBroker()
  -> batch/cycle candidate selection
  -> internal matching
  -> submitOne()
       -> recommendation/stock/strategy checks
       -> execution-window check
       -> lending/pending-execution handling
       -> in-flight reconciliation
       -> quote, funds, and quantity resolution
       -> local decision/order preparation
       -> BrokerGateway::placeOrder()
```

The unguarded period begins after admission and includes matching and
per-order preparation. In particular, a halt, entitlement revocation,
reconciliation block, broker disconnect, mode change, or session-window
closure can become visible before a later `placeOrder()` without the current
complete gate being invoked again.

`submitOne()` does already revalidate some recommendation, stock, and
strategy state. That partial validation does not cover all authority, halt,
reconciliation, mode, TOTP-enabled, or broker-readiness conditions.

## 5. Remediation Options Considered

### Option A: Call `assertCanSubmitBroker()` again immediately before `placeOrder()`

This is the smallest textual change but is unsafe. Semi-automatic TOTP
verification is deliberately stateful: it advances the verification counter
or consumes a recovery code. Repeating it can reject a valid order and write
duplicate audit evidence. It is not recommended.

### Option B: Extract a pure fresh-state final gate and call it from `submitOne()`

Extract the non-consuming policy checks into a method such as
`ExecutionGate::assertCurrentBrokerSubmissionState($userId, $profileId,
$trigger)`. It must load fresh User and PortfolioProfile state, perform the
current non-TOTP-proof checks, and return or throw without persistence or
provider placement. Keep `assertCanSubmitBroker()` as the admission wrapper:
it calls the shared pure policy and then performs the one-time semi-automatic
TOTP/recovery validation.

`LiveBrokerExecutionService` retains its order-specific revalidation and
calls the pure method after slow preparation but immediately before the
regular-order local submission sequence and external call.

Advantages: reuses policy without duplicating business rules; avoids TOTP
consumption; keeps recommendation and order policy in its owning service;
supports every retry; has focused tests.

Disadvantages: requires careful method extraction and fresh-record handling.
The check cannot make an external broker call and a concurrent halt atomic
without a broader locking/state-machine design.

### Option C: Enforce the domain gate inside `BrokerGateway`

This would catch every regular gateway caller, but the gateway receives a
broker request rather than the full recommendation, profile, trigger,
admission-proof, and lifecycle context. It would either duplicate/couple
execution domain policy to an integration adapter or require a substantially
broader gateway interface. It also does not naturally cover decision-state
semantics. It is not the smallest safe change.

### Option D: Hold database locks through final validation and broker HTTP I/O

This could reduce some local-state races, but it risks long transactions,
lock contention, deadlocks, and incorrect rollback behavior after an
irreversible external call. The current flow does not hold a transaction over
`placeOrder()`, and this design should preserve that boundary. It is not
recommended for AUD-015.

## 6. Recommended Design

### Design choice

Use Option B: split reusable, non-consuming policy from the admission-only
semi-automatic proof challenge, then invoke the pure policy at the final
regular broker-submission boundary.

### Intended code locations for a later implementation

1. In `app/app/Engines/Execution/ExecutionGate.php`, extract the current
   non-consuming checks into a pure method that accepts identifiers or
   refreshes by identifier. It must fresh-load the user and portfolio profile
   before evaluating ownership, paper/live status, reconciliation block,
   halt, entitlement, execution mode, TOTP-enabled status, and broker
   readiness.
2. Keep `assertCanSubmitBroker()` as the admission API. It calls the shared
   pure policy and, for `TRIGGER_SEMI_AUTOMATIC`, performs the one-time
   `TotpService::assertRecentVerification()` afterward.
3. In `app/app/Engines/Execution/LiveBrokerExecutionService.php`, add a
   final regular-order revalidation after request/quantity preparation and
   duplicate/in-flight checks, but before `ExecutionDecision` and
   `TradingOrder` creation and before `BrokerGateway::placeOrder()`.
4. The live service's final revalidation should first refresh and validate
   the recommendation, stock, strategy, profile, and relevant lifecycle/
   capital state, recheck `isExecutionOpportunity()`, then invoke the pure
   gate with the originating trigger.

Placing the check before the local submitted-decision/order records avoids
creating a permanently pending, broker-unknown order when the final gate
blocks a call that never left StoX. There must be no slow provider operation
or broad business workflow between that check and `placeOrder()`; only the
existing short local idempotency/order preparation remains.

On a final gate rejection, record an `ExecutionDecision` with the existing
blocked outcome and structured error code, return a blocked result, and do
not create a `TradingOrder` or call the gateway. Keep the recommendation
pending unless existing lifecycle logic independently cancels or expires it.
This matches the existing distinction between a safety block and a broker
rejection.

The design deliberately does not add a generic gateway check and does not
make final validation a database transaction around broker I/O.

### Residual race boundary

A fresh final read prevents safety changes committed before that read. No
ordinary application-level check can also prevent a halt that commits after
the final read but before the external HTTP request without a coordinated
cross-process state machine or holding a lock over provider I/O. This design
closes the confirmed practical batch/cycle window while avoiding unsafe
external I/O under database locks. A requirement for stronger linearizable
halt semantics is an open architectural decision, not something to imply
silently through this small patch.

## 7. Retry Behavior

The first `placeOrder()` and each bounded insufficient-funds retry are
separate external broker-submission attempts. The pure final validation must
run on every invocation of `submitOne()`, including recursive calls after a
`BROKER_INSUFFICIENT_FUNDS` response.

This preserves the current retry policy: initial quantity, then at most two
whole-share reductions using the current 95-percent sizing behavior. It does
not repeat the semi-automatic TOTP/recovery proof. If a halt, entitlement
revocation, reconciliation block, readiness failure, or other final safety
failure occurs after attempt one, attempt two must not call `placeOrder()`.

## 8. Internal Matching Boundary

`InternalRecommendationMatcher::match()` is invoked before residual regular
broker submissions. It creates paired internal-transfer accounting evidence
inside its own transaction and does not call a broker gateway.

The final broker gate belongs after matching, per residual order, immediately
before the residual external placement. A broker session failure or emergency
halt must stop an unplaced residual order; it should not retrospectively
invalidate an already completed internal transfer merely because broker
readiness changed. Existing admission and internal-matching policy continue
to govern whether matching starts.

No broker-specific final gate should be inserted inside the matcher unless a
separate accepted contract explicitly makes internal accounting transfers
dependent on live broker readiness.

## 9. State And Error Semantics

The following is the smallest-safe proposed behavior, aligned with existing
gate exceptions and current `submitOne()` treatment where it already exists.
It should be confirmed during implementation against the precise result DTO
and batch-summary behavior.

| Final rejection reason | Broker call | Proposed local evidence | Recommendation effect | Batch effect |
| --- | --- | --- | --- | --- |
| Emergency halt | None | Blocked execution decision with halt code; structured log | Remains pending | Later orders are blocked; already submitted orders are not rolled back. |
| Entitlement revoked, wrong mode, paper profile, or TOTP no longer enabled | None | Blocked decision with gate code | Remains pending | Block/skip this order; no new broker submission. |
| Reconciliation block | None | Blocked decision with reconciliation code | Remains pending | Block/skip this order; reconciliation process remains owner of recovery. |
| Broker disconnected/session no longer usable | None | Blocked decision with readiness code | Remains pending | Block/skip this order; do not fabricate a broker rejection. |
| Execution window is no longer an opportunity | None | Existing opportunity/lifetime result rather than broker-order evidence | Remains pending until ordinary lifecycle expiry handling | Skip/defer; do not place after cutoff. |
| Recommendation cancelled, superseded, already fulfilled, or no longer capital/lending eligible | None | Existing lifecycle/capital error or blocked decision | Existing lifecycle owner determines cancellation/release; do not add a new generic transition | Skip/defer; no broker call. |
| Stock inactive or strategy invalid/disabled | None | Existing current-state revalidation evidence | Preserve current cancellation/reservation-release behavior | Skip this order; no broker call. |

The current batch summaries principally distinguish submitted from skipped
rows. Whether final-gate blocks deserve a separately surfaced batch count,
notification, or attention state is an open presentation/observability
decision; it is not required to ensure that no broker call occurs.

## 10. Concurrency And Fresh-State Requirements

The final policy must not rely on the User and PortfolioProfile instances
loaded at batch admission. It should reload the relevant rows by identifier
immediately before evaluation. The order-specific revalidation should also
use fresh recommendation, stock, and strategy records rather than cached
relations.

The intended final check occurs outside a database transaction and outside
the internal-matching transaction. No database lock should be held across
quote/funds provider calls or `BrokerGateway::placeOrder()`.

Existing local idempotency remains important: `TradingOrder.submission_key`
and in-flight/terminal reconciliation checks prevent ordinary duplicate
placement paths. The final gate must be re-run for a retry after an order
attempt changes local state, rather than assuming the first attempt's
admission is still authoritative.

Potential implementation review points:

- Ensure a fresh halt or entitlement update is observed even when a long-lived
  service holds an older Eloquent model.
- Avoid moving local order creation before a final failure without defining a
  non-broker-submitted order state; otherwise a broker-unknown pending row can
  become permanently duplicate-blocking.
- Preserve partial-batch durability: a successful earlier order remains
  submitted when a later order is blocked.
- Do not treat a provider-level session rejection after the final local check
  as proof that the local readiness gate was wrong; retain current broker
  error/reconciliation behavior.

## 11. Test Plan

The primary home should be
`app/tests/Feature/Execution/LiveExecutionFeatureTest.php`; an automatic
multi-order case may fit better in
`app/tests/Feature/V4Feat010UnattendedOpsTest.php`. Tests should use a
controlled fake/hook that changes persisted state after admission and before
the proposed final check, not merely a fake that changes state after the
gateway has already observed a placement.

| Test | Arrangement | Required assertion |
| --- | --- | --- |
| Halt after admission | Admission succeeds; a quote/funds test seam activates emergency halt before final check | Zero `placeOrder()` calls; blocked result/decision; recommendation remains pending. |
| Entitlement revoked after admission | Automatic cycle admitted; seam revokes the user's automated entitlement before final check | Zero broker calls for the affected order; current gate code is retained. |
| Reconciliation becomes blocking | Admission succeeds; seam sets profile reconciliation block before final check | Zero broker calls; no fabricated broker rejection. |
| Broker readiness changes | Admission succeeds; seam disconnects/expires connection before final check | Zero broker calls and fail-closed readiness result. |
| Window closes during preparation | Clock begins inside allowed opportunity and advances past the current cutoff before final check | Zero broker calls; existing opportunity/lifetime semantics are used. |
| Margin retry safety | First `placeOrder()` returns `BROKER_INSUFFICIENT_FUNDS`; state changes before recursive retry | Exactly one broker placement; retry is blocked; existing bounded-resize policy is otherwise unchanged. |
| Valid semi-automatic order | Valid one-time TOTP proof and stable state | One broker placement; no second TOTP/recovery consumption or false failure. |
| Multi-order automatic batch | First order succeeds; test fake changes halt or entitlement before next order's final check | First order remains correctly persisted; second order has zero placement; batch does not roll back the first. |
| Current-state invalidation | Recommendation is cancelled/superseded or stock/strategy becomes invalid during preparation | No placement and existing lifecycle semantics remain intact. |

Likely test-support change for the later patch: extend the fake broker/quote
or funds collaborator with a narrowly scoped callback, or bind a dedicated
test double, so the state transition occurs after admission but before final
validation. A callback only inside `placeOrder()` is too late to prove this
guard because it fires after the proposed check.

Existing tests to retain and review:

- `LiveExecutionFeatureTest` currently covers admission-time emergency halt,
  execution modes, entitlement, TOTP, current-state validation, strict quote
  handling, and the existing two-retry insufficient-funds behavior.
- `V4Feat010UnattendedOpsTest` covers unattended scheduling, account
  isolation, and internal matching/batch behavior.
- `V5PortfolioReconciliationFoundationTest` provides reconciliation-block
  evidence but not a state change between admission and placement.
- `AdvancedOrdersFeatureTest` covers protection/GTT behavior, which is a
  related but separately scoped external broker workflow.
- Kite readiness, personal-token, and emergency-halt tests cover component
  policies but do not currently prove the final submission boundary.

## 12. Regression Risks

- Repeating the consuming TOTP/recovery proof causes valid semi-automatic
  orders to fail or consumes recovery codes twice.
- A final check after local order creation can leave a broker-unknown pending
  order that blocks future work through idempotency checks.
- Incorrect lifetime revalidation could create false expiry near a market
  cutoff.
- A blocked later order must not roll back a successful earlier batch order.
- Reordering decision/order creation can affect existing assertions about
  decision history or failure rows.
- Holding row locks around provider I/O could reduce throughput and create
  transaction/deadlock failures.
- Repeating quote/funds provider calls unnecessarily could increase rate-limit
  exposure and introduce new failures.
- A broad gateway-level solution could accidentally apply normal-order policy
  to GTT maintenance or omit recommendation-specific policy.

## 13. Documentation Impact

The current contract already states the desired behavior: final broker
submission revalidation is required, approval is not submission, and
internal matching precedes residual broker work. No current product-document
change is required for this remediation design.

After implementation, the Phase 2 audit and its gap register may be updated
with implementation and test evidence. That is separate from this design.

## 14. Implementation Plan

1. Characterize the current admission gate and its consuming TOTP/recovery
   behavior with focused tests before refactoring.
2. Extract a pure, identifier/fresh-state broker-submission policy from
   `ExecutionGate`; leave the public admission method responsible for the
   one-time semi-automatic proof.
3. Add a final regular-order helper in `LiveBrokerExecutionService` that
   refreshes order-specific state, rechecks the execution opportunity, and
   invokes the pure policy after sizing/preparation and immediately before
   local submission records and `placeOrder()`.
4. On final rejection, emit the existing blocked decision/result path without
   creating a broker order, while preserving existing lifecycle ownership for
   cancellation/expiry/release.
5. Ensure recursive MarginException retries enter the same helper and retain
   their current count and 95-percent whole-share behavior.
6. Add the race-oriented tests in the proposed execution test files, then run
   the relevant execution, reconciliation, protection, and unattended-cycle
   suites.
7. Review GTT placement/maintenance as a separately scoped follow-up; do not
   silently broaden this regular-order patch.

## 15. Open Questions

1. Does the accepted emergency-halt/readiness policy intentionally apply to
   creating or modifying protective GTT orders after a filled position, or is
   protection maintenance allowed during a halt? The current regular-order
   AUD-015 fix should not decide this implicitly.
2. Should a final-gate block be exposed as a distinct batch result/attention
   count rather than the current broad skipped category, and which conditions
   warrant a user notification?
3. Does the product require stronger linearizable halt semantics than a
   fresh check immediately before HTTP placement? If so, a coordinated
   submission reservation/lock design needs separate analysis.
4. Must quote and broker-funds values be fetched again after final authority
   validation, or is the current per-attempt fetch immediately before final
   preparation sufficient? A second provider request should not be added
   without an explicit freshness/rate-limit decision.
5. For a recommendation invalidated after admission, should the final helper
   reuse the present cancellation/release behavior exactly or expose a more
   explicit "blocked-before-submit" decision code? Existing lifecycle
   semantics should govern this choice.

## Evidence Anchors

- `docs/audit/V1-V7-IMPLEMENTATION-AUDIT.md`, `AUD-015`.
- `docs/current/execution-broker-safety.md`, "Execution Readiness Gates",
  "Broker Order Lifecycle", "Emergency Halt And Recovery", and "Critical
  Safety Invariants".
- `app/app/Engines/Execution/ExecutionGate.php`.
- `app/app/Engines/Execution/LiveBrokerExecutionService.php`.
- `app/app/Engines/Execution/InternalRecommendationMatcher.php`.
- `app/app/Services/Protection/PositionProtectionService.php`.
- `app/app/Services/Auth/TotpService.php`.
- `app/tests/Feature/Execution/LiveExecutionFeatureTest.php`.
- `app/tests/Feature/V4Feat010UnattendedOpsTest.php`.
