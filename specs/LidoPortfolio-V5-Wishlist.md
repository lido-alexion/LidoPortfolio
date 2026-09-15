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

This is the canonical V5 status and closure register. V4 is closed; V5 is now formally closed; V6 has since been fully implemented; V7 implementation has also progressed independently.

Feature IDs retain the historical `V4-FEAT-*` prefix for traceability.

Status values: `OPEN` · `BLOCKED` · `DECIDED` · `IN PROGRESS` · `COMPLETE` · `SUPERSEDED`.

## 2. Current V5 status

V5 scope remains 18 items.

**V5 is COMPLETE / CLOSED. Current count: 0 OPEN / 0 IN PROGRESS / 0 DECIDED / 16 COMPLETE / 2 SUPERSEDED.**

| ID | Item | Current state | Status |
|---|---|---|---|
| V4-FEAT-003 | B4 persistent app-wide critical banner | Superseded by the complete FEAT-004 Notification Service / Critical presentation model. | SUPERSEDED |
| V4-FEAT-004 | Notification channel abstraction + email/webhook | Implemented and verified: canonical Notification Center/lifecycle, In-app, Telegram, Email and signed Webhook delivery, verification, reminders, health handling and audience isolation. See `V5-FEAT-004-Notification-Service.md`. | COMPLETE |
| V4-FEAT-007 | Indicator Registry deeper versioning / remaining cutover | Implemented and verified: exact SemVer definitions, lifecycle history, pinned dependencies, evaluation provenance, result states, parameter precedence and Admin inspection. See `V5-FEAT-007-Indicator-Registry-Versioning.md`. | COMPLETE |
| V4-FEAT-008 | Trading Artifact Framework remaining phases | Complete: immutable lifecycle/distribution, exact Portfolio runtime resolution, legacy backfill/projection, Recommendation/order/fill/simulation evidence, authoring cutover, production rollout, idempotence, representative runtime/backtest evidence and final CI/MySQL verification are complete. See `V5-FEAT-008-IMPLEMENTATION-STATUS.md`. | COMPLETE |
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
| V4-FEAT-042 | Separate role-based Admin Portal and Investor application | Complete: production ownership audit conflict was forensically classified as disposable legacy/default Admin-owned state, remediated under Product Owner disposition, and the final production audit reports `safe_to_enforce=true` with zero conflicts. See `V5-FEAT-042-Role-Separated-Admin-Investor-Applications.md`. | COMPLETE |

## 3. Remaining V5 closure work

None. FEAT-008 and FEAT-042 both passed their production closure gates on 2026-09-15.

FEAT-031 is no longer a closure blocker because its cPanel deployment architecture has been superseded by the successful VPS production architecture.

## 4. Later-version reconciliation

The items historically deferred from V5 have since moved through later registers:

- V4-FEAT-016, 035 and 036 were implemented in V6.
- V4-FEAT-017 AI Assistant is now V9.
- V4-FEAT-018 ML Scoring Models is implemented/deployed in V7.
- V4-FEAT-019 Instrument Expansion is now V9.
- Dashboard/UX, emergency execution controls, live quote sizing, Execution State, Clone-as-Paper, Admin Audit Explorer and Contextual Notes are complete in V6.

Use `LidoPortfolio-V6-Wishlist.md`, `LidoPortfolio-V7-Wishlist.md`, `LidoPortfolio-V8-Wishlist.md` and `LidoPortfolio-V9-Wishlist.md` for current later-version status rather than this historical deferral section.

## 5. V5 closure result

V5 is formally **COMPLETE / CLOSED** as of 2026-09-15. The final closure evidence is the production FEAT-008 rollout/idempotence/runtime/backtest evidence, the production FEAT-042 ownership audit/remediation evidence, and GitHub Actions CI run `34961289670` for commit `75ed8bac51cac12a59186c0319df45eb2333657b`.

## 6. Latest reconciliation

| Date | Change |
|---|---|
| 2026-09-15 | Formally closed V5. FEAT-008 completed production rollout (`10 created`, idempotence `0 created / 10 skipped`), runtime Recommendation evidence (`298` mapped recommendations), representative backtest evidence and final CI/MySQL verification. FEAT-042 completed production ownership remediation/audit with `safe_to_enforce=true`, one Admin checked and zero conflicts. |
| 2026-09-15 | Reconciled V5 against the live VPS deployment. FEAT-031 changed from `IN PROGRESS` to `SUPERSEDED` because the original GoDaddy/cPanel topology was abandoned and replaced by the operational `stoxla.in` VPS release architecture. At that checkpoint, FEAT-008 and FEAT-042 were the final closure gates; they are now complete. |
| 2026-09-13 | Production deployment to `stoxla.in` completed successfully through GitHub Actions with backend/MySQL/OpenAPI/frontend/typecheck/build/package/deploy/HTTPS health gates. |
| 2026-09-11 | FEAT-015, FEAT-020, FEAT-040 and FEAT-041 closure/acceptance completed. |
| 2026-09-09 | MySQL migration/seed hardening and FEAT-008 rollout-safety tooling completed; FEAT-008 retained representative-data and final-audit gates. |
| 2026-09-08 | FEAT-004 and FEAT-007 completed. |
| 2026-09-07 | V5/V6 roadmap reconciliation froze the 18-item V5 scope. |
