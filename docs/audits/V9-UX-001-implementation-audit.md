# V9-UX-001 Implementation Audit

**Status:** IN PROGRESS — the epic remains open.
**Audit date:** 2026-10-10
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
| Screener, strategy, recommendation, execution, and end-to-end journeys | SCR-01/02/03/04/05/06/07/08 are mapped and assert documented create/edit/validation/run-and-inspect/shared-import/archive behavior on mobile and desktop; SCR-09 now persists and displays per-security outcomes, but its hosted browser gate and wider journey conformance review are pending. Full strategy runtime concurrency, STR-16 execution attribution, execution, and end-to-end coverage remain incomplete. | In progress. |
| Deterministic data, isolation, and broker simulation | Existing harness includes a seed hook and broker-related mocks in selected tests. | Open — audit per scenario and close missing lifecycle/recovery cases. |
| Chromium desktop and representative mobile | Playwright Chromium and mobile projects exist; auth slice covers both target viewports. | Partial — all major journeys must meet the viewport standard. |
| Blocking CI and nightly regression | PR frontend verifier runs the canonical suite; scheduled nightly runs journeys and uploads failure evidence for 14 days. | In progress — validate hosted runs and complete the unmapped journey inventory. |
| Visual regression | Login and rejected-credential card baselines are recorded for mobile and desktop in the current follow-up branch. | Partial — add baselines for the other key journey pages and states. |
| Accessibility regression | Existing checks cover selected surfaces; auth slice adds a login-card axe check. | Partial — cover other key journey pages/states. |
| Production smoke | Post-deploy workflow runs Chromium against the public production shell and JavaScript asset; authenticated read-only smoke requires `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD`. | Pending hosted deployment run and smoke identity provisioning. |
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
- STR-08 coverage verifies the strategy ATR stop control. The classic portfolio stop-loss and trailing-stop settings remain under Settings, so they are not part of this Strategy editor journey. STR-09 checks saving market gates; behavior for blocked OPEN/INCREASE versus eligible REDUCE/EXIT still needs pipeline coverage.


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
