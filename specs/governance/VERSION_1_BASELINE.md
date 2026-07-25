# Version 1.0 Baseline

**Document:** Governance — Version 1.0 Baseline  
**Status:** Frozen  
**Baseline ID:** TOS-V1.0-2026-07-25  

Related: [`MVP_SCOPE.md`](./MVP_SCOPE.md) · [`SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md) · [`PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md) · [`DOCUMENT_PRECEDENCE.md`](./DOCUMENT_PRECEDENCE.md)

---

## Purpose

This document **freezes** the Version 1.0 implementation baseline. Future 1.x work evolves **from this baseline** and updates **governance** documents. Historical architecture and engine specifications under `/specs` remain the long-term intent and are **not** rewritten to match V1.0 code.

---

## Implementation Date

| Field | Value |
|-------|-------|
| MVP implementation pass | 2026-07-25 |
| Independent audit | 2026-07-25 |
| Governance pack | 2026-07-25 |

---

## Specification Version

| Set | Location | Version / status |
|-----|----------|------------------|
| Architecture docs | `specs/architecture/` | 0.1 Draft (intent) |
| Engine / REST / schema / roadmap | `specs/engines/` | 1.0 Draft (intent); Roadmap “Approved for Execution” |
| Progress / demo | `specs/IMPLEMENTATION_PROGRESS.md`, `MVP_DEMO_CHECKLIST.md` | Working MVP definitions |

Original specs are **intent**, not a claim that every SHALL was implemented without deviation.

---

## Audit Version

| Artifact | Path |
|----------|------|
| Audit pack | `specs/audit/` |
| Verdict | `specs/audit/MVP_VERDICT.md` |
| Traceability | `specs/audit/SPECIFICATION_TRACEABILITY.md` |
| Architecture compliance | `specs/audit/ARCHITECTURE_COMPLIANCE.md` |
| API / UI / DB inventories | `specs/audit/API_INVENTORY.md`, `UI_INVENTORY.md`, `DATABASE_MAPPING.md` |
| Limitations / debt | `specs/audit/KNOWN_LIMITATIONS.md`, `TECHNICAL_DEBT.md` |
| Manual acceptance script | `specs/audit/MVP_TEST_SCRIPT.md` |

**Audit conclusion:** Clarified MVP requirements **YES** (~90%); full-spec fidelity ~70%; recommendation **Ready for Internal Testing Only**.

---

## Technology Stack (baseline)

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.x, Laravel, Eloquent |
| Auth | Laravel Sanctum (SPA cookies) |
| Database | MySQL / MariaDB, `portfolio_*` + `portfolio_tos_*` |
| Engines | `App\Engines\*` + `DailyDecisionPipeline` |
| Frontend | React (JSX), Bootstrap, Vite, React Router, Axios |
| Notifications | Telegram |
| Hosting target | GoDaddy / cPanel (existing deploy scripts) |
| Tests | PHPUnit feature tests (incl. `TradingOsPipelineTest`); no Vitest suite |

---

## Accepted Deviations

Authoritative list: [`SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md) (SD-001 … SD-020).

Headline Accepted decisions:

- Sanctum instead of JWT  
- `portfolio_*` / `portfolio_tos_*` schema mapping  
- PatternScan + Screener reuse  
- Telegram-only notifications  
- Manual execution (no broker)  
- Engines wrapping existing Services  
- Dual `/api` + `/api/v1` surfaces  
- User-review recommendation states  
- Nested `app/` React SPA (not separate frontend repo)

Deferred decisions (not in V1.0 product, tracked in backlog): formal data publish gates, Strategy, repositories/DTOs, deep Position Review, OpenAPI, trading calendar, etc.

---

## Known Limitations

See `specs/audit/KNOWN_LIMITATIONS.md`. Material baseline limitations:

- Soft dataset publish / no trading calendar product  
- Shallow Position Review  
- Telegram-only; may skip if unconfigured  
- Pipeline schedule and post-sync hook off/unwired by default  
- No OpenAPI; no frontend automated tests for TOS pages  

---

## Known Technical Debt

See `specs/audit/TECHNICAL_DEBT.md` and Critical/High items in [`PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md).

Priority hardening before broader release: PB-001–PB-003, PB-010–PB-011, PB-014–PB-015, PB-017.

---

## Repository State

| Item | Baseline expectation |
|------|----------------------|
| Application root | `app/` (Laravel + React) |
| Engines | `app/app/Engines/` |
| Config | `app/config/trading_os.php` |
| Migrations | `2026_07_25_000002_*`, `2026_07_25_000003_*` |
| V1 API | `routes/api.php` prefix `v1` |
| UI routes | `/candidates`, `/evaluations`, `/recommendations`, `/review`, `/notification-history` |
| Command | `php artisan portfolio:decision-pipeline` |
| Scope definition | [`MVP_SCOPE.md`](./MVP_SCOPE.md) |

Exact git commit hash is environment-specific; treat **this document + audit date + migrations above** as the logical freeze marker when tagging releases.

---

## Release Recommendation

| Posture | Status |
|---------|--------|
| Clarified MVP workflow complete | Yes |
| Ready for Internal Testing Only | **Yes — recommended** |
| Ready for Beta Testing | Not yet |
| Ready for Production | Not yet |

Promotion requires soak using `specs/audit/MVP_TEST_SCRIPT.md` and progress on Critical backlog items.

---

## Evolution rule

1. Do **not** edit historical architecture/engine specs to “match” V1.0.  
2. Record new deviations as SD-xxx updates.  
3. Change product scope via MVP_SCOPE revisions or a new VERSION_1_x baseline.  
4. Schedule work via PRODUCT_BACKLOG.  
5. Follow [`DOCUMENT_PRECEDENCE.md`](./DOCUMENT_PRECEDENCE.md) when documents conflict.
