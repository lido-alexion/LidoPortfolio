# StoX Audit Provenance Verification — Screener / Strategy

**Date:** 2026-09-26
**Status:** VERIFIED FINDINGS — REMEDIATION REQUIRED BEFORE UX SIMPLIFICATION
**Parent:** `2026-09-26-ws1-ws2-migration-architecture.md`

## Purpose

Verify that simplifying Screener/Strategy authoring will not weaken the provenance chain needed to explain why a historical recommendation or transaction was produced under rules that may differ from today's rules.

Target chain:

`Transaction/Order -> Recommendation -> exact Strategy version -> exact Screener version/run`

## 1. Strategy provenance

### Verified

`TradingRecommendation` persists `strategy_version_id` and relates directly to `TradingStrategyVersion`. Recommendation generation writes the actual Strategy version used. Transactions preserve `recommendation_id`, and transaction ownership resolves through Recommendation -> StrategyVersion first, with `owner_key` as fallback. Orders also preserve `recommendation_id`.

### Critical gap

`StrategyConfigurationService::updateActiveConfig()` currently modifies the existing active `TradingStrategyVersion.config_json` in place.

The FK identity therefore survives, but its historical meaning can change after a later edit. This defeats the reason for preserving `strategy_version_id`.

### Required remediation

Strategy Save must use copy-on-write immutable versioning. Once a Strategy version participates in recommendation/evaluation/execution provenance, its audit-relevant definition must never be edited in place.

This is a P0 auditability correction and should precede or ship atomically with Strategy UX simplification.

## 2. Strategy -> Screener pinning

### Verified

`portfolio_tos_strategy_screeners` stores `strategy_version_id`, `screener_id`, enabled, priority and display order. It does not store `screener_version_id`.

Strategy `eligibility_sources` similarly centers on mutable `screener_id`, with optional `min_artifact_version`. A minimum version is not an exact historical version identity.

`StrategyRegistrySupport::resolveEligibilitySources()` resolves references to the current local Screener row and emits its `screener_id`; it does not pin an exact `ScreenerVersion` row.

### Consequence

An immutable Strategy version can still point to a mutable Screener lineage. Editing that Screener can change the effective meaning of the historical Strategy unless separate technical artifact-binding evidence happens to cover that execution.

### Required remediation

Each immutable Strategy version must be able to reconstruct the exact Screener definition it depended on.

Recommended domain model:

- persist exact `screener_version_id` or equivalent immutable identity per Strategy-Screener dependency;
- retain `screener_id` as lineage/current-domain identity;
- use the exact version for audit/backtest reconstruction;
- when a current Strategy is saved, resolve and freeze the current Screener version into the new Strategy version.

Do not rely only on `min_artifact_version` for audit identity.

## 3. Recommendation eligibility evidence

### Verified

`StrategyEligibilityService::resolve()` records Screener metadata including `screener_id`, name, status, `run_id`, hit count and security IDs. This gives useful evidence that a stock passed or failed a particular run.

### Gap

The eligibility path selects a recent `ScreenerRun` by mutable `screener_id`; the domain dependency itself does not pin `ScreenerVersion`.

`ScreenerRun` currently has `reusable_artifact_version_id` and `artifact_binding_revision_id`, which can pin Artifact Framework runtime provenance. The simplified domain architecture should retain equivalent audit correctness without requiring Artifact Library ceremony.

### Required remediation

Every Screener run should be attributable to the exact immutable Screener definition it executed.

Recommended target:

- persist `screener_version_id` or stable equivalent on every run;
- retain reusable-artifact/binding IDs as additional technical provenance where relevant;
- Recommendation eligibility evidence retains run ID plus exact Screener version identity/hash sufficient for reconstruction.

## 4. Screener snapshot completeness

`ScreenerVersioningService` currently hashes/snapshots only `definition_json` (normalized around `root`) and copies selected descriptive metadata. The live Screener model separately contains scope/universe and operational fields.

Runtime inspection confirms that `ScreenerRunService::resolveStockIds()` directly reads the live `scope`, `watchlist_id`, and `index_symbol` to decide which securities are evaluated. Therefore these are investment/execution semantics, not merely UI metadata.

### 4.1 Fields that MUST be immutable/versioned

These fields can change the set of securities evaluated or the pass/fail result and therefore belong to the immutable Screener execution definition:

- `definition_json` — condition tree and indicator parameters;
- `scope` — e.g. holdings, watchlist, index, all equities;
- `watchlist_id` when `scope=watchlist`;
- `index_symbol` when `scope=index`.

The version hash must cover these semantic fields. A historical run must resolve them from its pinned Screener version, not from the current mutable Screener row.

### 4.2 Descriptive identity metadata

The following are useful for explanation/display but do not themselves alter the investment result:

- `name`;
- `slug`;
- `description`;
- `intent`;
- `summary`;
- `tags_json`;
- `is_factory` / `factory_key` as provenance metadata.

They may be snapshotted with each version for historical readability. They should not be confused with the canonical semantic hash unless intentionally included for artifact identity rules.

### 4.3 Operational fields — mutable without a new investment-rule version

These control when/how the current Screener operates rather than what the rule means:

- `schedule_enabled`;
- `schedule_time`;
- `schedule_days`;
- `telegram_enabled`;
- `last_run_at`.

Changing these should not create a new investment-definition version.

### 4.4 Lifecycle/access fields

These affect availability/governance rather than condition semantics:

- `is_enabled`;
- `is_shared`;
- `artifact_status`.

They should remain mutable lifecycle/access state. Historical runs/recommendations must remain readable even if the current Screener is disabled, unshared or archived/draft-like later.

### 4.5 Technical lineage/binding fields

These support registry/framework integration and should remain separately attributable:

- `artifact_version`;
- `definition_hash`;
- `reusable_artifact_id`;
- run-level `reusable_artifact_version_id`;
- run-level `artifact_binding_revision_id`.

They supplement domain provenance; they do not replace exact domain `ScreenerVersion` identity.

### 4.6 Important dynamic-universe nuance

Versioning `watchlist_id` or `index_symbol` freezes the **selection rule**, not necessarily the membership of that universe forever. A watchlist or index can change membership later.

For a historical run this is acceptable only because the run itself must remain the evidence of what was actually scanned/matched at that time. Therefore the audit contract is two-part:

1. `ScreenerVersion` freezes the rule and universe selector.
2. `ScreenerRun` freezes the execution identity/time and result evidence for that historical execution.

Where exact scanned-universe reconstruction is required beyond matched hits, run evidence/stats should be sufficient or be extended with a universe snapshot/hash rather than pretending `watchlist_id`/`index_symbol` alone freezes membership.

## 5. Orders and Transactions

The Recommendation is the main provenance anchor:

- Orders reference `recommendation_id`.
- Transactions reference `recommendation_id`.
- Transaction Strategy ownership resolves first through Recommendation -> StrategyVersion.
- Recommendation stores `strategy_version_id` and technical Artifact Framework version/binding IDs where applicable.

A separate Strategy version column is not necessarily required on every downstream row if Recommendation remains immutable and retained. The normalized chain is adequate once the referenced StrategyVersion is genuinely immutable.

## 6. Target provenance chain

After remediation:

`Transaction`
-> `Recommendation`
-> exact immutable `TradingStrategyVersion`
-> exact immutable Strategy-Screener dependency
-> exact immutable `ScreenerVersion`
-> exact `ScreenerRun` / eligibility evidence
-> relevant stock/evaluation evidence at generation time.

Artifact Framework IDs may remain additional provenance for imported/system/advanced artifacts, but ordinary auditability must not depend on an investor operating Artifact Library.

## 7. Required changes

### P0

1. Stop mutating `TradingStrategyVersion.config_json` in place.
2. Strategy Save creates a new immutable version on audit-relevant change.
3. Pin exact Screener version identity in each Strategy version's Screener dependency.
4. Pin exact Screener version identity to Screener runs.
5. Expand Screener semantic version/hash to include `definition_json`, `scope`, and the applicable universe selector (`watchlist_id` or `index_symbol`).
6. Screener execution must use the pinned version's semantic definition rather than rereading mutable current semantic fields during a historical/version-pinned run.

### P1

7. Include exact Screener version/run provenance in Recommendation eligibility evidence where it is not otherwise recoverable.
8. Keep descriptive metadata snapshots for human-readable historical explanation.
9. Verify whether exact scanned-universe reconstruction requires a run-level universe snapshot/hash in addition to existing stats/hits.
10. Add tests proving old Recommendations remain explainable after later Screener and Strategy edits.

## 8. Mandatory provenance regression scenario

1. Create Screener S with ROC > 15.
2. Create Strategy A using S.
3. Run eligibility and create Recommendation R for a stock with ROC 17.
4. Execute R and create transaction T.
5. Edit S to ROC > 20 and Save.
6. Save/adopt the changed Screener in Strategy A as applicable.
7. Verify current S/Strategy use the new rules.
8. Audit T/R.
9. Verify the audit resolves the old Strategy version, old Screener version and old Screener run showing ROC > 15.
10. Verify later edits did not rewrite any historical evidence.

Additional universe test:

11. Create a Screener scoped to a watchlist/index and run it.
12. Later change the Screener scope/selector and change membership of the referenced watchlist/index.
13. Verify the historical run still identifies the exact Screener version/selector used and preserves sufficient run evidence to explain the historical recommendation.

## 9. No PO question required

These corrections follow the frozen principle **Simplicity first, auditability intact**. They add no user-facing version-management ceremony; they make the invisible versioning machinery correctly support the simple UI.
