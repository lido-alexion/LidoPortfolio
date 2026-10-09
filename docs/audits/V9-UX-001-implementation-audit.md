# V9-UX-001 Implementation Audit

**Status:** IN PROGRESS — the epic remains open.
**Audit date:** 2026-10-09
**Canonical scope:** [V9-UX-001 specification](../archive/specs/V9-User-Journey-Automation-E2E-Specification.md)
**Journey sources:** [`docs/user-journeys/`](../user-journeys/)

## Initial foundation slice

The first implementation group covers account entry: a signed-out investor opening a protected page, successful sign-in returning to that page, and recoverable rejected credentials. The source corpus now generates 71 journey topics, including the two new stable IDs `AUTH-01` and `AUTH-02`.

The login form now associates its labels with the email/password fields and announces rejected-credential feedback as an alert. Playwright coverage exercises sign-in and retry at 390×844 mobile and 1440×900 desktop viewports, with an axe WCAG 2A/2AA check on the login card. Journey annotations are checked against authoritative Markdown headings.

SCR-09 now stores a per-security outcome snapshot for each screener run and exposes paginated diagnostics through the run detail API. The screener page shows predicate failures, unavailable operands, and skip/processing reasons from the saved run snapshot, with an explicit note that AND/OR evaluation may short-circuit. Backend coverage verifies skipped-input diagnostics and false predicate evidence; desktop/mobile Playwright coverage verifies the run-detail experience. This closes the identified SCR-09 diagnosis gap for new runs, pending hosted E2E and full journey conformance review.

Frontend CI is configured to run the canonical frontend verifier on pull requests when frontend, E2E, or journey-source files change. Failed runs upload Playwright evidence for 14 days. The scheduled nightly workflow runs the full canonical journey suite and now retains failure evidence for 14 days; operational hosted run evidence remains pending.

## Screener foundation slice

SCR-01 now has browser coverage at 390×844 and 1440×900. The scenario creates a screener named “Price Above MA200,” selects Close > SMA(200), saves it, and asserts the exact definition sent to the validated create endpoint. The screener test mock now persists created definitions so the detail route reflects the saved rule. SCR-02 now covers the documented AND combination of Close > SMA(200) and RSI(14) < 70, with assertions on the persisted condition tree at both viewports. SCR-03 also verifies nested AND/OR grouping for a trend condition with RSI and ROC alternatives. Hosted CI exposed that the documented em dash was rejected by both name validators; the UI and backend now accept en/em dashes, with API coverage for the documented SCR-02 name. SCR-04 now covers selecting an existing definition, changing its operand, and saving the revised rule. The source corpus remains at 71 topics; SCR-05 checks that an out-of-range indicator is rejected, communicated, and recoverable after correction. SCR-06 exercises a completed deterministic run, checks scan/match/insufficient-data counts, inspects the returned TCS predicate evidence, and confirms the flow makes no order request. SCR-07 imports a shared screener and verifies the resulting private copy in the active-portfolio editor preserves its definition. Sixty-two of the 71 IDs are annotated. Coverage now includes AUTH-01/02, all SCR-01–09, STR-01–16, REC-01–12, AI-01–09, and EXE-01/02/03/04/05/06/07/08/09/10/11/12/13/14. The nine unmapped IDs are EXE-15 and E2E-01–08. REC-10 and REC-11 exercise partial and zero funding resolutions, including the requested, actually available, and unresolved amounts, at mobile and desktop sizes. REC-12 marks superseded intent as non-current and opens its replacement, where target, quantity, strategy version, and reasoning can be compared. REC-07 verifies a funded recommendation moves to pending_execution with its reservation while submitting no order.

## Completion criteria snapshot

| Requirement | Current evidence | Status |
|---|---|---|
| Journey inventory and stable IDs | 71 source topics generated; annotation validator rejects missing/invalid source IDs. | Partial — discovered-workflow review remains open. |
| Traceability from automation to source | 62 of 71 source IDs carry stable annotations across account entry, screeners, strategy, recommendations, assistant, and execution. | Partial — EXE-15 and eight composite IDs remain unmapped. |
| Auth/account-entry prerequisite | AUTH-01/AUTH-02 desktop/mobile browser scenarios; labels, alert semantics, and axe checks. | In progress — hosted E2E gate pending. |
| Screener, strategy, recommendation, execution, and end-to-end journeys | SCR-01/02/03/04/05/06/07/08 are mapped and assert documented create/edit/validation/run-and-inspect/shared-import/archive behavior on mobile and desktop; SCR-09 now persists and displays per-security outcomes, but its hosted browser gate and wider journey conformance review are pending. Full strategy runtime concurrency, STR-16 execution attribution, execution, and end-to-end coverage remain incomplete. | In progress. |
| Deterministic data, isolation, and broker simulation | Existing harness includes a seed hook and broker-related mocks in selected tests. | Open — audit per scenario and close missing lifecycle/recovery cases. |
| Chromium desktop and representative mobile | Playwright Chromium and mobile projects exist; auth slice covers both target viewports. | Partial — all major journeys must meet the viewport standard. |
| Blocking CI and nightly regression | PR frontend verifier runs the canonical suite; scheduled nightly runs journeys and uploads failure evidence for 14 days. | In progress — validate hosted runs and complete the unmapped journey inventory. |
| Visual regression | No targeted journey screenshot baselines are currently recorded. | Open. |
| Accessibility regression | Existing checks cover selected surfaces; auth slice adds a login-card axe check. | Partial — cover other key journey pages/states. |
| Production smoke | Post-deploy workflow now runs Chromium against the public production shell and JavaScript asset; an optional dedicated read-only account check is skipped unless its secrets are configured. | In progress — hosted post-deploy execution and smoke-identity provisioning are unverified. |
| Journey conformance | No full comparison of the application against every approved journey is complete. | Open — resolve all deviations before automating each journey; seek PO decisions for behavior changes. |
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

The current feature branch is based on latest `master` and remains open as draft PR #118. The branch adds the EXE-01/02/03/04/05/08/09/13 manual, pending, cancellation, and reconciliation scenarios; unknown broker state never triggers retry or order submission in the deterministic test. The Review orders table exposes broker status, broker error, and filled quantity; it offers reconciliation for in-flight orders and withholds manual ledger entry for submitted/open/partial/unknown/rejected/cancelled orders. The pending queue reads prior orders by recommendation, fails closed when history cannot load, and blocks retries when any attempt remains in flight or has a fill.

The post-deploy workflow now has a browser smoke gate after existing runtime, deployed-SHA, and JavaScript-asset checks. The public shell smoke is non-destructive; the authenticated read-only check requires dedicated `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD` GitHub secrets. The workflow retains browser evidence for 14 days.

Current inventory: 71 documented IDs; 62 annotated; nine still unmapped (EXE-15 and E2E-01–08). `npm run test:js:unit` passes 215 tests, static docs pass with 53 topics, journey metadata generates 71 topics, and Playwright discovery lists 231 tests. Hosted browser execution for the latest commit is pending.

Closure remains blocked by unmapped verification/composite journeys, incomplete visual regression and full conformance review, hosted gate results, production smoke execution, and required PO approval for behavior-changing resolution of the documented STR-07/STR-08 differences. The epic and register must remain open.
