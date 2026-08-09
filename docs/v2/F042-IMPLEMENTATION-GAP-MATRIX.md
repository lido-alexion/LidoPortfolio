# F042 Implementation Gap Matrix

**Date:** 2026-08-09 (updated post-hardening)  
**Spec:** [F042-DATA-QUALITY-SPEC.md](./F042-DATA-QUALITY-SPEC.md)  
**Status:** V2 F042 **COMPLETE** (`F042_COMPLETE_WITH_NON_BLOCKERS`). OHLCV mutation items closed by F043.

---

## Summary

| Status | Count (post-hardening) |
|--------|----------------------:|
| NO_GAP | 20+ |
| TEST_MISSING | 0 (F042 suite added) |
| IMPLEMENTATION_MISSING | 0 (required gaps closed) |
| DEFERRED_TO_F043 / delivered by F043 | 0 open — OHLCV repair + factor consumption delivered in **F043_COMPLETE** |
| INTENTIONAL_OUT_OF_SCOPE | 4 |

---

## Requirement gap matrix (post-hardening)

| Requirement | Current Implementation | Status | Test Evidence |
|-------------|------------------------|--------|---------------|
| F042-R001 Exchange feed detection | `DataQualityCorporateActionSyncService` + run id | NO_GAP | `DataQualityDetectionTest` |
| F042-R002 Heuristic detection | `DataQualityCorporateActionHeuristicService` | NO_GAP | `DataQualityDetectionTest` |
| F042-R004 Duplicate pending prevention | Dedupe with `lockForUpdate` | NO_GAP | `DataQualityIssueServiceTest` |
| F042-R004b Append evidence on re-detect | `createOrRefreshPendingIssueForStock` | NO_GAP | `DataQualityIssueServiceTest` |
| F042-R005 Immutable detection fields | Unchanged on re-detect | NO_GAP | `DataQualityIssueServiceTest` |
| F042-R006–R009 Resolution/audit | `DataQualityResolutionService` | NO_GAP | `DataQualityResolutionServiceTest`, `DataQualityApiTest` |
| F042-R010 Conditional auto-accept | `autoAcceptStaleIssues` + config | NO_GAP | `DataQualityAutoAcceptTest` |
| F042-R011 Adjustment factor on accept | `DataQualityAdjustmentFactorService` | NO_GAP | Resolution + API tests |
| F042-R011b F043 handoff marker | `ohlcv_repair_status: pending` + scope | NO_GAP | Resolution + API tests |
| F042-R014 Admin-only access | middleware + tests | NO_GAP | `DataQualityApiTest` |
| F042-R015 Admin REST surface | `DataQualityController` | NO_GAP | `DataQualityApiTest` |
| F042-R016 Admin UI | React pages + `re_resolve` on history | NO_GAP | Manual / API tests |
| Pipeline guard | `DataQualityGuardService` | NO_GAP | `DataQualityGuardServiceTest`, pipeline test |
| Concurrent resolution 409 | `requirePendingReview` + lock | NO_GAP | `DataQualityApiTest`, unit tests |
| Detection run ID | evidence payload | NO_GAP | Issue + detection tests |
| F042 PHPUnit coverage | 35 tests | NO_GAP | Full DataQuality filter |
| OHLCV repair on accept | Intentionally not in F042 | DELIVERED_BY_F043 | F043 consumes pending factors |
| Consume adjustment factors | F042 writes only; F043 reads/applies | DELIVERED_BY_F043 | `F043_COMPLETE` |
| Non-CA issue types | Not implemented | INTENTIONAL_OUT_OF_SCOPE | — |

---

## Test inventory

| File | Tests |
|------|------:|
| `tests/Unit/DataQualityIssueServiceTest.php` | 3 |
| `tests/Unit/DataQualityResolutionServiceTest.php` | 7 |
| `tests/Unit/DataQualityAutoAcceptTest.php` | 8 |
| `tests/Unit/DataQualityGuardServiceTest.php` | 3 |
| `tests/Feature/DataQualityApiTest.php` | 9 |
| `tests/Feature/DataQualityDetectionTest.php` | 3 |
| `tests/Feature/DataQualityPipelineGatingTest.php` | 2 |
| **Total** | **35** |

Run: `php vendor/bin/phpunit --filter DataQuality`

---

*Related: [F042-DATA-QUALITY-SPEC.md](./F042-DATA-QUALITY-SPEC.md), [F042-POLICY-DECISIONS.md](./F042-POLICY-DECISIONS.md)*
