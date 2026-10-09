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
