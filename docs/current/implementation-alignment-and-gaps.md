# Implementation Alignment And Gaps

## 1. Purpose

This index tracks accepted contracts requiring implementation verification, confirmed gaps, intentional deviations, runtime-only verification, and resolved alignment items. It does not redefine domain contracts or assume code is complete.

## 2. Classification Vocabulary

Phase 2 uses: `IMPLEMENTED`, `PARTIALLY_IMPLEMENTED`, `NOT_IMPLEMENTED`, `IMPLEMENTED_BUT_NOT_WIRED`, `IMPLEMENTED_DIFFERENTLY`, `TEST_ONLY`, `DEAD_CODE`, `RUNTIME_VERIFICATION_REQUIRED`, and `SPEC_CONFLICT`. Do not assign one of these merely from filenames, components, or passing related tests.

## 3. Current State Before Phase 2 Audit

Documentation reconstruction is complete. Current docs contain accepted contracts, implementation anchors, and explicit alignment notes. The V1-V7 implementation audit is still pending; there is no blanket claim that the codebase completely implements the corpus.

## 4. Domain Alignment Index

| Domain | Owning document | Main audit focus | Current status |
| --- | --- | --- | --- |
| Frontend / Navigation | [Frontend](./frontend-and-navigation.md) | Shell, rails, responsive/state adoption | `PENDING_PHASE_2_AUDIT` |
| Portfolio / Accounting | [Portfolio](./portfolio-cash-accounting.md) | Ledger/cash/ownership/capital integrity | `PENDING_PHASE_2_AUDIT` |
| Market Data | [Market](./market-data-and-data-quality.md) | Sync, quality, freshness, versions | `PENDING_PHASE_2_AUDIT` |
| Discovery / Screeners | [Discovery](./discovery-screeners-registries.md) | Grammar, discovery evidence, PIT runtime | `PENDING_PHASE_2_AUDIT` |
| Strategy / Recommendations | [Strategy](./strategy-and-recommendations.md) | Multi-strategy, lifecycle, capital | `PENDING_PHASE_2_AUDIT` |
| Execution / Broker / Safety | [Execution](./execution-broker-safety.md) | Gates, orders, halt, reconciliation | `PENDING_PHASE_2_AUDIT` |
| Analytics / Backtesting | [Analytics](./analytics-review-backtesting.md) | Pinning, replay, recovery | `PENDING_PHASE_2_AUDIT` |
| Notifications / Calendar | [Notifications](./notifications-calendar-alerts.md) | Occurrence/delivery, retry, reminders | `PENDING_PHASE_2_AUDIT` |
| Knowledge / Documentation | [Knowledge](./knowledge-and-documentation.md) | Ownership, capability shares, served help | `PENDING_PHASE_2_AUDIT` |
| Administration / Security / API | [Security](./administration-security-api.md) | Role/profile/token/TOTP boundary | `PENDING_PHASE_2_AUDIT` |
| Trading Artifacts | [Artifacts](./stox-trading-artifacts-ai-guide.md) | Binding, package, sharing, historical pins | `PENDING_PHASE_2_AUDIT` |

## 5. Highest-Risk Audit Areas

- **Frontend:** five-zone shell, right utility rail, Page Visit History, contextual Notes placement, V6 non-regression, responsive/state vocabulary.
- **Execution:** sessions/windows, internal match/residual ordering, broker funds, bounded insufficient-funds retry, reconciliation/halt, partial/finalization.
- **Accounting:** reservations, capital status, lending/recall/bridge, ownership isolation, pending proceeds.
- **Security:** Admin/Investor split, active profile, token scope, TOTP, entitlement, callback state.
- **Market/Analytics:** freshness, dataset attribution, point-in-time replay isolation, artifact/data pins.
- **Artifacts:** bindings, archive, sharing, upgrade/rollback, historical pins.
- **Notifications:** provider retry/runtime, event reachability, critical-banner behavior.

## 6. Runtime-Only Verification Areas

Provider/fallback behavior; scheduler/queue deployment; Sanctum cookie/CSRF configuration; broker callback deployment; real Telegram/email/webhook delivery; responsive/accessibility behavior; concurrency/race safety; and production execution/reconciliation require runtime evidence.

## 7. Test Evidence Index

Use detailed test-anchor tables in each owning document. High-level starting points: frontend navigation tests; transaction/cash/ownership tests; sync/freshness/quality tests; screener/artifact binding tests; strategy/lifecycle/capital tests; execution/Kite/TOTP/reconciliation tests; backtest/replay/paper tests; notification delivery/calendar/alert tests; wiki/context note tests; and auth/role/token tests.

## 8. Known Intentional Legacy / Non-Gaps

- Lido repository/database/CSS naming persists alongside StoX branding.
- Legacy `/api/*` remains alongside additive `/api/v1/*`.
- Generated served help is distinct from current documentation source/authority.
- Archived JWT wording is superseded by Sanctum current architecture.

## 9. Confirmed Gaps

No new implementation gap is classified here before Phase 2 evidence. The accepted V6 Page Visit History/right utility rail contract is `PENDING_PHASE_2_AUDIT`; it must be traced for mount/reachability and responsive behavior before classification.

## 10. How Phase 2 Updates This File

Phase 2 populates an auditable gap register with requirement ID, domain, contract reference, implementation evidence, verdict, severity, user impact, runtime-verification need, and recommended next action. Audit gathers evidence only; it does not fix code.

## 11. Debugging And Investigation Entry Points

| Domain | Start with | Code/test entry point |
| --- | --- | --- |
| Frontend | Frontend doc | `PageChrome`, navigation catalog, shell/navigation tests |
| Portfolio | Portfolio doc | ledger/holding/cash services and accounting tests |
| Market | Market doc | sync/gap/freshness services and tests |
| Discovery | Discovery doc | screener evaluator/run/registry tests |
| Strategy | Strategy doc | generation/lifecycle/capital tests |
| Execution | Execution doc | safety/order/Kite/reconciliation tests |
| Analytics | Analytics doc | performance/backtest/replay tests |
| Notifications | Notifications doc | publisher/planner/processor/calendar tests |
| Knowledge | Knowledge doc | contextual note/wiki services/tests |
| Security | Security doc | middleware/auth/token/TOTP tests |
| Artifacts | Artifact guide | validation/package/binding tests |

## 12. Archive Use

Archive is history/source archaeology. Unsuperseded accepted knowledge should already be promoted into current docs. Phase 2 may use archive for detail but must not treat it as automatic current override.
