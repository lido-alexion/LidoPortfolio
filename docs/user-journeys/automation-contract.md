# V9 journey automation contract

The Markdown journeys remain the product source of truth. V9 automation references
their stable IDs (for example `SCR-01`, `REC-07`, and `E2E-01`) and records the ID
as test metadata; selectors are implementation details and do not become part of
the user-facing journey text.

Every deterministic run uses an isolated seed (`window.__STOX_TEST_SEED__` in the
browser harness), Chromium, and the representative `1440x900` desktop or
`390x844` mobile viewport. Setup hooks may seed/reset data, but the journey itself
must use the real user-facing surface. Broker behavior is mocked in CI and no test
may submit or cancel a real order.

Blocking journey checks run in normal CI. The broader suite is also scheduled
nightly. Failed runs retain Playwright evidence in the standard reporter output;
production smoke remains non-destructive and separate from deterministic CI.

| Journey source | Stable IDs | Automation location |
| --- | --- | --- |
| `00-account-entry.md` | `AUTH-*` | `app/tests/e2e/auth-entry.spec.js`, `supporting-investor-journeys.spec.js`, `account-recovery.spec.js`, `profile-recovery.spec.js`, and `onboarding-journeys.spec.js` |
| `01-screeners.md` | `SCR-*` | `app/tests/e2e/` |
| `02-strategies.md` | `STR-*` | `app/tests/e2e/` |
| `03-recommendations-review.md` | `REC-*` | `app/tests/e2e/` |
| `04-execution-transactions.md` | `EXE-*` | `app/tests/e2e/` |
| `05-end-to-end.md` | `E2E-*` | `app/tests/e2e/` |
| `06-assistant.md` | `AI-01`–`AI-09` | `app/tests/e2e/assistant.spec.js`, `assistant-agent.spec.js`, and `embedded-ai.spec.js` |
| `07-supporting-investor.md` | `E2E-09`–`E2E-13` | `app/tests/e2e/supporting-investor-journeys.spec.js` |
| `08-investor-overview-alerts.md` | `E2E-14`–`E2E-17` | `app/tests/e2e/investor-overview-journeys.spec.js` and `optional-email-recovery.spec.js` |
| `11-historical-analysis.md` | `E2E-23`–`E2E-24` | `app/tests/e2e/historical-analysis-journeys.spec.js` |
| `10-portfolio-cash.md` | `E2E-20`–`E2E-22` | `app/tests/e2e/portfolio-cash-journeys.spec.js` |
| `09-data-export.md` | `E2E-18`–`E2E-19` | `app/tests/e2e/export-journeys.spec.js` |
| `12-portfolio-analysis-tax.md` | `E2E-25`–`E2E-27` | `app/tests/e2e/portfolio-analysis-tax-journeys.spec.js` |
| `13-financial-source-lifecycle.md` | `EXE-16`, `E2E-28`–`E2E-30` | `app/tests/e2e/financial-source-journeys.spec.js` |
| `14-artifact-library-governance.md` | `E2E-31`–`E2E-34` | `app/tests/e2e/artifact-library-journeys.spec.js` |
