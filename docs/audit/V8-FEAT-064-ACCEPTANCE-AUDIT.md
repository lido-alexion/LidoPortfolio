# V8 FEAT-064 Acceptance Audit

Date: 2026-09-29
Status: **REVIEW — implementation guard merged; production provenance adoption remains required**

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

## Closure continuation — 2026-10-05 (read-only production provenance check)

**State: REVIEW — blocker found.** This check did not execute a Screener, generate a Recommendation, or change production data.

- Four active Strategies (IDs 3, 4, 5, 7) have an enabled Screener dependency whose active Strategy-version link has a null `screener_version_id`. Their active version's `eligibility_sources` also lacks a `screener_version_id`. Strategy 8 has an exact link to Screener version 7.
- Exact immutable Screener-version rows exist for the affected Screener lineages (4 and 5); the missing Strategy dependency pins are not explained by missing version records.
- `StrategyEligibilityService::resolve()` only applies its run-version filter when a positive pin exists. For the four unpinned active versions, a future recent completed run of the same Screener lineage could be selected without exact version matching. `resolveExitScreeners()` has the same conditional filter pattern. `StrategyReadinessService::assess()` checks the presence of enabled Screener IDs but does not require exact pins.
- All 11 existing production Screener runs have null `screener_version_id`; the newest finished on 2026-09-15. The 72-hour eligibility lookback excludes them now. This establishes a latent code-path risk, **not an observed incorrect Recommendation**. Of 786 Recommendations, 765 carry a Strategy-version ID; existing historical nulls need migration review rather than guessed backfills.

### Required production provenance work before closing FEAT-064

1. Add a regression that exercises an active legacy unpinned Strategy when a new Screener run exists, including the exit-rule path. Preserve the rule that new runs and newly saved Strategy versions pin exact immutable versions.
2. Reconcile the four active legacy Strategy versions through the documented provenance migration process. Do not silently edit immutable `config_json` or assign a historical version from current state without evidence; if exact historic identity cannot be proven, preserve uncertainty and require a new copy-on-write Strategy save to adopt the current Screener version.
3. Make runtime eligibility fail closed or report Setup Required for an unresolved pin, with a clear investor action. Verify activation/readiness and recommendation generation cannot silently treat an unpinned dependency as version-agnostic. Assess the operational impact before production rollout.
4. Re-run the exact-version regression and production read-only provenance report; then perform the controlled two-account workflow, membership-drift, and deployed accessibility checks already pending above. Only mark FEAT-064 IMPLEMENTED when the provenance gate passes.

The frozen requirement is exact Strategy-version-to-Screener-version reconstruction. Historical nulls must remain visible as unresolved evidence, not be cosmetically filled to clear a gate.


## Implementation follow-up — 2026-10-07 (after PR #72)

PR #72 is merged as `aa1ef576d49ed7270d5b1d7b9c5d18faf08c7f88`; its PHP 8.4 backend CI job passed. It adds fail-closed handling for missing/invalid exact Screener-version pins, prevents unresolved active Strategies from generating or cancelling recommendations, and requires an explicit copy-on-write save or binding revision to adopt current pins. Regression coverage includes fresh runs against unpinned legacy Strategies and exit rules.

The remaining issue is production data and rollout, not an unimplemented guard: the 2026-10-05 read-only report identified four active bindings (42, 43, 44, 47) without exact pins. Reconcile their current definitions and immutable versions, then adopt exact versions through the supported flow and verify recommendation behavior after deployment. Do not guess historical versions or silently rewrite immutable history. FEAT-064 remains REVIEW until this provenance gate is safely resolved.
