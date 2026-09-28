# StoX V8 acceptance audit (living)

Authoritative specs: `docs/archive/specs/LidoPortfolio-V8-Wishlist.md`. Implementation ledger: `docs/V8-IMPLEMENTATION-LEDGER.md`. Requirement-level gaps: [V8-GAP-AUDIT.md](V8-GAP-AUDIT.md).

Status key: **done** | **partial** | **not started** | **n/a**

| Epic | Status | Evidence |
|------|--------|----------|
| FEAT-052 OpenTelemetry / LidoTelemetry | partial | Fail-open HTTP OTLP traces/events + focused HTTP duration metrics; traceparent; route-view duration; queue/scheduler spans; business catalogue; `cpanel-lido-telemetry-probe.php` for Collector smoke test; gaps: official OTEL PHP/JS SDK instrumentation and production Collector validation |
| FEAT-054 Historical fundamentals | partial | Bootstrap + investor UI + valuation metric history (daily/monthly P/E, P/B); NSE/BSE JSON adapters; gaps: live exchange feeds at scale |
| FEAT-055 Access requests | done | §FEAT-055 checklist (055-01–055-10) + `AccessRequestWorkflowTest` |
| FEAT-056 ML lifecycle | partial | Drift trigger (+ disabled gate `MlDriftTriggerLifecycleTest::test_tick_skips_drift_retrain_when_trigger_disabled`), promotion review, SSE, cancel, retries, notifications, retention API + lifecycle tick gate (`MlLifecycleRetentionGateTest`, per-horizon `STOXLA_ML_SCHEDULE_*_ENABLED`); retention opt-in via `STOXLA_ML_RETENTION_ENABLED` |
| FEAT-057 ML feature engineering / training | partial | Registry `v8-registry-10`, 50/50 implemented features; validation-set probability calibration (Platt/isotonic); Ridge return regressor; chrono grid; HistGradientBoosting challenger; durable candidate archive; bounded same-architecture 1m/3m/6m training, partition coverage and artifact reload/contribution evidence; production/browser acceptance remains external |
| FEAT-061 Guided tour | done | §FEAT-061 checklist + `GuidedTourTest.php` |
| FEAT-062 Fundamental signals & AI | partial | WC/CWIP + peer table; orchestrator; insights page; admin prefs; invocation audit + daily limits; provider test |
| FEAT-063 Live microstructure | partial | Collector, spool, OI/microprice, spread min/max; gaps: VPS hardening, production Parquet validation |
| FEAT-064 Screener/Strategy UX | partial | Runtime create/import/shared copy; WP-09/10; `Feat064MandatoryAuditAcceptanceTest`; provenance `definition_json`; Playwright screener and incomplete-Strategy `Setup Required` journey; remaining live membership drift/runtime acceptance |
| FEAT-065 Intraday ML historical platform | partial | Kite client, Parquet store, DuckDB/Polars builders, instrument map; gaps: full NIFTY 500 corpus backfill |

## Test gate

```bash
cd app && php artisan test tests/Feature/V8/
```

Latest recorded: **1,336** Feature tests under `tests/Feature/` (1,335 passed, 1 skipped, 0 failures under PHP CLI 512 MB); the focused configured V8 suite is 190/190. The FEAT-064 browser journey `screener-investor-workflow.spec.js` passes 2/2 under Node 20.19.1. `npm run test:js`, Vitest, build, typecheck and docs checks are green; isolated Python ML, intraday and microstructure suites are green.

## FEAT-055 checklist (frozen decisions 055-01 … 055-10)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| 055-01 | Email verified before pending | done | `AccessRequestVerificationService`, workflow test |
| 055-02 | Reject + reversible ban | done | `AccessRequestBan`, reject test + audit event |
| 055-03 | Ignore + cooldown | done | `AccessRequestPolicyService`, workflow test |
| 055-04 | Name + email only on form | done | public API validation |
| 055-05 | Admin Create issues secure invite | done | `AccessRequestAdminService::createInvite` |
| 055-06 | Outcome notifications | done | `AccessRequestNotificationService` + mailables |
| 055-07 | Internal admin reason (optional) | done | API + admin prompt on Ignore/Reject |
| 055-08 | Active invite blocks duplicate request | done | policy tests |
| 055-09 | Prior request history in admin | done | admin detail `audit_events` / history API |
| 055-10 | No public status lookup | done | no public status route |

## FEAT-061 checklist (frozen decisions 061-01 … 061-10)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| 061-01 | Welcome prompt until cap/complete/dismiss | done | `GuidedTourTest` |
| 061-02 | Manual relaunch from Profile | done | `config/guided_tour.php`, Profile UI |
| 061-03 | Multi-route tour | done | `investorTourSteps.js` |
| 061-04 | Core journey scope | done | tour step config |
| 061-05 | Investor-only | done | admin blocked from investor tour API |
| 061-06 | Backend persistence | done | `portfolio_user_onboarding_state` |
| 061-07 | Resume from last step | done | interrupted tour test |
| 061-08 | Explanatory spotlight only | done | guided tour overlay docs |
| 061-09 | Skip missing targets | done | `GuidedTourService` + frontend overlay |
| 061-10 | Auto prompt cap | done | `max_auto_prompts` + skip/dismiss tests |

## FEAT-056 checklist (selected frozen items)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| 056-04 | Drift may queue retrain; no auto-promote | done | `MlDriftTriggerLifecycleTest.php` |
| 056-05 | SSE progress for admin | done | `trainingRunStream` + `MlScoringAdminPage` |
| 056-09 | Action-oriented external notifications | done | `MlLifecycleNotificationTest.php` |
| 056-11 | Cancel queued/running at checkpoints | done | `MlTrainingRunCancelTest.php` |
| 056-13 | One active/queued/cancelling run per horizon | done | `MlLifecycleAutomationTest`, `MlRetrainQueueTest` |
| 056-03 | Bounded transient retries | done | `MlTrainingRunRetryTest.php` |
| 056-15 | Bounded artifact retention | partial | `MlArtifactRetentionService`; env gate + admin preview/apply (`MlScoringAdminPage`, `MlArtifactRetentionTest`) |

## FEAT-057 checklist (selected acceptance items)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| 057-01 | Versioned feature registry | partial | `v8-registry-10`, `MlFeatureRegistryAdminTest` (49 numeric implemented) |
| 057-07 | Logistic interpretable baseline | done | `ml_adapter.py` LogisticRegression artifact |
| 057-08 | Gradient boosting challenger | partial | HistGradientBoosting evidence + promotable sibling (`MlChallengerEvidenceTest`, `MlPromotionReviewTest`, admin promote UI) |
| 057-12 | Repeated chronological validation | partial | `MlChronologicalValidationGridService` (v2 windows + regime slices) |
| 057-19 | Stock detail ML outlook | partial | `GET ml-insights` + watchlist ML tab |
| 057-21 | Screener ML operands | done | `MlScreenerOperandTest` |

## FEAT-064 checklist (selected work packages)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| WP-09 | Contextual screener create from Strategy | done | `strategyScreenerReturnFlow.test.mjs` |
| WP-10 | Provenance migration gate report | done | `StrategyProvenanceMigrationReportTest.php` |
| — | Recommendation provenance resolution | partial | `StrategyProvenanceResolutionService` (`definition_json` on pinned screeners), `StrategyProvenanceResolutionTest`, audit test |
| WP-05 | Screener definition-copy sharing | done | `ScreenerDefinitionCopySharingTest.php`, `F060SharedScreenerAuthzTest` |
| §7 | Mandatory audit acceptance (ROC + universe) | partial | `Feat064MandatoryAuditAcceptanceTest.php` (no browser automation) |
| WP-08 | Readiness separate from save | done | `StrategyReadinessTest.php` |
| WP-07 | Immutable strategy save | done | `StrategyImmutableSaveTest`, `StrategyProvenanceRegressionTest` |

## FEAT-065 checklist (selected acceptance items)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| 065-04 | Schema-versioned Parquet canonical store | partial | `parquet_store.py`, worker `--apply` |
| 065-06 | DuckDB over Parquet | partial | `dataset_builder.py` + tests |
| 065-07 | Polars PIT daily assembly | partial | `polars_daily_ohlc` |
| 065-01 | Historical importer | partial | `kite_historical_client.py`, instrument map |

## Next audit actions

1. Close remaining partial epics per [V8-GAP-AUDIT.md](V8-GAP-AUDIT.md) with spec-level evidence only.
2. Re-run full PHPUnit + frontend build before production deploy.
3. Do not mark V8 release-complete until every epic row is **done** or explicitly **n/a**.
