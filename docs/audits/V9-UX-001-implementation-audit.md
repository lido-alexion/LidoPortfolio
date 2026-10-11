# V9-UX-001 Implementation Audit

**Status:** IN PROGRESS — hosted verification, full journey conformance, authenticated production smoke, current ML qualification and real-Kite evidence remain open. The canonical repository register is available and must stay open until those gates pass.
**Audit date:** 2026-10-11
**Canonical scope:** [V9-UX-001 specification](../archive/specs/V9-User-Journey-Automation-E2E-Specification.md)
**Journey sources:** [`docs/user-journeys/`](../user-journeys/)

## Initial foundation slice

The first implementation group covers account entry: a signed-out investor opening a protected page, successful sign-in returning to that page, and recoverable rejected credentials. The source corpus now generates 71 journey topics, including the two new stable IDs `AUTH-01` and `AUTH-02`.

The login form now associates its labels with the email/password fields and announces rejected-credential feedback as an alert. Playwright coverage exercises sign-in and retry at 390×844 mobile and 1440×900 desktop viewports, with an axe WCAG 2A/2AA check on the login card. Journey annotations are checked against authoritative Markdown headings.

SCR-09 now stores a per-security outcome snapshot for each screener run and exposes paginated diagnostics through the run detail API. The screener page shows predicate failures, unavailable operands, and skip/processing reasons from the saved run snapshot, with an explicit note that AND/OR evaluation may short-circuit. Backend coverage verifies skipped-input diagnostics and false predicate evidence; desktop/mobile Playwright coverage verifies the run-detail experience. This closes the identified SCR-09 diagnosis gap for new runs, pending hosted E2E and full journey conformance review.

Frontend CI is configured to run the canonical frontend verifier on pull requests when frontend, E2E, or journey-source files change. Failed runs upload Playwright evidence for 14 days. The scheduled nightly workflow runs the full canonical journey suite and now retains failure evidence for 14 days; operational hosted run evidence remains pending.

## Screener foundation slice

SCR-01 now has browser coverage at 390×844 and 1440×900. The scenario creates a screener named “Price Above MA200,” selects Close > SMA(200), saves it, and asserts the exact definition sent to the validated create endpoint. The screener test mock now persists created definitions so the detail route reflects the saved rule. SCR-02 now covers the documented AND combination of Close > SMA(200) and RSI(14) < 70, with assertions on the persisted condition tree at both viewports. SCR-03 also verifies nested AND/OR grouping for a trend condition with RSI and ROC alternatives. Hosted CI exposed that the documented em dash was rejected by both name validators; the UI and backend now accept en/em dashes, with API coverage for the documented SCR-02 name. SCR-04 now covers selecting an existing definition, changing its operand, and saving the revised rule. The source corpus remains at 71 topics; SCR-05 checks that an out-of-range indicator is rejected, communicated, and recoverable after correction. SCR-06 exercises a completed deterministic run, checks scan/match/insufficient-data counts, inspects the returned TCS predicate evidence, and confirms the flow makes no order request. SCR-07 imports a shared screener and verifies the resulting private copy in the active-portfolio editor preserves its definition. All 71 source IDs now carry validated stable annotations, including composite E2E-03/04/06/07 and the E2E-08 preflight-blocked scenario. Coverage also includes AUTH-01/02, all SCR-01–09, STR-01–16, REC-01–12, AI-01–09, and EXE-01–15. E2E-08 still lacks its real production acceptance workflow. REC-10 and REC-11 exercise partial and zero funding resolutions, including the requested, actually available, and unresolved amounts, at mobile and desktop sizes. REC-12 marks superseded intent as non-current and opens its replacement, where target, quantity, strategy version, and reasoning can be compared. REC-07 verifies a funded recommendation moves to pending_execution with its reservation while submitting no order.

## Completion criteria snapshot

| Requirement | Current evidence | Status |
|---|---|---|
| Journey inventory and stable IDs | 71 source topics generated; annotation validator rejects missing/invalid source IDs. | Partial — discovered-workflow review remains open. |
| Traceability from automation to source | All 71 documented source IDs have stable annotations validated against authoritative journey headings. | Mapped; end-to-end evidence depth and conformance remain partial. |
| Auth/account-entry prerequisite | AUTH-01/AUTH-02 mobile/desktop scenarios, four visual baselines, and login-card axe checks; hosted PR CI passed. | Verified for this slice. |
| Screener, strategy, recommendation, execution, and end-to-end journeys | All 71 IDs are annotated; composite E2E-01/02/03/04/06/07 browser journeys and SCR/STR/REC/EXE scenario suites are present. SCR-08 archive preservation is merged in PR #128. STR-09 REDUCE-under-entry-gate pipeline coverage is in PR #129. Composite coverage uses deterministic mocks and does not prove live domain persistence or broker execution. | In progress — hosted gates and conformance evidence remain. |
| Deterministic data, isolation, and broker simulation | Existing harness includes a seed hook and broker-related mocks in selected tests. | Open — audit per scenario and close missing lifecycle/recovery cases. |
| Chromium desktop and representative mobile | Playwright Chromium and mobile projects exist; auth slice covers both target viewports. | Partial — all major journeys must meet the viewport standard. |
| Blocking CI and nightly regression | PR frontend verifier runs the canonical suite; scheduled nightly runs journeys and uploads failure evidence for 14 days. | In progress — validate hosted runs and complete the unmapped journey inventory. |
| Visual regression | PR #127 contains 24 Chromium baselines: the original 20 plus four hosted pending-execution row baselines for E2E-01/02 at mobile and desktop. | Pending — four hosted pending-row baselines are added. Run #958 confirmed those captures and identified one existing desktop screener-list baseline that predates the Archive action merged in PR #128; that baseline is now refreshed from the hosted screenshot and needs a clean rerun. |
| Accessibility regression | Existing checks cover selected surfaces; auth slice adds a login-card axe check. | Partial — cover other key journey pages/states. |
| Production smoke | The prior post-deploy workflow passed the public shell and JavaScript asset smoke. The authenticated read-only case was skipped because `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD` are not configured. The PR #128 post-merge deployment workflow is still gating on backend verification. | Pending deployment completion and dedicated read-only smoke credentials. |
| Journey conformance | PO accepted the documented STR-07/STR-08 behavior differences on 2026-10-10. | Open — complete the full comparison and resolve remaining non-PO deviations. |
| Failure evidence and safety | PR workflow uploads Playwright results on failure; normal CI uses deterministic mocks in selected suites. | Partial — confirm artifacts in hosted CI and audit all order paths for non-destructive behavior. |

## Verification for this slice

- `npm run test:js:unit`: **PASS**, 215 tests.
- `npm run docs:static:check`: **PASS**, 53 static documentation topics.
- `npm run journeys:metadata`: **PASS**, 71 journey topics generated.
- Targeted screener backend tests: **PASS**, 23 tests and 248 assertions.
- SCR-09 Playwright discovery: **PASS**, 18 desktop/mobile tests listed; browser execution remains with hosted CI.
- `git diff --check`: **PASS**.
- `git diff --check`: **PASS** after the SCR-01 additions.
- `node --check` on the modified E2E files and `git diff --check`: **PASS** for REC-10/11.
- `node --check` and `git diff --check`: **PASS** for REC-12; hosted browser CI remains the verification gate.
- Playwright browser tests, including the new SCR-01 scenarios, are delegated to hosted PR CI; repository guidance prohibits running the heavyweight browser install/suite on the production VPS.

This audit does not claim full journey conformance, completion of the 71-scenario corpus, production smoke readiness, or V9-UX-001 implementation. Keep the register status unchanged until all frozen completion criteria pass.


## Conformance findings

- SCR-08 is covered only for an unused reusable artifact with no active portfolio binding. The classic runtime screener delete route hard-deletes the screener; database foreign keys cascade to run/history evidence. That path cannot satisfy the journey's preservation requirement.
- SCR-09 diagnosis is implemented for new runs through persisted per-security outcome snapshots and a desktop/mobile journey. Existing historical runs have no backfilled diagnostics; they retain their original hits and aggregate counts. Hosted E2E and complete journey conformance review remain pending.

- STR-13 exercises enabling a draft strategy while a sibling remains active; STR-14 exercises archiving one active strategy while retaining an active sibling. STR-15 verifies concurrent registry identities/versions/allocations, and STR-16 verifies the same-stock recommendation list preserves separate strategy names and informational HOLD treatment. REC-01 through REC-12 now cover pipeline invocation and strategy-specific output, review/rejection with notes, explanation evidence, action labels, informational HOLD/WATCH, diagnostic preview, defer/reopen behavior, partial/unfunded capital resolution, supersession/replacement review, and funded approval-to-pending-execution behavior using deterministic UI mocks. STR-15 pipeline evidence and STR-16 strategy-owned execution remain unverified in the browser. Existing MultiStrategyLifecycleAssuranceTest covers same-stock execution ownership and independent quantities at the backend level.
- STR-07 coverage verifies the sizing fields exposed by Strategy and the allocation method. Strategy target allocation % remains on `/cash`; minimum actionable amount and whole-share behavior are not exposed in the Strategy editor, and BUY cooldown is fixed at one calendar day.
- STR-08 coverage verifies the strategy ATR stop control. The classic portfolio stop-loss and trailing-stop settings remain under Settings, so they are not part of this Strategy editor journey. STR-09 pipeline coverage for blocked entry and eligible REDUCE is in PR #129; existing MarketGateRecommendationTest cases cover OPEN/INCREASE demotion, EXIT eligibility, and normal entry when gates pass. Await the hosted PHPUnit result before closing this finding.


## Progress update — 2026-10-09

The current feature branch is based on latest `master` and remains open as draft PR #118. The branch adds the EXE-01/02/03/04/05/08/09/13 manual, pending, cancellation, and reconciliation scenarios; unknown broker state never triggers retry or order submission in the deterministic test. The Review orders table exposes broker status, broker error, and filled quantity; it offers reconciliation for in-flight orders and withholds manual ledger entry for submitted/open/partial/unknown/rejected/cancelled orders. The pending queue reads prior orders by recommendation, fails closed when history cannot load, and blocks retries when any attempt remains in flight or has a fill. E2E-05 now drives mocked submit → cancel request → broker-confirmed zero-fill reconciliation → same-recommendation retry across mobile and desktop.

The post-deploy workflow now has a browser smoke gate after existing runtime, deployed-SHA, and JavaScript-asset checks. The public shell smoke is non-destructive; the authenticated read-only check requires dedicated `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD` GitHub secrets. The workflow retains browser evidence for 14 days.

Historical inventory before the 2026-10-10 composite journey slice: 71 documented IDs; 64 annotated; seven unmapped. This count was superseded by the annotation and browser work recorded below.

Closure remains blocked by unmapped verification/composite journeys, incomplete visual regression and full conformance review, hosted gate results, production smoke execution, and required PO approval for behavior-changing resolution of the documented STR-07/STR-08 differences. The epic and register must remain open.


## Progress update — 2026-10-10

E2E-08 now has deterministic Chromium scenario annotations at 390×844 and 1440×900. It uses `/settings/ml-scoring` and the existing **Production ML acceptance** panel. The mocked acceptance API returns unknown production evidence, then an unresolved dated-source preflight; the browser asserts the blocking evidence is visible and the explicit training action is unavailable. It does not upload official NSE sources, run a real backfill/training campaign, or assert production qualification. Those outcomes require the protected acceptance service and deployed evidence; the local scenario deliberately does not represent local fixtures as production-qualified.

E2E-01 and E2E-02 composite browser scenarios now cover the complete deterministic user path at 390×844 and 1440×900. E2E-01 creates and reads back screener #99/version 1 through `/screeners/new`; E2E-02 selects existing screener #41/version 3. Both configure strategy #8 with that enabled eligibility source, enable it through the visible **Enable** lifecycle action, invoke `/recommendations` pipeline through its visible action, and assert the pipeline result carries screener ID/version and strategy #8/version ID 81. The recommendation review detail now displays both source screener/version and strategy/version provenance. Approval is asserted to make no order, execution-submit, or broker request. Each journey then enters the approved recommendation’s pending queue, chooses **Execute manually**, and saves a deterministic 10-share BUY at 3500 using the standard transaction form. The mocked transaction and holdings responses attribute the fill to strategy **Momentum Core** and the recommendation ID; the mock cash balance decreases from 100,000 to 65,000 and the cash page asserts the updated value. A request-origin guard aborts all requests outside the local E2E app; unknown transaction, cash, and holding behavior is mocked/fails closed.

**Remaining evidence limits:** the browser scenarios use deterministic intercepted responses, so they prove UI stage ordering, request payload linkage, displayed API evidence, and attribution as returned by the mock, not persistence or accounting by the live decision/transaction domain. E2E-01’s mock assigns version 1 to the newly created screener response; it does not model an independent server-side version-history endpoint. The scenarios are not marked browser-verified until hosted Chromium CI runs them. No real broker or production endpoint can be reached by these scenarios because of the origin guard.

Verification for this E2E-01/02 slice: `node --check tests/e2e/composite-journeys.spec.js` and `node --check tests/e2e/investorWorkflowApiMocks.js` **PASS**; journey metadata and automation contract checks **PASS** (2 tests); `npm run docs:static:check` **PASS** (53 topics); `git diff --check` **PASS**. A direct `node --check` attempt on the modified `.jsx` file is unsupported by Node’s file-extension loader, and the checkout has no local esbuild binary for an independent JSX parse. Playwright/browser tests were not launched on the VPS; hosted Chromium CI remains pending.

E2E-03 and E2E-07 require strategy-owned SELL attribution and closure while preserving same-security holdings owned by another strategy; current evidence includes backend lifecycle assurance and STR-16 recommendation display but no browser transaction/holding attribution journey. E2E-04 requires a single journey proving that a strategy save alone does not mutate intent, a separately invoked pipeline supersedes old intent, and old reservations/readiness are reconciled; STR-10 and REC-12 cover pieces separately. E2E-06 requires a single capital-shortfall-to-funded execution journey retaining desired target versus actual amount and cash/holding evidence; REC-10/11 cover resolution states and REC-07 covers funded approval, but do not assert the complete execution/accounting chain.

Verification for this slice: `node --check tests/e2e/composite-journeys.spec.js` passed; `node --test tests/js/journeyMetadata.test.mjs tests/js/journeyAutomationContract.test.mjs` passed (2 tests); `npm run docs:static:check` passed (53 topics); `git diff --check` passed. Playwright `--list` did not return within 15 seconds and was interrupted before browser launch; hosted browser execution remains the gate per the VPS restriction. The checkout cannot fetch `origin`: Git attempted to update the shared worktree `FETCH_HEAD` under a read-only parent Git directory. Local `origin/master` is 47 commits behind this branch and may be stale. No rebase was attempted.


## Progress update — 2026-10-10 (post-merge and visual regression)

PR #118 merged to `master` as `e360204daaf5c8d0c008197c8a0dbac790bafa66` after PR CI run #943 passed the frontend build/test suite, PHPUnit, and Python AI/FastMCP contracts. The merged fixes cover the previously failing transaction-entry browser fixtures, strategy-owned exit/accounting assertions, cash read fixtures, and dark-theme danger-button contrast. The 18 affected composite/execution/accessibility Playwright cases passed locally across mobile and desktop. The post-merge master CI and production deployment workflows were still running at this audit update; deployment and post-deploy smoke are not yet claimed as passed.

The follow-up branch adds targeted visual regression snapshots for the login card and rejected-credential state at 390×844 and 1440×900. All four screenshot assertions passed during baseline creation and again in comparison mode. The branch also passed `npm run test:js:unit` (215), `npm run docs:static:check` (53 topics), and `npm run typecheck`. These snapshots provide initial visual coverage only; key screener, strategy, recommendation, execution, and composite pages still need baselines.

The E2E-03/07 strategy-owned SELL, E2E-04 intent supersession, and E2E-06 capital-shortfall-to-BUY paths now have deterministic mobile and desktop browser coverage. These mocked journeys validate visible stage ordering, payload attribution, and mock-returned holdings/cash evidence; they do not establish live domain persistence or broker execution.

**PO decisions recorded (2026-10-10):** the PO accepts target allocation under `/cash`, the fixed one-calendar-day BUY cooldown, and keeping minimum actionable amount/whole-share behavior implicit. The PO also accepts ATR stop configuration on Strategy while classic portfolio stop-loss and trailing-stop settings remain under Settings. No behavior-changing conformance work is authorized for these differences.

**Remaining external evidence and access:** completing E2E-08 requires the protected production ML acceptance service and official dated NSE evidence, plus an authorized real acceptance/backfill run. The authenticated post-deploy smoke requires dedicated read-only `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD` secrets. Full journey conformance, remaining key-page visual/accessibility coverage, and production smoke evidence are still required before marking V9-UX-001 implemented.


## Latest status — 2026-10-10

The prior progress notes above are historical and are superseded where they describe PRs as open, browser CI as pending, or composite journey work as incomplete.

- PR #118 is merged to `master` as `e360204daaf5c8d0c008197c8a0dbac790bafa66`. Its post-merge CI passed, and deploy run #496 passed release verification, VPS activation, post-deploy health check, public Chromium smoke, and artifact upload. The public shell smoke passed (1 test). The authenticated read-only investor smoke was skipped because GitHub secrets `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD` are empty.
- PR #126 is merged as `f6c6e683adaf74ff7d249b62376061de26068d01`. Its post-merge Python contract and frontend verification jobs passed; its deploy jobs skipped because it changed only docs and E2E snapshots. The PR added four login visual baselines.
- The merged PR #118 adds E2E-03/07 strategy-owned SELL attribution and closure, E2E-04 strategy-save/pipeline supersession, and E2E-06 capital-shortfall-to-funded BUY evidence at desktop and mobile sizes. These remain deterministic mocked UI evidence and do not assert live-domain persistence or broker execution.
- The follow-up branch adds 20 visual baselines across E2E-01/02 screener rule/list, strategy editor, recommendation review, and actual-fill form screens for E2E-01/02/03/07. The composite screenshots use fixed transaction dates, hide transient toast and mobile utility chrome, and capture recommendation dialogs against an opaque backdrop so background timestamps do not vary. All eight composite browser cases pass baseline creation and a CI-mode repeat comparison locally. Hosted run #949 exposed these dynamic screenshot differences; the follow-up fix is ready for a new hosted CI run. Auth plus composite coverage totals 24 screenshot baselines.
- The PO decisions for STR-07 and STR-08 are recorded above and accepted. No behavior change is outstanding for those agreed differences.

**External completion gates:** provision a dedicated read-only production investor identity and configure the two smoke secrets; provide access to the protected ML acceptance service and official dated NSE source evidence, and authorize the real acceptance/backfill run required by E2E-08. Until those gates pass, production smoke and production ML qualification cannot be evidenced. The epic remains **IN PROGRESS**, not implemented. Further full conformance review and broader visual/accessibility coverage also remain open; in particular, SCR-08 history-preserving delete behavior and STR-09 blocked-market pipeline behavior require resolution or explicit PO acceptance before closure.


## Progress update — 2026-10-11 (archive, gate, and visual evidence)

PR #128 merged to `master` as `5324fe30ae1ec1b11d3274c06fce716b847f9ea1`. Its hosted PR checks passed (PHPUnit, frontend, and Python AI/FastMCP). The merge preserves screener run/history evidence on archive and updates the legacy authorization tests to assert archived-source behavior. Post-merge master CI and the production deployment workflow are still running their backend verification; deployment and post-deploy smoke are not yet claimed as passed.

PR #129 adds the missing Strategy pipeline assertion that an entry-blocking market gate does not demote a legitimate REDUCE. The branch has been reconciled with PR #128 and pushed as `f76f5339`; its original hosted PHPUnit run is still active and the updated head requires its own CI run.

PR #127 adds 20 key journey baselines plus four pending-execution-row baselines captured from hosted Chromium failure evidence. The first run of the new assertions failed solely because those four baselines were absent; actual and expected row captures were identical in the artifact. The images are now included in the branch, whose local head also includes the PR #128 merge. Run #958 passed the newly added pending-row snapshots, then exposed the intentional Archive action in the E2E-02 desktop screener-list baseline; that baseline is now refreshed from the hosted capture. A clean hosted rerun is required before merge. No review submissions or unresolved inline review threads were present on PRs #127 or #129 at this check.

External closure requirements remain: the protected production ML acceptance service and official dated NSE source evidence, followed by an authorized production acceptance/backfill run for E2E-08; a dedicated read-only production smoke identity stored as GitHub Actions secrets `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD`; and access to the epic tracker so the final “Implemented” state can be recorded. Do not mark the epic implemented while these required evidence and tracker steps remain outstanding.


PR #127 follow-up: run #958 confirmed all four new pending-execution screenshots and failed on one older E2E-02 desktop screener-list baseline because merged PR #128 added the Archive action. The baseline has been replaced with the exact hosted capture containing that action; this is a functional visual update, not a tolerance adjustment.


## Acceptance recheck — 2026-10-11

This section supersedes historical pending hosted-gate and visual-coverage statements above. It does **not** assert epic completion or full per-journey conformance.

### Fresh hosted and production evidence

- Current master and production SHA: `9e979028fa3af60b854807595ebf49577d4c236f` (PR #129); PR #127 merged as `3b688d130ecfe5c1a42d82244bfa7ba93ebd447c`.
- [Master CI 38121095435](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38121095435): success on 2026-10-11 at 07:52:09 UTC. Canonical MySQL backend and Python contracts passed. Frontend was correctly not selected for this backend-only change; this run is not a fresh full-browser result.
- [Production deployment 38121095427](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38121095427): success at 07:46:06 UTC. Runtime gate verified build identity, public root/module, queue coverage, and scheduler heartbeat. A fresh public `/api/build-info` read confirms the same SHA, build 500, and release `20261011074429-9e979028fa3a`.
- Deployment job `114419590947` reports **1 public smoke passed, 1 authenticated smoke skipped**. Artifact `production-smoke-9e979028fa3af60b854807595ebf49577d4c236f` (ID `11695444523`) was retained. The successful workflow does not satisfy the skipped authenticated acceptance requirement.
- [PR #127 hosted frontend run 38114662826](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38114662826), head `5a1601512f7d395f7252da2e22f0490c5e8bd343`: canonical frontend verification passed; **192 browser tests passed, 57 skipped**, no reported flaky/failed tests. Its snapshots cover account entry plus selected screener, strategy, recommendation, pending execution, and fill-form states on desktop/mobile. These are targeted element snapshots, not full-page visual certification.
- [Nightly 38120592644](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38120592644): canonical journey job passed at 07:23:19 UTC on `5324fe30ae1ec1b11d3274c06fce716b847f9ea1`, with **192 passed, 57 skipped**. This predates PR #127/#129 and cannot substitute for a nightly result on the current head. Skips include viewport-inapplicable shell checks and production-only smoke; they are not proof of missing mandatory real acceptance.
- Chromium configuration allows one retry, with trace/video/screenshot on failure and 14-day failure-artifact uploads. Six desktop/mobile accessibility checks cover strategy configuration, recommendation review, and pending execution; other suites cover login, screener editor and fundamentals.
- PR #128 corrected runtime screener archival/history preservation; PR #129 added STR-09 pipeline assertions that market entry gates do not suppress valid reduction/exit actions. Their merged hosted checks supersede the corresponding historical findings; dependent screener/history and full runtime review remain distinct from a single mock browser scenario.

### Read-only production acceptance inspection

Inspection at 2026-10-11 08:43–08:48 UTC used the deployed read-only acceptance report and allowlisted persisted summaries. It did not dispatch jobs, train models, replay backfills, activate lifecycle, or alter services.

- All required ML acceptance tables exist and dedicated queue configuration is ready. `stoxla-ml-acceptance.service` is **inactive**.
- Latest campaign `abab7807-4745-473d-af36-7605ffccbb9e`, created 2026-10-06 19:11:54 UTC with cutoff 2026-10-01, remains `preflight`. Last recorded action is `preflight_requested`; all 1m/3m/6m horizons report `preflight_not_recorded`, null worker/Python evidence and no reference-date/coverage evidence. The report has `evidence_current=false`, `readiness.ready=false`, reason `current_production_acceptance_required`.
- There are **414 sealed NSE sources**. Recent governed backfills #7–#10 each completed their one requested date with no failed dates. Existing sealed sources and completed dates are not permission to replay old applies or train; a reviewed current-build campaign and per-date gates remain mandatory.
- Dedicated smoke credentials are absent in the deployed environment; the hosted authenticated smoke also skipped. No user email matching a dedicated smoke identity was found. An operator must provision the dedicated read-only identity and `STOX_SMOKE_EMAIL` / `STOX_SMOKE_PASSWORD` Actions secrets without putting values into chat.
- The persisted Kite session expired at **2026-10-06 00:30 UTC** and is not usable. No real provider API was called with the expired session. Controlled real-Kite smoke needs an operator-established current session; its validation must use read-only requests only, with no order placement/cancellation or portfolio reconciliation mutation.
- The frozen specification explicitly names `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` as the parent register. Its V9-UX-001 row remains **FROZEN / IMPLEMENTATION-READY**. GitHub issue search found no actual epic issue. External Linear access is absent; no tracker ID or closure is invented.

### Remaining independent engineering and scope

The original 71-ID annotation corpus is not proof of complete scope. The frozen contract explicitly includes stock research/watchlist, Kite connect/disconnect, and material account/portfolio settings; those supporting flows were missing from the journey inventory. The current slice documents existing behavior as `E2E-09`–`E2E-11` and adds deterministic desktop/mobile UI journeys for stock research/add-failure retry/removal, expired Kite-session handoff/retry/disconnect, and independent portfolio safeguard persistence. Only intercepted local responses are used; a strict local-origin guard prevents provider access. These tests prove UI requests and mock state, not real broker or database integration.

Production authenticated smoke now aborts unapproved mutation requests **before** they reach the server, rather than merely noticing them afterward, and disables credential-bearing traces/screenshots/videos. Missing identity remains a recorded acceptance blocker; this slice does not silently treat that skip as pass or claim to enforce a complete authenticated deployment gate before identity provisioning.

Still open: complete per-journey product/domain conformance, screener results visual coverage, remaining major workflow inventory (Dashboard/Stock Explorer, alerts/notification history, account/session recovery, profile/portfolio switching and relevant completed V9 features), primary-investor authorization/recovery paths, full current-head hosted regression, qualified real ML evidence, real-Kite boundary evidence, and authenticated production smoke. Keep the register open. No behavior-changing deviation waiver is granted by this update.

Local canonical frontend verification was attempted in the isolated scratch clone. Unit/component tests, typecheck and production build passed, but Playwright OS dependency installation failed under container setuid permissions; a separate exact Chromium download also failed with a truncated archive. No heavyweight browser verification was run on the production VPS. Hosted canonical CI remains the required merge gate for this slice.


The supporting slice also documents and automates `AUTH-03` logout/relogin, `E2E-12` notification history failure/retry/filter/attention persistence, and `E2E-13` per-tab portfolio switching and scoped holdings reads. The generated inventory is now **77 IDs**. All new behavior remains existing user-facing product behavior; deterministic tests are not substitutes for real session/database acceptance. Remaining inventory includes Dashboard/Stock Explorer, alert lifecycle, profile editing/password recovery and relevant completed V9 feature workflows. Hosted verification is still pending for this branch. Updated local Node unit verification: **216 passed**, typecheck/static documentation checks passed.

Hosted run [38126195260](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38126195260) failed at Playwright test discovery: worker-scoped artifact settings cannot be set inside a describe group. The follow-up uses an explicit untraced browser context for authenticated smoke, preserving ordinary public-smoke failure artifacts. Local discovery then listed **261 tests in 20 files**. Expanding the help corpus exposed a synonym ranking regression for “stock filter”; exact documented synonyms now receive a deterministic ranking bonus, and the existing retrieval regression test passes. Neither correction waives hosted verification.


Review of the installed Playwright reporter showed that manually created browser contexts are still instrumented and `fill` arguments can enter HTML step metadata even with tracing disabled. The final authenticated smoke therefore executes in a standalone child process with no Playwright Test reporter, trace/HAR/video/screenshot recording. It emits only allowlisted pass/failure stage names and discards raw browser errors. Public smoke retains ordinary failure evidence. No real smoke credentials were used during this investigation.

Final local pre-push checks for the isolated smoke worker: **218 Node unit tests passed**, including fail-closed configuration and secret-free output checks; static documentation/typecheck and `git diff --check` passed. Chromium execution remains the hosted gate.

Source review of the supporting fixtures corrected the notification action to the implemented **Mark as read** label and aligned watchlist membership with its root-level `watchlist_ids` response contract. This changes test fidelity only. A further account conformance finding is open: `ResetPasswordPage` uses one error state for link-load and submission failures, so a rejected submission hides the form, and its password labels are not associated with inputs. Account reset/recovery requires a separate reviewed fix and desktop/mobile acceptance coverage before final completeness.

Hosted [38126521869](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38126521869) finished on 2026-10-11 at 09:11:34 UTC with **196 passed, 8 failed, 57 skipped** and retained artifact `11697917733`. Four newly added Kite/settings cases passed. The remaining failures exposed (a) exact accessible-name mismatches for the Revenue info icon, emoji-prefixed Logout and Mark as read; (b) an actual session-restoration defect clearing `portfolio_active_id` before authentication finished; and (c) a mobile header utility row whose end-aligned horizontal overflow placed the portfolio switcher outside the viewport. Test fixtures/selectors are aligned with implemented response/accessibility contracts. The product fixes preserve a tab selection during unresolved authentication, clear it after confirmed logout, retain valid restoration/default fallback, and wrap mobile utilities so their controls and menus remain reachable. No force-click or assertion weakening is used. Four component regression cases reproduced **2 failures / 2 passes** before the restoration fix, then **4 passes** afterward. Updated hosted verification is mandatory before merge.

The canonical frontend verifier was rerun after the restoration/header fixes in the isolated clone: **218 Node tests and 184 component tests (42 files) passed**, typecheck and production build passed. The final browser-install step remains blocked by this container's OS dependency permissions; the exact Chromium hosted suite remains the required gate.
