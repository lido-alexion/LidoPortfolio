# Architecture Compliance Report

**Audit date:** 2026-07-25  
**Primary references:** `specs/architecture/04-System-Architecture.md`, `06-Engine-Overview.md`, `engines/Application-Architecture-Specification.md`

---

## 1. Engine boundaries

| Engine | Boundary intent | Implementation assessment |
|--------|-----------------|---------------------------|
| Data | Market data only | **Compliant** — wraps sync/stocks/prices; no trading decisions |
| Discovery | Candidates/patterns/signals | **Compliant** — orchestrates PatternScan + Screener services |
| Evaluation | Indicators/rules/scores | **Compliant** — no recommendations/notifications |
| Recommendation | Decisions + lifecycle | **Mostly compliant** — owns generation/review; status also set by Execution |
| Notification | Delivery only | **Compliant** — Telegram delivery; does not create recommendations |
| Execution | Orders/positions/txs | **Mostly compliant** — writes recommendation `executed` status (lifecycle cross-cut) |
| Review | Observational analytics | **Compliant** — does not mutate upstream facts |
| Pipeline | Orchestration | **Accepted extra** — not a business engine; coordinates stages |

**Verdict:** Ownership is clear enough for Stage 1. The main boundary smell is Execution mutating Recommendation status instead of calling RecommendationEngine.

---

## 2. Layering

**Specified:**

```text
React UI → REST API → Application Services → Business Engines → Repositories → DB
```

**Actual:**

```text
React UI → TradingOsController → Engines → Eloquent Models / existing Services → MySQL
```

| Layer | Compliance |
|-------|------------|
| Controllers thin | **Good** — validation + serialize + engine calls |
| Engines hold business logic | **Good** |
| Services reused | **Good** — PatternScan, Screener, Holdings, Telegram, PortfolioCalculation |
| Repositories | **Missing** — Eloquent used directly |
| DTOs / interface-driven engines | **Missing** — concrete classes injected via Laravel container |

---

## 3. Separation of concerns

| Concern | Assessment |
|---------|------------|
| UI business logic | **Mostly clean** — pages call APIs; decision rules live in engines |
| Controllers | **Clean** — no scoring/order fill math |
| Config | **Centralized** — `config/trading_os.php` |
| Logging | **Present** via PortfolioLoggerService; not fully structured per App Architecture §7 |
| Auth | **Sanctum** instead of JWT (accepted) |

---

## 4. Dependency direction

Allowed: Data → Discovery → Evaluation → Recommendation → Notification | Execution → Review

| Check | Result |
|-------|--------|
| Pipeline stage order | Compliant |
| Discovery does not call Recommendation | Compliant |
| Notification does not generate recommendations | Compliant |
| Review does not write recommendations/orders | Compliant |
| Recommendation reads Holdings (Execution-owned concept) | Mild reverse read — common and acceptable for position-aware types |
| Execution writes Recommendation status | **Violation (soft)** |

---

## 5. Business logic placement

| Logic | Location | OK? |
|-------|----------|-----|
| Candidate merge/scoring sources | DiscoveryEngine | Yes |
| Indicator + weighted score | EvaluationEngine | Yes |
| BUY/SELL/WATCH/HOLD policy | RecommendationEngine | Yes |
| Accept/Reject/Defer | RecommendationEngine | Yes |
| Order fill + holdings update | ExecutionEngine → existing portfolio services | Yes |
| Telegram formatting | NotificationEngine | Yes |
| Outcome P/L | ReviewEngine | Yes |
| Pattern detection algorithms | PatternScanService (Service layer) | Yes (wrapped) |

---

## 6. Repository usage

**Spec:** Repositories between engines and MariaDB.  
**Actual:** No `app/Repositories` directory. Engines query Eloquent models directly.

**Classification:** Accepted technical deviation for evolving monolith. Increases coupling of engines to persistence schema.

---

## 7. Controller responsibilities

`TradingOsController` (single V1 controller):

- Resolves active portfolio middleware context
- Validates request inputs
- Delegates to engines
- Serializes ApiEnvelope responses

**Assessment:** Appropriate for MVP. Risk: one large controller file; future split by engine module would improve maintainability.

---

## 8. Service responsibilities

Existing `App\Services\*` remain the implementation core for market sync, patterns, screeners, holdings, Telegram. Engines are orchestration façades.

**Assessment:** Matches “evolve existing app” strategy. Risk of dual entry points (legacy controllers still call Services while V1 uses Engines).

---

## 9. Frontend architecture

| Spec | Actual |
|------|--------|
| Separate `frontend/` | Nested under `app/resources/js` |
| TypeScript | JavaScript/JSX |
| features / store / layouts / types | Not adopted |
| TanStack Query | Not used for TOS pages |
| Engine-agnostic pages | Pages named by domain; call `/api/v1` |

**Assessment:** Compatible with existing Lido SPA. Not compliant with Application Architecture folder/tech checklist, but preserves product consistency.

---

## 10. Reuse of existing modules

**Strong reuse (positive):**

- `portfolio_stocks` / `portfolio_stock_prices`
- Holdings, transactions, snapshots
- PatternScanService, Screener stack
- TelegramNotificationService
- PortfolioCalculationService, Sanctum auth, profile middleware

**Avoided greenfield duplication:** Correct architectural choice per project clarifications.

---

## Architecture violations

1. **ExecutionEngine updates `TradingRecommendation.status`** — Recommendation lifecycle ownership leak.  
2. **No repository layer** — Application Architecture non-compliance.  
3. **JWT specified / Sanctum implemented** — documented accepted deviation.  
4. **Physical schema ≠ logical schema names** — accepted (A2).  
5. **Pipeline `positions` stage does not perform Position Review analysis** — stage name mismatch with architecture doc Stage 8.  
6. **`trading_os.pipeline.run_after_daily_sync` unused** — config without consumer.  
7. **Dual API surfaces** — legacy `/api/*` and TOS `/api/v1/*` both active (intentional additive design).

---

## Technical debt (architecture-facing)

- Monolithic `TradingOsController`
- Hardcoded Telegram channel in NotificationEngine
- Evaluation rules embedded in engine (not rule catalog)
- Soft dataset “published” flag
- Frontend TOS logic duplicated patterns across five pages (no shared hooks)

---

## Accepted deviations

| Deviation | Justification |
|-----------|---------------|
| Sanctum vs JWT | A1 / SPA cookie auth already production |
| `portfolio_*` tables | A2 / avoid migration of entire SOS |
| Engines wrap Services | A5 / incremental evolution |
| Telegram only | A9 |
| No Strategy entity | A12 |
| Formal Data publish gates deferred | A13 |
| No Repositories | Pragmatic MVP on Eloquent |

---

## Risks

| Risk | Severity | Notes |
|------|----------|-------|
| Bad OHLCV reaches evaluation without hard publish gate | High | A13 deferral |
| Cross-engine status writes diverge later | Medium | Prefer RecommendationEngine.markExecuted() |
| Legacy vs V1 API confusion for clients | Medium | Document clearly |
| Schedule off by default; ops may forget pipeline | Medium | Manual/UI trigger required |
| Position Review shallow → poor SELL/HOLD quality | Medium | Score-only exits |
| Large controller / no OpenAPI | Low | Maintainability |
