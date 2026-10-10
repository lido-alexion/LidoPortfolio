# V8 FEAT-064 Functional Acceptance Test Plan

Date: 2026-10-10
Status: **IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN**
Epic: [Core Investor Workflow & UX Simplification](../archive/specs/V8-Core-Investor-Workflow-UX-Simplification.md)  
Audit: [FEAT-064 acceptance audit](../audit/V8-FEAT-064-ACCEPTANCE-AUDIT.md)  
Closure sequence: [V8 register §8](../archive/specs/LidoPortfolio-V8-Wishlist.md)

## Purpose

Implementation and exact-pin production adoption are complete on build 493. This plan records completed production gates and remaining broader two-account, membership-change, recommendation-resume, and deployed accessibility checks.

## Gate 0 — stable target

- [x] Production build 493 / commit b06f3fa7001f2848867de53dba60a92c15776f75 confirmed live; no pending migrations.
- [x] Confirmed no deployment/configuration change in progress.
- [x] Re-read active Strategies 3, 4, 5, 7 and enabled bindings 42, 43, 44, 47; active Strategy versions are 34–37.
- [x] Re-read Screener 4 and 5 definitions, semantic hashes and immutable version rows; evidence current as of 2026-10-10 (see audit).

## Gate 1 — immutable Screener snapshot reconciliation

- [x] Trace definition history/audit records to establish the intended semantics of current versions for Screeners 4 and 5.
- [x] Implement and test an idempotent recovery for missing immutable snapshots; do not change existing v1 rows or assign their hashes to current definitions.
- [x] For the known reconciled production state, restore Screener 4 v2 with expected hash `sha256:d3edf5394abd4066e1e894e73abbbe55057c69e0f0ee3915cf116028ffc9bae4`, using its exact immutable v3 snapshot as proof; preserve v1 and v3.
- [x] Restore Screener 5 v2 with semantic hash `sha256:092a4e0ad9d00d5796f0e97de6d4760e2e7db6c59e30c8ab42cf35a542271e02`, using published same-lineage Screener artifact-version row 39 as proof; preserve v1.
- [x] Run each repair with `--dry-run` first. If a proof/hash check fails or a conflicting row exists, stop and refresh the evidence; do not call generic `ensureCurrentVersion()` to fill a version gap, because it can increment the current version.
- [x] Verify exact semantic hash match, safe retry, and unchanged historical run/backtest/recommendation/transaction evidence.
- [x] Recorded immutable rows 10 and 11 and hashes in the audit.
- [ ] If either proof/hash check fails or a conflicting target row exists, stop; do not adopt or fabricate a version.

Executed repair command reference (2026-10-10; retained for audit only). The dry runs passed before the guarded writes; immutable rows 10 and 11 now exist. Do not rerun these production repairs unless a new read-only audit identifies a missing snapshot:

```bash
php artisan v8:repair-screener-version-snapshot --screener=4 --target-version=2 --expected-hash=sha256:d3edf5394abd4066e1e894e73abbbe55057c69e0f0ee3915cf116028ffc9bae4 --proof-version=3 --change-notes='Reconstructed approved v2; exact match to immutable v3 and PO-accepted definition' --dry-run
php artisan v8:repair-screener-version-snapshot --screener=5 --target-version=2 --expected-hash=sha256:092a4e0ad9d00d5796f0e97de6d4760e2e7db6c59e30c8ab42cf35a542271e02 --proof-artifact-version=39 --change-notes='Reconstructed v2 from same-lineage published Screener artifact evidence' --dry-run
```

The target option is named `--target-version` because Artisan reserves `--version` for its own framework version output.

Each corresponding command was executed once without `--dry-run` after its dry run passed. The writes created only the missing immutable snapshot rows; they did not create Screener runs, recommendations or transactions.

## Gate 2 — code and CI

- [x] PR [#72](https://github.com/lido-alexion/LidoPortfolio/pull/72) is merged; its PHP 8.4 backend CI job passed. The guard and regression tests are in master.
- [x] PR #123 PHP 8.4 backend suite passed (2,319 tests; 15,888 assertions; 2 skipped; 5 PHPUnit notices); build 493 passed post-deploy health verification.
- [x] Regression coverage proves unresolved pins block readiness/execution before stale recommendation cancellation or generation.
- [x] Regression coverage proves explicit adoption writes a new immutable Strategy version while preserving historical immutable versions and provenance.
- [x] No readiness guard was weakened; production had no pending migrations.

## Gate 3 — controlled exact-pin adoption

- [x] Confirm valid immutable Screener versions exist for each current definition and capture exact IDs/hashes.
- [x] Through the supported explicit Artifact binding revision flow, adopt exact versions for bindings 42, 43, 44 and 47.
- [x] Recorded revisions 57–60 and Strategy versions 34–37 in the audit.
- [x] Confirmed each active Strategy has non-null exact pins and prior version config/hash remained unchanged.
- [x] Confirmed prior run/backtest/recommendation/transaction history was preserved.
- [x] Fail-closed behavior is deployed after the production bindings were resolved.

## Gate 4 — deployed runtime and investor workflow

- [x] Build 493 recorded; all four Strategy pins resolve to the reconciled Screener versions.
- [x] Deployed readiness and exact-pin runtime checks passed; run IDs and resolver results are in the audit.
- [x] CI regression coverage verifies unresolved Strategies cannot activate or execute or trigger recommendation cancellation/generation.
- [ ] Observe recommendation generation for eligible resolved Strategies in a controlled workflow; no recommendation pipeline was invoked during provenance verification.
- [ ] With two controlled accounts, verify Screener instances remain private and sharing creates a definition copy, not a shared mutable instance.
- [ ] Exercise ordinary Screener create/edit/run/backtest and Strategy create/edit/enable/archive flows without requiring Artifact Library for normal CRUD.
- [ ] Verify multiple active Strategies remain supported and incomplete Strategies remain Setup Required.
- [ ] Verify historical recommendation/transaction provenance remains resolvable after edits, adoption and controlled membership change.
- [ ] Complete responsive, keyboard and screen-reader checks; attach browser/device and assistive-technology evidence.
- [ ] Avoid altering real holdings or creating unintended live trades.

## Evidence and disposition

For each check, record date/time UTC, operator, build/commit, account/fixture IDs, exact version/revision/run IDs, result, and sanitized logs/screenshots. Restore any test-only state and record restoration.

FEAT-064 is **IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN** after implementation, immutable snapshot recovery, exact-pin adoption and deployed runtime proof pass. Keep the listed broader workflow checks open in the audit; do not describe the epic as fully accepted until those checks are completed.
