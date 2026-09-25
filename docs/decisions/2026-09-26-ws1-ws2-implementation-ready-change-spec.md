# StoX WS-1 / WS-2 Implementation-Ready Change Specification

**Date:** 2026-09-26
**Status:** READY FOR IMPLEMENTATION PLANNING — CODE NOT YET CHANGED
**Inputs:** Golden workflows, source verification, migration architecture, audit provenance verification

## 1. Objective

Make ordinary Screener and Strategy workflows understandable from their own domain pages while preserving exact historical evidence invisibly underneath.

Investor mental model:

- I create/edit a Screener on the Screener page.
- I create/edit a Strategy on the Strategy page.
- I press Save.
- StoX handles immutable versions automatically.
- If something cannot be edited/enabled/deleted, StoX explains why and what I can do next.

No ordinary investor workflow requires Artifact Library, Draft lifecycle, publish/bind steps or manual SemVer input.

## 2. WS-1 — Screener domain authoring

### 2.1 Create

**UI:** `Screeners -> New Screener -> fill domain fields -> Save`

**Backend target:** create the account-owned Screener and immutable ScreenerVersion v1 in one successful domain operation.

After Save:

- show the created Screener;
- it appears immediately in the account's Screener list;
- no Artifact Library redirect;
- no Draft/publish ceremony;
- no version-number input.

### 2.2 Edit

**UI:** `Screeners -> Screener -> Edit -> Save`

On Save:

- validate proposed domain state;
- if semantic investment definition changed, update the current Screener projection and create the next immutable ScreenerVersion automatically;
- if only operational/descriptive state changed, do not create a meaningless semantic version unless historical-display requirements explicitly require it;
- historical versions remain immutable.

### 2.3 Semantic Screener version

Canonical semantic payload must include at least:

```text
definition_json
scope
watchlist_id when scope=watchlist
index_symbol when scope=index
```

The semantic hash/version comparison must operate over this canonical payload rather than `definition_json` alone.

Descriptive metadata may accompany the snapshot for readability without defining investment semantics.

Operational fields such as scheduling/Telegram do not create investment-rule versions.

### 2.4 Run pinning

Add an exact immutable Screener-version reference to each ScreenerRun.

At run creation:

1. resolve current ScreenerVersion;
2. store its exact ID on the run;
3. use that pinned version's semantic definition for execution;
4. retain Artifact Framework version/binding IDs as additional provenance where applicable.

Do not begin a run whose semantic version cannot be resolved consistently.

### 2.5 Backtest pinning and cache correctness

Current backtest jobs snapshot the condition definition but resolve the universe from the current mutable Screener and persistent per-date cache is keyed by Screener/date. That can mix results across semantic versions.

Target:

- each backtest job pins exact `screener_version_id`;
- backtest execution resolves both condition definition and universe selector from the pinned version;
- persisted backtest day/hit cache must be version-aware, e.g. keyed by `(screener_version_id, as_of_date)` or an equivalent semantic-definition identity;
- changing a Screener creates a new semantic version rather than deleting historical evidence for the old version;
- current-editor display may default to results for the current Screener version only;
- historical audit/backtest can still address old-version results.

This replaces the current conceptual model of clearing all results for the mutable Screener when conditions/universe change.

### 2.6 Sharing

`Share` exposes a portable definition/template snapshot, never a shared mutable Screener instance.

Recipient flow:

`Inspect definition -> Create Screener from this definition -> independent account-owned Screener v1`

No later propagation in either direction.

### 2.7 Read-only

Remove `reusable_artifact_id != null` as the investor-facing editability rule.

Return explicit domain editability and reason/action information. Valid reasons include historical immutable version, protected system definition, authorization and defined archive semantics.

Every read-only presentation answers:

- Why can't I edit this?
- What can I do instead?

## 3. WS-2 — Strategy domain authoring

### 3.1 Save becomes immutable copy-on-write

Current active StrategyVersion must never be rewritten for an audit-relevant configuration change.

Target transaction:

1. load Strategy + current active version;
2. validate proposed configuration;
3. resolve all Screener dependencies to exact current ScreenerVersion IDs;
4. canonicalize audit-relevant Strategy configuration;
5. if unchanged, avoid meaningless version creation;
6. if changed, create new immutable TradingStrategyVersion;
7. create/copy its exact dependency rows;
8. atomically set Strategy `active_version_id` to the new version;
9. leave previous version and dependencies untouched;
10. return new current Strategy state.

### 3.2 Strategy-Screener dependency

Extend dependency storage so every StrategyVersion -> Screener relation contains:

- Screener lineage/domain ID;
- exact immutable ScreenerVersion ID;
- role/priority/enabled/display-order information already required by Strategy semantics.

The mutable Screener ID is useful for navigation. The exact version ID is authoritative for audit/reconstruction.

### 3.3 What happens when a Screener is later edited

Editing Screener S creates S-vNext but **does not rewrite existing Strategy versions**.

For the current Strategy, the product may indicate that a newer Screener definition exists. When the Strategy is next saved/adopts that definition, StoX creates a new Strategy version pinning the newer Screener version.

Historical Strategy versions remain pinned to their original Screener versions.

There must be no silent retroactive change to the meaning of an old Strategy version.

### 3.4 Readiness vs persistence

A Strategy may be saved while incomplete.

State shown to user: **Setup Required** (wording may be refined in implementation).

The page lists missing mandatory requirements with direct actions/focus points. Once requirements pass, the Strategy becomes eligible for Enable.

Saving, readiness and enabling are separate concepts.

### 3.5 Multiple Strategies

Multiple Strategies are supported and may be enabled concurrently.

Remove obsolete assumptions that require exactly one enabled Strategy or prohibit archive/disable merely because it is the last enabled Strategy.

Only genuine unresolved operational obligations may block disable/archive, and the UI must explain those obligations.

### 3.6 Create Screener in Strategy context

When a required Screener does not exist:

`Strategy editor -> Create new Screener -> normal Screener editor -> Save -> return to Strategy -> newly created Screener selected`

Preserve unsaved Strategy form state during this contextual round trip using transient UI/navigation state; do not introduce persisted Draft versions merely for this purpose.

## 4. Provenance contract

After implementation, a historical recommendation-driven transaction must support:

```text
Transaction / Order
  -> Recommendation
  -> exact TradingStrategyVersion
  -> exact Strategy-Screener dependency
  -> exact ScreenerVersion
  -> exact ScreenerRun / eligibility evidence
  -> evaluation evidence
```

Later edits must not mutate any node already referenced by this historical chain.

## 5. Data migration/backfill

Existing rows require safe migration.

### Screener versions

For each existing Screener lineage:

- preserve existing ScreenerVersion rows;
- generate/backfill canonical semantic snapshots where reconstructable;
- map current Screener to a current immutable semantic version;
- do not fabricate historical scope/universe values when they cannot be proven from stored evidence; mark provenance quality/legacy limitation where necessary.

### Strategy versions

Existing TradingStrategyVersion rows become immutable from migration onward.

Existing Strategy-Screener dependencies need exact ScreenerVersion backfill where deterministically recoverable from Artifact version/binding evidence or timestamps. Where exact historical resolution is impossible, retain the legacy relationship and mark it as legacy/unresolved rather than falsely asserting precision.

### Runs/backtests

Backfill exact ScreenerVersion IDs when deterministic evidence exists. Never guess an historical version merely because it is the current/latest version.

## 6. API compatibility

Prefer keeping current investor-facing route shapes where practical (`/screeners`, `/v1/strategy`) so the migration is behavioral rather than gratuitous API churn.

Responses should evolve toward domain concepts:

- current entity;
- current immutable version identity;
- editable + reason/actions;
- readiness + missing requirements;
- provenance references where relevant.

Artifact Framework identifiers can remain available to advanced/admin surfaces but should not drive ordinary investor UX wording.

## 7. Required tests before rollout

### Screener

- create -> v1 automatically;
- semantic edit -> v2 automatically;
- schedule-only edit -> no semantic version bump;
- scope/index/watchlist selector edit -> semantic version bump;
- old version remains immutable;
- run pins exact version and executes that version;
- shared definition copy creates independent recipient Screener;
- account-owned current Screener is not read-only merely because Artifact provenance exists.

### Strategy

- edit/save creates a new version, not in-place mutation;
- no-op save does not create noise;
- each dependency pins exact ScreenerVersion;
- later Screener edit does not alter historical StrategyVersion meaning;
- multiple enabled Strategies work;
- incomplete Strategy saves as Setup Required;
- Enable blocked with explicit missing requirements;
- contextual Create Screener returns and selects it.

### End-to-end audit

Use the frozen ROC 15 -> recommendation/execution -> ROC 20 scenario and prove the old transaction still resolves ROC 15 evidence.

Also test universe changes and later watchlist/index membership changes.

## 8. Implementation order

1. Add/expand immutable Screener semantic-version model and hashing.
2. Add ScreenerVersion pinning to runs/backtests and make backtest cache version-aware.
3. Migrate Screener create/edit away from Artifact Library lifecycle.
4. Implement definition-copy sharing.
5. Add exact ScreenerVersion pinning to Strategy dependencies.
6. Convert Strategy Save to immutable copy-on-write.
7. Add readiness/missing-requirement contract and remove obsolete single-Strategy assumptions.
8. Implement contextual Create Screener return flow.
9. Run provenance regression suite.
10. Only after verified implementation, update/strike obsolete older specs with dated history rather than erasing the decision trail.

## 9. Explicit non-goals for this implementation phase

- Playwright/Selenium automation scripts;
- automation selectors/test IDs except those naturally needed by implementation;
- broad visual redesign unrelated to these workflows;
- removal of Artifact Framework internals;
- removal of historical versions;
- user-managed Draft versions;
- manual SemVer entry.

These can be addressed in the later automation/UX-hardening phase already registered for V9 where applicable.

## 10. PO decisions

No new PO decision is required by this specification. It derives from already-frozen principles and source-verified behavior. Any implementation discovery that would remove functionality, weaken provenance, or require a product-semantic choice must be returned to PO review before implementation proceeds.
