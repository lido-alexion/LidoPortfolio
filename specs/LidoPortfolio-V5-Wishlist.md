# LidoPortfolio V5 Wishlist

| Field | Value |
|-------|-------|
| **V4 Status** | **V4 COMPLETE AND CLOSED** (18/18 active features complete) |
| **Document type** | Canonical V5 product wishlist and planning register |
| **Created** | 2026-09-02 |
| **Last reconciled** | 2026-09-04 |
| **Canonical path** | [`specs/LidoPortfolio-V5-Wishlist.md`](LidoPortfolio-V5-Wishlist.md) |
| **Related** | [`LidoPortfolio-V4-Wishlist.md`](LidoPortfolio-V4-Wishlist.md) · [`LidoPortfolio-V3-Specification.md`](LidoPortfolio-V3-Specification.md) · [`../implementation.md`](../implementation.md) |

## 1. Purpose and authority

This is the single active source of truth for V5 wishlist discovery, prioritization, specification, and implementation status. It contains genuine post-V4 product work. The completed V4 register remains a historical record and must not be used as an active V5 backlog.

Feature IDs retained from the earlier combined roadmap remain `V4-FEAT-*` to preserve traceability. An ID prefix records origin; it does not assign the feature back to V4. New entries continue the existing sequence until a deliberate ID migration is approved.

Moving an item here does not satisfy it. Every item remains `OPEN` until its product rules are sufficiently specified, implemented, tested, documented, deployed, and production-verified as appropriate.

### Status values

`OPEN` · `BLOCKED` · `DECIDED` · `IN PROGRESS` · `COMPLETE`

## 2. V5 wishlist

Current count: **18 OPEN / 2 IN PROGRESS / 1 DECIDED / 3 COMPLETE**.

| ID | Item | V5 scope and rationale | Priority | Status |
|----|------|------------------------|----------|--------|
| V4-FEAT-003 | B4 persistent app-wide critical banner | V3 §29: **B4 is explicit wishlist**; B3 Dashboard reserve warning is current V3. | P2 | OPEN |
| V4-FEAT-004 | Notification channel abstraction + email/webhook | V3 §30 requires Telegram/in-app capability (shipped); multi-channel is new. | P2 | OPEN |
| V4-FEAT-007 | Indicator Registry deeper versioning / remaining cutover | SD-033 residual beyond V3 registries already shipped. | P2 | OPEN |
| V4-FEAT-008 | Trading Artifact Framework remaining phases | SD-034 residual beyond the shipped envelope, package I/O, Indicator/Screener/Strategy registries, Create/Enable/Archive, AI authoring/runtime docs, and V3 multi-strategy surfaces. Remaining scope: immutable published versions, first-class library/binding model, expanded AI catalogue UX, sharing/distribution, dependency dashboards, rollback, bundle UI, and fork workflows. Shipped infrastructure stays shipped. | P2 | OPEN |
| V4-FEAT-012 | Admin force-logout of other users (PD-007) | Authentication product expansion deferred from V4. | P3 | OPEN |
| V4-FEAT-013 | Cash-as-of / export / compare polish | F014 residual cash-history polish deferred from V4. | P3 | OPEN |
| V4-FEAT-015 | Tax reporting / attribution / benchmarks | New reporting and analysis surface. | P3 | OPEN |
| V4-FEAT-016 | Mobile application | New client. | TBD | OPEN |
| V4-FEAT-017 | AI assistant (non-decision) | New assistive surface. | TBD | OPEN |
| V4-FEAT-018 | ML scoring models | Optional non-deterministic path; V3/V4 decision logic is deterministic. | TBD | OPEN |
| V4-FEAT-019 | Options / crypto / ETF products | Markets and instrument expansion. | TBD | OPEN |
| V4-FEAT-020 | Live paper / portfolio replay modes | New live simulation and replay modes. | TBD | OPEN |
| V4-FEAT-030 | CI workflow for PHPUnit + frontend build | **Implemented and verified 2026-09-04:** CI runs the full Laravel/PHPUnit suite on PHP 8.4 plus JavaScript tests and a production Vite build on Node 22 for pushes to `master`, pull requests, and manual dispatch. Initial full coverage exposed and repaired stale market-gate fixtures and the intentionally public, encrypted-state Kite callback contract. GitHub Actions run `33865499081` passed both jobs on `e09a9ff`. | P2 | COMPLETE |
| V4-FEAT-031 | Production secrets / single-folder deploy hardening | **Implemented; production cutover verification pending:** [`V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md`](V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md). External environment secrets, one-build packaging, nested Laravel denial, compatible cPanel helpers, migration verification, and rollback are implemented. | P3 | IN PROGRESS |
| V4-FEAT-033 | Discovery inline default screener | **Implemented 2026-09-04:** [`V5-FEAT-033-Discovery-Inline-Default-Screener.md`](V5-FEAT-033-Discovery-Inline-Default-Screener.md). Discovery shows and runs the factory screener inline using existing APIs, while keeping candidate regeneration explicit. | P3 | COMPLETE |
| V4-FEAT-034 | Richer Evaluation history UX | **Implemented 2026-09-04:** [`V5-FEAT-034-Richer-Evaluation-History-UX.md`](V5-FEAT-034-Richer-Evaluation-History-UX.md). Discovery includes a bounded, portfolio-scoped Evaluation run selector and ranked historical results without restoring a separate Evaluation page. | P3 | COMPLETE |
| V4-FEAT-035 | TypeScript / TanStack Query / AG Grid migration | **Incremental migration in progress (2026-09-04):** TypeScript strict/no-emit checking and CI gate, application-level TanStack Query provider with portfolio-scoped Evaluation queries, and a typed/lazy-loaded AG Grid Evaluation-history table are implemented. Remaining legacy screens migrate opportunistically; no big-bang rewrite. See [`V5-FEAT-035-Frontend-Stack-Migration.md`](V5-FEAT-035-Frontend-Stack-Migration.md). | TBD | IN PROGRESS |
| V4-FEAT-036 | Optional JWT/token API for non-SPA clients | Authentication expansion; the current SPA continues to use Sanctum session cookies. | TBD | OPEN |
| V4-FEAT-037 | Dashboard-first daily Kite readiness and reconnect | When an Automatic portfolio cannot submit because its daily Kite session is missing or expired, show a prominent Dashboard readiness state and minimum-friction **Connect Kite** action. Return to Dashboard and refresh readiness after Zerodha authentication. Add a configurable, at-most-once-daily Telegram reminder while Automatic execution is enabled and Kite remains unusable; suppress it when connected. Interactive Zerodha authentication remains mandatory. | P2 | OPEN |
| V4-FEAT-038 | Exchange holidays in Calendar with automatic holiday-list sync | Add a visibly distinct exchange-holiday Calendar event type with admin correction/override. Automatically refresh the official NSE holiday list when a reliable source is available, retaining manual entry as fallback. This becomes the canonical trading-calendar input for scheduling. | P2 | OPEN |
| V4-FEAT-039 | Holiday-aware scheduled order execution | **Frozen:** [`V5-FEAT-039-Holiday-Aware-Scheduled-Execution.md`](V5-FEAT-039-Holiday-Aware-Scheduled-Execution.md). Two-session target-seeking execution, holiday-aware opportunities, same-symbol internal netting, shared Kite funds, resizing, internal-transfer valuation, partial progress and bounded insufficient-funds retry. Implementation pending. | P2 | DECIDED |
| V4-FEAT-040 | Kite portfolio reconciliation (holdings and funds) | Available only for **Semi-Automatic and Automatic** portfolios; Manual mode must not expose or run it. Manually and on a configurable schedule, fetch Kite holdings/positions and funds/margins through read-only APIs while the session is usable, including outside market hours. Preview differences against StoX before applying anything. Kite data is evidence and must never silently overwrite the StoX transaction ledger, Strategy ownership, costs, fees, or history. Applying a difference requires an explicit audited reconciliation action. Persist runs, discrepancies, decisions, and failures. | P2 | OPEN |
| V4-FEAT-041 | Linked Markdown wiki rooted in Knowledge Board | Add wiki-style Knowledge pages with canonical `.md` Markdown sources rendered safely as HTML in-app. **Knowledge Board remains the root** of the nested page and navigation hierarchy. Every rendered page has a hierarchy-derived breadcrumb tree at the top beginning at Knowledge Board. Stable internal page links make cross-page linking easy without manually constructing deployment URLs and remain valid under the configured app base path. Handle missing/broken links clearly and provide navigation back to the Knowledge Board tree. | P2 | OPEN |
| V4-FEAT-042 | Separate role-based Admin Portal and investor application | Retain one login endpoint, then route each authenticated account to a role-specific application shell. Administrators see only administrative data and controls; normal users see the portfolio and investment application. Enforce separation server-side and in navigation/UI, including direct URLs and APIs. Administrators cannot own portfolios, stocks/holdings, strategies, recommendations, broker connections, or trading activity. Define migration and validation for existing administrator-owned investment data before enforcing this invariant. | P1 | OPEN |

## 3. Preserved V6 wishlist additions

1. **Dashboard Kite disconnect kill switch.** Immediately disconnect/revoke StoX's usable Kite session and block new submissions until reconnect. It does not cancel already-submitted orders.
2. **Emergency cancel-open-orders then disconnect kill switch.** After deliberate confirmation, attempt to cancel every StoX-managed Kite order not fully executed, including only the cancellable remainder of partial fills. Persist and report every failure; then disconnect. Disconnection is not proof of cancellation.
3. **Live Kite quote-based execution sizing.** Immediately before an automated/semi-automated order, fetch the latest applicable Kite price and derive whole shares through `target amount -> actual Strategy ownership -> remaining gap -> live price -> V3 capital/lending -> internal netting -> verified shared broker funds -> residual order`. This replaces V5's previous-session-close sizing, keeps the cushion as safety margin and reduces dependence on V5's 5%-retry fallback.

## 4. Planning rules

Before implementation, each selected feature should have:

1. A frozen Product Owner outcome and explicit non-goals.
2. Dependencies and sequencing identified.
3. Data ownership, authorization, migration, and audit implications resolved.
4. Acceptance criteria covering UI, APIs, scheduled behavior, and failure states as applicable.
5. A deployment and production-verification plan.

Priority is a planning signal, not execution order. Dependencies, risk, and prerequisite product decisions may change the delivery sequence.

## 5. Change log

| Date | Change |
|------|--------|
| 2026-09-04 | **FEAT-033 COMPLETE:** Discovery now shows default factory-screener readiness/latest totals and runs it inline with a missing-screener recovery path; no engine or API behavior changed. |
| 2026-09-04 | **FEAT-031 implementation stored:** hardened single-folder package and external secret loading implemented and locally verified. Status remains IN PROGRESS until the production cutover and cron path are verified. |
| 2026-09-04 | **FEAT-030 COMPLETE:** added full backend, frontend, and production-build CI; repaired the regressions it exposed; local backend verification passed 1,322 tests / 8,048 assertions and GitHub Actions run `33865499081` passed both PHP 8.4 and Node 22 jobs. |
| 2026-09-04 | Reconciled and froze FEAT-039 against current master and V3/V4. Preserved three agreed V6 Kite safety/live-sizing additions. FEAT-040 was not started. |
| 2026-09-02 | Created the dedicated canonical V5 wishlist by extracting all 24 open V5 items from the completed V4 register. V4 remains closed. |
