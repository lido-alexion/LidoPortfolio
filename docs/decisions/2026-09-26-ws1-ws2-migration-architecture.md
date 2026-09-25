# StoX WS-1 / WS-2 Migration Architecture

**Date:** 2026-09-26  
**Status:** DESIGN BASELINE — IMPLEMENTATION PENDING  
**Depends on:** Golden workflows, source verification, Screener read-only/sharing semantics

## 1. Executive conclusion

The source confirms that the desired investor UX does **not** require rebuilding Screener or Strategy editors. The principal work is to remove Artifact Library lifecycle leakage and make existing domain pages the façade over immutable audit/version machinery.

However, Screener and Strategy have different current versioning implementations and must not be migrated by blindly applying the same code pattern.

## 2. Screener: current architecture

### Current create path

`ScreenerEditorPage -> POST /screeners -> ScreenerController::store -> ScreenerArtifactRegistry::createFromEditorInput -> create -> LegacyArtifactAuthoringService::createDraft`

The frontend then reports `created as an Artifact Library Draft` and redirects to Artifact Library.

### Current legacy/native path

`ScreenerService::create()` already:

1. validates/normalizes domain input;
2. creates the account-owned Screener row;
3. invokes `ScreenerVersioningService::afterCreate()`;
4. creates immutable version 1 automatically.

`ScreenerService::update()` already:

1. validates/normalizes input;
2. detects definition changes;
3. updates the current Screener;
4. invalidates stale backtest results when needed;
5. invokes `ScreenerVersioningService::afterUpdate()`;
6. creates the next immutable `ScreenerVersion` only when the definition hash changes.

### Current blocker

`ScreenerService::format()` sets:

`compatibility_read_only = reusable_artifact_id !== null`

and `assertLegacyMutable()` rejects update/delete for such rows.

This is implementation provenance being used as domain editability.

## 3. Screener target architecture

### Investor create

`Screeners -> New -> Save`

Target backend façade:

`POST /screeners -> domain authoring service -> account-owned current Screener + immutable v1`

Response returns the current Screener domain object and its Screener URL. The UI remains on/navigates to the Screener, **not Artifact Library**.

### Investor edit

`Screeners -> Screener -> Edit -> Save`

Target behavior:

- validate once;
- update current account-owned Screener;
- if audit-relevant definition changed, create immutable next version automatically;
- keep all historical versions immutable;
- no SemVer input;
- no Draft/publish/bind ceremony;
- no Artifact Library redirect.

### Important versioning detail

The current `ScreenerVersioningService` versions the normalized `definition_json`. Metadata stored with the snapshot includes name/slug/intent/summary/tags/share/factory state.

Implementation must verify whether audit reconstruction requires additional execution-affecting fields such as scope/universe and other settings to be frozen in the immutable evidence. If Recommendation/backtest provenance relies on those fields, version snapshots must be expanded before Artifact Library indirection is removed.

This is an engineering verification item, not a PO question: the principle is already frozen that auditability must remain intact.

## 4. Sharing target

Current code has `sharedVisibleTo()` and imports another profile's Screener through `projectShared()` into an Artifact Draft.

Target semantics are instead:

`source account Screener -> portable definition snapshot -> recipient chooses Create Screener -> new recipient-owned Screener + v1`

The recipient does not acquire or reference the source mutable instance.

Implementation requirements:

- exported/shared material must be a definition/template snapshot;
- recipient creation assigns a new Screener identity;
- recipient history starts independently;
- source provenance may be recorded as metadata for traceability, but not as mutable ownership/dependency;
- source edits never propagate;
- recipient edits never propagate;
- shared source is not listed as an editable account-owned Screener until copied/created.

## 5. Strategy: current architecture differs materially

The Strategy page already saves through a domain-looking endpoint:

`PUT /v1/strategy -> StrategyController::update -> StrategyConfigurationService::updateActiveConfig()`.

But `updateActiveConfig()` currently edits the **existing active TradingStrategyVersion row in place**:

- replaces `config_json` on the current version;
- updates change notes/status/activation;
- leaves the same version identity active.

That conflicts with the auditability principle if historical Recommendations/executions reference that version identity, because its meaning can change after the fact.

It also rejects any Strategy with `reusable_artifact_id`, directing the user back to Artifact Library.

## 6. Strategy target architecture

The Strategy domain page remains the normal authoring façade, but Save must become **copy-on-write immutable versioning**.

Target save transaction:

1. resolve the account-owned Strategy;
2. validate the proposed configuration;
3. compare against current version;
4. if no audit-relevant change, avoid a meaningless version bump;
5. if changed, create a **new immutable TradingStrategyVersion**;
6. sync its exact Screener dependencies/references;
7. atomically move `TradingStrategy.active_version_id` to the new version;
8. retain previous version unchanged;
9. return the newly current Strategy/version to the editor.

This preserves the simple user action `Edit -> Save` while keeping exact historical provenance.

## 7. Strategy readiness is separate from persistence

Saving a Strategy and enabling it are separate concepts.

A Strategy may be persisted while incomplete and shown as **Setup Required** when mandatory operational inputs are missing.

The domain page should explain each missing requirement and link/focus the user to fix it.

When all requirements pass, it becomes eligible for **Enable**.

Multiple Strategies may be enabled concurrently.

The obsolete rule `cannot archive the last enabled Strategy` must be removed. Disable/archive restrictions, if any, must instead come from genuine unresolved operational obligations.

## 8. Screener dependency behavior inside Strategy

Strategies continue to reference Screeners rather than embedding Screener condition trees.

When selecting an eligibility/entry Screener:

- existing Screener -> select it normally;
- missing Screener -> `Create new Screener` launches the normal Screener creation experience;
- after successful creation, return to Strategy context and select the new Screener automatically where practical.

The exact Screener version used for a Strategy version must be reconstructable for audit purposes. Implementation must verify whether current dependency tables pin an exact version or only a mutable Screener ID and strengthen this if necessary.

## 9. Read-only contract

The frontend must no longer derive investor-facing read-only state from `reusable_artifact_id`.

API/domain façade should return explicit editability semantics, conceptually:

- editable;
- reason code/text when not editable;
- allowed next actions.

Valid reasons are domain reasons such as historical immutable version, protected system definition, archive semantics, or authorization. `Managed by Artifact Library` is not a valid reason.

## 10. Artifact Library after migration

Artifact Library can remain as an advanced/internal surface for:

- provenance/history inspection;
- portable import/export;
- definition distribution;
- bundle/dependency inspection;
- administrative/debugging operations.

It is not an ordinary investor CRUD dependency.

The user does not need to understand Draft, publish, binding or SemVer to create/edit a Screener or Strategy.

## 11. Implementation sequencing

### Phase A — audit-safety verification

Before changing UX plumbing:

1. map every consumer of `ScreenerVersion`;
2. verify which Screener fields must be snapshotted for exact reconstruction;
3. map every consumer of `TradingStrategyVersion` and `active_version_id`;
4. verify whether Recommendations/orders/transactions store exact Strategy version IDs;
5. verify Screener dependency pinning inside Strategy versions;
6. identify Artifact Draft dependencies in AI generation/import/backtest/bundle flows.

### Phase B — Screener façade migration

1. make normal POST create a current account-owned Screener with automatic v1;
2. remove Artifact Library redirect/toast;
3. replace artifact-derived read-only guard with domain editability;
4. preserve immutable versions;
5. implement definition-copy sharing semantics;
6. update tests.

### Phase C — Strategy immutable-save migration

1. change `updateActiveConfig()` from in-place version mutation to copy-on-write version creation;
2. preserve exact dependency references;
3. remove Artifact Library edit redirect for current account-owned Strategies;
4. introduce Setup Required/readiness model;
5. remove obsolete last-enabled restriction;
6. add real obligation blockers;
7. update tests.

### Phase D — contextual workflow

1. Create Screener from Strategy;
2. return-to-Strategy context;
3. automatic selection of newly created Screener;
4. actionable missing-requirement UX;
5. version/provenance inspection under secondary actions.

## 12. No new PO questions from this pass

The architecture above follows already-frozen principles:

- simplicity first;
- auditability intact;
- no persisted ordinary drafts;
- private account-scoped Screener instances;
- sharing is definition-copy only;
- multiple Strategies supported;
- domain pages own ordinary CRUD.

Any further PO question should be raised only if source verification reveals a genuine product trade-off that cannot be derived from those principles or existing specs.
