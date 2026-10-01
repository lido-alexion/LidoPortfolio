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
| Strategy versions pin exact Screener versions | PASS locally | `StrategyProvenanceTest`, `StrategyProvenanceResolutionTest`, `StrategyProvenanceRegressionTest::test_strategy_execution_uses_exact_pinned_screener_run_version`, and `Feat064MandatoryAuditAcceptanceTest`; production eligibility now filters runs by the persisted `StrategyScreener.screener_version_id`. |
| Strategy activation preserves immutable dependency/config semantics | PASS locally | `StrategyProvenanceRegressionTest::test_activation_does_not_mutate_existing_strategy_version_or_repin_dependencies`; activation no longer resolves portable refs, rewrites `config_json`/`definition_hash`, deletes links, or re-syncs dependencies. |
| Incomplete Strategies may persist as Setup Required | PASS locally | `StrategyReadinessTest` and browser assertion in `screener-investor-workflow.spec.js`. |
| Setup Required Strategies cannot activate or execute | PASS locally | `StrategyReadinessTest`; browser verifies Enable is disabled. |
| Complete Strategies can activate; multiple enabled Strategies remain supported | PASS locally | `StrategyReadinessTest`, `MultiStrategyLifecycleAssuranceTest`; `StrategyProvenanceRegressionTest::test_last_active_strategy_can_be_archived` proves the obsolete minimum-one restriction is removed. |
| Contextual Screener creation preserves Strategy state and selects the result | PASS locally | `strategyScreenerReturnFlow.test.mjs`, WP-09 Strategy/Screener editor hooks. |
| Historical recommendation/transaction provenance remains resolvable | PASS locally | `Feat064MandatoryAuditAcceptanceTest`, `StrategyProvenanceRegressionTest`, `StrategyProvenanceMigrationReportTest`. |
| Responsive investor workflow and browser acceptance | PASS locally / broader acceptance pending | Focused Chromium Screener workflow passes 2/2; full configured Playwright run passes all 31 executed journeys across 52 configured tests. Broader deployed/device validation remains external. |
| Automated accessibility scan | PASS locally / assistive technology pending | `v8-accessibility.spec.js` runs full axe WCAG 2A/2AA scans against the Screener editor; the scan passes after explicit labels and shared dark-theme contrast fixes. Native screen-reader and broader device acceptance remain external. |

## Verification executed

- Full Laravel Feature suite: **1,346 passed / 1,347 total / 1 skipped**, 11,014 assertions under the matching x86_64 ML runtime.
- FEAT-064 backend/provenance/readiness suites pass within that run.
- Focused Screener/Strategy Chromium journey: **2/2 passed** with deterministic API fixtures.
- Full configured Playwright run: **31 executed passed**, with 21 intentional viewport/configuration skips across 52 configured tests.
- Focused full WCAG 2A/2AA axe accessibility journeys: **5/5 passed** for guided-tour dialogs, fundamentals, investor insights, Screener editor, and Request an account.
- Node/Vitest/build/typecheck/docs and `git diff --check` pass.
- Correction regression slice: **24/24 Laravel tests passed**, 120 assertions, including exact pinned runtime selection, activation immutability, last-active archive, checkpoint validation and Admin pause/resume authorization.

## External validation pending

1. Live membership-drift/runtime behavior against deployed universe providers.
2. Broader deployed-device and assistive-technology acceptance beyond the local Chromium matrix.

No readiness gate was weakened: persistence as `Setup Required` remains distinct from activation/execution eligibility.


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Deployed private Screener, definition-copy sharing, multiple Strategies and immutable pins | NOT YET RUN | Controlled two-account browser path and provenance check required. |
| Real membership change, historical transaction/recommendation provenance and responsive/screen-reader | NOT YET RUN | Coordinate membership drift after NSE apply, with explicit before/after IDs and no production portfolio disruption. |

### Authenticated workflow inspection — 2026-10-01 about 19:05 UTC

Cloud Chrome showed seven existing rows under `My screens`, a separate `Shared screens` tab (empty for this portfolio), and a Strategies selector with multiple concurrent enabled strategies (Ts2, Test swing, Swing SmallCap, Momentum Strategy Copy). The selected published Artifact Library binding displayed a read-only notice and disabled Save. **PASS for UI presence and multiple-enabled display only**; no cross-account copy, new version, membership drift or historical provenance mutation was tested.
