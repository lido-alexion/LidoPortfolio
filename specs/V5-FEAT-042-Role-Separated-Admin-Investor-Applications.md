# V5 FEAT-042 — Separate Role-Based Admin Portal and Investor Application

**Status:** DECIDED / FROZEN  
**Date:** 2026-09-07

## 1. Problem
StoX currently needs a hard product and authorization boundary between administrative operation of the platform and an Investor's portfolio/trading application. Navigation-only hiding is insufficient: Admin accounts must not accidentally become investment-domain owners, and Investor accounts must not gain administrative capabilities through direct URLs or APIs.

## 2. Frozen outcome
- Retain a **single login/authentication entry point**.
- After authentication, route the account into a role-specific application shell.
- **Admin** sees the Admin application only.
- **Investor** sees the investment/portfolio application only.
- Separation is enforced server-side as well as in frontend routing/navigation.
- Direct URLs and APIs must enforce the same role boundary; UI hiding is not authorization.
- An Admin account cannot own or operate Investor-domain resources, including Portfolios, holdings/stocks, Strategies, Recommendations, broker/Kite connections or trading activity.
- An Investor account cannot access Admin-only data/actions.
- No account operates in both application roles in V5. Role switching/impersonation is not part of this feature.

## 3. Application boundaries

### Admin application
Admin surfaces contain platform/operator functions only, including existing or V5-defined administrative configuration and operational controls. Admin pages may read Investor-related information only where an explicitly administrative feature requires it; such access does not grant ownership or Investor-domain mutation rights.

### Investor application
Investor surfaces contain Portfolio, Strategy, holdings/transactions, Recommendations, trading/broker, Knowledge, simulation and other investment-domain workflows. Administrative navigation and APIs are unavailable.

## 4. Authorization
- Backend authorization/middleware/policies are authoritative.
- Every Admin-only endpoint must reject Investor access.
- Every Investor-domain endpoint must reject Admin use unless an explicit read-only administrative capability is separately specified by a feature.
- Frontend route guards and navigation reflect the backend boundary but do not replace it.
- Authentication/session infrastructure may remain shared.
- Existing account/user identity remains the authentication principal; role determines the permitted application domain.

## 5. Existing Admin-owned investment data
Before enforcing the invariant, implementation must inspect existing data for Admin accounts that own Investor-domain resources.

- Do not silently delete, orphan or reinterpret such data.
- Provide a migration/validation path that identifies every conflicting Admin-owned Portfolio/investment-domain record.
- Where an unambiguous existing Investor account can legally receive the data, migration may transfer it only through an explicit, auditable migration decision/process.
- Where ownership cannot be determined safely, deployment must report/block the affected invariant rather than guessing.
- Once migration is complete, database/application invariants must prevent new Admin-owned investment-domain state.

## 6. UX
- One login page remains.
- Successful login redirects according to role.
- Admin and Investor use distinct application shells/navigation appropriate to their role.
- Attempts to navigate to the other application's routes receive an appropriate forbidden/not-found/role-safe response and must not leak protected data.
- Logout/session expiry behaviour remains common unless another feature explicitly changes it.

## 7. Interaction with other V5 features
- FEAT-012 force logout is **Admin → Investor/user operational control** and belongs in the Admin application.
- FEAT-004 notifications preserve strict Admin/Investor audience separation.
- FEAT-038 Admin exchange-calendar configuration belongs to Admin; Investor consumes resulting calendar behaviour where relevant.
- FEAT-039/040 Kite execution/reconciliation are Investor-domain functionality; Admin does not own/connect Kite for an Investor.
- FEAT-007/008 administrative catalogue/configuration surfaces that are explicitly Admin-owned belong in Admin; Investor-facing artifact library/bindings remain Investor-domain according to their frozen specs.
- FEAT-020 Paper/Replay/Backtest are Investor-domain functionality.
- V6 Admin Audit Explorer will be an explicit read-only Admin capability over audit evidence; it does not weaken the V5 ownership separation.

## 8. Acceptance criteria
1. There remains exactly one authentication/login entry point.
2. Admin login lands in the Admin application; Investor login lands in the Investor application.
3. Admin cannot access Investor application routes/APIs merely by entering their URLs.
4. Investor cannot access Admin routes/APIs merely by entering their URLs.
5. Admin cannot create/own Portfolios, holdings, Strategies, Recommendations, Kite connections, Orders/Trades or other Investor-domain state.
6. Existing Admin-owned investment data is detected and handled through explicit migration/validation; no silent deletion or guessed reassignment occurs.
7. Role authorization is covered by backend tests, including direct API attempts.
8. Frontend navigation/routes expose only the applicable application shell.
9. Shared login/logout/session behaviour continues to work after separation.
10. Cross-feature Admin/Investor surfaces follow the ownership rules above.

## 9. Non-goals for V5
- Admin impersonation of Investors.
- Role switching within a logged-in session.
- Dual Admin+Investor operating mode for one account.
- Admin mutation of an Investor's Portfolio/trading state.
- Separate authentication systems or separate login pages.
- V6 Audit Explorer implementation.

## 10. Implementation instruction
This feature is **not blocked on further Product Owner design**. Engineering should inspect the current role/auth/navigation/data model, implement the separation above non-destructively, add migration/invariant checks and tests, and escalate only if existing production data presents an ownership ambiguity that cannot be resolved safely from repository/domain evidence.
