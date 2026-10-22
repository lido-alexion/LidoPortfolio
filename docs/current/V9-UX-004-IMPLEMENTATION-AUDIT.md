# V9-UX-004 verified implementation and closure audit

**IMPLEMENTED / VERIFIED — 22 October 2026.** Root owns source review, acceptance, normal protected integration and release verification. The [frozen specification](../archive/specs/V9-UX-004-ML-Acceptance-Guided-Wizard-Specification.md) governs all twenty §18 criteria below.

PR [186](https://github.com/lido-alexion/LidoPortfolio/pull/186) was marked ready and merged through the normal expected-head protected path on 22 October 2026 at 13:07 UTC. Accepted head `56c56d7fcc40c51c63ff5c1ca5f5c80752263351`, tree `a26848e51aca85ec901eb81e39a1bee92e9145a4`; merge and exact released master `554c7d60453b4313d4450206240155820b2371da`.

## Hosted and production evidence

- Exact-head [CI1529](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39778709356): Node247, Vitest600 across91 files, typecheck/documentation/build pass; Chromium482 pass,57 governed skips,30.7m, all six new completion/recovery cases first attempt; PHP2378 tests/16786 assertions/five notices/two governed skips; Python56 pass. Root reviewed full logs and artifacts (none).
- Normal master [CI1531](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39784063428), attempt1: frontend364562-character full log reviewed; Node247, Vitest600/91, typecheck/docs53 topics/197 static pages/build, Chromium482 pass/57 governed skips/28.9m; no failed-test retries, flakiness or unhandled failures. PHP2378/16786/five notices/two skips, Python56 pass. Existing router/build/container notices did not change assertions or gate policy.
- Normal package job119859045730 and deploy job119859380327: complete logs57118/61607 characters reviewed. Trusted canonical candidate gate, archive paths/hashes/modes and current-master equality passed. Release activated at13:41:13UTC; exact runtime identity/queue coverage/scheduler health gates passed twice.
- Production browser smoke: public non-destructive route and authenticated dedicated-identity read-only investor page **2/2 first attempt**,33.3s, completed13:42:43UTC. This is authenticated evidence; the later public refresh is not substituted for it.
- Release artifact12429388883:11525666 bytes, SHA256`ff7935b6aa1e3ac1c699cb3a242b34ccd8e3863fa956534098e527a8bac8dcb8`; root downloaded it and verified the digest, runtime archive and exact build1531/master metadata. Smoke artifact12430241889:194733 bytes, SHA256`f0836409a998932e7c3ce5d371dc41745ab5ddaffad384cba04683f0ee033e6e`; downloaded digest matched.
- Fresh public observation22October13:44:17UTC: `/api/build-info`200, exact released`554c7d60453b4313d4450206240155820b2371da`/build1531/run39784063428; `/up`200.

The earlier CI1527 selector failure and its downloaded artifact were reviewed. Its eight contexts identified one exact preflight action/navigation collision. The active-step/exact-button selector correction retained all assertions, timeouts, retries, skips and policies; current-head and master suites both passed all six new cases. Old CI1211/1526 is not used as current qualification.

## Frozen §18 acceptance ledger

All twenty criteria are accepted against the frozen specification and released implementation.
1 ACCEPT source/CI: ReadinessStep/StepCard plain goal, consequences and essential prose; component tests.
2 ACCEPT: AcceptanceStepper/server wizard current_step; backend readonly step-mapping/current-campaign tests; initial component.
3 ACCEPT: DiscoveryPreflightStep explains readiness not training; no-start component and E2E08.
4 ACCEPT: discovery reported dates, remediation-linked fresh final preflight; backend lineage tests and exact new completion payload.
5 ACCEPT: HistoricalSourcesStep dated manifest/file/family validation, partial chunk resume202queued/sealed test; no invented historic source requirements.
6 ACCEPT: preview/apply server can_apply and explicit focused confirmation; backend idempotency and full browser exact writes.
7 ACCEPT: persisted campaign/wizard_sequence/backfill lineage; hook refresh/recovery; new reload preserves identity/campaign and3running runIDs, single start.
8 ACCEPT: server resumable/can_retry_preview/can_apply only; backend scope/recovery plus source/failed/cancelled and new resume browser.
9 ACCEPT: reasonStatus/review/training components and backend status mapping; component19cases/composites/new qualified/rejected/stale cases.
10 ACCEPT: separate training confirmation invokes existing canonical campaign start/three horizons; backend pinned-input tests plus exact single browser start receipt.
11 ACCEPT: review/prose and visible active-model IDs/lifecycle controls remain unchanged; new browser disallows promotion/schedule/drift mutations.
12 ACCEPT: acceptanceReasonRegistry17 recognized prefixes include plain explanation/action/owning step; horizon suffix kept diagnostically. Root reviewed registry/report/horizon routing alongside original frozen design and known blocker paths; no speculative new requirements.
13 ACCEPT: unknown codes visible in AdvancedDiagnostics and safe fallback, component unknown-code coverage.
14 ACCEPT: essential StepCard prose independent of hover; InfoHelp touch/Escape/outside/keyboard behavior and confirmation focus tests.
15 ACCEPT: responsive wizard CSS/composite mobile and new390px completion no-overflow assertion; desktop1440.
16 ACCEPT: existing Admin/CSRF routes, canonical campaign start/backfill queues, locks/idempotency/evidence/promotion/lifecycle retained; full backend2378 and source review. No live production training needed to prove UI.
17 ACCEPT: report/wizard explicit readonly projections; backend readonly test forbids writes/probe; hook automatic polling GET-only.
18 ACCEPT:19 targeted components plus existing E2E08 material recovery +6 new completion/resume/stale tests pass full current-head suites.
19 ACCEPT: current ml-production-acceptance/ml-lifecycle-operations/journey05/catalog/statichelp updated in SAME product PR, only ships with implementation merge; this audit and the accompanying register update record verified closure after release.
20 ACCEPT: exact head current canonical backend/frontend/browser/Python green; old CI1211/1526 not substituted.

## Authority and exclusions

The wizard remains a recoverable educational/orchestration layer over existing server-authoritative V8 acceptance, evidence, candidate, promotion and lifecycle rules. It does not authorize production training, promotion, provider/account requests or service control merely to prove UI behavior. No actual production training is a closure prerequisite for this epic. The normal deployment guard was authoritative; the prior ML worker hold was refreshed and no worker service action was taken.

This documentation change records the released implementation. It does not change application, deployment or test policy, and does not claim its documentation commit is the runtime SHA. UX001 remains closed, OPS001 remains in progress, OPS004 remains separate and not started; unrelated holds and work are preserved.
