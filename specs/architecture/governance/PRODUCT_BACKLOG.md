# Product Backlog

**Document:** Governance — Product Backlog  
**Version:** 1.0  
**Status:** Active  
**Effective:** 2026-07-25  
**Source:** MVP implementation + `specs/audit` findings  

Related: [`./MVP_SCOPE.md`](./MVP_SCOPE.md) · [`./SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md) · [`./ARCHITECTURE_REPOSITORY_GOVERNANCE.md`](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) · [`../audit/TECHNICAL_DEBT.md`](../audit/TECHNICAL_DEBT.md)

**Post-V3 forward tracking:** After **V3 COMPLETE** (2026-08-26), schedule new work via [`../../LidoPortfolio-V4-Wishlist.md`](../../LidoPortfolio-V4-Wishlist.md). This backlog remains a historical V1-deferred index; prefer V4-* IDs that reference PB-* rather than conflicting status updates here.

---

## Purpose

Authoritative roadmap of work **deferred from Version 1.0**. Priorities guide sequencing; Suggested Release is indicative, not a commitment.

**Priority:** Critical | High | Medium | Low  
**Suggested Release:** 1.1 | 1.2 | 2.0 | Future

---

## Critical

| ID | Category | Feature | Description | Reason Deferred | Dependencies | Suggested Release |
|----|----------|---------|-------------|-----------------|--------------|-------------------|
| PB-001 | Data Engine | Hard dataset publish gate | Validate OHLCV completeness/duplicates; only published datasets feed discovery | SD-004; MVP reused sync | Sync pipeline, dataset_version table | 1.1 |
| PB-002 | Data Engine | Immutable dataset versioning | Replace date-string version with reproducible published snapshot id | Audit TD-13 | PB-001 | 1.1 |
| PB-003 | Recommendation | Deep Position Review | Stop-loss, exit rules, position health, allocation impact before/with recommendations | SD-017; Stage 8 shallow | Holdings, ATR/risk config | 1.1 |

---

## High

| ID | Category | Feature | Description | Reason Deferred | Dependencies | Suggested Release |
|----|----------|---------|-------------|-----------------|--------------|-------------------|
| PB-010 | Infrastructure | Wire pipeline after daily sync | Honour `trading_os.pipeline.run_after_daily_sync` | Config unused | DailyMarketSyncService | 1.1 |
| PB-011 | Infrastructure | Production-safe scheduled pipeline | Enable/document schedule with timezone and failure alerts | Default off for safety | PB-010, ops runbook | 1.1 |
| PB-012 | Notification | Channel abstraction | Interface for Telegram/email/webhook; config-driven selection | SD-009 hardcoded channel | NotificationEngine refactor | 1.2 |
| PB-013 | Notification | Email notifications | Deliver recommendation events by email | Out of V1.0 scope | PB-012 | 1.2 |
| PB-014 | Architecture | Recommendation markExecuted API | Execution calls RecommendationEngine instead of direct status write | SD-018 ownership | ExecutionEngine | 1.1 |
| PB-015 | Recommendation | Per-symbol target allocation + cash | Explicit targets and cash balance beyond default/max position % | V1.0 uses config default/max % only (SD-023) | Portfolio settings | 1.1 |
| PB-015a | Recommendation | Opinion→Decision analytics | Review dashboards: bullish→increase rates, reject rates by action | Needs soak data | ReviewEngine | 1.2 |
| PB-016 | Developer Experience | OpenAPI for `/api/v1` | Machine-readable contract | SD-019 | Stable v1 routes | 1.2 |
| PB-017 | Review | Soak / production migrate checklist | Harden cPanel migrate + Telegram live test | Release posture Internal Only | Deploy scripts | 1.1 |
| PB-054 | Evaluation / Strategy | Wire Strategy indicator parameters into Evaluation | Strategy UI params (`rsi_period`, lookbacks, SMA/ATR/vol periods, benchmark) are saved but EvaluationEngine uses `trading_os.evaluation` — see TD-19 / analysis §10 | Discovered 2026-07-30; **out of scope** for Indicator Registry Phases 1–3 (SD-033) | EvaluationEngine, StrategyConfigurationService | 1.1 |
| PB-055 | Architecture | Indicator Registry Phase 1–3 | Registry module + seed from catalogues; Admin read-only UI; consumers discover via Registry façades. **Plan:** [../indicators/10-Indicator-Registry-Implementation-Plan.md](../indicators/10-Indicator-Registry-Implementation-Plan.md) Epics 1–3 (+5 discovery) | SD-033 design accepted; Epics 1–2 landed in code (metadata + façades); Admin UI remaining | ScreenerCatalog, SupportedIndicators | 1.1 |
| PB-056 | Architecture | Indicator Registry Phase 4–5 | Declare deps + formula_explanation; persist definition versions on Evaluation/Recommendation evidence | After PB-055 | Evaluation evidence schema | 1.2 |
| PB-057 | Indicators | Liquidity & Tradability indicators | Implement planned Primaries (turnover, gaps, circuits, …) + Liquidity Score / Tradability Score composites | Metadata in SD-033; formulas deferred | PB-055, market event data | 1.2 |
| PB-058 | Architecture | Trading Artifact Framework Phase 1–2 | Shared envelope schemas; Screener artifact metadata + export/import (**keep `definition_json`**). **Spec:** [../indicators/11-Trading-Artifact-Framework.md](../indicators/11-Trading-Artifact-Framework.md) | SD-034 design accepted; not coded | Screener module, SD-033 | 1.2 |
| PB-059 | Architecture | Trading Artifact Framework Phase 3–4 | Strategy artifact library + portfolio binding; umbrella ArtifactRegistry + dependency resolver | After PB-058; preserve Save-in-place UX initially | Strategy Configuration, PB-058 | 1.2 |
| PB-060 | Architecture / AI | Trading Artifact Framework Phase 5 | AI catalogue cards + draft-from-schema validate-before-activate | After PB-059 | ArtifactRegistry, UI | 1.3 |

---

## Medium

| ID | Category | Feature | Description | Reason Deferred | Dependencies | Suggested Release |
|----|----------|---------|-------------|-----------------|--------------|-------------------|
| PB-020 | Data Engine | Trading calendar | Holidays / missing session detection product | SD-003 | Data Engine | 1.2 |
| PB-021 | Evaluation | Market regime assessment | Regime input to scoring/ranking | Spec responsibility deferred | EvaluationEngine | 1.2 |
| PB-022 | Evaluation | Pluggable rules modules | Extract hard-coded rules into testable rule classes | A8 MVP scoring | EvaluationEngine | 1.2 |
| PB-023 | Notification | Webhook notifications | HTTP callbacks for events | Multi-channel deferred | PB-012 | 1.2 |
| PB-024 | Discovery | Inline default screener run | Optionally run screener inside discovery if hits stale | Depends on prior runs | Screener services | 1.2 |
| PB-025 | Discovery | Discovery Engine Specification doc | Document ownership/acceptance without changing architecture intent | Spec file never created | Governance process | 1.2 |
| PB-026 | Architecture | Repository layer for TOS aggregates | Optional Eloquent→repository boundary | SD-013 | Engines | 1.2 |
| PB-027 | API | Consistent pagination | page/pageSize on all list endpoints | Partial today | TradingOsController | 1.2 |
| PB-028 | API | Idempotency keys on import/generate | Align with REST SHOULD | Notifications only today | Controllers | 1.2 |
| PB-029 | Review | Reports list/detail UI | Surface review_reports beyond dashboard | Dashboard prioritized | Review APIs | 1.2 |
| PB-030 | Review | Deeper metrics (drawdown, execution quality) | Expand Review Engine metrics | Partial V1.0 | ReviewEngine | 1.2 |
| PB-031 | Frontend | Shared TOS hooks/components | Deduplicate page fetch/error patterns | Speed of MVP | React pages | 1.2 |
| PB-032 | Developer Experience | Vitest / E2E smoke for TOS pages | UI regression coverage | Backend tests only | Frontend toolchain | 1.2 |
| PB-033 | Infrastructure | Structured logging fields | Engine, request_id per App Arch | Existing logger | PortfolioLoggerService | 1.2 |
| PB-034 | Execution | Order Rejected status | Spec state unused; cancel covers abort | Cancel sufficient | ExecutionEngine | Future |

---

## Low

| ID | Category | Feature | Description | Reason Deferred | Dependencies | Suggested Release |
|----|----------|---------|-------------|-----------------|--------------|-------------------|
| PB-040 | Architecture | Split TradingOsController by engine | Maintainability | Monolith OK for V1.0 | Routes | 1.2 |
| PB-041 | Frontend | TypeScript migration | Spec stack item | Existing JSX SPA | Large effort | Future |
| PB-042 | Frontend | TanStack Query / AG Grid adoption | Spec stack items | Not required for V1.0 | Product need | Future |
| PB-043 | Auth | Optional JWT/token API | Non-SPA clients | Sanctum Accepted | Auth design | 2.0 |
| PB-044 | Domain | Strategy management | Multi-strategy isolation & comparison | SD-007 | Domain model | 2.0 |
| PB-045 | Execution | Broker automation (Zerodha etc.) | Assisted Execution stage | Vision Stage 2 | Adapters, auth | 2.0 |
| PB-046 | Execution | GTT / stop / target / partial fills | Advanced order types | Broker stage | PB-045 | 2.0 |
| PB-047 | Notification | SMS / push / Teams / Slack | Additional channels | Future scope in Notification Spec | PB-012 | Future |
| PB-048 | Evaluation | ML scoring models | Spec future scope | Determinism principle conflict — careful ADR | Evaluation | Future |
| PB-049 | Markets | Options / crypto / ETF products | Spec future scope | Equity focus | Data providers | Future |
| PB-050 | Product | AI assistant (non-decision) | Vision / roadmap | Trust-first | Knowledge surfaces | Future |
| PB-051 | Product | Mobile application | Roadmap future | SPA first | API maturity | Future |
| PB-052 | Review | Tax reporting / attribution / benchmarks | Review future scope | Core metrics first | ReviewEngine | Future |
| PB-053 | Architecture | Formal ADR process for SD changes | Process maturity | Governance pack is start | Team process | 1.1 |

---

## Grouped by category (index)

| Category | Item IDs |
|----------|----------|
| Architecture | PB-014, PB-026, PB-040, PB-053, PB-055, PB-056 |
| Data Engine | PB-001, PB-002, PB-020 |
| Discovery | PB-024, PB-025 |
| Evaluation | PB-021, PB-022, PB-048, PB-054 |
| Indicators | PB-057 |
| Recommendation | PB-003, PB-015 |
| Notification | PB-012, PB-013, PB-023, PB-047 |
| Execution | PB-034, PB-045, PB-046 |
| Review | PB-029, PB-030, PB-052 |
| Infrastructure | PB-010, PB-011, PB-017, PB-033 |
| Developer Experience | PB-016, PB-032 |
| Frontend | PB-031, PB-041, PB-042 |
| Auth / Domain / Product / Markets | PB-043, PB-044, PB-049–PB-051 |

---

## Release themes (indicative)

| Release | Theme |
|---------|--------|
| **1.1** | Harden trust: data gates, position review, ops wiring, ownership fix, internal soak; Indicator Registry Phases 1–3 (PB-055); Strategy-param→Evaluation wiring (PB-054) |
| **1.2** | Expand channels & analytics: email/webhook, OpenAPI, regime/rules modules, UI polish; Registry versions (PB-056); Liquidity/Tradability indicators (PB-057) |
| **2.0** | Strategy + Assisted Execution (broker) |
| **Future** | AI, mobile, alternative assets, ML (only with explicit ADR) |

---

## Change control

- Completing an item: mark done in this file (or move to a Completed section) and update [`./VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) only when cutting a new baseline.  
- New deferred discoveries: add PB-xxx; link related SD-xxx if a specification decision exists.
