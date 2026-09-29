# V8 FEAT-064 Acceptance Audit

Date: 2026-09-29
Status: **REVIEW — implementation and local browser evidence complete; live membership-drift/runtime validation pending**

Authoritative contract: `docs/archive/specs/V8-Core-Investor-Workflow-UX-Simplification.md`.

## Requirement matrix

| Frozen requirement | Status | Evidence |
|---|---|---|
| Ordinary Screener CRUD stays on Screener pages | PASS locally | `ScreenerProvenanceTest`, `ScreenerBacktestVersionTest`, registry/editor routes and `tests/e2e/screener-investor-workflow.spec.js`. |
| Ordinary Strategy CRUD stays on Strategy pages | PASS locally | `StrategyImmutableSaveTest`, `StrategyReadinessTest`, Strategy editor/registry routes and frontend strategy workflow tests. |
| Artifact Library is not required for ordinary CRUD | PASS locally | `artifact-library-ui.test.mjs` and direct Screener/Strategy route coverage; no normal-flow redirect is required. |
| No user-entered SemVer or ordinary persisted Draft | PASS locally | editor UI/static tests and immutable-save API tests. |
| Dirty unsaved edits receive normal navigation protection | PASS locally | editor implementation and frontend workflow coverage. |
| Screener instances are private/account-scoped | PASS locally | `ScreenerProvenanceTest`, shared-definition copy tests and authorization coverage. |
| Sharing copies a definition, not a mutable instance | PASS locally | `ScreenerDefinitionCopySharingTest` and `artifact-library-ui.test.mjs`. |
| Screener semantic versions are immutable | PASS locally | `ScreenerProvenanceTest`, `ScreenerBacktestVersionTest`. |
| Runs and backtests pin exact Screener versions | PASS locally | `ScreenerBacktestVersionTest`, `Feat064MandatoryAuditAcceptanceTest`. |
| Strategy versions are immutable copy-on-write records | PASS locally | `StrategyImmutableSaveTest`, `StrategyProvenanceResolutionTest`. |
| Strategy versions pin exact Screener versions | PASS locally | `StrategyProvenanceTest`, `StrategyProvenanceResolutionTest`, `Feat064MandatoryAuditAcceptanceTest`. |
| Incomplete Strategies may persist as Setup Required | PASS locally | `StrategyReadinessTest` and browser assertion in `screener-investor-workflow.spec.js`. |
| Setup Required Strategies cannot activate or execute | PASS locally | `StrategyReadinessTest`; browser verifies Enable is disabled. |
| Complete Strategies can activate; multiple enabled Strategies remain supported | PASS locally | `StrategyReadinessTest`, `MultiStrategyLifecycleAssuranceTest`. |
| Contextual Screener creation preserves Strategy state and selects the result | PASS locally | `strategyScreenerReturnFlow.test.mjs`, WP-09 Strategy/Screener editor hooks. |
| Historical recommendation/transaction provenance remains resolvable | PASS locally | `Feat064MandatoryAuditAcceptanceTest`, `StrategyProvenanceRegressionTest`, `StrategyProvenanceMigrationReportTest`. |
| Responsive investor workflow and browser acceptance | PASS locally / broader acceptance pending | Focused Chromium Screener workflow passes 2/2; full configured Playwright run passes all 25 executed journeys. Broader deployed/device validation remains external. |
| Automated accessibility scan | PASS locally / assistive technology pending | `v8-accessibility.spec.js` runs axe WCAG 2A/2AA critical-violation scans against the Screener editor; the scan passes after explicit labels were added to editor controls. Native screen-reader and broader device acceptance remain external. |

## Verification executed

- Full Laravel Feature suite: **1,345 passed / 1,347 total / 2 skipped**, 9,565 assertions under the matching x86_64 ML runtime.
- FEAT-064 backend/provenance/readiness suites pass within that run.
- Focused Screener/Strategy Chromium journey: **2/2 passed** with deterministic API fixtures.
- Full configured Playwright run: **25 executed passed**, with 21 intentional viewport/configuration skips.
- Focused axe accessibility journeys: **3/3 passed** for guided-tour dialogs, fundamentals, and the Screener editor.
- Node/Vitest/build/typecheck/docs and `git diff --check` pass.

## External validation pending

1. Live membership-drift/runtime behavior against deployed universe providers.
2. Broader deployed-device and assistive-technology acceptance beyond the local Chromium matrix.

No readiness gate was weakened: persistence as `Setup Required` remains distinct from activation/execution eligibility.
