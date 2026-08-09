# F003 / F005 Implementation Gap Matrix

**Date:** 2026-08-09  
**Status:** **F003 COMPLETE** (`F003_COMPLIANT_WITH_NON_BLOCKERS`); **F005 READY_FOR_IMPLEMENTATION** (PD-006 not started)  
**Initiative:** Account & Access Management (F003 → F005)  
**Related:** [F003-USER-INVITE-SPEC.md](./F003-USER-INVITE-SPEC.md), [F005-SESSION-MANAGEMENT-SPEC.md](./F005-SESSION-MANAGEMENT-SPEC.md), [F003-F005-BOUNDARY.md](./F003-F005-BOUNDARY.md), [F003-F005-POLICY-DECISIONS.md](./F003-F005-POLICY-DECISIONS.md)

### Delivery summary

| Track | Status |
|-------|--------|
| F003 User Invite hardening (PD-004 / PD-005) | **COMPLETE** — compliant with documented non-blockers |
| F005 Session Management hardening (PD-006) | **NOT started** — next Account & Access work |

### Deploy note — pending-invite migration (operational)

Migration `2026_08_09_120001_harden_portfolio_user_invite_token_hashes`:

- **Intentionally deletes** all pending (`accepted_at` null) invitation rows when moving to hash-only storage.
- Existing pending invitation **URLs stop working**; administrators **must re-issue** invitations after migrate.
- Accepted rows keep metadata; their stored token values are scrambled to random hashes (no usable URL).
- Migration is **destructive for pending rows**; `down()` does not restore them. Not a zero-downtime / reversible data migration.

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
| Highest remaining V2 priority (at planning) | **Yes (historical)** | Was F003 at score 12; **F003 now COMPLETE** — F005 is next Account & Access work |
| Unlocks F060 | **Soft yes** | Planning dependency; no hard code gate |
| No formal V2 pack before this work | **Yes** | No prior `docs/v2/F003-*` / `F005-*` |

---

## V1 / V2 capability boundary (summary)

| Capability | Existing V1 | Existing implementation | V2 requirement | Gap |
|------------|-------------|-------------------------|----------------|-----|
| Sanctum SPA login/logout/me/CSRF | Yes (SD-001) | Shipped | Preserve | None (do not re-scope) |
| F004 password reset links | Yes (SD-035) | Shipped | Preserve | None |
| `is_admin` + admin middleware | Yes | Shipped | Preserve | None |
| Invite-only registration | Deferred from V1; shipped | **F003 hardening done** (hashed tokens, PD-004/005) | Preserve; F005 still open | Hashing/rotation/login separation **implemented** |
| Session list / revoke / logout-others | Deferred from V1; shipped | Full API + Settings UI | Formalize + PD-006 + tests/docs | Spec done; PD-006 not implemented; test/docs gaps |
| RBAC / tenants | Out of V1 | Absent | Out of scope | N/A |

---

## F003 — User Invite

| ID | Feature | Capability | Classification | Existing implementation | Gap | Evidence | Priority |
|----|---------|------------|----------------|-------------------------|-----|----------|----------|
| F003-G001 | Invite | Admin create invite | V2_REQUIRED | Implemented; admin-only; raw URL once | **DONE** | `UserInviteController@store`, `UserInviteTest` | P1 |
| F003-G002 | Invite | List invites (admin) | V2_REQUIRED | List without `invite_url` (no reconstruct) | **DONE** | `listForAdmin`, GET `/api/invites` | P1 |
| F003-G003 | Invite | Regenerate / rotate token | V2_REQUIRED | Hash replace; same row; no expiry extend; confirm UX | **DONE** | `regenerate`, POST `…/regenerate` | P0 |
| F003-G004 | Invite | Revoke pending invite | V2_REQUIRED | Hard delete; blocked if accepted | **DONE** | `revoke` | P1 |
| F003-G005 | Invite | 72h expiry + purge | V2_REQUIRED | Expiry from original create; rotate does not extend | **DONE** | `EXPIRY_HOURS`, `purgeExpired` | P0 |
| F003-G006 | Invite | Single pending per email | V2_REQUIRED | Enforced; regenerate updates same row | **DONE** | create validation | P1 |
| F003-G007 | Invite | Reject invite for existing email | V2_REQUIRED | Implemented | **DONE** | create + accept paths | P1 |
| F003-G008 | Invite | Guest validate token | V2_REQUIRED | Lookup by `hash('sha256', raw)` | **DONE** | `InviteAcceptController@show` | P0 |
| F003-G009 | Invite | Guest accept → user + default portfolio + login | V2_REQUIRED | Hash match; first session; no F005 revoke | **DONE** | `accept`, `PortfolioProfileService` | P0 |
| F003-G010 | Invite | Copy-paste delivery (no email) | V2_REQUIRED | Copy Invitation URL on issue; no SMTP | **DONE** | compose message + UI | P1 |
| F003-G011 | Invite | Login must not return invite token | V2_REQUIRED | `invite_setup_required` + message only; SPA no navigate | **DONE** | `AuthController::login`, `LoginPage.jsx` | P0 |
| F003-G012 | Invite | Token at-rest hashing + rotation | V2_REQUIRED | SHA-256 in `token` column; `$hidden`; migration purge pending | **DONE** | `portfolio_user_invites.token`, admin payload | P0 |
| F003-G013 | Invite | Non-admin forbidden | V2_REQUIRED | 403 via `admin` middleware | **DONE** | `UserInviteTest` | P1 |
| F003-G014 | Invite | Invitee non-admin | V2_REQUIRED | Default `is_admin=false` | **DONE** | User create on accept | P1 |
| F003-G015 | Invite | Open self-registration | OUT_OF_SCOPE | Removed | Keep removed | no `POST /auth/register` | — |
| F003-G016 | Invite | SMTP invite send | OUT_OF_SCOPE | Absent | Do not add unless product reopens | no Mail classes | — |
| F003-G017 | Invite | Cross-check token uniqueness vs reset links | V2_SHOULD | Reset gen checks hashed invite tokens; invite gen does not check reset table | **PARTIAL** (non-blocker) | `PasswordResetLinkService` | P2 |
| F003-G018 | Invite | Formal V2 specification pack | V2_REQUIRED | Specs authoritative; implementation aligned | **DONE** | this doc + F003 spec | P1 |
| F003-G019 | Invite | Regenerate UI confirmation / wording | V2_REQUIRED | Regenerate Invitation URL + warning + confirm; Copy on issue | **DONE** | `UserManagementPage.jsx` | P0 |
| F003-G020 | Invite | Regenerate must not extend expiry | V2_REQUIRED | `regenerate()` preserves `expires_at` | **DONE** | `UserInviteService::regenerate` | P0 |

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
| SEC-001 | Pending-invite login returns raw token without password | High → closed | **PD-005 implemented** — no token on login |
| SEC-002 | Invite tokens plaintext at rest | Medium → closed | **PD-004 implemented** — SHA-256 hash + rotation |
| SEC-003 | Password change / reset leave other sessions alive | Medium → policy closed | **PD-006** — implement revoke-others (**F005**, not done) |
| SEC-004 | Session revoke APIs are self-scoped (good) | Info | Preserve |
| SEC-005 | No tenant isolation bugs found in invite paths (no tenants) | Info | N/A |
| SEC-006 | Debug agent auto-login middleware exists when enabled | Ops risk | Outside F003/F005 unless enabled in prod — document only |

No penetration test performed; items above are from static code inspection only.

---

## Gap rollup

| Area | Shipped? | Formal V2 need |
|------|----------|----------------|
| F003 core lifecycle | Yes | Hardening complete |
| F003 token storage | SHA-256 hash | **PD-004 done** |
| F003 login vs invite | Separated (no token) | **PD-005 done** |
| F003 regenerate expiry | Preserved | **DONE** |
| F003 email transport | Intentionally no | Out of scope |
| F005 list/revoke UI+API | Yes | Tests + docs; preserve |
| F005 password change/reset sessions | Survive today | **PD-006** revoke-others (**not implemented**) |
| F005 admin force logout | No | Deferred PD-007 |
| Shared V1 auth | Yes | Do not re-scope |

---

### Policy status (closed)

| Decision | Status | Implementation complete? |
|----------|--------|--------------------------|
| PD-004 | **DECIDED** | **Yes** (F003) |
| PD-005 | **DECIDED** | **Yes** (F003) |
| PD-006 | **DECIDED** | **No** (F005) |
| PD-012 | **RESOLVED_BY_PD-006** | See F005-G010 |
| PD-013 | **NOT_A_POLICY_DECISION** | See F005-G014 (docs) |

---

## Implementation backlog

### F003 — Required hardening

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F003-G012 / G008 / G009 | Hash invite tokens | **DONE** | — | `UserInviteService` | — | — |
| F003-G003 / G019 | Rotation UX + confirm | **DONE** | — | `UserManagementPage.jsx` | — | — |
| F003-G020 / G005 | No expiry extend on rotate | **DONE** | — | `UserInviteService::regenerate` | — | — |
| F003-G011 | Login no invite token | **DONE** | — | `AuthController`, `LoginPage.jsx` | — | — |
| F003 tests | AC coverage for hash/rotate/login | **DONE** | — | `UserInviteTest.php` | — | — |

### F003 — Should / documentation

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F003-G017 | Token collision vs reset links | **PARTIAL** (reset→invite check only) | Optional invite→reset check | services | — | non-blocker |
| Invite help | Users topic | **DONE** | — | `appDocumentation.js` | — | — |
| F003-G001–G007, G013–G014 | Core lifecycle | **DONE** | Preserve | tests | — | — |

### F003 — Documented non-blockers (do not reopen MUST scope)

| Item | Classification | Notes |
|------|----------------|-------|
| AC009 / AC010 test coverage | test gap | Behaviour implemented; dedicated tests thin/absent |
| AC004 portfolio assertion in tests | test gap | `createDefaultForUser` called; test does not assert profile row |
| AC007 accept-after-revoke assertion | test gap | Revoke deletes row; post-revoke accept not separately asserted |
| Frontend automated UI tests | test gap | No FE test framework; source + build verification only |
| F003-G017 one-way collision check | SHOULD residual | Reset generation avoids invite hashes; inverse optional |
| Regenerate allowed on expired invite row | UX limitation | Preserves `expires_at` (may yield immediately unusable URL) |

### F005 — Required hardening

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F005-G009 | Password change revoke others | Preserves others — **NOT_DELIVERED** | Keep current; revoke others; invalidate other remember-me | `ProfileController`, `SessionManagementService`, User remember_token | PD-006 | P0 |
| F005-G010 | Reset accept revoke others | Preserves others — **NOT_DELIVERED** | Keep new session; revoke others; invalidate other remember-me | `PasswordResetAcceptController` | PD-006 | P0 |
| F005-G015 (+ AC007–009) | Session tests | Thin DELETE / no PD-006 — **OPEN** | Expand AuthSession/Profile/reset tests | PHPUnit | G009/G010 | P1 |
| F005-G003–G005 | Single/foreign/current revoke tests | Partial — **OPEN** | Formal AC | `AuthSessionTest` | — | P1 |

### F005 — Should / documentation

| ID | Capability | Current | Target | Areas | Depends | Priority |
|----|------------|---------|--------|-------|---------|----------|
| F005-G014 | Active sessions help | Missing — **OPEN** | Document controls (+ PD-006 note on profile) | `appDocumentation.js` | — | P2 |
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

**PHASE 1 — F003:** **COMPLETE** (`F003_COMPLIANT_WITH_NON_BLOCKERS`). Non-blockers listed above; do not treat as new feature scope.

**PHASE 2 — F005:** **READY_FOR_IMPLEMENTATION** — password-change revoke-others + remember-me → password-reset revoke-others + remember-me → session tests → Active sessions / profile help.

---

*End of gap matrix.*
*F003 closed 2026-08-09 after final compliance audit; F005 remains open.*
