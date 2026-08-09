# F003 / F005 / V1 Auth Boundary Document

**Date:** 2026-08-09  
**Status:** **F003 COMPLETE** (`F003_COMPLIANT_WITH_NON_BLOCKERS`); **F005 READY_FOR_IMPLEMENTATION** (PD-006 not started)  
**Purpose:** Prevent scope bleed between V1 authentication, F003 invite onboarding, F005 session lifecycle, authorization primitives, and F060 collaboration.  
**Related:** [F003-USER-INVITE-SPEC.md](./F003-USER-INVITE-SPEC.md), [F005-SESSION-MANAGEMENT-SPEC.md](./F005-SESSION-MANAGEMENT-SPEC.md), [F003-F005-POLICY-DECISIONS.md](./F003-F005-POLICY-DECISIONS.md), [F003-F005-IMPLEMENTATION-GAP-MATRIX.md](./F003-F005-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Overview

Account & Access spans several responsibilities that share Sanctum infrastructure but must remain separately owned.

```text
┌─────────────────────────────────────────────────────────────────┐
│              V1 AUTH STACK (SD-001 / frozen baseline)            │
│   Sanctum SPA cookies · web session guard · CSRF · login/me     │
└───────────────────────────────┬─────────────────────────────────┘
                                │
         ┌──────────────────────┼──────────────────────┐
         │                      │                      │
         ▼                      ▼                      ▼
   ┌───────────┐          ┌───────────┐          ┌───────────┐
   │ V1 F004   │          │ V2 F003   │          │ V2 F005   │
   │ Password  │          │ User      │          │ Session   │
   │ reset     │          │ Invite    │          │ Mgmt      │
   └─────┬─────┘          └─────┬─────┘          └─────┬─────┘
         │                      │                      │
         ▼                      ▼                      ▼
   Existing-user          New-user               Authenticated
   credential             onboarding             device/session
   recovery               (invite-only)          lifecycle
```

**Cross-cutting (not F003/F005 ownership):**

| Concern | Owner |
|---------|--------|
| `is_admin` boolean | V1 admin authorization (shared) |
| Portfolio profile scoping (`X-Profile-Id`) | V1 multi-portfolio model |
| Shared screener import | F060 (Phase 3; depends on account clarity) |
| Fine-grained RBAC / multi-tenant orgs | **Out of scope** (MVP_SCOPE excludes advanced RBAC / multi-tenant identity) |

---

## 2. V1 authentication (frozen baseline)

### Responsibility

Establish and restore authenticated identity for an **existing** user via Sanctum SPA session cookies.

### Owns

| Capability | Notes |
|------------|-------|
| Login / logout (current session) | `AuthController` |
| CSRF cookie + CSRF token endpoint | Sanctum + `GET /api/auth/csrf-token` |
| `GET /api/auth/me` | Guest-safe |
| Remember-me cookie behaviour | Laravel `web` guard |
| Session store configuration | Database driver, lifetime, path, secure, SameSite |
| Auth audit (login success/failure, logout) | `AuthAuditService` |
| Login rate limit | `throttle:login` |

### Does **not** own

| Capability | Owner |
|------------|-------|
| Creating new users | F003 |
| Listing / revoking other devices | F005 |
| Admin password-reset links | F004 (V1) |
| Admin flag changes | User management (V1 admin ops; adjacent to F003 UI) |

### Primary code

`AuthController` (login/logout/me/csrf), `config/sanctum.php`, `config/session.php`, `AuthContext.jsx`, `LoginPage.jsx`.

---

## 3. V1 F004 — Password reset (frozen)

### Responsibility

Admin-issued recovery links for **existing** accounts (no current-password required on guest accept).

### Boundary vs F003

| | F004 | F003 |
|--|------|------|
| Audience | Existing user | New email with no account |
| Table | `portfolio_password_reset_links` | `portfolio_user_invites` |
| Outcome | Updates password; signs in | Creates user + default portfolio; signs in |
| Parallel UX | Copy link/message, regenerate, revoke | Same pattern |

F003 MUST NOT absorb F004; shared UX patterns are intentional reuse, not merged ownership.

---

## 4. F003 — User invite

### Responsibility

**Invite-only registration** — admins onboard new users; guests accept tokens and set credentials.

### Owns

| Capability |
|------------|
| Create / list / regenerate / revoke invites |
| Invite token lifecycle (issue, hash storage, expiry, accept, purge) — **PD-004** |
| Guest validate + accept APIs and SPA (`/invite/:token`) |
| Non-sensitive pending-invite login indication (`invite_setup_required` without token) — **PD-005** |
| Provision default portfolio on accept (first session; **no** PD-006 revoke requirement) |
| Admin invite UX (Settings → Manage users; Copy vs Regenerate URL) |

### Does **not** own

| Capability | Owner |
|------------|-------|
| Ongoing session list/revoke after accept | F005 |
| Password change for existing users | V1 profile; session side effects → F005 / PD-006 |
| Admin promotion (`is_admin`) | User management |
| Email transport infrastructure | None today (copy-paste delivery) — OUT_OF_SCOPE |
| Cross-user portfolio sharing | F060 |
| Returning invite tokens from `POST /auth/login` | **Forbidden** (PD-005) |

### Primary code

`UserInviteService`, `UserInvite` / `portfolio_user_invites`, `UserInviteController`, `InviteAcceptController`, `AcceptInvitePage.jsx`, `UserManagementPage.jsx`.

---

## 5. F005 — Session management

### Responsibility

**Authenticated multi-device session visibility and termination** for the current user.

### Owns

| Capability |
|------------|
| List sessions for the authenticated user |
| Revoke a non-current session |
| Logout all other sessions (manual) |
| Device/IP/last-activity presentation in Settings Account |
| Semantics of session row deletion vs current-session logout |
| **PD-006 automatic** revoke-others on authenticated password change and on F004 password-reset accept (keep current/new session; invalidate other-device remember-me) |

### Does **not** own

| Capability | Owner |
|------------|-------|
| Establishing a session on login/invite/reset accept | V1 auth / F003 / F004 |
| Idle session lifetime / cookie config | V1 session configuration |
| Invite or password-reset **tokens** | F003 / F004 |
| Admin force-logout of another user’s sessions | **DEFERRED** (PD-007) |
| F004 reset-link issuance UX | F004 (V1); F005 owns only the **session outcome** after accept |

### Primary code

`SessionManagementService`, `AuthController` session endpoints, Settings Account tab (`SettingsPage.jsx`).

---

## 6. Authorization primitives

| Primitive | Status | Owner |
|-----------|--------|-------|
| `portfolio_users.is_admin` | Shipped | V1 |
| `EnsureUserIsAdmin` (`admin` middleware) | Shipped | V1 |
| Portfolio profile binding | Shipped | V1 |
| Eloquent Policies / Gates / Spatie roles | **Absent** | OUT_OF_SCOPE for this initiative |
| Multi-tenant org model | **Absent** | OUT_OF_SCOPE |

F003 uses `admin` middleware for invite CRUD. F005 session APIs use `auth:sanctum` only (self-service).

---

## 7. F060 dependency

```text
F003 (invite / multi-user account creation)
  → clearer multi-user deployment posture
    → F005 (secure device lifecycle for those accounts)
      → F060 (shared screener import across users / portfolios)
```

| Claim | Verification |
|-------|----------------|
| Hard code dependency F003 → F005 | **Weak** — F005 works without invites; both share Sanctum |
| Soft product dependency | **True** — V2 planning treats Account & Access as one initiative; F060 deferred until account clarity |
| F060 requires F003 APIs | **No** — F060 uses screener `is_shared` + profile scoping today |
| Recommended order | F003 formalize/harden → F005 harden/tests → later F060 formal V2 |

---

## 8. Ownership answers

| Question | Answer |
|----------|--------|
| Who owns login cookies? | V1 auth |
| Who owns creating users? | F003 (invite accept) |
| Who owns recovering passwords? | F004 |
| Who owns “log out my phone”? | F005 (manual) |
| Who owns revoke-others after password change/reset? | F005 / PD-006 (automatic) |
| Who owns admin user list / `is_admin` toggle? | V1 user management (adjacent UI to F003) |
| Who owns sharing screeners? | F060 |
| Who owns RBAC matrices? | Nobody in V1/V2 Account initiative — OUT_OF_SCOPE |
| SMTP invites / multi-tenant orgs / admin force-logout? | OUT_OF_SCOPE or DEFERRED |

---

## 9. Anti-duplication rules

1. Do not re-implement login/logout inside F003 or F005 services.
2. Do not merge invite tokens and session IDs into one table.
3. Do not move F004 password-reset into F003.
4. Do not expand F005 into admin cross-user session tooling unless PD-007 is elevated.
5. Do not treat portfolio profiles as tenants for invite authorization.
6. Do not return invitation bearer tokens from normal login (PD-005).
7. Do not confuse F005 manual logout-others with PD-006 automatic revoke-on-credential-change.

---

## 10. Implementation must not expand into

- RBAC / Spatie permissions  
- Multi-tenant organizations  
- SMTP/email invitation delivery  
- Admin forced logout of arbitrary users (PD-007)  
- Shared screener F060  
- Unrelated authentication redesign (JWT, Bearer PAT login)

---

*End of boundary document.*
