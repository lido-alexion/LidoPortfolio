# F003 — User Invite

**Date:** 2026-08-09  
**Status:** **COMPLETE** (`F003_COMPLIANT_WITH_NON_BLOCKERS`) — PD-004 / PD-005 hardened; compliance audit closed  
**V2 initiative:** Account & Access Management  
**Classification:** Deferred from V1 by SD-035; formally specified and hardened in V2  
**Related:** [F003-F005-BOUNDARY.md](./F003-F005-BOUNDARY.md), [F003-F005-POLICY-DECISIONS.md](./F003-F005-POLICY-DECISIONS.md), [F003-F005-IMPLEMENTATION-GAP-MATRIX.md](./F003-F005-IMPLEMENTATION-GAP-MATRIX.md), [F005-SESSION-MANAGEMENT-SPEC.md](./F005-SESSION-MANAGEMENT-SPEC.md)  
**Note:** F005 / PD-006 session revocation is a separate initiative (not part of F003 closure); F005 is now **COMPLETE**.

---

## 1. Purpose

Define authoritative V2 requirements for **invite-only user onboarding**: how administrators invite new users, how invitees accept invitations, and which security and authorization semantics are in scope.

F003 does not redefine V1 Sanctum authentication (login for existing users) or V1 F004 password reset.

---

## 2. Scope

### In scope

- Admin create / list / regenerate / revoke invitations
- Guest validate and accept invitation
- Token expiry, purge, and single-pending-email rules
- Copy-paste invite delivery (URL + composed message)
- Login interaction when a pending invite exists for an email
- Default portfolio provisioning on accept
- Admin-only authorization for invite management

### Out of scope

- Open self-registration
- SMTP / Telegram invite delivery (unless product reopens PD-001)
- Multi-tenant organizations / fine-grained RBAC
- Session list/revoke after login (F005)
- Changing `is_admin` (user management)
- F060 shared screener rules

---

## 3. Existing V1 behaviour

| Item | Status |
|------|--------|
| Sanctum SPA session auth | V1 (SD-001) |
| F004 admin password-reset links | V1 (SD-035) |
| `is_admin` middleware | V1 |
| F003 invite flow | **Shipped in code** but **formally deferred from V1** (SD-035 / MVP_SCOPE) |

V1 product freeze does **not** mean the invite code is absent — it means invite is not a frozen V1 *requirement*. V2 formalizes and hardens it.

---

## 4. V2 delta

V2 work for F003 was:

1. **Formal specification** (this document) and gap matrix
2. **Implement PD-004** — hashed invite tokens + explicit token-rotation UX (**DECIDED**; **delivered**)
3. **Implement PD-005** — separate login vs invitation flows; login must not return invite tokens (**DECIDED**; **delivered**)
4. **F005 / PD-006** owns credential-change session revocation (password change/reset); F003 invite accept has **no** PD-006 revocation requirement — **delivered under F005**
5. Invite help/docs sync during F003 hardening (**delivered** for Users topic); Active-sessions help **delivered** under F005-G014 / PD-013

**Current delivery:** F003 hardening and final compliance audit closed as `F003_COMPLIANT_WITH_NON_BLOCKERS`. Documented non-blockers (test gaps, one-way reset/invite collision check, regenerate-on-expired UX) do not reopen MUST requirements.

---

## 5. Actors

| Actor | Capabilities |
|-------|----------------|
| Admin (`is_admin=true`) | Create, list, regenerate (rotate), revoke invites; **Copy Invitation URL** on issue; confirmed **Regenerate Invitation URL** later |
| Invitee (guest) | Validate token; set name/password; accept → becomes User |
| Authenticated non-admin | No invite management (403) |
| System | Purge expired unaccepted invites on relevant operations |

---

## 6. Invitation lifecycle

```text
Admin create
    → pending (store token HASH only; expires_at = now+72h; show raw URL once)
        → regenerate (confirmed) → new token; replace hash; old URL invalid; expires_at UNCHANGED
        → revoke → row deleted
        → expire → purged on access / purgeExpired
        → accept (hash submitted raw token; match + pending + unexpired) → user created, session established
```

| Question | Answer (V2 target / PD-004) |
|----------|------------------------------|
| Who can invite? | Admins only |
| Who can be invited? | Emails with no existing `portfolio_users` row |
| Email-based? | Yes (identity key); delivery is copy-paste, not SMTP |
| Token generated? | Cryptographically secure random; **hash** stored; raw only at create/regenerate response |
| Validity | 72 hours from **create** (PD-002); rotation does **not** extend expiry |
| Can expire? | Yes |
| Can revoke? | Yes, if not accepted |
| Later URL? | **Regenerate Invitation URL** (rotation) — cannot reconstruct old URL |
| Multiple pending same email? | No — regenerate replaces credential on the same pending row |
| Email already a user? | Create fails; accept deletes invite and errors |
| On accept? | Hash-compare token; create user (non-admin), default portfolio, mark accepted, establish **first** authenticated session |
| PD-006 session revoke on accept? | **No** — new user; no prior sessions expected; do not add unnecessary revoke logic |
| Invalid/expired/old-rotated? | Reject; no user create; do not reveal that a prior token once existed |

---

## 7. Authorization

| Operation | AuthZ |
|-----------|-------|
| `GET/POST /api/invites`, regenerate, delete | `auth:sanctum` + `admin` |
| `GET /api/invites/{token}`, `POST /api/invites/accept` | Guest + `throttle:login` |
| Who sees pending invites? | Admins (full list) |
| Portfolio/tenant boundary | Invites are **global to the deployment**, not per portfolio profile |

There is no tenant table. Portfolio profiles are per-user data scopes created after accept.

---

## 8. Data model

Table: `portfolio_user_invites` (migration `2026_06_28_000001`).

| Column | Role |
|--------|------|
| `id` | PK |
| `email` | Normalized lowercase invitee email |
| `token` | **SHA-256 hash** of invite secret (column kept as `token`; no rename). Raw token never persisted. |
| `invited_by_user_id` | FK admin |
| `expires_at` | Expiry (**set on create**; not extended by token rotation) |
| `accepted_at` | Null until accepted |
| `user_id` | Accepted user FK (nullable) |
| timestamps | created/updated |

Indexes: unique on stored token/hash value; `(email, accepted_at)`; `expires_at`.  
No DB unique on pending email — enforced in `UserInviteService`.

Statuses (computed): `pending` | `expired` | `accepted`.

**Persistence rule (PD-004):** plaintext invitation token MUST NOT be persisted. Admin list endpoints MUST NOT return a reconstructable invitation URL after the raw token has left the create/regenerate response.

---

## 9. API behaviour

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/invites` | Admin list; **must not** re-materialize raw URL from hash (PD-004) |
| POST | `/api/invites` | Body `{ email }` → create; response includes one-time raw `invite_url` / message |
| POST | `/api/invites/{invite}/regenerate` | Confirmed rotation: new token, replace hash, return new URL; **must not** reset `expires_at` |
| DELETE | `/api/invites/{invite}` | Revoke pending |
| GET | `/api/invites/{token}` | Guest validate — lookup by hashing submitted token |
| POST | `/api/invites/accept` | `{ token, name?, password, password_confirmation, remember? }` — hash-compare |

Related (V1 auth boundary + **PD-005**): `POST /api/auth/login` for a pending-invite email MUST NOT return `invite_token`. It MAY return non-sensitive `invite_setup_required` + a message to use the administrator invitation link. Registered-user password login remains unchanged.

---

## 10. UI behaviour

| Surface | Behaviour |
|---------|-----------|
| `/settings/users` — **create** | Show active invitation URL once; **Copy Invitation URL** (no regenerate); tell admin to copy/save now; warn that regenerating later invalidates the URL |
| `/settings/users` — **pending row** | Advance warning that regenerating invalidates the current URL; action labeled **Regenerate Invitation URL** (not a silent copy); confirm dialog before rotate; then show new URL + copy |
| `/invite/:token` | Guest set password (and optional name); accept; signed in |
| Login | On pending-invite response: show administrator-link message; **do not** auto-navigate using a login-returned token (**PD-005**) |

Registration from AuthContext remains invite-only (no open register). Delivery remains **copy-paste** (PD-001). Lost URL → admin **Regenerate Invitation URL** (PD-004); login must not retrieve or rotate tokens.

---

## 11. Security semantics

*Columns below retain the **historical pre-hardening** snapshot (left) vs the **authoritative V2 stance** (right). Post-hardening delivery matches the V2 stance (see Status header).*

| Topic | Historical (pre-hardening) | V2 stance (PD-004 / PD-005) — now delivered |
|-------|----------------------------|---------------------------------------------|
| Token entropy | `Str::random(64)` | MUST use cryptographically secure random generation |
| Storage | Plaintext | MUST store **hash only**; plaintext MUST NOT be persisted |
| Admin exposure | URL always available from stored plaintext | Raw URL only at create/confirmed regenerate; later access = **token rotation** |
| Rotation | Regenerates token (and previously reset expiry) | MUST replace hash; invalidate previous URL immediately; MUST NOT extend `expires_at` |
| Accept lookup | Equality on plaintext token | Hash submitted token; compare to stored hash |
| Old token after rotate | Invalid after new token stored | MUST fail like any invalid token; no special disclosure |
| Expiry window | 72h (create; code also on regenerate) | 72h from create (PD-002); rotation does not extend |
| Replay after accept | Accept rejected | MUST |
| Replay after revoke/expiry | Token unknown | MUST |
| Throttle | Guest routes `throttle:login` | MUST retain |
| Email enumeration (admin) | Explicit errors | Allowed for admin (PD-010) |
| Login token disclosure | Raw token without password (pre-hardening) | **MUST NOT** return invitation token (**PD-005**) |
| Knowing invitee email alone | Sufficient to obtain token via login (pre-hardening) | MUST NOT be sufficient to obtain the bearer credential |
| Invite credential issuance | Create / list re-copy / regenerate | Create + confirmed regenerate only (PD-004); never via login |
| Cross-user IDOR on admin invite id | Admin-only routes | MUST retain admin gate |

---

## 12. Error semantics

| Case | Expected |
|------|----------|
| Non-admin manage invites | 403 |
| Duplicate pending email | 422 validation |
| Email already registered | 422 validation |
| Invalid / expired / previously rotated token accept | 422 with generic contact-admin / invalid message; **no** user create; do not reveal prior-token history |
| Already accepted token | 422 sign-in message |
| Guest GET expired | Treated as invalid (purge; not valid / 410 path per controller) |
| Login with pending invite email | Unauthenticated; no `invite_token`; MAY `invite_setup_required` + admin-link message (**PD-005**) |

---

## 13. Idempotency

| Action | Semantics |
|--------|-----------|
| Accept twice | Second fails; user already exists / invite accepted |
| Create twice same email while pending | Second fails |
| Regenerate | Not idempotent — intentionally rotates secret |
| Revoke twice | Second fails (missing) |

---

## 14. Auditability

| Event | Current |
|-------|---------|
| Accept → login | `AuthAuditService` login success via accept controller |
| Invite create/revoke | No dedicated invite audit table (ops via admin UI + DB rows) |

V2_SHOULD: retain at least Auth audit on accept login; dedicated invite audit log is not required unless product elevates it (not invented as MUST).

---

## 15. Requirements

### MUST (baseline + PD-004 + PD-005)

| ID | Requirement |
|----|-------------|
| F003-R001 | Only administrators SHALL create, list, regenerate, or revoke user invites. |
| F003-R002 | Open self-registration SHALL remain unavailable. |
| F003-R003 | An invite SHALL bind to a single normalized email address. |
| F003-R004 | At most one pending (non-accepted, non-expired) invite SHALL exist per email. |
| F003-R005 | Creating an invite for an email that already has a user account SHALL fail without creating a row. |
| F003-R006 | Creating an invite SHALL issue a cryptographically secure random token, persist **only its hash**, set `expires_at` to approximately 72 hours ahead, and return/display the invitation URL built from the raw token once. |
| F003-R007 | Expired unaccepted invites SHALL be purged such that their tokens cannot be accepted. |
| F003-R008 | Guests SHALL be able to validate a pending token (via hash lookup) and learn the invited email and expiry without authenticating. |
| F003-R009 | Accepting a valid pending invite SHALL create a non-admin user, create a default portfolio profile, mark the invite accepted, and establish an authenticated session. |
| F003-R010 | Accepting an invalid, expired, already-accepted, or previously rotated invite token SHALL fail without creating a user. |
| F003-R011 | On initial create, admins SHALL be offered a **Copy Invitation URL** action for the just-issued URL without performing regeneration. |
| F003-R012 | Invite management SHALL NOT require SMTP. |
| F003-R013 | Guest invite endpoints SHALL remain rate-limited consistently with login throttling. |
| F003-R014 | Invitation tokens SHALL be stored hashed at rest; plaintext tokens MUST NOT be persisted; raw tokens exist only when initially generated or after confirmed regeneration. |
| F003-R015 | `POST /api/auth/login` SHALL NOT return an invitation token, invitation URL, or any other usable invitation bearer credential. |
| F003-R017 | Regenerating an invitation URL SHALL generate a new cryptographically secure token, replace the stored hash, invalidate the previous token/URL immediately, and return/display the new URL; it SHALL NOT create a second pending invite for the same email. |
| F003-R018 | Token regeneration SHALL NOT change `expires_at` (shall not extend or reset the original invitation expiry window). |
| F003-R019 | Admin UI for later URL access SHALL use **Regenerate Invitation URL** (or equivalent rotation wording), show a prominent invalidation warning before and ahead of the action, and require explicit confirmation before rotating. |
| F003-R020 | A login attempt for an email with a pending invitation SHALL NOT authenticate the caller, SHALL NOT create a session, and SHALL NOT perform password verification against a registered account (none exists yet). |
| F003-R021 | A login attempt for an email with a pending invitation MAY return a non-sensitive indication such as `invite_setup_required` and a message directing the user to the administrator-provided invitation link; it MUST NOT silently regenerate or rotate the invitation token. |
| F003-R022 | The login UI SHALL NOT depend on receiving an invitation token from `POST /api/auth/login` and SHALL NOT auto-navigate to an invitation acceptance route using login response data as the bearer credential. |
| F003-R023 | Normal registered-user login (password verification and session establishment) SHALL remain unchanged by F003 invitation policies. |
| F003-R024 | Invitation acceptance SHALL NOT be required to revoke other sessions under PD-006; it establishes the first session for a newly created user and MUST NOT add unnecessary session-revocation behaviour. |

### SHOULD

| ID | Requirement |
|----|-------------|
| F003-R016 | Invite token generation SHOULD avoid colliding with outstanding password-reset token material (hash/lookup domain as applicable). |

---

## 16. Acceptance criteria

| ID | Criterion |
|----|-----------|
| F003-AC001 | Given an admin session, `POST /api/invites` with a new email returns 201 and a pending invite payload including an invite URL built from the just-generated raw token. |
| F003-AC002 | Given a non-admin session, `POST /api/invites` returns 403. |
| F003-AC003 | Given a pending invite, guest `GET /api/invites/{token}` with the raw token returns valid with that email. |
| F003-AC004 | Given a pending invite, guest accept with password creates a `portfolio_users` row, a default `portfolio_profiles` row, sets `accepted_at`, and authenticates the new user. |
| F003-AC005 | Given an accepted invite token, a second accept fails and does not create another user. |
| F003-AC006 | Given a pending invite, confirmed regenerate issues a new URL such that the previous raw token cannot be accepted and the new raw token can. |
| F003-AC007 | Given a pending invite, revoke deletes it such that its prior token cannot be accepted. |
| F003-AC008 | Given expiry in the past, accept fails and the invite is not usable. |
| F003-AC009 | Given an existing user email, create invite fails with a validation error. |
| F003-AC010 | Given a pending invite for an email, a second create for that email fails until regenerate/revoke of that pending row. |
| F003-AC011 | After create (or regenerate), the persisted invite record MUST NOT contain the plaintext raw token (only a hash or equivalent non-reversible form). |
| F003-AC012 | After regenerate, accepting with the **previous** raw token fails without creating a user and without disclosing that the token was formerly valid. |
| F003-AC013 | Regenerating a pending invite MUST leave `expires_at` unchanged from its pre-regeneration value. |
| F003-AC014 | Admin invitation UI MUST expose **Copy Invitation URL** on initial issuance without rotating the token, and MUST label later URL access as **Regenerate Invitation URL** (or equivalent) with confirmation that the previous URL will stop working. |
| F003-AC015 | Given a pending invite for an email, `POST /api/auth/login` with that email MUST NOT include `invite_token` (or any equivalent usable invitation credential) in the response body. |
| F003-AC016 | Given a pending invite for an email, `POST /api/auth/login` MUST leave the caller unauthenticated (no session established for that attempt). |
| F003-AC017 | Given a registered user with no pending invite, `POST /api/auth/login` with correct password still authenticates and returns the user payload (PD-005 must not break normal login). |
| F003-AC018 | Login UI MUST NOT navigate to `/invite/{token}` based on a token field returned from `POST /api/auth/login`; it MAY show a pending-invitation message directing the user to the administrator-provided link. |

---

## 17. Non-goals

- Building an email delivery subsystem
- Role matrices beyond `is_admin`
- Merging F004 into F003
- Admin impersonation
- Changing V1 Sanctum cookie architecture

---

## 18. Dependencies

| Depends on | Why |
|------------|-----|
| V1 Sanctum + `web` session | Accept logs the user in |
| V1 `is_admin` | Invite CRUD authorization |
| `PortfolioProfileService` | Default portfolio on accept |

| Unlocks | Why |
|---------|-----|
| Cleaner multi-user ops | Controlled onboarding |
| F060 (soft) | Collaboration assumes multiple real users |

F005 may follow for device session hygiene of invited users but is not a hard runtime dependency of invite accept.

---

## 19. Open decisions

**None** for F003. See [F003-F005-POLICY-DECISIONS.md](./F003-F005-POLICY-DECISIONS.md) final register:

- PD-004 / PD-005 / PD-006 — **DECIDED**
- PD-012 — **RESOLVED_BY_PD-006**
- PD-013 — **NOT_A_POLICY_DECISION**
- PD-007 — **DEFERRED**

**F003 delivery status:** **COMPLETE** (`F003_COMPLIANT_WITH_NON_BLOCKERS`).  
**F005:** **COMPLETE** (`F005_COMPLETE_WITH_NON_BLOCKERS`) — PD-006 delivered outside F003.

---

## 20. Implementation notes

- Primary service: `App\Services\UserInviteService`
- **Delivered (PD-004 / PD-005):** hash-at-rest; regenerate without extending `expires_at`; login does not return `invite_token`; LoginPage shows pending-invite message only
- Accept path: hash submitted raw token → lookup stored hash → pending + unexpired
- Admin list does not return a recoverable URL from storage after create/regenerate response
- Invite accept: first session only; **no** PD-006 session-revocation requirement (F003-R024) — owned by F005
- Tests: `tests/Feature/UserInviteTest.php` (core ACs; residual test gaps are documented non-blockers)
- **Deploy migration:** `2026_08_09_120001_harden_portfolio_user_invite_token_hashes` **deletes all pending** invitation rows (intentional; re-issue required). See `implementation.md` and the gap matrix deploy note.

---

*End of F003 specification.*
