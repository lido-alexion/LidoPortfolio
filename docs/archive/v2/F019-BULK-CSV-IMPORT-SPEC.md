# F019 — Bulk CSV Import Specification

**Date:** 2026-08-09  
**Status:** `F019_COMPLETE_WITH_NON_BLOCKERS` (hardening delivered 2026-08-09)  
**Initiative:** Portfolio History & Import (Phase 3) — **F019 first**; F014 downstream (not in this initiative)  
**Related:** [F019-BOUNDARY.md](./F019-BOUNDARY.md), [F019-POLICY-DECISIONS.md](./F019-POLICY-DECISIONS.md), [F019-IMPLEMENTATION-GAP-MATRIX.md](./F019-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = observed shipped behaviour.  
**DECIDED** = approved V2 target after product-owner decisions (see policy register).  
Where CURRENT is unambiguous and acceptable, V2 may **KEEP** it without inventing new mechanisms.

---

## 1. Purpose

Formalize V2 requirements for **bulk buy/sell transaction entry** via CSV paste + review UI, so portfolio onboarding does not corrupt the ledger, cash, or holdings — and so F014 historical-holdings UI can later trust imported data.

F019 is **data-entry convenience** over the existing V1 transaction ledger. It is **not** a second write stack, not a broker sync product, and not F014 as-of analytics.

---

## 2. Scope

### In scope

| Area | Notes |
|------|--------|
| CSV paste parse (client) | `bulkTransactionCsv.js` |
| Review / edit before save | `BulkTransactionImport.jsx` |
| Persist rows via **bulk commit API** (DECIDED) | All-or-nothing batch; uses shared financial write unit (PD-F019-01, 14, 18) |
| Shared create path for each row in the batch | Ledger + holdings/realizations + cash atomic (PD-F019-14); not parallel inserts |
| Profile-scoped active portfolio | `X-Profile-Id` / `activePortfolio()` |
| Buy / sell equity trades only | Same types as manual transaction form |
| Client fee calculation | Same `feeCalculator` as single-entry form |
| Error reporting for parse + per-row save failures | CURRENT UX |
| Tests / help sync for F019 semantics | Hardening |
| Spec alignment with V2 policies | This pack |

### Explicitly out of scope

| Area | Owner / note |
|------|----------------|
| F014 historical holdings reconstruction UI / as-of analytics | F014 |
| Dedicated bulk API / server-side file ingest storage | Bulk **commit** API is **in scope** (PD-18); stored file upload remains out |
| Broker statement formats (Zerodha/tradebook multi-column) | OUT_OF_SCOPE unless future policy |
| Corporate actions, dividends, transfers, bonus, splits as CSV types | F020 / other; not F019 CSV |
| TOS recommendation execute / pending execution | V1 TOS |
| Email/webhook import channels | SD-009 |
| RBAC / multi-tenant org import | Out of scope |
| Overwrite/replace of existing ledger rows via CSV | Not CURRENT |
| Automatic idempotency keys / content hashing | Not CURRENT — see policies |
| F042/F043 DQ gating of import | Soft only; F019 does not own DQ |

---

## 3. Actors

| Actor | Capability |
|-------|------------|
| Authenticated portfolio user | Paste CSV, review, save into **active** portfolio they can access |
| Admin | No special F019 cross-portfolio privilege |

---

## 4. CURRENT implementation inventory

| Layer | Artefact | Role |
|-------|----------|------|
| UI entry | `TransactionsPage.jsx` — Single / Bulk (CSV) toggle | Hosts importer |
| UI | `BulkTransactionImport.jsx` | Paste → parse → review → sequential save |
| Parser | `bulkTransactionCsv.js` | Line parse + row validation |
| Fees | `feeCalculator.js` (+ Settings fee components) | Per-row fees before POST |
| Dates | `transactionDate.js` / `TransactionDateInput` | Review-step dates (default **today**) |
| API | `POST /api/transactions` | Same as single add |
| Resolve | `StockResolverService` | Symbol + exchange → stock (may create) |
| Ledger | `TransactionWriteService` | Insert + holdings + realizations + buy OHLCV backfill + snapshots |
| Cash | `CashManagementService::applyTradeTransaction` | After successful ledger create (controller) |
| Tests | `tests/js/bulkTransactionCsv.test.mjs` | Parser only — **no PHP feature tests for bulk UI path** |
| Help | `appDocumentation.js` transactions topic | Mentions bulk CSV (wording slightly inaccurate: “upload”) |

**There is no dedicated bulk-import backend endpoint.** Persistence is N sequential single creates.

---

## 5. CURRENT workflow

```text
1. User opens Transactions → Bulk (CSV)
2. Paste CSV text (optional header)
3. Parse & review
   - Invalid lines → parse errors (those lines omitted from review)
   - Valid lines → review table (default exchange NSE, date = local today)
4. User may edit symbol, qty, price, buy/sell, NSE/BSE, date; remove rows
5. Save all (disabled until every remaining row passes client validation)
6. For each row in table order:
   POST /transactions { symbol, exchange, type, quantity, price, fees, transaction_date }
7. Success-all → toast, reset to paste, refresh list/dashboard
   Partial failure → toast “Saved X of Y”; failed row messages listed; review state kept
```

---

## 6. CSV contract (CURRENT)

### Columns (positional)

| # | Name (header hint) | Required | Semantics |
|---|--------------------|----------|-----------|
| 1 | Stock | Yes | Symbol; trimmed; uppercased |
| 2 | Quantity | Yes | Positive **whole number** (≥ 1) |
| 3 | Average Price | Yes | Positive number; **at most 2 decimal places** |
| 4 | Transaction Type | Yes | `BUY`/`B` or `SELL`/`S` (case-insensitive) |

- Header row optional; detected if line contains hints `stock`, `quantity`, `price`, `type`.
- Delimiter: comma; **no quoted-field support** (commas inside cells break parsing).
- **No CSV columns** for: date, exchange, fees, notes, ISIN, BSE code, corporate-action flags.

### Defaults applied on review (not in CSV)

| Field | Default |
|-------|---------|
| `exchange` | `NSE` (editable NSE/BSE) |
| `transaction_date` | Local calendar **today** (editable per row) |
| `fees` | Computed client-side from Settings fee components |

### Unsupported in CSV

Dividends, transfers, corporate actions, HOLD, short sells beyond ledger rules, non-equity types, multi-currency.

---

## 7. Validation semantics (CURRENT)

### Parse-time (client)

- Empty paste → error  
- Header-only → error  
- `< 4` cells → line error; line skipped  
- Bad qty/price/type/symbol → line error; line skipped  
- Valid lines still proceed to review **even if other lines failed** (partial parse)

### Review-time (client)

Before Save all, every remaining row must have:

- Non-empty symbol  
- Integer qty ≥ 1  
- Price > 0  
- Type buy|sell  
- Valid non-future transaction date  

### Server-time (per POST — shared with single entry)

- Sanctum auth + active profile ownership  
- Stock resolve/validate for symbol+exchange (may persist new stock)  
- Type buy|sell; qty > 0; price > 0; fees ≥ 0; date required; date not future  
- Sell qty ≤ available holding (at time of that request)  
- Cash: buy may fail with insufficient cash **after** ledger insert in CURRENT controller ordering (shared V1 path — see boundary / gaps)

---

## 8. Atomicity and failure (CURRENT)

| Scenario | Behaviour |
|----------|-----------|
| Invalid parse lines | Omitted from review; other lines continue |
| Invalid review row | Save blocked until fixed/removed |
| Mid-batch POST failure | **Earlier rows remain saved**; later rows may still be attempted; failures listed |
| Same CSV saved twice | **Creates duplicate ledger rows** (no idempotency) |
| Same logical trade in two files | Duplicate rows (no cross-file dedupe) |

**Classification of CURRENT import:** **row-by-row sequential; partial-success; not all-or-nothing; not idempotent.**

No server transaction wraps the whole batch.

---

## 9. Ledger / cash / holdings integrity (CURRENT)

Each successful POST:

1. `TransactionWriteService::create` → insert ledger row (`source` = manual)  
2. Holdings recalc for profile+stock  
3. FIFO realizations recalc  
4. Buy → sync OHLCV backfill attempt  
5. Portfolio snapshot rebuild from transaction date  
6. Controller → `CashManagementService::applyTradeTransaction` (cash balance update)

**Downstream effects:** holdings qty/avg cost, realized/unrealized P&L views, snapshots, cash balance, analytics that read the ledger.

**Canonical path:** F019 does **not** bypass `TransactionWriteService` for creates. It also does not call holdings/cash services directly from the SPA.

**Integrity notes for V2:**

- Batch is not atomic.  
- Ledger create and cash apply are sequential in the controller (shared V1); cash failure can leave a ledger row without matching cash application for that request (pre-existing shared-path risk).  
- Sell availability depends on prior successful buys in the same batch (order-sensitive).

---

## 10. Ordering (CURRENT)

- Review/submit order = CSV line order (after skipping bad parse lines).  
- **No chronological sort** by date.  
- With default today on all rows, buy-before-sell for the same symbol in the paste order matters for sell qty checks.  
- Historical multi-date imports require manual date edits; unsorted dates can produce surprising sell failures or avg-cost paths identical to manual entry order sensitivity.

---

## 11. Idempotency and duplicates (CURRENT)

| Question | Answer |
|----------|--------|
| Safe to upload same CSV twice? | **No** — duplicates created |
| Content hash / import batch id? | **None** |
| Duplicate row detection within one paste? | **None** (identical lines become two rows) |
| Modify existing transactions via import? | **No** — create only |
| Undo | Via normal transaction delete/edit (V1 CRUD), not F019-specific reverse |

Product decision required before claiming “safe re-import” (see PD-F019-02 / PD-F019-03).

---

## 12. Error / retry UX (CURRENT)

| Stage | UX |
|-------|----|
| Parse | Danger list of line errors; valid rows still enter review if any |
| Review | Invalid rows highlighted (`table-warning`); Save disabled |
| Submit | Progress `Saving… (k/n)`; per-failure symbol + message list |
| Partial success | Toast warning; stay on review (does **not** auto-remove saved rows from table — user may accidentally re-save remaining/all again) |

Recovery: edit/remove rows and Save all again; or Back to CSV and re-paste. No download of “failed rows only” CSV.

---

## 13. Security / authorization (CURRENT)

- Authenticated Sanctum SPA session required.  
- `api` client attaches `X-Profile-Id` for active portfolio.  
- `TransactionController::store` uses `\activePortfolio()` — imports only into that profile.  
- No admin F019 cross-profile import API.  
- Stock master validation prevents arbitrary garbage symbols (subject to resolver rules).

---

## 14. Requirements

### MUST

| ID | Requirement |
|----|-------------|
| F019-R001 | F019 SHALL create transactions only for the authenticated user’s **active** portfolio profile. |
| F019-R002 | F019 creates SHALL use the canonical ledger path (`POST /api/transactions` → `TransactionWriteService` / SD-021), not a parallel insert. |
| F019-R003 | CSV types SHALL be limited to **buy** and **sell** equity trades unless a future policy expands types. |
| F019-R004 | Quantity SHALL be a positive whole number; price SHALL be positive with at most 2 decimal places at parse (aligned with CURRENT). |
| F019-R005 | Users SHALL be able to review and edit parsed rows (including exchange and date) before persistence. |
| F019-R006 | Client SHALL compute fees using the same fee-component model as single transaction entry unless policy changes. |
| F019-R007 | Server SHALL enforce sell-quantity ≤ available holding and non-future dates (shared validation). |
| F019-R008 | F019 SHALL NOT implement overwrite/replace of existing transactions via CSV. |
| F019-R009 | F019 SHALL NOT absorb F014 historical-holdings UI or as-of reconstruction product scope. |
| F019-R010 | F019 SHALL NOT introduce email/webhook/broker-sync import channels. |
| F019-R011 | F019 SHALL NOT introduce RBAC/multi-tenant import semantics. |
| F019-R012 | Hardening SHALL implement **DECIDED** targets: all-or-nothing bulk commit (PD-01/18), batch/row identity (PD-02/03), retry UX (PD-15), and shared financial-unit atomicity (PD-14). |
| F019-R013 | Feature tests and/or equivalent automated coverage SHOULD/MUST per DECIDED test policy prove critical ledger behaviours for the import path. |
| F019-R014 | Contextual help SHALL accurately describe paste/review/save behaviour (not misleading “file upload” if paste-only remains). |

### SHOULD

| ID | Requirement |
|----|-------------|
| F019-R040 | Parser SHOULD remain resilient and report per-line errors without silently inventing values. |
| F019-R041 | After partial failure, UX SHOULD reduce accidental re-creation of already-saved rows (subject to PD-F019-01). |
| F019-R042 | PHP and/or end-to-end coverage SHOULD exercise at least one multi-row import through the API path. |

### MUST NOT

| ID | Requirement |
|----|-------------|
| F019-R050 | MUST NOT bypass `TransactionWriteService` for creates. |
| F019-R051 | MUST NOT silently treat F014 as done by shipping only CSV import. |
| F019-R052 | MUST NOT invent idempotency mechanisms not approved in policy decisions. |

---

## 15. Acceptance criteria

| ID | Criterion | Notes |
|----|-----------|-------|
| F019-AC001 | Parse sample CSV with header → review rows with correct symbol/qty/price/type | CURRENT + JS tests |
| F019-AC002 | Invalid lines reported; do not invent fill values | CURRENT + JS tests |
| F019-AC003 | Save posts to `POST /transactions` with fees and date | CURRENT |
| F019-AC004 | Successful row updates holdings/cash via shared path | CURRENT (shared) |
| F019-AC005 | Foreign/inactive profile cannot be targeted without active profile switch | CURRENT authZ |
| F019-AC006 | Unsupported type (e.g. HOLD) rejected at parse | CURRENT |
| F019-AC007 | Atomicity: all-or-nothing per CSV import (PD-F019-01); zero rows on validation/business failure | **DECIDED** — implement |
| F019-AC008 | Batch ID + row identity; completed batch not re-submittable; new batch may repeat data (PD-F019-02/03/15) | **DECIDED** — implement |
| F019-AC009 | Help text matches shipped UX | PARTIAL today |
| F019-AC010 | Automated tests cover DECIDED critical paths | PARTIAL (parser only) |

---

## 16. Determinism / idempotency

| Topic | V2 stance |
|-------|-----------|
| Parse determinism | Same CSV text → same logical rows (ids ephemeral) |
| Save idempotency | **Not CURRENT** — requires PD-F019-02/03 |
| Batch atomicity | **Not CURRENT** — requires PD-F019-01 |

---

## 17. Security

Profile ownership + Sanctum only. No new auth model. Symbol resolution uses existing validation/persist rules.

---

## 18. V1 / V2 boundary

- V1 owns: `TransactionWriteService`, cash ledger, holdings calc, single transaction CRUD, fee settings, stock resolver.  
- V2 F019 owns: formal CSV contract, import UX semantics, hardening/tests/help, policy closure for batch/duplicate behaviour.  
- V2 F014 owns: historical holdings reconstruction presentation — **after** F019.

---

## 19. Dependencies

| Dependency | Status |
|------------|--------|
| SD-021 TransactionWriteService | Done |
| Active portfolio / Sanctum | Done |
| Fee components settings | Done |
| F014 | Downstream — do not implement in F019 |
| F003/F005/F042/F043/F127 | Complete — do not reopen |

---

## 20. Open decisions

**None** for product policy. See [F019-POLICY-DECISIONS.md](./F019-POLICY-DECISIONS.md). Initiative status: **`READY_FOR_IMPLEMENTATION`**.

---

## 21. Implementation notes (non-normative)

Hardening order (gap matrix): PD-14 shared financial unit → bulk all-or-nothing API (batch/row ids) → client UX (PD-15) → tests → help. Client sequential `POST /transactions` Save-all is **not** the DECIDED model.

---

*End of F019 specification.*  
*Status: READY_FOR_IMPLEMENTATION — implement DECIDED targets; do not absorb F014.*
