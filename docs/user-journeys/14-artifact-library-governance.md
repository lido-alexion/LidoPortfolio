# StoX User Journeys — Artifact Library Governance

[Back to journey index](README.md)

These journeys preserve the frozen [Trading Artifact Framework](../archive/specs/V5-FEAT-008-Trading-Artifact-Framework.md). Library ownership is account-level; deployment is an explicit version-pinned Portfolio binding. Synthetic UI envelopes/packages do not qualify validation, checksums, dependency resolution or runtime deployment.

## E2E-31 — Recover Library reads and create/import unpublished drafts

- Requires: An authenticated Investor and a supported artifact envelope/package.
- Warning: Creation/import produces Drafts, never automatic publication, enablement or deployment. Actual portable packages require server checksum/dependency validation.

1. Open `/artifact-library`, filter Search/Type and inspect accessible owned/shared/system provenance. An unavailable read must be reported separately from an empty search; Retry Library after recovery.
2. Choose Create Draft, enter its type, slug, name, SemVer and supported Artifact envelope JSON. Closing the form leaves no source mutation.
3. Correct JSON/API validation failures without losing the draft, then Create Draft explicitly. Inspect the new unpublished lineage.
4. Expand Import validated portable package, paste the package JSON and choose Validate and import as Drafts.
5. Correct syntax/checksum/dependency errors while retaining the reviewed package. Confirm imported Drafts and that nothing was published/deployed.

**Expected result:** Account-accessible Library reads, Draft authoring and validated import remain separate from immutable publication and Portfolio runtime configuration.

## E2E-32 — Save and publish an immutable version with exact dependencies

- Requires: Owner access to an artifact with an editable Draft and its supported schema/dependencies.
- Warning: One active Draft per lineage and optimistic locking prevent lost updates. Published content cannot be edited; later changes require a forward Draft/version. Sharing grants access to an exact published version and dependencies.

1. Inspect `/artifact-library/:uuid`; retry an unavailable initial detail read or return to Library. Inspect provenance, versions, dependencies and any binding before mutation.
2. Edit Draft envelope/documentation/change summary and Save Draft. Syntax or lock/validation errors retain the draft for correction/review; save only against the current reviewed lock version.
3. Choose Validate and publish and review the exact dependency array. Cancelling leaves the Draft. Correct publication validation errors before retrying.
4. Inspect the published version/hash/dependencies and confirm existing bindings remain pinned. Publication alone does not enable or deploy.
5. If sharing is intended, Share only to the intended existing account through the exact published version; cancelled/failed requests do not claim access was granted.
6. For later changes choose New version Draft and a forward SemVer. Inspect the separate unpublished version and preserved published history.

**Expected result:** Optimistically saved drafts, immutable published versions, exact-version grants and later forward drafts retain distinct identity and evidence.

## E2E-33 — Inspect differences and explicitly manage a Portfolio binding

- Requires: Access to published versions and the intended owned active Portfolio.
- Warning: Bind creates disabled deployment settings; Enable is separate. Blocked usability cannot be enabled. A newly published version never upgrades an existing binding automatically.

1. Inspect the desired published version and exact dependencies, then Bind to Portfolio explicitly. Failure leaves the existing binding state available for inspection/retry.
2. Inspect disabled state and usability reasons. Resolve genuine compatibility conditions before enabling; reload to inspect current readiness/lock version.
3. Enable deliberately and verify confirmed binding status. Failed enable/disable retains the previous confirmed status.
4. Compare distinct immutable versions and inspect their structural changes/dependency impact. Equal endpoints cannot compare; retry unavailable comparison without inventing changes.
5. Choose Upgrade binding on the reviewed version. For a concurrent-update conflict, reload current binding state/lock before retrying. Verify explicit forward activation and unchanged published history.
6. Export the intended published version and inspect its portable package. A failed download remains recoverable and does not change binding state.
7. Archive only when intended. Dismiss its history/binding warning to preserve state; after successful confirmation published history and valid active bindings remain intact.

**Expected result:** Read comparison, published package export, explicit disabled binding, enablement, upgrade and archive do not silently alter one another or submit trades.

## E2E-34 — Fork shared versions and confirm a transactional Bundle plan

- Requires: Access to the exact shared/system published source, or a published Bundle and the intended owned Portfolio.
- Warning: Recipients cannot edit/share/publish someone else's source. Fork is a new local unpublished 1.0.0 lineage. Bundle deployment plans are atomic evidence, not perpetual runtime controllers; nested Bundles are unsupported.

1. Inspect `/artifact-library/:uuid` source permission and published definition/dependencies. Fork, name the new slug/lineage, and confirm only the intended operation. Cancelled/failed prompts preserve the source.
2. Inspect the new local Draft and unchanged immutable source. Modification occurs on the fork, not the shared published version.
3. For a published Bundle, choose Deploy Bundle to obtain its server-validated transactional plan. Inspect failure instead of claiming deployment.
4. Review the planned member-count confirmation for the active Portfolio. Dismiss to leave deployment unapplied.
5. Confirm only the intended validated plan. If its apply response is unconfirmed, retain that exact deployment UUID and inspect its read-only status/member results before any further mutation; do not assume a transport error proves rollback. A `planned` result remains pending and requires the existing explicit confirmation; `failed` and `completed` results are evidence and are not replayed.
6. Inspect confirmed deployed member bindings/provenance independently; the Bundle does not become a trading controller.

**Expected result:** Fork identity, plan generation, human confirmation and atomic deployment remain explicit and bounded to the intended account/Portfolio.
