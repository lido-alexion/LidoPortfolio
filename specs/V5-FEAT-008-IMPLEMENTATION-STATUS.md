# V5 FEAT-008 — Implementation Status and Remaining Closure Work

**Status:** IN PROGRESS

**Reconciled:** 2026-09-09

**Authority:** [`V5-FEAT-008-Trading-Artifact-Framework.md`](V5-FEAT-008-Trading-Artifact-Framework.md)

## Implemented and verified

- Account-owned artifact lineages with one optimistic-concurrency Draft and immutable SemVer publications.
- Exact artifact/Indicator dependency pinning, strict DAG validation and nested-Bundle rejection.
- Stable Portfolio bindings with immutable revision history, explicit settings/enablement revisions and atomic opt-in upgrades; no latest-version following.
- Authenticated immutable-version sharing with exact dependency closure, durable adoption, revocation and Fork provenance.
- Portable checksum-validated export and all-or-nothing staged foreign import into new local Draft lineages.
- Transactional Bundle plans with stale-plan rejection, full member rollback on failure, retained deployment evidence and no perpetual Bundle control.
- Derived `Usable | Warning | Blocked` binding state over transitive exact dependencies, hourly refresh and deduplicated FEAT-004 Action-required notification/resolution for blocked enabled bindings.
- Account/Portfolio-scoped APIs for Library inspection, structural diff, lifecycle actions, binding actions, sharing, packages and Bundles.
- Consolidated Investor Artifact Library UI for search, provenance, lifecycle, exact dependencies, diff, Draft authoring, publishing, explicit deployment, sharing/Fork, import/export and archive.
- Published history cannot be physically deleted; archive leaves history and valid active bindings intact.
- Deterministic, idempotent legacy Screener/Strategy backfill creates exact immutable publications and Portfolio bindings, resolves Screeners before dependent Strategies, supports one-Portfolio rollout, and rolls back invalid rows without partial mappings.
- Library binding and explicit-upgrade transactions create/update the existing Strategy/Screener runtime identities rather than a parallel execution model; projection failure rolls back the binding transaction.
- Live Recommendation generation consumes the exact enabled usable Strategy binding definition and fails closed for mapped-but-unavailable bindings. Recommendation, manual/live/GTT order, and fill evidence retain the originating artifact version and binding revision.
- Manual/scheduled Screener runs pin the exact immutable definition and binding revision at start and retain it across chunk continuation; inconsistent or unavailable mapped bindings fail closed.
- Screener and Strategy backtests retain exact artifact-version/binding-revision evidence and immutable definition/config snapshots across resumable chunks, so a later binding upgrade cannot reinterpret an in-flight simulation.
- Mapped legacy editors/registries are explicitly read-only compatibility projections and link to the authoritative Artifact Library; legacy mutation/enable/archive/delete endpoints reject lifecycle bypasses.

Focused evidence at this checkpoint:

- Combined backend compatibility/runtime/projection suite: **143 tests / 1,219 assertions passed**.
- Frontend JavaScript groups: **143 contract/unit tests + 59 component tests passed**.
- Dedicated Library UI contracts, TypeScript no-emit and production Vite build passed.
- Implementation commits: `843c34e`, `80577a4`, `278a528`, `7f5248d`, `dcf85d6`, `47738b2`, `70f686c`, `11761a9`, `8b6a7b4`, `26d8ed2`, `78fdaf7`, `8214c0d`, `40e0db`, `46485a2`, `a68e473`.

## Material work still required

1. **Authoring cutover completion.** New legacy Registry/editor creation/import paths can still create unmapped compatibility rows. Route those paths into V5 Draft creation (or retire them after equivalent Library UX) so no new runnable definition can bypass publication and binding.
2. **Operational rollout evidence.** Execute the explicit backfill against representative/restored application data, inventory any invalid rows, and document remediation/rollback procedure before production rollout.
3. **Final verification.** Run the full backend/frontend/typecheck/build/migration/security/trading suites and re-audit every frozen acceptance criterion before changing FEAT-008 to COMPLETE.

## Closure decision

FEAT-008 remains deliberately **IN PROGRESS**. The live Strategy/Screener runtime, trading evidence, and resumable simulation pinning are implemented, but new legacy authoring paths still require reconciliation plus final rollout/full-suite evidence before closure.
