# StoX Trading Artifacts And Runtime Guide

## 1. Purpose And Scope

This is the authoritative current contract for reusable indicator, screener, strategy, and package artifacts. It distinguishes a portable registry artifact from a portfolio runtime instance/binding. It applies equally to human and AI authors; AI has no privileged path.

## 2. Artifact Envelope

Portable artifacts use schema version `1.0` and include `artifact_type`, `slug`, `name`, `metadata`, and `definition`. Optional `dependencies`, provenance, and `minimum_engine_version` may describe portability/runtime context; the latter is exported/documented but is not currently a Validate/Import enforcement gate. Database fields such as `definition_json`/`config_json` are not portable envelope fields.

## 3. Artifact Types

Supported artifact types are `indicator`, `screener`, and `strategy`. Indicators describe deterministic registered capabilities; screeners contain eligibility expression definitions; strategies contain policy/scoring configuration and reference screeners rather than embed screener trees. Packages/bundles group portable artifacts/dependencies for transport/deployment, not a new decision type.

## 4. Artifact Lifecycle

The required lifecycle is **draft -> validate -> publish/import -> bind/use -> fork/version -> archive**. Concrete registry statuses and legacy projections can differ by type, but validation and usable runtime binding are required before use. Registry existence is not runtime activation.

## 5. Draft Versus Published

Drafts are mutable authoring material. Published/imported version records are provenance anchors and must not be silently mutated into a different historical definition. A strategy import creates a draft runtime strategy until explicitly selected/enabled; it does not replace another active strategy.

## 6. Validation

Validation covers envelope/schema/type shape, semantic rules, indicator/parameter catalogue compatibility, dependencies, supported fields, and runtime usability. Unknown fields/operators/ids/parameters and incompatible dependencies are rejected rather than silently ignored. Validate must precede Import; edits after validation require revalidation.

## 7. Dependencies

Artifacts may declare dependencies on compatible indicator/screener/strategy artifact identities/versions. Dependencies must resolve to usable compatible definitions before runtime binding. Missing/archived/incompatible dependency fails closed; runtime must never substitute an arbitrary current/default artifact.

## 8. Runtime Binding

An artifact is a portable/versioned registry definition. A binding ties a portfolio runtime instance to an exact artifact version and revision. Strategies/screeners use runtime selection/resolution to choose a binding. **Registry presence is not runtime activation.** Historical runs preserve binding/version evidence.

## 9. Binding Resolution

Resolution uses the requested/selected runtime binding and verifies artifact/version/dependency usability. Exact pinned version/revision takes precedence for historical use. If a selected binding is disabled, broken, missing, or incompatible, resolution fails closed with diagnostic evidence; it must not fall through to a different artifact.

## 10. Import

Import validates first, normalizes documented aliases/weights where allowed, checks identity/version/dependencies/ownership, and creates a portable/runtime record according to type. Slug collision may be suffixed in the target portfolio. Import does not bypass validation or grant foreign ownership; import Screener before a Strategy that references it by slug/factory key.

## 11. Export

Export produces a portable envelope/package with exact type/schema/definition/metadata/dependency/provenance evidence. Export is the preferred starting point for edits because it preserves compatible optional sections and avoids database-ID coupling.

## 12. Fork

Fork creates a new user-owned draft/version lineage with provenance to its source. It does not mutate the source or move its existing bindings. A fork must validate before import/publish/use.

## 13. Archive

Archive prevents new use/binding according to type lifecycle and removes normal active runtime eligibility. It does not erase versions, binding revisions, historical recommendations, screener runs, backtests, replays, or other provenance. Existing historical evidence remains explainable.

## 14. Upgrade

Upgrade is an explicit binding move to a selected compatible artifact version/revision, validated before activation. No silent auto-upgrade is permitted. Existing historical runs retain their original pin; a new runtime decision/run records the new binding.

## 15. Rollback

Rollback restores a previously usable binding revision/version through explicit binding lifecycle operations. It must validate current usability and retain the failed/abandoned upgrade evidence. Exact public rollback UI/API availability requires implementation-audit verification.

## 16. Disabled Or Broken Binding

Bound artifact archived, missing dependency, schema incompatibility, validation failure, or unavailable runtime implementation yields unusable binding/failed resolution. Consumers must stop/block/report diagnostics rather than select a fallback definition. Historical records continue to reference their original artifact evidence.

## 17. Sharing Model

Artifacts are private user-owned, system-owned, or explicitly grant-accessible. Same-user cross-portfolio use follows library access/adoption/binding rules. Classic shared screeners are same-user-only and import as private copies. Cross-user use requires explicit share-grant semantics; it must never be inferred from a slug, exported JSON, or Admin role.

## 18. Version Resolution

Historical/replay/backtest use exact pinned artifact version and binding revision. Current runtime uses selected/default usable binding only where configured. Compatibility ranges are dependency-validation metadata, not permission to reinterpret a historical pin with a new current version.

## 19. Packaging

Packages/bundles include portable envelopes, dependency information, and integrity/provenance metadata. Deployment validates the package and every item/dependency before applying bindings. A package import/deployment is not proof that its contained artifact is active in a portfolio runtime.

## 20. Deployment And Runtime Availability

Runnable availability requires registry record, validated compatible version, resolved dependencies, deployed/runtime implementation, and a usable selected binding. Registry metadata alone, package storage, or successful export does not make an artifact executable.

## 21. AI Generation Boundary

AI may propose portable draft JSON, suggestions, or explanations. AI output must pass the same schema, semantic, dependency, ownership, and runtime validation as human output before import/binding/use. AI must not invent indicators/operators/sections or bypass governance; it does not make artifacts executable code.

## 22. Security

Artifacts must contain no secrets, credentials, environment paths, executable code, or portfolio-local database IDs needed for portability. Import is an untrusted boundary subject to validation/sanitization. Ownership/share grants and active-profile boundaries apply after validation; Admin authority does not silently grant Investor artifact runtime ownership.

## 23. Data Model

`ReusableArtifact` is registry identity; `ReusableArtifactVersion` is portable version/provenance; dependencies attach compatibility requirements. `ArtifactBinding` selects an artifact for a runtime context and `ArtifactBindingRevision` records changes. `ArtifactShareGrant`, library adoption/access records, package deployment/items, and `TradingArtifactDraft` support ownership, transport, and authoring. Legacy Screener/Strategy versions remain runtime projections with links to reusable artifacts where migrated.

## 24. API Contract

- Registry/create/validate/import/export: `/api/v1/artifacts*`, `/api/v1/screener-registry/*`, `/api/v1/strategy-registry/*`, and indicator routes.
- Library/share/binding: `/api/v1/artifact-library/*`, `/api/v1/artifact-share-grants/*`, `/api/v1/artifact-bindings/*`.
- Lifecycle actions/package deployments: artifact action and bundle deployment routes where exposed.

All artifact operations enforce user/profile ownership, type restrictions, and Admin-only indicator administration where applicable.

## 25. Services

`ArtifactRegistry`/type registries own type-specific persistence; `ArtifactValidationService` owns envelope/semantic validation; `ArtifactPackageService` handles portable package I/O; lifecycle, binding, runtime resolver/usability, library access, sharing, structural diff, legacy projection/backfill, and bundle deployment services own their named boundaries.

## 26. Critical Invariants

- Runtime identifies exact artifact version/binding revision.
- Historical runs retain pinned artifact evidence.
- Import never bypasses validation.
- Broken/missing dependency fails closed.
- Archive never erases historical provenance.
- AI-generated artifacts have no special trust.
- Sharing never bypasses user/profile ownership.
- Registry/package presence is never runtime activation.

## 27. Error And Recovery

Schema/semantic invalidity, unknown field/type/operator, missing dependency, incompatible version, import collision, broken/archived binding, unavailable runtime plugin, upgrade failure, or rollback failure returns actionable validation/usability evidence. Correct/fork/revalidate/reimport or explicitly restore a prior usable binding; never patch historical version evidence or silently fall back.

## 28. Test Anchors

`ArtifactFrameworkTest`, `ArtifactRegistryApiTest`, `ArtifactActionApiTest`, `ReusableArtifactLifecycleTest`, `ArtifactPackageServiceTest`, `ArtifactBundleDeploymentServiceTest`, `ArtifactBindingServiceTest`, `ArtifactRuntimeBindingResolverTest`, `ArtifactBindingUsabilityServiceTest`, `ArtifactSharingServiceTest`, `ArtifactLibraryApiTest`, `ScreenerArtifactRuntimeTest`, `ScreenerRegistryApiTest`, `StrategyRegistryApiTest`, `IndicatorRegistryApiTest`, and `artifact-library-ui.test.mjs` provide anchors for validation, registry/lifecycle, import/export/package, deployment, binding/usability, sharing, and runtime use.

**Test coverage gap — implementation audit follow-up:** complete upgrade/rollback surface, all dependency compatibility permutations, cross-user grant UI/runtime, deployment failure recovery, and every historical pin consumer.

## 29. Debugging Guide

| Symptom | Inspect |
| --- | --- |
| Validate/import fails | Envelope/type/schema, semantic errors, indicator/parameter catalogue, dependencies. |
| Library artifact does not run | Binding selection/revision, usability, runtime resolver, dependency state. |
| Historical run changed | Pinned artifact/version/revision and resolver/as-of evidence. |
| Share unavailable | Owner/grant/adoption/profile scope. |
| Upgrade/rollback fails | Target compatibility, binding revision history, archived/dependency state. |

## 30. Implementation Alignment Notes

Verify under V1-V7 audit: exact lifecycle state transitions by artifact type, published immutability enforcement, all upgrade/rollback APIs/UI, dependency range semantics, package/deployment runtime availability, share-grant reachability, and full historical pinning across strategy/screener/backtest/replay consumers.

## 31. Historical Context

V5 introduced the trading-artifact framework, packages, library, sharing, bindings, and runtime pinning. Later strategy/screener integration and V7 analysis use the same governance boundary. Current behavior is defined here and in [Discovery, Screeners, And Registries](./discovery-screeners-registries.md), not by legacy authoring narration.
