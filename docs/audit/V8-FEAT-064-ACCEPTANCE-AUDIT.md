# V8 FEAT-064 Acceptance Audit

Audit opened: 2026-09-29
Current status: **IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN** (updated 2026-10-10; prior entries below are chronological evidence)

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

1. **Completed in PR #72:** regression coverage exercises an active legacy unpinned Strategy when a new Screener run exists, including exit rules; new runs and saved Strategy versions preserve exact immutable pins.
2. **Still required:** reconcile the four active legacy Strategy bindings through the documented provenance process. Do not silently edit immutable `config_json` or assign a historical version from current state without evidence. If exact historic identity cannot be proven, preserve that uncertainty and use a new copy-on-write Strategy save to adopt the current Screener version.
3. **Completed in PR #72:** runtime eligibility fails closed for unresolved pins, prevents recommendation generation/cancellation for those active Strategies, and readiness prevents activation; regression tests cover these boundaries.
4. After the build is stable and the four bindings are safely reconciled, rerun the production read-only provenance report and perform the controlled two-account workflow, membership-drift, and deployed accessibility checks. Keep FEAT-064 in REVIEW until the production provenance gate is safely resolved.

The frozen requirement is exact Strategy-version-to-Screener-version reconstruction. Historical nulls must remain visible as unresolved evidence, not be cosmetically filled to clear a gate.


## Implementation follow-up — 2026-10-07 (after PR #72)

PR #72 is merged as `aa1ef576d49ed7270d5b1d7b9c5d18faf08c7f88`; its PHP 8.4 backend CI job passed. It adds fail-closed handling for missing/invalid exact Screener-version pins, prevents unresolved active Strategies from generating or cancelling recommendations, and requires an explicit copy-on-write save or binding revision to adopt current pins. Regression coverage includes fresh runs against unpinned legacy Strategies and exit rules.

The remaining issue is production data and rollout, not an unimplemented guard: the 2026-10-05 read-only report identified four active bindings (42, 43, 44, 47) without exact pins. Reconcile their current definitions and immutable versions, then adopt exact versions through the supported flow and verify recommendation behavior after deployment. Do not guess historical versions or silently rewrite immutable history. FEAT-064 remains REVIEW until this provenance gate is safely resolved.


## Closure continuation — 2026-10-10 (production build 493)

**Current disposition: IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN.** Implementation, production immutable-snapshot repair, explicit binding adoption, readiness and deployed exact-pin runtime verification are complete. Broader two-account, membership-drift, recommendation-resume and assistive-technology acceptance remain open.

- Build: build-493-attempt-1-b06f3fa7001f2848867de53dba60a92c15776f75, commit b06f3fa7001f2848867de53dba60a92c15776f75; production /var/www/stoxla/current and /api/build-info agreed. No pending migrations; deploy service/timer inactive at verification.
- PR #122 merged as 2a524fb08bc518d90d3e1ab88463df539bd1c173; PR #123 merged as b06f3fa7001f2848867de53dba60a92c15776f75. PR #123 PHP 8.4 backend CI passed: 2,319 tests, 15,888 assertions, 2 skipped, 5 PHPUnit notices. Deployment run 493 passed packaging, deploy and post-deploy health verification.
- Snapshot repair command initially exposed an Artisan-reserved --version option during dry-run only; it performed no write. PR #123 changed the supported argument to --target-version; both subsequent dry runs passed before guarded writes.
- Screener 4: v1 row 4 retained; repaired v2 row 10 hash sha256:d3edf5394abd4066e1e894e73abbbe55057c69e0f0ee3915cf116028ffc9bae4, proven by exact immutable v3 row 9.
- Screener 5: v1 row 5 retained; repaired v2 row 11 semantic hash sha256:092a4e0ad9d00d5796f0e97de6d4760e2e7db6c59e30c8ab42cf35a542271e02, proven by same-lineage published artifact version 39. The prior sha256:59d3e174bc606b42e291ff06c24301de334356d5175a9a74a5ab7b59245d6cdb was the artifact-envelope hash, not the semantic hash. The current Screener 5 hash field was normalized to the semantic hash without incrementing its version.
- Supported ArtifactBindingService upgrade created active revisions 57–60 for bindings 42, 43, 44 and 47. Active Strategy versions 34–37 have exact pins: Strategy 3 → Screener 4 v3 row 9; Strategy 4 → Screener 5 v2 row 11; Strategy 5 → Screener 4 v3 row 9; Strategy 7 → Screener 4 v3 row 9. All bindings remain enabled. Prior active Strategy config and definition hashes were unchanged when superseded.
- Deployed StrategyReadinessService assess returned ready=true and no requirements for all four active versions; unresolvedPinnedScreeners returned empty for each.
- Production runtime was verified through ScreenerRunService::runToCompletion with the manual trigger for Screeners 4 and 5; Telegram is disabled for both. Run 15 (Screener 4) completed at 2026-10-10 09:07:13 UTC, pinned to Screener version row 9, artifact version 38 and artifact binding revision 38: 5,123 scanned, 587 matched, 642 skipped for insufficient data, zero errors or warnings. Run 16 (Screener 5) completed at 2026-10-10 09:08:33 UTC, pinned to Screener version row 11, artifact version 39 and artifact binding revision 39: 5,123 scanned, 251 matched, 366 skipped for insufficient data, zero errors or warnings. Both report telegram_sent=false.
- StrategyEligibilityService::resolve was then checked against each live active Strategy version: Strategy 3/version 34, Strategy 5/version 36 and Strategy 7/version 37 returned screener_union/PASS on Screener 4 v3 row 9 via run 15 (587 hits); Strategy 4/version 35 returned screener_union/PASS on Screener 5 v2 row 11 via run 16 (251 hits). This proves deployed resolution selected only runs matching persisted exact pins. No Recommendations or trades were generated.
- Before these manual runtime runs, totals were 11 Screener runs, 1 backtest, 786 recommendations and 78 transactions. Afterward they were 13 runs, 1 backtest, 786 recommendations and 78 transactions. The only change to run count was the two acceptance runs; recommendations, transactions and historical immutable provenance were unchanged.

### Broader functional acceptance still open

| Check | State |
|---|---|
| Two-account private instance and definition-copy workflow | Open; no controlled two-account session recorded. |
| Controlled deployed universe membership change and resulting eligibility behavior | Open; no membership mutation performed. |
| Recommendation generation resumes for eligible resolved Strategies; unrelated Strategies remain unaffected | Open; no recommendation pipeline invoked as part of provenance proof. |
| Deployed keyboard and native screen-reader/device acceptance | Open; local Chromium/axe evidence exists, no deployed assistive-technology session recorded. |

These broader checks do not block the IMPLEMENTED disposition; retain FUNCTIONAL ACCEPTANCE OPEN until evidence is added.
