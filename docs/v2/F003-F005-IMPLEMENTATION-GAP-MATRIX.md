# F003 / F005 Implementation Gap Matrix

**Date:** 2026-08-09  
**Status:** **READY_FOR_IMPLEMENTATION** (policy closed; application hardening not started)  
**Initiative:** Account & Access Management (F003 → F005)  
**Related:** [F003-USER-INVITE-SPEC.md](./F003-USER-INVITE-SPEC.md), [F005-SESSION-MANAGEMENT-SPEC.md](./F005-SESSION-MANAGEMENT-SPEC.md), [F003-F005-BOUNDARY.md](./F003-F005-BOUNDARY.md), [F003-F005-POLICY-DECISIONS.md](./F003-F005-POLICY-DECISIONS.md)

### Classification legend

| Classification | Meaning |
|----------------|---------|
| `V1_EXISTING` | Frozen V1 behaviour; not F003/F005 delta |
| `V2_REQUIRED` | Formal V2 Account & Access requirement (implement/harden) |
| `V2_SHOULD` | Desired; not blocking formal completeness if deferred with rationale |
| `V2_COULD` | Nice-to-have |
| `OUT_OF_SCOPE` | Explicitly excluded |
| `DEFERRED` | Recognized; postponed (e.g. PD-007) |

Do **not** use `AMBIGUOUS` — product policies for this initiative are closed.

### Gap kind (in Gap column)

Use explicit labels: `security hardening` · `test gap` · `docs gap` · `UX wording` · `formal AC` · `none (preserve)`.

Capabilities already shipped but needing harden/test/docs are **not** “missing features.”

### Priority legend

| Priority | Meaning |
|----------|---------|
| P0 | Blocks secure multi-user posture / must resolve before calling initiative complete |
| P1 | Formalization / test / docs gap with clear shipped behaviour |
| P2 | Hardening or UX polish |
| P3 | Optional / deferred |

---

## Hypothesis check (roadmap assessment)

| Claim | Verified? | Evidence |
|-------|-----------|----------|
| F003 largely shipped | **Yes** | `UserInviteService`, admin + guest APIs, SPA, `UserInviteTest` |
| F005 mostly shipped | **Yes** | `SessionManagementService`, APIs, Settings Account UI; audit “partial UI” is **outdated** |
| Highest remaining V2 priority | **Yes** | `V2-PRIORITIZATION.md` score 12 for F003 |
| Unlocks F060 | **Soft yes** | Planning dependency; no hard code gate |
| No formal V2 pack before this work | **Yes** | No prior `docs/v2/F003-*` / `F005-*` |

---

## V1 / V2 capability boundary (summary)

| Capability | Existing V1 | Existing implementation | V2 requirement | Gap |
|------------|-------------|-------------------------|----------------|-----|
| Sanctum SPA login/logout/me/CSRF | Yes (SD-001) | Shipped | Preserve | None (do not re-scope) |
| F004 password reset links | Yes (SD-035) | Shipped | Preserve | None |
| `is_admin` + admin middleware | Yes | Shipped | Preserve | None |
| Invite-only registration | Deferred from V1; shipped | Full (plaintext tokens) | Formalize + **PD-004 hash/rotation** | Spec done; hashing/rotation **not implemented** |
| Session list / revoke / logout-others | Deferred from V1; shipped | Full API + Settings UI | Formalize + PD-006 + tests/docs | Spec done; PD-006 not implemented; test/docs gaps |
| RBAC / tenants | Out of V1 | Absent | Out of scope | N/A |

---

## F003 — User Invite

| ID | Feature | Capability | Classification | Existing implementation | Gap | Evidence | Priority |
|----|---------|------------|----------------|-------------------------|-----|----------|----------|
| F003-G001 | Invite | Admin create invite | V2_REQUIRED | Implemented; admin-only | Formal AC coverage | `UserInviteController@store`, `UserInviteTest` | P1 |
| F003-G002 | Invite | List invites (admin) | V2_REQUIRED | Implemented | Formal AC | `listForAdmin`, GET `/api/invites` | P1 |
| F003-G003 | Invite | Regenerate / rotate token | V2_REQUIRED | Implemented (plaintext rotate; **also resets expiry**) | Target: hash replace + invalidate old URL; **stop resetting `expires_at`**; confirm UX | `regenerate`, POST `…/regenerate` | P0 |
| F003-G004 | Invite | Revoke pending invite | V2_REQUIRED | Hard delete; blocked if accepted | Formal AC | `revoke` | P1 |
| F003-G005 | Invite | 72h expiry + purge | V2_REQUIRED | Implemented on create; regenerate currently extends | Align with PD-002/PD-004 (no extend on rotate) | `EXPIRY_HOURS`, `purgeExpired` | P0 |
| F003-G006 | Invite | Single pending per email | V2_REQUIRED | Enforced in service | Formal AC; regenerate replaces credential on same row | create validation | P1 |
| F003-G007 | Invite | Reject invite for existing email | V2_REQUIRED | Implemented | Formal AC | create + accept paths | P1 |
| F003-G008 | Invite | Guest validate token | V2_REQUIRED | GET `/api/invites/{token}` plaintext match | Switch to hash-compare lookup | `InviteAcceptController@show` | P0 |
| F003-G009 | Invite | Guest accept → user + default portfolio + login | V2_REQUIRED | Implemented (plaintext token) | Hash submitted token before match | `accept`, `PortfolioProfileService` | P0 |
| F003-G010 | Invite | Copy-paste delivery (no email) | V2_REQUIRED | Implemented | Keep; split Copy vs Regenerate UX per PD-004 | compose message + UI | P1 |
| F003-G011 | Invite | Login must not return invite token | V2_REQUIRED | **PD-005 DECIDED (OPTION_C)**; code still returns `invite_token`; SPA auto-navigates | Remove token from login; optional `invite_setup_required` + message; update LoginPage | `AuthController::login`, `LoginPage.jsx` | P0 |
| F003-G012 | Invite | Token at-rest hashing + rotation | V2_REQUIRED | **PD-004 DECIDED**; code still plaintext; list can re-copy URL | Persist hash only; raw URL only at create/confirmed regenerate; no reconstruct | `portfolio_user_invites.token`, admin payload | P0 |
| F003-G013 | Invite | Non-admin forbidden | V2_REQUIRED | 403 via `admin` middleware | Formal AC | `UserInviteTest` | P1 |
| F003-G014 | Invite | Invitee non-admin | V2_REQUIRED | Default `is_admin=false` | Formal AC | User create on accept | P1 |
| F003-G015 | Invite | Open self-registration | OUT_OF_SCOPE | Removed | Keep removed | no `POST /auth/register` | — |
| F003-G016 | Invite | SMTP invite send | OUT_OF_SCOPE | Absent | Do not add unless product reopens | no Mail classes | — |
| F003-G017 | Invite | Cross-check token uniqueness vs reset links | V2_SHOULD | Reset gen avoids invite tokens; invite gen does not check reset table | Optional harden collision check (hash domain) | `PasswordResetLinkService` vs `UserInviteService` | P2 |
| F003-G018 | Invite | Formal V2 specification pack | V2_REQUIRED | Specs created; PD-004 recorded | Maintain as source of truth | this doc + F003 spec | P1 |
| F003-G019 | Invite | Regenerate UI confirmation / wording | V2_REQUIRED | Current UI uses regenerate/copy patterns without PD-004 warnings | **Regenerate Invitation URL** + pre-warning + confirm; initial **Copy Invitation URL** only | `UserManagementPage.jsx` | P0 |
| F003-G020 | Invite | Regenerate must not extend expiry | V2_REQUIRED | Current `regenerate()` sets new 72h `expires_at` | Stop silent expiry reset (PD-002/PD-004) | `UserInviteService::regenerate` | P0 |

---

## F005 — Session Management

| ID | Feature | Capability | Classification | Existing implementation | Gap | Evidence | Priority |
|----|---------|------------|----------------|-------------------------|-----|----------|----------|
| F005-G001 | Session | List own sessions | V2_REQUIRED | Implemented | Formal AC | GET `/api/auth/sessions` | P1 |
| F005-G002 | Session | Logout other devices | V2_REQUIRED | Implemented | Formal AC + test exists | POST `…/logout-others`, `AuthSessionTest` | P1 |
| F005-G003 | Session | Revoke single non-current session | V2_REQUIRED | Implemented | **Feature test thin** for DELETE path | DELETE `/api/auth/sessions/{id}` | P1 |
| F005-G004 | Session | Revoking current session = logout | V2_REQUIRED | Delegates to logout | Formal AC + test | `logoutSession` | P1 |
| F005-G005 | Session | Cannot revoke foreign user session | V2_REQUIRED | Scoped by `user_id`; suspicious audit | Formal AC + test | `destroySession`, audit | P1 |
| F005-G006 | Session | Settings Account UI | V2_REQUIRED | **Present** (Active sessions) | Audit “partial UI” outdated; keep UX polish only | `SettingsPage.jsx` | P1 |
| F005-G007 | Session | Multi-device allowed | V2_REQUIRED | Allowed by design | Formal AC | implementation.md | P1 |
| F005-G008 | Session | Device metadata display | V2_REQUIRED | IP + UA heuristic label | Formal AC | `SessionManagementService` | P1 |
| F005-G009 | Session | Password change revokes others | V2_REQUIRED | **PD-006 DECIDED (OPTION_B)**; code does **not** revoke; no remember-me rotate | Revoke others; keep current; invalidate other remember-me | `ProfileController::updatePassword` | P0 |
| F005-G010 | Session | F004 accept revokes others | V2_REQUIRED | **PD-006 DECIDED**; code preserves other sessions after reset accept | Revoke other pre-existing; keep new session; invalidate other remember-me | `PasswordResetAcceptController::accept` | P0 |
| F005-G011 | Session | Admin force-logout other users | DEFERRED | Absent | Leave deferred | no admin session API | P3 |
| F005-G012 | Session | Refresh tokens / PAT login | OUT_OF_SCOPE | PAT table idle; no refresh | Do not resurrect Bearer login | Sanctum SPA only | — |
| F005-G013 | Session | Idle lifetime config | V1_EXISTING | `SESSION_LIFETIME` ~30d | Not F005 delta | `config/session.php` | — |
| F005-G014 | Session | Help docs for Active sessions | V2_SHOULD | Thin / missing in Settings help | **PD-013 NOT_A_POLICY_DECISION** — docs sync during hardening | `appDocumentation.js` | P2 |
| F005-G015 | Session | Extend `AuthSessionTest` | V2_REQUIRED | Partial (list + logout-others) | Add revoke-one / foreign / current + PD-006 ACs | `AuthSessionTest.php`, `ProfileTest` | P1 |
| F005-G016 | Session | PD-012 tracking | — | Subsumed | **RESOLVED_BY_PD-006** — use F005-G010 | policy register | — |

---

## Cross-cutting / security observations (reconciliation only)

| ID | Observation | Severity | Action in this initiative |
|----|-------------|----------|---------------------------|
| SEC-001 | Pending-invite login returns raw token without password | High → policy closed | **PD-005** — implement no-token login |
| SEC-002 | Invite tokens plaintext at rest | Medium → policy closed | **PD-004** — implement hash + rotation |
| SEC-003 | Password change / reset leave other sessions alive | Medium → policy closed | **PD-006** — implement revoke-others |
| SEC-004 | Session revoke APIs are self-scoped (good) | Info | Preserve |
| SEC-005 | No tenant isolation bugs found in invite paths (no tenants) | Info | N/A |
| SEC-006 | Debug agent auto-login middleware exists when enabled | Ops risk | Outside F003/F005 unless enabled in prod — document only |

No penetration test performed; items above are from static code inspection only.

---

## Gap rollup

| Area | Shipped? | Formal V2 need |
|------|----------|----------------|
| F003 core lifecycle | Yes | Security hardening PD-004/005 + UX/tests |
| F003 token storage | Plaintext today | **PD-004** hash + rotation |
| F003 login vs invite | Returns token today | **PD-005** separate flows |
| F003 regenerate expiry | Extends today | Stop extend (PD-002/004) |
| F003 email transport | Intentionally no | Out of scope |
| F005 list/revoke UI+API | Yes | Tests + docs; preserve |
| F005 password change/reset sessions | Survive today | **PD-006** revoke-others |
| F005 admin force logout | No | Deferred PD-007 |
| Shared V1 auth | Yes | Do not re-scope |

---

### Policy status (closed)

| Decision | Status | Implementation complete? |
|----------|--------|--------------------------|
| PD-004 | **DECIDED** | **No** |
| PD-005 | **DECIDED** | **No** |
| PD-006 | **DECIDED** | **No** |
| PD-012 | **RESOLVED_BY_PD-006** | See F005-G010 |
| PD-013 | **NOT_A_POLICY_DECISION** | See F005-G014 (docs) |

---

## Implementation backlog (do not implement in this doc session)

### F003 — Required hardening

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F003-G012 / G008 / G009 | Hash invite tokens; validate/accept by hash | Plaintext equality | Store hash; compare hash(submitted) | `UserInviteService`, model/migration if rename, accept/show | — | P0 |
| F003-G003 / G019 | Rotation UX + confirm | Soft regenerate/copy | Regenerate Invitation URL + warning + confirm; Copy only on issue | `UserManagementPage.jsx`, admin API payload | G012 | P0 |
| F003-G020 / G005 | No expiry extend on rotate | Regenerates sets +72h | Leave `expires_at` unchanged | `UserInviteService::regenerate` | — | P0 |
| F003-G011 | Login no invite token | Returns `invite_token`; SPA navigates | No token; optional message; LoginPage message only | `AuthController`, `LoginPage.jsx` | PD-005 | P0 |
| F003 tests | AC coverage for hash/rotate/login | Partial `UserInviteTest` | Extend for PD-004/005 ACs | `UserInviteTest.php` | above | P0 |

### F003 — Should / documentation

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F003-G017 | Token collision vs reset links | Partial | Optional hash-domain check | Invite + reset services | G012 | P2 |
| Invite help | Users topic | Pre-PD-004/005 copy | Reflect hash/rotation + no login token | `appDocumentation.js` | harden | P2 |
| F003-G001–G007, G013–G014 | Core lifecycle | Implemented | Formal AC / preserve | tests | — | P1 |

### F005 — Required hardening

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F005-G009 | Password change revoke others | Preserves others | Keep current; revoke others; invalidate other remember-me | `ProfileController`, `SessionManagementService`, User remember_token | PD-006 | P0 |
| F005-G010 | Reset accept revoke others | Preserves others | Keep new session; revoke others; invalidate other remember-me | `PasswordResetAcceptController` | PD-006 | P0 |
| F005-G015 (+ AC007–009) | Session tests | Thin DELETE / no PD-006 | Expand AuthSession/Profile/reset tests | PHPUnit | G009/G010 | P1 |
| F005-G003–G005 | Single/foreign/current revoke tests | Partial | Formal AC | `AuthSessionTest` | — | P1 |

### F005 — Should / documentation

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F005-G014 | Active sessions help | Missing | Document controls (+ PD-006 note on profile) | `appDocumentation.js` | — | P2 |
| F005-G001–G008 | List/revoke UI+API | Implemented | Preserve + formal AC | existing | — | P1 |

### Out of scope / deferred

| ID | Capability | Status |
|----|------------|--------|
| F003-G015 | Open registration | OUT_OF_SCOPE |
| F003-G016 | SMTP invites | OUT_OF_SCOPE |
| F005-G011 / PD-007 | Admin force-logout others | DEFERRED |
| F005-G012 | PAT / refresh tokens | OUT_OF_SCOPE |
| F060 | Shared screener | Later phase |
| RBAC / tenants | — | OUT_OF_SCOPE |

### Recommended implementation order

**PHASE 1 — F003:** hash storage → hash validate/accept → regenerate without expiry extend → admin Copy vs Regenerate UX → remove login token disclosure + LoginPage → tests → invite help.

**PHASE 2 — F005:** password-change revoke-others + remember-me → password-reset revoke-others + remember-me → session tests → Active sessions / profile help.

---

*End of gap matrix.*
