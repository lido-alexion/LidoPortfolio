# V2 Dependency Analysis

**Date:** 2026-08-10 (closure housekeeping)  
**Program status:** **SD-035 V2 = CLOSED**  
**Authoritative snapshot:** [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md)  
**Scope:** Eleven SD-035 deferred capabilities only  
**V1 frozen:** 119 capabilities — do not re-scope  

---

## Current dependency status (post-closure)

| Dependency | Status |
|------------|--------|
| F042 → F043 | **Satisfied** (both CLOSED) |
| F019 → F014 | **Satisfied** (both CLOSED) |
| F003 → F060 | **Satisfied** (both CLOSED; F060 same-user sharing) |
| V1 recommendation pipeline → F137 | **Satisfied** (F137 CLOSED over shared decision core) |
| F143 / F144 formalization | **Completed** (both CLOSED) |
| Remaining SD-035 dependency blocking work | **None** |

Do **not** invent new dependencies. Historical analysis below is retained for traceability.

---

## Dependency matrix (historical + final)

| Feature | Depends On | Final status | Shared infrastructure |
|---------|------------|--------------|----------------------|
| **F003** Invite | V1 Sanctum auth, admin role | **CLOSED** | `UserInviteService`, invites |
| **F005** Sessions | V1 Sanctum, F004 password reset | **CLOSED** | `SessionManagementService`, Settings UI |
| **F014** Historical holdings | V1 transactions, OHLCV | **CLOSED** | Ledger; distinct from F015 snapshots |
| **F019** Bulk CSV import | V1 `TransactionWriteService` | **CLOSED** | Bulk import UI + write path |
| **F042** Data quality | V1 OHLCV, F020 | **CLOSED** | DQ Center + handoff |
| **F043** CA price repair | **F042** handoff; F020 | **CLOSED** | Factor repair + single-writer |
| **F060** Shared screener import | V1 screeners, profile scoping | **CLOSED** | `is_shared`, import fork |
| **F127** Portfolio alerts | Holdings enrichment, daily sync | **CLOSED** | Alert policies (≠ TOS Telegram) |
| **F137** Recommendation preview | V1 generation decision logic | **CLOSED** | `decideForSecurity` + preview API |
| **F143** Contextual help | Stable pages/routes | **CLOSED** | `appDocumentation.js`, static docs |
| **F144** Knowledge Board | Portfolio scoping | **CLOSED** | Notes/tags/images services |

---

## External / foundational dependencies (not in V2 backlog)

| Foundation | Required by | Notes |
|------------|-------------|-------|
| V1 Sanctum session auth | F003, F005 | SD-001; already V1 |
| V1 F004 password reset | F005 | Account lifecycle; already V1 |
| V1 F020 corporate actions | F042, F043 | Core CA apply; V1 |
| V1 transaction ledger | F019, F014 | `TransactionWriteService` SD-021 |
| V1 OHLCV / daily sync | F042, F127 | Price tables |
| V1 TOS Telegram notifications | — (distinct) | F127 remains separate channel/product |
| V1 screener engine | F060 | SD-030 eligibility screeners |

---

## Dependency graph (historical implementation order)

*Historical planning graph. All nodes CLOSED.*

```text
V1 foundations (frozen)
├── Sanctum auth + F004 password reset
├── Transaction ledger + OHLCV sync
├── F020 corporate actions (core)
└── TOS pipeline + Telegram notifications

V2 Phase 1 (parallel tracks) — CLOSED
├── F003 ──→ F005          Account & Access
└── F042                   Market Data Quality (anchor)

V2 Phase 2 — CLOSED
├── F042 ──→ F043          Price repair after DQ governance
└── F127                   Alert hardening (parallel)

V2 Phase 3 — CLOSED
├── F019 ──→ F014          Import validation → historical holdings UI
├── F003 ──→ F060          Account clarity → screener sharing
└── F137                   Preview API over shared decision core

V2 Phase 4 — CLOSED
├── F143                   Contextual help formalization
└── F144                   Knowledge Board formalization
```

**Legend:** `A ──→ B` = historical precedence (now satisfied).

---

## Special analyses (historical; outcomes closed)

### F003 / F005 — Account & Access — **CLOSED**

Planned as one initiative; F003 then F005. Delivered.

### F042 / F043 / V1 F020 — Market Data Quality — **CLOSED**

F042 preceded F043; single-writer invariant delivered.

### F014 / F019 — Portfolio History & Data — **CLOSED**

F019 before F014 precedence satisfied.

### F060 — Shared Screener Import — **CLOSED**

Same-user multi-profile sharing; F003 dependency for clarity **satisfied**.

### F127 — Portfolio Alerts — **CLOSED**

Formalized existing framework; did not rebuild notification stack.

### F137 — Recommendation Preview — **CLOSED**

Shared decision core + contract tests delivered.

### F143 / F144 — Knowledge & Guidance — **CLOSED**

Formalization packs completed; runtimes were already shipped.

---

## Wrong-order risks (historical lessons — still valid)

| If built too early… | Risk |
|---------------------|------|
| **F043 before F042** | Untraceable price mutations |
| **F014 UI before F019 validation** | Bad CSV then trusted as-of analytics |
| **F060 before F003** | Unclear multi-user sharing AuthZ |
| **F127 expansion (email/webhook)** | Conflicts with SD-009 Telegram-only |
| **F137 before pipeline stable** | Preview churn |
| **F143 formal spec while UI still changing** | Doc debt |

These risks informed sequencing; they are **not** open blockers.

---

## Foundations table (updated)

| Foundation | Precedes | Current note |
|------------|----------|--------------|
| Invite + admin auth hardening | F003 | **CLOSED** |
| Session list/revoke + PD-006 | F005 | **CLOSED** |
| DQ + OHLCV repair | F042, F043 | **CLOSED** |
| Import validation patterns | F019, F014 | **CLOSED** |
| Alert policy boundaries | F127 | **CLOSED** |
| Preview API contract tests | F137 | **CLOSED** (`F137RecommendationPreviewTest`) |
| Help content governance | F143 | **CLOSED** (~44 topics; formal pack) |
| Knowledge Board formalization | F144 | **CLOSED** |
| Corporate-action SQLite fixture flakiness | Suite hygiene | **Outside SD-035** — maintenance |

---

*See also: [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md), [V2-PRIORITIZATION.md](./V2-PRIORITIZATION.md), [V2-ROADMAP.md](./V2-ROADMAP.md)*
