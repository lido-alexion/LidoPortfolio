# V9-UX-001 Implementation Audit

**Status:** IN PROGRESS — the epic remains open.
**Audit date:** 2026-10-09
**Canonical scope:** [V9-UX-001 specification](../archive/specs/V9-User-Journey-Automation-E2E-Specification.md)
**Journey sources:** [`docs/user-journeys/`](../user-journeys/)

## Initial foundation slice

The first implementation group covers account entry: a signed-out investor opening a protected page, successful sign-in returning to that page, and recoverable rejected credentials. The source corpus now generates 71 journey topics, including the two new stable IDs `AUTH-01` and `AUTH-02`.

The login form now associates its labels with the email/password fields and announces rejected-credential feedback as an alert. Playwright coverage exercises sign-in and retry at 390×844 mobile and 1440×900 desktop viewports, with an axe WCAG 2A/2AA check on the login card. Journey annotations are checked against authoritative Markdown headings.

SCR-09 now stores a per-security outcome snapshot for each screener run and exposes paginated diagnostics through the run detail API. The screener page shows predicate failures, unavailable operands, and skip/processing reasons from the saved run snapshot, with an explicit note that AND/OR evaluation may short-circuit. Backend coverage verifies skipped-input diagnostics and false predicate evidence; desktop/mobile Playwright coverage verifies the run-detail experience. This closes the identified SCR-09 diagnosis gap for new runs, pending hosted E2E and full journey conformance review.

Frontend CI is configured to run the canonical frontend verifier on pull requests when frontend, E2E, or journey-source files change. Failed runs upload Playwright evidence for 14 days. The scheduled nightly workflow already exists; its broader coverage is not yet complete.

## Screener foundation slice

SCR-01 now has browser coverage at 390×844 and 1440×900. The scenario creates a screener named “Price Above MA200,” selects Close > SMA(200), saves it, and asserts the exact definition sent to the validated create endpoint. The screener test mock now persists created definitions so the detail route reflects the saved rule. SCR-02 now covers the documented AND combination of Close > SMA(200) and RSI(14) < 70, with assertions on the persisted condition tree at both viewports. SCR-03 also verifies nested AND/OR grouping for a trend condition with RSI and ROC alternatives. Hosted CI exposed that the documented em dash was rejected by both name validators; the UI and backend now accept en/em dashes, with API coverage for the documented SCR-02 name. SCR-04 now covers selecting an existing definition, changing its operand, and saving the revised rule. The source corpus remains at 71 topics; SCR-05 checks that an out-of-range indicator is rejected, communicated, and recoverable after correction. SCR-06 exercises a completed deterministic run, checks scan/match/insufficient-data counts, inspects the returned TCS predicate evidence, and confirms the flow makes no order request. SCR-07 imports a shared screener and verifies the resulting private copy in the active-portfolio editor preserves its definition. Forty-two IDs are annotated (AUTH-01/02, SCR-01/02/03/04/05/06/07/08/09, STR-01/02/03/04/05/06/07/08/09/10/11/12/13/14/15/16, REC-01/02/03/04/05/06/07/08/09/10/11/12, and AI-01/02/03). REC-10 and REC-11 exercise partial and zero funding resolutions, including the requested, actually available, and unresolved amounts, at mobile and desktop sizes. REC-12 marks superseded intent as non-current and opens its replacement, where target, quantity, strategy version, and reasoning can be compared. REC-07 verifies a funded recommendation moves to pending_execution with its reservation while submitting no order.

## Completion criteria snapshot

| Requirement | Current evidence | Status |
|---|---|---|
| Journey inventory and stable IDs | 71 source topics generated; annotation validator rejects missing/invalid source IDs. | Partial — discovered-workflow review remains open. |
| Traceability from automation to source | AUTH-01/AUTH-02, SCR-01/02/03/04/05/06/07/08/09, STR-01/02/03/04/05/06/07/08/09/10/11/12/13/14/15/16, REC-01/REC-02/REC-03/REC-04/REC-05/REC-06/REC-07/REC-08/REC-09/REC-10/REC-11/REC-12, and AI-01/AI-02/AI-03 carry stable annotations. | Partial — 42 of 71 IDs are annotated; core investor flows remain unmapped or uncovered. |
| Auth/account-entry prerequisite | AUTH-01/AUTH-02 desktop/mobile browser scenarios; labels, alert semantics, and axe checks. | In progress — hosted E2E gate pending. |
| Screener, strategy, recommendation, execution, and end-to-end journeys | SCR-01/02/03/04/05/06/07/08 are mapped and assert documented create/edit/validation/run-and-inspect/shared-import/archive behavior on mobile and desktop; SCR-09 now persists and displays per-security outcomes, but its hosted browser gate and wider journey conformance review are pending. Full strategy runtime concurrency, STR-16 execution attribution, execution, and end-to-end coverage remain incomplete. | In progress. |
| Deterministic data, isolation, and broker simulation | Existing harness includes a seed hook and broker-related mocks in selected tests. | Open — audit per scenario and close missing lifecycle/recovery cases. |
| Chromium desktop and representative mobile | Playwright Chromium and mobile projects exist; auth slice covers both target viewports. | Partial — all major journeys must meet the viewport standard. |
| Blocking CI and nightly regression | PR frontend verifier and scheduled nightly workflow are configured. | In progress — validate the PR gate in hosted CI and expand the full nightly suite. |
| Visual regression | No targeted journey screenshot baselines are currently recorded. | Open. |
| Accessibility regression | Existing checks cover selected surfaces; auth slice adds a login-card axe check. | Partial — cover other key journey pages/states. |
| Production smoke | Existing test verifies public availability and page title only. | Open — add safe authenticated/read-only checks and deployment health integration when a dedicated smoke identity is configured. |
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
