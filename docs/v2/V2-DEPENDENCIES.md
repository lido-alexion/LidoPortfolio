# V2 Dependency Analysis

**Date:** 2026-08-09  
**Scope:** Eleven SD-035 deferred capabilities only  
**V1 frozen:** 119 capabilities — do not re-scope

---

## Dependency matrix

| Feature | Depends On | Enables | Shared infrastructure |
|---------|------------|---------|----------------------|
| **F003** Invite | V1 Sanctum auth, admin role (`is_admin`) | F060 collaboration, multi-user ops | `UserInviteService`, `portfolio_user_invites`, invite SPA routes |
| **F005** Sessions | V1 Sanctum sessions, F004 password reset | Secure multi-device account lifecycle | `SessionManagementService`, Laravel session store, Settings UI |
| **F014** Historical holdings | V1 transactions, OHLCV, `PortfolioHistoricalHoldingsService` | As-of analytics UI | Same ledger as F019; distinct from V1 F015 snapshots |
| **F019** Bulk CSV import | V1 `TransactionWriteService`, holdings calc | Faster portfolio onboarding; patterns for F014 | CSV parser, transaction validation, `BulkTransactionImport.jsx` |
| **F042** Data quality | V1 OHLCV sync, V1 F020 corporate actions | F043 repair; pipeline data trust | `DataQualityGuardService`, issue/evidence tables, admin UI |
| **F043** CA price repair | **F042** issue model; V1 F020 corporate actions | Ops recovery after bad prices | `CorporateActionPriceRepairService`, deploy scripts |
| **F060** Shared screener import | V1 screeners (SD-030), profile scoping | Cross-portfolio screener reuse | `is_shared` flag, shared tab, import API |
| **F127** Portfolio alerts | V1 holdings enrichment, daily price sync | Holding monitoring (non-TOS) | `AlertPolicyService`, `AlertPolicyEvaluationService`, `portfolio_alert_policies` |
| **F137** Recommendation preview | V1 `RecommendationGenerationPipeline` components (read-only) | Research UI, future automation | `RecommendationPreviewService`, analytics API |
| **F143** Contextual help | V1 pages/routes stable | User onboarding | `appDocumentation.js`, static docs generator |
| **F144** Knowledge Board | V1 portfolio scoping | Research notes (standalone) | `KnowledgeBoardNoteService`, Tiptap editor, tags |

---

## External / foundational dependencies (not in V2 backlog)

| Foundation | Required by | Notes |
|------------|-------------|-------|
| V1 Sanctum session auth | F003, F005 | SD-001; already V1 |
| V1 F004 password reset | F005 | Account lifecycle; already V1 |
| V1 F020 corporate actions | F042, F043 | Core CA apply; V1 — not expanded to F043 scope |
| V1 transaction ledger | F019, F014 | `TransactionWriteService` SD-021 |
| V1 OHLCV / daily sync | F042, F127 | `DailyMarketDataJob`, price tables |
| V1 TOS Telegram notifications | — (distinct) | F127 must remain clearly separate channel/product |
| V1 screener engine | F060 | SD-030 eligibility screeners |

---

## Dependency graph (implementation order)

```text
V1 foundations (frozen)
├── Sanctum auth + F004 password reset
├── Transaction ledger + OHLCV sync
├── F020 corporate actions (core)
└── TOS pipeline + Telegram notifications

V2 Phase 1 (parallel tracks)
├── F003 ──→ F005          Account & Access
└── F042                   Market Data Quality (anchor)

V2 Phase 2
├── F042 ──→ F043          Price repair after DQ governance
└── F127                   Alert hardening (parallel)

V2 Phase 3
├── F019 ──→ F014          Import validation → historical holdings UI
├── F003 ──→ F060          Account clarity → screener sharing
└── F137                   Preview API stabilization

V2 Phase 4 (postpone)
├── F143                   Contextual help formalization
└── F144                   Knowledge Board formalization
```

**Legend:** `A ──→ B` = A should precede or complete before B is formalized as V2.

---

## Special analyses

### F003 / F005 — Account & Access Management

| Finding | Detail |
|---------|--------|
| Shared infrastructure | Sanctum sessions, `AuthController`, admin user management UI, invite + password-reset link patterns |
| F004 (V1) relationship | Password reset already V1; invites and sessions complete the lifecycle |
| Recommendation | **Plan F003 and F005 as one V2 initiative** — "Account & Access Management" |
| Order within initiative | F003 first (invite-only model already production-intent) → F005 session UI hardening |

---

### F042 / F043 / V1 F020 — Market Data Quality

| Finding | Detail |
|---------|--------|
| V1 F020 scope | Core split/bonus apply — frozen V1 |
| F042 scope | Detection, issue queue, resolution workflow — admin ops |
| F043 scope | Price repair scripts — **not** V1 F020 |
| Relationship | F042 detects anomalies; F043 repairs prices; F020 applies user-facing corporate actions |
| Legacy bridge | `DataQualityLegacyCorporateActionMigrationService` maps old manual CAs into DQ issues |
| Recommendation | **F042 must precede F043**; both belong to **Market Data Quality** initiative |
| F043 standalone? | **No** — repair without DQ governance increases risk |

---

### F014 / F019 — Portfolio History & Data

| Finding | Detail |
|---------|--------|
| Shared infra | Transactions, `TransactionWriteService`, holdings recalculation, OHLCV |
| F019 role | Bulk entry into ledger |
| F014 role | As-of holdings queries (backend exists) |
| Overlap | Both touch historical correctness but different UX surfaces |
| Recommendation | **F019 before F014** — establish import validation framework before promoting historical analytics UI |
| Independence | Can be separate releases within same initiative |

---

### F060 — Shared Screener Import

| Finding | Detail |
|---------|--------|
| Current state | Works within single user's multiple portfolios (`is_shared`, shared tab) |
| F003 dependency | Full multi-user collaboration semantics need invite/account clarity |
| Strategy link | Imported copy feeds SD-030 eligibility screeners |
| Recommendation | **Postpone formal V2 until F003/F005 complete** unless scope stays single-tenant multi-portfolio |

---

### F127 — Portfolio Alerts (non-TOS)

| Finding | Detail |
|---------|--------|
| vs TOS Telegram | `NotificationEngine` = recommendation lifecycle events; F127 = holding formula policies |
| vs pipeline notifications | Pipeline uses Telegram skip/history; alerts use `portfolio_alerts` table |
| Framework | `AlertPolicyService` + `AlertPolicyEvaluationService` + formula evaluator — **framework exists** |
| External gap | Email/webhook channels explicitly V1_OUT_OF_SCOPE — do not expand F127 into multi-channel without new governance |
| Recommendation | Formalize existing framework; **do not rebuild parallel notification stack** |

---

### F137 — Recommendation Preview API

| Finding | Detail |
|---------|--------|
| Pipeline relationship | Read-only subset of generation logic via `RecommendationPreviewService` |
| Consumers | Watchlist research panel; potential external API |
| Tests | No feature tests — V2 hardening priority |
| Dependency | Stable V1 pipeline behaviour (frozen) |
| Recommendation | Phase 3 — platform API stabilization, not Phase 1 |

---

### F143 / F144 — Knowledge & Guidance

| Finding | Detail |
|---------|--------|
| Independence | Separate product areas (help vs notes) |
| Shared concern | Content maintenance burden |
| F143 | Tied to V1 page churn — help text must track UI changes |
| F144 | Standalone research capture |
| Recommendation | **Group as "Knowledge & Guidance" initiative** but **deliberately postpone to Phase 4** |

---

## Wrong-order risks

| If built too early… | Risk |
|---------------------|------|
| **F043 before F042** | Repairs without issue tracking → untraceable price mutations; duplicated ops paths |
| **F014 UI before F019 validation** | Users import bad CSV then trust as-of analytics → compounded errors |
| **F060 before F003** | Sharing semantics unclear across users; authorization gaps |
| **F127 expansion (email/webhook)** | Duplicates deferred V1 notification channels; conflicts with SD-009 Telegram-only |
| **F137 before pipeline stable** | Preview API churn breaks Watchlist consumers |
| **F143 formal spec while UI still changing** | Documentation debt multiplied every release |
| **Rebuilding F127 alert stack** | Wastes existing `AlertPolicy*` investment; parallel to TOS notifications |

---

## Technical foundations to complete before V2 features

| Foundation | Precedes | Evidence |
|------------|----------|----------|
| Invite + admin auth hardening | F003 formal V2 | `UserInviteTest`, admin-only routes |
| Session list/revoke UI completion | F005 formal V2 | Partial Settings integration |
| DQ issue lifecycle spec | F042, F043 | Models exist; governance absent |
| Import validation + rollback patterns | F019, F014 | `TransactionWriteService` single write path |
| Alert policy test coverage + channel boundaries | F127 | Extensive code; clarify vs Telegram |
| Preview API contract tests | F137 | No PHPUnit feature tests today |
| Help content governance process | F143 | 43 topics in `appDocumentation.js` |
| Corporate-action test fixture fix | F042/F043 hardening | SQLite price collision in CA tests (non-V1 blocker) |

---

*See also: [V2-PRIORITIZATION.md](./V2-PRIORITIZATION.md), [V2-ROADMAP.md](./V2-ROADMAP.md)*
