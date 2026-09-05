# V5 FEAT-039 — Holiday-aware scheduled execution

| Field | Value |
|---|---|
| **Status** | **FROZEN / IMPLEMENTED** |
| **Frozen** | 2026-09-04 |
| **Implemented** | 2026-09-05 |
| **Scope** | Primary Recommendation execution for Semi-Automatic and Automatic portfolios |
| **Depends on** | V4-FEAT-037, V4-FEAT-038; V3 capital/lending and ownership; V4 live execution |
| **Related** | [`LidoPortfolio-V5-Wishlist.md`](LidoPortfolio-V5-Wishlist.md) · [`LidoPortfolio-V3-Specification.md`](LidoPortfolio-V3-Specification.md) |

## Problem

The shipped broker path submits eligible Recommendations immediately, per portfolio, using stored suggested quantity. It has idempotent submission, reconciliation and partial-fill recording, but no holiday-aware execution opportunity, two-trading-day target-seeking lifetime, Investor/Kite-account coordinator, cross-Strategy same-symbol netting, execution-time resizing, or internal-transfer accounting. Generic `expires_at` is wall-clock based and automatic Recommendation expiry is not scheduled.

V3 already supplies the upstream machinery: monetary target amount as source of truth; per-Strategy ownership; latest stored raw close for quantity derivation; Strategy capital allocation; own-capital partial funding; capital fill ordering; recalls; normal inter-Strategy lending; Recall Bridge Loans for recall fulfilment only; and funded/partially-funded/unfunded states. FEAT-039 reuses those rules and adds the missing reconciliation layer between capital-resolved intent and Kite.

`TradingCalendar` already treats weekends and active admin-defined global Trade Holidays as non-sessions. FEAT-038 remains responsible for the canonical exchange-holiday product and reliable official-list refresh; FEAT-039 consumes it.

## Frozen behaviour

### Recommendation intent and lifetime

- An actionable Recommendation is a live intent to move a Strategy from actual current state toward a monetary **target investment/exposure amount**. Displayed share quantity is derived, not authoritative.
- It makes any valid directional progress, down to one whole share, symmetrically for BUY/INCREASE and SELL/REDUCE/EXIT. It remains active after partial internal or external execution and is re-evaluated at later opportunities.
- Terminal states are **Completed**, **Expired** (fully or partially unfulfilled), **Superseded**, and **Cancelled** with an audited reason. Past fills/transfers are never unwound.
- A materially new Strategy target supersedes the old Recommendation and gets its own generation timestamp and lifetime. It begins from actual current Strategy ownership; it neither completes nor reverses the old Recommendation.
- The lifetime anchor is fixed at generation in IST. Generation before that day's effective execution cutoff on an eligible session has Day #0 that day; otherwise the first opportunity is Day #1. Day #1 and Day #2 are the first and second eligible trading sessions after the immutable anchor classification. Future dates follow TradingCalendar until each date begins in IST, after which FEAT-038 makes that date's state immutable.
- The current platform execution window applies immediately. On Day #2 its current cutoff is also expiry. Approval, reconnection, revalidation, funds/price changes or Calendar movement never reset or extend lifetime.
- Calendar movement is not arbitrary future scheduling. Intents wait only for the next eligible market opportunity; once open, an eligible intent is not delayed hoping for a future offset.

### Eligibility and revalidation

- Only independently execution-ready intents enter a cycle. Automatic requires current entitlement/mode; Semi-Automatic requires valid approval; Manual never enters.
- Current mode is authoritative for unsubmitted intent: Automatic→Semi requires approval; Semi→Automatic may proceed; either→Manual cancels it. Strategy disable/archive, stock inactivation or Strategy ownership reassignment also cancels it. Re-enabling does not resurrect it. Submitted broker orders stay under Order Lifecycle.
- Kite disconnection is a temporary blocker, not cancellation. Reconnection triggers all currently eligible pending intents for that Investor with fresh gates.
- Before each incremental execution, revalidate current Strategy ownership, target/direction, Strategy/risk rules, approval, capital resolution and broker readiness. A material Strategy-intent change requires fresh Semi-Automatic approval; resource-only quantity changes while target/direction remain materially the same preserve approval. Automatic may follow current valid intent. Fresh approval cannot extend lifetime.
- Only active/pending Recommendations expire. Earlier completion, supersession or cancellation suppresses expiry and its notification.
- The lifetime applies only to primary Recommendation execution. Protective/GTT orders after a fill retain the advanced-order lifecycle.

## Architecture

```text
Strategy target Recommendation
  -> V3 capital resolution
     (own funds -> recalls -> normal loan; Recall Bridge Loan only for recall)
  -> capital-resolved candidates
  -> Investor/Kite-account execution coordinator
     -> eligibility, window and current-state revalidation
     -> same-symbol internal matching across Strategies/portfolios
     -> committed logical ownership transfers
     -> residual external orders, SELL before BUY
     -> verified shared Kite funds and final whole-share resizing
  -> Kite -> fills/transfers -> remaining target gap
  -> repeat while active; complete or expire at Day #2 cutoff
```

The coordinator is user/Kite-account scoped because physical holdings and funds are shared. V5 has no portfolio-level broker-fund reservation. Strategy capital accounting stays isolated upstream; the coordinator does not rewrite V3 allocation, recall, lending, bridge, ranking or priority rules.

Persistence must distinguish target amount/snapshot, original displayed quantity, capital-resolved amount/quantity, remaining target gap, internal and external execution, provisional/final transfer valuation, attempt/blocker history, first/effective dates, expiry, approval validity and terminal fulfillment. The present one-order/one-Recommendation schema must be extended for net batches/internal crosses without losing attribution. Matching and ownership changes require transactional locking and idempotency. Ambiguous broker placement is never resubmitted until reconciled.

## Algorithms

### Trading-day derivation

1. Freeze the Day #0/Day #1 anchor at generation using IST, current cutoff and `TradingCalendar::isEquitySessionDate`.
2. Walk canonical NSE equity sessions for Day #1 and Day #2, skipping weekends and Trade Holidays.
3. Recompute future effective dates after permitted Calendar corrections; never rewrite the anchor or a date whose IST day has begun.
4. Admit attempts only inside the current window. At Day #2 cutoff atomically expire only the remaining gap.

### Target-seeking quantity

Before each attempt:

`actual Strategy position -> current valuation -> target amount -> remaining directional exposure gap -> V3 capital resolution -> internal match -> verified broker funds -> whole-share executable quantity`

V5 uses the latest stored raw close (normally previous-session close intraday) and existing capital/margin cushion. Live quotes are not added. Floor whole shares; bound sells by current ownership and buys by capital/risk/broker constraints. Zero shares remains pending; one share executes.

### Investor-level cycle and priority

1. Gather all independently ready intents for one Investor/Kite account.
2. Revalidate and group by listed instrument.
3. Match opposing same-symbol intents within this cycle only, oldest Recommendation first on each side, then stable ID.
4. Commit the full internal match independently of residual broker success.
5. Process residual SELLs before BUYs; oldest first, then stable ID.
6. Priority is evaluation order, not reservation; a blocked earlier intent cannot block a later valid one.
7. Never assume SELL proceeds. Only funds Kite currently reports usable may fund BUYs; refresh after fills as appropriate.

### Internal matching and valuation

- A SELL 50 plus B BUY 100 becomes an immediate A→B logical transfer of 50 and residual Kite BUY 50. A perfect 50/50 cross makes no Kite order and completes both intents.
- Internal transfers are final Strategy-accounting executions and are never rolled back for external rejection, cancellation, partial fill or expiry.
- They realize the seller's Strategy performance at transfer value and set the buyer's basis to that value; the seller's old basis is not carried into the buyer.
- Initially use previous trading day's close. If the associated residual net order gets any fill, finalize at its terminal WAVG actual fill price (including terminal partial fill). With zero fills or no residual order, restate to current-session official close after normal universe-price sync. Preserve provisional and final values and audit the restatement.
- Internal quantity creates no fictional broker/tax/exchange charges. Actual charges apply only to external quantity and are proportionally attributed where multiple Strategies contribute. Charges do not enter Strategy performance.

### External resizing and insufficient-funds retry

- Apply broker funds only to the residual net external BUY, never gross pre-net intents. Internal progress proceeds even if the residual is unfunded.
- Size from remaining monetary target using stored close, V3 capital result, existing cushion and currently verified shared Kite funds. Submit maximum valid whole shares and recompute remaining target after every fill/transfer.
- Only an unambiguously classifiable Kite insufficient-funds/margin rejection receives retries: reduce the just-attempted quantity by approximately 5% (whole-share floor) and retry; after the same second rejection reduce by another approximately 5% from the preceding attempt and retry once. Never retry zero or the same rounded quantity. After two reduced retries, or any unrelated/ambiguous error, stop this attempt and leave the gap pending. Verified against Kite Connect 3's documented structured `error_type`: only `MarginException` maps to StoX `BROKER_INSUFFICIENT_FUNDS`; message-text matching is forbidden.

## UX

- Avoid language implying user-selected future scheduling. Show **Recommendation date**, **First eligible execution date**, **Effective execution date** only when adjusted, and **Expires**.
- Show target amount, current state, remaining gap, capital-resolved capacity, internally executed, externally executed and unfulfilled/expired portions. Resource-only quantity changes are not new Recommendations.
- Normal Calendar rolls in either direction are silent but audited and do not invalidate approval.
- After Day #1 window close, send at most one approaching-expiry warning only for an unresolved investor-actionable blocker, naming actual Day #2 cutoff. Observable recovery resolves it in-app without recovery Telegram.
- Expiry is Info with a one-time Telegram exception because an intended trade failed to complete. Any actionable root cause stays a separate Action-required notification. No expiry notification follows another terminal state.
- Audit/history exposes every gate, date change, approval invalidation, derivation, internal match/valuation, broker attempt/fill/rejection and terminal decomposition.

## Acceptance criteria

1. Weekends/Trade Holidays never admit primary automated submission; opportunities roll and permitted future Calendar corrections recalculate correctly.
2. Before/after-cutoff generation creates the frozen anchor and exactly two eligible-session opportunities; remaining gap expires atomically at current Day #2 cutoff.
3. Partial progress, including one share, stays active and can progress again; completion requires target reached and partial expiry retains history.
4. Material new targets supersede from actual current state. Cancellation conditions and submitted-order boundaries behave as frozen.
5. Automatic and approved Semi intents coordinate across all portfolios sharing an Investor's Kite account; unapproved/Manual/blocked intents never net.
6. Same-symbol intents match oldest-first, create explicit ownership transfers and only net residual orders; zero-net, partial-fill and external-failure cases are idempotent.
7. Internal valuation follows provisional close then WAVG-fill/current-close finalization with correct seller realization, buyer basis, audit and no fictional fees.
8. SELL-before-BUY, non-blocking priority, verified-proceeds-only and shared-funds resizing are deterministic under concurrency.
9. Target, capital-resolved and executed quantities/amounts remain separate. Stored quantity is not blindly submitted after state changes.
10. Only explicit insufficient-funds/margin rejection receives at most two successively ~5%-smaller retries; unrelated/ambiguous errors do not.
11. Warning/recovery/silent-roll/expiry notification semantics work, and terminal Recommendations cannot later expire.
12. Protective/GTT lifecycle is not truncated by primary Recommendation expiry.

## Dependencies

- **V4-FEAT-038:** canonical NSE session calendar and future-date immutability. Existing manual Trade Holiday/TradingCalendar is reusable; official holiday sync remains FEAT-038.
- **V4-FEAT-037:** Investor-level Kite readiness/reconnect trigger and blocker state.
- **V3 shipped:** OD-12 target amount, OD-14 stored-close whole shares, ownership, WS2 capital accounting, WS3 allocation/fill order, WS4 recall/lending/Recall Bridge Loan/capital snapshots.
- **V4 shipped:** modes/entitlement/TOTP, Kite gateway, idempotency, reconciliation, partial fill ledger application and advanced-order lifecycle.
- New schema/services are needed for target-seeking lifecycle, net batches, internal transfers/valuation revisions, user coordination, calendar dates, decomposition and notifications.

## Explicit reconciliation with older V3 rules

- V3 remains authoritative upstream. FEAT-039 creates no new capital account or lending policy.
- V3 staggered-entry/cooldown governs Strategy generation/material target change. Once a V5 Recommendation is actionable, execution-resource partiality creates neither a new tranche/cooldown nor terminal fulfillment. The newer two-session target-seeking rule supersedes any reading that closes this Recommendation at its first funded slice.
- V3's minimum actionable amount applies to generation of an opportunity, not incremental execution of an already-actionable V5 intent. FEAT-039 adds no minimum; one valid share progresses.
- V3's latest stored raw close remains the V5 sizing input. Older “current/live price” wording is deferred to V6.
- V4's current per-profile loop, ID ordering, stored suggested quantity and one-Recommendation/one-order assumption are baseline, not FEAT-039 behavior. FEAT-039 replaces them with account coordination, frozen priority, target-gap resizing and net attribution.
- “Scheduled execution date” means first eligible market opportunity only; FEAT-039 adds no arbitrary future scheduling.

## Non-goals

- No FEAT-040 broker holdings/funds reconciliation product or silent ledger overwrite.
- No live Kite quote retrieval; this is V6.
- No redesign of V3 capital, recall, lending, bridge, proceeds, ranking, cooldown or Strategy logic.
- No portfolio-level shared-cash reservation or configurable portfolio/Strategy priority.
- No speculative waiting, cross-Investor netting, fractional shares, short selling, invented fees or broker/tax-basis claims for internal transfers.
- No change to downstream GTT/protection lifetime.
- No FEAT-040 planning or implementation.

## Major PO decision assessment

No genuinely major Product Owner decision remains. Exact schema names, lock granularity, scheduler cadence, stable-ID representation, Kite error mapping and event names are implementation details constrained by this specification.
