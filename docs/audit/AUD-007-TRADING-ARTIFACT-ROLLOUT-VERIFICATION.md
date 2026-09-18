# AUD-007 Trading Artifact Rollout Verification

## 1. Finding Recap

AUD-007 asked whether the Trading Artifact framework could safely carry one representative artifact through authoring, immutable versioning, validation, binding, runtime use, rollout, rollback, dependency failure, and historical explanation. Repository evidence was extensive but had not previously included one focused end-to-end assurance gate.

The representative verification in this document uses the current immutable artifact model and a holdings-scoped screener runtime. It does not alter artifact semantics or introduce a new deployment mechanism.

## 2. Artifact Architecture

The implemented path is:

```text
ArtifactLibraryController / ArtifactLibraryPage
  -> ReusableArtifactLifecycleService
  -> ReusableArtifactVersion + dependencies
  -> ArtifactBindingService
  -> ArtifactBindingRevision
  -> ArtifactRuntimeBindingResolver
  -> ScreenerRunService
  -> pinned ScreenerRun evidence
```

Bundle rollout adds:

```text
published Bundle
  -> ArtifactBundleDeploymentService::plan()
  -> planned deployment/items
  -> deploy() transaction
  -> ArtifactBindingService bind/upgrade/settings operations
```

Published versions are immutable. A later version is created as a new draft and must be explicitly published and bound; publishing alone does not change an existing binding.

## 3. Artifact Types

The current reusable framework defines `indicator`, `screener`, `strategy`, and `bundle`. The representative type is `screener` because it has a real runtime resolver and a deterministic consumer that persists version and binding-revision evidence on each run.

| Type | Authoring | Versioning | Binding | Runtime consumer | Rollback |
| --- | --- | --- | --- | --- | --- |
| Indicator | Registry/framework and artifact validation | Registry/version identity | Dependency reference | Screener/strategy dependency evaluation | Dependency version remains exact |
| Screener | Artifact Library and legacy projection | Immutable reusable versions | Portfolio binding | `ScreenerRunService` | Explicit upgrade to a prior published version |
| Strategy | Artifact Library and legacy projection | Immutable reusable versions | Portfolio binding | Strategy runtime resolver/projection | Explicit upgrade to a prior published version |
| Bundle | Artifact Library | Immutable member manifest | Atomic member deployment plan | Binding deployment, not direct runtime execution | Re-deploy/upgrade member versions |

## 4. Persistence / Source of Truth

| Concept | Model/table | Key fields | Source of truth |
| --- | --- | --- | --- |
| Artifact lineage | `ReusableArtifact` / `portfolio_reusable_artifacts` | `artifact_uuid`, owner, type, slug, `archived_at` | Artifact lineage row |
| Artifact version | `ReusableArtifactVersion` / `portfolio_reusable_artifact_versions` | semver, status, content, definition hash, lock version, published time | Immutable published version |
| Dependency | `ReusableArtifactDependency` / `portfolio_reusable_artifact_dependencies` | source version, exact target version or indicator/version | Version dependency rows |
| Portfolio binding | `ArtifactBinding` / `portfolio_artifact_bindings` | profile, artifact lineage, status, usability, active revision, lock | Active binding row |
| Binding history | `ArtifactBindingRevision` / `portfolio_artifact_binding_revisions` | revision number, exact artifact version, action, actor, timestamp | Immutable revision history |
| Bundle deployment | `ArtifactBundleDeployment` and items | planned/completed/failed, plan, member/resulting revisions | Deployment plan and item rows |
| Runtime evidence | `ScreenerRun` and equivalent backtest/order/recommendation fields | reusable artifact version ID and binding revision ID | Historical consumer output |

## 5. State Machine

The lifecycle states found in code are:

```text
draft -> published
published -> archived (lineage archive; published version remains historical)

binding: enabled <-> disabled
binding usability: usable | warning | blocked

bundle deployment: planned -> completed
                   planned -> failed
```

There is no separate persisted `rollback` status. Rollback is an explicit binding upgrade to an earlier published version and is recorded as an immutable binding revision with an upgrade action and change summary.

## 6. Authoring / Versioning

Investor-facing Artifact Library routes are mounted at `/artifact-library` and `/artifact-library/{uuid}`. The API provides draft creation, optimistic-lock draft updates, publish, next-draft, fork, archive, bind/upgrade, export/import, and bundle-plan/deploy operations. The detail page exposes JSON envelope editing for drafts and a distinct “Validate and publish” action.

`ReusableArtifactLifecycleService` enforces owner authorization, one active draft per lineage, SemVer validation, optimistic lock versions, intrinsic envelope validation, and immutable published versions. Attempting to update a published version is rejected by the model/service boundary.

## 7. Package / Dependencies

`ArtifactPackageService` exports a version closure with schema/package format, exact source keys, content, documentation, provenance, definition hashes, and exact dependency references. It computes a checksum over the package payload. Import verifies checksum, content hashes, root identity, dependency presence, and DAG validity before creating imported drafts. Import is intentionally a draft/import workflow; it does not silently publish or bind imported content.

Dependencies are exact version references for artifact dependencies and exact indicator/version references for indicator dependencies. Publication rejects unavailable, unpublished, incompatible, nested-bundle, or cyclic dependency graphs. Existing package tests cover export/import and checksum tampering.

## 8. Binding Model

Bindings are scoped to a `PortfolioProfile` and an artifact lineage. A binding revision pins one published artifact version, settings, enabled/disabled status, usability state, actor, timestamp, and action. `ArtifactBindingService` requires portfolio ownership and artifact access, uses optimistic lock versions, and synchronizes the existing screener/strategy projection in the same transaction.

A new published version does not change an existing binding. An explicit bind/upgrade is required. A blocked version cannot be enabled. Usability is derived from the version's published state and dependency graph; warnings remain distinct from blocked versions.

## 9. Runtime Resolution

`ArtifactRuntimeBindingResolver` selects the active enabled binding for the profile, follows its active revision, requires a published version of the expected type, and rejects missing or malformed definitions. The screener resolver does not fall back to mutable latest content. Disabled, blocked, unmapped, cross-portfolio, unpublished, and invalid evidence paths fail closed.

The screener run service persists the selected `reusable_artifact_version_id` and `artifact_binding_revision_id` before evaluation and revalidates that evidence while reading the definition for the run.

## 10. Provenance / Historical Reproducibility

The representative `ScreenerRun` stores both exact artifact-version and binding-revision IDs. Existing recommendation, order, transaction, backtest, replay, and simulation models also carry the corresponding artifact/binding evidence where their runtime path uses artifacts. This prevents a later rollout or rollback from changing the meaning of an earlier result.

Archive is lineage-level metadata. It does not delete published versions or historical consumer evidence. Published versions are deletion-protected once referenced by binding/runtime foreign keys.

## 11. Representative Artifact Selection

The selected artifact is a holdings-scoped screener. It was chosen because it exercises the largest useful slice of the framework with deterministic local data: authoring, validation, exact indicator dependency, portfolio binding, legacy projection, runtime resolution, observable hits, immutable run evidence, version rollout, rollback, and fail-closed evaluation.

## 12. Baseline v1

`TradingArtifactRolloutAssuranceTest` creates and publishes Screener A v1 with an exact SMA dependency, binds it enabled to a test portfolio, resolves the binding, and runs the real `ScreenerRunService` against a controlled holding and price series. The run completes with a deterministic hit and records v1 plus the initial binding revision.

## 13. v2 Rollout

The test creates v2 through `createNextDraft`, changes only the controlled threshold, publishes it through the real validation/dependency path, and upgrades the existing binding through `ArtifactBindingService`. v1 remains published and its binding revision remains persisted. The binding moves to v2 only after the explicit upgrade.

## 14. Runtime After Rollout

The same consumer input resolves v2 after the upgrade. The new run records v2 and the new binding revision and produces the expected changed result. The earlier v1 run remains pinned to v1 and is not re-resolved through the current binding.

## 15. Rollback

Rollback is exercised through the supported current mechanism: an explicit binding upgrade from v2 back to the prior published v1. The active revision returns to v1, a new immutable revision records the action, and a subsequent run resolves v1 again. Repeating the rollback is a safe explicit no-op in terms of runtime selection; it creates another auditable revision rather than corrupting the binding.

The UI presents this through the version list's existing binding action rather than a separately named “Rollback” button. That is consistent with the current service contract, though browser operator wording remains a runtime/discoverability check.

## 16. Invalid Rollout

An invalid v3 definition with an unsupported screener operator is rejected during publication. The existing good binding remains unchanged, and no invalid published version is available to runtime resolution. This is fail-closed at the lifecycle gate rather than a partially active deployment.

## 17. Dependency Failure

A draft referencing a missing artifact dependency is rejected before publication. The artifact lineage remains with its existing published v1 only, and the active portfolio binding remains on v1. Existing tests also cover blocked usability for unavailable indicator dependencies and reject enabling a blocked version.

## 18. Archive / Historical Evidence

The assurance test archives the artifact lineage after producing a run. The lineage receives `archived_at`; the published version remains published, the active binding remains pinned, and the historical run continues to resolve its stored version relation. The UI explicitly tells the operator that published history and active bindings remain intact.

## 19. Cache / Transactionality

No artifact runtime cache was found in the artifact binding/resolution services; cache invalidation is therefore not applicable to the current implementation. Binding revision changes, projection synchronization, and bundle deployment member updates execute within database transactions. Bundle deployment records a planned state first and changes to completed or failed; stale plan/binding checks prevent partial member application. Existing bundle tests cover atomic deployment and stale-plan failure evidence.

## 20. Authorization / Namespace Boundary

Artifact lifecycle mutation is owner-scoped. Portfolio bindings require the actor to own the target portfolio and to have access to the exact published version. Library access supports owner, factory/system, adoption, and active share-grant paths. Existing tests cover cross-account binding denial, sharing/adoption, and bundle requester checks.

Artifact types and tables use the current artifact model namespace. Namespace governance remains AUD-014 scope; no separate namespace remediation is inferred here.

## 21. Operator UI Reachability

| User goal | Route/component | API | Backend | Status |
| --- | --- | --- | --- | --- |
| List/search artifacts | `/artifact-library`, `ArtifactLibraryPage` | `GET /v1/artifact-library` | `ArtifactLibraryQueryService` | `UI_REACHABLE` |
| Create/edit draft | Library/detail pages | draft `POST`/`PUT` | lifecycle service | `UI_REACHABLE` |
| Validate/publish | Artifact detail | version publish `POST` | validation + lifecycle | `UI_REACHABLE` |
| Inspect versions/dependencies | Artifact detail | artifact detail `GET` | query service | `UI_REACHABLE` |
| Bind/upgrade/disable | Artifact detail | bind/binding mutation routes | binding service | `UI_REACHABLE` |
| Roll back | Artifact detail, select prior published version | binding upgrade route | binding service | `UI_REACHABLE` through explicit prior-version upgrade |
| Export/import | Library/detail pages | package routes | package service | `UI_REACHABLE` |
| Plan/deploy bundle | Artifact detail | bundle plan/deploy routes | bundle deployment service | `UI_REACHABLE` |
| Inspect deployment audit details | API/models/tests; no dedicated detail panel found | deployment routes | deployment service | `API_ONLY` |

The detail page shows current binding status, active version, usability, reasons, version hashes, dependencies, and errors. It does not provide a separate deployment-history dashboard or a separately named rollback control.

## 22. Error / Loading / Recovery

The detail page has explicit loading and error rendering; mutation failures retain the error message and do not replace the artifact with an empty success state. Backend validation, dependency, stale-lock, blocked-usability, stale-bundle-plan, and authorization errors are bounded through API envelopes.

The main remaining UI verification is browser-level confirmation that long JSON/dependency content, binding status, and error recovery remain usable at constrained widths. No static failure-to-empty artifact state was found in the reviewed detail path.

## 23. Test Coverage

Existing suites cover lifecycle immutability/concurrency, binding and projection, runtime exact-version selection, package checksum/import, bundle planning/deployment/atomic failure, sharing, registry APIs, and artifact UI behavior. Added `TradingArtifactRolloutAssuranceTest` covers:

- deterministic v1 runtime result and exact evidence;
- v2 publication and explicit rollout;
- v2 runtime result;
- historical v1 preservation;
- rollback to v1 and repeated rollback;
- invalid publication fail-closed behavior;
- missing dependency rejection;
- archive without historical evidence loss.

Focused artifact validation result: **26 tests passed, 122 assertions** across the new suite and existing artifact lifecycle/binding/runtime/package/bundle suites.

## 24. Runtime Verification Boundary

Repository evidence does not prove deployed behavior for:

- actual queue/job execution if a deployment is asynchronous;
- production package/object storage and filesystem permissions;
- deployment cache/process invalidation outside the current no-cache service path;
- production authorization and operator role configuration;
- deployed browser geometry, constrained-width usability, and rollout recovery discoverability;
- a staged representative rollout against production-like data.

These are deployment/runtime checks, not evidence of a missing artifact lifecycle implementation.

## 25. Gap Register

| ID | Finding | Classification | Severity | Evidence |
| --- | --- | --- | --- | --- |
| ART-001 | Representative lifecycle was previously not covered by one executable current-model scenario | `IMPLEMENTED` | High | `TradingArtifactRolloutAssuranceTest` passes v1, v2, rollback, invalid/dependency failures, archive/history |
| ART-002 | Rollback is not separately named in the UI; it is an explicit upgrade to a prior published version | `ACCEPTABLE_VARIATION` | Low | Service contract and detail-page prior-version binding action |
| ART-003 | Deployed queue/storage/browser rollout drill is not repository-provable | `RUNTIME_VERIFICATION_REQUIRED` | High | Requires deployed-like environment, worker/storage/cache/role checks |
| ART-004 | Dedicated deployment-history/operator evidence panel is absent | `ACCEPTABLE_VARIATION` | Medium | Current detail page exposes binding/version/usability; deployment records remain API/model evidence |

No confirmed wrong-version execution, historical rewrite, unsafe dependency fallback, partial binding transaction, or stale-cache defect was found.

## 26. Remediation Groups

### A — Version/provenance integrity

Completed by immutable versions, exact binding revisions, runtime evidence fields, and the representative assurance suite.

### B — Binding/runtime resolution

Completed by exact active-revision resolution and fail-closed tests for disabled, blocked, unmapped, and incompatible evidence.

### C — Rollout/rollback safety

Completed by explicit upgrade/rollback revisions and atomic bundle deployment tests. Operator wording remains a bounded runtime check.

### D — Dependencies/package portability

Completed by exact dependency validation, DAG checks, package checksums, import/export tests, and missing dependency failure coverage.

### E — Operator visibility/recovery

Current UI is sufficient to inspect versions, binding status, usability, dependencies, errors, and select a prior version. A dedicated deployment-history panel is not required by the current contract and is not proposed here.

### F — Runtime deployment verification

Remain as a staged/deployed drill for workers, storage, role configuration, browser geometry, and production-like rollout recovery.

## 27. Recommended Order

1. Run a staged representative screener rollout with the same v1/v2/rollback assertions against deployed services.
2. Verify queue/worker or asynchronous deployment execution, package storage, cache/process behavior, and audit persistence in the deployed environment.
3. Perform a role-separated Admin/Investor browser walkthrough of artifact authoring, binding, upgrade, rollback-by-prior-version, and failure recovery.
4. Keep namespace inventory and enforcement work under AUD-014 and backtest/replay mode semantics under AUD-008.

## 28. Final AUD-007 Assessment

**Static disposition: `IMPLEMENTED`, with bounded runtime verification retained.**

The representative current-model lifecycle is executable and deterministic. Version immutability, exact dependency gates, binding-controlled runtime resolution, v1/v2 rollout, rollback, historical provenance, archive behavior, invalid publication, missing dependency rejection, package portability, and bundle transactionality are supported by code and tests. No confirmed static artifact rollout or historical-reproducibility defect remains.

The remaining `RUNTIME_VERIFICATION_REQUIRED` work is deployment-only: staged rollout, workers/storage/roles, deployed process/cache behavior, and browser/operator recovery. It does not justify keeping the static AUD-007 verdict open.

## 29. Open Questions

- Which deployed environment will host the staged representative rollout and who owns its rollback approval?
- Are artifact bundle deployments executed synchronously in the deployed environment, or is there an external worker/storage step that needs a separate operational check?
- Should a future operator-facing deployment-history view be added, or is the current binding/version evidence sufficient for the product’s rollout runbook?
