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
