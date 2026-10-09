# V8 FEAT-064 Functional Acceptance Test Plan

Date: 2026-10-06  
Status: **BLOCKED — production Screener snapshot reconciliation and safe pin adoption required**  
Epic: [Core Investor Workflow & UX Simplification](../archive/specs/V8-Core-Investor-Workflow-UX-Simplification.md)  
Audit: [FEAT-064 acceptance audit](../audit/V8-FEAT-064-ACCEPTANCE-AUDIT.md)  
Closure sequence: [V8 register §8](../archive/specs/LidoPortfolio-V8-Wishlist.md)

## Purpose

Use this plan to complete FEAT-064 production acceptance after the build is stable. Local suites and browser checks are recorded in the audit; this plan covers the remaining production lineage, adoption, investor workflow, membership-change and accessibility checks.

## Gate 0 — stable target

- [ ] Record production build ID, commit, schema/migration state and relevant runtime configuration.
- [ ] Confirm no deployment or configuration change is in progress.
- [ ] Re-read active Strategies 3, 4, 5, 7 and bindings 42, 43, 44, 47; record current versions and enabled state.
- [ ] Re-read Screener 4 and 5 current definitions, artifact versions, semantic hashes and every immutable version row/hash.
- [ ] Stop and refresh this plan's evidence if identities differ from the 2026-10-09 read-only observation.

## Gate 1 — immutable Screener snapshot reconciliation

- [ ] Trace definition history/audit records to establish the intended semantics of current versions for Screeners 4 and 5.
- [ ] Implement and test an idempotent recovery for missing immutable snapshots; do not change existing v1 rows or assign their hashes to current definitions.
- [ ] For the known reconciled production state, restore Screener 4 v2 with expected hash `sha256:d3edf5394abd4066e1e894e73abbbe55057c69e0f0ee3915cf116028ffc9bae4`, using its exact immutable v3 snapshot as proof; preserve v1 and v3.
- [ ] Restore Screener 5 v2 with expected hash `sha256:59d3e174bc606b42e291ff06c24301de334356d5175a9a74a5ab7b59245d6cdb`, using published same-lineage Screener artifact-version row 39 as proof; preserve v1.
- [ ] Run each repair with `--dry-run` first. If a proof/hash check fails or a conflicting row exists, stop and refresh the evidence; do not call generic `ensureCurrentVersion()` to fill a version gap, because it can increment the current version.
- [ ] Verify exact semantic hash match, safe retry, and unchanged historical run/backtest resolution.
- [ ] Record resulting Screener version IDs, hashes and repair evidence.
- [ ] If either proof/hash check fails or a conflicting target row exists, stop; do not adopt or fabricate a version.

After Gate 0 reconfirms the same IDs and hashes, run these one at a time in the deployed `current` release. First run with `--dry-run`; proceed only on the exact success message, then repeat without `--dry-run`:

```bash
php artisan v8:repair-screener-version-snapshot --screener=4 --version=2 --expected-hash=sha256:d3edf5394abd4066e1e894e73abbbe55057c69e0f0ee3915cf116028ffc9bae4 --proof-version=3 --change-notes='Reconstructed approved v2; exact match to immutable v3 and PO-accepted definition' --dry-run
php artisan v8:repair-screener-version-snapshot --screener=5 --version=2 --expected-hash=sha256:59d3e174bc606b42e291ff06c24301de334356d5175a9a74a5ab7b59245d6cdb --proof-artifact-version=39 --change-notes='Reconstructed v2 from same-lineage published Screener artifact evidence' --dry-run
```

Repeat the corresponding command without `--dry-run` only after the dry run validates the exact current production state. The command is idempotent and writes no Screeners, Strategies, runs, recommendations or transactions beyond the missing immutable snapshot row.

## Gate 2 — code and CI

- [x] PR [#72](https://github.com/lido-alexion/LidoPortfolio/pull/72) is merged; its PHP 8.4 backend CI job passed. The guard and regression tests are in master.
- [ ] Required backend and frontend CI passes for any follow-up code changes; production deployment remains gated on safe exact-pin adoption.
- [ ] Regression coverage proves unresolved pins block readiness/execution before stale recommendation cancellation or generation.
- [ ] Regression coverage proves explicit adoption writes a new immutable Strategy version and leaves historical versions, bindings, runs, recommendations and transactions resolvable.
- [ ] Verify no guard or migration weakens readiness, changes ordinary CRUD or affects resolved unrelated Strategies.

## Gate 3 — controlled exact-pin adoption

- [ ] Confirm valid immutable Screener versions exist for each current definition and capture exact IDs/hashes.
- [ ] Through the supported explicit Artifact binding revision flow, adopt exact versions for bindings 42, 43, 44 and 47.
- [ ] Record each binding revision and resulting Strategy version ID.
- [ ] Confirm each active Strategy has non-null exact pins and its old versions remain unchanged.
- [ ] Confirm no historical run, recommendation or transaction provenance changed.
- [ ] Do not merge/deploy fail-closed behavior while these active links remain unresolved unless a bounded, approved operational procedure ensures the intended behavior and no surprise recommendation gap.

## Gate 4 — deployed runtime and investor workflow

- [ ] Record deployed build/commit and confirm all four Strategy pins resolve to the reconciled Screener versions.
- [ ] Verify each Strategy consumes Screener runs matching its exact persisted pin.
- [ ] Verify unresolved Strategies cannot activate or execute and do not trigger recommendation cancellation/generation.
- [ ] Verify recommendation generation resumes for eligible resolved Strategies; check logs for provenance errors and confirm unrelated Strategies are unaffected.
- [ ] With two controlled accounts, verify Screener instances remain private and sharing creates a definition copy, not a shared mutable instance.
- [ ] Exercise ordinary Screener create/edit/run/backtest and Strategy create/edit/enable/archive flows without requiring Artifact Library for normal CRUD.
- [ ] Verify multiple active Strategies remain supported and incomplete Strategies remain Setup Required.
- [ ] Verify historical recommendation/transaction provenance remains resolvable after edits, adoption and controlled membership change.
- [ ] Complete responsive, keyboard and screen-reader checks; attach browser/device and assistive-technology evidence.
- [ ] Avoid altering real holdings or creating unintended live trades.

## Evidence and disposition

For each check, record date/time UTC, operator, build/commit, account/fixture IDs, exact version/revision/run IDs, result, and sanitized logs/screenshots. Restore any test-only state and record restoration.

Mark FEAT-064 **COMPLETE** only after all applicable gates pass, immutable history is intact, and the audit and V8 register link to the evidence. If a check fails, record the failure and exact remediation; keep the epic in REVIEW.
