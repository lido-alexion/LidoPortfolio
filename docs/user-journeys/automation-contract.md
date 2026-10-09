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
| `00-account-entry.md` | `AUTH-*` | `app/tests/e2e/auth-entry.spec.js` |
| `01-screeners.md` | `SCR-*` | `app/tests/e2e/` |
| `02-strategies.md` | `STR-*` | `app/tests/e2e/` |
| `03-recommendations-review.md` | `REC-*` | `app/tests/e2e/` |
| `04-execution-transactions.md` | `EXE-*` | `app/tests/e2e/` |
| `05-end-to-end.md` | `E2E-*` | `app/tests/e2e/` |

| `06-assistant.md` | `AI-01`, `AI-02`, `AI-03` | `app/tests/e2e/assistant.spec.js` |
