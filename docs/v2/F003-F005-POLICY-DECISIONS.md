# F003 / F005 Policy Decisions

**Date:** 2026-08-09  
**Status:** **READY_FOR_IMPLEMENTATION** — all Account & Access product policies closed  
**Specs:** [F003-USER-INVITE-SPEC.md](./F003-USER-INVITE-SPEC.md), [F005-SESSION-MANAGEMENT-SPEC.md](./F005-SESSION-MANAGEMENT-SPEC.md)  
**Boundary:** [F003-F005-BOUNDARY.md](./F003-F005-BOUNDARY.md)  
**Gap matrix / backlog:** [F003-F005-IMPLEMENTATION-GAP-MATRIX.md](./F003-F005-IMPLEMENTATION-GAP-MATRIX.md)

No unresolved **product-policy** decisions remain for F003/F005. Remaining work is **implementation/hardening** (and documentation sync). Application code has **not** been changed by this policy pack.

---

## Final policy register

| Decision | Status |
|----------|--------|
| PD-001 Invite delivery (copy-paste) | **DECIDED** |
| PD-002 Invite expiry (72h from create; no extend on rotate) | **DECIDED** |
| PD-003 One pending invite per email | **DECIDED** |
| PD-004 Invite token hashing + rotation | **DECIDED** |
| PD-005 Separate login vs invitation flows | **DECIDED** |
| PD-006 Revoke other sessions on credential change/reset | **DECIDED** |
| PD-007 Admin force-logout of another user | **DEFERRED** (out of this initiative) |
| PD-008 Session metadata fields | **DECIDED** |
| PD-009 Multi-device sessions allowed | **DECIDED** |
| PD-010 Admin invite create error messages | **DECIDED** |
| PD-011 Invitee non-admin | **DECIDED** |
| PD-012 Password-reset vs other sessions | **RESOLVED_BY_PD-006** |
| PD-013 Contextual help for Active sessions | **NOT_A_POLICY_DECISION** |

---

## Summary table (detail)

| Decision | Current behaviour (code) | Approved target | Status |
|----------|--------------------------|-----------------|--------|
| PD-001 | Copy link/message; no Mailer | Keep copy-paste | **DECIDED** |
| PD-002 | 72h; regenerate also resets expiry today | 72h from **create** only; rotate must not extend | **DECIDED** |
| PD-003 | One pending per email | Keep | **DECIDED** |
| PD-004 | Plaintext token; re-copy from storage | Hash at rest; later URL = explicit regenerate | **DECIDED** |
| PD-005 | Login returns `invite_token` | OPTION_C — no token from login | **DECIDED** |
| PD-006 | Change/reset leave other sessions | OPTION_B — revoke others; keep current/new | **DECIDED** |
| PD-007 | No admin cross-user session kill | Defer | **DEFERRED** |
| PD-008 | IP / device / UA / activity shown | Keep | **DECIDED** |
| PD-009 | Multi-device allowed | Keep | **DECIDED** |
| PD-010 | Explicit admin create errors | Keep | **DECIDED** |
| PD-011 | Invitee non-admin | Keep | **DECIDED** |
| PD-012 | (was tracking reset sessions) | Same as PD-006 for reset | **RESOLVED_BY_PD-006** |
| PD-013 | Settings help omits Active sessions | Docs sync during hardening | **NOT_A_POLICY_DECISION** |

---

## PD-001 — Invite delivery channel

**DECIDED** — Manual copy-paste of invite URL and composed message. No SMTP requirement for F003.

---

## PD-002 — Invite expiry

**DECIDED** — Validity is **72 hours from create**. Token regeneration/rotation **MUST NOT** extend or reset `expires_at`.

**Implementation note:** current `UserInviteService::regenerate()` resets expiry — hardening gap (not a new product choice).

---

## PD-003 — Multiple pending invitations

**DECIDED** — At most one pending invite per normalized email. Regenerate replaces the credential on that row.

---

## PD-004 — Invite token storage / hashing (TOKEN ROTATION)

**DECIDED**

Invitation tokens are **stored hashed**, not plaintext. Later URL access uses **explicit token regeneration/rotation**, not reconstruction.

| Moment | Behaviour |
|--------|-----------|
| Create | CSPRNG token → store hash only → show URL → **Copy Invitation URL** |
| Later | **Regenerate Invitation URL** + warning + confirm → new token → replace hash → old URL invalid → show new URL |
| Expiry | Unchanged on rotate (PD-002) |
| Delivery | Copy-paste (PD-001) |

Rationale: DB compromise resistance; operational URL recovery via rotation; explicit credential rotation.

---

## PD-005 — Authentication vs invitation-flow boundary

**DECIDED** — **OPTION_C**

| Flow | Path |
|------|------|
| Normal login | Existing user + password → session |
| Invitation | URL bearer token → validate → set password → accept → session |

`POST /api/auth/login` MUST NOT return a usable invitation token. Pending-invite login: no auth, no session, no token; MAY `invite_setup_required` + admin-link message. Lost URL → admin regenerate (PD-004).

Together with PD-004:

```text
Invitation token → hashed at rest → issued only on create/regenerate
  → consumed only on accept → never disclosed by normal login
```

---

## PD-006 — Credential change and session revocation

**DECIDED** — **OPTION_B**

Principle: credential change/reset is a security recovery event.

| Flow | Survive | Revoke | Remember-me (other devices) |
|------|---------|--------|------------------------------|
| Authenticated password change | Current session | All other sessions | Invalidated |
| Password-reset accept | Newly established session | All other pre-existing | Invalidated |
| Invitation acceptance | First session | **No PD-006 revoke requirement** | N/A |

F005 manual list/revoke/logout-others remains available; PD-006 is automatic.

---

## PD-007 — Admin force-logout

**DEFERRED** — Out of F003/F005 hardening unless elevated later.

---

## PD-008 / PD-009 — Session visibility and multi-device

**DECIDED** — Show IP + device summary + activity; allow multiple simultaneous sessions; provide logout-others and per-session revoke.

---

## PD-010 / PD-011 — Admin invite messages / invitee privilege

**DECIDED** — Explicit admin create errors; new invitees are non-admin.

---

## PD-012 — Password-reset accept and other sessions

**RESOLVED_BY_PD-006**

### Original question

Should F004 password-reset acceptance preserve or revoke other authenticated sessions for that user?

### Resolution

Fully answered by **PD-006 OPTION_B**: after reset accept establishes the new session, revoke all other pre-existing sessions and invalidate other-device remember-me. No additional product choice remains.

### Not a separate policy

PD-012 does **not** add behaviour beyond PD-006. It is closed as **RESOLVED_BY_PD-006**. Implementation remains tracked under gap **F005-G010**.

---

## PD-013 — Help documentation for Active sessions

**NOT_A_POLICY_DECISION**

PD-013 is **documentation/help synchronization** work and is **not** a product policy decision.

- F005 Active sessions UI already exists  
- `F005-R013` already says contextual help SHOULD describe those controls  
- Invite/password help should also reflect PD-004 / PD-005 / PD-006 when those hardenings land  

Handle during F003/F005 implementation/hardening via `appDocumentation.js` (and static docs). Tracked as gap **F005-G014** (and related invite help updates).

---

## Decisions that are **not** open / out of initiative

| Topic | Why closed |
|-------|------------|
| Open self-registration | Removed; invite-only |
| Sanctum SPA vs JWT | SD-001 |
| Bearer PAT login | Removed |
| Multi-tenant orgs / Spatie RBAC | MVP_SCOPE out of scope |
| SMTP invite send | Out of scope (PD-001) |
| Reconstruct old invite URL from DB | Superseded by PD-004 |
| Login rediscloses invite token | Superseded by PD-005 |
| Keep other sessions after password change/reset | Superseded by PD-006 |
| Separate PD-012 reset-session choice | **RESOLVED_BY_PD-006** |
| Whether to document Active sessions | **NOT_A_POLICY_DECISION** (docs task) |

---

*End of policy decisions.*
