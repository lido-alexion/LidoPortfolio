# LidoPortfolio / StoX V8 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V8 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V7-Wishlist.md` |

## 1. Purpose

V8 contains the **Standalone Telemetry Platform**, deferred follow-on data-bootstrap work that is intentionally kept outside the V7 closure gate, and selected account/onboarding improvements that do not alter the existing V1-V7 security boundaries.

The Telemetry Platform is separated from StoX product implementation because it is a separate, independently deployable, product-independent application. StoX is its first intended client, but building the Telemetry product and integrating StoX with it are separate roadmap concerns.

V8 also contains the one-time Historical Fundamental Data Bootstrap, deferred from V7 until the historical dataset/source is prepared and selected, and a guest-to-admin **Account Access Request** workflow that preserves admin-controlled onboarding while removing the need for an applicant to obtain an invitation before expressing interest.

## 2. Current V8 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-052 | Standalone Telemetry Platform | Build the product-independent telemetry/analytics platform as a separate repository/application. Core architecture is already decided in `specs/V7-Telemetry-Platform.md`; the historical filename is retained for now, but this V8 register supersedes its earlier V7 roadmap placement. | CORE ARCHITECTURE DECIDED |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | One-time population of StoX with available historical quarterly and annual fundamental data after the V7 canonical fundamental model exists. The import may use custom/offline scripts rather than the FEAT-053 live fetcher. StoX imposes no fixed historical-depth window; the canonical store and downstream analytics must support whatever depth is imported. Detailed source, extraction method, mapping and bootstrap procedure remain OPEN until the historical dataset is prepared/selected. | OPEN / DEFERRED |
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow linked from Login. Human applicants submit the information required for admin onboarding behind CAPTCHA and abuse controls. Pending duplicates are deduplicated; admins review requests in User Management, receive operational notifications, and resolve them as **Create**, **Ignore**, or **Reject**. Reject creates a reversible email ban; Ignore closes the request without banning; Create hands off into the existing secure admin onboarding/invite flow with request details prefilled. | WISHLIST / NEEDS DESIGN |

## 3. V4-FEAT-055 — Account Access Request / Admin Approval Workflow

### 3.1 Product intent

StoX remains **admin-controlled / invite-only** for actual account creation. This feature does **not** introduce open self-registration.

It adds a public way for a prospective user to ask an administrator for access:

```text
Login
  -> Request an account
      -> CAPTCHA + request form
          -> pending request
              -> Admin Create -> existing secure create/invite flow
              -> Admin Ignore -> closed; applicant may request again later
              -> Admin Reject -> closed + email banned
                                  -> Admin may later clear ban
```

The applicant never chooses their own role, receives admin privileges, or creates an account merely by submitting the request.

### 3.2 Guest request form

The Login page SHALL expose a clear **Request an account** link to a dedicated unauthenticated form.

The form SHALL collect the fields required by the current admin onboarding contract. Initial V8 design should at minimum support:

- full/display name;
- normalized email address;
- any additional non-secret fields that the eventual Admin **Create user** flow requires.

The request model and Admin create form SHOULD share a common field contract so they cannot silently drift apart as onboarding fields evolve.

The form MUST NOT collect:

- password;
- TOTP/recovery secrets;
- broker credentials;
- API tokens;
- requested Admin role or execution entitlement.

Password establishment remains inside the existing secure invitation/onboarding flow.

### 3.3 Human / abuse verification

Submission SHALL require server-verified CAPTCHA or an equivalent human-verification mechanism.

Design requirements:

- CAPTCHA validation occurs server-side;
- CAPTCHA response tokens are transient and are not persisted as applicant data;
- provider keys remain server-side;
- failure degrades explicitly rather than silently accepting unverified submissions;
- public endpoint remains rate-limited in addition to CAPTCHA;
- rate limits SHOULD consider normalized email and source/IP dimensions;
- repeated failed CAPTCHA/submission attempts must not create request rows.

The CAPTCHA implementation SHOULD be provider-abstracted so StoX is not permanently coupled to one vendor.

### 3.4 Identity normalization and duplicate handling

Email is the primary request identity and SHALL be normalized consistently with existing users/invites.

Before creating a new request, the system SHALL distinguish:

1. **existing StoX user** — do not create an access request; direct the person to normal login/password-recovery guidance;
2. **active/pending invitation already exists** — do not create a duplicate request; tell the applicant that onboarding is already in progress and to use/contact the administrator for the invitation;
3. **pending access request already exists** — do not create another row; return a clear, non-destructive "request already pending" message;
4. **email is on rejected/banned list** — do not create a request; return the product-defined blocked-request message;
5. **previous request was ignored/closed without ban** — a future request may be created normally;
6. **no conflict** — create a new pending request.

Duplicate protection SHOULD be enforced transactionally/database-backed where practical, not only by a frontend check.

### 3.5 Request lifecycle

Minimum lifecycle:

```text
pending
  -> created
  -> ignored
  -> rejected
```

Recommended semantic meaning:

- **pending** — awaiting Admin action;
- **created** — Admin chose Create and the request has been handed into the secure account onboarding flow;
- **ignored** — request closed without a ban; a new request from the same email is allowed later;
- **rejected** — request closed and the normalized email is placed on the rejected/banned list.

The design SHALL define whether `created` means "invite/onboarding issued" or "user account ultimately accepted." Preferred V8 semantics are:

- access request becomes `created` when Admin successfully initiates the existing invitation/account-create workflow;
- link the request to the resulting invite and, once accepted, to the resulting user where feasible;
- do not reopen the request merely because an invitation later expires.

All transitions must be auditable and idempotent.

### 3.6 Admin experience

User Management/Admin SHALL expose:

- pending request count/badge;
- request list;
- request detail;
- applicant name/email and submitted metadata;
- submitted timestamp;
- current status;
- prior request history for the same normalized email;
- ban/rejection state where applicable;
- Admin actor and decision timestamp/reason.

Admin actions:

#### Create

- opens the existing Admin user/invite creation flow;
- pre-populates all compatible request fields;
- allows Admin to review/edit allowed onboarding fields before committing;
- actual user creation/onboarding still uses the existing security model;
- success links the request to the generated invite/user workflow;
- concurrent double-click/two-admin resolution must not create duplicate users or invites.

#### Ignore

- closes the request;
- optionally records an Admin note/reason;
- does **not** ban the email;
- applicant may submit a future request;
- any future anti-spam cooldown, if introduced, must be explicit and configurable rather than silently treating Ignore as Reject.

#### Reject

- closes the request;
- adds the normalized email to a rejected/banned identity list;
- optionally records a reason/internal note;
- future requests for that identity are blocked until an Admin clears the ban.

### 3.7 Rejected / banned identities

The Admin UI SHALL provide a dedicated rejected/banned list with:

- normalized email;
- originating request;
- rejected by;
- rejected at;
- optional internal reason;
- **Clear ban / Allow future requests** action.

Clearing a ban SHALL:

- be explicit and confirmed;
- be audited;
- permit a future request;
- not resurrect the original rejected request automatically.

A ban applies to the **request workflow**, not to an already-existing StoX user account unless a separate account-administration action explicitly says otherwise.

### 3.8 Notifications

Creation of a new pending request SHALL notify administrators using the existing StoX notification architecture.

Initial channels:

- in-app/admin notification;
- Telegram;
- email where configured;
- other enabled notification channels supported by the notification subsystem.

Notification requirements:

- target the appropriate Admin recipients, not the applicant;
- include enough non-secret context to identify the request;
- link/deep-link to the Admin request detail where supported;
- deduplicate retries/redelivery so one request does not create notification storms;
- notification delivery failure must **not** delete or roll back a valid access request;
- channel delivery status remains notification state, not request-domain state.

### 3.9 Security and privacy

The public workflow SHALL preserve existing authentication/security boundaries.

At minimum:

- no open `POST /register` equivalent;
- no applicant-controlled role or entitlement;
- Admin-only list/detail/resolve/ban APIs;
- server-side authorization on every Admin action;
- normalized and validated input;
- no password or credential collection;
- CAPTCHA + rate limiting;
- CSRF/session rules appropriate to public vs Admin endpoints;
- request data retained only as long as product policy requires;
- sensitive internal rejection notes are never returned to the applicant;
- public responses should reveal only the minimum useful state needed for the submitting applicant.

### 3.10 Auditability and concurrency

Persist an immutable/auditable decision trail covering:

- request submitted;
- duplicate prevented where useful for operational metrics without storing unnecessary PII;
- Admin Create / Ignore / Reject;
- ban created;
- ban cleared;
- linked invite/user identifier when Create succeeds.

Two Admins acting concurrently on the same pending request SHALL result in one authoritative transition. Losing requests receive a clear "already resolved" response rather than producing duplicate side effects.

### 3.11 Operational/admin filters

Admin request list SHOULD support at least:

- status;
- submitted date range;
- email/name search;
- pending-only default view;
- rejected/banned view.

Useful counters:

- pending;
- created;
- ignored;
- rejected;
- banned identities.

### 3.12 Initial acceptance criteria

1. Guest can reach **Request an account** from Login without authenticating.
2. CAPTCHA failure creates no request.
3. Valid first submission creates exactly one pending request.
4. Repeating a pending request for the same normalized email creates no second pending request and returns a clear duplicate message.
5. Existing-user email creates no request and is directed to existing-account recovery/login guidance.
6. Existing pending invite creates no duplicate request.
7. Admin receives a visible pending request and notification.
8. Non-admin cannot list, inspect, resolve, reject, or unban requests.
9. **Ignore** closes the request without banning; a later request for that email is allowed.
10. **Reject** closes the request and blocks subsequent requests for that email.
11. Admin can clear the rejection ban; a later request is then allowed.
12. **Create** opens the existing secure user/invite onboarding flow with compatible fields prefilled.
13. Successful Create cannot create a duplicate account/invite even under concurrent Admin actions.
14. Applicant-supplied request data never sets Admin role, automated-execution entitlement, password, broker credentials, or other privileged state.
15. Notification-provider failure does not lose or revert the pending request.
16. Admin decision and ban/unban actions are auditable.

### 3.13 Open design decisions

The detailed V8 design should explicitly decide:

- CAPTCHA provider and fallback behavior;
- exact applicant fields beyond name/email;
- whether Ignore has a minimum resubmission cooldown;
- retention/anonymization period for old ignored/rejected requests;
- wording shown for banned identities;
- whether rejection reason is entirely internal or supports a separate applicant-facing generic reason;
- whether email confirmation/OTP is required before a request becomes pending;
- whether Admin Create hands off to the current invite flow only or a future direct-create workflow;
- whether a successful Create notification should also be sent to the applicant, separate from the invitation itself.

## 4. Boundary

V8 owns the Telemetry product itself: ingestion, storage, analytics/query APIs, dashboards/explorer, SDKs, identity/correlation model, events/metrics/logs/traces, retention, export, deletion, administration and the other capabilities frozen in the Telemetry architecture specification.

V8 also owns the one-time historical fundamental bootstrap outcome, but does not change FEAT-053's ongoing provider-driven fundamental ingestion architecture.

V8 owns the account-access request workflow through Admin disposition and handoff into the existing secure user onboarding flow. It does **not** replace the existing invitation/token security model, establish open registration, or allow a guest request to grant any account privilege by itself.

V8 does **not** own StoX-specific Telemetry instrumentation/integration. That is planned as a separate V9 StoX epic.
