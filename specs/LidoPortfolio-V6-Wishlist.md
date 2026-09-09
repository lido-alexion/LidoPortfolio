# LidoPortfolio / StoX V6 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V6 product wishlist and planning register |
| **Created** | 2026-09-07 |
| **Frozen** | 2026-09-09 |
| **Status** | **FROZEN / IMPLEMENTATION READY** |
| **Canonical path** | `specs/LidoPortfolio-V6-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V5-Wishlist.md` |

## 1. Purpose and authority

This is the canonical register for V6 product scope and planning. **V6 product architecture is frozen as of 2026-09-09 and implementation may proceed.** V1–V5 frozen specifications remain authoritative for inherited behaviour. A V6 feature may supersede an older rule only when the V6 specification says so explicitly and records compatibility/migration consequences.

`DECIDED` means product behaviour is sufficiently frozen for implementation. It does not mean implementation exists. `COMPLETE` requires implementation, tests, documentation, migrations/build/deployment verification as applicable.

### V6 freeze rule

The six V6 epics and twelve V6 backlog items are frozen for implementation. Routine engineering choices, implementation details, refactoring and architecture-consistent edge-case handling do not require PO review. A change that materially alters frozen product semantics, security boundaries, accounting/execution behaviour, user-visible capability, or intentionally removes an existing preserved capability requires explicit PO review and a recorded V6 amendment before implementation.

Work explicitly moved to V7 is not unfinished V6 scope and must not be pulled into V6 implementation implicitly.

### Status values

`OPEN` · `BLOCKED` · `DECIDED` · `IN PROGRESS` · `COMPLETE` · `SUPERSEDED`

### Feature-ID continuity

Existing roadmap IDs are retained for traceability. The historical `V4-FEAT-*` prefix records origin and does not assign a feature to V4. New V6 entries continue the established numeric sequence rather than renumbering previously referenced work.

## 2. Canonical V6 backlog

Current count: **12 items — 12 DECIDED, 0 OPEN**.

| ID | Feature | Scope / inherited boundary | Planning group | Status |
|---|---|---|---|---|
| V4-FEAT-016 | Mobile / responsive client support | Responsive SPA across mobile, desktop, ultrawide and 4K. Mobile may use different interaction components rather than shrinking desktop UI. Native/PWA remains out of scope unless later needed. | UX / Platform | DECIDED |
| V4-FEAT-035 | Remaining frontend stack migration | Continue the already-shipped TypeScript/TanStack Query/AG Grid foundation incrementally. No big-bang rewrite and no rework of already migrated V5 surfaces merely for uniformity. Existing useful controls/components must be reused where practical and current functionality must not silently regress. | UX / Platform | DECIDED |
| V4-FEAT-036 | Trusted non-SPA API tokens | Personal/scoped API tokens for first-party or trusted personal integrations. Browser SPA remains Sanctum stateful-cookie auth. No public OAuth/developer-platform commitment in V6. | Platform / API | DECIDED |
| V4-FEAT-043 | Dashboard / UX reorganization and widget management | V6 UX fit-and-finish baseline: spacious, hierarchical, visual-first, icon-rich, responsive, progressively disclosed UI; dashboard/widget management and reusable component standardization. Existing Dashboard data and small convenience features are preservation baseline; removal requires PO review. Emergency controls are never hideable widgets. | UX / Platform | DECIDED |
| V4-FEAT-044 | Kite Disconnect Kill Switch | Account-level emergency action: enter Emergency Halt, hard-close StoX outbound order creation/submission, attempt Kite disconnect/revocation, destroy local usable credential. Existing submitted orders remain Order Lifecycle responsibility. | Live Execution Safety | DECIDED |
| V4-FEAT-045 | Emergency Cancel Open Orders + Disconnect | High-risk action: halt first; cancel all eligible StoX-managed primary open orders for the targeted broker/account; bounded verification; disconnect/revoke; destroy local credential. Existing protective/GTT orders are not cancelled. | Live Execution Safety | DECIDED |
| V4-FEAT-046 | Live Kite Quote-Based Execution Sizing | Recompute residual external order from target/current ownership using live quote. Investor-level quote policy: Strict or Allow closing-price fallback. V5 internal-netting valuation remains unchanged. | Live Execution Safety | DECIDED |
| V4-FEAT-047 | Account-Level Execution State | Broker-independent Investor execution state `Normal` / `Emergency Halt`, separate from Portfolio mode. Halt is Investor-triggered only and hard-blocks all new StoX broker-order creation/submission. | Live Execution Safety | DECIDED |
| V4-FEAT-048 | Persistent Emergency Controls | Global Investor-app execution-state indicator and emergency controls; responsive/mobile equivalents required. Recovery remains explicit and strongly validated. | Live Execution Safety / UX | DECIDED |
| V4-FEAT-049 | Clone Portfolio as Paper | Create an independent PAPER Portfolio from an existing Portfolio. User chooses whether current holdings are copied. Strategy/artifact versions are pinned; no historical trades/performance or ongoing synchronization. | Portfolio experimentation | DECIDED |
| V4-FEAT-050 | Admin Audit Explorer | Admin-only read-only explorer over authoritative persisted audit traces. Admin may inspect Investors/Portfolios. UI is curated; CSV may expose a broader/rawer authorized audit dataset. | Administration | DECIDED |
| V4-FEAT-051 | Contextual Notes | Personal plain-text notes available contextually across pages via stable logical page context + account/portfolio scope. Lightweight right overlay pane, inline add/edit/delete, timestamps and responsive mobile alternative. | UX / Productivity | DECIDED |

## 3. Explicitly moved to V7

The following roadmap items are **not V6 scope** and are moved to V7 planning:

| ID | Feature | V7 rationale |
|---|---|---|
| V4-FEAT-017 | AI Assistant | Deferred until after V6 safety/UX/platform consolidation. |
| V4-FEAT-018 | ML scoring models | Deferred with AI/decision-authority work; deterministic Strategy semantics remain authoritative. |
| V4-FEAT-019 | ETF / Options / Crypto expansion | Deferred market/instrument expansion; likely to be decomposed by instrument family in V7. |
| V4-FEAT-052 | Standalone Telemetry Platform | Deliberation showed this should not be a StoX-internal feature. It is a separate, product-independent application/product with StoX as its first client. Canonical architecture is in `V7-Telemetry-Platform.md`. |

Their historical IDs are retained for traceability and must not be treated as unfinished V6 work.

## 4. Reconciliation against V5 deferred work

The V5 canonical register preserved six deferred IDs: `V4-FEAT-016`, `017`, `018`, `019`, `035`, and `036`. It additionally preserved V6 product work around Dashboard UX, emergency execution controls, live quote sizing, account Execution State, Clone Portfolio as Paper, and Admin Audit Explorer.

During V6 planning:

- `V4-FEAT-017`, `018`, and `019` were explicitly moved to V7.
- The combined Execution State/persistent-control concept remains split into `V4-FEAT-047` and `V4-FEAT-048` for domain/UX traceability.
- `V4-FEAT-051` Contextual Notes was added as a new V6 productivity/UX epic.
- `V4-FEAT-052` Telemetry was initially added to V6, then moved to V7 after product deliberation established it as a standalone, reusable Telemetry application rather than a StoX feature. Its canonical architecture is `V7-Telemetry-Platform.md`.

## 5. V6 epic grouping and planning state

### E1 — Live Execution Safety & Emergency Controls — DECIDED

Features: `V4-FEAT-044` through `V4-FEAT-048`.

Frozen architecture includes broker-independent `Normal/Emergency Halt`, hard outbound gate close, Kite-specific cleanup below the global halt, strict explicit Recovery, crash-safe bounded emergency cleanup, Investor-level live-quote policy, and persistent safety controls across clients.

### E2 — Paper Experimentation — DECIDED

Feature: `V4-FEAT-049`.

Clone-as-Paper creates a new independent Paper Portfolio. User chooses whether to copy current holdings. Historical Trades/performance are not copied; exact published Strategy/artifact versions are pinned; provenance is retained; no ongoing synchronization exists.

### E3 — Administrative Auditability — DECIDED

Feature: `V4-FEAT-050`.

Authoritative persisted audit records remain source of truth. Admin can inspect any Investor and that Investor's Portfolios in read-only mode. CSV may expose broader/rawer authorized audit fields than the curated explorer UI.

### E4 — Investor UX & Client Evolution — DECIDED

Features: `V4-FEAT-043`, `V4-FEAT-016`, `V4-FEAT-035`.

Frozen on 2026-09-08 in `V6-E4-Investor-UX-Client-Evolution.md`.

V6 UX is a fit-and-finish/consolidation effort rather than a frontend rewrite: responsive across viewport classes, hierarchical information architecture, standardized tabs/components, progressive disclosure, visual-first presentation, icon-rich controls, themes, adaptive chrome, local preference persistence and incremental reuse/migration of proven StoX components.

Frozen constraints include:

- reuse the existing `ThemeToggle`/theme mechanism rather than introducing a new theme selector;
- current Dashboard information is a preservation baseline: presentation may change without repeated PO review, but removing an existing data item requires PO review;
- existing small convenience features such as handy links, contextual shortcuts, copy helpers and readymade prompts for external LLM review should be retained as much as reasonably possible;
- E4 must not silently functionally regress an existing page; materially redesigned pages require a before/after capability inventory, with any intentional removal explicitly reviewed by the PO.

### E5 — External/API Access — DECIDED

Feature: `V4-FEAT-036`.

Trusted first-party/personal integrations use named, revocable, scoped personal API tokens. SPA remains Sanctum cookie auth. Tokens never bypass normal StoX role, Emergency Halt, reconciliation or execution-readiness gates. No public OAuth/developer ecosystem in V6.

### E6 — Contextual Notes — DECIDED

Feature: `V4-FEAT-051`.

Personal plain-text notes are available contextually throughout Investor pages via a lightweight right-side utility pane, with responsive mobile replacement where appropriate.

## 6. V6 freeze declaration and implementation handoff

**V6 is frozen for implementation as of 2026-09-09.** There are no unresolved V6 product-architecture epics. Implementation may proceed by dependency and priority.

1. **E1 Live Execution Safety** — frozen.
2. **E2 Paper Experimentation** — frozen.
3. **E3 Administrative Auditability** — frozen.
4. **E4 Investor UX & Client Evolution** — frozen.
5. **E5 External/API Access** — frozen.
6. **E6 Contextual Notes** — frozen.
7. **FEAT-035 frontend migration** — continues incrementally wherever touched surfaces justify migration, within the frozen E4 rules.

`V4-FEAT-052` Telemetry is no longer part of V6; it is V7 standalone-product work.

Implementation agents should treat the frozen specifications and inherited V1–V5 rules as the product contract. They should inspect existing code before changing a surface, preserve established behavior unless superseded, and prefer architecture-consistent implementation choices over reopening settled product questions. Any genuine contradiction or material product-semantic gap should be escalated rather than guessed.

## 7. Implementation governance

For implementation of each feature/cluster:

1. Inspect current implementation and authoritative V1–V6 specifications.
2. Preserve inherited rules unless the frozen V6 specification explicitly supersedes them.
3. Resolve routine engineering choices and edge cases using existing architecture and project conventions without reopening product planning.
4. Escalate only material contradictions or missing product/security/accounting/execution semantics.
5. Keep implementation, tests, migrations, documentation and acceptance evidence aligned.
6. Mark a feature `COMPLETE` only after implementation, tests, documentation, migrations/build/deployment verification as applicable.

V6 implementation may run concurrently with the separate V5 closure mission, but V6 work must not contaminate or rewrite V5 closure evidence.

## 8. Inherited V6-wide principles

- Recommendation target amount remains authoritative; stored/displayed quantity is derived.
- Execution remains target-seeking and revalidates before each incremental attempt.
- Submitted broker orders remain Order Lifecycle responsibility.
- Internal transfers/fills already completed remain economically real and are never rolled back because later residual broker work fails.
- Reconciliation remains diagnostic; a confirmed holdings mismatch blocks new Semi/Automatic execution, while cash mismatch alone does not.
- Portfolio execution mode remains configuration. Emergency Halt is a separate account operational state.
- Paper Portfolios are structurally outside Kite/reconciliation/live Emergency Halt execution authority.
- Admin observation does not imply Investor trading authority.
- Reuse existing StoX domain/component foundations rather than redesigning frozen V3–V5 behavior without necessity.
