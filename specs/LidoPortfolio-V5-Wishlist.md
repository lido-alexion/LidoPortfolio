# LidoPortfolio V5 Wishlist

| Field | Value |
|---|---|
| **V4 Status** | **V4 COMPLETE AND CLOSED** (18/18 active features complete) |
| **Document type** | Canonical V5 product / closure register |
| **Created** | 2026-09-02 |
| **Last reconciled** | 2026-09-15 |
| **Canonical path** | `specs/LidoPortfolio-V5-Wishlist.md` |
| **Related** | `LidoPortfolio-V4-Wishlist.md` · `LidoPortfolio-V6-Wishlist.md` · `../implementation.md` |

## 1. Purpose and authority

This is the canonical V5 status and closure register. V4 is closed; V6 has since been fully implemented; V7 implementation has also progressed independently. Those later versions do not change the remaining V5 closure evidence listed here.

Feature IDs retain the historical `V4-FEAT-*` prefix for traceability.

Status values: `OPEN` · `BLOCKED` · `DECIDED` · `IN PROGRESS` · `COMPLETE` · `SUPERSEDED`.

## 2. Current V5 status

V5 scope remains 18 items.

**Current count: 0 OPEN / 2 IN PROGRESS / 0 DECIDED / 14 COMPLETE / 2 SUPERSEDED.**

| ID | Item | Current state | Status |
|---|---|---|---|
| V4-FEAT-003 | B4 persistent app-wide critical banner | Superseded by the complete FEAT-004 Notification Service / Critical presentation model. | SUPERSEDED |
| V4-FEAT-004 | Notification channel abstraction + email/webhook | Implemented and verified: canonical Notification Center/lifecycle, In-app, Telegram, Email and signed Webhook delivery, verification, reminders, health handling and audience isolation. See `V5-FEAT-004-Notification-Service.md`. | COMPLETE |
| V4-FEAT-007 | Indicator Registry deeper versioning / remaining cutover | Implemented and verified: exact SemVer definitions, lifecycle history, pinned dependencies, evaluation provenance, result states, parameter precedence and Admin inspection. See `V5-FEAT-007-Indicator-Registry-Versioning.md`. | COMPLETE |
| V4-FEAT-008 | Trading Artifact Framework remaining phases | Core implementation is present: immutable lifecycle/distribution, exact Portfolio runtime resolution, legacy backfill/projection, Recommendation/order/fill/simulation evidence, authoring cutover and rollout tooling. Remaining closure is representative production-shaped rollout evidence plus final frozen-criteria audit. See `V5-FEAT-008-IMPLEMENTATION-STATUS.md`. | IN PROGRESS |
| V4-FEAT-012 | Admin force-logout of other users | Implemented and verified with server-side authorization, self-protection, idempotent revocation and audit evidence. | COMPLETE |
| V4-FEAT-013 | Cash-as-of / export / compare polish | Implemented and verified: effective-dated cash history, Cash Statement, historical holdings+cash valuation, Date-A/B comparison and schema-versioned CSV exports. | COMPLETE |
| V4-FEAT-015 | Tax reporting / attribution / benchmarks | Implemented and verified: performance/XIRR/TWR, benchmark/risk evidence, attribution, FIFO tax-lot derivation over WAVG accounting, India-focused tax reporting and exports. | COMPLETE |
| V4-FEAT-020 | Paper Portfolio / Portfolio Replay / Strategy Backtest | Implemented and verified, including pinned artifacts, point-in-time safe backtest/replay, Paper Portfolio, configurable declared parameters and Backtest-to-Draft flow. | COMPLETE |
| V4-FEAT-030 | CI workflow for PHPUnit + frontend build | Implemented and verified; CI covers backend, MySQL migration/seed chain, frontend tests, TypeScript and production build. | COMPLETE |
| V4-FEAT-031 | Production secrets / single-folder deploy hardening | Original GoDaddy/cPanel implementation was completed but its target topology was abandoned before production cutover. The live StoX production architecture is now the dedicated `stoxla.in` VPS with GitHub Actions release deployment, shared secrets/storage, rollback and health checks. The cPanel-specific feature is therefore closed as superseded. See `V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md`. | SUPERSEDED |
| V4-FEAT-033 | Discovery inline default screener | Implemented. | COMPLETE |
| V4-FEAT-034 | Richer Evaluation history UX | Implemented. | COMPLETE |
| V4-FEAT-037 | Dashboard-first daily Kite readiness and reconnect | Implemented. | COMPLETE |
| V4-FEAT-038 | Exchange holidays in Calendar with automatic holiday-list sync | Implemented with official NSE CM sync, provenance and Admin override handling. | COMPLETE |
| V4-FEAT-039 | Holiday-aware scheduled order execution | Implemented and verified: holiday-aware target-seeking, execution coordination, internal transfers, funds resizing, retries, revalidation and lifecycle notifications. | COMPLETE |
| V4-FEAT-040 | Kite portfolio reconciliation | Implemented and verified: holdings/funds reconciliation, immutable evidence, execution blocking on holdings mismatch, lifecycle notifications and Investor surfaces. | COMPLETE |
| V4-FEAT-041 | Linked Markdown Wiki rooted in Knowledge Board | Implemented and verified: hierarchy, stable links, revisions, search, managed images, export, guarded deletion and revocable isolated sharing. | COMPLETE |
| V4-FEAT-042 | Separate role-based Admin Portal and Investor application | Implementation is complete. Remaining formal gate is the read-only Admin-investment ownership audit against actual production data and explicit disposition of any conflict. See `V5-FEAT-042-Role-Separated-Admin-Investor-Applications.md`. | IN PROGRESS |

## 3. Remaining V5 closure work

Only two V5 items retain formal closure gates:

1. **FEAT-008 — Trading Artifact Framework:** execute/document the rollout validation against production-shaped or production data as appropriate and complete the final frozen-criteria audit.
2. **FEAT-042 — Admin/Investor separation:** run `php artisan portfolio:audit-admin-investment-ownership --json` against production data and disposition any reported conflict. The command is read-only.

FEAT-031 is no longer a closure blocker because its cPanel deployment architecture has been superseded by the successful VPS production architecture.

## 4. Later-version reconciliation

The items historically deferred from V5 have since moved through later registers:

- V4-FEAT-016, 035 and 036 were implemented in V6.
- V4-FEAT-017 AI Assistant is now V9.
- V4-FEAT-018 ML Scoring Models is implemented/deployed in V7.
- V4-FEAT-019 Instrument Expansion is now V9.
- Dashboard/UX, emergency execution controls, live quote sizing, Execution State, Clone-as-Paper, Admin Audit Explorer and Contextual Notes are complete in V6.

Use `LidoPortfolio-V6-Wishlist.md`, `LidoPortfolio-V7-Wishlist.md`, `LidoPortfolio-V8-Wishlist.md` and `LidoPortfolio-V9-Wishlist.md` for current later-version status rather than this historical deferral section.

## 5. V5 closure rule

V5 is formally closed only when the two remaining evidence gates above are resolved and the status register is reconciled accordingly. Later-version implementation does not by itself waive those two evidence requirements.

## 6. Latest reconciliation

| Date | Change |
|---|---|
| 2026-09-15 | Reconciled V5 against the live VPS deployment. FEAT-031 changed from `IN PROGRESS` to `SUPERSEDED` because the original GoDaddy/cPanel topology was abandoned and replaced by the operational `stoxla.in` VPS release architecture. V5 now has only FEAT-008 and FEAT-042 formal closure gates. |
| 2026-09-13 | Production deployment to `stoxla.in` completed successfully through GitHub Actions with backend/MySQL/OpenAPI/frontend/typecheck/build/package/deploy/HTTPS health gates. |
| 2026-09-11 | FEAT-015, FEAT-020, FEAT-040 and FEAT-041 closure/acceptance completed. |
| 2026-09-09 | MySQL migration/seed hardening and FEAT-008 rollout-safety tooling completed; FEAT-008 retained representative-data and final-audit gates. |
| 2026-09-08 | FEAT-004 and FEAT-007 completed. |
| 2026-09-07 | V5/V6 roadmap reconciliation froze the 18-item V5 scope. |
