# StoX User Journeys — Account Entry

[Back to journey index](README.md)

These journeys cover the authentication prerequisite for using protected investor workflows. Public static documentation remains available without signing in.

---

## AUTH-01 — Sign in and return to the requested page

**Goal:** Open a protected StoX page while signed out, authenticate, and continue to that page.

1. Open a protected destination such as `/screeners` while signed out.
2. StoX displays the Login form and keeps the requested destination for after authentication.
3. Enter the account email and password. Choose whether to remember the session on this device.
4. Select **Login**.
5. If authentication succeeds, StoX opens the protected destination that was requested.

**Expected result:** The investor sees the requested page after signing in. Protected content is not shown before authentication.

**Important:** Public documentation can be opened without signing in. New accounts are invite-only; an invitation setup link is separate from the normal sign-in flow.

---

## AUTH-02 — Recover from rejected credentials

**Goal:** Correct a sign-in mistake without losing the sign-in page or requested destination.

1. Open a protected page while signed out, or open `/login` directly.
2. Enter an incorrect email/password combination and select **Login**.
3. Read the sign-in error and correct the credentials.
4. Select **Login** again.

**Expected result:** Rejected credentials do not authenticate the account or expose protected content. The error is announced to assistive technology, and the investor can retry. A successful retry returns to the requested destination when one was saved.

**Recovery:** If the account is pending invitation setup, use the administrator-provided invitation link rather than repeatedly retrying normal sign-in.


## AUTH-03 — End a session and return through sign-in

**Goal:** Stop account access on a device and recover access through explicit login when needed.

1. Open the profile menu from a protected page and choose Logout.
2. Confirm that protected investor content is no longer visible.
3. Reopen the intended protected page. If the previous session expired or was ended, sign in again.
4. Confirm the intended page is restored only after successful sign-in.

**Expected result:** Ending a session clears the signed-in UI. Neither logout nor sign-in submits a broker order. A stale session must not reveal a protected page after refresh.

## AUTH-04 — Recover a password through an administrator-issued link

**Goal:** Recover access using a valid reset link without changing an unrelated account or session.

**Admin prerequisite:** An authorized Admin uses `/settings/users` → Password reset links, selects the intended existing User and creates the standard 72-hour capability. Recover unavailable lists with Retry user management. Rejected creation retains that User for explicit retry; confirmed creation exposes Copy link / Copy message for private handoff. An Investor cannot issue this capability. Actual token signing, expiry, account/session effects and Admin authorization remain backend requirements.

- Requires: An administrator-issued, unexpired reset link for the intended account.
- Warning: Use only the intended account's link. An expired, revoked or already-used link requires the administrator to issue a new one. Browser CI uses synthetic tokens and credentials only.

1. Open the administrator-issued reset link and confirm the displayed account.
2. Enter and confirm a new password of at least eight characters. Choose whether to remember this device.
3. Choose Update password & sign in.
4. If the server rejects the submission, read the error, correct the values and retry from the same form.
5. After acceptance, confirm the signed-in account is shown and the requested valid destination or Dashboard opens.
6. If link validation reports expiry or invalidity, return with Back to sign in and obtain a new administrator-issued link. No reset submission is offered for that invalid link.

**Expected result:** The server validates the capability and credentials before accepting the reset. Accepted resets rotate the remember token and invalidate other sessions under the existing account-security contract. Rejected or invalid links never imply a successful credential change. Deterministic UI tests prove recovery and safe refusal; backend `PasswordResetLinkTest` remains the source of session-invalidation assurance.

## AUTH-05 — Maintain your profile and credentials

**Goal:** Keep the account identity and credentials current without changing portfolio state.

- Requires: An authenticated account. A password change also requires the current password.
- Warning: Enter real credentials only in the account UI. Test automation uses synthetic credentials and local API fixtures only.

1. Open `/profile` and confirm the account's read-only Username.
2. Edit Display name and choose Save name. Confirm the header identity changes and survives refresh.
3. If saving fails, read the error and retry with the intended name retained.
4. To change the password, enter Current password, New password and Confirm new password; choose Update password.
5. If current-password validation fails, correct it and retry. After acceptance, confirm the success feedback and that credential inputs are cleared. Other devices are signed out; this device remains signed in under the existing security contract.
6. To change the profile picture, select a local JPEG or PNG (maximum 5 MB). Unsupported formats are rejected before upload. Confirm the updated picture action, refresh, and use Remove photo when needed.

**Expected result:** Changes apply to the authenticated account only. These actions do not transfer cash, alter holdings, run a decision pipeline or send orders. UI fixtures prove name persistence, rejected-password correction, input clearing and photo lifecycle; backend `ProfileTest` owns actual authorization, file validation and cross-session effects.

## AUTH-06 — Accept an administrator-issued invitation and recover a rejected submission

- Requires: a current administrator-issued, single-use invitation link. Start from `/login` if you need to contact the administrator for a link.
- Search synonyms: accept invite, invitation password, invite expired.

1. Open the issued invitation link and confirm the fixed account email.
2. Enter your name and matching new password/confirmation, then create the account and sign in.
3. If validation or a transient acceptance error occurs, retain the form and correct/retry without losing the entered values or falsely becoming authenticated.
4. After successful acceptance, the application restores the account session and requested protected page, or the Dashboard.
5. An invalid, expired, revoked or used invitation offers return to sign-in; it must not create an account or session. Request a new invitation from the administrator.

**Expected result:** account creation stays inside the existing invite capability and Admin-controlled workflow. Credentials are never emailed or placed in the URL.

## AUTH-07 — Request account access and verify email ownership without self-registration

- Requires: a guest using `/request-account` and completing the mandatory human verification.
- Search synonyms: request account, request access, verify request email.

1. Enter your full name and email. Complete human verification before submitting the access request.
2. If verification is missing/unavailable, stop submission and try later. Correct a validation error or retry a transient request failure while retaining the form.
3. A successful eligible request is available for Admin review immediately, with email verification initially advisory. A safe generic response must not claim that a verification email was delivered or disclose whether an existing account exists.
4. If an emailed verification link is available, open it to verify ownership of the same pending request. Verification alone does not approve access or create a signed-in account.
5. Return to login or wait for the administrator's standard invitation. An expired/used verification link cannot resurrect an expired request; submit a fresh request if necessary.

**Expected result:** Turnstile and Admin invitation remain mandatory boundaries. The frozen V9 email-resilience rules supersede the earlier V8 requirement to verify before Admin review.

## AUTH-08 — Review an access request and preserve the standard invitation boundary

- Requires: an authorized Admin using `/settings/users`; an investor cannot perform these actions.
- Search synonyms: approve access request, unverified request, create invite, resend verification, ignore request, reject request, clear request ban.

1. Inspect pending Account access requests, their Verified/Unverified indication and observed email delivery state. An unavailable read is not an empty list; use Retry access requests. A failed filter read preserves known rows but requires fresh recovery before another decision. Review loads the selected request’s history/audit; retry that selected detail read if unavailable.
2. Review the request and choose Create invite. For an unverified request, read the confirmation; cancelling performs no action, while explicit approval records the unverified decision and issues the normal secure invitation.
3. If creation fails, preserve the pending request and retry after recovery. Email delivery failure does not invalidate a successfully created invitation.
4. Use Show all statuses to inspect the resolved request. Where manual fallback is needed, Copy invitation email/link uses the existing invitation and does not mint another token.
5. Where needed, resend verification for the same still-active unverified request. A rejected resend preserves the request; retry deliberately. Queued delivery does not mean Verified or actual mailbox receipt.
6. Use Ignore/Reject with an optional internal reason. Cancelling performs no action. A rejected decision preserves the pending request and reason for the next prompt; retry explicitly. Confirmed status preserves other requests. Applicant-facing messages must not disclose the internal reason or ban details.
7. If an authorized Admin deliberately clears a displayed active request ban, a rejected clear retains that ban; retry after recovery. Successful clearing does not approve a rejected request or issue an invitation.
8. To issue a direct standard invitation without a pending request, use Invite new users. An unavailable invitation list blocks creation; Retry user management recovers it, and Refresh user management deliberately refreshes known material. Rejected creation retains its email. Confirmed creation preserves the original expiry; Copy link / Copy invitation email on the pending invitation retrieves that same private capability after refresh. A failed email retry retains the invitation; queued retry and actual mailbox receipt remain separate.

**Expected result:** Admin controls approval, invitation capability and audit history. Verification is advisory, while invitation expiry/single-use, abuse controls and authorization remain authoritative. Queue delivery and actual mailbox receipt need separate runtime evidence.
## AUTH-09 — Recover owned session management without losing this device

- Requires: An authenticated account.
- Warning: Revoke invalidates the selected non-current session. Log out other devices preserves this device. The current Log out action follows AUTH-03 and clears local authority.

1. Open Settings → Account (`/settings/account`) and inspect Active sessions. Retry active sessions recovers an unavailable list; failure is not a confirmed empty account.
2. Identify This device and the intended other device. Revoke the non-current session explicitly. An unconfirmed failure retains its row and permits deliberate retry after inspection.
3. Use Log out other devices to revoke the remaining non-current sessions. Failure preserves displayed evidence and provides read recovery before retry.
4. Confirm that This device remains active after successful other-session revocation. Foreign account sessions are never available to this workflow.

**Expected result:** Session recovery preserves user ownership and separates this device from targeted/other-session revocation.

## AUTH-10 — Recover StoX execution-code enrollment without broker authority

- Requires: An authenticated account and an authenticator app.
- Warning: A StoX execution code provides sensitive-action proof; it does not grant entitlement, broker readiness or permission to submit an order. Kite login OTPs are entered only on Kite/Zerodha. Recovery codes are separate one-time backups and must be stored privately.

1. Open Settings → Account (`/settings/account`) → StoX execution code. If current status is unavailable, Retry execution-code status before changing enrollment; unknown state must not appear unconfigured.
2. Set up StoX execution code deliberately. A rejected request preserves existing enrollment state. Retry to provision the pending QR/secret.
3. Enter an optional Authenticator app name and current code, then Confirm. Rejected proof retains entered setup fields for correction/retry.
4. After confirmed activation, save the newly issued one-time StoX recovery codes. If the follow-up status read fails, codes remain available and Retry execution-code status recovers the authoritative enabled state.
5. To Disable, provide the identified current proof and submit deliberately. Rejected proof preserves enabled state and entered draft; confirmed disable clears active/pending state and displayed recovery codes.

**Expected result:** Enrollment/confirmation/disable failures are recoverable and remain separate from trading authority or broker login.

## AUTH-11 — Create and revoke personal API tokens with limited scope

- Requires: An authenticated owner of the account.
- Warning: A personal API token is separate from the browser session. Its abilities never bypass ownership, Admin, entitlement, TOTP or execution-safety checks. Plaintext is shown only at creation; store it privately. Prefer only the abilities needed for the intended integration.

1. Open Settings → Account (`/settings/account`) → Personal API tokens. Retry personal API tokens recovers an unavailable list; failure is not a confirmed empty list.
2. Enter Token name and select abilities deliberately. A blank name or no ability cannot be submitted. A rejected creation retains the name/selection for correction/retry.
3. Create token explicitly and save its display-once plaintext. Inspect its persisted metadata/scopes independently of this browser session.
4. Cancel Revoke token to preserve it. A rejected confirmed revocation retains the row and warns that the write is unconfirmed; inspect and retry deliberately.
5. Confirmed revocation removes only the owned token. Unknown or foreign token IDs are not usable resources.

**Expected result:** Token recovery preserves least-privilege selection, owner boundaries, display-once secret handling and explicit revocation.
