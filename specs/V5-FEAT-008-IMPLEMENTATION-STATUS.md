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

Focused evidence at this checkpoint:

- Backend FEAT-008 group: **34 tests / 175 assertions passed**.
- Frontend JavaScript unit group: **139 tests passed**.
- Dedicated Library UI contracts, TypeScript no-emit and production Vite build passed.
- Implementation commits: `843c34e`, `80577a4`, `278a528`, `7f5248d`, `dcf85d6`, `47738b2`, `70f686c`, `11761a9`, `8b6a7b4`, `26d8ed2`, `78fdaf7`.

## Material work still required

1. **Legacy identity migration/evolution.** Map the existing `portfolio_screeners` and Strategy registry rows into the V5 artifact identities/versions without duplicating business definitions or breaking current routes.
2. **Runtime exact binding resolution.** Live Screener/Strategy/Recommendation paths must resolve an enabled, usable Portfolio binding revision and its exact published dependency graph rather than a mutable legacy “current” definition.
3. **In-flight evidence propagation.** Recommendation, pending execution, order and execution evidence must retain the originating artifact version and binding revision so later publications/upgrades cannot reinterpret work already created.
4. **Safe migration/backfill.** Existing enabled Strategy/Screeners need deterministic initial published versions and Portfolio bindings, with idempotent migration/backfill tests and explicit handling of invalid legacy definitions.
5. **Legacy UX/documentation reconciliation.** Existing Registry/editor text that still promises save-in-place integer versioning must be updated or clearly labelled as the compatibility surface after runtime cutover.
6. **Final verification.** Run the full backend/frontend/typecheck/build/migration/security/trading suites and re-audit every frozen acceptance criterion before changing FEAT-008 to COMPLETE.

## Closure decision

FEAT-008 is deliberately **not COMPLETE**. The lifecycle/distribution subsystem is implemented, but shipping it beside an unchanged legacy runtime would violate the frozen requirement to evolve existing infrastructure rather than create a parallel framework, and would leave in-flight work unpinned.
