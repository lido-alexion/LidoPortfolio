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
| `08-investor-overview-alerts.md` | `E2E-14`–`E2E-17`, `E2E-48` | `app/tests/e2e/investor-overview-journeys.spec.js` and `optional-email-recovery.spec.js` |
| `11-historical-analysis.md` | `E2E-23`–`E2E-24` | `app/tests/e2e/historical-analysis-journeys.spec.js` |
| `10-portfolio-cash.md` | `E2E-20`–`E2E-22` | `app/tests/e2e/portfolio-cash-journeys.spec.js` |
| `09-data-export.md` | `E2E-18`–`E2E-19` | `app/tests/e2e/export-journeys.spec.js` |
| `12-portfolio-analysis-tax.md` | `E2E-25`–`E2E-27` | `app/tests/e2e/portfolio-analysis-tax-journeys.spec.js` |
| `13-financial-source-lifecycle.md` | `EXE-16`, `E2E-28`–`E2E-30` | `app/tests/e2e/financial-source-journeys.spec.js` |
| `14-artifact-library-governance.md` | `E2E-31`–`E2E-34` | `app/tests/e2e/artifact-library-journeys.spec.js` |
| `15-research-persisted-review.md` | `E2E-35`–`E2E-39` | `app/tests/e2e/research-review-journeys.spec.js`; existing `fundamental-insights-browser.spec.js` retains deterministic/AI provenance and desktop/mobile evidence |
| `16-personal-knowledge-calendar.md` | `E2E-40`–`E2E-45` | `app/tests/e2e/personal-knowledge-calendar-journeys.spec.js`; API/domain evidence remains separately covered by KnowledgeBoardTest, KnowledgeBoardImageTest, V5WikiFoundationTest, V6ContextualNotesTest and CalendarEventTest |

Personal-knowledge journeys exercise real desktop/mobile controls against strictly bounded localhost fixtures. Synthetic public capabilities, page images, export transport, recurrence occurrences, holidays and reminder settings are UI evidence only; they do not qualify production sharing, ZIP structure, official holiday sources or provider delivery. Failed private reads are never treated as confirmed empty/missing, unknown contextual-note reads block Save, and delayed Wiki responses cannot replace another Portfolio's content.

| `00-account-entry.md`, `17-account-delivery-settings.md` | `AUTH-09`–`AUTH-11`, `E2E-46`–`E2E-47` | `app/tests/e2e/account-channel-journeys.spec.js`; backend ownership/proof/delivery evidence remains independent in AuthSessionTest, TotpFlowTest, V6PersonalApiTokenTest and NotificationSettingsApiTest |

Account authority/delivery journeys use only synthetic localhost sessions, execution codes, recovery codes, tokens, destinations and channel responses. No actual credential is created/revoked, no provider message is sent, and no OTP/token/notification outcome qualifies real trading or provider authority. Display-once values are visibly marked synthetic; real production smoke remains read-only.

### Existing Dashboard portability and Stock Details combo charts

`dashboard-combo-journeys.spec.js` extends E2E-48 named recovery with E2E-49–51 at mobile/desktop Chromium: account-local variants/core visibility/size/move/lock/reset; named create/default/rename/duplicate/delete recovery and exact-definition autosave retry/local precedence; compatible/invalid/oversized import and configuration-only JSON export; grouped/cyclic chart presets, disabled coverage reasons, preference/history GET recovery, temporary legend/range/sampling and explicit account-default failure/retry. Origin/method guards allow only named-layout and combo-preference fixture writes. Source observation arrays and sibling named definitions stay unchanged. Full targeted combo WCAG stays enabled; two new layout-editor visual states must be captured, inspected and committed before fresh hosted comparison acceptance. Existing references/tolerances are unchanged.

Independent backend contracts remain `tests/Feature/V9DashboardLayoutApiTest.php` and `tests/Feature/V9/ComboChartPreferenceTest.php`; dashboard/combo data Node tests and preset component tests retain migration, minimum visibility, coverage, cadence alignment and tooltip contracts. Synthetic UI charts do not qualify real historical inputs, backend persistence, provider integrations, production authentication, or governed ML evidence.

### Stored transaction and evaluation-history recovery

`persisted-history-journeys.spec.js` runs E2E-52–53 on desktop/mobile Chromium. Unknown closed/history reads never fabricate empty states; a rejected filtered read retains known rows and retries the same scope; cancelled/rejected stored-transaction deletion preserves source evidence and a confirmed local fixture deletion preserves a sibling. Evaluation list/result failures keep the selected run, retry its ordinary GET and distinguish a known empty run from an unavailable one. Origin/method guards permit only DELETE of the one synthetic transaction fixture, no order/provider/cash/ML or evaluation mutation. Four meaningful component regressions cover each failure state. Financial FIFO/correction and evaluation-domain tests remain independent; synthetic browser history is not backend qualification.

### Runtime registry compatibility

`registry-catalog-journeys.spec.js` covers E2E-54–55 on desktop/mobile Chromium: explicit unavailable catalogue/envelope/version recovery, exact definition export, shared read-only source/copy boundary, validation invalidation on JSON edits, retained rejected imports, runtime editor navigation and intended-only enable/archive confirmation/cancellation. Five meaningful component regressions reproduce list/detail/version failures. Strict localhost origin/method guards permit only the named synthetic registry operations; no live broker, cash, training, source qualification or protected service calls. Backend V8 `ScreenerRegistryImportRuntimeTest` and `StrategyRegistryImportRuntimeTest` independently govern runtime persistence, imported provenance and absence of a Library prerequisite. Strategy lifecycle/immutable source attribution remain covered by their existing domain contracts; synthetic browser fixtures do not qualify backend persistence or source data.
