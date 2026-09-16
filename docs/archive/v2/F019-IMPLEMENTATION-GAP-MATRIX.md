# F019 Implementation Gap Matrix

**Date:** 2026-08-09  
**Status:** Hardening **delivered** — `F019_COMPLETE_WITH_NON_BLOCKERS`  
**Initiative:** F019 Bulk CSV Import  
**Related:** [F019-BULK-CSV-IMPORT-SPEC.md](./F019-BULK-CSV-IMPORT-SPEC.md), [F019-BOUNDARY.md](./F019-BOUNDARY.md), [F019-POLICY-DECISIONS.md](./F019-POLICY-DECISIONS.md)

### Delivery summary

| Track | Status |
|-------|--------|
| F019 shipped UI + parser | **Hardened** — bulk API commit |
| Shared financial unit (PD-14) | **DONE** |
| Bulk API + batch/row identity | **DONE** |
| Formal V2 pack | **Present** |
| Product policies | **Closed** |
| F019 hardening | **DONE** |

### Classification legend

| Label | Meaning |
|-------|---------|
| `NO_GAP` | CURRENT matches DECIDED |
| `IMPLEMENTED` | Behaviour exists; may still need tests/docs |
| `PARTIAL` | Exists but incomplete vs DECIDED |
| `MISSING` | DECIDED target not implemented |
| `OUT_OF_SCOPE` | Explicitly excluded |
| `DEFERRED` | Postponed |

---

## Gap register

| ID | Area | Topic | Gap | Priority | Notes |
|----|------|-------|-----|----------|-------|
| F019-G001–G005 | Docs / CSV date | | **NO_GAP** | — | |
| F019-G006 | Quoted CSV | | **DEFERRED** | P3 | |
| F019-G007 | Broker formats | | **OUT_OF_SCOPE** | — | |
| F019-G008–G010 | Types / review / paste | | **NO_GAP** | — | |
| F019-G011–G013 | Persist + batch identity | | **NO_GAP** | — | Delivered |
| F019-G014 | Economic fingerprint | | **NO_GAP** | — | Not used |
| F019-G015 | Retry UX | | **NO_GAP** | — | |
| F019-G016 | Ordering | | **NO_GAP** | — | |
| F019-G017–G019 | Financial unit + cash | | **NO_GAP** | — | PD-14 |
| F019-G020–G022 | AuthZ / overwrite / undo | | **NO_GAP** / **OUT_OF_SCOPE** | — | |
| F019-G023 | Bulk API | | **NO_GAP** | — | `POST /api/transactions/bulk` |
| F019-G024 | Max rows | | **DEFERRED** | P3 | |
| F019-G025–G026 | Tests | | **NO_GAP** | — | `BulkTransactionImportTest` |
| F019-G027 | Help | | **NO_GAP** | — | Synced |
| F019-G028–G029 | F014 / CA CSV | | **OUT_OF_SCOPE** | — | |

---

## Remaining non-blockers

- Quoted CSV / broker formats (deferred / OOS)
- Max row soft limit (deferred)
- Optional chronological warnings (PD-06 later)
- FE automated tests (none in repo pattern for this initiative)

---

*End of F019 gap matrix.*  
*Hardening closed 2026-08-09 → F019_COMPLETE_WITH_NON_BLOCKERS.*
