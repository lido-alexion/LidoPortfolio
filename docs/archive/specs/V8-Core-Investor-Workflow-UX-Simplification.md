# V8 Core Investor Workflow & UX Simplification — Screener and Strategy

| Field | Value |
|---|---|
| **Epic** | V4-FEAT-064 |
| **Release** | V8 |
| **Title** | Core Investor Workflow & UX Simplification — Screener and Strategy |
| **Status** | FROZEN / IMPLEMENTATION-READY |
| **Registered** | 2026-09-26 |
| **Parent register** | `LidoPortfolio-V8-Wishlist.md` |
| **Implementation work packages** | `../decisions/2026-09-26-ws1-ws2-codex-work-packages.md` |

## 1. Product intent

Overhaul the core day-to-day Screener and Strategy workflows so an investor can create, edit, configure, enable and understand these domain objects directly from their own pages without learning StoX's internal Artifact Library lifecycle.

The guiding principle is:

> **Simplicity first, auditability intact.**

Ordinary investor actions should look like ordinary product actions:

```text
Screeners -> New/Edit -> Save
Strategies -> New/Edit -> Save -> Enable when ready
```

StoX must automatically preserve immutable versions and exact historical provenance underneath those simple workflows.

## 2. Why this epic exists

Source and user-journey review found that current Screener/Strategy UX leaks internal Artifact Framework concepts into normal investor work. Examples include:

- account-owned Screeners becoming Inspect-only because they are mapped to Artifact Library;
- users being redirected to Artifact Library to edit normal domain objects;
- Draft/publish/binding lifecycle appearing in routine authoring;
- manual SemVer/version concepts exposed to non-technical users;
- Strategy readiness and enablement prerequisites not forming a clear workflow;
- obsolete last-enabled/single-Strategy restrictions;
- Strategy versions being mutated in place despite historical recommendation references;
- Strategy dependencies not pinning exact immutable Screener versions;
- Screener runs/backtests not consistently pinning exact semantic Screener versions.

This epic fixes both the **UX problem** and the underlying **audit/provenance correctness** required to simplify the UX safely.

## 3. Frozen product decisions

1. Ordinary Screener CRUD belongs on Screener pages.
2. Ordinary Strategy CRUD belongs on Strategy pages.
3. Artifact Library is not a prerequisite for normal investor CRUD.
4. Users do not enter SemVer/version numbers.
5. There is no persisted ordinary Draft concept: unsaved edits are lost if the user leaves without saving, subject to normal dirty-form warnings.
6. Historical versions remain immutable for audit, explanation and backtesting.
7. A normal current account-owned Screener is editable unless a genuine domain reason prevents it.
8. Read-only UI must explain **why** and what the user can do next; `Managed by Artifact Library` is not a valid investor-facing reason.
9. Screener instances are private/account-scoped.
10. Sharing exposes a Screener **definition/template**, not a shared mutable instance. Recipient creates an independent account-owned Screener.
11. Multiple Strategies are supported and may be enabled concurrently.
12. Strategy versions and their exact Screener dependencies must be reconstructable historically.
13. Saving an audit-relevant Strategy change creates a new immutable Strategy version rather than rewriting an old version.
14. Saving a semantic Screener change creates the next immutable Screener version automatically.
15. Incomplete Strategies may be saved as **Setup Required**; persistence, readiness and enablement are separate concepts.

## 4. Epic scope

### 4.1 Screener semantic version foundation

Version the complete investment semantics, including condition definition and applicable universe selector. Operational scheduling/notification changes do not create investment-rule versions.

### 4.2 Screener run provenance

Every new Screener run pins the exact immutable Screener version it executed.

### 4.3 Screener backtest provenance

Backtests and persistent result caches become version-aware so historical evidence is not deleted or mixed after later Screener edits.

### 4.4 Screener authoring UX

Create/Edit/Save stays within the Screener domain experience. Remove Artifact Library redirects, manual versioning and Draft/publish ceremony from normal use.

### 4.5 Screener definition-copy sharing

Sharing allows another account to inspect/use a portable definition to create a new independent Screener. No shared mutable instance or update propagation.

### 4.6 Strategy dependency provenance

Each immutable Strategy version pins exact immutable Screener versions while retaining Screener lineage IDs for navigation.

### 4.7 Immutable Strategy Save

Strategy Save becomes transactional copy-on-write versioning. Historical Strategy versions referenced by Recommendations remain unchanged.

### 4.8 Strategy readiness and lifecycle

Introduce clear Setup Required/readiness feedback, actionable missing requirements, multiple concurrently enabled Strategies and obligation-based disable/archive safety rather than obsolete count-based rules.

### 4.9 Contextual dependency creation

From Strategy authoring, user can create a missing Screener, return to the Strategy editor with unsaved transient state preserved, and have the newly created Screener selected.

### 4.10 Provenance regression gate

The implementation must prove that a historical transaction/recommendation can still resolve the exact Strategy version, Screener version and execution evidence that caused it after later edits.

## 5. Implementation work packages

The epic is implemented through the following ordered packages:

| WP | Work package | Delivery group |
|---|---|---|
| WP-01 | Screener semantic version foundation | A — Provenance foundation |
| WP-02 | Pin live Screener runs to exact versions | A — Provenance foundation |
| WP-03 | Version-aware Screener backtests | A — Provenance foundation |
| WP-04 | Simplify Screener create/edit domain workflow | B — Screener UX |
| WP-05 | Screener definition-copy sharing | B — Screener UX |
| WP-06 | Pin Strategy dependencies to exact Screener versions | C — Strategy provenance |
| WP-07 | Immutable Strategy Save | C — Strategy provenance |
| WP-08 | Strategy readiness + multiple-Strategy lifecycle | D — Strategy UX |
| WP-09 | Contextual Create Screener from Strategy | D — Strategy UX |
| WP-10 | Provenance regression and migration gate | E — Final gate |

Canonical detailed handoff:

`docs/decisions/2026-09-26-ws1-ws2-codex-work-packages.md`

Codex should begin with **WP-01** and proceed sequentially. Each delivery group must be green before starting the next; avoid a mega-commit.

## 6. Canonical design/decision chain

Implementation must read these documents together:

1. `docs/decisions/2026-09-26-current-vs-golden-ux-gap-analysis.md`
2. `docs/decisions/2026-09-26-ws1-ws2-source-verification.md`
3. `docs/decisions/2026-09-26-screener-readonly-and-sharing-semantics.md`
4. `docs/decisions/2026-09-26-ws1-ws2-migration-architecture.md`
5. `docs/decisions/2026-09-26-audit-safety-verification-screener-strategy.md`
6. `docs/decisions/2026-09-26-ws1-ws2-implementation-ready-change-spec.md`
7. `docs/decisions/2026-09-26-ws1-ws2-codex-work-packages.md`

The later/more specific document supersedes earlier exploratory wording where they conflict.

## 7. Mandatory audit acceptance scenario

At minimum:

1. Create Screener V1 with ROC > 15.
2. Create Strategy V1 using/pinning Screener V1.
3. A stock with ROC 17 passes and produces Recommendation R.
4. R is executed and produces transaction T.
5. Edit Screener to ROC > 20, creating V2.
6. Strategy later adopts V2 through a new immutable Strategy version.
7. Current views show the new rule.
8. Audit T/R.
9. StoX resolves the old Strategy version -> old Screener version -> original run/evidence showing ROC > 15.
10. No later edit has rewritten the historical evidence.

Universe-selector changes and later watchlist/index membership changes must also be covered.

## 8. Out of scope

This epic does not include:

- Playwright/Selenium user-journey automation;
- automation-selector/test-ID hardening beyond implementation necessities;
- the broader V9 documentation chatbot/type-ahead/agentic roadmap;
- removal of Artifact Framework internals;
- removal of historical versions;
- user-managed persisted Drafts;
- manual SemVer entry;
- unrelated broad visual redesign.

## 9. Implementation gate

Codex may start immediately with WP-01.

If implementation discovers a choice that would remove functionality, weaken auditability, change investment semantics, or contradict a frozen product decision, stop and return it for PO/architect review. Pure implementation details should be resolved from existing conventions and the frozen principles without reopening settled PO questions.

## 10. Decision history

- **2026-09-26:** Epic registered as **V4-FEAT-064** after user-journey baseline, Golden workflow design, source verification, provenance audit and Codex work-package decomposition.
- Existing historical specifications are not silently rewritten. Once implementation is complete and verified, obsolete decisions should be struck/superseded with dated history as already agreed.
