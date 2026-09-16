# V2.1 Workstream C — Shadow Feature Inventory

**Date:** 2026-08-10  
**Status:** **DISCOVERY COMPLETE** (read-only — no packs created, no code changes)  
**Phase:** V2.1 Product Hardening — retrospective documentation planning  
**Predecessors:**  
- WS-A COMPLETE — [`WS-A-TEST-BASELINE-CLEANUP.md`](./WS-A-TEST-BASELINE-CLEANUP.md)  
- WS-B COMPLETE — [`WS-B-FINANCIAL-AUTHZ-AUDIT.md`](./WS-B-FINANCIAL-AUTHZ-AUDIT.md)  
- Programme audit — [`V2.1-PRODUCT-HARDENING-AUDIT.md`](./V2.1-PRODUCT-HARDENING-AUDIT.md)  

**Constraints:** Do not invent feature IDs; do not reopen V2 initiatives; do not start V3; do not implement packs in this pass.

---

## Executive summary

SD-035 V2 closed eleven initiatives with V2-style SPEC/BOUNDARY/POLICY discipline. Much of the **shipped V1 product** (and some V1-adjacent surfaces) still relies on architecture intent specs, `implementation.md`, and in-app help — **without** that retrospective pack discipline.

| Metric | Count (approx.) |
|--------|----------------:|
| Shipped SPA routes (auth + guest + admin) | ~55+ |
| Help topics in `appDocumentation.js` | 40 |
| Closed V2 packs (formal) | **11** (F003–F144 set) |
| **Shadow features** (user-visible, meaningful logic, no V2-style pack) | **~20** prioritized |
| Already documented (V2 pack) | 11 initiative surfaces |
| V3 / future candidates (do not implement) | Listed in §5 |

**Recommendation:** Proceed with **retrospective CURRENT packs under `docs/v2.1/`** (F143/F144 pattern) for the highest-value shadows — starting with **Cash Management**, then **Portfolio Snapshots (F015)**, then **Dashboard** (+ Explorer contract notes). Do **not** create F145+ IDs. Architecture specs remain intent; V2.1 packs capture CURRENT behaviour, boundaries, and gaps.

---

## Feature inventory table

Classification key:

- **A** — Already documented (V2-style pack under `docs/v2/`)  
- **B** — Shadow feature (retrospective documentation recommended)  
- **C** — Technical debt / doc drift (not a full feature pack)  
- **D** — Future / V3 candidate  

| Capability | Runtime | User visible | Existing docs | Class | Recommendation |
|------------|---------|--------------|---------------|-------|----------------|
| F003 User invite | Yes | Yes | V2 pack + help | **A** | Leave closed |
| F005 Session management | Yes | Yes | V2 pack + help | **A** | Leave closed |
| F014 Historical holdings | Yes | Yes | V2 pack + help | **A** | Leave closed |
| F019 Bulk CSV import | Yes | Yes | V2 pack + help | **A** | Leave closed |
| F042 Data quality | Yes | Yes (admin) | V2 pack + help | **A** | Leave closed |
| F043 CA price repair | Yes | Partial | V2 pack | **A** | Leave closed |
| F060 Shared screener import | Yes | Yes | V2 pack + help | **A** | Leave closed |
| F127 Portfolio alerts | Yes | Yes | V2 pack + help | **A** | Leave closed |
| F137 Recommendation preview | Yes | Yes | V2 pack + help | **A** | Leave closed |
| F143 Contextual help | Yes | Yes | V2 pack | **A** | Leave closed |
| F144 Knowledge Board | Yes | Yes | V2 pack + help | **A** | Leave closed |
| **Cash Management** | Yes | Yes | Arch SPEC + help; **no V2 pack** | **B** | **Priority 1** retrospective pack |
| **Dashboard** | Yes | Yes | Arch SPEC + help; **no V2 pack** | **B** | **Priority 3** pack |
| **User Calendar** | Yes | Yes | Help only; Data Engine calendar ≠ UI | **B** | Priority 4 pack |
| **Portfolio Snapshots (F015)** | Yes | Yes | Help + F014 boundary mentions; **no F015 pack** | **B** | **Priority 2** pack |
| **Strategy configuration UI** | Yes | Yes | Arch Strategy specs + help | **B** | Pack or CURRENT note (boundary vs Evaluation) |
| **TOS Notifications** | Yes | Yes | Notification Engine arch + help | **B** | Pack (boundary vs F127) |
| **Explorer / RS analytics** | Yes | Yes | Help; no dedicated SPEC | **B** | Bundle with Dashboard or separate CURRENT note |
| **Pattern guide / scan** | Yes | Yes | Help; pipeline mentions | **B** | Lower priority pack |
| **Discovery / Candidates** | Yes | Yes | Discovery arch + help | **B** | Pack (engine ownership) |
| **Evaluation UX** | Yes (folded into candidates) | Partial | Evaluation Engine arch | **B** | With Discovery pack |
| **Review dashboard** | Yes | Yes | Review Engine arch + help | **B** | Medium priority |
| **Strategy / screener backtests** | Yes | Yes | Arch intent + help | **B** | Medium priority |
| **Universe price sync (admin)** | Yes | Admin | Help; no product SPEC | **B** | Ops CURRENT pack |
| **India VIX alerts** | Yes | Partial | Tests; **no help topic** | **B** | Document or absorb into Indices/Alerts |
| **Indicator registry** | Yes | Admin | Arch Indicator Registry + help | **B** | Formalize CURRENT vs SD-033 remainder → V3 |
| **Artifact / Screener / Strategy registries** | Yes | Yes | Arch Artifact Framework + help | **B** | CURRENT pack; expansion → V3 |
| **Indices / Market depth** | Yes | Yes | Market Analysis arch + help | **B** | Medium priority |
| **Transactions / Holdings / Watchlist (core)** | Yes | Yes | Portfolio/Watchlist arch + help; bulk only = F019 | **B** | Core ledger CURRENT note (shared with Cash) |
| **Pending execution / Closed transactions** | Yes | Yes | Execution arch + help | **B** | With Cash/reservation semantics |
| **Corporate actions F020 (core apply)** | Yes | Yes | Help; V1; repair = F043 only | **B** | CURRENT pack (boundary vs F043) |
| **Password reset F004** | Yes | Yes | V1; partial help; couples F005 | **B** | Light CURRENT note (V1) |
| **Portfolios / Profile** | Yes | Yes | Help | **B** | Light CURRENT note |
| **Settings (global/portfolio/account)** | Yes | Yes | Help; sessions = F005 | **B** | Light CURRENT note |
| **Admin ops (sync logs, op alerts, users)** | Yes | Admin | Help; users/invite = F003 | **B** | Ops CURRENT note |
| Soft reservation semantics | Yes | Indirect | WS-B §0 + Cash arch | **C** | Fold into Cash pack |
| Dual `/api` vs `/api/v1` client clarity | Yes | Dev | TD / PB-016 | **C** | Docs hygiene |
| SPA help prose drift | Yes | Ops | F143 non-blockers | **C** | Maintenance |
| Strategy → Evaluation parameter wiring | Partial | Misleading UI | TD-19 / PB-054 | **D** | **V3** |
| Hard dataset publish gate | No | — | PB-001 | **D** | **V3** |
| Broker / Zerodha automation | No | — | Backlog | **D** | **V3** |
| Multi-channel notifications | No | — | PB-012+ / F127 OOS | **D** | **V3** |
| F014 cash-as-of / export | No | — | F014 OOS | **D** | **V3** |
| F005 admin force-logout | No | — | F005 deferred | **D** | **V3** |
| F144 sharing / entity notes | No | — | F144 OOS | **D** | **V3** |

---

## Shadow features (detail)

### B1. Cash Management — **Priority 1**

| Aspect | Detail |
|--------|--------|
| **Implementation** | UI `/cash` (`CashManagementPage`); API `/api/cash*` (summary, reservations, ledger, deposit/withdraw/adjust); `CashManagementService`; tables `portfolio_cash_accounts`, `portfolio_cash_ledger_entries`; reservation fields on `portfolio_tos_recommendations` |
| **Workflow** | Deposit / withdraw / adjust → ledger; balance − reserved → available (withdraw/approve); soft reserved does **not** block manual/F019 buys (WS-B PO) |
| **Ownership** | Portfolio profile–scoped cash account; ledger append-only |
| **Dependencies** | `TransactionWriteService` (buy/sell cash); Recommendation lifecycle (reserve/release/convert); F019 bulk; capital allocation |
| **Tests** | `FinancialIntegrityHardeningTest`, bulk cash rollback, `TradingOsPipelineTest` reserve path; no full retrospective pack tests matrix |
| **Doc gaps** | Arch `Cash-Management-Specification.md` exists; **no** BOUNDARY/POLICY/GAP pack; soft vs hard clarified in WS-B only |
| **Suggested pack** | `docs/v2.1/CASH-MANAGEMENT-SPEC.md`, `…-BOUNDARY.md`, `…-POLICY-DECISIONS.md`, `…-GAP-MATRIX.md` |

**Boundaries (do not reopen V2):** F019 owns bulk create atomicity; F014 cash-as-of OOS; F137 preview does not reserve; reservations stay recommendation workflow.

---

### B2. Portfolio Snapshots (F015) — **Priority 2**

| Aspect | Detail |
|--------|--------|
| **Implementation** | UI `/portfolio/snapshots`; APIs rebuild-history / snapshots; `PortfolioSnapshotRebuildService`; snapshot tables |
| **Workflow** | View equity curve / historical portfolio value snapshots; rebuild after transaction changes (post-commit) |
| **Ownership** | Aggregate time-series cache — **not** F014 as-of holdings SoT |
| **Dependencies** | Transaction write post-commit; OHLCV; Dashboard may consume aggregates |
| **Tests** | `PortfolioSnapshotApiTest`, `PortfolioSnapshotRebuildTest` |
| **Doc gaps** | Help topic exists; F014 BOUNDARY distinguishes F015; **no F015 pack** |
| **Suggested pack** | `docs/v2.1/PORTFOLIO-SNAPSHOTS-SPEC.md`, `…-BOUNDARY.md`, `…-GAP-MATRIX.md` (+ POLICY if decisions needed) |

---

### B3. Dashboard — **Priority 3**

| Aspect | Detail |
|--------|--------|
| **Implementation** | UI `/`; `GET /api/dashboard`; v1 analytics dashboard bundle; market depth gauges; top movers |
| **Workflow** | Operator home: portfolio value, allocation, growth, market sentiment/phase, alerts strip |
| **Ownership** | Presentation/aggregation over holdings, cash, market analysis — not ledger SoT |
| **Dependencies** | Holdings, cash summary, Market Analysis, alerts, Explorer math siblings |
| **Tests** | `DashboardTopMoversTest`, `DashboardGrowthTest` (thin) |
| **Doc gaps** | Arch `Dashboard-Specification.md` + Analytics Architecture; **no V2.1 CURRENT pack**; WS-A fixed growth fixture drift |
| **Suggested pack** | `docs/v2.1/DASHBOARD-SPEC.md`, `…-BOUNDARY.md`, `…-GAP-MATRIX.md` |

---

### B4. User Calendar — **Priority 4**

| Aspect | Detail |
|--------|--------|
| **Implementation** | UI `/calendar`; API `/api/calendar/events|occurrences|upcoming`; models/services for events/recurrence |
| **Workflow** | Personal/portfolio calendar events (not exchange holiday product) |
| **Ownership** | Profile-scoped calendar entities |
| **Dependencies** | Distinct from Data Engine trading session calendar (`TradingCalendar` helper) |
| **Tests** | `CalendarEventTest`, `CalendarRecurrenceServiceTest` |
| **Doc gaps** | Help exists; MVP historically excluded “trading calendar product”; UI still ships undocumented as CURRENT |
| **Suggested pack** | `docs/v2.1/USER-CALENDAR-SPEC.md`, `…-BOUNDARY.md`, `…-GAP-MATRIX.md` |

---

### B5. Explorer & relative-strength analytics

| Aspect | Detail |
|--------|--------|
| **Implementation** | `/explorer`; `POST /api/analytics/explore`; `ExploratoryAnalyticsService`, `RelativeStrengthService`, `StockPriceHistoryService` |
| **Workflow** | Symbol research vs benchmark growth/RS/charts |
| **Doc gaps** | Help only; no dedicated SPEC |
| **Suggested pack** | Fold into Dashboard pack **or** `docs/v2.1/EXPLORER-ANALYTICS-SPEC.md` + BOUNDARY |

**Boundaries:** Do not reopen F042 DQ guard injection; session-aware closes are intentional (WS-A).

---

### B6. Strategy configuration UI

| Aspect | Detail |
|--------|--------|
| **Implementation** | `/strategy`, `/api/v1/strategy*`; `StrategyConfigurationService` |
| **Doc gaps** | Rich arch specs; no V2.1 CURRENT pack; **TD-19** Strategy params ≠ Evaluation runtime → **V3** to wire |
| **Suggested pack** | `docs/v2.1/STRATEGY-CONFIG-CURRENT.md` (document CURRENT behaviour + known Evaluation disconnect without fixing it) |

---

### B7. TOS Notifications (Telegram)

| Aspect | Detail |
|--------|--------|
| **Implementation** | `/notification-history`; `/api/v1/notifications*`; `NotificationEngine` |
| **Boundaries** | Parallel to F127 portfolio alerts; Telegram-only by design |
| **Suggested pack** | `docs/v2.1/TOS-NOTIFICATIONS-SPEC.md` + BOUNDARY vs F127 |

---

### B8. Discovery / Evaluation / Review / Recommendations core

| Aspect | Detail |
|--------|--------|
| **Implementation** | `/candidates`, `/recommendations`, `/review`; `/api/v1/discovery*`, evaluation*, recommendations*, reviews* |
| **Doc gaps** | Engine architecture specs exist; Discovery Engine SPEC incomplete historically (PB-025); F137 covers preview only |
| **Suggested pack** | Grouped CURRENT packs: Discovery+Evaluation, Recommendations lifecycle (excluding closed F137), Review — **without** changing behaviour |

**Do not reopen** F137 decision core / preview contract.

---

### B9. Screeners / Backtests / Registries

| Aspect | Detail |
|--------|--------|
| **Implementation** | Screeners UI + runs + backtests; strategy backtests; screener/strategy/artifact registries; indicator registry |
| **Doc gaps** | Screener/Strategy/Artifact/Indicator arch specs; F060 only for sharing; SD-033/034 remainder → V3 |
| **Suggested pack** | CURRENT notes for Screener authoring + Backtests; Registries CURRENT vs planned Artifact phases |

---

### B10. Patterns, Indices, Market depth

| Aspect | Detail |
|--------|--------|
| **Implementation** | `/patterns`, `/indices`, `/market-depth` + APIs |
| **Suggested pack** | Medium priority; Market depth may fold into Dashboard/Market Analysis CURRENT |

---

### B11. Corporate actions F020 (core apply)

| Aspect | Detail |
|--------|--------|
| **Implementation** | `/corporate-action`; `/api/corporate-actions*`; apply split/bonus |
| **Boundaries** | F043 = price repair only — do not reopen |
| **Suggested pack** | `docs/v2.1/CORPORATE-ACTIONS-CORE-SPEC.md` + BOUNDARY vs F043/F042 |

---

### B12. Universe sync & admin ops

| Aspect | Detail |
|--------|--------|
| **Implementation** | Admin universe price sync, gap failures, sync logs, operational alerts, user management (beyond F003) |
| **Suggested pack** | Ops CURRENT runbook-style pack under `docs/v2.1/` |

---

### B13. Portfolios, Profile, Settings, F004 password reset

| Aspect | Detail |
|--------|--------|
| **Implementation** | Multi-portfolio switch, profile photo/password, settings pages, reset-password flow |
| **Boundaries** | F005 sessions closed; F003 invites closed |
| **Suggested pack** | Light CURRENT notes; avoid reopening F003/F005 |

---

### B14. India VIX alerts

| Aspect | Detail |
|--------|--------|
| **Implementation** | Services + tests; surfaced via indices/market UX |
| **Doc gaps** | **No dedicated help topic** |
| **Suggested** | Document in Indices/Market CURRENT or Settings; classify severity low |

---

## Relationship with existing V2 features (boundary guardrails)

| Shadow | Must not absorb / reopen |
|--------|---------------------------|
| Cash | F019 financial unit (create/bulk); F014 cash-as-of OOS; F137 preview; soft reservation PO from WS-B |
| Snapshots F015 | F014 ledger as-of reconstruction |
| Dashboard / Explorer | F042/F043 price SoT; F137 preview |
| Calendar UI | Data Engine holiday calendar; F127 alerts |
| TOS Notifications | F127 portfolio alert policies |
| Strategy CURRENT | TD-19 wiring = V3; F137 shared decision core |
| CA F020 core | F043 repair; F042 DQ handoff |
| Screeners | F060 sharing AuthZ only |
| Help / Knowledge | F143 / F144 closed |

---

## Potential V3 candidates discovered

Do **not** plan or implement in V2.1:

| Candidate | Source |
|-----------|--------|
| Strategy → Evaluation indicator parameter wiring | TD-19 / PB-054 |
| Hard dataset publish gate / immutable dataset versions | PB-001 / PB-002 |
| Broker / Zerodha automatic execution | Product backlog / MVP out |
| Multi-channel notifications (email/webhook) | PB-012+; F127 OOS |
| Deep Position Review | PB-003 |
| Indicator Registry Phase 4–5 / Liquidity indicators | PB-056 / PB-057 |
| Artifact Framework Phase 3–5 / AI catalogue | PB-059 / PB-060 |
| F014 cash-as-of / realized / export / compare | F014 OOS |
| F005 admin force-logout | F005 deferred |
| F144 sharing / entity-linked notes | F144 OOS |
| F003 SMTP invites | F003 OOS |
| Full OpenAPI / E2E programmes | PB-016 / PB-032 |

---

## Recommended retrospective pack sequence (planning only)

| Order | Pack theme | Rationale |
|------:|------------|-----------|
| 1 | **Cash Management** (+ soft reservation CURRENT) | Highest financial risk; WS-B complete; arch SPEC exists |
| 2 | **Portfolio Snapshots (F015)** | Confusion risk vs F014 |
| 3 | **Dashboard** (+ optional Explorer CURRENT) | User-facing home; metrics ownership |
| 4 | **User Calendar** | Clear shadow UI |
| 5 | **CA F020 core** BOUNDARY vs F043 | Clarify ownership |
| 6 | **TOS Notifications** vs F127 | Parallel channel clarity |
| 7 | **Strategy CURRENT** (document Evaluation disconnect) | Prevent false expectations without V3 wiring |
| 8 | Discovery / Review / Backtests / Registries / Ops | As capacity allows |

**Pack naming convention (no new F-IDs):** descriptive files under `docs/v2.1/` as in examples above. Index in `DOCS.md` §3.M when created.

---

## Confirmation

| Item | Status |
|------|--------|
| Application code changed | **No** |
| Tests changed | **No** |
| Schema / migrations changed | **No** |
| Retrospective packs created | **No** (inventory only) |
| New feature IDs | **None** |
| V2 initiatives reopened | **No** |
| V3 work started | **No** |

---

*Next step (human-gated): authorize creation of Priority 1 Cash Management retrospective pack files under `docs/v2.1/`.*
