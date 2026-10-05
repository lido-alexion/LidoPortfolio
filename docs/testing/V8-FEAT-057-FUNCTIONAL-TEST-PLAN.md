# FEAT-057 ML Feature Engineering, Training & Validation — Functional Acceptance Plan

**Implementation status: IMPLEMENTED. Overall acceptance: OPEN.** This plan tracks production and investor-facing acceptance; it does not authorize model promotion.

## Implemented scope and evidence

- The frozen FEAT-057 code is merged through [PR #46](https://github.com/lido-alexion/LidoPortfolio/pull/46) (dated, evidence-backed historical identity resolution) and [PR #47](https://github.com/lido-alexion/LidoPortfolio/pull/47) (governed multi-date apply completion).
- PR #46’s parser v5 replay passed the 90% mapping floor across all 360 dates, with 93.4096% minimum coverage. Production preview run 4 also passed the mapping floor on all 360 dates.
- The implementation provides versioned/PIT-safe feature profiles, horizon-specific datasets, training-only preprocessing, repeated chronological validation, candidate evidence/archive integrity, baseline comparison, and 1m/3m/6m adapter training. The audit records bounded real-adapter campaign evidence and CI results for both merged code changes.
- FEAT-056 owns lifecycle scheduling, production promotion/rollback and live drift. FEAT-057 does not activate a model.

## Production acceptance gates

### 1. Recover the governed data apply safely — COMPLETE

- Run 5 used the verified release containing PR #47 and completed a fresh governed apply for the 360-date source set.
- Run 4 and its valid 2022-11-04 boundary remain intact; run 4 was not forced, resumed or reused.
- Run 5's parser 5 results cover all 360 dates, with minimum mapping of 93.4096% (above the unchanged 90% gate).
- The existing first boundary was recognized idempotently; run 5's snapshot and membership digests match run 4's boundary. Durable cursor and processed-date evidence both equal 360/360. See the [production audit continuation](../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md#production-governed-apply-and-current-build-readiness--2026-10-05).

### 2. Verify post-apply data readiness

- Run the fresh campaign preflight after apply.
- Confirm required point-in-time membership, breadth, sector, price and FEAT-054 fundamental coverage gates for every horizon.
- Stop if preflight is blocked. Do not train from partial membership/fundamental coverage or substitute current-universe data.

### 3. Verify production training evidence for each horizon

Only after readiness passes:

- Run the approved 1m, 3m and 6m campaigns through the real adapter.
- Verify dataset/profile identity, train/validation/test coverage, purge/embargo and training-only preprocessing.
- Verify calibration, deterministic-baseline comparison, candidate metrics, immutable evidence archive and artifact reload/explainability.
- Compare against the deployed active model where one exists. Record explicit no-active-model semantics where none exists.
- Confirm candidates remain candidates; do not promote or roll back through FEAT-057.

### 4. Investor-facing acceptance

- Verify the deployed investor-facing ML score/explanation uses the pinned feature profile and exposes understandable signed drivers and evidence provenance.
- Check loading, unavailable-data and rejected-candidate states; confirm no stale/current-universe fallback is presented as historical evidence.

## Current known blocker

Run 4's partial apply defect is preserved as historical evidence; run 5 has since completed the fresh 360/360 governed apply. The remaining gate is the new current-build campaign preflight, which is queued but awaiting the inactive dedicated acceptance worker. A prior build-393 preflight showed 1m canonical dataset/coverage gaps and missing 3m/6m core fundamentals; current blockers must be confirmed by running the build-436 preflight. No production training has run from a current qualified campaign. Detailed runtime evidence remains in the [acceptance audit](../audit/V8-FEAT-057-ACCEPTANCE-AUDIT.md).

## Exit criteria

- Fresh governed apply completes all 360 dates with provenance and the frozen mapping threshold intact. **Passed by run 5.**
- Post-apply preflight passes all frozen data-quality gates. **Pending current-build preflight** (campaign `ee93e3a6-5740-4dea-bfef-c109abc90a69`).
- 1m/3m/6m production evidence, active-model comparison where applicable, immutable artifacts and investor-facing acceptance are recorded.
- No model was automatically promoted or rolled back.

Only then should FEAT-057 move from **IMPLEMENTED** to **COMPLETE**.
