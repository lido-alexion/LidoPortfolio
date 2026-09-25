# StoX Screener Read-only And Sharing Semantics

**Date:** 2026-09-26  
**Status:** TARGET DECISION — IMPLEMENTATION PENDING  
**Related:** `2026-09-25-screener-ux-versioning-simplification.md`, `2026-09-26-golden-core-investor-workflows.md`, `2026-09-26-ws1-ws2-source-verification.md`

## 1. Purpose

Clarify when a Screener may legitimately be read-only and correct the sharing model before the WS-1 redesign is implemented.

## 2. Core rule: read-only must have a domain reason

`Read-only` is a consequence, not an explanation.

Whenever StoX prevents editing, the UI must state:

1. **why this particular object cannot be edited**;
2. **what the user can do instead**.

Internal implementation language such as `Managed by Artifact Library`, `compatibility projection`, artifact binding, or version plumbing is not a valid investor-facing reason.

A normal current account-owned Screener should ordinarily be editable from the Screener domain page.

## 3. Legitimate read-only cases

### 3.1 Historical immutable Screener version

Reason shown to user:

> This is a historical Screener definition retained to explain past decisions. Historical versions cannot be changed because doing so would alter the audit trail.

Actions may include:

- **View current Screener**
- **Create a new Screener from this definition** where useful
- **Use as basis for a new current version** only if such a convenience is explicitly implemented; the historical record itself remains immutable

### 3.2 System/factory definition

If StoX ships protected factory/system definitions, the source definition may be read-only because it is system-owned.

Reason:

> This is a StoX-provided Screener definition. The original is protected so product defaults remain stable.

Action:

- **Create Screener from this definition** / **Make my copy**

The resulting account-owned Screener is independent and editable.

### 3.3 Archived account-owned Screener

Whether an archived Screener itself is read-only depends on final archive semantics. If archived objects are immutable while archived, explain that state explicitly and offer the valid recovery action, such as **Restore**, only when operational rules permit it.

Archive must not be used as a substitute explanation for Artifact Library ownership.

## 4. Sharing model — no shared mutable Screener instance

A Screener instance is private and scoped to its owning account/profile.

**StoX does not share the mutable Screener instance between accounts.**

Sharing exposes only a portable **Screener definition/template** containing the reusable domain definition required to reproduce it, such as applicable conditions, structure and parameters. Account-local runtime identity/state is not shared as a jointly mutable object.

Recipient journey:

1. User A chooses to expose/share a Screener definition.
2. User B can inspect that definition.
3. User B selects **Create Screener from this definition**.
4. StoX creates a **new account-owned Screener instance** for User B.
5. User B's new Screener receives its own identity, provenance and version history.
6. User B can edit it normally.
7. Later changes by User A do not propagate to User B.
8. Later changes by User B do not affect User A.

Therefore **sharing is never a reason for an account-owned Screener to be read-only**.

## 5. Source implications already observed

Current source still contains concepts that conflict with this target:

- `ScreenerService::format()` exposes `is_shared` and derives `compatibility_read_only` solely from the presence of `reusable_artifact_id`.
- `ScreenerEditorPage` refuses Save for `compatibility_read_only` and sends the user to Artifact Library.
- New Screener creation currently reports that it was created as an Artifact Library Draft and redirects away from the Screener domain editor.
- Screener Registry currently has copy/import language around shared Screeners and Artifact Library Drafts.

These are implementation migration points, not target UX requirements.

## 6. Target UI contract

Every Screener presentation should distinguish **ownership/currentness** from **historical/system provenance**.

Suggested domain flags returned by a future façade/API may conceptually include:

- `editable: true|false`
- `read_only_reason_code`
- `read_only_reason_text`
- `allowed_actions`

The frontend should not infer a user-facing explanation merely from `reusable_artifact_id != null`.

Possible reason codes are implementation details, but could represent:

- `historical_version`
- `system_definition`
- `archived`
- `permission_denied`

`artifact_managed` should not be an investor-facing reason.

## 7. Auditability remains intact

Making the current account-owned Screener editable does not mean mutating historical evidence.

`Edit -> Save` creates the next immutable internal definition/version. Existing Recommendations, Strategy versions, backtests or other historical records continue referencing the exact historical definition they used.

This is the central separation:

- **Current Screener** — editable domain object/pointer for future work.
- **Historical Screener version** — immutable evidence.

## 8. Migration rule

During implementation:

1. stop treating `reusable_artifact_id != null` as sufficient reason to make the current account-owned Screener read-only;
2. introduce domain-aware editability/reason semantics;
3. retain immutable artifact/version records underneath;
4. convert sharing/import to `definition -> create independent Screener` semantics;
5. replace technical read-only messages with domain reasons and actionable next steps;
6. update current specs/user journeys only when the corresponding behavior is implemented and verified.
