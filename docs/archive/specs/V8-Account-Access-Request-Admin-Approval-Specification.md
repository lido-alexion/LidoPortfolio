# StoX V8 Account Access Request / Admin Approval Workflow Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-055 — Account Access Request / Admin Approval Workflow |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Canonical path** | `docs/archive/specs/V8-Account-Access-Request-Admin-Approval-Specification.md` |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` |
| **Existing security dependency** | Existing StoX Admin invite / invite-accept flow |
| **Primary implementation agent** | Codex |

---

## 1. Purpose

V4-FEAT-055 adds a public **Request an account** entry point while preserving StoX's existing admin-controlled, invite-only account-creation model.

The feature is an access-request workflow, not self-registration. A guest may ask for access, but only an Admin may decide whether to issue an invitation. Actual account creation remains inside the existing secure invite-accept flow.

Canonical lifecycle:

```text
Login
  -> Request an account
  -> name + email + CAPTCHA
  -> email ownership verification
  -> pending request
  -> Admin review
      -> Create -> issue existing secure invite -> invite acceptance -> account created
      -> Ignore -> close request -> cooldown -> may request again
      -> Reject -> close request + request-ban email -> Admin may later clear ban
```

---

## 2. Scope

### 2.1 In scope

- guest-facing **Request an account** link and form;
- full name and email only;
- server-side CAPTCHA validation;
- email-ownership verification before a request becomes pending;
- normalized-email duplicate/conflict handling;
- request lifecycle and Admin review UI;
- Create / Ignore / Reject outcomes;
- reversible request-ban list;
- cooldown after Ignore;
- prior request history for the same normalized email;
- Admin and applicant notifications;
- auditability, concurrency and idempotency;
- retention/pruning configuration;
- rate limiting and abuse controls.

### 2.2 Out of scope

- open self-registration;
- applicant-selected role or entitlement;
- direct public account creation;
- password collection on the request form;
- broker/API/TOTP credential collection;
- public request-status lookup;
- applicant access to Admin notes, ban state or rejection rationale;
- replacement of the existing invitation/token security model.

---

## 3. Frozen product decisions

| Decision | Frozen choice |
|---|---|
| 055-01 | Email ownership must be verified before a request becomes pending. |
| 055-02 | Reject closes the request and creates a reversible future-request ban for the normalized email. |
| 055-03 | Ignore closes the request without ban, but future requests are subject to a short configurable cooldown. |
| 055-04 | Applicant form collects only full name and email. |
| 055-05 | Admin **Create** immediately issues the existing secure StoX invite using the verified request data. |
| 055-06 | Applicant is notified on every final outcome: invite email on Create, neutral email on Ignore, generic rejection email on Reject. |
| 055-07 | Admin may optionally record an internal reason for Ignore/Reject; it is never applicant-visible. |
| 055-08 | If an active pending invite already exists, no access request is created; public flow does not resend/regenerate the invite. |
| 055-09 | Admin sees prior request history for the same normalized email. |
| 055-10 | No public request-status lookup is exposed. |

---

## 4. Existing security boundary

FEAT-055 MUST reuse the existing StoX account onboarding boundary:

- Admin controls invitation issuance;
- invitation token lifecycle remains authoritative;
- invite token storage/hashing/expiry/acceptance remain unchanged unless implementation compatibility requires a narrow extension;
- applicant never chooses role, Admin state or automated-execution entitlement;
- actual user creation remains owned by invite acceptance;
- existing Admin middleware/authorization remains authoritative.

FEAT-055 may create and resolve an access request, but cannot itself create a logged-in user session or user account.

---

## 5. Guest request flow

### 5.1 Entry point

Login SHALL expose a clear **Request an account** link to a dedicated unauthenticated route.

### 5.2 Form fields

Collect exactly:

- full/display name;
- email address.

Do not collect free-text justification, phone, company, occupation, password, role, broker credentials, API credentials or TOTP secrets.

### 5.3 CAPTCHA and rate limiting

Initial submission requires server-verified CAPTCHA or equivalent human verification.

Requirements:

- CAPTCHA verification occurs server-side;
- CAPTCHA tokens are transient and are not stored as request data;
- provider secrets remain server-side;
- verification failure creates no request;
- public endpoints are rate-limited independently of CAPTCHA;
- rate limits should consider source/IP and normalized email dimensions;
- implementation should isolate CAPTCHA provider details behind a small service abstraction.

---

## 6. Email ownership verification

A submission does not become a pending access request until email ownership is verified.

Preferred implementation is a short-lived, single-use verification link sent to the submitted address.

Requirements:

- verification token is cryptographically random;
- persist only a hash where practical, following existing invite/reset-token patterns;
- token has bounded expiry;
- verification is single-use/idempotent;
- verification does not log the user in;
- verification does not issue an account invite;
- expired verification can be restarted through the public request flow subject to abuse controls.

Unverified submissions MUST NOT appear in the normal Admin pending-request queue or trigger Admin access-request notifications.

---

## 7. Identity normalization and conflict handling

Email is the request identity and MUST be normalized consistently with existing users and invites.

Before establishing a pending request, distinguish at least:

1. **existing StoX user** — do not create a request; direct to normal login/recovery guidance;
2. **active pending invite exists** — do not create a request; state that onboarding is already in progress;
3. **pending access request exists** — do not create another pending request;
4. **email is request-banned** — do not create a request;
5. **ignored request is still within cooldown** — do not create a new request until cooldown expires;
6. **no conflict** — create exactly one pending request.

Conflict prevention should be transactionally/database enforced where practical, not frontend-only.

Public responses MUST avoid exposing unnecessary account-existence or internal-ban detail. Wording may be generic where necessary for enumeration resistance.

---

## 8. Request lifecycle

Canonical states:

```text
pending
  -> created
  -> ignored
  -> rejected
```

Semantics:

- `pending`: verified requester awaiting Admin decision;
- `created`: Admin successfully issued the existing StoX invitation;
- `ignored`: closed without ban; future request allowed after cooldown;
- `rejected`: closed and normalized email request-banned until Admin clears it.

A request is considered `created` when invitation issuance succeeds, not when the applicant later accepts the invitation.

Invite expiry does not reopen the original access request.

---

## 9. Admin experience

User Management/Admin SHALL expose:

- pending request count/badge;
- request list and detail;
- full name and normalized email;
- verification/submission timestamp;
- current status;
- prior request history for the same email;
- current request-ban state;
- Admin actor and action timestamps;
- optional internal Ignore/Reject reason;
- links/identifiers to resulting invite/user where applicable.

Useful filters:

- status;
- date range;
- email/name search;
- pending-only default;
- rejected/banned view.

---

## 10. Admin actions

### 10.1 Create

Create SHALL:

1. revalidate that the request is still pending;
2. revalidate no user or incompatible active invite conflict now exists;
3. issue the existing secure StoX invitation using verified name/email;
4. mark request `created` only if invitation issuance succeeds;
5. link the request to the resulting invite where practical;
6. send the normal invitation email through the existing invite/onboarding channel.

Create must be idempotent/conflict-safe against double-clicks and concurrent Admins.

### 10.2 Ignore

Ignore SHALL:

- mark request `ignored`;
- optionally store an Admin-only reason;
- not ban the email;
- establish a bounded configurable resubmission cooldown;
- send a neutral applicant outcome email.

The applicant email must not expose the internal reason.

### 10.3 Reject

Reject SHALL:

- mark request `rejected`;
- create/activate a request-ban for the normalized email;
- optionally store an Admin-only reason;
- send a generic rejection email;
- block future access requests until the ban is explicitly cleared.

The applicant email must not mention internal notes or implementation details of the ban list.

---

## 11. Request-ban administration

Admin SHALL have a dedicated way to inspect request-banned identities.

Persist at minimum:

- normalized email;
- originating request where available;
- rejected by;
- rejected at;
- optional internal reason;
- cleared by/at when cleared.

**Clear ban / Allow future requests** SHALL:

- require explicit Admin action;
- be auditable;
- allow future access requests;
- not resurrect the original rejected request;
- not modify an existing user account.

The ban is scoped only to the access-request workflow.

---

## 12. Prior request history

For a request detail, Admin can inspect previous requests for the same normalized email, including:

- created/invited;
- ignored;
- rejected;
- ban creation/clearance;
- relevant timestamps and Admin actors.

This history is operational context, not a separate customer profile.

---

## 13. Notifications

### 13.1 Admin

Create an Admin-facing notification when a verified request becomes pending.

Use the existing StoX notification architecture and configured channels. Notification failure MUST NOT roll back a valid request.

### 13.2 Applicant

Applicant notifications:

- verification email after initial valid submission;
- Create -> normal invitation email;
- Ignore -> neutral outcome email;
- Reject -> generic rejection email.

Do not send Admin notes, ban mechanics or sensitive diagnostic information.

Delivery state is notification state, not request-domain state.

---

## 14. Public status behavior

There is no public request-status page or lookup API.

The applicant learns state through:

- verification flow;
- final outcome email;
- normal invite onboarding if Create is selected.

This intentionally minimizes public identity-enumeration surface and extra token/state machinery.

---

## 15. Auditability and concurrency

Audit at minimum:

- verification initiated/completed where useful;
- pending request created;
- duplicate/conflict prevented where appropriate without unnecessary PII;
- Admin Create / Ignore / Reject;
- request-ban created;
- request-ban cleared;
- invite identifier created from a request;
- optional internal reason and Admin actor for decision events.

Two Admins resolving the same request concurrently must result in one authoritative transition. The loser receives a clear already-resolved/conflict response with no duplicate side effects.

---

## 16. Security and privacy rules

1. No open registration endpoint is introduced.
2. Applicant cannot set role, Admin state or execution entitlement.
3. Applicant cannot submit password, broker/API or TOTP credentials through this workflow.
4. Admin list/detail/action APIs are Admin-only.
5. CAPTCHA and rate limiting protect the public entry points.
6. Email verification must succeed before pending state.
7. Verification tokens are short-lived and single-use.
8. Sensitive Admin notes are never applicant-visible.
9. Public responses reveal the minimum useful state.
10. Request/notification failure cannot weaken existing invite-token security.

---

## 17. Retention and cleanup

Exact retention durations are implementation/configuration parameters rather than PO decisions.

Implementation SHALL provide bounded cleanup for:

- expired unverified submissions/verification tokens;
- old request records according to operational usefulness;
- stale notification delivery records according to existing notification policy.

Historical decision/audit evidence may be retained longer than transient verification material.

Request-ban records remain until explicitly cleared unless a later product policy says otherwise.

---

## 18. Suggested domain/service direction

Prefer extending existing Admin/user/invite infrastructure instead of creating parallel onboarding architecture.

Conceptual responsibilities may include:

- `AccessRequestService` — create/verify/transition requests;
- `AccessRequestVerificationService` — token issue/validation;
- `AccessRequestPolicyService` — duplicate, cooldown and ban checks;
- `AccessRequestAdminService` — Create/Ignore/Reject/clear-ban transactions;
- existing invite service — authoritative invitation issuance;
- existing notification/mail services — verification, Admin notification and outcome delivery.

Exact class/table names may follow repository conventions.

---

## 19. API direction

Conceptual public capabilities:

- submit access request for verification;
- consume verification token / complete verified request.

Conceptual Admin capabilities:

- list/detail requests;
- Create;
- Ignore;
- Reject;
- list request bans;
- clear request ban.

No public status-lookup endpoint is required.

All mutation endpoints must be idempotent or conflict-safe for retry/double-click behavior.

---

## 20. Implementation-level defaults

The following are architect/implementation parameters and may be tuned without reopening frozen product decisions:

- CAPTCHA provider;
- CAPTCHA failure wording;
- verification-token expiry;
- verification resend/rate limits;
- Ignore cooldown duration;
- record retention/pruning periods;
- exact generic applicant email wording;
- Admin filters/pagination details;
- notification channel mapping;
- internal table/service names.

---

## 21. Acceptance criteria

1. Login exposes **Request an account** without authentication.
2. Form accepts only full name and email as applicant data.
3. CAPTCHA failure creates no pending request.
4. Valid submission sends email verification and creates no Admin-pending request yet.
5. Only successful email verification creates a pending request.
6. Verification link/token is bounded, single-use and does not authenticate the user.
7. Existing user creates no access request.
8. Existing active invite creates no access request and is not publicly resent/regenerated.
9. Duplicate pending request for the same normalized email is prevented.
10. Request-banned email cannot create a pending request.
11. Ignored email cannot resubmit until cooldown expires, then may request normally.
12. Admin receives the verified pending request and notification.
13. Non-admin cannot inspect or resolve requests/bans.
14. Admin sees prior request history for the same normalized email.
15. Create issues the existing secure invitation and marks the request created only on success.
16. Concurrent/double Create cannot issue duplicate invitations/accounts.
17. Ignore closes without ban and sends neutral outcome email.
18. Reject closes, activates request ban and sends generic rejection email.
19. Optional Admin reason remains internal for Ignore/Reject.
20. Admin can explicitly clear a request ban; future request becomes possible without resurrecting the old request.
21. No public request-status lookup is exposed.
22. Notification failure does not corrupt request or invite domain state.
23. Applicant can never choose role/Admin/execution entitlement through this workflow.
24. All significant Admin decisions and ban/unban actions are auditable.

---

## 22. Definition of done

FEAT-055 is complete when:

- public request + CAPTCHA + email-verification flow works;
- only verified identities enter the pending Admin queue;
- duplicate/user/invite/ban/cooldown conflicts are enforced server-side;
- Admin can Create/Ignore/Reject safely;
- Create reuses the existing secure invite flow rather than creating users directly;
- Ignore cooldown and reversible Reject ban behave as specified;
- prior request history and ban administration are visible to Admin;
- applicant/Admin notifications use existing StoX messaging infrastructure;
- public status lookup is absent;
- audit, concurrency, authorization and abuse-control tests pass;
- V8 register links this document as the authoritative FEAT-055 implementation contract.

---

## 23. Explicit non-goal

FEAT-055 does not turn StoX into an open-signup product.

The authoritative V8 boundary remains:

```text
request access = public
verify email = public
approve / invite = Admin decision
create account = existing secure invite acceptance
```
