# LidoPortfolio / StoX V6 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V6 product wishlist and planning register |
| **Created** | 2026-09-07 |
| **Status** | ACTIVE PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V6-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V5-Wishlist.md` |

## 1. Purpose and authority

This is the canonical register for V6 product planning. V1–V5 frozen specifications remain authoritative for inherited behaviour. A V6 feature may supersede an older rule only when the V6 specification says so explicitly and records compatibility/migration consequences.

`DECIDED` means product behaviour is sufficiently frozen for implementation. It does not mean implementation exists. `COMPLETE` requires implementation, tests, documentation, migrations/build/deployment verification as applicable.

### Status values

`OPEN` · `BLOCKED` · `DECIDED` · `IN PROGRESS` · `COMPLETE` · `SUPERSEDED`

### Feature-ID continuity

Existing roadmap IDs are retained for traceability. The historical `V4-FEAT-*` prefix records origin and does not assign a feature to V4. New V6 entries continue the established numeric sequence rather than renumbering previously referenced work.

## 2. Canonical V6 backlog

Current count: **14 items — 14 OPEN**.

| ID | Feature | Scope / inherited boundary | Planning group | Status |
|---|---|---|---|---|
| V4-FEAT-016 | Mobile application | Client expansion. Native vs PWA vs responsive SPA is not yet decided. Must preserve role/security boundaries. | UX / Platform | OPEN |
| V4-FEAT-017 | AI Assistant | Assistive/non-authoritative AI. FEAT-008 permits AI-assisted Draft authoring, but normal validation/publication/versioning remains authoritative. | Intelligence | OPEN |
| V4-FEAT-018 | ML scoring models | Optional non-deterministic scoring path. Must not silently replace deterministic Strategy/Recommendation semantics. Requires model/version evidence, leakage controls, reproducibility and fallback design. | Intelligence | OPEN |
| V4-FEAT-019 | ETF / Options / Crypto expansion | Instrument/market expansion. Accounting, execution, calendars, pricing, risk, tax, reconciliation and simulation implications must be designed; likely split by instrument family during planning. | Market expansion | OPEN |
| V4-FEAT-035 | Remaining frontend stack migration | Continue the already-shipped TypeScript/TanStack Query/AG Grid foundation incrementally. No big-bang rewrite and no rework of already migrated V5 surfaces merely for uniformity. | UX / Platform | OPEN |
| V4-FEAT-036 | Optional JWT/token API for non-SPA clients | Add legitimate non-SPA authentication without replacing Sanctum stateful-session browser authentication. Client/use-case/security model requires planning. | Platform / API | OPEN |
| V4-FEAT-043 | Dashboard reorganization / widget management | Define fixed vs customizable widgets, show/hide, ordering, defaults, persistence scope, reset behaviour and mobile implications. Emergency controls are not ordinary hideable widgets. | UX / Platform | OPEN |
| V4-FEAT-044 | Kite Disconnect Kill Switch | Account-level emergency action: enter Emergency Halt, revoke/disconnect StoX's usable Kite session and prevent new live broker submissions. Does not cancel already-submitted orders and must not imply cancellation. | Live Execution Safety | OPEN |
| V4-FEAT-045 | Emergency Cancel Open Orders + Disconnect | High-risk action: enter Emergency Halt, attempt cancellation of StoX-managed submitted-but-not-fully-executed Kite orders (remaining cancellable quantity only for partial fills), persist/report failures, then disconnect. Partial fills remain real Trades. | Live Execution Safety | OPEN |
| V4-FEAT-046 | Live Kite Quote-Based Execution Sizing | Before Semi/Automatic external submission, recompute remaining target using actual Strategy ownership, current broker quote, V3 capital/lending rules, internal netting and verified shared broker funds. Stored Recommendation quantity remains non-authoritative. | Live Execution Safety | OPEN |
| V4-FEAT-047 | Account-Level Execution State | Introduce operational `Normal` / `Emergency Halt` state separate from Portfolio `Manual/Semi-Automatic/Automatic` mode. Halt blocks new live execution immediately; recovery is explicit and never automatic. | Live Execution Safety | OPEN |
| V4-FEAT-048 | Persistent Emergency Controls | If any LIVE Portfolio is Semi-Automatic/Automatic, emergency control remains quickly accessible throughout Investor application; while halted, recovery remains accessible. Applies across portfolio/page context. | Live Execution Safety / UX | OPEN |
| V4-FEAT-049 | Clone Portfolio as Paper | Create a new independent PAPER Portfolio from an existing Portfolio. Source financial identity is neither altered nor linked. Exact state/configuration copied is a V6 product decision. | Portfolio experimentation | OPEN |
| V4-FEAT-050 | Admin Audit Explorer | Admin-only read-only explorer over persisted StoX audit traces with filters, pagination/detail and filter-respecting CSV export. No audit mutation and no weakening of FEAT-042 ownership separation. | Administration | OPEN |

## 3. Reconciliation against V5 deferred work

The V5 canonical register preserved six deferred IDs: `V4-FEAT-016`, `017`, `018`, `019`, `035`, and `036`. It additionally preserved seven V6 product-work bullets: Dashboard widget management; Kite disconnect; emergency cancel+disconnect; live quote sizing; combined Execution State/persistent controls; Clone Portfolio as Paper; and Admin Audit Explorer.

V6 separates the combined Execution State/persistent-control bullet into two independently traceable features (`V4-FEAT-047` and `V4-FEAT-048`) because one is a domain safety state and the other is an application-wide UX/accessibility requirement. This yields the canonical **14-item** V6 backlog. No other explicit V6-deferred item was found in the reconciled V5 register or the reviewed relevant V5 specifications as of 2026-09-07.

## 4. Dependency groups

### A. Live Execution Safety — plan first

- `V4-FEAT-047` Account-Level Execution State is the foundational domain concept.
- `V4-FEAT-044` Disconnect Kill Switch and `V4-FEAT-045` Cancel+Disconnect transition the account into Emergency Halt.
- `V4-FEAT-048` Persistent Emergency Controls exposes those account-level safety actions independently of current page/portfolio.
- `V4-FEAT-046` Live Quote Sizing changes the final live-submission sizing stage but preserves FEAT-039 target-seeking, V3 capital/lending and FEAT-040 reconciliation gates.
- Inherits FEAT-037 Kite readiness, FEAT-039 execution/order lifecycle, FEAT-040 reconciliation and FEAT-004 notification/audit principles.

### B. Portfolio experimentation

- `V4-FEAT-049` depends on FEAT-020 Paper Portfolio semantics, FEAT-008 immutable artifact/binding versions and canonical Portfolio accounting/capital state.

### C. Administration

- `V4-FEAT-050` depends on FEAT-042 role separation and existing persisted audit evidence. It is observational only and does not introduce Admin impersonation or Investor-domain ownership.

### D. UX / Platform

- `V4-FEAT-043` Dashboard customization must never allow safety-critical emergency controls to be hidden.
- `V4-FEAT-035` proceeds incrementally alongside feature work rather than as a release-blocking rewrite.
- `V4-FEAT-016` Mobile architecture should be decided before finalizing any mobile-driven authentication requirement.

### E. Intelligence

- `V4-FEAT-017` AI Assistant inherits FEAT-008 Draft/publication/versioning boundaries and deterministic investment-decision authority.
- `V4-FEAT-018` ML scoring additionally depends on Indicator/artifact version provenance, historical anti-leakage and simulation/reproducibility evidence.

### F. Platform / API

- `V4-FEAT-036` JWT/token API should follow concrete non-SPA client/use-case definition. It may be influenced by the Mobile decision but must coexist with Sanctum browser sessions.

### G. Market expansion

- `V4-FEAT-019` should first be decomposed by instrument family. ETFs are closest to present equity semantics; Options and Crypto have materially larger accounting/execution/calendar/risk/tax consequences and should not be treated as one dropdown extension.

## 5. Recommended V6 planning sequence

1. **Live Execution Safety cluster:** FEAT-047 → FEAT-044/045/048 → FEAT-046, frozen as one coherent safety architecture while retaining independently traceable feature IDs.
2. **Clone Portfolio as Paper** — FEAT-049.
3. **Admin Audit Explorer** — FEAT-050.
4. **Dashboard reorganization/widget management** — FEAT-043.
5. **Mobile architecture** — FEAT-016, before locking mobile-driven API/auth needs.
6. **JWT/non-SPA API** — FEAT-036 once legitimate clients and scopes are known.
7. **AI Assistant** — FEAT-017.
8. **ML scoring** — FEAT-018, after AI/decision-authority boundaries and evidence requirements are explicit.
9. **Market expansion** — FEAT-019, decomposed ETF first vs Options/Crypto as separate planning tracks if confirmed.
10. **Frontend migration** — FEAT-035 continues incrementally in parallel wherever touched surfaces justify migration.

## 6. Planning rules

For each feature/cluster:

1. Inspect current implementation and authoritative V1–V5 specifications.
2. State inherited rules and whether V6 preserves or explicitly supersedes them.
3. Ask the PO only for materially different product semantics/UX/policy/security decisions.
4. Freeze outcomes, non-goals, dependencies, authorization/audit/migration implications and acceptance criteria.
5. Persist a dedicated V6 specification and mark the register `DECIDED` only when product behaviour is implementable without unresolved material PO choices.
6. Implementation begins only after the relevant feature is `DECIDED`.

V6 planning may run concurrently with the separate V5 closure mission, but V6 implementation must not contaminate V5 closure work.

## 7. First active planning cluster — Live Execution Safety

Relevant inherited rules:

- Recommendation target amount remains authoritative; stored/displayed quantity is derived.
- Execution remains target-seeking and revalidates before each incremental attempt.
- Submitted broker orders remain Order Lifecycle responsibility.
- Internal transfers/fills already completed remain economically real and are never rolled back because later residual broker work fails.
- Reconciliation remains diagnostic; a confirmed holdings mismatch blocks new Semi/Automatic execution, while cash mismatch alone does not.
- Portfolio execution mode remains configuration. Emergency Halt is a separate account operational override.
- Paper Portfolios are structurally outside Kite, reconciliation, kill switches and Emergency Halt.

Initial V6 safety architecture baseline (subject only to genuine PO decisions):

1. Emergency action first establishes `Emergency Halt` atomically before attempting broker-side disconnect/cancellation, preventing concurrent new live execution from escaping while the emergency operation runs.
2. Emergency Halt applies across the Investor account's LIVE execution domain; it does not rewrite Portfolio modes and does not affect PAPER simulation.
3. Existing submitted orders continue under Order Lifecycle. Later fills remain real Trades/accounting evidence.
4. Disconnect failure or cancellation failure does not automatically clear Emergency Halt.
5. Kill-switch/cancellation/disconnect/recovery attempts and outcomes are auditable independently; `disconnect != cancellation succeeded` remains explicit.
6. Recovery is deliberate and never automatic; it must revalidate current execution readiness before returning to Normal.
7. Safety controls are platform chrome/application-level controls when applicable, not ordinary Dashboard widgets and therefore are not hideable by FEAT-043.

### Genuine PO decisions still to freeze for this cluster

- Live-quote failure/fallback behaviour immediately before Semi/Automatic submission.
- Exact recovery eligibility/UX semantics after Emergency Halt.
- Emergency cancel+disconnect confirmation and partial-failure recovery UX.
- Final user-facing terminology for `Emergency Halt` and recovery (working name: `Get-a-life`).

These decisions should be taken one at a time; ordinary schema/service/API/component details remain engineering decisions.
