# Trading Operating System — Implementation Progress

This document is a current implementation/status summary. Detailed historical evidence lives in version registers, dedicated feature specs, Git history and deployment records.

**Last reconciled:** 2026-09-15

## 1. Current overall state

StoX has progressed well beyond the original MVP baseline.

| Area | Current state |
|---|---|
| Original TOS MVP | COMPLETE |
| V3 | STRICTLY COMPLETE |
| V4 | COMPLETE / CLOSED — 18/18 |
| V5 | 14 COMPLETE / 2 IN PROGRESS / 2 SUPERSEDED |
| V6 | COMPLETE — 12/12 |
| V7 | FEAT-018 ML + FEAT-053 Fundamentals implemented and deployed; FEAT-055 closed after dedicated-DB architecture change |
| V8 | Future planning / not implemented |
| V9 | Future planning / not implemented |

The original end-to-end MVP remains complete:

```text
Market Data
  → Discovery
  → Evaluation
  → Recommendation
  → User Review
  → Pending Execution
  → Manual/Broker Trade
  → Review
```

Later versions added substantial Strategy, automation, execution-safety, analytics, simulation, knowledge, administration, fundamentals and ML capabilities on top of that baseline.

## 2. Production state

Current production is the dedicated VPS deployment at **https://stoxla.in/**.

The previous GoDaddy/cPanel target is historical and is no longer the StoX production architecture.

The successful GitHub Actions production deployment on 2026-09-13 deployed commit `6307a4eccaa49682795188422498bd4a5b3bb990` and passed:

- full MySQL 8.4 migration/seed validation;
- complete backend test suite;
- OpenAPI contract verification;
- frontend test suite;
- TypeScript no-emit validation;
- production Vite build;
- release packaging;
- VPS release activation;
- Laravel migration/cache steps;
- queue restart; and
- post-deploy HTTPS health check.

Because the V6 implementation and V7 Fundamentals/ML implementation commits predate that deployed release, those implementations are part of the current production codebase.

Current deployment authority: `deploy/STOXLA-VPS-DEPLOY.md` and `.github/workflows/deploy-stoxla-production.yml`.

## 3. Implemented product capabilities

The following high-level capabilities are implemented in the current codebase.

### Core portfolio and decision system

- market-data ingestion and historical price storage;
- Discovery / Screener candidate generation;
- Evaluation and explainable scoring;
- Strategy configuration and multi-Strategy Portfolio operation;
- Recommendations and informational Market Insights;
- approval/reject/defer lifecycle;
- Portfolio cash, capital allocation and lending/recall mechanics;
- Orders, Trades and execution evidence;
- Review/performance reporting;
- deterministic point-in-time/historical analysis safeguards.

### Live trading and safety

- Zerodha/Kite integration;
- Manual / Semi-Automatic / Automatic execution modes;
- broker order lifecycle;
- GTT protective orders / partial-fill handling;
- holiday-aware scheduled target seeking;
- live quote-based sizing;
- Kite holdings/funds reconciliation;
- Investor-level `Normal` / `Emergency Halt` execution state;
- disconnect kill switch;
- cancel-open-orders + disconnect emergency action;
- persistent emergency controls.

### Artifact / Strategy platform

- Indicator Registry and SemVer lifecycle;
- Strategy/Screener/Indicator dependency evidence;
- Trading Artifact Library;
- immutable publications and Portfolio bindings;
- sharing/Fork and package import/export;
- Bundles;
- backfill/projection into legacy runtime identities;
- artifact evidence through Recommendation/order/fill and simulation flows;
- Strategy Backtest, Portfolio Replay and Paper Portfolio;
- Backtest declared-parameter overrides and Backtest-to-Draft flow.

### Investor / Admin product surfaces

- responsive/mobile-capable SPA;
- Dashboard UX/widget management;
- separate Admin and Investor application shells;
- Admin force logout;
- Admin Stocks surface;
- Admin Audit Explorer;
- contextual Notes;
- Linked Markdown Wiki / Knowledge Board;
- personal/scoped API tokens;
- richer Discovery/Evaluation/Review history surfaces.

### Notifications and operations

- canonical Notification Center/lifecycle;
- In-app notifications;
- Telegram delivery;
- Email delivery;
- signed Webhook delivery;
- retries, reminders and channel-health handling;
- unattended daily pipeline;
- scheduler/queue operational flows;
- GitHub Actions CI and production deployment pipeline.

### Analytics / tax / data

- historical cash-as-of and Cash Statement;
- date comparison and CSV exports;
- XIRR/TWR and benchmark/risk evidence;
- attribution;
- FIFO-derived tax analysis over canonical WAVG accounting;
- India-focused gains/loss/dividend reporting;
- exchange-holiday synchronization and calendar support;
- immutable dataset versioning and freshness gates.

### V7 analytical foundations

- first-class company fundamental-data storage and ingestion;
- provider-isolated Yahoo/yfinance initial adapter;
- immutable fundamental revisions and point-in-time reads;
- derived fundamental metrics and freshness handling;
- scheduled incremental fundamental updates and Admin surfaces;
- 1m/3m/6m ML model lifecycle;
- chronological/point-in-time-safe ML training metadata;
- explicit retrain/promote/rollback controls;
- persisted ML predictions, explanations and drift evidence;
- additive ML evidence in Evaluation/Strategy flows without replacing deterministic Strategy authority.

## 4. Formal work still open

Only the following current-version items remain materially open or intentionally future.

### V5 formal closure gates

#### V4-FEAT-008 — Trading Artifact Framework

Implementation is substantially complete. Remaining formal closure work:

1. execute/document the rollout validation against representative production-shaped or production data; and
2. complete the final frozen-criteria audit.

See `V5-FEAT-008-IMPLEMENTATION-STATUS.md`.

#### V4-FEAT-042 — Admin / Investor separation

Implementation is complete. Remaining formal gate:

```bash
php artisan portfolio:audit-admin-investment-ownership --json
```

Run the read-only ownership audit against actual production data and disposition any reported conflict.

### V7

There is no remaining V7 implementation epic.

- FEAT-018 is implemented and deployed.
- FEAT-053 is implemented and deployed.
- FEAT-055 is closed at its current state because the shared-database motivation was removed by the dedicated StoX database. Existing `portfolio_*` legacy tables are intentionally retained; new V7 tables use `stox_*`.

Detailed manual/functional production acceptance for FEAT-018 and FEAT-053 may still be recorded as operational evidence, but this is not unimplemented feature scope.

## 5. Superseded work

### V4-FEAT-003

Superseded by FEAT-004 Notification Service.

### V4-FEAT-031

The old GoDaddy/cPanel single-folder deployment feature is closed as superseded. Its code/design was implemented, but the target topology was abandoned before cPanel production cutover.

The replacement VPS architecture is live and uses GitHub Actions release packaging, shared environment/storage, versioned releases, rollback tooling and health checks.

### V4-FEAT-055

The full legacy database-prefix cutover is no longer required. StoX now has a dedicated database, so the original shared-database naming problem no longer exists.

## 6. Future roadmap

### V8

- **V4-FEAT-052 — Standalone Telemetry Platform**: separate product/application; core architecture decided, not implemented.
- **V4-FEAT-054 — Historical Fundamental Data Bootstrap**: one-time historical quarterly/annual fundamental import; source/procedure still open.

### V9

- **V4-FEAT-017 — AI Assistant**.
- **V4-FEAT-019 — ETF / Options / Crypto Expansion**.
- **StoX Telemetry Platform Integration**.

See the V8 and V9 canonical registers for scope.

## 7. Historical MVP assumptions

The original MVP made several intentionally limited assumptions—Sanctum cookies, Telegram-only notifications, no Strategy entity, no automated broker execution, etc. Those assumptions are **historical MVP context only** and must not be read as current product limitations. Later versions superseded many of them.

For current behavior, prefer the newest applicable version register and dedicated feature specification.

## 8. Status authority

When documents disagree about whether something is implemented or pending, use this precedence for status:

1. current canonical version register;
2. dedicated feature specification / implementation-status document;
3. current deployment evidence;
4. this summary;
5. older historical implementation logs or MVP notes.
