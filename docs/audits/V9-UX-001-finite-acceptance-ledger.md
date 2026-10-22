# V9-UX-001 finite acceptance ledger

**Status:** IMPLEMENTATION IN PROGRESS. **Review date:** 2026-10-22. Root owns acceptance.
**Authority:** [frozen UX001 specification §§1–20](../archive/specs/V9-User-Journey-Automation-E2E-Specification.md), [golden journeys](../user-journeys/) and recorded PO decisions. This is an evidence reconciliation, not an amendment to frozen behavior.

## Accepted scope

The epic covers all major Investor journeys, including discovered existing workflows, their happy paths and key recovery/error/cancel/auth paths, Chromium desktop and representative mobile, targeted visual regression, lightweight accessibility, deterministic known-state setup/teardown, safe broker fixtures, traceability, retained failure diagnostics, blocking CI, ordinary scheduled regression and non-destructive production/integration smoke.

Successful historical PIT acquisition/training for 1m/3m/6m belongs to FEAT-056/057 acceptance. UX001 retains relevant readiness and blocked-state UI checks; it does not acquire data or train models to close an Investor journey. Other V9 epics need not all close. Only their directly relevant journey evidence matters.

Watcher configuration lifecycle, blocked reviewer recovery and runner-to-VPS SSH diagnosis are tracked separately in [V9-OPS-004 / #251](https://github.com/lido-alexion/LidoPortfolio/issues/251). Administrative repairs do not block UX product acceptance. A healthy accepted release and production smoke remain required outcomes.

Nightly must be operational and qualified with relevant-source freshness. Frozen scope does not impose a universal rerun after every SHA. The established [expected-deployed-SHA three-GET Kite protocol](V9-UX-001-real-kite-smoke-protocol.md) remains the safe procedure for the required separate real integration boundary. No new provider/account authority is granted here.

## Current qualification

[PR250](https://github.com/lido-alexion/LidoPortfolio/pull/250) was normally merged after exact-head [CI39717113629 /1511](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39717113629) passed. Head `469aca9f465ae744e5f3c70957a15dd783e739ec`:247 Node contracts,583 component cases/90files including13 Backtest route cases, typecheck,129 documentation topics, build,470 Chromium passes and57 governed skips, no failed-test retries or flaky results. Root reviewed full frontend/Python/current-job/artifact evidence; Python 56 passed and artifacts were empty. The change restores route-owned evidence/editor/continuation behavior; financial contracts, metadata payloads and the existing background continuation helper are unchanged.

Normal master release [CI39728312989 /1513](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39728312989) qualifies application source `acba369fd4458bf2f55d02fa896800d8115d652e`. Its final release receipt is recorded in the current implementation-audit section; PR qualification alone is not deployment proof.

## Accepted ordinary nightly and current blocker disposition

[Scheduled nightly24](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39732013335) passed on exact source `198a343fd8eacf5320d3f8d920f18dbc717b5d6d`:470 Chromium passes,57 governed skips,29.6minutes, no failed-test retries/flaky results and no failure artifacts. Root reviewed the full terminal log and job outcomes. The app, scripts, deploy and workflow trees equal accepted live acba/build1513; intervening changes are documentation only. The ordinary scheduled-regression requirement is qualified for the relevant application source. No new final-SHA rerun rule applies to this documentation receipt.

Root's finite adjudication retains exactly two missing acceptance outcomes: AI-02's held automation package and the authorized real-Kite boundary receipt. These require the established scope authorizer and authorized operator respectively. Current instructions preserve PR220's all-mutation/indirect hold and prohibit automatic real provider/account actions. No executable producer exists for either outcome within current authority. The epic remains incomplete; it is not waiting on an external code reviewer, another ML campaign or all parallel epics.

## Frozen §19 evidence ledger

| Criterion | Evidence and accepted boundary | Remaining action |
|---|---|---|
|100% major journeys documented|22 numbered guides/129 stable IDs plus the retained App route inventory; discovered Portfolio, history, research, knowledge, registry, settings, export and AI workflows are included.|Adjudicate the guide ledger with the exact outstanding AI-02 action below; no fresh generic inventory programme.|
|Each journey reviewed against implementation|Dated implementation-audit inventories and root's scoped source reviews provide semantic evidence separately from static annotations.|Retain the existing reviews and resolve specific omissions; a passing ID count is not a conformance decision.|
|Every documented difference resolved|Recorded corrections and PO-approved placement/cooldown decisions remain traceable. Recent Board, Bundle, Recommendation, Review, Dashboard, Holdings, Transactions and Backtest corrections are integrated.|Do not claim the held AI-02 coverage package accepted or erase its hold. Name any remaining actual difference rather than requiring every parallel epic.|
|Required PO approval|Frozen decisions and recorded 10 October approvals remain authoritative; current ownership fixes restore documented behavior without changing financial policy.|No new product decision identified by this reconciliation. Scope authorization holds remain distinct.|
|Happy/key recovery/error automation|Named suites below exercise user-visible recovery/cancel/uncertain receipts; deferred components add latest-read/session/operation proofs.|AI-02 actual copy/feedback actions remain an exact automation omission in current master; see below.|
|Chromium desktop|Canonical suites run the required desktop scenarios.|Retain relevant current CI; no new browser family.|
|Representative mobile|Major journey suites include 390×844 cases; current full run passes their applicable cases.|No all-device matrix.|
|Targeted visuals|Auth login/error, SCR06 results, STR06 threshold, composite recommendation/pending/actual-fill and critical Dashboard mobile comparisons pass in 1511; reviewed PNG provenance is retained in the implementation audit.|Bounded requirement accepted; no per-page screenshot expansion.|
|Lightweight accessibility|Axe assertions and keyboard checks on auth, results, strategy, review, pending and configured STR-06/REC-02 dialogs pass mobile/desktop in 1511.|Bounded requirement accepted; no full WCAG certification.|
|Deterministic setup/teardown|Per-test browser contexts and per-invocation fixture state, exact origin/method guards, and diagnostic terminal-receipt afterEach checks. Backend isolated MySQL checks are separate.|Seed label alone is not database-seed proof; one worker is not order-independence proof. Frozen known-state requirements do not prescribe one database architecture.|
|Deterministic brokerCI +real Kite|Mock lifecycle/partial/reject/cancel/uncertain/idempotent-retry scenarios remain separate from live boundary evidence.|Historical12 October/build 523 three-GET receipt retained; current accepted-source safe protocol receipt remains open. No current session expiry is inferred.|
|BlockingCI/one retry/nightly|Canonical verification blocks E2E failures, retries1, and retains repeated flakiness as blocking. Ordinary scheduled runs are recorded separately.|Qualified by ordinary scheduled24 on relevant current source198; retain the receipt. No manual dispatch or per-documentation-SHA repeat.|
|Non-destructive production smoke|Normal release requires artifact verification, current-master equality under deployment lock, exact runtime SHA, public health and mandatory authenticated smoke.|Qualified for live application acba/build1513; current documentation-only source has identical application/deployment trees. Public-only health does not replace the retained AUTH receipt.|
|Failed-run diagnostics|Existing failure traces/screenshots/network/error contexts and full logs are retained;1505 artifact12392383191 SHA25651bcf1893edcd72ba79fb923d35f70c876ea10e856c32d5c43a2bcba88e463fb was independently reviewed.|Preserve useful evidence; no test analytics product.|
|No real money-moving automation|Synthetic request guards and deterministic broker lifecycle; production read-only allowlist and separate guarded three-GET protocol.|Preserve; no real orders, cancellations, cash, provider or account action is authorized by this ledger.|
|Documentation-to-test traceability|129-ID AST-backed contract, route map, guides and named source suites;current Node contract passes.|Publish truthful final audit/register status only after actual remaining outcomes pass; static traceability is not behavior proof.|

## Exact AI-02 automation omission and existing ownership

Guide 06 AI02 requires copying the answer and source links and submitting Helpful/Not helpful feedback. Current `assistant.spec.js` asserts that Copy response is visible and tests clearing the conversation, but does not click copy or exercise feedback. Root searched the current JavaScript tests for the feedback endpoint and independently read the product copy/feedback implementation. This is a missing automation receipt, not a newly inferred product defect.

Existing [PR220](https://github.com/lido-alexion/LidoPortfolio/pull/220), head `2d3a6bf32010ecacfcac962c14018ea14356eaca`, contains the two targeted component/desktop-mobile fixtures: exact copied answer/source call contents, answer-request-ID feedback payloads, Helpful/Not helpful comment and 503-retry feedback. Its clipboard mock proves the UI call contract, not native OS clipboard behavior.

**PR220 remains under its explicit all-mutation/indirect scope hold.** Do not copy, recreate, restore, replace or merge its work. The established scope authorizer must resolve that hold before root can normally reconcile its old base and obtain fresh current-head hosted qualification. Old CI is not current merge acceptance. This exact dependency satisfies neither itself nor all parallel V9 work by inference. PR210 and foreign 176/186/187 ownership remain preserved.

## Guide-to-evidence consolidation

This table reuses the dated semantic inventories and accepted scoped reviews in the [implementation audit](V9-UX-001-implementation-audit.md). Browser suite names below are under `app/tests/e2e/`; named component regressions supplement them. The current canonical run executes their applicable cases, but synthetic responses do not establish live provider delivery or production financial persistence.

|Guide /IDs|Named suites|Happy/recovery evidence and limitation|
|---|---|---|
|00 account /AUTH01–11|auth-entry, account-recovery, onboarding-journeys, profile-recovery, access-admission-journeys, account-channel-journeys|Login/return, rejected credentials, password/invite/access, own session/TOTP/token recovery/cancel;synthetic secrets.|
|01 Screeners /SCR01–09|screener-journeys, screener-investor-workflow|Create/edit/validate/run/reuse/archive/diagnose;failed run receipts/recovered results;SCR08 preservation correction integrated;approved SCR-06 baselines.|
|02 Strategy /STR01–16|strategy-journeys, composite-journeys|Definition/entry/exit/weights/sizing/gates/edit/enable/pause-retire-restore/concurrent ownership;accepted 10 October decisions;STR-14 PR240 and INR5000 boundary integrated.|
|03 Recommendations /REC01–12|recommendation-journeys, tos-recommendations, composite-journeys|Pipeline/review/explanation/actions/approval/rejection/defer-reopen/funding/supersession;PR245 adds 17 ownership components.|
|04 Execution /EXE01–15|execution-journeys, composite-journeys|Manual/semi-auto authorization/pending/order/cancel/partial/reject/uncertain/retry/idempotency/holding receipts through mocks.|
|05 Composite /E2E01–08|end-to-end-journeys, composite-journeys|Idea-to-BUY, SELL, supersession, cancel/retry, capital resolution, same-stock strategy separation;E2E08 readiness/blocked UI, not successful ML training.|
|06 Assistant /AI01–09|assistant, assistant-agent, embedded-ai|Grounding/followup/clear/unavailable/history/preview-confirm/stock placements/strategy draft;221–225 recovery and 218 pending-clear integrated. **AI-02 copy/feedback actions remain open under PR220.**|
|07 Stock details /VIZ01|v9-viz001-combo-charts|Preset/evidence/keyboard/mobile touch/availability;fixture-bound charts and targeted Axe.|
|07 Supporting /E2E09–13|supporting-investor-journeys, holdings-portfolio-ownership|Watchlist/connect state/settings/notifications/switch;PR248 adds 20 components+2 mobile/desktop stale-response cases.|
|08 Overview /E2E14–17,48|investor-overview-journeys, optional-email-recovery, dashboard-combo-journeys|Refresh/unavailable/cache/relative-strength/policy/optional channel/named layout;PR247 adds 13 ownership cases.|
|09 Export /E2E18–19|export-journeys|Snapshot/background job/cancel/private basket/uncertain receipt/retry;local fixtures, not actual workbook/provider qualification.|
|10 Portfolio/cash /E2E20–22|portfolio-cash-journeys|Create/rename/select/default/retire/clone/deliberate cash evidence;validation/cancel/failed-or-uncertain receipt/retry;no real funds.|
|11 Historical /E2E23–24|historical-analysis-journeys +backtest-detail-route-ownership components|Experiment/read/meta/duplicate/checkpoint cancel/delete/replay/readiness/recovery;PR250 adds 13 actual-router deferred cases, unchanged background helper.|
|12 Analysis/tax /E2E25–27|portfolio-analysis-tax-journeys|Historical holdings/comparison/snapshot/performance-tax/preferences/inputs/dividend preview/recovery;no live tax/provider qualification.|
|13 Source lifecycle /EXE16, E2E28–30|financial-source-journeys +transaction-save-recovery +transactions-portfolio-ownership|Correct/delete/CSV idempotency/adoption/corporate preview-confirm;PR249 adds 19 components;legacy uncertain-save exact PUT/zero POST retained.|
|14 Library /E2E31–34|artifact-library-journeys|Draft/import/immutable publication/exact dependencies/binding/fork/Bundle/recovery;242+243 completion receipt fixes integrated, both E2E-34 viewports pass.|
|15 Research/review /E2E35–39|research-review-journeys, fundamental-insights-browser +review-dashboard-portfolio-ownership|Dated price/index/breadth/observational Review vs stored report/fundamentals/patterns/read retry/identity;PR246 adds 12 ownership components.|
|16 Knowledge/calendar /E2E40–45|personal-knowledge-calendar-journeys +knowledge-board-portfolio-ownership|Notes/autosave/tags/Wiki/history/share-revoke/recurrence/holiday/contextual reads;PR244 adds 12 components+both E2E-40 viewports. Unproven timing candidate creates no task.|
|17 Settings/delivery /E2E46–47|account-channel-journeys|Unknown reads, safeguards, retained drafts, once-only values, verified channel ownership/serializedwrites;not actual provider delivery.|
|18 Layout/chart /E2E49–51|dashboard-combo-journeys|Local/named variants/lock/reset/import-export/error/privacy/defaults;approved bounded mobile and desktop references.|
|19 Stored history /E2E52–53|persisted-history-journeys|Stored squared-off/evaluation evidence, current filter/detail identity/correction/delete/read retry;no regeneration masquerading as stored evidence.|
|20 Registry /E2E54–55|registry-catalog-journeys|Owned/shared definitions/versions/recovery/export/validated runtime import/status/scope;current V8 contract, no invented Library publication prerequisite.|

## Finite closure actions and owners

1. **Root — complete:** build1513/acba release, exact SHA/public+mandatory AUTH accepted; failed1510 remains historical.
2. **Existing hold scope authorizer, then root:** resolve AI-02's explicit PR220 hold before any mutation; obtain normal fresh-base/current-head qualification only if permitted. No duplicate worker or indirect restoration.
3. **Root — reconciled:** existing22-guide review record and criteria consolidated; AI-02 is the named remaining automation omission. No generic matrix or bug hunt is opened.
4. **Root — complete:** ordinary nightly24 on source198 accepted with application-source equivalence to live acba; it supplements normal CI.
5. **Authorized operator under existing protocol:** supply safe real-Kite boundary receipt; root records it. No new credentials/session status or provider-action authority is inferred.
6. **Root:** update the audit and V9 register to IMPLEMENTED only when these actual UX criteria are proven.

No additional generic bug hunt, test factory, closure of all V9 epics, PIT training, full WCAG, all-page visual or performance gate is introduced.
