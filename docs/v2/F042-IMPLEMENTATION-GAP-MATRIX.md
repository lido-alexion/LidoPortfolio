# F042 Implementation Gap Matrix

**Date:** 2026-08-09  
**Spec:** [F042-DATA-QUALITY-SPEC.md](./F042-DATA-QUALITY-SPEC.md)  
**Purpose:** Compare proposed F042 requirements against current repository implementation.

**Status classifications:** `SPEC_MISSING` | `IMPLEMENTATION_MISSING` | `IMPLEMENTATION_PARTIAL` | `TEST_MISSING` | `DOCUMENTATION_MISSING` | `INTENTIONAL_OUT_OF_SCOPE` | `DEFERRED_TO_F043` | `NO_GAP`

---

## Summary

| Status | Count |
|--------|------:|
| NO_GAP | 9 |
| TEST_MISSING | 12 |
| SPEC_MISSING | 1 (resolved by spec doc creation) |
| IMPLEMENTATION_PARTIAL | 3 |
| DOCUMENTATION_MISSING | 2 |
| DEFERRED_TO_F043 | 3 |
| INTENTIONAL_OUT_OF_SCOPE | 4 |

---

## Requirement gap matrix

| Requirement | Current Implementation | Status | Code Evidence | Test Evidence | Required Work |
|-------------|------------------------|--------|---------------|---------------|---------------|
| **F042-R001** Exchange feed detection | `DataQualityCorporateActionSyncService`, scheduled sync | NO_GAP | `SyncCorporateActionsCommand`, `config/services.php` | None | Add PHPUnit tests; document feed schema |
| **F042-R002** Heuristic gap detection | Overnight close/open scan, common ratios | NO_GAP | `DataQualityCorporateActionHeuristicService` | None | Add PHPUnit tests with fixture OHLCV |
| **F042-R003** Scheduled detection ordering | Daily 20:15 / 20:45 / 21:15 | DOCUMENTATION_MISSING | `routes/console.php` | None | Document sync→detect→auto-resolve ordering in ops runbook |
| **F042-R004** Duplicate pending prevention | Dedupe query; no evidence refresh | IMPLEMENTATION_PARTIAL | `DataQualityIssueService::createOrRefreshPendingIssueForStock` | None | Decide: rename or implement refresh; add dedupe tests |
| **F042-R005** Immutable detection fields | Resolution does not overwrite detection columns | NO_GAP | `DataQualityResolutionService` | None | Add regression test |
| **F042-R006** Evidence capture | `attachEvidence` on create | NO_GAP | `DataQualityIssueEvidence` model | None | Add unit tests |
| **F042-R007** Source traceability | method, source, payloads, timestamps | NO_GAP | Issue schema + services | None | Add unit tests |
| **F042-R008** Manual accept/reject | API + UI | NO_GAP | `DataQualityController`, React pages | None | Add feature tests + admin auth tests |
| **F042-R009** Resolution audit chain | Append-only resolutions with supersession | NO_GAP | `DataQualityIssueResolution` | None | Add unit tests for reversal chain |
| **F042-R010** Auto-accept stale pending | 15-day default, scheduled command | IMPLEMENTATION_PARTIAL | `autoAcceptStaleIssues`, `AutoResolveDataQualityIssuesCommand` | None | Governance review of 15-day policy; make threshold configurable; add tests |
| **F042-R011** Adjustment factor on accept | Creates/deactivates factors; **not read** elsewhere | IMPLEMENTATION_PARTIAL | `DataQualityAdjustmentFactorService` | None | Document handoff to F043; do not wire repair in F042 |
| **F042-R012** Legacy CA migration | Dry-run default migration service | TEST_MISSING | `DataQualityLegacyCorporateActionMigrationService` | None | Add migration tests |
| **F042-R013** Append-only resolutions | No delete of resolution rows | NO_GAP | Resolution service | None | Add test |
| **F042-R014** Admin-only access | admin middleware + AdminRoute | TEST_MISSING | `api.php`, `App.jsx` | None | Add 403 feature tests for non-admin |
| **F042-R015** Admin REST surface | 6 endpoints implemented | TEST_MISSING | `DataQualityController` | None | Add API feature test suite |
| **F042-R016** Admin review UI | Queue + history pages | NO_GAP | `DataQualityCenterPage.jsx`, `CorporateActionHistoryPage.jsx` | None | Optional E2E/manual checklist |
| **Pipeline guard** Block pending_review stocks | Guard integrated in 6 consumers | NO_GAP | `DataQualityGuardService` + engines | None | Add integration test via EvaluationEngine |
| **Formal F042 specification** | This document set | SPEC_MISSING → **addressed** | `docs/v2/F042-DATA-QUALITY-SPEC.md` | N/A | Product review + approval |
| **PHPUnit coverage for F042 subsystem** | Zero tests | TEST_MISSING | — | No `DataQuality*` tests | Full test plan per spec §20 |
| **OHLCV price repair on accept** | Not implemented | DEFERRED_TO_F043 | `CorporateActionPriceRepairService` (F043) | F043 unit tests exist | F043 initiative |
| **Consume adjustment factors in price reads** | Not implemented | DEFERRED_TO_F043 | No reads of `PriceAdjustmentFactor` outside DQ | — | F043 / price pipeline |
| **Before/after OHLCV audit on resolution** | Not implemented | DEFERRED_TO_F043 | — | — | F043 |
| **Non-CA issue types** | Not implemented | INTENTIONAL_OUT_OF_SCOPE | Only `TYPE_CORPORATE_ACTION` | — | Future enhancement |
| **Profile-scoped issues** | Not implemented | INTENTIONAL_OUT_OF_SCOPE | Stock-global issues | — | Future if multi-tenant |
| **User notifications for DQ issues** | Not implemented | INTENTIONAL_OUT_OF_SCOPE | — | — | Future |
| **Hard data publish gates (PB-001)** | Not implemented | INTENTIONAL_OUT_OF_SCOPE | MVP_SCOPE V1_OUT_OF_SCOPE | — | Separate backlog item |
| **API trigger for sync/scan** | CLI/cpanel only | IMPLEMENTATION_MISSING (minor) | No API routes | — | COULD — ops convenience |

---

## Acceptance criteria coverage

| AC ID | Requirement | Test exists? | Gap |
|-------|-------------|--------------|-----|
| F042-AC001 | Feed sync creates pending issues | No | TEST_MISSING |
| F042-AC002 | Heuristic flags gap stocks | No | TEST_MISSING |
| F042-AC003 | Duplicate pending prevention | No | TEST_MISSING |
| F042-AC004 | Detection fields immutable | No | TEST_MISSING |
| F042-AC005 | Evidence on create | No | TEST_MISSING |
| F042-AC006 | Admin accept/reject | No | TEST_MISSING |
| F042-AC007 | Resolution audit append | No | TEST_MISSING |
| F042-AC008 | Adjustment factor lifecycle | No | TEST_MISSING |
| F042-AC009 | Evaluation guard blocks stock | No | TEST_MISSING |
| F042-AC010 | Non-admin 403 | No | TEST_MISSING |
| F042-AC011 | Auto-accept stale | No | TEST_MISSING |
| F042-AC012 | Legacy migration dry-run | No | TEST_MISSING |

**Coverage:** 0 / 12 acceptance criteria have automated tests.

---

## Component-level inventory

| Component | Role | Tests |
|-----------|------|-------|
| `DataQualityIssue` | Model | None |
| `DataQualityIssueEvidence` | Model | None |
| `DataQualityIssueResolution` | Model | None |
| `PriceAdjustmentFactor` | Model | None |
| `DataQualityIssueService` | Issue create/dedupe/evidence | None |
| `DataQualityCorporateActionSyncService` | Feed detection | None |
| `DataQualityCorporateActionHeuristicService` | OHLCV heuristic | None |
| `DataQualityResolutionService` | Accept/reject/auto | None |
| `DataQualityAdjustmentFactorService` | Factor metadata | None |
| `DataQualityGuardService` | Pipeline block map | None |
| `DataQualityLegacyCorporateActionMigrationService` | F020→DQ audit | None |
| `DataQualityController` | Admin API | None |
| `SyncCorporateActionsCommand` | Scheduler | None |
| `DetectCorporateActionAnomaliesCommand` | Scheduler | None |
| `AutoResolveDataQualityIssuesCommand` | Scheduler | None |
| `MigrateLegacyCorporateActionsCommand` | One-off | None |
| `DataQualityCenterPage.jsx` | UI | None |
| `CorporateActionHistoryPage.jsx` | UI | None |
| `cpanel-data-quality-center.php` | Production ops | None |

---

## Implementation work priority (for future V2 implementation phase — not authorized here)

| Priority | Work item | Status driver |
|----------|-----------|---------------|
| P0 | PHPUnit suite covering R001–R016 and AC001–AC012 | TEST_MISSING |
| P0 | Product sign-off on auto-accept policy (R010) | IMPLEMENTATION_PARTIAL + risk |
| P1 | Clarify F042→F043 handoff for adjustment factors (R011) | IMPLEMENTATION_PARTIAL |
| P1 | Fix or document `createOrRefreshPendingIssueForStock` refresh behaviour (R004) | IMPLEMENTATION_PARTIAL |
| P2 | Ops runbook for detection schedule (R003) | DOCUMENTATION_MISSING |
| P3 | Optional API triggers for sync/scan | IMPLEMENTATION_MISSING (minor) |

---

*Related: [F042-DATA-QUALITY-SPEC.md](./F042-DATA-QUALITY-SPEC.md), [F042-F043-BOUNDARY.md](./F042-F043-BOUNDARY.md)*
