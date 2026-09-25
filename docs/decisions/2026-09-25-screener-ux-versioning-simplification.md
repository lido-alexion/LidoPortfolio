# StoX Decision Record — Screener UX, Ownership, Sharing, Drafts, and Versioning

**Date:** 2026-09-25  
**Status:** PO DECIDED — TARGET BEHAVIOR, NOT YET IMPLEMENTED  
**Scope:** Screener authoring/ownership/sharing UX and its relationship to the Trading Artifact Framework  
**Reason for separate decision record:** These decisions intentionally describe target behavior before implementation. Existing current/frozen specs continue to describe shipped/previously accepted behavior until implementation alignment is completed. This record must be consulted when implementing the Screener golden workflow.

## 1. Context

StoX currently exposes parts of the generic Trading Artifact Framework directly to an investor. In particular, Screener editing can require navigation into Artifact Library, explicit version creation, SemVer input, Draft lifecycle actions, publication and binding concepts.

This preserves strong artifact provenance but imposes implementation bookkeeping on an investor performing a simple domain task such as changing a Screener threshold.

The governing redesign principles are:

1. **Simplicity first.** The investor works with Screeners, Strategies, Recommendations, Executions and Transactions rather than artifact-management machinery.
2. **Auditability intact.** Exact historical definitions/versions used by recommendations, backtests and other historical consumers remain immutable and traceable.
3. **Hide bookkeeping, do not destroy evidence.** UUIDs, SemVer, immutable version records and dependency pins may remain internally even when not presented as authoring inputs.
4. **No silent behavior changes.** Editing a domain object is explicit user intent; unrelated/shared/system updates must not silently alter live strategy behavior.

## 2. Screener ownership

### Decision

A user-created Screener is **private and account-scoped**.

A Screener runtime/use context may be Portfolio-specific where required by existing runtime architecture, but sharing a Screener does not share the user's mutable Screener instance or transfer its runtime identity.

### Sharing semantics

Sharing exposes/copies the Screener's **definition** — its structure, conditions and other reusable definition content — rather than sharing the source user's mutable instance object.

The recipient therefore receives/adopts a private definition/copy/lineage appropriate to the supported sharing flow. Subsequent edits by either party must not silently modify the other party's Screener.

Cross-user sharing must not create a shared mutable Screener instance or silently bind one user's runtime object into another user's Portfolio.

## 3. No persistent Screener Draft concept

### Decision

For ordinary Screener authoring there is **no persisted Draft lifecycle**.

The user flow is:

`Open/Create Screener -> Edit in the page -> Save`

If the user leaves/cancels without saving, those unsaved edits are discarded.

There is no investor-facing requirement to create, name, resume, publish or manage a Screener Draft.

### Internal consequence

The previous generic Artifact Framework rule **"one active Draft per artifact lineage"** must not be required for Screener authoring. If implementation currently requires a Draft record as a transient technical staging mechanism, that is an implementation detail only and must not become a persisted user workflow/state. Prefer removing the persistent Screener Draft dependency when implementing this decision if no other material workflow requires it.

### Known affected functionality

The previous framework allowed AI-assisted Draft creation and some simulation/backtest flows to create unpublished artifact Drafts. When implementing this decision, those flows must be reviewed. They must not reintroduce a persistent Screener Draft UX merely because the generic artifact model previously used Drafts. If a workflow needs a proposed Screener, use an explicit proposal/preview/import confirmation or save-as-new-Screener flow rather than a general persistent Draft lifecycle unless a separate PO decision establishes a real need.

## 4. Save and versioning semantics

### Create

A valid new Screener is created from the Screener domain UI. The application automatically performs required validation and internal version/provenance bookkeeping. The user is not asked to provide SemVer or other technical version identifiers.

### Edit

Editing a saved Screener never mutates historical published evidence in place.

When the user changes a saved Screener and clicks **Save**:

1. validate the new definition;
2. create the next immutable internal version automatically;
3. preserve the previous version for audit/backtest/replay/historical explanation;
4. make the saved definition the current definition for the Screener's own normal domain view/use, subject to exact Strategy dependency rules below;
5. generate technical version identifiers/SemVer automatically.

The user performs **Edit -> Save**, not **Create Draft -> choose SemVer -> publish -> bind**.

## 5. Why historical versions exist

Historical versions primarily preserve **causality and auditability**, not rollback convenience.

Example:

- Screener version used when a recommendation was generated required `ROCE > 15%`.
- The stock had ROCE of 17% and legitimately matched.
- Later the user edits the Screener to require `ROCE > 20%`.
- The old recommendation/transaction must continue to reference the exact historical Screener version containing the 15% threshold.

This allows StoX to answer later: **"Why was this stock recommended/bought at that time?"**

Historical recommendations, candidate evidence, backtests and replays must resolve their pinned historical definition rather than today's mutable/current Screener definition.

## 6. History UX

Version/history management is secondary UX, not part of ordinary Screener editing.

A Screener may expose **History** or **Version history** under a secondary/overflow action. Historical entries are primarily read-only audit snapshots.

A historical Recommendation/Transaction explanation should provide a direct route such as **View Screener used for this recommendation**, resolving the exact pinned historical definition.

Technical SemVer/UUID/hash metadata need not be prominent; it may be available under technical details when useful for support/audit/debugging.

## 7. Restore/rollback

Restoring an older definition is **not a primary requirement** for preserving history.

If a restore convenience is implemented, it must not reactivate/mutate an old immutable version. It creates a **new forward version** whose definition is copied from the selected historical version. Thus chronological history remains append-only and the newest saved version remains the current version.

The same result can always be achieved by manually editing the current Screener back to the desired conditions and saving.

## 8. Strategy dependency boundary remains strict

Editing a Screener must **not silently rewrite an already-published Strategy version's exact Screener dependency**.

A Strategy/recommendation must remain auditable against the exact Screener version it used.

If a Strategy currently uses an older Screener definition and the Screener is edited, the Strategy UX should surface that a newer Screener definition is available and provide a simple explicit update/review path. Adopting that changed dependency creates the appropriate new Strategy version/evidence according to Strategy versioning rules.

This preserves the existing principle that historical/live Strategy behavior cannot change merely because a dependency received a new definition.

## 9. Artifact Library role

Artifact Library may remain as an advanced technical/provenance/distribution surface, but it is **not the mandatory authoring route for ordinary Screener CRUD**.

Normal Screener operations belong on the Screener domain surface:

- Create
- View
- Edit
- Save
- Test/Run
- Duplicate where supported
- Inspect secondary history
- Archive where supported

Artifact Library can expose advanced provenance, immutable versions, dependencies, package/import/export and other framework-level details without forcing those concepts into ordinary Screener editing.

## 10. Superseded/changed prior decisions

This target decision changes or narrows the following previously frozen/general artifact decisions for **Screeners**:

| Previous decision | New target decision |
|---|---|
| Artifact authoring follows Draft -> Published immutable version. | Ordinary Screener authoring has no persisted Draft lifecycle. Create/Edit -> Save; immutable historical versions are generated internally. |
| One active Draft per artifact lineage. | Not applicable as a user-visible/persisted Screener authoring concept. Remove the dependency where no material workflow requires it. |
| Strategy, Screener and Bundle versions use user-supplied/validated SemVer lifecycle. | Screener technical version/SemVer is application-managed; investor does not supply it during ordinary create/edit. |
| Library exposes Draft authoring/publishing as normal artifact editing. | Artifact Library is advanced; Screener domain page owns normal Screener authoring. |
| Generic artifact sharing/grants may expose artifact identities/versions. | Screener sharing exposes reusable definition content, not a shared mutable Screener instance; recipient ownership/use remains private. |

The following prior principles remain intact:

- published/historical versions are immutable;
- historical consumers remain pinned to exact versions;
- rollback, if provided, is a new forward version;
- provenance remains explicit;
- one user's changes must not silently alter another user's Screener;
- Strategy dependencies remain exact/auditable and do not silently cascade when a Screener changes;
- archived historical evidence is retained.

## 11. Implementation review required later

Before implementation, inspect all code/tests relying on persisted Screener Drafts, including:

- Artifact Library draft authoring/publish APIs and UI;
- `ReusableArtifactLifecycleService` draft enforcement;
- AI-assisted artifact creation;
- backtest/replay flows that can emit unpublished artifact Drafts;
- fork/import flows that currently land in Draft state;
- sharing/adoption semantics;
- Screener registry/runtime binding creation and upgrade;
- Strategy dependency upgrade UX.

The implementation may retain generic Draft support for other artifact types if still required. This decision removes the requirement specifically from the ordinary Screener workflow; it does not automatically abolish every Draft concept for every artifact type.

## 12. Documentation migration rule

This file is the dated target decision record until implementation occurs.

When the behavior is implemented and verified:

1. update the canonical `docs/current/**` contracts to the implemented behavior;
2. add dated change/history notes identifying this decision record;
3. mark/supersede the conflicting Screener-specific statements in historical/frozen specs without deleting their historical context;
4. update user journeys to the new implemented workflow;
5. retain this record as the decision history explaining why the change was made.
