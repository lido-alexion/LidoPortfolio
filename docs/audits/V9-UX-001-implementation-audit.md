# V9-UX-001 Implementation Audit

**Status:** IN PROGRESS — the epic remains open.
**Audit date:** 2026-10-09
**Canonical scope:** [V9-UX-001 specification](../archive/specs/V9-User-Journey-Automation-E2E-Specification.md)
**Journey sources:** [`docs/user-journeys/`](../user-journeys/)

## Initial foundation slice

The first implementation group covers account entry: a signed-out investor opening a protected page, successful sign-in returning to that page, and recoverable rejected credentials. The source corpus now generates 71 journey topics, including the two new stable IDs `AUTH-01` and `AUTH-02`.

The login form now associates its labels with the email/password fields and announces rejected-credential feedback as an alert. Playwright coverage exercises sign-in and retry at 390×844 mobile and 1440×900 desktop viewports, with an axe WCAG 2A/2AA check on the login card. Journey annotations are checked against authoritative Markdown headings.

Frontend CI is configured to run the canonical frontend verifier on pull requests when frontend, E2E, or journey-source files change. Failed runs upload Playwright evidence for 14 days. The scheduled nightly workflow already exists; its broader coverage is not yet complete.

## Completion criteria snapshot

| Requirement | Current evidence | Status |
|---|---|---|
| Journey inventory and stable IDs | 71 source topics generated; annotation validator rejects missing/invalid source IDs. | Partial — discovered-workflow review remains open. |
| Traceability from automation to source | AUTH-01/AUTH-02 and AI-01/AI-02/AI-03 carry stable annotations. | Partial — only 5 of 71 IDs are currently annotated; core investor flows remain unmapped or uncovered. |
| Auth/account-entry prerequisite | AUTH-01/AUTH-02 desktop/mobile browser scenarios; labels, alert semantics, and axe checks. | In progress — hosted E2E gate pending. |
| Screener, strategy, recommendation, execution, and end-to-end journeys | Existing browser tests are not yet mapped to the authoritative journey IDs and do not establish full scenario/recovery coverage. | Open. |
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
- `git diff --check`: **PASS**.
- Playwright browser tests are delegated to hosted PR CI; repository guidance prohibits running the heavyweight browser install/suite on the production VPS.

This audit does not claim full journey conformance, completion of the 71-scenario corpus, production smoke readiness, or V9-UX-001 implementation. Keep the register status unchanged until all frozen completion criteria pass.
