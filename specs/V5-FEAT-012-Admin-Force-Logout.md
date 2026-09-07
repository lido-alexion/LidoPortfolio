# V5 FEAT-012 — Admin Force Logout of Other Users

**Status:** DECIDED / FROZEN  
**Date:** 2026-09-07

## Problem
V2 F005 deliberately deferred PD-007: administrators could not terminate another user's sessions. V5 now needs that operational security control without weakening the user's own session-management model or FEAT-042 role separation.

## Frozen behaviour
- FEAT-012 elevates PD-007 from deferred to V5 scope.
- The control is **Admin-only** and belongs in the FEAT-042 Admin application.
- An Admin may view a target Investor/user's active application sessions sufficiently to identify which sessions will be terminated.
- Admin may force logout:
  - one selected target session; or
  - all active sessions for the selected target user.
- The Admin's own session is unrelated and must remain authenticated.
- The action terminates application authentication sessions only. It does not delete the user, disable the account, change credentials, revoke invitations/password-reset tokens or manipulate broker/Kite sessions unless another feature explicitly does so.
- Forced logout invalidates the selected database-backed web session(s) so subsequent authenticated requests from them fail and fresh login is required.
- Multi-device sessions remain allowed; this feature provides administrative revocation, not a single-session policy.
- No Investor can access cross-user session APIs or UI.
- Admin cannot use FEAT-012 as an impersonation mechanism.
- Force-logout actions must be auditable with actor, target user, scope, affected session identifiers/count and timestamp while never storing raw cookies/secrets.
- Repeating an all-sessions action when none remain is safe/idempotent.
- A target session disappearing between display and action is handled safely and reported without affecting unrelated sessions.

## Relationship to V2 F005
- Existing user self-service session listing/revoke/logout-others remains unchanged.
- Existing PD-006 credential-change/password-reset revocation remains unchanged.
- Prefer extending the existing `SessionManagementService`/database-session infrastructure rather than creating a second session revocation stack.
- Existing session metadata rules (IP, user-agent/device, last activity, current-session semantics for the owner) remain the basis for Admin display where appropriate.

## Authorization and UX
- Admin application provides a user-level session view/action, likely from the relevant user-management surface.
- Destructive force-logout action requires clear confirmation identifying the target user and scope.
- Backend authorization is authoritative; direct Investor/API access is rejected.
- The target user's session list contains only that user's sessions.

## Acceptance criteria
1. Admin can list a selected user's active application sessions.
2. Admin can revoke one selected target session without revoking other sessions.
3. Admin can revoke all sessions for the selected target user.
4. A revoked target session fails subsequent authenticated requests and requires fresh login.
5. Admin's own session remains authenticated.
6. Investor cannot list or revoke another user's sessions via UI or direct API.
7. Existing F005 self-service session management and PD-006 behaviour continue to pass regression tests.
8. Force-logout produces durable audit evidence without raw authentication secrets.
9. Broker/Kite sessions, password-reset tokens and account status are not implicitly changed.

## Dependencies
- V1/V2 Sanctum stateful SPA authentication and database `sessions` table.
- V2 F005 Session Management / PD-006.
- FEAT-042 role-separated Admin application.

## Non-goals
- Admin impersonation.
- Account disable/lockout.
- Password reset/change.
- Kite/broker disconnect.
- MFA/session-policy redesign.
- Replacing user self-service F005 controls.
