# Portfolio Snapshots (F015) — Policy Decisions (Retrospective)

**Document:** V2.1 Retrospective POLICY register  
**Date:** 2026-08-10  
**Status:** CURRENT formalization  
**Companions:** SPEC, BOUNDARY, GAP matrix  

**Rules:** Do not invent decisions. Classify CURRENT behaviour. Reference F014 / Cash / implementation where already decided. Status: **DECIDED** | **CURRENT** | **DECISION_REQUIRED** | **DEFERRED** | **OOS** | **TECHNICAL DEBT**

---

## Register

| ID | Topic | Status | Decision / CURRENT behaviour | Evidence |
|----|-------|--------|------------------------------|----------|
| **PS-01** | Snapshot source of truth | **CURRENT** / aligned with F014 | Snapshots are **rebuildable materialized cache**, not independent SoT; ledger + prices are upstream | `implementation.md`; F014 PD-14 |
| **PS-02** | Rebuild strategy | **CURRENT** | Range rebuild with `updateOrCreate`; not append-only cron logs | `PortfolioSnapshotRebuildService` |
| **PS-03** | Valuation date semantics | **CURRENT** | Equity session dates only; close on/before D; weekends purged | TradingCalendar + rebuild tests |
| **PS-04** | Missing prices (F015) | **CURRENT** | Silent-zero for aggregate market value + warning log | `calculatePortfolioStateForDate` |
| **PS-05** | Missing prices (F014 contrast) | **DECIDED** (F014) | F014 must not silent-zero in UI/API | F014 BOUNDARY / SPEC — do not reopen |
| **PS-06** | Fees in invested_value | **CURRENT** | Fee-exclusive cost basis via shared holdings engine | Holdings replay |
| **PS-07** | Deleted / edited transactions | **CURRENT** | Rebuild from earliest affected date → today using ledger as known today | `rebuildAfterTransactionChange` |
| **PS-08** | Corporate actions | **CURRENT** | CA apply rewrites ledger then triggers rebuild; no belief-time series | `CorporateActionService` |
| **PS-09** | Soft-fail vs financial unit | **CURRENT** | Snapshot errors after commit do not undo ledger/cash (default soft-fail) | `TransactionWriteService` |
| **PS-10** | Cash in portfolio_value | **CURRENT** / **OOS** to add | Cash excluded from snapshot valuation | Persist columns; Cash BOUNDARY |
| **PS-11** | Live Dashboard vs snapshots | **CURRENT** | Headline metrics = live holdings calc; growth series = snapshots | `DashboardController` |
| **PS-12** | Lazy rebuild on empty growth | **CURRENT** | Dashboard rebuilds once if txs exist and snapshots empty | `portfolioGrowthSeries` |
| **PS-13** | Profile isolation | **CURRENT** | `profile_id` + `activePortfolio()` | Controller + API tests |
| **PS-14** | Persisted holdings detail | **CURRENT** | Not stored; UI uses aggregates only | `persistSnapshot` |
| **PS-15** | Retention / archival | **DEFERRED** / **NOT FOUND** | No purge policy beyond weekend cleanup and portfolio delete | Code inventory |
| **PS-16** | Historical accuracy expectation | **CURRENT** | “Worth on D given ledger known today” — not immutable point-in-time beliefs | Philosophy in `implementation.md` |
| **PS-17** | PHPUnit skip create/update rebuild | **TECHNICAL DEBT** / **CURRENT** | Create/update skip post-commit in unit tests; delete still rebuilds | `TransactionWriteService` |
| **PS-18** | F019 multi-rebuild | **TECHNICAL DEBT** | Per-row post-commit may rebuild repeatedly in a bulk import | Bulk import service |
| **PS-19** | Merge F014 + F015 | **OOS** | Explicitly forbidden by F014 BOUNDARY | F014-R005 / PD-18 |
| **PS-20** | Cash-as-of on snapshots | **OOS** | Not in CURRENT F015; Cash/F014 OOS | Cash + F014 packs |

---

## Source-of-truth statement (canonical)

**Status: CURRENT (documented; consistent with F014 DECIDED PD-14)**

1. The **transaction ledger** is SoT for reconstructing what was held on date D.  
2. **F015 snapshot rows** are a **derived, rebuildable cache** optimized for equity-curve display and Dashboard growth.  
3. **F014** answers per-stock as-of questions on demand and **must not** treat F015 rows as SoT.  
4. **Live** Dashboard portfolio value uses **current holdings + latest quotes**, not “trust latest snapshot only.”

---

## Open items

| ID | Ask | Notes |
|----|-----|-------|
| PS-15 | Should old snapshot rows be retained forever or capped? | No product pressure observed; **DEFERRED** |
| — | Align F015 missing-price UX with F014? | Would be a **product change** — not decided; do not silently change CURRENT silent-zero |

No blocking `DECISION_REQUIRED` items to describe CURRENT behaviour.

---

## Out of scope (do not decide here)

- Redesign of Dashboard ownership  
- Hardening F019 to single end-of-batch rebuild (implementation change)  
- Belief-time corporate-action history  
- Absorbing F014 into Snapshots  

---

*End of policy register.*
