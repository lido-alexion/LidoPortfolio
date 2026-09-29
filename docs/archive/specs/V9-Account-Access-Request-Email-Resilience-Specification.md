# StoX V9 Account Access Request Email Resilience Specification

| Field | Value |
|---|---|
| **Epic** | `V9-COMM-001` — StoX Email Notifications & Account Lifecycle Messaging |
| **Related inherited feature** | `V4-FEAT-055` — Account Access Request / Admin Approval Workflow |
| **Version target** | V9 beta hardening |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Owner** | Product / Architecture |
| **Parent register** | [`LidoPortfolio-V9-Wishlist.md`](LidoPortfolio-V9-Wishlist.md) |
| **Parent communication spec** | [`V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md`](V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md) |

## 1. Purpose

StoX beta must not make external email deliverability a single point of failure for account-access administration. SMTP acceptance does not guarantee inbox delivery, and production acceptance testing showed inconsistent delivery to otherwise valid mailboxes.

Email verification remains useful evidence, but it is not a prerequisite for an Admin to review or approve an access request during beta.

## 2. Frozen lifecycle

1. The public requester completes the normal access-request form and mandatory Turnstile/human-verification check.
2. StoX creates the access request immediately and makes it visible in the Admin portal.
3. The request starts with `verification_status = unverified`.
4. StoX attempts to send the verification email asynchronously.
5. If the verification link is successfully used before expiry, the same request transitions to `verification_status = verified`.
6. Admin may approve, reject or otherwise process either a verified or an unverified pending request.
7. Email verification is advisory identity evidence, not the authority that grants access.

Turnstile remains mandatory. This change must not weaken bot-abuse protection or permit direct public account creation.

## 3. Separate request and verification state

Request lifecycle and email-verification state must remain separate concepts.

Request status should continue to represent business lifecycle, for example:

- `pending`
- `approved` / `invite_created` according to the existing implementation model
- `rejected`
- `ignored`
- `expired`

Verification state is independently:

- `unverified`
- `verified`

Do not encode combinations such as `pending_verified` or `pending_unverified` unless required by an existing persistence constraint. The product model is explicitly two-dimensional.

## 4. Admin portal behavior

Every pending request must display a clear verification indicator:

- **Verified** — verification link was successfully completed.
- **Unverified** — no successful email verification exists yet.

Unverified requests must appear in the same pending-review workflow rather than being hidden until verification.

Admin actions for an unverified request must include the normal review actions plus, where available:

- **Resend verification email**
- **Approve anyway**
- **Reject**
- **Ignore**

Approving an unverified request is permitted. The UI should show a concise confirmation such as:

> This email address has not been verified. Approve this request anyway?

This confirmation is informational and must not become a technical blocker.

## 5. Seven-day unverified cleanup

A request that remains both `pending` and `unverified` for seven days after submission must leave the active Admin pending queue automatically.

The system must not hard-delete it. Instead, retain auditable history using an expired/inactive lifecycle state such as:

- `status = expired`
- `expiry_reason = unverified_timeout`

A verified request is not subject to this seven-day unverified timeout merely because seven days have elapsed. Other existing expiry/retention rules may still apply independently.

An expired request must not be silently resurrected by a late verification-link click. The requester must submit a fresh request.

## 6. Verification email retry

Admin may resend verification email for a still-active unverified request.

Resend behavior must preserve existing security rules for tokens and expiry. It must not create duplicate pending business requests solely because the email was retried.

Delivery state must distinguish, as far as StoX can know:

- queued
- accepted/sent by the SMTP layer
- failed

StoX must not label SMTP acceptance as confirmed inbox delivery.

## 7. Approval and invitation behavior

Admin approval of either a verified or unverified request follows the same existing invitation/account-creation path.

Approval must not create a bypass account or alternate authentication mechanism. It must create/use the normal StoX invitation semantics, including existing single-use and expiry behavior.

For an unverified approval, preserve explicit audit evidence that the Admin intentionally approved without successful email verification, including at minimum:

- request ID
- verification state at approval time
- approving Admin identity
- approval timestamp

## 8. Automated approval/invitation email plus manual fallback

After approval/invite creation, StoX should attempt normal automated email delivery.

The Admin portal must also preserve/reintroduce the pre-V8 manual fallback:

**Copy invitation email**

The copied content must be a complete ready-to-send message suitable for pasting into Gmail, Outlook or another normal mailbox and should include:

- recipient name
- concise account/invitation explanation
- the existing generated StoX invitation URL
- relevant invitation-expiry information
- StoX signature/identity

The manual fallback must reuse the already-created invitation/token. It must not create an alternate token, bypass token or parallel invitation model.

Where authorized and already supported, a separate **Copy invitation link** action may remain available.

## 9. Security and abuse boundaries

The beta resilience path must preserve:

- mandatory Turnstile on the public request form;
- existing duplicate/existing-user abuse policy;
- authorization around all Admin actions;
- normal invitation expiry/single-use semantics;
- audit logging for approval and invitation creation;
- no passwords in email or copied content;
- no account activation solely because an email was sent or copied.

Unverified approval is a deliberate Admin decision, not automatic fallback behavior.

## 10. Existing-user response behavior

When a submitted email already belongs to an existing StoX account, the backend must continue to block creation of a new access request/verification flow.

The public response must not falsely state that a verification email was sent. Response wording must also avoid unintended account-enumeration leakage. A safe generic response may direct existing users toward login without explicitly confirming account existence.

This requirement is also tracked by GitHub issue #14.

## 11. Testing requirements

Automated tests must cover at minimum:

- new request becomes Admin-visible immediately before email verification;
- initial verification state is unverified;
- successful verification transitions the same request to verified;
- Admin can approve an unverified request;
- unverified approval records explicit audit evidence;
- verified approval remains unchanged;
- resend verification does not create duplicate business requests;
- pending + unverified requests expire after seven days;
- verified requests are not expired by the unverified seven-day rule;
- late verification cannot resurrect an expired request;
- automated invite email failure does not invalidate the created invite;
- Copy invitation email uses the existing valid invitation/link;
- manual copy action cannot mint an unauthorized alternate token;
- existing-user request does not create/send verification and does not show a false success claim;
- authorization, Turnstile and invitation security boundaries remain intact.

## 12. Acceptance criteria

This V9-COMM-001 beta-resilience slice is complete only when:

- unverified requests appear in Admin pending review immediately;
- Admin can distinguish verified and unverified requests at a glance;
- Admin can approve an unverified request after an explicit warning/confirmation;
- no backend rule blocks approval solely because verification is incomplete;
- unverified pending requests expire from the active queue after seven days without hard deletion;
- late verification cannot resurrect an expired request;
- verification resend is available for active unverified requests;
- approval creates the normal StoX invite regardless of verification state;
- automated approval/invite email is attempted normally;
- Admin can copy a complete invitation email using the same existing invite as a manual-delivery fallback;
- audit history preserves whether approval occurred while unverified;
- Turnstile and existing security boundaries remain mandatory;
- existing-account submissions no longer produce a misleading sent-email message;
- automated tests cover the above states and transitions.

## 13. Frozen PO decisions

- Email verification is optional for Admin approval during beta.
- All valid Turnstile-passed requests enter Admin pending review immediately.
- Verification state is visible as verified/unverified and is separate from request lifecycle state.
- Admin may approve unverified requests at their discretion.
- Unverified pending requests leave the active queue after seven days and remain auditable rather than being hard-deleted.
- Verified requests are not subject to the seven-day unverified cleanup rule.
- Expired unverified requests are not resurrected by late verification.
- Admin can resend verification email while a request is active.
- Approval/invitation mail is still sent automatically when possible.
- The pre-V8 Copy invitation email/manual-send fallback is preserved/reintroduced.
- Manual fallback reuses the same created invitation and never creates a bypass token.
- Turnstile remains mandatory and unverified approval remains an explicit Admin decision.
