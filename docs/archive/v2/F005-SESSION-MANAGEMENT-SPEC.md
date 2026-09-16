# F005 — Session Management

**Date:** 2026-08-09  
**Status:** **COMPLETE** (`F005_COMPLETE_WITH_NON_BLOCKERS`) — PD-006 delivered; manual F005 preserved  
**V2 initiative:** Account & Access Management  
**Classification:** Deferred from V1 by SD-035; mostly implemented in code  
**Related:** [F003-F005-BOUNDARY.md](./F003-F005-BOUNDARY.md), [F003-F005-POLICY-DECISIONS.md](./F003-F005-POLICY-DECISIONS.md), [F003-F005-IMPLEMENTATION-GAP-MATRIX.md](./F003-F005-IMPLEMENTATION-GAP-MATRIX.md), [F003-USER-INVITE-SPEC.md](./F003-USER-INVITE-SPEC.md)

---

## 1. Purpose

Define authoritative V2 requirements for **authenticated users to view and terminate their browser sessions** (devices), on top of the frozen V1 Sanctum SPA authentication stack.

F005 does not replace login/logout, CSRF, or session cookie configuration owned by V1 auth.

---

## 2. Scope

### In scope

- Listing the current user’s sessions
- Identifying the current session
- Revoking a non-current session
- Logging out all other sessions
- Settings Account “Active sessions” UI
- Ownership checks (no cross-user revoke)
- Relationship to logout of the current session

### Out of scope

- Changing Sanctum SPA to Bearer PAT / JWT login
- Refresh-token protocol
- Admin force-logout of other users (deferred — PD-007)
- Broker/live-trading “session” concepts in strategy-engine docs
- Invite / password-reset token lifecycle (F003 / F004)

---

## 3. Existing V1 behaviour

| Item | Status |
|------|--------|
| Sanctum stateful SPA cookies | V1 (SD-001) |
| Database `sessions` table | V1 infrastructure |
| Login / logout / me / CSRF | V1 |
| Session idle lifetime (~30 days default) | V1 configuration |
| F005 list/revoke | **Shipped** API + Settings UI; **formally deferred from V1** |

Feature-coverage audits marked F005 `PARTIALLY_IMPLEMENTED` citing partial UI. Repository inspection (2026-08-09) shows Settings Account **Active sessions** (list, revoke, logout others) is present — treat “partial UI” as **historical**, not current.

---

## 4. V2 delta

1. Formal specification and acceptance criteria
2. Strengthen automated tests (especially single-session revoke / foreign id)
3. **Implement PD-006** — revoke other sessions on password change/reset; keep current/new session — **DONE**
4. Help/docs sync (**PD-013** = documentation task, not a product decision) — **DONE**

Not a greenfield build. Delivery status: **COMPLETE** (`F005_COMPLETE_WITH_NON_BLOCKERS`).

---

## 5. Actors

| Actor | Capabilities |
|-------|----------------|
| Authenticated user | List own sessions; revoke own non-current; logout others; logout current |
| Admin | Same self-service only (no extra session powers today) |
| Guest | No session management APIs |

---

## 6. Session lifecycle

```text
Login / invite accept / password-reset accept
    → web guard authenticates
    → session row in `sessions` (user_id, ip, ua, payload, last_activity)
    → optional logged_in_at in payload
        → idle until SESSION_LIFETIME
        → user logout current → invalidate session
        → user revoke other row → delete sessions row
        → user logout-others → delete all other rows
        → password change (PD-006) → keep current; revoke others + invalidate other remember-me
        → password-reset accept (PD-006) → keep new session; revoke other pre-existing + invalidate other remember-me
```

Multi-session: **allowed** (laptop + phone) until credential change/reset or manual F005 revoke.

---

## 7. Authentication / token semantics

| Mechanism | Used for app auth? |
|-----------|--------------------|
| Sanctum SPA + `web` session cookies | **Yes** |
| Database session store | **Yes** |
| Sanctum personal access tokens | **No** (table retained; login does not `createToken`) |
| JWT / localStorage bearer | **No** (removed) |

Proof points: `bootstrap/app.php` `statefulApi()`; `AuthController::login` returns `{ user }` only; `AuthSessionTest` asserts missing `token`; `SessionManagementService` queries `sessions` table.

---

## 8. Session listing

`GET /api/auth/sessions` returns rows for `user_id = auth user`, ordered by `last_activity` desc.

Per row:

| Field | Meaning |
|-------|---------|
| `id` | Session id |
| `ip_address` | Recorded IP |
| `device` | Heuristic label from UA |
| `user_agent` | Raw UA |
| `is_current` | Matches `$request->session()->getId()` |
| `last_activity` | ISO time |
| `login_time` | From payload `logged_in_at` when present |

---

## 9. Session termination

| API | Effect |
|-----|--------|
| `DELETE /api/auth/sessions/{sessionId}` | If current → full logout; else delete owned row; else 422 + suspicious audit |
| `POST /api/auth/sessions/logout-others` | Delete all other rows for user; current remains |

After non-current revoke, subsequent requests presenting that session cookie MUST fail authentication (session row gone / invalid).

---

## 10. Logout

| Path | Owner |
|------|-------|
| `POST /api/auth/logout` | V1 auth (invalidate current) |
| DELETE current session id via sessions API | F005 delegates to same logout behaviour |

F005 MUST NOT invent a second logout stack.

---

## 11. Expiration

| Concern | Owner / behaviour |
|---------|-------------------|
| Idle timeout | V1 `SESSION_LIFETIME` (default 43200 minutes) |
| Expire on browser close | V1 `SESSION_EXPIRE_ON_CLOSE` (default false) |
| Sanctum PAT TTL | N/A (unused) |
| Refresh tokens | None |

F005 documents but does not redefine idle timeout unless product opens a new decision (none required today).

---

## 12. Authorization

| Rule | Enforcement |
|------|-------------|
| Must be authenticated | `auth:sanctum` |
| May only mutate own sessions | `where user_id = auth id` |
| Admin bypass to other users | **Not provided** |

Portfolio middleware may run on the route group; session rows are account-scoped, not profile-scoped.

---

## 13. Security semantics

| Topic | Current (pre-hardening) | V2 stance |
|-------|-------------------------|-----------|
| Cross-user session revoke | Scoped by user_id | MUST be impossible via F005 APIs |
| Session fixation | Regenerates on login/accept | V1 behaviour retained |
| CSRF | Sanctum SPA + CSRF token | V1 behaviour retained |
| Password change vs sessions | Other sessions preserved | **PD-006:** revoke all other sessions; keep current; invalidate remember-me for other devices |
| Password-reset accept vs sessions | Other sessions preserved | **PD-006:** revoke all other pre-existing sessions; keep newly established session; invalidate remember-me for other devices |
| Invite accept vs sessions | First session for new user | No PD-006 revocation requirement (F003) |
| Manual vs automatic revoke | F005 logout-others only | F005 = manual; PD-006 = automatic on credential change/reset |
| Audit | Logout others / remote revoke logged | Retain `AuthAuditService` |
| Metadata disclosure | Own IP/UA | DECIDED PD-008 |

### Credential-change session semantics (PD-006)

**Password change** (`PUT /api/profile/password`): after successful update — (1) current session remains authenticated; (2) every other session for that user is revoked; (3) remember-me credentials for other devices are invalidated; (4) user is not required to use F005 “Log out other devices” as a follow-up step.

**Password reset** (F004 accept): after new password + new session — (1) newly established session remains authenticated; (2) all other pre-existing sessions for that user are revoked; (3) remember-me for other devices invalidated. F004 link/API ownership unchanged; F005/PD-006 define the session-security outcome.

**Invitation acceptance:** out of PD-006 revocation scope (first account session).

---

## 14. API behaviour

| Method | Path | Auth |
|--------|------|------|
| GET | `/api/auth/sessions` | auth |
| POST | `/api/auth/sessions/logout-others` | auth |
| DELETE | `/api/auth/sessions/{sessionId}` | auth |
| POST | `/api/auth/logout` | auth (V1) |

---

## 15. UI behaviour

| Surface | Behaviour |
|---------|-----------|
| Settings → Account (`/settings/account`) | Active sessions list; Log out other devices; per-row Revoke / Log out |
| Profile menu | Logout current (V1) |
| Profile password form | Changes password; **target (PD-006):** other devices signed out automatically; current device stays signed in |

---

## 16. Error semantics

| Case | Expected |
|------|----------|
| Unauthenticated sessions API | 401 |
| Unknown / foreign session id revoke | 422 “Session not found” + suspicious audit |
| Logout-others with only current | 200 with `sessions_removed` 0 |

---

## 17. Idempotency

| Action | Semantics |
|--------|-----------|
| Logout-others twice | Second removes 0 |
| Revoke already-deleted id | 422 not found |
| Logout current twice | Second unauthenticated |

---

## 18. Auditability

`AuthAuditService` records logout with scope `current` | `others` | `remote`. Masked identifiers; no raw cookies in logs.

---

## 19. Requirements

### MUST (preserve shipped F005 + PD-006)

| ID | Requirement |
|----|-------------|
| F005-R001 | Authenticated users SHALL be able to list their own sessions with current-session identification. |
| F005-R002 | Authenticated users SHALL be able to terminate all sessions other than the current session in one action. |
| F005-R003 | Authenticated users SHALL be able to terminate a specific non-current session they own. |
| F005-R004 | Terminating the current session via the sessions API SHALL invalidate the current authentication equivalently to logout. |
| F005-R005 | Users SHALL NOT be able to terminate sessions belonging to another user_id. |
| F005-R006 | The product SHALL continue to allow multiple simultaneous sessions per user. |
| F005-R007 | Session list payloads SHALL include IP and a human-readable device summary derived from user-agent. |
| F005-R008 | Settings Account UI SHALL expose list, per-session revoke, and logout-others controls. |
| F005-R009 | App authentication SHALL remain Sanctum SPA session cookies (not Bearer PAT login). |
| F005-R010 | After a successful authenticated password change, the current session SHALL remain authenticated, all other sessions for that user SHALL be revoked, and remember-me authentication credentials for other devices SHALL be invalidated. |
| F005-R011 | After a successful F004 password-reset acceptance that establishes a new session, that newly established session SHALL remain authenticated, all other pre-existing sessions for that user SHALL be revoked, and remember-me authentication credentials for other devices SHALL be invalidated. |
| F005-R016 | Manual F005 session listing and revocation SHALL remain available independently of PD-006 automatic revocation; PD-006 SHALL NOT replace F005 logout-others. |

### SHOULD

| ID | Requirement |
|----|-------------|
| F005-R012 | Feature tests SHOULD cover single-session revoke, foreign session reject, and current-session logout via DELETE. |
| F005-R013 | Contextual help SHOULD describe Active sessions controls. |

### MUST NOT

| ID | Requirement |
|----|-------------|
| F005-R014 | F005 SHALL NOT reintroduce localStorage bearer tokens for login. |
| F005-R015 | F005 SHALL NOT implement admin cross-user session termination unless PD-007 is elevated from DEFERRED. |

---

## 20. Acceptance criteria

| ID | Criterion |
|----|-----------|
| F005-AC001 | Given an authenticated user with a session, `GET /api/auth/sessions` returns at least one row with `is_current=true` matching the caller session. |
| F005-AC002 | Given two sessions for the same user, `POST /api/auth/sessions/logout-others` from session A leaves A authenticated and causes session B’s cookie to fail subsequent authenticated requests. |
| F005-AC003 | Given session B owned by the user, `DELETE /api/auth/sessions/{B}` from session A returns success and B can no longer authenticate. |
| F005-AC004 | Given session C owned by another user, `DELETE /api/auth/sessions/{C}` from user A returns a validation/authorization failure and does not delete C. |
| F005-AC005 | Given the current session id, `DELETE /api/auth/sessions/{current}` results in the caller being unauthenticated afterward. |
| F005-AC006 | Login JSON SHALL NOT include an API `token` field for SPA auth. |
| F005-AC007 | Given two sessions for the same user, after a successful `PUT /api/profile/password` from session A, session A remains authenticated and session B can no longer authenticate subsequent requests. |
| F005-AC008 | Given a pre-existing session B for a user, after successful password-reset accept that establishes session A, session A remains authenticated and session B can no longer authenticate subsequent requests. |
| F005-AC009 | After a successful authenticated password change, remember-me authentication for other devices of that user MUST no longer establish an authenticated session without a fresh password login (or equivalent re-authentication). |

---

## 21. Non-goals

- Redis/file session driver migration
- Device push notifications on new login
- Step-up MFA / 2FA (future-compatible, not specified here)
- Admin security console for all users’ sessions

---

## 22. Dependencies

| Depends on | Why |
|------------|-----|
| V1 Sanctum + database sessions | Session rows and auth |
| User model | `user_id` on sessions |
| F004 (V1) | Password-reset accept must apply PD-006 session outcome |
| F003 (initiative order) | Soft — multi-user accounts; invite accept has no PD-006 revoke requirement |

| Unlocks | Why |
|---------|-----|
| Safer multi-device use | Users can drop stolen/lost device sessions |
| F060 readiness (soft) | Account hygiene for collaboration users |

---

## 23. Open decisions

**None** for product behaviour. See [F003-F005-POLICY-DECISIONS.md](./F003-F005-POLICY-DECISIONS.md):

- **PD-006** — **DECIDED** (OPTION_B)
- **PD-012** — **RESOLVED_BY_PD-006**
- **PD-007** — **DEFERRED** (admin force-logout)
- **PD-013** — **NOT_A_POLICY_DECISION** (help sync during hardening)

Initiative delivery: **COMPLETE** (`F005_COMPLETE_WITH_NON_BLOCKERS`).

---

## 24. Implementation notes

- Primary service: `App\Services\SessionManagementService` — `destroyOtherSessions`, `revokeOtherSessionsForCredentialChange`, `invalidateRememberToken`
- Controllers: `AuthController` session methods; `ProfileController::updatePassword`; F004 `PasswordResetAcceptController::accept` (session outcome only)
- Remember-me: rotate `users.remember_token` (single column per user) so outstanding remember cookies stop working; surviving session uses session cookie
- UI: `SettingsPage.jsx` Account tab (manual F005); `ProfilePage.jsx` password form (automatic PD-006 messaging)
- Tests: `AuthSessionTest` / `ProfileTest` / `PasswordResetLinkTest` / invite regression in `UserInviteTest`
- Keep F005 distinct from invite tokens and from broker session docs
- Non-blockers: no FE automated UI tests; PHPUnit DELETE-current cookie stickiness under Sanctum test client

---

*End of F005 specification.*
