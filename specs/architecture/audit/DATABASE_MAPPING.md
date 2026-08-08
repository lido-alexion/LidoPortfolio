# Database Mapping Report

**Audit date:** 2026-07-25  
**Sources:** `specs/architecture/data/Database-Schema-Specification.md`, `specs/architecture/platform/System-Domain-Model.md`, migrations `2026_07_25_000002_*`, `2026_07_25_000003_*`, Eloquent models

---

## Domain entity → physical schema

| Domain Entity | Physical Table | Owning Engine | Primary Relationships |
|---------------|----------------|---------------|----------------------|
| Security | `portfolio_stocks` | Data | 1→N price bars; referenced by candidates, recommendations, orders, holdings |
| Price Bar | `portfolio_stock_prices` | Data | N→1 Security (`stock_id`) |
| Trading Session | *(none)* | Data (spec) | Not modeled; date lives on price bar `price_date` |
| Market Dataset / Dataset Version | Logical only | Data | `DataEngine::currentDatasetVersion()` = `ohlcv-{max price_date}` |
| Import Job | `portfolio_sync_runs` (approx) | Data | Sync history; not full Created→Published state machine |
| Discovery Run | `portfolio_tos_discovery_runs` | Discovery | N→1 PortfolioProfile; 1→N Candidates |
| Candidate | `portfolio_tos_candidates` | Discovery | N→1 DiscoveryRun; N→1 Security; 1→0..1 EvaluationResult |
| Evaluation Run | `portfolio_tos_evaluation_runs` | Evaluation | N→1 Profile; N→1 DiscoveryRun; 1→N Results |
| Evaluation Result | `portfolio_tos_evaluation_results` | Evaluation | N→1 EvaluationRun; N→1 Candidate; 1→0..1 Recommendation |
| Recommendation | `portfolio_tos_recommendations` | Recommendation | N→1 EvaluationResult; N→1 Security; 1→N Reviews/Notifications/Orders |
| Recommendation Review | `portfolio_tos_recommendation_reviews` | Recommendation | N→1 Recommendation; N→1 User |
| Notification | `portfolio_tos_notifications` | Notification | N→1 Recommendation (nullable allowed by model); N→1 Profile |
| Order | `portfolio_tos_orders` | Execution | N→1 Recommendation (optional); N→1 Security; 1→N OrderTransactions |
| Order↔Ledger link | `portfolio_tos_order_transactions` | Execution | N→1 Order; N→1 `portfolio_transactions` |
| Transaction (ledger) | `portfolio_transactions` | Execution (shared legacy) | Portfolio truth for buys/sells |
| Position | `portfolio_holdings` | Execution (shared legacy) | Derived from transactions via HoldingsCalculationService |
| Review Report | `portfolio_tos_review_reports` | Review | N→1 Profile; 1→N Metrics |
| Review Metric | `portfolio_tos_review_metrics` | Review | N→1 Report |
| Pipeline Run | `portfolio_tos_pipeline_runs` | Pipeline (ops) | N→1 Profile; stages_json audit |
| Portfolio / Profile | `portfolio_profiles` (legacy) | Existing app | Scopes all TOS runs |
| Watchlist | `portfolio_watchlists` + items | Existing app | Discovery membership source |
| Screener Hit | `portfolio_screener_run_hits` (legacy) | Existing / Discovery consumer | Discovery input |
| Pattern result | Ephemeral / service output | Discovery consumer | Stored inside candidate evidence JSON |

---

## Engine ownership summary

```text
Data Engine          → portfolio_stocks, portfolio_stock_prices, portfolio_sync_runs
Discovery Engine     → portfolio_tos_discovery_runs, portfolio_tos_candidates
Evaluation Engine    → portfolio_tos_evaluation_runs, portfolio_tos_evaluation_results
Recommendation Engine→ portfolio_tos_recommendations, portfolio_tos_recommendation_reviews
Notification Engine  → portfolio_tos_notifications
Execution Engine     → portfolio_tos_orders, portfolio_tos_order_transactions
                       (+ writes portfolio_transactions, portfolio_holdings)
Review Engine        → portfolio_tos_review_reports, portfolio_tos_review_metrics
Orchestration        → portfolio_tos_pipeline_runs
```

---

## Duplicate concepts

| Concept A | Concept B | Assessment |
|-----------|-----------|------------|
| Spec `securities` | `portfolio_stocks` | Intentional mapping (A2) |
| Spec `price_bars` | `portfolio_stock_prices` | Intentional mapping |
| Spec `positions` | `portfolio_holdings` | Intentional mapping |
| Spec `transactions` | `portfolio_transactions` + `portfolio_tos_order_transactions` | Split: ledger vs order link |
| Spec Recommendation status `active` | MVP `pending_review` / `accepted` | Intentional lifecycle enhancement; migration remaps legacy `active` |
| Legacy Alerts | TOS Notifications | Parallel concepts; different engines |

---

## Unused / lightly used artifacts

| Artifact | Notes |
|----------|-------|
| `trading_os.recommendation.max_position_pct` | Config present; not enforced in RecommendationEngine |
| `trading_os.pipeline.run_after_daily_sync` | Config present; no consumer found |
| Order status `rejected` | Spec mentions; cancel path uses `cancelled` |
| Dedicated `trading_sessions` table | Spec only; unused |
| Spec-named greenfield tables | Never created (by design) |

---

## Deprecated

| Item | Notes |
|------|-------|
| Recommendation status `active` | Remapped to `pending_review` in migration 000003; generate() still cancels legacy `active` rows |
| `RecommendationEngine::listActive()` | Marked deprecated in engine; list open/pending used instead |

---

## Intentional schema deviations

1. **Prefix `portfolio_tos_*`** instead of unprefixed logical names.  
2. **No `trading_sessions` table** — date on price rows.  
3. **No separate positions table** — holdings.  
4. **Extra tables:** recommendation_reviews, pipeline_runs, order_transactions bridge.  
5. **Extra columns:** `reference_price`, `limit_price`, `notes`, `cancelled_at`, idempotency_key, etc. — support MVP UX.  
6. **Foreign keys** present on TOS tables to profile/security/recommendation as migrated.

---

## Integrity observations

- Candidates uniquely scoped per discovery run + security (verify unique indexes in migration).  
- Executed ledger rows are not soft-deleted by TOS cancel (cancel only before execute) — good.  
- Historical recommendations superseded by cancellation of pending/deferred — auditable via status, not version chain beyond `version` field.
