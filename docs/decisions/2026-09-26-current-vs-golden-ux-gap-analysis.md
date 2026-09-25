# StoX Current vs Golden UX Gap Analysis

**Date:** 2026-09-26  
**Status:** INITIAL GAP ANALYSIS — DESIGN/IMPLEMENTATION BACKLOG INPUT  
**Golden baseline:** `docs/decisions/2026-09-26-golden-core-investor-workflows.md`  
**Current journey baseline:** `docs/user-journeys/**`  
**Companion Screener decision:** `docs/decisions/2026-09-25-screener-ux-versioning-simplification.md`

## 1. Purpose

This document compares the current StoX investor workflows with the approved Golden core workflow. It identifies structural UX problems before implementation begins.

This is not yet a code-change specification. Runtime defects may exist in addition to these structural gaps and must be verified separately. The comparison deliberately distinguishes:

- **UX architecture gap** — the current product asks the user to follow the wrong/overly technical workflow even when everything works as coded;
- **contract/spec conflict** — an older accepted decision must be superseded or narrowed to reach the Golden workflow;
- **implementation defect** — current behavior fails even its intended contract; this requires runtime/test verification;
- **presentation improvement** — semantics are largely correct but the user cannot easily understand state, cause, or next action.

## 2. Severity

- **P0 — Safety/accounting:** can cause duplicate/incorrect real-money action, ownership corruption, or false execution/accounting state.
- **P1 — Core workflow blocker:** a normal investor may be unable to complete or understand a core job without internal product knowledge.
- **P2 — Major friction/ambiguity:** job is possible but unnecessarily difficult, fragmented or easy to misunderstand.
- **P3 — Secondary polish:** useful simplification that does not materially block the core workflow.

## 3. Executive Findings

### GAP-001 — Artifact Framework leaks into ordinary Screener/Strategy authoring — P1

**Current:** mapped Screeners are read-only runtime projections; the current documentation instructs the user to author a Draft and publish/upgrade it in Artifact Library. Artifact Library itself exposes Draft creation, SemVer, validation/publish and binding/upgrade operations.

**Golden:** `Screeners -> Create/Edit -> Save` and `Strategies -> Create/Edit -> Save`. StoX performs immutable-version, publication/provenance and binding bookkeeping internally.

**Why this is painful:** the investor must understand an internal artifact lifecycle merely to change an investment rule. This directly explains the observed confusion around Inspect/Open Artifact Library/SemVer/version creation.

**Required change:** make domain pages the authoritative ordinary authoring surfaces. Keep Artifact Library as advanced provenance/distribution/technical tooling. Remove SemVer and generic Draft ceremony from ordinary Screener authoring; similarly abstract Strategy version lifecycle behind Strategy Save.

**Preserve:** immutable historical versions, exact dependency pins, provenance, backtest/replay identity, account ownership and auditability.

---

### GAP-002 — Too many surfaces represent one domain object — P1

**Current:** core navigation exposes Screeners plus Screener Registry, Strategies plus Strategy Registry, and Artifact Library. Current journeys explicitly move between `/screeners`, `/screeners/registry`, `/strategy`, `/strategy/registry`, and Artifact Library.

**Golden:** one normal home per domain object. Registry/Artifact surfaces may remain routable for compatibility/advanced inspection but are not required to complete normal CRUD.

**Required change:** consolidate normal actions into Screeners and Strategies. Remove Registry/Artifact Library from ordinary task instructions and primary navigation where appropriate. Use deep links from advanced/technical areas rather than making those areas prerequisites.

**Preserve:** runtime registry/binding architecture internally where still useful.

---

### GAP-003 — Creating a missing Screener breaks Strategy task continuity — P1

**Current:** STR-02 tells the user to leave Strategy, go to Screeners, create/validate/run a Screener, then navigate back to Strategy and select it.

**Golden:** Strategy's Screener selector includes `Create Screener`; StoX preserves the unfinished Strategy, opens normal Screener creation, then returns with the new Screener selected.

**Required change:** implement contextual child-creation flow with return route/context and preserved Strategy form state.

**Preserve:** Screener and Strategy remain separate domain objects and versioned evidence.

---

### GAP-004 — Strategy readiness is not a first-class user concept — P1

**Current:** creation journeys instruct users to configure policy, save and then enable; current surfaces/lifecycle terminology include draft/archived/enabled and registry/artifact state. A user can encounter unavailable actions without a single domain-level checklist explaining what remains.

**Golden:** Strategy exposes `Setup Required`, `Disabled`, `Enabled`, `Archived`. Incomplete new Strategy configuration can be saved. `Setup Required` lists every missing requirement with direct links. A complete disabled Strategy clearly offers Enable.

**Required change:** add a domain readiness evaluator and UI checklist. Separate configuration completeness from artifact lifecycle and enabled state.

**Preserve:** validation must fail closed; invalid policy must never become operational.

---

### GAP-005 — Disabled controls and lifecycle failures need actionable reasons — P1

**Current contract:** shared UI guidance already says disabled controls should expose why an action is unavailable, but the reported real workflow includes unexplained disabled actions and hidden prerequisite/status changes.

**Golden:** every unavailable core action says why and provides the shortest resolution route.

**Required change:** standardize `BlockedAction`/readiness presentation across Screener, Strategy, Recommendation and Pending Execution. Never rely on disabled HTML state alone for non-obvious prerequisites.

**Runtime audit:** verify each currently disabled Create/Edit/Enable/Archive/Execute action separately; some may be implementation defects rather than only UX design issues.

---

### GAP-006 — Persisted Screener Draft/SemVer lifecycle is unnecessary investor ceremony — P1

**Current:** Artifact Library creates explicit Drafts and validates user-supplied SemVer; generic lifecycle enforces one active Draft per lineage.

**Golden/PO decision:** no persisted Screener Draft concept. User edits in the page and saves; abandoned unsaved changes are lost. Internal technical versions are generated automatically.

**Required change:** implement the dated Screener simplification decision. Review AI/backtest/import flows that currently depend on Draft records and replace them with explicit proposal/preview/import-confirmation or save-as-new flows where needed.

**Spec impact:** older generic Artifact Framework rules must be narrowed/superseded for ordinary Screener authoring when implementation lands.

---

### GAP-007 — Screener sharing/import semantics are too infrastructure-oriented — P2

**Current:** SCR-07 asks users to inspect reusable/shared/factory artifacts and use an import/create/binding flow; current documentation also discusses runtime ownership/binding.

**Golden/PO decision:** user-created Screeners are private/account-scoped. Sharing exposes/copies the current definition, not a shared mutable instance. Recipient use remains private and independent.

**Required change:** present `Share definition` / `Copy to my Screeners` semantics. Hide binding mechanics. Ensure later edits do not cascade between users.

---

### GAP-008 — Screener history is not contextual enough for its real purpose — P2

**Current:** version identity is prominent during authoring, while historical investigation requires the user to know versions/runs.

**Golden:** history is secondary. The primary audit path is contextual: `Recommendation -> Why? -> View Screener used`, resolving the exact immutable historical definition.

**Required change:** add historical-definition deep links from Recommendation/Transaction evidence. Keep Screener History under secondary actions.

**Preserve:** exact immutable version IDs remain stored and auditable internally.

---

### GAP-009 — Screener edits and Strategy dependency adoption need a human workflow — P1

**Current architecture:** Strategy dependencies resolve to exact published Screener versions and do not silently cascade, which is correct. But the current user journeys mainly describe replacing/binding a Screener and do not provide a simple `newer definition available` adoption workflow.

**Golden:** editing Screener creates a new immutable definition; dependent Strategy remains pinned and displays `Update available`. User reviews a human-readable diff and explicitly adopts it, producing the appropriate new Strategy version.

**Required change:** dependency-update detection, diff UX and explicit `Use updated Screener` action on Strategy.

---

### GAP-010 — Strategy editing still exposes Registry/Artifact lifecycle rather than domain editing — P1

**Current:** STR-10 begins at Strategy Registry or Strategy; current product documentation says Artifact Library is authoritative for lifecycle/deployment and mapped registry rows are compatibility/read-only surfaces.

**Golden:** Strategy detail owns Edit. Save transactionally creates the next immutable version; no SemVer/publish/bind ceremony.

**Required change:** domain Strategy editor becomes normal authoring façade over versioned backend.

**Preserve:** historical Recommendations remain pinned to exact Strategy versions; Save does not run the decision pipeline.

---

### GAP-011 — Invalid edits to an enabled Strategy need transactional protection and clear UX — P1

**Current:** validation exists, but the user journey does not define the simple operational promise that a failed edit leaves the currently valid enabled Strategy untouched.

**Golden:** invalid replacement configuration is rejected as a whole; UI states `Strategy wasn't changed` and lists corrections. Existing valid operational version remains in force.

**Required change:** verify backend atomicity and make the guarantee explicit in UI/tests.

---

### GAP-012 — Obsolete single/last-enabled Strategy rules still leak into docs/UI — P1

**Current:** some older/current help text still refers to a `last remaining enabled strategy` restriction even though the accepted model supports multiple concurrent Strategies and the PO has declared the single-Strategy rule obsolete.

**Golden:** multiple Strategies may independently be enabled. Disabling/archive safety is based on real operational obligations, not an obsolete requirement that some Strategy must always remain enabled.

**Required change:** remove obsolete last-enabled/single-active constraints from docs, validation and UI wherever still implemented, after code audit confirms locations.

**Preserve:** a Strategy that owns positions/live work cannot be disabled in a way that abandons required exit management.

---

### GAP-013 — Disable and Archive are not sufficiently distinguished — P1

**Current:** STR-14 combines `Archive or disable a strategy` in one journey and points to Registry lifecycle actions.

**Golden:** Disable means stop normal new decision participation when operationally safe; Archive means retire from ordinary use after obligations are resolved. Neither deletes history.

**Required change:** separate actions, consequences, eligibility checks and explanations. Surface owned positions, live Recommendations, pending execution and reservations that prevent retirement.

---

### GAP-014 — Recommendation and Review are split across overlapping surfaces — P2

**Current:** REC-02 says open `/recommendations` or `/review` as appropriate, requiring the user to understand which surface owns which part of review.

**Golden:** Recommendations is the investor's primary work queue, with `Needs Your Attention` first. Review/reporting may remain as analytical/history functionality but should not create ambiguity about where to approve/reject/defer current intent.

**Required change:** consolidate actionable review into Recommendations or make `/review` a clear route-backed subview with one mental model and consistent deep links.

---

### GAP-015 — Recommendation detail needs a first-class human explanation hierarchy — P2

**Current semantics:** evidence exists and REC-03 tells users to inspect Strategy version, Screener evidence, factors, gates, position, sizing and capital. This is conceptually correct but can become a technical evidence hunt.

**Golden:** detail answers in order: `What? -> Why? -> Which Strategy? -> How much? -> Funding? -> What do I do?`. `Why?` renders human-readable evidence and links to exact historical Strategy/Screener definitions.

**Required change:** redesign Recommendation detail information hierarchy without weakening stored evidence.

---

### GAP-016 — Internal lifecycle vocabulary should not dominate Recommendation UX — P2

**Current:** lifecycle names such as `pending_review`, `pending_execution`, `OPEN_POSITION` etc. are central technical terms.

**Golden:** investor language leads: `Needs Review`, `Open Position`, `Add to Position`, `Reduce Position`, `Exit Position`; technical status may remain in details/support surfaces.

**Required change:** presentation mapping only; preserve canonical backend enums/API semantics.

---

### GAP-017 — Capital readiness must remain visually separate from investment opinion — P1

**Current contract/journeys:** already conceptually correct: partial/unfunded capital must not rewrite OPEN/INCREASE into WATCH/HOLD.

**Golden gap:** ensure the UI visibly separates `Desired target`, `Fundable now`, and `Funding gap`, with contextual resolution.

**Required change:** presentation/readiness consolidation; verify every Recommendation and Pending Execution view follows this distinction.

---

### GAP-018 — Approval must explicitly hand off to Pending Execution — P1

**Current:** contract correctly states approval is not broker submission, but users can still misunderstand lifecycle boundaries if status changes are terse.

**Golden:** after Approve, show `Investment decision recorded`, `Moved to Pending Execution`, and `No broker order has been placed yet`, with `Review Pending Execution` action.

**Required change:** explicit transition confirmation and deep link.

---

### GAP-019 — Pending Execution should be a readiness queue, not a technical status table — P1

**Current:** EXE-05 requires the user to manually inspect many prerequisites: execution window, reservation, broker session, quantity, etc.

**Golden:** each item presents `Ready` or `Blocked/Waiting` plus the exact blocker and resolution action. Detailed gate diagnostics remain expandable.

**Required change:** create a consolidated execution-readiness model/view sourced from existing authoritative checks; do not duplicate business logic in frontend.

---

### GAP-020 — Broker connection/authentication should resolve in context — P1

**Current:** Semi-Automatic journey starts by requiring a valid Kite session; users may need to leave the execution job and find account/settings connection controls. StoX execution authorization and Kite authentication are also distinct concepts.

**Golden:** Pending Execution shows `Kite — Not connected` and a `Connect Kite` action; after broker login, return to the same execution item. Authentication prompts clearly identify Kite vs StoX execution authorization vs recovery code.

**Required change:** contextual connection deep-link/return-state flow and clearer authentication labels.

---

### GAP-021 — Cancel broker attempt vs cancel Recommendation needs explicit separation — P0

**Current:** EXE-08 describes cancellation before submission as cancelling the pending Recommendation; EXE-09 handles broker-order cancellation. Recent accepted lifecycle behavior additionally preserves an approved Recommendation after a zero-fill broker-attempt cancellation for retry.

**Golden:** two explicit actions/concepts:

- `Cancel Order / Cancel Execution Attempt` — stops the current broker attempt; underlying approved Recommendation can remain pending for retry according to policy.
- `Cancel Recommendation` — terminates the investment intent and releases applicable reservation.

**Required change:** audit current pre-submission and post-submission cancel APIs/UI and align labels/lifecycle so users cannot accidentally terminate the wrong object.

---

### GAP-022 — Cancellation requested must not appear final — P0

**Current accepted behavior:** this was recently corrected: broker DELETE acknowledgement is not terminal cancellation and `Cancellation requested; waiting for Kite confirmation` is required.

**Golden:** preserve this behavior prominently. The order may still fill until broker confirmation.

**Required change:** ensure all execution surfaces use the same state; add E2E regression coverage later.

---

### GAP-023 — Retry/partial-fill UX must make remaining quantity explicit — P0

**Current contract:** partial fills and retries are already protected semantically.

**Golden:** show original target, already filled and remaining quantity; retry button says `Execute Remaining N`, never repeats the full target.

**Required change:** presentation plus regression tests around idempotency and partial-fill reconciliation.

---

### GAP-024 — Order state should replace submission controls immediately — P0

**Current backend:** durable submission/idempotency protections exist, but UI must reinforce them.

**Golden:** once submission starts/succeeds, normal `Place Order` disappears and the item becomes `Submitting/Submitted/Open/...` with order identity/status. Uncertain state blocks blind retry until reconciliation.

**Required change:** audit all submission state transitions and button enablement.

---

### GAP-025 — Transactions need a direct provenance trail — P2

**Current:** EXE-15 asks the user to verify transaction/holding linkage; evidence exists across domains.

**Golden:** Transaction detail directly links `Order -> Recommendation -> Strategy used -> Screener used -> evidence`, while displaying Strategy ownership.

**Required change:** add provenance navigation/deep links and historical-definition viewers.

---

### GAP-026 — Manual Transaction and Execute Recommendation must remain visibly different — P1

**Current:** EXE-01/EXE-02 support manual execution and transaction recording, but a generic add-transaction flow can be confused with fulfilling a pending Recommendation.

**Golden:** `Execute/Record this approved Recommendation` retains its provenance. `Record Transaction` is explicitly for legitimate external activity not already represented by normal StoX execution provenance.

**Required change:** contextual manual-execution flow and duplicate-intent safeguards.

---

### GAP-027 — Same-security multi-Strategy ownership must be visible everywhere money moves — P0

**Current contract:** ownership isolation is correct, but any stock-centric UI that hides Strategy identity creates a dangerous ambiguity.

**Golden:** Recommendation, Pending Execution, Order, Transaction and Holding detail all prominently show owning Strategy and strategy-owned quantity. EXIT/REDUCE acts only on that ownership.

**Required change:** audit every money-moving view and API payload presentation for Strategy identity/quantity.

---

### GAP-028 — Current user documentation itself contains obsolete/technical workflows — P2

**Current baseline examples:**

- SCR-07 sends users through registry/import/binding mechanics.
- STR-01/02/10/13/14 reference Strategy Registry as normal workflow.
- STR-14 still mentions the last-enabled-strategy rule.
- Current served Screener help says mapped Screeners are read-only and must be authored/published/upgraded in Artifact Library.

**Golden:** after implementation, human docs must describe the simplified domain workflows, while advanced Artifact Library documentation remains separate.

**Required change:** do not rewrite current user journeys yet as if implementation exists. Update them alongside implementation and preserve this gap record as migration history.

## 4. Journey-Level Mapping

| Current journey | Golden journey(s) | Main gap | Priority |
|---|---|---|---|
| SCR-01/02/03 | GJ-SCR-01 | Save currently participates in Draft/publish/bind lifecycle | P1 |
| SCR-04 | GJ-SCR-02 | Edit must move from Artifact Library/version ceremony to domain Edit/Save | P1 |
| SCR-05 | GJ-SCR-01/02 | Validation should be integrated into Save/Test rather than a separate lifecycle ceremony | P2 |
| SCR-06 | GJ-SCR-03 | Mostly aligned; improve run/readiness/evidence presentation | P2 |
| SCR-07 | GJ-SCR-09 | Replace import/binding mental model with private definition copy/share | P2 |
| SCR-08 | GJ-SCR-07/08 | Archive from Screener page; show dependencies/blockers | P1 |
| SCR-09 | GJ-SCR-06 | Make historical audit contextual and human-readable | P2 |
| STR-01 | GJ-STR-01 | Remove Registry/Artifact ceremony; add readiness model | P1 |
| STR-02 | GJ-STR-02 | Preserve Strategy context while creating missing Screener | P1 |
| STR-03/06/07/08/09 | GJ-STR-01/03/04 | Consolidate into one Strategy editor organized by investor questions | P2 |
| STR-04/05 | Strategy Exit Rules | Avoid unnecessary separate exit-entity UX unless real reuse requires it | P2 |
| STR-10 | GJ-STR-05/06 | Direct transactional Edit/Save; hide version ceremony | P1 |
| STR-11 | GJ-STR-07 | Add explicit dependency update/diff workflow | P1 |
| STR-12 | GJ-STR-05 | Mostly aligned; preserve unrelated entry policy | P2 |
| STR-13 | GJ-STR-04 | Enable from Strategy detail with readiness explanation | P1 |
| STR-14 | GJ-STR-08/09/10 | Separate Disable/Archive; remove obsolete last-enabled rule | P1 |
| STR-15/16 | GJ-STR-13/GJ-X-09 | Semantics aligned; strengthen ownership visibility | P0/P1 |
| REC-01 | Pipeline prerequisite | Mostly semantic alignment; make pipeline result/status clear | P2 |
| REC-02/03/04 | GJ-REC-01/02/13 | Consolidate work queue and explanation hierarchy | P2 |
| REC-05 | GJ-REC-07/08 | Mostly aligned; ensure no trade controls appear | P1 |
| REC-06 | Diagnostic preview | Keep diagnostic/non-persistent distinction | P3 |
| REC-07 | GJ-REC-03 | Explicit handoff to Pending Execution | P1 |
| REC-08/09 | GJ-REC-04/05/06 | Mostly aligned; simplify history/reopen discovery | P2 |
| REC-10/11 | GJ-REC-09/10 | Make desired vs fundable amounts visually explicit | P1 |
| REC-12 | GJ-REC-11/12 | Clear non-actionable state + replacement link | P1 |
| EXE-01/02 | GJ-EXE-04/GJ-TXN-04 | Separate fulfilling Recommendation from unrelated manual record | P1 |
| EXE-03/04 | GJ-EXE-03/05 | Resolve Kite/auth context inline; clarify code types | P1 |
| EXE-05 | GJ-EXE-01/02 | Convert checklist into readiness summary + fix actions | P1 |
| EXE-06/07 | GJ-EXE-05/06/09 | Strong state transition and Strategy ownership | P0 |
| EXE-08/09/10 | GJ-EXE-10/11/14 | Separate attempt cancellation from Recommendation cancellation | P0 |
| EXE-11 | GJ-EXE-08/13 | Explicit remaining quantity; exactly-once accounting | P0 |
| EXE-12/13/14 | GJ-EXE-02/12/13 | Recovery must prevent blind duplicate retry | P0 |
| EXE-15 | GJ-TXN-01/02/03 | Add direct provenance chain | P2 |
| E2E-01/02 | GJ-X-01/02/04 | Major reduction in cross-page/Artifact/Registry ceremony | P1 |
| E2E-03 | GJ-REC-13/GJ-X-04/09 | Preserve Strategy-owned EXIT quantity | P0 |
| E2E-04 | GJ-STR-05/GJ-REC-11 | Clarify new-version/current-intent relationship | P1 |
| E2E-05 | GJ-X-07 | Preserve Recommendation across attempt cancellation/retry | P0 |
| E2E-06 | GJ-REC-09/10 | Funding resolution in context | P1 |
| E2E-07 | GJ-X-09 | Strategy ownership must remain visible throughout | P0 |

## 5. Proposed Redesign Workstreams

### WS-1 — Domain-surface simplification — highest UX priority

- Screeners becomes normal Screener CRUD home.
- Strategies becomes normal Strategy CRUD home.
- Artifact Library/Registries become advanced/compatibility surfaces.
- Remove SemVer/Draft/publish/bind ceremony from ordinary authoring.
- Add automatic internal versioning and historical deep links.

Primary gaps: 001, 002, 006, 010, 028.

### WS-2 — Strategy setup and dependency workflow

- `Setup Required` readiness model/checklist.
- contextual Create Screener and return.
- transactional Strategy edits.
- Screener-update detection/diff/adoption.
- separate Disable and Archive with operational blockers.
- remove obsolete single/last-enabled Strategy constraints.

Primary gaps: 003, 004, 005, 009, 011, 012, 013.

### WS-3 — Recommendation decision workspace

- one clear Needs Attention queue;
- human-first action/status vocabulary;
- first-class Why? evidence;
- historical Strategy/Screener links;
- desired vs fundable capital presentation;
- explicit Approve -> Pending Execution handoff.

Primary gaps: 014–018.

### WS-4 — Execution readiness and broker safety UX

- readiness summary and blocker resolution;
- contextual Kite connection/return;
- explicit order-attempt vs Recommendation cancellation;
- broker cancellation-pending state;
- partial-fill remaining quantity;
- duplicate-submission prevention in UI;
- uncertain-state reconciliation before retry.

Primary gaps: 019–024.

### WS-5 — Transaction provenance and Strategy ownership

- provenance chain from Transaction backward;
- contextual manual execution vs external transaction entry;
- Strategy identity/owned quantity on every money-moving surface.

Primary gaps: 025–027.

## 6. Recommended Implementation Order

The order below minimizes rework and addresses the user's largest current blockers before cosmetic improvements:

1. **WS-1 Domain-surface simplification** — establishes where CRUD actually lives.
2. **WS-2 Strategy setup/dependency workflow** — makes creation/configuration usable end to end.
3. **WS-4 Execution safety UX** — P0 states should be verified/fixed before broad UI automation.
4. **WS-3 Recommendation workspace** — simplify review/explanation around the stabilized upstream/downstream flows.
5. **WS-5 Transaction provenance/ownership** — complete the audit chain.
6. Update current user-journey documentation to the implemented Golden paths.
7. Add stable automation hooks and Playwright E2E coverage against the frozen journeys.

P0 execution/ownership defects discovered during runtime verification override this sequencing and should be fixed immediately.

## 7. What Requires Runtime/Code Verification Next

The structural analysis is sufficient to identify the redesign direction, but these points must be verified against source/runtime before implementation tickets are frozen:

1. exact Screener page buttons and current Create/Edit routing;
2. exact Strategy page/Registry button states and validation blockers;
3. all remaining code enforcing `last enabled strategy` or single-active assumptions;
4. whether Strategy incomplete configuration can currently persist without a generic Artifact Draft;
5. current Screener/Strategy binding-upgrade APIs and whether they can be safely wrapped by domain Save/Adopt actions;
6. Recommendation vs Review page overlap and current primary actions;
7. Pending Execution readiness data already available from backend versus frontend-computed checks;
8. pre-submission cancellation semantics versus post-submission order-attempt cancellation;
9. all UI paths where broker submission can remain clickable during in-flight/uncertain state;
10. every money-moving view where Strategy ownership is absent or visually weak;
11. current deep-linkability from Recommendation/Transaction to exact historical artifact versions.

These checks should distinguish **bug** from **redesign gap**. The Golden behavior remains the target unless a newly discovered functional dependency requires an explicit PO decision.

## 8. Spec Migration Policy

Do not erase history while redesign is pending.

- Keep current contracts describing current/previous accepted implementation until each workstream is implemented and verified.
- Use dated decision/gap documents as the target-change record.
- When a workstream lands, update `docs/current/**` to the new authoritative behavior and add dated history notes.
- Mark conflicting older decisions as superseded/obsolete rather than silently deleting them.
- Update `docs/user-journeys/**` only when the corresponding Golden workflow is actually available to users.
