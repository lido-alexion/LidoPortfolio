# StoX WS-1 / WS-2 Source Verification

**Date:** 2026-09-26  
**Status:** VERIFIED SOURCE FINDINGS — INPUT TO REDESIGN SPEC  
**Parent:** `2026-09-26-current-vs-golden-ux-gap-analysis.md`

## 1. Scope

This pass verifies the highest-priority Screener/Strategy UX gaps against the current source tree. The goal is to distinguish observed product behavior from assumptions before freezing implementation work.

## 2. Verified findings

### SV-001 — Screener list deliberately converts Edit to Inspect for mapped artifacts

**Source:** `app/resources/js/src/components/screener/ScreenerMyScreensTab.jsx`

Current action rendering is explicitly:

- `compatibility_read_only ? 'Inspect' : 'Edit'`;
- Delete is disabled for `compatibility_read_only`;
- tooltip says `Managed by the Artifact Library`.

**Classification:** architecture/UX gap, not an accidental rendering bug.

**Target:** every account-owned Screener is edited from the Screener domain page. Internal artifact mapping must not turn ordinary Screener editing into Inspect-only UX.

---

### SV-002 — Screener Editor deliberately redirects mapped definitions to Artifact Library

**Source:** `app/resources/js/src/pages/ScreenerEditorPage.jsx`

For `form.compatibility_read_only`, the page displays:

> This is a read-only runtime projection. Draft, publish, and explicitly upgrade it in the Artifact Library.

and exposes `Open artifact`.

**Classification:** architecture/UX gap confirmed exactly as reported by the PO.

**Target:** remove this ordinary-user branch. The same page must provide Edit/Save while backend services preserve immutable versions and exact provenance.

---

### SV-003 — Artifact Library exposes technical lifecycle directly

**Source:** `app/resources/js/src/pages/ArtifactLibraryPage.jsx` and Artifact Library services/tests.

Current creation state includes `semver: '1.0.0'`, creates `/v1/artifact-library/drafts`, and uses Draft/publish/binding lifecycle concepts.

**Classification:** valid advanced/internal framework, wrong abstraction level for ordinary investor authoring.

**Target:** retain framework where useful for provenance/import/distribution, but ordinary Screener/Strategy CRUD must not require direct use of it.

---

### SV-004 — Screener domain already has a mature editor worth preserving

**Source:** `ScreenerEditorPage.jsx`.

The editor already owns domain concepts including:

- name/description;
- universe scope;
- watchlist/index selection;
- nested AND/OR condition editing;
- indicator parameters/constants;
- schedule controls;
- validation;
- run history/backtesting/compare utilities.

**Classification:** important implementation simplification.

**Target:** do not build a second Screener authoring UI in Artifact Library. Make the existing Screener Editor the authoritative investor-facing façade and change persistence/version plumbing behind it.

---

### SV-005 — Screener list currently allows Run even when Edit is replaced by Inspect

**Source:** `ScreenerMyScreensTab.jsx`.

Mapped runtime projections can be run directly from Screeners, but cannot be edited there.

**Classification:** mental-model inconsistency.

**Target:** a Screener visible as `My screen` should behave as one coherent domain object: View/Edit/Run/History/Archive from the same domain surface, subject to real permissions and dependencies.

---

### SV-006 — Current Delete wording conflicts with auditability goals

**Source:** `ScreenerMyScreensTab.jsx`.

The confirmation says:

> Delete this screener and its run history?

**Classification:** likely contract/UX conflict requiring implementation audit.

**Target:** ordinary lifecycle should prefer Archive/Retire while preserving historical evidence. Hard deletion, if retained at all, should be limited to objects with no historical/audit dependencies and have explicit rules.

---

### SV-007 — Strategy page has the same Artifact Library read-only projection problem

**Source:** `app/resources/js/src/pages/StrategyPage.jsx`.

The page derives `compatibilityReadOnly = Boolean(meta.compatibility_read_only)` and shows the same message directing users to Draft/publish/upgrade in Artifact Library.

**Classification:** architecture/UX gap confirmed.

**Target:** Strategy page becomes authoritative normal editor; version publication/binding is internal bookkeeping.

---

### SV-008 — Last-enabled Strategy restriction is implemented in both frontend and backend

**Frontend source:** `StrategyPage.jsx`

`canArchive = currentEnabled && enabledCount > 1`.

**Backend source:** `app/app/Services/Strategy/StrategyRegistrySupport.php`

When archiving an active Strategy, backend searches for another active sibling and throws:

> Cannot archive the last enabled strategy. Enable another strategy first.

**Classification:** confirmed obsolete business rule, not merely stale documentation.

**PO decision already available:** multiple Strategies are supported; the single/last-enabled rule is obsolete.

**Target:** remove this rule. Replace it with real operational safety checks: owned positions requiring management, live actionable Recommendations, Pending Execution/broker work, reservations, or other unresolved obligations.

**No further PO question required for removal of the obsolete count-based rule.** Exact obligation blockers can be derived from existing lifecycle/safety contracts and reviewed as implementation detail unless a genuine product choice emerges.

---

### SV-009 — Multi-Strategy enablement itself is already additive

**Source:** `StrategyRegistrySupport::activate()`.

Activation makes the selected Strategy/version active and supersedes other versions of the *same Strategy*; it does not disable sibling Strategies.

**Classification:** backend semantics already align with the PO's multiple-Strategy decision.

**Target:** preserve this behavior. Remove only contradictory last-enabled assumptions and confusing UI/docs.

---

### SV-010 — Strategy dependencies are intentionally references, not embedded Screener copies

**Source:** `StrategyRegistrySupport::resolveEligibilitySources()`.

The service resolves Screener references in the active portfolio and explicitly states strategies never embed Screener definitions.

**Classification:** correct architectural boundary.

**Target:** preserve separation. Contextual `Create Screener` from Strategy should create a normal Screener and then bind/select its exact version/reference; it should not embed a condition tree into Strategy.

---

### SV-011 — Existing portable import/export architecture can remain advanced functionality

**Source:** `StrategyRegistrySupport::toPortableDefinition()` and Registry/Artifact services.

Portable definitions deliberately strip portfolio-local IDs and retain portable Screener references.

**Classification:** useful technical/distribution capability; no reason to expose it in routine CRUD.

**Target:** keep under advanced Import/Export/Share tooling. Do not let portability requirements dictate everyday authoring UX.

## 3. Decisions now considered implementation-ready

The following no longer require PO questions because they follow directly from prior decisions plus verified source:

1. **Screener ordinary CRUD stays in Screeners.**
2. **Strategy ordinary CRUD stays in Strategies.**
3. **Artifact Library is not a prerequisite for ordinary investor CRUD.**
4. **SemVer is never entered by an ordinary investor.**
5. **No persisted ordinary Screener Draft workflow.**
6. **Immutable historical versions remain.**
7. **Mapped runtime projections must not force Inspect-only domain UX.**
8. **Multiple sibling Strategies may remain enabled concurrently.**
9. **Remove the count-based last-enabled Strategy archive restriction.**
10. **Strategy still references Screeners rather than embedding their definitions.**
11. **Import/export/share can remain advanced features using the existing artifact/portable-definition architecture.**

## 4. Items still requiring code verification before freezing implementation tickets

These are engineering-discovery questions, not PO questions yet:

1. identify the exact persistence/API path used by Screener Editor for mapped vs unmapped Screeners;
2. determine the safest service façade for `Save Screener -> create immutable internal version -> make current` without exposing Draft/publish/bind;
3. identify every dependency currently expecting an Artifact Draft record (AI generation, backtest proposals, imports, bundles);
4. identify Strategy page save/create APIs and how to wrap version creation transactionally;
5. inventory all Strategy archive/disable endpoints and tests enforcing last-enabled behavior;
6. identify current data needed to calculate operational blockers for Disable/Archive;
7. verify whether hard Screener deletion is currently allowed after runs/recommendations/backtests and what foreign-key behavior exists;
8. identify exact Screener-version dependency data needed for `Update available` and diff/adopt UX.

## 5. Implementation direction

### Screener façade

Investor flow:

`Screeners -> New/Edit -> Save`

Save must internally perform the required validation/version/provenance operations atomically. User sees success or actionable validation errors, not SemVer, Draft, publish or binding steps.

### Strategy façade

Investor flow:

`Strategies -> New/Edit -> Save -> Enable when ready`

Save creates immutable policy evidence internally. Incomplete but structurally valid configuration may be represented as `Setup Required`; operational enablement remains blocked until readiness checks pass.

### Advanced Artifact tooling

Artifact Library may remain available for:

- provenance inspection;
- technical version/history inspection;
- portable import/export;
- sharing/distribution workflows;
- bundle/dependency inspection;
- administrative/debugging use.

It must not be the normal place an investor is sent merely to edit a Screener or Strategy.
