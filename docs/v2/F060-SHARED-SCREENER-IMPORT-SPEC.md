# F060 — Shared Screener Import Specification

**Date:** 2026-08-09  
**Status:** **`F060_COMPLETE_WITH_NON_BLOCKERS`**  
**Initiative:** Collaboration / Screeners (V2)  
**Related:** [F060-BOUNDARY.md](./F060-BOUNDARY.md), [F060-POLICY-DECISIONS.md](./F060-POLICY-DECISIONS.md), [F060-IMPLEMENTATION-GAP-MATRIX.md](./F060-IMPLEMENTATION-GAP-MATRIX.md)

**DECIDED** = product-owner target (2026-08-09) — **implemented**.  
Residual non-blocking: PD-10 (import remap), PD-22 (404 vs 403).

---

## 1. Purpose

Same-user multi-profile sharing and import of screeners with cross-user denial on all shared read/import/resolve paths.

---

## 2. Implemented behaviour (normative)

| Area | Behaviour |
|------|-----------|
| Audience | Same owning user only (`profile.user_id`) |
| Ownership | `profile_id`; no `user_id` column |
| Shared list/import | Classic + registry; `Screener::sharedVisibleTo` |
| Shared exposure | `{ id, name, definition_json }` (classic); registry `projectShared` name+definition + ownership/read_only flags |
| Import | Independent local copy on active profile; no sync |
| Naming | `Name`, `Name (1)`, `Name (2)`, … |
| Eligibility / Discovery / backtest pin | `Screener::ownedOrSameUserShared` |
| Writes | Owning profile only (classic binding) |
| Admin | No bypass |
| Private | Not on shared paths |
| Errors | 404 on deny (PD-22 unchanged) |
| Remap | watchlist→holdings; schedule/telegram off (PD-10 unchanged) |

---

## 3. Scopes (code)

- `Screener::scopeSharedVisibleTo(PortfolioProfile)`  
- `Screener::scopeOwnedOrSameUserShared(PortfolioProfile)`

---

## 4. Acceptance criteria

| ID | Status |
|----|--------|
| AC-I1 Cross-user deny list/import/registry GET | **Met** |
| AC-I2 Same-user allow | **Met** |
| AC-I3 Private non-leak | **Met** |
| AC-I4 Exposure contract | **Met** |
| AC-I5 Import independence | **Met** |
| AC-I6 Name `(1)`, `(2)` | **Met** |
| AC-I7 Eligibility/Discovery same-user | **Met** |
| AC-I8 Classic/registry parity | **Met** |
| AC-I9 Tests assert deny cross-user | **Met** |
| AC-I10 Help same-user wording | **Met** |

---

## 5. Tests

- `tests/Feature/F060SharedScreenerAuthzTest.php`  
- `ScreenerRegistryApiTest::test_shared_screener_appears_in_registry_same_user_only`  
- `ScreenerTest::test_shared_list_and_import`

---

## 6. Non-blockers

- PD-10, PD-22  
- No FE e2e for Shared tab  

---

## 7. Compliance

**`F060_COMPLETE_WITH_NON_BLOCKERS`** — all DECIDED PDs and MUST/ACs satisfied; residual items explicitly non-blocking.
