# StoX V8 acceptance audit (living)

Authoritative specs: `docs/archive/specs/LidoPortfolio-V8-Wishlist.md`. Implementation ledger: `docs/V8-IMPLEMENTATION-LEDGER.md`. Requirement-level gaps: [V8-GAP-AUDIT.md](V8-GAP-AUDIT.md).

Status key: **COMPLETE** | **REVIEW** | **IN PROGRESS** | **NOT STARTED** | **N/A**

| Epic | Status | Evidence |
|------|--------|----------|
| FEAT-052 OpenTelemetry / LidoTelemetry | REVIEW | Production Collector transform now strips the observed URL/query attributes; a fresh synthetic trace reached LidoTelemetry with correct parentage and no marker in either received span. Broader privacy, propagation, metrics and fail-open acceptance remain open |
| FEAT-054 Historical fundamentals | REVIEW | Three-stock production bootstrap stored 530 Yahoo facts without rejected rows; deployed SBIN Basic/Advanced/history slice verified. Targeted-scope guard passed CI and reached production at `ac6602ae`; official NSE/BSE feeds, broad coverage, and remaining provider/UI acceptance remain open |
| FEAT-055 Access requests | REVIEW | Local §FEAT-055 checklist (055-01–055-10) + `AccessRequestWorkflowTest`; real Turnstile/mail/deployed multi-worker validation remains external |
| FEAT-056 ML lifecycle | REVIEW | Drift trigger, promotion review, SSE, cancel, retries, notifications, retention API + lifecycle tick gate, and stale-run recovery; deployed lifecycle worker/runtime remains external |
| FEAT-057 ML feature engineering / training | REVIEW | Registry `v8-registry-11`, 50/50 implemented features; calibration, chronological validation, durable candidate archive, bounded same-architecture 1m/3m/6m training, partition coverage and artifact reload/contribution evidence; production/browser acceptance remains external |
| FEAT-061 Guided tour | REVIEW | §FEAT-061 checklist, `GuidedTourTest.php`, persisted resume, full configured-route traversal and desktop/mobile/tablet browser journeys are locally verified; live refresh/session interruption, screen-reader and broader-device acceptance remain |
| FEAT-062 Fundamental signals & AI | REVIEW | Deterministic signal catalogue, PIT comparisons, provider-neutral orchestrator, insights page, admin preferences, invocation audit, daily limits and provider test; browser/mobile and real-provider validation remain |
| FEAT-063 Live microstructure | REVIEW | Production VPS collector and paid Kite FULL-mode stream validated with 499 instruments; 2026-09-29 finalization marker records 15,213 rows, primary and same-VPS backup-copy partitions match recursively, and raw spool is empty after cleanup. Independent secondary-storage durability, deployed Admin controls, persistent hold, restart/reconnect and retry acceptance remain open; journal recorded a WebSocket 1006 close at 15:30 IST. |
| FEAT-064 Screener/Strategy UX | REVIEW | Runtime create/import/shared copy; WP-09/10; `Feat064MandatoryAuditAcceptanceTest`; provenance `definition_json`; Playwright screener and incomplete-Strategy `Setup Required` journey; live membership drift/runtime acceptance remains external |
| FEAT-065 Intraday ML historical platform | REVIEW | Kite client, Parquet store, DuckDB/Polars builders, instrument map, checkpoints and retryable backfill; live POC/full NIFTY 500 corpus remains external |

## Test gate

```bash
cd app && php artisan test tests/Feature/V8/
```

Latest recorded: **1,346 passed / 1,347 total** Feature tests under `tests/Feature/` (1 extension-only skip, 0 failures under PHP CLI 512 MB with the matching x86_64 ML runtime); the focused configured V8 suite is 191 total with 189 passed and 2 environment skips. The focused FEAT-061/062 Chromium journeys pass 9/9 under Node 20.19.1. `npm run test:js`, Vitest, build, typecheck and docs checks are green; isolated Python ML, intraday and microstructure suites are green.

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
3. Do not mark V8 release-complete until every epic is **COMPLETE** or explicitly **N/A**; REVIEW means implementation is locally complete but bounded external evidence remains.

## Production acceptance evidence — 2026-09-29

FEAT-063 production checks confirmed the VPS collector was enabled and active; paid Kite Connect delivered real FULL-mode ticks for 499 mapped instruments; and minute Parquet output was produced. Post-market finalization recorded 15,213 rows in identical primary and backup `_FINALIZED.json` markers. Recursive comparison of the day partitions found no differences, and the raw-tick spool contained no files after finalization. The backup directory is under the same VPS shared-storage tree, so this proves a consistent local copy, not an independent secondary backup. The journal recorded a WebSocket close code `1006` at 15:30 IST; finalization completed despite that close.

FEAT-063 remains **REVIEW**. Independent secondary-storage backup, deployed Admin controls, persistent manual hold, restart/reconnect/resubscription, retry flows, and normal market-close handling of the WebSocket close still need acceptance evidence.


## 2026-10-01 closure continuation checkpoint (historical; see 2026-10-02 reconciliation below)

Deployed build `ef66133c05cb84d9760f95026ffe6d08fcc44cde` (run `36899469783`), distinct from current repository master at the start of this checkpoint (`3cba7f9`). The ten per-epic audits now hold criterion-level PASS, FAILED, BLOCKED and NOT YET RUN rows; none is declared COMPLETE.

- FEAT-054: production runs #1–2 completed three representative stocks with 530 Yahoo facts, no rejected rows. Official NSE/BSE feeds are disabled/unconfigured. Active-equity universe is 5,140 while only 19 stocks had facts before the slice. A targeted unknown symbol could accidentally expand to the entire universe; a guarded fix and focused tests are on branch `audit/v8-closure-20261001`, pending CI and a safely sequenced deployment.
- FEAT-057: preview run #1 was 332/360, zero failed and zero boundaries at 18:31:37 UTC; existing worker and continuation own the NSE lane. No duplicate preview/apply, worker, training, deployment or service restart was initiated in this checkpoint.
- FEAT-052/055: configuration and service presence verified only; end-to-end provider/runtime results remain open. FEAT-062 real-provider test is blocked by disabled AI and absent configured keys. FEAT-056 qualification is blocked while its production campaign remains blocked; lifecycle remains disabled.
- FEAT-061/064 deployed authenticated UX paths, FEAT-063 independent backup/recovery, and FEAT-065 research-machine Kite corpus remain open. The Mac research device was offline.

Release and production acceptance gates remain unsatisfied. After the NSE preview/apply stabilizes, reconcile the deployed SHA with master, pass CI and deploy without interrupting a worker, then rerun release gates and update each criterion from actual evidence.


## 2026-10-02 production reconciliation

- **Resolved:** FEAT-054 targeted unknown-symbol scope defect. PR #23 passed all three CI jobs in run `36925637515`, merged as `920eb6a`, and is included in live build `ac6602ae2db35b52b68a7183c50f987a1b4d615f` (`build-332-attempt-1-ac6602ae2db3`; successful deployment run `36963314151`). The 2026-10-01 branch-pending and not-deployed wording above is historical. A subsequent deployed unknown-symbol dry-run exited 1 with no new run or job; the per-epic audit records the bounded probe.
- **FEAT-054 remains REVIEW:** only two bounded production runs/three jobs were observed, covering TCS, SBIN and LAURUSLABS with 530 Yahoo facts. Official NSE/BSE adapters are still disabled and feed URLs unset. Official precedence and full-universe quality/coverage have not passed.
- **FEAT-057 remains FAILED at apply:** its 360-date preview completed with zero failed dates and minimum 94.2266% mapping, but the first apply date failed the sealed membership hash comparison and the run was cancelled with zero processed dates/boundaries. Read-only production inspection on 2026-10-02 found no replacement run, one unreserved `ml-acceptance` queue job, and the dedicated worker inactive. Reconcile that job and diagnose mapping determinism with the NSE operator before a fresh preview; do not train from this failed apply.
- **Other gates remain REVIEW:** production telemetry propagation, real access-request provider/mail/concurrency, deployed lifecycle qualification, full guided-tour and accessibility, real AI-provider validation, independent FEAT-063 backup/restore and controls, FEAT-064 live provenance, and FEAT-065 real Kite minute corpus. No epic is promoted to COMPLETE or N/A by this reconciliation.

The CI and production deployment gates for the FEAT-054 guard are now satisfied. **Overall V8 release acceptance remains open** until every active epic has production-backed COMPLETE or justified N/A evidence.


### FEAT-052 Collector reconciliation — 2026-10-02

The root-owned Collector privacy transform was deployed and validated after the historical FAILED query-attribute finding. A fresh synthetic HTTP trace produced two received spans without the marker or four URL/query keys; the per-epic audit holds the backup, processor order, trace ID and bounded proof. This clears the **observed LidoTelemetry sink leak for the tested HTTP path**. FEAT-052 stays **REVIEW**, and the V8 release gate remains open for broader privacy and propagation/fail-open evidence.
