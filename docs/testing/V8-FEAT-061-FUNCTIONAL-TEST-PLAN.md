# FEAT-061 guided tour functional test plan

**Implementation state:** IMPLEMENTED on StoX V8.  
**Functional testing state:** Open.  
**Contract:** [Frozen guided tour specification](../archive/specs/V8-Guided-Tour-Welcome-Onboarding-Specification.md).  
**Evidence:** [FEAT-061 acceptance audit](../audit/V8-FEAT-061-ACCEPTANCE-AUDIT.md).

The guided tour, persistence, Developer options reset and access boundaries are deployed. Local Chromium, unit, API and accessibility suites passed. Production evidence covers a persisted resume/Back/Next/Escape slice, Admin UI exclusion, reset-route Admin 403 and Investor payload rejection, and a direct Investor reset response. The full current Investor browser journey remains to be run. Use a disposable Investor account or a user-authorized reset; do not reset existing accounts in a CLI test harness.

| ID | Scenario | Pass criteria | Current state |
|---|---|---|---|
| FT-061-01 | Fresh eligible Investor logs in, sees welcome, begins, traverses all eight configured steps across routes, presses Finish, refreshes and relaunches manually. | Only explicit Finish completes; persisted state and route/step order match spec; Admin has no launcher. | **PARTIAL:** local browser 11/11 and prior production steps 7–8 passed; full deployed first-run is open. |
| FT-061-02 | Set `localStorage.setItem('devOptions', 'true')`, reload, open the top-left Developer options icon and reset the guided tour; then remove the key with `localStorage.removeItem('devOptions')`. | Icon is shown only for exact `true`; modal calls own-user reset, shows success/error, and the welcome prompt is restored after reload. Removing key hides icon. | **PARTIAL:** local UI/API tests passed; deployed route exists; direct Investor reset returned 200. A full deployed browser click flow is open. The key is a discoverability gate, not authorization. |
| FT-061-03 | Try unauthenticated, Admin and Investor requests with a target identity/body field against reset API. | 401/403/422 respectively; no other user's state changes. | **PARTIAL:** deployed Admin 403 with unchanged state and Investor body 422 observed; unauthenticated path and full browser session matrix open. |
| FT-061-04 | Interrupt an in-progress tour by refresh, navigation, session expiry and signing in again; test Resume/Restart/Skip/Don't show again. | Saved step resumes safely; prompt cap, dismissal and manual relaunch behave as configured. | **PARTIAL:** local refresh and deployed manual resume at step 7 passed; real production expiry/interruption is open. |
| FT-061-05 | Exercise mobile/tablet, keyboard-only and native screen-reader journeys, including missing target, scrim, focus/announcements and Escape. | Panel stays in viewport; no background activation; dialog labels and focus work; skipped targets never mark completion. | **PARTIAL:** local desktop/tablet/mobile Chromium and axe passed; native device/screen-reader acceptance open. |
| FT-061-06 | Confirm telemetry or API failure does not block ordinary navigation and restart the journey. | Tour errors remain bounded, recoverable and do not break product navigation. | **OPEN for deployed failure conditions.** |

Record build, account type (no personal identifiers), device/browser, step and route, expected/actual result, and screenshots with sensitive data hidden. A failed functional scenario remains a defect to triage even while implementation is marked complete. The temporary Developer options UI/API must be removed during the planned public-hardening work described in the specification.
