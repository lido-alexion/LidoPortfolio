# V2 Final Reconciliation — SD-035 Program Snapshot

**Date:** 2026-08-10  
**Type:** Read-only program-level reconciliation (factual snapshot)  
**Authority order:** code/tests → initiative packs → `DOCS.md` → `implementation.md` → older V2 planning docs  
**Does not invent:** new initiative IDs, product decisions, or mandatory hardening programmes

---

## 1. Executive verdict

**All eleven SD-035 V2 initiatives are formally reconciled and closed.**

| Question | Answer |
|----------|--------|
| Remaining unfinished SD-035 capability? | **None** |
| Formal packs for all eleven? | **Yes** (`docs/v2/F*-*`) |
| Mandatory V2 implementation remaining? | **No** |
| Remaining work? | **Non-blocking** tests/docs/ops/deferred product polish only |
| Recommended program phase | **Option A — V2 CLOSED** (maintenance / backlog; optional polish outside SD-035) |

“V2 complete” here means: every SD-035 deferred capability has an authoritative pack, a closed status (`COMPLETE` / `COMPLETE_WITH_NON_BLOCKERS` / `COMPLIANT_WITH_NON_BLOCKERS`), and no blocking product gaps. It does **not** mean “no future product work forever.”

---

## 2. SD-035 initiative status table

| ID | Name | Authoritative final status | Maturity | Major non-blockers | Product blockers? | Closed? |
|----|------|----------------------------|----------|--------------------|-------------------|---------|
| F003 | User Invite | `F003_COMPLIANT_WITH_NON_BLOCKERS` | Hardened + shipped | Invite↔reset token uniqueness cross-check PARTIAL | **No** | **Yes** |
| F005 | Session Management | `F005_COMPLETE_WITH_NON_BLOCKERS` | Hardened + shipped | Admin force-logout **DEFERRED** | **No** | **Yes** |
| F042 | Data Quality | `F042_COMPLETE_WITH_NON_BLOCKERS` | Hardened + shipped | Residual ops/docs depth | **No** | **Yes** |
| F043 | CA Price Repair | `F043_COMPLETE` | Hardened + shipped | Audit residual polish | **No** | **Yes** |
| F127 | Portfolio Alerts | `F127_COMPLETE_WITH_NON_BLOCKERS` | Hardened + shipped | Deferred alert channels/polish | **No** | **Yes** |
| F019 | Bulk CSV Import | `F019_COMPLETE_WITH_NON_BLOCKERS` | Hardened + shipped | Help wording / FE depth | **No** | **Yes** |
| F014 | Historical Holdings | `F014_COMPLETE_WITH_NON_BLOCKERS` | Delivered + shipped | Cash/realized/export/compare OOS/deferred | **No** | **Yes** |
| F060 | Shared Screener Import | `F060_COMPLETE_WITH_NON_BLOCKERS` | Hardened + shipped | PD-10 remap, PD-22 404-vs-403 | **No** | **Yes** |
| F137 | Recommendation Preview | `F137_COMPLETE_WITH_NON_BLOCKERS` | Hardened + shipped | FE component tests; flat aliases | **No** | **Yes** |
| F143 | Contextual Help | `F143_COMPLETE_WITH_NON_BLOCKERS` | Formalized (runtime pre-shipped) | Help tests; orphan Doc*; SPA prose drift | **No** | **Yes** |
| F144 | Knowledge Board | `F144_COMPLETE_WITH_NON_BLOCKERS` | Formalized (runtime pre-shipped) | Note AuthZ tests; image GC; API doc drift | **No** | **Yes** |

**Genuinely unfinished SD-035 items:** **none.**

---

## 3. Dependency graph / status

Historical planning order (now **satisfied**):

```text
F004 (V1) ──► F003 / F005
F042 ──► F043
F019 ──► F014
F003 ──► F060 (same-user sharing AuthZ clarity)
V1 recommendation pipeline ──► F137 (contract over shared decision core)
Feature surfaces ──► F143 (help sync; formalization)
Portfolio scoping ──► F144 (standalone notes)
```

| Dependency | Status |
|------------|--------|
| F003/F005 for multi-user ops | **Satisfied** |
| F042 for F043 | **Satisfied** |
| F019 before F014 | **Satisfied** |
| F003 before F060 (planning) | **Satisfied** (F060 closed independently with same-user rules) |
| Frozen V1 pipeline for F137 | **Satisfied** |
| F143/F144 after product stabilizes | **Satisfied** as formalization (runtime already existed) |

**Remaining V2-blocking dependencies:** **none.**

Completing F019/F014/F060/F137/F143/F144 does **not** unlock another SD-035 ID — the set is exhausted.

---

## 4. SD-035 completeness audit

| Check | Result |
|-------|--------|
| Authoritative eleven list (`MVP_SCOPE.md` / SD-035) | F003, F005, F014, F019, F042, F043, F060, F127, F137, F143, F144 |
| Formal packs present | **Yes** for all eleven |
| Reconciliation/delivery status recorded | **Yes** (`DOCS.md` §3.B–§3.K + packs) |
| Unfinished SD-035 item | **None** |
| New initiative IDs invented outside SD-035 during this programme | **None** (F015 etc. remain V1/other; not SD-035 V2) |

---

## 5. V2 roadmap drift (housekeeping applied 2026-08-10)

Tracking docs were updated so **current** status matches this reconciliation. Historical scores/phases remain, labelled as planning context.

| Location | Pre-cleanup issue | Post-cleanup |
|----------|-------------------|--------------|
| `V2-ROADMAP.md` | “remaining” / next-initiative language | **CLOSED** banner; phases labelled historical |
| `V2-DEPENDENCIES.md` | postpone F143/F144; open foundations | Current status: all deps **satisfied** |
| `V2-PRIORITIZATION.md` | MOSTLY IMPLEMENTED / first initiative | Final CLOSED state table; scores historical |
| `DOCS.md` §3.A / §3.F | “deferred” framing; F014 “not started” | §3.A CLOSED; §3.F corrected |
| `implementation.md` | Program CLOSED note | Strengthened housekeeping note |
| Initiative packs | Final verdicts | **Current truth** (unchanged except F143 pack confirmation line) |

---

## 6. Consolidated remaining non-blockers

### A. Tests
| Item | Origin | Severity | Blocks release? | Timing |
|------|--------|----------|-----------------|--------|
| Invite↔reset uniqueness cross-check | F003 | Low | No | After V2 close |
| Note/tag cross-profile AuthZ tests | F144 | Medium (parity) | No | After / optional |
| F137 FE component harness | F137 | Low | No | After |
| F143 help/route automated checks | F143 | Low | No | After |
| Narrow F060 residual AuthZ semantics tests | F060 | Low | No | After |

### B. Documentation
| Item | Origin | Severity | Blocks release? | Timing |
|------|--------|----------|-----------------|--------|
| Stale V2-ROADMAP / DEPENDENCIES / PRIORITIZATION phase tables | Program | Medium (confusing) | No | **Addressed** in 2026-08-10 housekeeping (historical labels retained) |
| `DOCS.md` §3.F “F014 not started” wording | Program | Low | No | **Fixed** |
| `API_DOCUMENTATION.md` Knowledge Board title drift | F144 | Low | No | After |
| `implementation.md` SPA-help prose vs static docs | F143 | Low | No | After |
| F019 help “upload” wording | F019 | Low | No | After |

### C. Operational polish
| Item | Origin | Severity | Blocks release? | Timing |
|------|--------|----------|-----------------|--------|
| Knowledge image orphan GC / delete API | F144 | Low–Med | No | After |
| F137 flat aliases / optional cache | F137 | Low | No | After |
| Orphan SPA `components/docs/*` cleanup | F143 | Low | No | After |
| Unrelated full-suite flaky/pre-existing failures (CA UNIQUE, Explorer, etc.) | Outside SD-035 | Suite hygiene | No* | Maintenance |

\*Not introduced as SD-035 blockers; track in normal QA.

### D. Product enhancements explicitly deferred / OOS
| Item | Origin | Severity | Blocks release? |
|------|--------|----------|-----------------|
| Admin force-logout other users | F005 | Deferred | No |
| F014 cash-as-of, realized P&L, export, compare | F014 | Deferred/OOS | No |
| F060 deeper remap / 404-vs-403 polish | F060 | Non-blocker | No |
| F127 extra notification channels | F127 | Deferred | No |
| F144 entity-linked notes, sharing, favorites UI | F144 | OOS/deferred | No |
| SMTP invites | F003 | OOS | No |

**None of the above are product blockers for declaring SD-035 V2 closed.**

---

## 7. Scope-boundary audit

| Boundary | Finding |
|----------|---------|
| F014 vs F015 / F019 | Intact — as-of reconstruction ≠ snapshots ≠ bulk import |
| F060 vs RBAC/tenancy | Intact — same-user multi-profile only; no tenant redesign |
| F137 vs generation | Intact — shared decision core; preview does not persist/cancel |
| F143 vs F144 | Intact — help platform vs research notes product |
| F144 vs AI/entity knowledge | Intact — standalone notes; no invented FKs |
| F019 vs ledger | Intact — bulk uses shared financial unit; no parallel ledger |

No evidence that completed initiatives accidentally absorbed another initiative’s core ownership.

---

## 8. Architecture assessment (high level)

Completed V2 work reinforces intended boundaries:

| Concern | Assessment |
|---------|------------|
| Ledger / write path | Transaction + F019 bulk share financial unit; F014 read-only reconstruction |
| Historical reconstruction | Dedicated F014 API/UI; not live holdings SoT |
| Shared screeners | F060 same-user AuthZ aligned across classic/registry/eligibility |
| Recommendation preview | F137 execution-grade contract over V1 decision logic |
| Contextual help | F143 formalizes catalogue + static `/docs` + sync rule |
| Knowledge Board | F144 portfolio-scoped notes separate from trading engines |
| Profile scoping / AuthZ | Active portfolio pattern consistent across closed packs |
| Derived valuation | F014/F015/holdings ownership distinctions preserved |

No redesign recommended in this reconciliation.

---

## 9. V2 completion assessment

**Yes — SD-035 V2 is complete** in the sense of:

1. All eleven initiatives reconciled with formal packs.  
2. Required runtime capabilities for those initiatives implemented (or formalized where already shipped).  
3. No unresolved blocking initiative or open product PD required for SD-035 closure.  
4. Remaining items are explicitly **non-blocking** polish / deferred OOS / suite hygiene.

Improvements remain possible; that is **not** “V2 unfinished.”

---

## 10. Program-level acceptance criteria

| AC | Status |
|----|--------|
| All SD-035 initiatives accounted for | **PASS** |
| No unresolved blocking initiative | **PASS** |
| Historical V2 dependencies satisfied | **PASS** |
| Completed initiative boundaries intact | **PASS** (audit §7) |
| No known cross-user security regression introduced as open SD-035 gap | **PASS** (residuals are test depth / deferred polish) |
| Roadmap status *can* be updated | **PASS** (cleanup recommended; not blocking) |
| Remaining non-blockers explicitly classified | **PASS** (§6) |

---

## 11. Recommended next phase

### **Option A — V2 CLOSED**

Move to **maintenance / bug-fix / product backlog** mode. Optionally schedule a **documentation hygiene** pass to align `V2-ROADMAP.md` / `V2-DEPENDENCIES.md` / `V2-PRIORITIZATION.md` / `DOCS.md` §3 blurbs with this snapshot — as **maintenance**, not a new V2 initiative.

**Not chosen:**

- **B (HARDENING)** — would only apply if product required clearing §6 items before closure; packs already classify them non-blocking.  
- **C (EXTENSION)** — no unfinished SD-035 capability found.  
- **D (NEW PRODUCT PHASE)** — valid *later* if product starts a new programme; not required to close SD-035.

Any future major capability should be a **separate specification exercise**, not an invented continuation of the SD-035 eleven.

---

## 12. Confirmation

- No application code changed (this document only)  
- No tests / schema / frontend behaviour changed  
- No completed initiative reopened  
- No new initiative ID invented  
- No implementation started  
