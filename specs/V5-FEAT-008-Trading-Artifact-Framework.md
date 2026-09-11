# V5 FEAT-008 — Trading Artifact Framework Remaining Phases

**Status:** DECIDED / FROZEN  
**Date:** 2026-09-07

## Problem
StoX needs a first-class lifecycle and distribution model for reusable trading artifacts—Strategies, Screeners, Indicators and Bundles—without allowing later edits or dependency changes to silently alter what an Investor deployed.

## Frozen behaviour
- Artifact authoring follows **Draft → Published immutable version**. Further edits create a new Draft/version.
- Published versions are immutable. Rollback is implemented as a new forward version, not mutation of history.
- Strategy, Screener and Bundle versions use SemVer. Indicator versions are owned by FEAT-007.
- Exact dependency versions are pinned at publication. No automatic dependency cascade/upgrade.
- Publication defines immutable artifact content. Enablement/deployment is separate mutable binding state.
- Artifact **Library** is account-owned; actual deployment/binding is Portfolio-specific.
- Artifact logic is distinct from binding/deployment settings.
- Existing active bindings do not auto-upgrade when a new artifact version appears. Upgrade is explicit per binding and activates atomically.
- In-flight Recommendation/order/execution work remains tied to its originating artifact version.
- Publish validates intrinsic artifact correctness. Binding validates Portfolio-specific compatibility/readiness.
- `Fork` creates a new artifact identity as an unpublished Draft copied from an exact published source version, starting at version `1.0.0` for the new lineage.
- Sharing is authenticated and immutable-version based. A recipient Forks to modify. Revoking future access does not break already adopted/forked artifacts.
- Dependency-aware sharing grants must include access required to resolve shared artifact dependencies.
- Bundles are distribution/deployment packages, not runtime business logic containers.
- Bundle deployment produces a transactional deployment plan. No nested Bundles in V5.
- After deployment, Bundle provenance remains evidence; Bundle does not become a perpetual configuration controller over deployed artifacts.
- Export/import uses a portable published package with staged validation before import. Foreign imports become a Fork/new local lineage rather than silently claiming native provenance.
- AI may assist creation of Drafts only. AI cannot bypass publication, validation, versioning or permission rules.
- One active Draft per artifact lineage; optimistic concurrency prevents lost updates.
- A Strategy may declare a minimal `configurable_parameters` array outside `definition`. Each declaration has a unique stable `key`, a `path` relative to `definition`, a scalar `type` (`integer`, `number`, `boolean`, or `string`), and may include `label`, numeric `minimum`/`maximum`, or an `enum`. The path must resolve to an existing Strategy definition value when the artifact is validated/published. Absence or an empty array means no parameter is configurable. This declaration authorizes FEAT-020 run-local overrides only; it does not make arbitrary Strategy logic or Indicator formulas editable.
- Published artifacts are not physically deleted. Archive changes discoverability/availability but preserves history and active bindings.
- Active bindings may continue referencing archived published versions unless their usability becomes Blocked by an actual dependency/runtime condition.
- Usability state is `Usable | Warning | Blocked`. A blocked active binding may raise FEAT-004 Action required notification.
- Provenance is explicit: owner-created, shared/adopted, forked, system/factory, imported as applicable.
- Published versions include versioned documentation/change summary.
- Structural diff between immutable versions is available.
- Permission model distinguishes owner/shared/system artifacts.
- System artifact evolution is versioned; existing bindings remain pinned until explicit upgrade.
- A binding has stable identity plus immutable revision/history sufficient to audit deployment changes.
- Dependency graph must be a strict DAG.
- Screener→Strategy dependency semantics and standalone Portfolio binding semantics must remain explicit rather than inferred.
- Preview/Test is non-trading validation; full historical simulation belongs to FEAT-020.

## Architecture
- Separate concepts: Artifact identity, immutable Artifact Version, Draft, Dependency, Library grant/share, Portfolio Binding, Binding Revision, Bundle package/deployment evidence.
- Runtime resolves exact published versions from bindings and must never use an unpinned “latest” dependency for an already-published artifact.
- Use FEAT-007 as the authoritative Indicator version/dependency source rather than duplicating Indicator definitions.
- Existing shipped package/registry infrastructure should be evolved rather than replaced with a parallel framework.

## UX
- Library shows owned/shared/system artifacts and provenance.
- Artifact detail shows current/published versions, Draft, dependencies, documentation/change summary, structural diff and lifecycle/usability.
- Binding UX is Portfolio-contextual and separates artifact version from mutable deployment settings.
- Upgrades are explicit and show version/dependency impact before activation.
- Sharing/Fork/Import/Export/Bundle actions use published immutable versions.

## Acceptance criteria
1. Published artifact versions cannot be edited in place.
2. Exact dependency versions are persisted and resolved deterministically.
3. New versions never silently change existing Portfolio bindings.
4. Explicit binding upgrade creates auditable atomic forward activation.
5. Fork creates a new lineage/Draft without mutating source or falsely preserving ownership identity.
6. Share/revoke semantics do not break already adopted artifacts.
7. Imported foreign packages are validated and enter as local forked lineage.
8. Bundles deploy transactionally and do not become runtime controllers.
9. Archive preserves immutable history and does not break valid active bindings by itself.
10. Cyclic dependency publication is rejected.
11. Blocked active bindings are visible and can integrate with FEAT-004 Action-required notifications.
12. Automated tests cover immutability, dependency pinning, upgrade, fork/share, bundle/import and authorization semantics.
13. Strategy configurable-parameter declarations are validated as part of the immutable envelope; undeclared paths and invalid schemas cannot be published.

## Dependencies
- FEAT-007 Indicator Registry.
- Existing V3 artifact registries/package I/O/Strategy/Screener infrastructure.
- FEAT-004 notifications for blocked active bindings.
- FEAT-020 simulation consumes immutable artifact worlds.

## Non-goals
- Automatic dependency upgrades.
- Mutation/rollback of already-published versions.
- Nested Bundles.
- AI auto-publication or auto-deployment.
- Full historical backtest/replay engine (FEAT-020).
- Physical deletion of published history.
