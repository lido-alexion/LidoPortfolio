# Cash Management — Implementation Gap Matrix

**Document:** V2.1 Retrospective GAP matrix  
**Date:** 2026-08-10  
**Status:** Post–WS-B CURRENT  
**Companions:** SPEC, BOUNDARY, POLICY  

Statuses: **IMPLEMENTED** | **PARTIAL** | **MISSING** | **TEST GAP** | **DOCUMENTATION GAP** | **TECHNICAL DEBT** | **DEFERRED** | **OOS**

---

## Matrix

| Behaviour / requirement | Current implementation | Status | Evidence | Tests | Risk | Recommended future action |
|-------------------------|------------------------|--------|----------|-------|------|---------------------------|
| Per-profile cash account | `CashAccount` unique `profile_id` | **IMPLEMENTED** | Migration 000007 | Indirect | Low | None |
| Deposit | API + UI + `deposit` | **IMPLEMENTED** | `CashController` | Seeded in many tests; thin dedicated | Low | Optional dedicated assert suite |
| Withdraw ≤ available | `withdraw` gates on available | **IMPLEMENTED** | Service | D4 withdraw 422 | Low | Keep |
| Adjust ± | API + UI | **IMPLEMENTED** | Service | **TEST GAP** | Low | Add adjust feature tests |
| Adjust reason required | Optional remarks | **DOCUMENTATION GAP** / **DECISION_REQUIRED** | Spec table vs code | — | Low | Resolve CM-06; sync arch/help |
| Ledger statement | `GET /cash/ledger` limit 100 | **PARTIAL** | No pagination/export | Indirect | Low | DEFERRED export |
| Buy cash apply | `applyTradeTransaction` | **IMPLEMENTED** | Write service | Bulk + WS-B | Low | None |
| Sell cash apply | Same | **IMPLEMENTED** | Write service | WS-B sell update | Low | None |
| Update cash sync | `updateFinancialUnit` reverse+apply | **IMPLEMENTED** | WS-B D1 | `FinancialIntegrityHardeningTest`, `TransactionUpdateTest` | Low | None — was defect, fixed |
| Delete cash atomicity | `deleteFinancialUnit` | **IMPLEMENTED** | WS-B D2 | D2 success + rollback tests | Low | None — was defect, fixed |
| Bulk cash atomicity | F019 commit + balance preflight | **IMPLEMENTED** | PD-F019-14 | `BulkTransactionImportTest` | Low | None |
| Soft reservation | Fields + no balance debit | **IMPLEMENTED** | WS-B D4 PO | D4 manual + bulk + cancel | Low | Preserve; help clarity |
| Reserve on approve | `reserveForApproval` | **IMPLEMENTED** | Lifecycle | Pipeline + D4 | Low | None |
| Cancel releases reserve | `releaseReservation` | **IMPLEMENTED** | Lifecycle | D4 cancel test | Low | None |
| Convert on execute | `convertReservation` | **IMPLEMENTED** | ExecutionEngine | Pipeline | Low | None |
| Manual fill + complete atomic | Outer store txn | **IMPLEMENTED** | WS-B D3 | Covered via pipeline/create paths | Low | None |
| Execution overwrite guard | Reject different tx id | **IMPLEMENTED** | WS-B D5 | D5 tests | Low | None |
| Insufficient cash rejects trade | `post` validation | **IMPLEMENTED** | Service | Bulk + D1 | Low | None |
| Negative balance prevented | `post` | **IMPLEMENTED** | Service | Via insufficient paths | Low | None |
| Profile / cross-user isolation | `activePortfolio()` | **IMPLEMENTED** | Middleware | D1 404; D5 cross-profile | Low | Optional cash summary cross-profile test |
| Cash-as-of | — | **OOS** / **MISSING** as product | F014 OOS | — | — | V3 / separate decision |
| Broker reconciliation | — | **OOS** | Arch §7 | — | — | V3 |
| Hard reservation | — | **OOS** | PO | Explicit anti-tests in D4 | — | Do not implement |
| Dedicated CashManagementTest | — | **TEST GAP** | Coverage spread across suites | Medium (maintainability) | Add focused unit/feature file |
| Help: soft buy vs available | Help emphasizes available for funding | **DOCUMENTATION GAP** / **PARTIAL** | `appDocumentation.js` cash topic | — | Low | Clarify soft-buy wording in help (future sync) |
| Concurrent reserve vs buy | Soft race possible | **TECHNICAL DEBT** | Audit | No concurrency suite | Low for single-operator | Accept or document |
| Reconstruct balance from ledger | Not implemented | **CURRENT** OK | Cached SoT | — | Low if `post`-only | Optional integrity check job (DEFERRED) |
| V2-style pack (pre this pass) | Was shadow | **DOCUMENTATION GAP** → **addressed** by this pack | WS-C | — | — | Maintain pack with behaviour changes |

---

## WS-B defect verification

| ID | Audit finding | Post-hardening |
|----|---------------|----------------|
| WSB-D1 | Update ignored cash | **FIXED** — not an open defect |
| WSB-D2 | Delete non-atomic | **FIXED** |
| WSB-D4 | Soft reservation | **DECIDED / preserved** — not a defect |
| WSB-D5 | Execution overwrite | **FIXED** |
| WSB-D3 | Manual fill two-phase | **FIXED** (low-risk unify) |

No new severe financial defects discovered during this retrospective pass.

---

## Gap priority (documentation / tests only — do not implement features here)

| Priority | Item | Class |
|----------|------|-------|
| P1 | Resolve CM-06 adjust reason policy; sync arch SPEC table | DECISION_REQUIRED |
| P1 | Help text clarify soft buys vs available | DOCUMENTATION GAP |
| P2 | Dedicated CashManagement feature/unit tests (deposit/adjust edges) | TEST GAP |
| P2 | Cash summary cross-profile AuthZ explicit test | TEST GAP |
| P3 | Ledger export / pagination | DEFERRED |
| — | Cash-as-of / broker / hard reserve | OOS / V3 |

---

## Confirmation

- Matrix reflects **post–WS-B** code.  
- Do not reopen F019/F014/F137.  
- No application changes in this documentation pass.  
