# StoX WS-1 / WS-2 — Codex Work Packages

**Date:** 2026-09-26
**Status:** IMPLEMENTATION HANDOFF PLAN
**Parent:** `2026-09-26-ws1-ws2-implementation-ready-change-spec.md`

## Goal

Implement the Screener/Strategy workflow overhaul in small, independently testable changes. Do not combine the whole migration into one large change.

Core invariant throughout:

> Simple investor workflow on the surface; immutable, reconstructable provenance underneath.

## WP-01 — Screener semantic version foundation

### Purpose

Make `portfolio_screener_versions` represent the complete immutable investment semantics of a Screener rather than only its condition tree.

### Current verified state

The existing table stores `screener_id`, numeric `version`, `definition_json`, `metadata_json`, `definition_hash`, change notes and timestamps. The original migration hashes only `definition_json`.

### Changes

- Extend immutable version representation to include semantic universe state:
  - `scope`
  - `watchlist_id` when applicable
  - `index_symbol` when applicable
- Prefer explicit columns for queryability plus a single canonical semantic-payload builder used for hashing/comparison.
- Canonical hash covers condition definition + semantic universe selector.
- Update `ScreenerVersioningService` so semantic change creates next numeric version automatically.
- Operational-only changes do not bump semantic version.
- Keep existing version numbers; do not introduce user-entered SemVer.
- Add relation/helper for resolving current ScreenerVersion from the Screener projection.

### Migration rule

For the current version of each existing Screener, current scope/selectors can be backfilled because they describe current state. Do not rewrite older historical versions with today's scope/selectors unless historical evidence proves those values.

Legacy older versions lacking provable universe state must be marked/treated as legacy-partial provenance rather than guessed.

### Tests

- create -> version 1;
- condition edit -> version +1;
- scope edit -> version +1;
- watchlist/index selector edit -> version +1;
- schedule/Telegram-only edit -> no semantic bump;
- old version rows unchanged.

### Exit criterion

Given any current Screener, backend can resolve one exact immutable semantic version and its canonical hash.

---

## WP-02 — Pin live Screener runs to exact versions

### Purpose

Make every new Screener run independently explainable after later edits.

### Changes

- Add nullable `screener_version_id` FK to `portfolio_screener_runs` for migration compatibility, then require it for newly created runs in service logic.
- At run start, resolve exact current ScreenerVersion.
- Persist the version ID before evaluation.
- Resolve definition and universe from the pinned version, not mutable semantic fields on `portfolio_screeners`.
- Preserve existing reusable-artifact-version/binding IDs as supplementary technical provenance.
- Expose version identity in run/evidence DTOs where useful for audit.

### Backfill

Backfill only when deterministic from existing artifact version/binding evidence or trustworthy timestamps/hash. Leave unresolved legacy rows null/flagged rather than guessing.

### Tests

- run pins current version;
- edit Screener after run; old run still resolves old conditions/universe;
- new run uses new version;
- artifact-backed run retains both domain and Artifact provenance.

### Exit criterion

A run never changes meaning when the current Screener is edited.

---

## WP-03 — Version-aware Screener backtests

### Purpose

Stop deleting/mixing historical backtest evidence across Screener semantic changes.

### Current verified state

Persistent backtest days are unique by `(screener_id, as_of_date)`. Backtest jobs snapshot the definition but resolve universe from the mutable Screener. `ScreenerService` clears results when condition/universe fields change.

### Changes

- Add exact `screener_version_id` to backtest job rows.
- Make persistent day/hit identity version-aware.
- Preferred uniqueness: `(screener_version_id, as_of_date)` for day cache; hit rows likewise belong unambiguously to version/date.
- Backtest execution reads condition + universe from pinned ScreenerVersion.
- Remove semantic-edit behavior that destroys all historical cached results for the Screener.
- Current Screener editor defaults to current-version backtest results.
- Historical audit can address old-version results.

### Migration caution

Existing persisted results cannot automatically be assigned to today's version unless evidence proves that version generated them. Preserve or mark legacy results appropriately.

### Tests

- V1 backtest remains after V2 edit;
- V2 computes separately;
- same date may exist for V1 and V2;
- universe changes do not reuse incompatible cached result;
- matrix for current Screener shows current version by default.

### Exit criterion

Backtest cache/evidence cannot cross semantic-version boundaries.

---

## WP-04 — Simplify Screener create/edit domain workflow

### Purpose

Remove Artifact Library ceremony from ordinary investor authoring.

### Backend

- Domain create creates Screener + v1 atomically.
- Domain edit saves directly and invokes semantic versioning automatically.
- Remove Draft/publish/manual-version requirements from ordinary endpoints.
- Replace Artifact-presence editability checks with explicit domain editability/reason/actions.

### Frontend

- Screener list/detail/editor owns Create/Edit/Save.
- No redirect to Artifact Library to edit an account-owned Screener.
- No SemVer field.
- No persisted Draft concept.
- Unsaved edits disappear on cancel/navigation after normal dirty-form warning behavior.
- Read-only UI explains why and what action is available.

### Tests

- create/edit entirely from Screener surface;
- save immediately appears in list/detail;
- account-owned artifact-backed Screener is editable unless a genuine domain restriction applies;
- read-only reason is human-readable.

### Exit criterion

A normal user can create and edit a Screener without knowing Artifact Library, versions or SemVer exist.

---

## WP-05 — Screener definition-copy sharing

### Purpose

Implement the frozen rule: Screeners are private account-owned instances; sharing exposes definition/class information, not a shared instance.

### Changes

- Sharing endpoint/export exposes portable Screener definition + relevant descriptive metadata.
- Recipient action creates a new independent account-owned Screener v1.
- No shared mutable object identity.
- No future source-to-copy or copy-to-source propagation.
- Preserve attribution/source metadata if useful, without coupling lifecycle.

### Tests

- recipient copy gets different Screener ID/version lineage;
- source edit does not alter recipient;
- recipient edit does not alter source.

### Exit criterion

Sharing behaves like `Use this definition to create my own Screener`.

---

## WP-06 — Pin Strategy dependencies to exact Screener versions

### Purpose

Make each immutable StrategyVersion reconstructable.

### Current verified state

`portfolio_tos_strategy_screeners` contains `strategy_version_id` + mutable `screener_id`, but no exact `screener_version_id`.

### Changes

- Add nullable-then-enforced-for-new-writes `screener_version_id` FK.
- When building/saving a StrategyVersion, resolve every Screener dependency to its exact current ScreenerVersion.
- Store both lineage `screener_id` and authoritative historical `screener_version_id`.
- Audit/backtest reconstruction uses exact version ID.
- `min_artifact_version` may remain compatibility metadata but is not provenance identity.

### Backfill

Use deterministic artifact binding/hash/timestamp evidence where possible. Do not assign latest/current version to historical Strategy versions merely for completeness.

### Tests

- dependency pins exact version;
- Screener later edited; old StrategyVersion remains pinned to old ScreenerVersion;
- new StrategyVersion can adopt new ScreenerVersion.

### Exit criterion

Every newly saved StrategyVersion has exact immutable Screener dependencies.

---

## WP-07 — Immutable Strategy Save

### Purpose

Remove in-place mutation of `TradingStrategyVersion.config_json`.

### Changes

- Replace `updateActiveConfig()` mutation semantics with transactional copy-on-write Save.
- Canonicalize audit-relevant Strategy config.
- No-op save does not create version noise.
- Changed save creates new TradingStrategyVersion + dependency rows, then atomically switches `active_version_id`.
- Previous StrategyVersion remains unchanged forever.
- Preserve recommendation references to old version.

### Concurrency

Use transaction/locking or equivalent optimistic guard so two concurrent saves cannot silently fork/overwrite active-version state.

### Tests

- V1 recommendation created;
- Strategy edited -> V2;
- recommendation still resolves unchanged V1;
- current Strategy resolves V2;
- no-op save stays on same version;
- concurrent-save behavior deterministic.

### Exit criterion

No production code path mutates audit-relevant fields of an existing StrategyVersion.

---

## WP-08 — Strategy readiness + multiple-Strategy lifecycle

### Purpose

Separate `can be saved`, `ready for use`, and `enabled`.

### Changes

- Permit incomplete Strategy persistence.
- Compute readiness centrally in backend.
- Return machine-readable missing requirements + user-readable explanations/actions.
- Enable only when ready.
- Support multiple concurrently enabled Strategies.
- Remove obsolete exactly-one/last-enabled restrictions.
- Disable/archive blockers only for genuine unresolved operational obligations.

### Frontend

- show `Setup Required` for incomplete Strategy;
- list missing items directly on Strategy page;
- each missing item leads user to the relevant field/action;
- do not silently disable buttons without explanation.

### Tests

- incomplete save succeeds;
- enable fails with explicit missing requirements;
- completion makes enable available;
- multiple Strategies can be enabled;
- legitimate operational blocker is explained.

### Exit criterion

User never needs to infer why a Strategy cannot be enabled.

---

## WP-09 — Contextual Create Screener from Strategy

### Purpose

Support the common workflow where the needed Screener does not yet exist.

### Flow

`Strategy editor -> Screener selector -> Create new Screener -> Screener editor -> Save -> return -> select created Screener`

### Rules

- preserve unsaved Strategy form state in transient client/navigation state;
- do not persist a Strategy Draft merely to survive navigation;
- cancellation returns without creating/selecting anything;
- direct normal Screener creation remains unchanged.

### Tests

- unsaved Strategy fields survive round trip;
- created Screener is selected;
- cancel path preserves Strategy editor state;
- refresh/browser-loss may lose unsaved state: acceptable under no-persisted-drafts decision.

### Exit criterion

Creating a missing dependency does not force the user to abandon and reconstruct Strategy setup.

---

## WP-10 — Provenance regression and migration gate

### Purpose

Prove the new simple UX has not weakened auditability.

### Mandatory end-to-end scenario

1. Screener V1: ROC > 15.
2. Strategy V1 pins Screener V1.
3. Stock ROC 17 passes run; Recommendation R created.
4. R executed into transaction T.
5. Edit Screener -> V2 ROC > 20.
6. Strategy later adopts V2 -> Strategy V2.
7. Current views show new rules.
8. Audit T/R.
9. Resolve Strategy V1 -> Screener V1 -> original run/evidence -> ROC > 15.
10. Confirm no historical row was rewritten.

Also cover universe selector and membership changes.

### Migration gate

Before enforcing NOT NULL/FK guarantees on legacy provenance columns, produce migration report counts:

- deterministically resolved;
- legacy partial/unresolved;
- invalid/orphaned.

Do not manufacture provenance to make counts look clean.

### Exit criterion

Regression suite demonstrates both UX simplification and historical reconstruction.

---

# Delivery grouping

Recommended delivery groups:

**Group A — provenance foundation:** WP-01, WP-02, WP-03.

**Group B — Screener UX:** WP-04, WP-05.

**Group C — Strategy provenance:** WP-06, WP-07.

**Group D — Strategy UX:** WP-08, WP-09.

**Group E — final gate:** WP-10.

Each group should be green before starting the next. Avoid a single mega-commit.

# Codex operating instructions

For each work package:

1. Re-read the parent decision/spec files before coding.
2. Audit existing tests and migrations first; reuse established naming/route conventions.
3. Implement only that package's scope.
4. Add/adjust automated tests in the same change.
5. Run focused tests, then the broader relevant suite.
6. Report migrations, changed files, tests, compatibility concerns and any unresolved provenance rows.
7. Stop and return to PO/architect review if implementation would remove functionality, weaken auditability, or requires a product-semantic choice not already frozen.
8. Do not reinterpret Artifact Framework internals as investor-facing workflow requirements.
9. Do not add persisted Drafts or manual SemVer.
10. Do not update old historical specs as though the implementation already exists; after rollout verification, update them with dated supersession/history.
