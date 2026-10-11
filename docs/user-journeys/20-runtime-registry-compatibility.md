# Runtime registry compatibility

The current V8 FEAT-064 contract governs these compatibility routes. Validated registry imports create account-owned runtime Screeners or runtime Strategy drafts. They do not require creating/publishing/binding an Artifact Library Draft. The separate reusable Library governance journey remains E2E-31–34. Definitions/version references, ownership and historical attribution remain explicit.

## E2E-54 — Recover the Screener compatibility registry and import a reviewed runtime definition

- Requires: An authenticated Investor and access to the account-scoped Screener compatibility registry.
- Warning: A shared source is read-only. Copy/import creates an owned runtime definition; it does not modify the source, execute an order or qualify market data.

1. Open Screener Registry (`/screeners/registry`). Inspect account/shared/factory ownership and filters. An unavailable list offers Retry without reporting that no Screeners match; a failed filtered read retains known rows with an unavailability warning.
2. Open an owned artifact by its stable identity. An unavailable envelope offers Retry registry artifact and blocks actions requiring that unknown definition.
3. Inspect the definition hash and immutable version history. A failed history read retains the known artifact, explains unavailability and offers Retry registry versions without claiming there are no versions.
4. Export the reviewed definition as portable JSON. An unconfirmed export explains failure; retry preserves definition and ownership.
5. Inspect a shared source through its read-only detail view. Open editor is unavailable and private version history is not requested. Import copy creates an account-owned Screener on My screens; a rejected copy keeps the original source visible and unchanged.
6. Paste Screener registry JSON with the documented condition root. Validate first; a validation failure keeps the JSON and Import disabled. Changing validated JSON invalidates validation.
7. Import the exact validated JSON. An unconfirmed import retains the draft for deliberate retry; confirmed import opens the account-owned runtime Screener editor. Inspect its definition before using it.

**Expected result:** Read, version, export, copy and import recovery preserve source evidence; runtime creation follows current V8 rather than an obsolete Library prerequisite.

## E2E-55 — Inspect portable Strategy registry evidence and deliberately enable or archive its runtime instance

- Requires: An authenticated Investor with account-owned Strategy definitions and eligible Screener references.
- Warning: Enable changes future recommendation eligibility; archive stops future recommendations for that Strategy. Other enabled Strategies and historical holdings/recommendation attribution remain intact. Neither action places an order.

1. Open Strategy Registry (`/strategy/registry`) and inspect enabled/draft/archived runtime entries. An unavailable list offers Retry without reporting that no Strategies match, and a failed filtered read retains known rows. Open a specific artifact's detail by stable identity.
2. Recover an unavailable envelope explicitly. Retry preserves the target identity and does not create or enable a Strategy.
3. Inspect definition hash, dependencies and portable Screener references. Recover unavailable version history separately; known immutable version evidence is preserved.
4. Export portable JSON without portfolio-local Screener ids. Failed export retains the artifact and explains the unconfirmed action; retry downloads the reviewed definition.
5. Enable only after reviewing the intended definition and confirming. Cancelling sends no activation. A rejected activation does not report success or disable another Strategy; review and retry deliberately.
6. Archive only the intended enabled Strategy after confirmation. Cancelling sends no archive; rejection preserves enabled evidence. Confirmed archive preserves other enabled Strategies and past attribution.
7. Paste and validate portable Strategy JSON with eligible Screener references and scoring configuration. Rejected validation/import retains JSON. Successful import creates a runtime Strategy draft and opens its editor for review before explicit enable; a separate Library publish/binding is not required.

**Expected result:** Runtime status and portable immutable source evidence remain distinct, with deliberate cancellation/recovery and no brokerage, cash or protected ML action.
