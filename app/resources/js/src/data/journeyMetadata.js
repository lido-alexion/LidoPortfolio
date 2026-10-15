/** Generated from docs/user-journeys/*.md; do not edit manually. */
export const JOURNEY_METADATA_VERSION = 'v9.ux001.generated';
export const JOURNEY_TOPICS = Object.freeze([
  {
    "id": "AI-01",
    "title": "How do I ask a grounded product question?",
    "aliases": [
      "Ask a grounded product question",
      "ai-01"
    ],
    "keywords": [
      "ask",
      "grounded",
      "product",
      "question"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-01-ask-a-grounded-product-question",
    "steps": [
      "Sign in and choose Ask StoX in the global header.",
      "Ask a product question such as “How do I create a screener?”",
      "Read the answer and its non-numeric grounding state.",
      "Expand Sources used for titles, sections, links and supporting snippets.",
      "Use a source link to navigate to maintained documentation. Existing authorization still applies to application pages."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-02",
    "title": "How do I follow up, copy and give feedback?",
    "aliases": [
      "Follow up, copy and give feedback",
      "ai-02"
    ],
    "keywords": [
      "follow",
      "copy",
      "and",
      "give",
      "feedback"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-02-follow-up-copy-and-give-feedback",
    "steps": [
      "Ask a follow-up or select an explanation prompt below an answer.",
      "Choose Copy response to copy the answer and source links.",
      "Choose Helpful, or expand Not helpful, optionally enter a comment and send feedback.",
      "Choose Clear conversation to remove the current conversation and cancel a pending answer."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-03",
    "title": "How do I recover when AI is unavailable?",
    "aliases": [
      "Recover when AI is unavailable",
      "ai-03"
    ],
    "keywords": [
      "recover",
      "when",
      "unavailable"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-03-recover-when-ai-is-unavailable",
    "steps": [
      "Open the assistant while the runtime is unavailable or its budget is exhausted.",
      "Ask a question and observe the temporary-unavailability message.",
      "Use How do I? links or Browse documentation.",
      "Close the drawer with Close assistant or Escape. Focus returns to Ask StoX."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-04",
    "title": "How do I investigate account evidence?",
    "aliases": [
      "Investigate account evidence",
      "ai-04"
    ],
    "keywords": [
      "investigate",
      "account",
      "evidence"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-04-investigate-account-evidence",
    "steps": [
      "Open Ask StoX and select Account investigation and actions.",
      "Ask an account question, such as “How concentrated are my holdings?”",
      "Read the answer and any missing-evidence disclosure.",
      "Expand Investigation trace to see which tools supplied evidence."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-05",
    "title": "How do I preview and approve changes?",
    "aliases": [
      "Preview and approve changes",
      "ai-05"
    ],
    "keywords": [
      "preview",
      "and",
      "approve",
      "changes"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-05-preview-and-approve-changes",
    "steps": [
      "In account mode, request a supported change, such as creating a watchlist.",
      "Review Proposed changes, affected objects, warnings and Review field changes.",
      "Choose Approve changes or Reject plan. Deletions also require the explicit deletion checkbox.",
      "Read the verified action results. If state changed or approval expired, choose Build a fresh plan and review the new preview."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-06",
    "title": "How do I inspect action history and recover?",
    "aliases": [
      "Inspect action history and recover",
      "ai-06"
    ],
    "keywords": [
      "inspect",
      "action",
      "history",
      "and",
      "recover"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-06-inspect-action-history-and-recover",
    "steps": [
      "Open Run history in the existing assistant drawer.",
      "Review a run's original objective, portfolio, preview, trace and action results.",
      "For a failed, partial, expired or stale run, choose Build a fresh plan.",
      "Review and approve any new mutations separately."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-07",
    "title": "How do I open a stock insight?",
    "aliases": [
      "Open a stock insight",
      "ai-07"
    ],
    "keywords": [
      "open",
      "stock",
      "insight"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-07-open-a-stock-insight",
    "steps": [
      "Choose the AI puzzle icon beside a stock in Holdings, either Dashboard stock context, Watchlist rows, the selected Watchlist stock, or either Stock Explorer result card.",
      "Read the structured AI Insights and Based on / Data used dates. Missing evidence is disclosed.",
      "Held stocks show Personalized with your active portfolio holding. Other portfolios and private watchlist notes are excluded.",
      "Choose Copy insight, Refresh insight, or Open stock details.",
      "Close the section, pane or modal to continue using the page."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-08",
    "title": "How do I recover an embedded insight?",
    "aliases": [
      "Recover an embedded insight",
      "ai-08"
    ],
    "keywords": [
      "recover",
      "embedded",
      "insight"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-08-recover-an-embedded-insight",
    "steps": [
      "Open an insight or choose Refresh insight while managed AI is unavailable.",
      "If a matching saved result exists, continue reading it with the degraded status.",
      "Choose Copy AI Prompt to use the existing manual prompt workflow."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-09",
    "title": "How do I design and explicitly create a draft strategy?",
    "aliases": [
      "Design and explicitly create a draft strategy",
      "ai-09",
      "new strategy",
      "create strategy"
    ],
    "keywords": [
      "design",
      "and",
      "explicitly",
      "create",
      "draft",
      "strategy"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-09-design-and-explicitly-create-a-draft-strategy",
    "steps": [
      "Open AI Strategy Designer on Strategy and choose the structured design inputs.",
      "Choose Generate strategy. Read the summary, entry/exit logic, sizing, risk controls, assumptions, caveats and artifact compatibility notes.",
      "Use Copy result or Regenerate as needed. Returning to matching inputs restores the account's saved result; changed inputs hide mismatched results.",
      "Optionally choose Create draft strategy. Review the proposed changes and choose Approve changes or Reject plan.",
      "Read the verified result and inspect the run in assistant history. Stale or expired plans require a fresh preview and approval."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AUTH-01",
    "title": "How do I sign in and return to the requested page?",
    "aliases": [
      "Sign in and return to the requested page",
      "auth-01"
    ],
    "keywords": [
      "sign",
      "and",
      "return",
      "the",
      "requested",
      "page"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/screeners",
    "guide": "/docs/journeys/00-account-entry.html#auth-01-sign-in-and-return-to-the-requested-page",
    "steps": [
      "Open a protected destination such as `/screeners` while signed out.",
      "StoX displays the Login form and keeps the requested destination for after authentication.",
      "Enter the account email and password. Choose whether to remember the session on this device.",
      "Select Login.",
      "If authentication succeeds, StoX opens the protected destination that was requested."
    ],
    "prerequisites": [],
    "warnings": [
      "Public documentation can be opened without signing in. New accounts are invite-only; an invitation setup link is separate from the normal sign-in flow."
    ]
  },
  {
    "id": "AUTH-02",
    "title": "How do I recover from rejected credentials?",
    "aliases": [
      "Recover from rejected credentials",
      "auth-02"
    ],
    "keywords": [
      "recover",
      "from",
      "rejected",
      "credentials"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/login",
    "guide": "/docs/journeys/00-account-entry.html#auth-02-recover-from-rejected-credentials",
    "steps": [
      "Open a protected page while signed out, or open `/login` directly.",
      "Enter an incorrect email/password combination and select Login.",
      "Read the sign-in error and correct the credentials.",
      "Select Login again."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AUTH-03",
    "title": "How do I end a session and return through sign-in?",
    "aliases": [
      "End a session and return through sign-in",
      "auth-03"
    ],
    "keywords": [
      "end",
      "session",
      "and",
      "return",
      "through",
      "sign"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/login",
    "guide": "/docs/journeys/00-account-entry.html#auth-03-end-a-session-and-return-through-sign-in",
    "steps": [
      "Open the profile menu from a protected page and choose Logout.",
      "Confirm that protected investor content is no longer visible.",
      "Reopen the intended protected page. If the previous session expired or was ended, sign in again.",
      "Confirm the intended page is restored only after successful sign-in."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AUTH-04",
    "title": "How do I recover a password through an administrator-issued link?",
    "aliases": [
      "Recover a password through an administrator-issued link",
      "auth-04"
    ],
    "keywords": [
      "recover",
      "password",
      "through",
      "administrator",
      "issued",
      "link"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/settings/users",
    "guide": "/docs/journeys/00-account-entry.html#auth-04-recover-a-password-through-an-administrator-issued-link",
    "steps": [
      "Open the administrator-issued reset link and confirm the displayed account.",
      "Enter and confirm a new password of at least eight characters. Choose whether to remember this device.",
      "Choose Update password & sign in.",
      "If the server rejects the submission, read the error, correct the values and retry from the same form.",
      "After acceptance, confirm the signed-in account is shown and the requested valid destination or Dashboard opens.",
      "If link validation reports expiry or invalidity, return with Back to sign in and obtain a new administrator-issued link. No reset submission is offered for that invalid link."
    ],
    "prerequisites": [
      "An administrator-issued, unexpired reset link for the intended account."
    ],
    "warnings": [
      "Use only the intended account's link. An expired, revoked or already-used link requires the administrator to issue a new one. Browser CI uses synthetic tokens and credentials only."
    ]
  },
  {
    "id": "AUTH-05",
    "title": "How do I maintain your profile and credentials?",
    "aliases": [
      "Maintain your profile and credentials",
      "auth-05"
    ],
    "keywords": [
      "maintain",
      "your",
      "profile",
      "and",
      "credentials"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/profile",
    "guide": "/docs/journeys/00-account-entry.html#auth-05-maintain-your-profile-and-credentials",
    "steps": [
      "Open `/profile` and confirm the account's read-only Username.",
      "Edit Display name and choose Save name. Confirm the header identity changes and survives refresh.",
      "If saving fails, read the error and retry with the intended name retained.",
      "To change the password, enter Current password, New password and Confirm new password; choose Update password.",
      "If current-password validation fails, correct it and retry. After acceptance, confirm the success feedback and that credential inputs are cleared. Other devices are signed out; this device remains signed in under the existing security contract.",
      "To change the profile picture, select a local JPEG or PNG (maximum 5 MB). Unsupported formats are rejected before upload. Confirm the updated picture action, refresh, and use Remove photo when needed."
    ],
    "prerequisites": [
      "An authenticated account. A password change also requires the current password."
    ],
    "warnings": [
      "Enter real credentials only in the account UI. Test automation uses synthetic credentials and local API fixtures only."
    ]
  },
  {
    "id": "AUTH-06",
    "title": "How do I accept an administrator-issued invitation and recover a rejected submission?",
    "aliases": [
      "Accept an administrator-issued invitation and recover a rejected submission",
      "auth-06"
    ],
    "keywords": [
      "accept",
      "administrator",
      "issued",
      "invitation",
      "and",
      "recover",
      "rejected",
      "submission"
    ],
    "synonyms": [
      "accept invite",
      "invitation password",
      "invite expired."
    ],
    "category": "Account access",
    "route": "/login",
    "guide": "/docs/journeys/00-account-entry.html#auth-06-accept-an-administrator-issued-invitation-and-recover-a-rejected-submission",
    "steps": [
      "Open the issued invitation link and confirm the fixed account email.",
      "Enter your name and matching new password/confirmation, then create the account and sign in.",
      "If validation or a transient acceptance error occurs, retain the form and correct/retry without losing the entered values or falsely becoming authenticated.",
      "After successful acceptance, the application restores the account session and requested protected page, or the Dashboard.",
      "An invalid, expired, revoked or used invitation offers return to sign-in; it must not create an account or session. Request a new invitation from the administrator."
    ],
    "prerequisites": [
      "a current administrator-issued, single-use invitation link. Start from `/login` if you need to contact the administrator for a link."
    ],
    "warnings": []
  },
  {
    "id": "AUTH-07",
    "title": "How do I request account access and verify email ownership without self-registration?",
    "aliases": [
      "Request account access and verify email ownership without self-registration",
      "auth-07"
    ],
    "keywords": [
      "request",
      "account",
      "access",
      "and",
      "verify",
      "email",
      "ownership",
      "without",
      "self",
      "registration"
    ],
    "synonyms": [
      "request account",
      "request access",
      "verify request email."
    ],
    "category": "Account access",
    "route": "/request-account",
    "guide": "/docs/journeys/00-account-entry.html#auth-07-request-account-access-and-verify-email-ownership-without-self-registration",
    "steps": [
      "Enter your full name and email. Complete human verification before submitting the access request.",
      "If verification is missing/unavailable, stop submission and try later. Correct a validation error or retry a transient request failure while retaining the form.",
      "A successful eligible request is available for Admin review immediately, with email verification initially advisory. A safe generic response must not claim that a verification email was delivered or disclose whether an existing account exists.",
      "If an emailed verification link is available, open it to verify ownership of the same pending request. Verification alone does not approve access or create a signed-in account.",
      "Return to login or wait for the administrator's standard invitation. An expired/used verification link cannot resurrect an expired request; submit a fresh request if necessary."
    ],
    "prerequisites": [
      "a guest using `/request-account` and completing the mandatory human verification."
    ],
    "warnings": []
  },
  {
    "id": "AUTH-08",
    "title": "How do I review an access request and preserve the standard invitation boundary?",
    "aliases": [
      "Review an access request and preserve the standard invitation boundary",
      "auth-08"
    ],
    "keywords": [
      "review",
      "access",
      "request",
      "and",
      "preserve",
      "the",
      "standard",
      "invitation",
      "boundary"
    ],
    "synonyms": [
      "approve access request",
      "unverified request",
      "create invite",
      "resend verification",
      "ignore request",
      "reject request",
      "clear request ban."
    ],
    "category": "Account access",
    "route": "/settings/users",
    "guide": "/docs/journeys/00-account-entry.html#auth-08-review-an-access-request-and-preserve-the-standard-invitation-boundary",
    "steps": [
      "Inspect pending Account access requests, their Verified/Unverified indication and observed email delivery state. An unavailable read is not an empty list; use Retry access requests. A failed filter read preserves known rows but requires fresh recovery before another decision. Review loads the selected request’s history/audit; retry that selected detail read if unavailable.",
      "Review the request and choose Create invite. For an unverified request, read the confirmation; cancelling performs no action, while explicit approval records the unverified decision and issues the normal secure invitation.",
      "If creation fails, preserve the pending request and retry after recovery. Email delivery failure does not invalidate a successfully created invitation.",
      "Use Show all statuses to inspect the resolved request. Where manual fallback is needed, Copy invitation email/link uses the existing invitation and does not mint another token.",
      "Where needed, resend verification for the same still-active unverified request. A rejected resend preserves the request; retry deliberately. Queued delivery does not mean Verified or actual mailbox receipt.",
      "Use Ignore/Reject with an optional internal reason. Cancelling performs no action. A rejected decision preserves the pending request and reason for the next prompt; retry explicitly. Confirmed status preserves other requests. Applicant-facing messages must not disclose the internal reason or ban details.",
      "If an authorized Admin deliberately clears a displayed active request ban, a rejected clear retains that ban; retry after recovery. Successful clearing does not approve a rejected request or issue an invitation.",
      "To issue a direct standard invitation without a pending request, use Invite new users. An unavailable invitation list blocks creation; Retry user management recovers it, and Refresh user management deliberately refreshes known material. Rejected creation retains its email. Confirmed creation preserves the original expiry; Copy link / Copy invitation email on the pending invitation retrieves that same private capability after refresh. A failed email retry retains the invitation; queued retry and actual mailbox receipt remain separate."
    ],
    "prerequisites": [
      "an authorized Admin using `/settings/users`; an investor cannot perform these actions."
    ],
    "warnings": []
  },
  {
    "id": "AUTH-09",
    "title": "How do I recover owned session management without losing this device?",
    "aliases": [
      "Recover owned session management without losing this device",
      "auth-09"
    ],
    "keywords": [
      "recover",
      "owned",
      "session",
      "management",
      "without",
      "losing",
      "this",
      "device"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/settings/account",
    "guide": "/docs/journeys/00-account-entry.html#auth-09-recover-owned-session-management-without-losing-this-device",
    "steps": [
      "Open Settings → Account (`/settings/account`) and inspect Active sessions. Retry active sessions recovers an unavailable list; failure is not a confirmed empty account.",
      "Identify This device and the intended other device. Revoke the non-current session explicitly. An unconfirmed failure retains its row and permits deliberate retry after inspection.",
      "Use Log out other devices to revoke the remaining non-current sessions. Failure preserves displayed evidence and provides read recovery before retry.",
      "Confirm that This device remains active after successful other-session revocation. Foreign account sessions are never available to this workflow."
    ],
    "prerequisites": [
      "An authenticated account."
    ],
    "warnings": [
      "Revoke invalidates the selected non-current session. Log out other devices preserves this device. The current Log out action follows AUTH-03 and clears local authority."
    ]
  },
  {
    "id": "AUTH-10",
    "title": "How do I recover StoX execution-code enrollment without broker authority?",
    "aliases": [
      "Recover StoX execution-code enrollment without broker authority",
      "auth-10"
    ],
    "keywords": [
      "recover",
      "stox",
      "execution",
      "code",
      "enrollment",
      "without",
      "broker",
      "authority"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/settings/account",
    "guide": "/docs/journeys/00-account-entry.html#auth-10-recover-stox-execution-code-enrollment-without-broker-authority",
    "steps": [
      "Open Settings → Account (`/settings/account`) → StoX execution code. If current status is unavailable, Retry execution-code status before changing enrollment; unknown state must not appear unconfigured.",
      "Set up StoX execution code deliberately. A rejected request preserves existing enrollment state. Retry to provision the pending QR/secret.",
      "Enter an optional Authenticator app name and current code, then Confirm. Rejected proof retains entered setup fields for correction/retry.",
      "After confirmed activation, save the newly issued one-time StoX recovery codes. If the follow-up status read fails, codes remain available and Retry execution-code status recovers the authoritative enabled state.",
      "To Disable, provide the identified current proof and submit deliberately. Rejected proof preserves enabled state and entered draft; confirmed disable clears active/pending state and displayed recovery codes."
    ],
    "prerequisites": [
      "An authenticated account and an authenticator app."
    ],
    "warnings": [
      "A StoX execution code provides sensitive-action proof; it does not grant entitlement, broker readiness or permission to submit an order. Kite login OTPs are entered only on Kite/Zerodha. Recovery codes are separate one-time backups and must be stored privately."
    ]
  },
  {
    "id": "AUTH-11",
    "title": "How do I create and revoke personal API tokens with limited scope?",
    "aliases": [
      "Create and revoke personal API tokens with limited scope",
      "auth-11"
    ],
    "keywords": [
      "create",
      "and",
      "revoke",
      "personal",
      "api",
      "tokens",
      "with",
      "limited",
      "scope"
    ],
    "synonyms": [],
    "category": "Account access",
    "route": "/settings/account",
    "guide": "/docs/journeys/00-account-entry.html#auth-11-create-and-revoke-personal-api-tokens-with-limited-scope",
    "steps": [
      "Open Settings → Account (`/settings/account`) → Personal API tokens. Retry personal API tokens recovers an unavailable list; failure is not a confirmed empty list.",
      "Enter Token name and select abilities deliberately. A blank name or no ability cannot be submitted. A rejected creation retains the name/selection for correction/retry.",
      "Create token explicitly and save its display-once plaintext. Inspect its persisted metadata/scopes independently of this browser session.",
      "Cancel Revoke token to preserve it. A rejected confirmed revocation retains the row and warns that the write is unconfirmed; inspect and retry deliberately.",
      "Confirmed revocation removes only the owned token. Unknown or foreign token IDs are not usable resources."
    ],
    "prerequisites": [
      "An authenticated owner of the account."
    ],
    "warnings": [
      "A personal API token is separate from the browser session. Its abilities never bypass ownership, Admin, entitlement, TOTP or execution-safety checks. Plaintext is shown only at creation; store it privately. Prefer only the abilities needed for the intended integration."
    ]
  },
  {
    "id": "E2E-01",
    "title": "How do I new idea to first BUY?",
    "aliases": [
      "New idea to first BUY",
      "e2e-01"
    ],
    "keywords": [
      "new",
      "idea",
      "first",
      "buy"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/recommendations",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-01-new-idea-to-first-buy",
    "steps": [
      "Create and validate the discovery rule using [SCR-02](01-screeners.md#scr-02--create-a-multi-condition-and-screener).",
      "Run it once and inspect representative matches using [SCR-06](01-screeners.md#scr-06--run-a-screener-and-inspect-matches).",
      "Create the strategy and attach the screener using [STR-02](02-strategies.md#str-02--create-a-strategy-when-the-required-screener-does-not-exist).",
      "Configure scoring/thresholds, sizing, exit policy and market gates as required.",
      "Enable the strategy using [STR-13](02-strategies.md#str-13--enable-a-strategy).",
      "Run/wait for the decision pipeline using [REC-01](03-recommendations-review.md#rec-01--generate-recommendations-through-the-decision-pipeline).",
      "Open `/recommendations` and inspect the generated OPEN/INCREASE recommendation and its evidence.",
      "Confirm capital state and actual executable amount.",
      "Approve it using [REC-07](03-recommendations-review.md#rec-07--approve-a-recommendation).",
      "Open `/transactions/pending` and perform the final check using [EXE-05](04-execution-transactions.md#exe-05--review-pending-execution).",
      "Execute manually or through the configured broker-assisted mode.",
      "Verify the resulting transaction and strategy-owned holding using [EXE-15](04-execution-transactions.md#exe-15--verify-transaction-and-holding-after-execution)."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-02",
    "title": "How do I existing screener to BUY?",
    "aliases": [
      "Existing screener to BUY",
      "e2e-02"
    ],
    "keywords": [
      "existing",
      "screener",
      "buy"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/screeners",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-02-existing-screener-to-buy",
    "steps": [
      "Find and inspect the existing screener under `/screeners` or `/screeners/registry`.",
      "Validate/reuse it using [SCR-07](01-screeners.md#scr-07--reuse-or-import-an-existing-screener).",
      "Create the strategy using [STR-01](02-strategies.md#str-01--create-a-strategy-using-an-existing-screener).",
      "Configure and enable it.",
      "Run the decision pipeline.",
      "Review the generated recommendation.",
      "Approve if desired.",
      "Review Pending Execution.",
      "Execute.",
      "Verify the resulting transaction and holding."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-03",
    "title": "How do I exit signal to closed transaction?",
    "aliases": [
      "Exit signal to closed transaction",
      "e2e-03"
    ],
    "keywords": [
      "exit",
      "signal",
      "closed",
      "transaction"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/recommendations",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-03-exit-signal-to-closed-transaction",
    "steps": [
      "Confirm the holding belongs to the intended strategy.",
      "Ensure the strategy's exit policy is current.",
      "Run/wait for the decision pipeline.",
      "Open `/recommendations` and locate the `EXIT_POSITION` or `REDUCE_POSITION` recommendation.",
      "Inspect the primary exit reason and quantity.",
      "Confirm an entry market gate has not incorrectly suppressed the exit.",
      "Approve the actionable exit recommendation.",
      "Open `/transactions/pending`.",
      "Verify the SELL quantity belongs to this strategy's position.",
      "Submit/record the SELL.",
      "Wait for actual fill/reconciliation.",
      "Inspect `/transactions` and `/transactions/closed`.",
      "Verify the strategy-owned holding is reduced or closed as intended."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-04",
    "title": "How do I strategy change and supersession?",
    "aliases": [
      "Strategy change and supersession",
      "e2e-04"
    ],
    "keywords": [
      "strategy",
      "change",
      "and",
      "supersession"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/recommendations",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-04-strategy-change-and-supersession",
    "steps": [
      "Open the existing strategy and make the intended policy change using [STR-10](02-strategies.md#str-10--edit-an-existing-strategy).",
      "Save the strategy. Confirm that saving alone did not create or cancel recommendations.",
      "Run the decision pipeline separately.",
      "Open `/recommendations`.",
      "If the new decision materially changes the same strategy/security target, inspect the old recommendation's superseded state and its link to the replacement.",
      "Confirm the old recommendation is no longer treated as current executable intent.",
      "Review the new recommendation normally.",
      "Confirm old reservations/readiness were reconciled appropriately."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-05",
    "title": "How do I approve, cancel and retry?",
    "aliases": [
      "Approve, cancel and retry",
      "e2e-05"
    ],
    "keywords": [
      "approve",
      "cancel",
      "and",
      "retry"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-05-approve-cancel-and-retry",
    "steps": [
      "Approve the recommendation and confirm `pending_execution`.",
      "Start execution.",
      "If no broker submission occurred, cancel using [EXE-08](04-execution-transactions.md#exe-08--cancel-before-broker-submission).",
      "If a broker order was submitted, request cancellation using [EXE-09](04-execution-transactions.md#exe-09--cancel-a-submitted-broker-order).",
      "Wait for final broker confirmation. “Cancellation requested” is not final cancellation.",
      "Confirm whether any quantity filled before cancellation.",
      "Reconcile partial fills exactly once.",
      "Confirm the recommendation remains current/eligible for retry according to lifecycle policy.",
      "Retry using [EXE-14](04-execution-transactions.md#exe-14--retry-without-creating-a-duplicate-order)."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-06",
    "title": "How do I insufficient capital to execution?",
    "aliases": [
      "Insufficient capital to execution",
      "e2e-06"
    ],
    "keywords": [
      "insufficient",
      "capital",
      "execution"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-06-insufficient-capital-to-execution",
    "steps": [
      "Open the recommendation and confirm the underlying action is OPEN/INCREASE.",
      "Compare desired target with currently fundable amount.",
      "Inspect the capital-resolution path: own capital, recall, lending/bridge or other supported source.",
      "Do not rewrite the investment opinion merely because funding is unavailable.",
      "Complete the supported capital-resolution step if you choose to fund it.",
      "Return to the recommendation and confirm capital state/readiness changed.",
      "Approve when lifecycle rules permit.",
      "In `/transactions/pending`, verify actual executable amount and whole-share quantity.",
      "Execute.",
      "Verify actual transaction, cash and holding state."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-07",
    "title": "How do I same stock in two strategies?",
    "aliases": [
      "Same stock in two strategies",
      "e2e-07"
    ],
    "keywords": [
      "same",
      "stock",
      "two",
      "strategies"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-07-same-stock-in-two-strategies",
    "steps": [
      "Open Strategy A's recommendation.",
      "Confirm it is `EXIT_POSITION` and its owned quantity is 10 in this example.",
      "Confirm Strategy B remains a separate 15-share position/context.",
      "Approve Strategy A's exit.",
      "In Pending Execution, verify the SELL quantity is 10, not the portfolio-wide 25.",
      "Execute and reconcile the actual fill.",
      "Verify Strategy A's holding episode closes.",
      "Verify Strategy B still owns its 15 shares and retains its own basis/policy/evidence."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-08",
    "title": "How do I admin production ML acceptance?",
    "aliases": [
      "Admin production ML acceptance",
      "e2e-08"
    ],
    "keywords": [
      "admin",
      "production",
      "acceptance"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/settings/ml-scoring",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-08-admin-production-ml-acceptance",
    "steps": [
      "As StoX Admin, open Settings → ML Scoring (`/settings/ml-scoring`) and refresh Production ML acceptance. Unknown evidence remains blocking.",
      "Queue campaign preflight for the desired cutoff; inspect each horizon's exact required source dates and blocking reasons.",
      "Upload official dated NSE CSV/ZIP sources in the private upload panel. Reselect the identical file to resume an incomplete upload. Refresh to see queued validation results; resume queued validation after interrupted dispatch if needed.",
      "Select sealed sources, queue a backfill dry-run, and review each date's mapping and provenance. Apply only after preview succeeds. Save the backfill ID to reopen status, resume completed progress, or cancel between dates.",
      "Create a fresh campaign preflight after resolving blockers. When all three horizons pass, explicitly choose Start 1m/3m/6m training.",
      "Refresh progress and inspect linked run, feature coverage/exclusion, calibration, fold, baseline and candidate evidence. Cancel requests cancellation through the existing training controls."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-09",
    "title": "How do I research a stock and maintain a watchlist?",
    "aliases": [
      "Research a stock and maintain a watchlist",
      "e2e-09"
    ],
    "keywords": [
      "research",
      "stock",
      "and",
      "maintain",
      "watchlist"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/watchlist",
    "guide": "/docs/journeys/07-supporting-investor.html#e2e-09-research-a-stock-and-maintain-a-watchlist",
    "steps": [
      "Open `/watchlist` and confirm the active watchlist.",
      "Search for a symbol or company and select the intended security and exchange.",
      "Inspect the stock's price-history availability and Fundamentals evidence. Missing history remains unavailable.",
      "Add the stock to the active watchlist. Confirm it appears in that list.",
      "Refresh and confirm the membership remains visible.",
      "Remove the stock from the watchlist when it is no longer being researched.",
      "If adding fails, read the failure, retry explicitly, and confirm only one membership exists."
    ],
    "prerequisites": [
      "An authenticated investor and an existing private watchlist."
    ],
    "warnings": []
  },
  {
    "id": "E2E-10",
    "title": "How do I recover a Kite connection and disconnect safely?",
    "aliases": [
      "Recover a Kite connection and disconnect safely",
      "e2e-10"
    ],
    "keywords": [
      "recover",
      "kite",
      "connection",
      "and",
      "disconnect",
      "safely"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/settings/account",
    "guide": "/docs/journeys/07-supporting-investor.html#e2e-10-recover-a-kite-connection-and-disconnect-safely",
    "steps": [
      "Open `/settings/account` and inspect Zerodha / Kite.",
      "Distinguish Not connected, Session expired and Connected. Manual mode works without a broker connection.",
      "Choose Connect Kite. Complete account login only on the provider's page, then return to StoX.",
      "If the connection cannot be started, read the error and retry explicitly.",
      "Choose Disconnect when the account should no longer be connected and confirm Not connected."
    ],
    "prerequisites": [
      "An authenticated investor. Real connection recovery requires login on Kite itself."
    ],
    "warnings": [
      "Broker login credentials and OTPs belong on Kite/Zerodha. CI uses deterministic responses and never contacts Kite."
    ]
  },
  {
    "id": "E2E-11",
    "title": "How do I save portfolio safeguards independently of execution?",
    "aliases": [
      "Save portfolio safeguards independently of execution",
      "e2e-11"
    ],
    "keywords": [
      "save",
      "portfolio",
      "safeguards",
      "independently",
      "execution"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/settings/portfolio",
    "guide": "/docs/journeys/07-supporting-investor.html#e2e-11-save-portfolio-safeguards-independently-of-execution",
    "steps": [
      "Open `/settings/portfolio` and confirm the intended active portfolio.",
      "Inspect the current Portfolio Stop-Loss % and Portfolio Trailing Stop %.",
      "Set the two values independently and choose Save portfolio settings.",
      "Confirm the save feedback, refresh, and verify the saved values.",
      "If a save fails, keep the intended values available and retry explicitly before relying on them."
    ],
    "prerequisites": [
      "An authenticated investor with an active portfolio."
    ],
    "warnings": []
  },
  {
    "id": "E2E-12",
    "title": "How do I filter notification history and manage attention?",
    "aliases": [
      "Filter notification history and manage attention",
      "e2e-12"
    ],
    "keywords": [
      "filter",
      "notification",
      "history",
      "and",
      "manage",
      "attention"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/notification-history",
    "guide": "/docs/journeys/07-supporting-investor.html#e2e-12-filter-notification-history-and-manage-attention",
    "steps": [
      "Open `/notification-history`.",
      "Search for the relevant event and apply the filters.",
      "Inspect the matching item. Mark it unread if attention is still needed, or read after review.",
      "Refresh and confirm attention state is retained.",
      "If history cannot load, use Retry rather than treating missing history as an empty result."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-13",
    "title": "How do I switch the active portfolio in one tab?",
    "aliases": [
      "Switch the active portfolio in one tab",
      "e2e-13"
    ],
    "keywords": [
      "switch",
      "the",
      "active",
      "portfolio",
      "one",
      "tab"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/holdings",
    "guide": "/docs/journeys/07-supporting-investor.html#e2e-13-switch-the-active-portfolio-in-one-tab",
    "steps": [
      "Open `/holdings` and confirm the active portfolio name in the header.",
      "Open the portfolio switcher and choose the intended portfolio.",
      "Confirm that holdings are requested for that portfolio and its name appears in the header.",
      "Refresh and confirm the active portfolio remains selected in this tab.",
      "Open StoX in a separate tab and confirm it starts with its own active portfolio selection."
    ],
    "prerequisites": [
      "An authenticated investor with at least two portfolios."
    ],
    "warnings": []
  },
  {
    "id": "E2E-14",
    "title": "How do I refresh portfolio overview and recover an unavailable dashboard?",
    "aliases": [
      "Refresh portfolio overview and recover an unavailable dashboard",
      "e2e-14"
    ],
    "keywords": [
      "refresh",
      "portfolio",
      "overview",
      "and",
      "recover",
      "unavailable",
      "dashboard"
    ],
    "synonyms": [
      "portfolio overview",
      "refresh dashboard",
      "dashboard unavailable."
    ],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/08-investor-overview-alerts.html#e2e-14-refresh-portfolio-overview-and-recover-an-unavailable-dashboard",
    "steps": [
      "Open the Dashboard at `/` and inspect Portfolio Value, Invested Value and Total Gain/Loss for the active portfolio.",
      "Use Refresh dashboard to clear this portfolio's local cache and request current server data.",
      "If refresh fails after data was displayed, retain the prior values and show the failure. Use Refresh dashboard again after the service recovers.",
      "Confirm the successful response replaces the displayed values. Reloading or returning to the page may use the labelled local cache; explicitly refresh when current server values are needed.",
      "If the initial load fails before any data exists, reload the page after the service recovers."
    ],
    "prerequisites": [
      "an authenticated investor with an active portfolio."
    ],
    "warnings": []
  },
  {
    "id": "E2E-15",
    "title": "How do I compare a stock with its benchmark and recover missing price data?",
    "aliases": [
      "Compare a stock with its benchmark and recover missing price data",
      "e2e-15"
    ],
    "keywords": [
      "compare",
      "stock",
      "with",
      "its",
      "benchmark",
      "and",
      "recover",
      "missing",
      "price",
      "data"
    ],
    "synonyms": [
      "relative strength",
      "stock explorer",
      "manual relative strength."
    ],
    "category": "StoX",
    "route": "/explorer",
    "guide": "/docs/journeys/08-investor-overview-alerts.html#e2e-15-compare-a-stock-with-its-benchmark-and-recover-missing-price-data",
    "steps": [
      "Open Stock Explorer at `/explorer`, select a stock and benchmark index, and calculate relative strength. A stock/benchmark deep link restores the same analysis context.",
      "Inspect relative strength for the available 1, 3, 6 and 12 month periods. Analytics use existing universe price history and do not request a new provider price fetch.",
      "If history is unavailable, use Manual Relative Strength Input with positive stock/index latest and six-month previous close values. The calculate action stays unavailable until all four inputs are positive.",
      "Calculate the temporary six-month comparison and inspect the resulting difference between stock and benchmark growth. Manual inputs do not persist authoritative market data.",
      "Retry Calculate relative strength after data is restored. Successful fetched results replace the temporary comparison."
    ],
    "prerequisites": [
      "an authenticated investor and a symbol in the local stock master."
    ],
    "warnings": []
  },
  {
    "id": "E2E-16",
    "title": "How do I maintain and evaluate an advisory alert policy?",
    "aliases": [
      "Maintain and evaluate an advisory alert policy",
      "e2e-16"
    ],
    "keywords": [
      "maintain",
      "and",
      "evaluate",
      "advisory",
      "alert",
      "policy"
    ],
    "synonyms": [
      "alert policy",
      "holdings alert",
      "evaluate alerts."
    ],
    "category": "StoX",
    "route": "/settings/alert-policies",
    "guide": "/docs/journeys/08-investor-overview-alerts.html#e2e-16-maintain-and-evaluate-an-advisory-alert-policy",
    "steps": [
      "Open Alert policies at `/settings/alert-policies` and choose New policy.",
      "Name the policy, describe its intent, define its holding-value comparison, message and suggested action, then create it. Correct validation errors or retry a transient failure without losing the form.",
      "Inspect the saved policy, edit its configuration, or disable it with Policy enabled before updating. Reload and confirm the saved enabled state.",
      "Choose Run policies now to evaluate enabled policies against holdings. If evaluation fails, retry after recovery; inspect Last evaluation and its per-holding outcome instead of assuming an alert was generated.",
      "For a non-system policy, choose Delete. Cancelling the confirmation preserves the policy; confirming removes it. System policies do not expose Delete."
    ],
    "prerequisites": [
      "an authenticated investor with an active portfolio."
    ],
    "warnings": []
  },
  {
    "id": "E2E-17",
    "title": "How do I save optional product-email preferences and recover a failed update?",
    "aliases": [
      "Save optional product-email preferences and recover a failed update",
      "e2e-17"
    ],
    "keywords": [
      "save",
      "optional",
      "product",
      "email",
      "preferences",
      "and",
      "recover",
      "failed",
      "update"
    ],
    "synonyms": [
      "optional emails",
      "email digest",
      "quiet hours."
    ],
    "category": "StoX",
    "route": "/settings/notifications",
    "guide": "/docs/journeys/08-investor-overview-alerts.html#e2e-17-save-optional-product-email-preferences-and-recover-a-failed-update",
    "steps": [
      "Inspect Optional product emails, which are off by default while account/security messages remain enabled.",
      "Enable optional emails, choose categories and Immediate or Daily digest delivery, then set quiet hours, digest time and timezone.",
      "Save email preferences. A failed update retains the form; correct invalid inputs or retry after recovery.",
      "Reload the page and confirm the saved preferences. Disabling optional emails leaves account/security messaging enabled."
    ],
    "prerequisites": [
      "an authenticated investor using `/settings/notifications`."
    ],
    "warnings": []
  },
  {
    "id": "E2E-18",
    "title": "How do I export snapshot data and recover a background export?",
    "aliases": [
      "Export snapshot data and recover a background export",
      "e2e-18"
    ],
    "keywords": [
      "export",
      "snapshot",
      "data",
      "and",
      "recover",
      "background"
    ],
    "synonyms": [
      "export CSV",
      "export chart data",
      "cancel export",
      "download snapshots"
    ],
    "category": "StoX",
    "route": "/portfolio/snapshots",
    "guide": "/docs/journeys/09-data-export.html#e2e-18-export-snapshot-data-and-recover-a-background-export",
    "steps": [
      "Open `/portfolio/snapshots`, inspect the date range and choose Export table data or Export chart data.",
      "Choose CSV or XLSX, an offered scope, and one or more offered fields. Selected scope uses selected stable row IDs; chart export contains underlying values rather than an image.",
      "Cancel the configuration dialog to leave without creating an export, or correct an invalid field selection before submitting.",
      "If generation fails, keep the originating data and retry with the intended scope/fields. A small ready export downloads immediately; a queued/running export shows its latest status.",
      "Use Refresh export status after a failed read or while waiting. Download becomes available only when generation is ready and its retention has not expired.",
      "To stop a queued/running export, choose Cancel export. If cancellation cannot be confirmed, refresh its status before retrying; confirm the cancelled state has no download.",
      "Generate a fresh export if the previous job failed or expired. Do not interpret an unavailable status as a completed job."
    ],
    "prerequisites": [
      "sign in as an investor with an active portfolio and available snapshot data."
    ],
    "warnings": [
      "generated downloads expire after 24 hours; cancelled, failed or expired jobs have no downloadable artifact."
    ]
  },
  {
    "id": "E2E-19",
    "title": "How do I maintain a private export basket and recover a rejected workbook?",
    "aliases": [
      "Maintain a private export basket and recover a rejected workbook",
      "e2e-19"
    ],
    "keywords": [
      "maintain",
      "private",
      "export",
      "basket",
      "and",
      "recover",
      "rejected",
      "workbook"
    ],
    "synonyms": [
      "export basket",
      "combine datasets",
      "XLSX export",
      "spreadsheet download"
    ],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/09-data-export.html#e2e-19-maintain-a-private-export-basket-and-recover-a-rejected-workbook",
    "steps": [
      "Open `/` and expand Export basket. Inspect the saved items and the available dataset catalog.",
      "Add a supported dataset. If saving fails, retry the addition; do not treat an unsaved item as persisted.",
      "Set its sheet name, choose fields/scope and provide required snapshot filters or stable selected-row IDs. Correct a highlighted invalid configuration before saving.",
      "Save item configuration. If saving fails, retain the draft for correction/retry; after success, reopen or reload the basket to verify persistence.",
      "Add another dataset with its own configuration. Choose Export XLSX to save/revalidate the configurations and request fresh data for each sheet.",
      "If the server rejects a stale/unavailable dataset, field, selection or permission, review the affected item and retry. Do not reuse the rejected result as a valid workbook.",
      "Follow the latest export status/download/cancellation controls as in E2E-18. A queued job does not start a download.",
      "Remove an item when no longer needed. A failed removal keeps the saved item so it can be retried."
    ],
    "prerequisites": [
      "sign in as an investor with an active portfolio and supported export datasets."
    ],
    "warnings": [
      "the basket stores configuration, not a data snapshot; every generation revalidates current access and freshness. The basket is account-private, limited to ten items and not an export history."
    ]
  },
  {
    "id": "E2E-20",
    "title": "How do I create, rename, select, default and retire a portfolio?",
    "aliases": [
      "Create, rename, select, default and retire a portfolio",
      "e2e-20"
    ],
    "keywords": [
      "create",
      "rename",
      "select",
      "default",
      "and",
      "retire",
      "portfolio"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/portfolios",
    "guide": "/docs/journeys/10-portfolio-cash.html#e2e-20-create-rename-select-default-and-retire-a-portfolio",
    "steps": [
      "Open `/portfolios` and enter a valid name using letters, numbers, spaces, hyphens or underscores.",
      "Choose Live and Create. A failed request retains the entered name; correct validation or retry without assuming a new portfolio exists.",
      "Confirm the new portfolio in the list. Choose Rename, enter its new name and Save. A failed save retains the editor; Cancel preserves the saved name.",
      "Choose Use in this tab to select an owned portfolio. Selection is per tab, separately from the default preference.",
      "On another owned portfolio, choose Set default and confirm the Default badge after a successful response. A failed response must not claim the preference changed.",
      "Delete only an inactive, non-default portfolio while another portfolio exists. Read the irreversible confirmation; dismiss it to keep the portfolio. Retry a failed deletion only after checking that the portfolio remains present.",
      "Reload and verify the saved names, default and surviving portfolios."
    ],
    "prerequisites": [
      "An authenticated investor who owns the affected portfolios."
    ],
    "warnings": [
      "Deleting a portfolio permanently removes its transactions, holdings, alerts, snapshots and settings. Review the confirmation; cancellation must leave it intact."
    ]
  },
  {
    "id": "E2E-21",
    "title": "How do I clone a live portfolio into a distinct paper simulation?",
    "aliases": [
      "Clone a live portfolio into a distinct paper simulation",
      "e2e-21"
    ],
    "keywords": [
      "clone",
      "live",
      "portfolio",
      "into",
      "distinct",
      "paper",
      "simulation"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/portfolios",
    "guide": "/docs/journeys/10-portfolio-cash.html#e2e-21-clone-a-live-portfolio-into-a-distinct-paper-simulation",
    "steps": [
      "Open `/portfolios`, locate the source and choose Clone as Paper.",
      "Review Paper name, positive Simulated cash, Fill price and Copy holdings. Cancel abandons the form without cloning.",
      "Choose Clone. A failed request leaves the draft available for correction or retry.",
      "Confirm a separate PAPER portfolio with the chosen name; the original LIVE portfolio remains present.",
      "Pause simulation and confirm its action changes to Resume simulation only after success. Retry a rejected pause while the previous state remains visible.",
      "Resume simulation and verify the active simulation state after reload. Its worker follows effective-session checkpoints and must never submit a live broker order."
    ],
    "prerequisites": [
      "An owned source portfolio."
    ],
    "warnings": [
      "Paper simulation is simulated evidence, not a live order or proof of live execution. Copy holdings is explicit; source ownership and accounting remain intact."
    ]
  },
  {
    "id": "E2E-22",
    "title": "How do I record cash evidence and inspect reservations without trading?",
    "aliases": [
      "Record cash evidence and inspect reservations without trading",
      "e2e-22"
    ],
    "keywords": [
      "record",
      "cash",
      "evidence",
      "and",
      "inspect",
      "reservations",
      "without",
      "trading"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/cash",
    "guide": "/docs/journeys/10-portfolio-cash.html#e2e-22-record-cash-evidence-and-inspect-reservations-without-trading",
    "steps": [
      "Open `/cash`, inspect cash balance, Reserved cash and Available cash. Use See reservation details to inspect recommendation identity and the reserved amount; do not infer a fill from reservation alone.",
      "Choose Deposit, enter a positive whole-rupee Amount, Transaction date and optional Remarks, then submit. A failed write retains the input and unchanged statement; retry only after checking whether the record exists.",
      "Confirm the successful entry in Cash account statement with its effective date, running balance and remarks.",
      "Choose Withdraw. An amount above available cash cannot be recorded. Record a valid withdrawal using the same explicit form, then inspect the statement.",
      "Choose Adjust, enter a non-zero signed whole-rupee amount and a required reason. Correct rejected input or retry a failed write while the draft remains visible. Confirm a separate adjustment entry rather than rewriting a prior economic event.",
      "Reload and verify the statement and balances. No order, fill or external transfer should be created by these actions."
    ],
    "prerequisites": [
      "An owned active portfolio and actual accounting evidence for any real cash record."
    ],
    "warnings": [
      "Record cash movement updates the StoX ledger; it does not transfer money at a bank or broker. Available cash and reserved cash differ. A reserve shortfall is a warning, not a second cash account or an independent withdrawal prohibition."
    ]
  },
  {
    "id": "E2E-23",
    "title": "How do I run and review a historical strategy experiment?",
    "aliases": [
      "Run and review a historical strategy experiment",
      "e2e-23"
    ],
    "keywords": [
      "run",
      "and",
      "review",
      "historical",
      "strategy",
      "experiment"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/backtests",
    "guide": "/docs/journeys/11-historical-analysis.html#e2e-23-run-and-review-a-historical-strategy-experiment",
    "steps": [
      "Open `/backtests`. If history is unavailable, use Retry backtest history; an operational read failure is not proof that no runs exist.",
      "Choose New Backtest, review the background-operation notice and enter Name, Period, Initial capital, Execution price, Adverse slippage, optional declared parameter overrides, Notes and Tags. Cancel closes the form without starting a run.",
      "Choose Start in New Strategy Backtest. A failed submission retains the draft for correction or retry. Review the resulting run's status; processing can continue in bounded background slices after leaving the page.",
      "Open the run. If the detail read is temporarily unavailable, choose Retry loading backtest. A confirmed missing run instead offers Back to Backtests.",
      "Inspect strategy/version, dates, assumptions, statistics, simulated trades, transactions and snapshots. Update Name/Notes/Tags using Save name / notes / tags; failure retains the draft and does not change historical economic evidence.",
      "Return to All backtests. Duplicate creates a separate experiment using stored dates, capital, notes, tags and fill assumptions with the current strategy; it does not copy old results or reinterpret the original run.",
      "Select two to five runs and Compare selected. Read differing assumptions and unavailable statistics; incompatible evidence must not be labeled compatible.",
      "For an in-progress run, choose Cancel and review the checkpoint confirmation. Dismissing it sends no cancellation; a failed request leaves the prior state visible. A confirmed cancelled run retains partial evidence and is not completed.",
      "Use Delete only after reviewing its irreversible confirmation. Dismiss it to preserve the run; retry a failed deletion after verifying the run remains present."
    ],
    "prerequisites": [
      "An owned portfolio, an active published strategy and eligible historical inputs."
    ],
    "warnings": [
      "A historical result is simulated research. Compare execution assumptions, pinned versions and dataset limitations before drawing conclusions; cancellation or failure is not completion."
    ]
  },
  {
    "id": "E2E-24",
    "title": "How do I check replay readiness and inspect isolated replay evidence?",
    "aliases": [
      "Check replay readiness and inspect isolated replay evidence",
      "e2e-24"
    ],
    "keywords": [
      "check",
      "replay",
      "readiness",
      "and",
      "inspect",
      "isolated",
      "evidence"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/portfolios",
    "guide": "/docs/journeys/11-historical-analysis.html#e2e-24-check-replay-readiness-and-inspect-isolated-replay-evidence",
    "steps": [
      "Open `/portfolios`, locate Portfolio Replay and choose Starting state, From/To dates, optional Starting cash, Fill method and Slippage.",
      "Choose Check. Retry an unavailable readiness request. Review blockers and limitations; Start remains disabled for a confirmed blocked assessment.",
      "Correct inputs and Check again. For Historical branch, inspect historical strategy versions and explicitly review any counterfactual version selection before proceeding.",
      "Choose Start only after reviewing readiness. A failed request retains the form; retry through the supported action. Confirm the separate queued/running replay and its immutable assumptions.",
      "Choose Details and inspect status, checkpoint/transaction/recommendation counts, fill assumptions, pinned-world evidence and limitations. A failed read does not claim that evidence was loaded; retry Details.",
      "Compare selected replay runs and read compatibility/assumption disclosure rather than treating missing statistics as zero.",
      "Cancel a queued/running replay only deliberately; retry a rejected cancellation while the previous state remains visible. Confirm cancelled status without presenting partial results as completed.",
      "Delete a replay only after its explicit confirmation; cancelling the confirmation preserves evidence. Successful deletion retains the backend audit tombstone."
    ],
    "prerequisites": [
      "An owned portfolio and authoritative historical inputs for the chosen period."
    ],
    "warnings": [
      "Readiness limitations and missing historical reconstruction are not waived by simulated UI fixtures. Historical replay differs from forward paper simulation and live execution."
    ]
  },
  {
    "id": "E2E-25",
    "title": "How do I inspect historical holdings, compare dates and recover derived snapshots?",
    "aliases": [
      "Inspect historical holdings, compare dates and recover derived snapshots",
      "e2e-25"
    ],
    "keywords": [
      "inspect",
      "historical",
      "holdings",
      "compare",
      "dates",
      "and",
      "recover",
      "derived",
      "snapshots"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/portfolio/historical-holdings",
    "guide": "/docs/journeys/12-portfolio-analysis-tax.html#e2e-25-inspect-historical-holdings-compare-dates-and-recover-derived-snapshots",
    "steps": [
      "Open `/portfolio/historical-holdings`, choose As-of date and inspect quantity, cost basis, dated price, market value and completeness warnings. Refresh after an unavailable read; preserve unavailable price/value distinctions.",
      "Follow Compare dates to `/portfolio/compare`, choose Date A before Date B with neither in the future, and Compare. Invalid dates cannot be submitted.",
      "Inspect dated cash/holdings totals, external deposits/withdrawals/adjustments, holdings differences and transaction evidence in `(A, B]`. Retry a failed comparison without changing the requested dates.",
      "Open `/portfolio/snapshots`, choose the history range and inspect the latest date, growth chart and daily values. Refresh a failed read; a failed refresh must not manufacture values or financial events.",
      "If source corrections require rebuilding derived history, choose Rebuild history and review its confirmation. Dismiss to keep the existing derived state. A failed rebuild reports failure; check existing results before retrying.",
      "Confirm successful rebuild and refreshed dated rows. Source ledger and live order state remain distinct from the recalculated presentation."
    ],
    "prerequisites": [
      "An owned active portfolio with dated financial source evidence."
    ],
    "warnings": [
      "Historical holdings are an as-of reconstruction, snapshots are rebuildable derived views, and value change between dates is not investment return or causal attribution. Missing prices are not zero."
    ]
  },
  {
    "id": "E2E-26",
    "title": "How do I review performance/tax scope, save preferences and preserve calculation evidence?",
    "aliases": [
      "Review performance/tax scope, save preferences and preserve calculation evidence",
      "e2e-26"
    ],
    "keywords": [
      "review",
      "performance",
      "tax",
      "scope",
      "save",
      "preferences",
      "and",
      "preserve",
      "calculation",
      "evidence"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/portfolio/performance-tax",
    "guide": "/docs/journeys/12-portfolio-analysis-tax.html#e2e-26-review-performance-tax-scope-save-preferences-and-preserve-calculation-evidence",
    "steps": [
      "Open `/portfolio/performance-tax`, choose Performance from/to and Tax financial year, then Calculate. Correct invalid/future ranges; retry an unavailable calculation with the same reviewed inputs.",
      "Inspect portfolio/account metrics, primary benchmark, attribution residual and tax completeness/limitations. Incomplete or unavailable values are not zero.",
      "Review Analysis settings: Primary benchmark, comparison benchmarks, risk-free rate, annualization days and independent account performance/tax inclusion. Save settings. Failure retains the draft for retry; confirmed changes appear after reloading.",
      "If needed, review and Save Account overrides separately; these are account analytics preferences, not a portfolio cash movement.",
      "Enable What-if portfolio selection and choose owned portfolios. Inspect the resulting scope without changing the configured inclusion preferences; disable it to return to configured reporting.",
      "Choose Preserve evidence for the intended portfolio performance, account performance or account tax calculation. Retry a failed preservation explicitly; do not claim immutable evidence was stored before success.",
      "Export the desired tax dataset. A failed CSV export reports failure; retry and verify the selected dataset/financial-year scope and attachment. Downloading a report does not change financial sources."
    ],
    "prerequisites": [
      "An owned active portfolio and the financial/benchmark evidence required by the chosen calculation."
    ],
    "warnings": [
      "Account results are independently aggregated, not averaged portfolio percentages. What-if selection does not change configured inclusion. Tax estimates are not certified advice, and incomplete inputs must remain disclosed."
    ]
  },
  {
    "id": "E2E-27",
    "title": "How do I record tax inputs and preview a dividend CSV before import?",
    "aliases": [
      "Record tax inputs and preview a dividend CSV before import",
      "e2e-27"
    ],
    "keywords": [
      "record",
      "tax",
      "inputs",
      "and",
      "preview",
      "dividend",
      "csv",
      "before",
      "import"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/portfolio/performance-tax",
    "guide": "/docs/journeys/12-portfolio-analysis-tax.html#e2e-27-record-tax-inputs-and-preview-a-dividend-csv-before-import",
    "steps": [
      "In Record tax evidence on `/portfolio/performance-tax`, enter Manual dividend date, positive amount and source reference, then Record dividend. Failure retains the draft and reports that the write was not confirmed.",
      "For Tax loss, choose term/status, enter a positive amount and the required evidence/reason for Confirmed carry-forward; Record loss. Do not confuse a calculated loss with confirmed carry-forward.",
      "For Opening tax lot, select the stock, acquired date, positive quantity, cost basis and required source reason, then Record opening lot. These inputs supplement tax reconstruction; they do not place a trade.",
      "Choose the supported Dividend CSV (`generic-dividend-csv.v1`) and Preview. Inspect validity, candidate rows and duplicates. A failed/invalid preview cannot enable Import validated rows.",
      "Changing the file invalidates its prior preview; preview the corrected file again. Only then deliberately choose Import validated rows and inspect imported/duplicate counts.",
      "After a failed write/import, inspect existing evidence before retrying. After success, verify refreshed reporting; a preview alone must not change reports or claim rows were imported."
    ],
    "prerequisites": [
      "An owned portfolio and actual documentary evidence for any real tax input."
    ],
    "warnings": [
      "These are source-evidence writes. Inspect existing records after an uncertain response before retrying. A browser fixture cannot certify tax rules, filing eligibility or real import persistence."
    ]
  },
  {
    "id": "E2E-28",
    "title": "How do I review and import an idempotent transaction CSV batch?",
    "aliases": [
      "Review and import an idempotent transaction CSV batch",
      "e2e-28"
    ],
    "keywords": [
      "review",
      "and",
      "import",
      "idempotent",
      "transaction",
      "csv",
      "batch"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/transactions",
    "guide": "/docs/journeys/13-financial-source-lifecycle.html#e2e-28-review-and-import-an-idempotent-transaction-csv-batch",
    "steps": [
      "Open `/transactions`, choose Bulk (CSV), and paste Stock, Quantity, Average Price and Transaction Type columns, optionally Owner (`unmanaged` or `strategy:ID`).",
      "Choose Parse & review. Correct invalid rows, whole positive quantities/prices, side, exchange and actual dates. Dates default to today and must be reviewed.",
      "Back to CSV abandons that review/batch identity. Reparse only when intending a new review; no source write occurs before Save all.",
      "Choose Save all once. If unavailable/unconfirmed, inspect source evidence and retry the same reviewed batch. Validation failures retain rows and their batch identity for correction.",
      "A committed/already-committed response locks the batch and removes Save all. Verify refreshed transactions and derived accounting.",
      "Choose New import only for additional, separate financial evidence."
    ],
    "prerequisites": [
      "An owned portfolio and actual transaction evidence in the supported CSV format."
    ],
    "warnings": [
      "Import writes all reviewed rows atomically through the financial write path. An uncertain response is not proof that nothing committed. Retry the same batch identity; use a new batch only for a separate import."
    ]
  },
  {
    "id": "E2E-29",
    "title": "How do I adopt an unmanaged holding into one enabled strategy?",
    "aliases": [
      "Adopt an unmanaged holding into one enabled strategy",
      "e2e-29"
    ],
    "keywords": [
      "adopt",
      "unmanaged",
      "holding",
      "into",
      "one",
      "enabled",
      "strategy"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/holdings",
    "guide": "/docs/journeys/13-financial-source-lifecycle.html#e2e-29-adopt-an-unmanaged-holding-into-one-enabled-strategy",
    "steps": [
      "Open `/holdings`, identify the unmanaged ownership episode, open its Sell menu and choose Adopt.",
      "Review the destination strategy and any merge/sibling disclosures. No eligible enabled strategy leaves Adopt unavailable; close/cancel preserves ownership.",
      "Choose the intended Destination strategy and Adopt explicitly.",
      "On failure retain the selected destination and unchanged visible ownership; inspect existing adoption evidence before retrying.",
      "After success inspect the refreshed ownership episodes and confirm other strategies' same-stock quantities/basis are unchanged."
    ],
    "prerequisites": [
      "An owned unmanaged holding and an enabled eligible destination strategy."
    ],
    "warnings": [
      "Adoption changes ownership-aware future lifecycle. Same-stock destination ownership can merge under accepted weighted-average rules; sibling strategies remain separate. It is not a trade."
    ]
  },
  {
    "id": "E2E-30",
    "title": "How do I preview and explicitly apply a split or bonus?",
    "aliases": [
      "Preview and explicitly apply a split or bonus",
      "e2e-30"
    ],
    "keywords": [
      "preview",
      "and",
      "explicitly",
      "apply",
      "split",
      "bonus"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/holdings",
    "guide": "/docs/journeys/13-financial-source-lifecycle.html#e2e-30-preview-and-explicitly-apply-a-split-or-bonus",
    "steps": [
      "From `/holdings`, choose Split/Bonus in the intended holding's menu, or open `/corporate-action` and select the stock.",
      "Choose Stock split or Bonus issue, enter its ratio, actual Ex-date / record date, supported split scope and source Notes.",
      "Preview affected transactions, warnings, blocking errors and estimated post-state. A failed preview leaves inputs available; changing any input invalidates the prior preview.",
      "Apply without a preview first obtains one; blocking errors cannot apply ledger changes. Review a valid preview before applying.",
      "Choose Apply and review the stock/ratio/ledger confirmation. Dismiss to preserve source state. A failed application does not claim success; inspect existing action evidence before retrying.",
      "After confirmed application, review the matching transactions/holdings. Verify cost/quantity/ownership consequences through accounting tests, not a synthetic browser response."
    ],
    "prerequisites": [
      "An owned portfolio, affected stock and official action/effective-date evidence for any real application."
    ],
    "warnings": [
      "Financial ledger application is separate from historical price repair. Rights subscriptions are normal buys at the actual subscription price, not an automatic split/bonus."
    ]
  },
  {
    "id": "E2E-31",
    "title": "How do I recover Library reads and create/import unpublished drafts?",
    "aliases": [
      "Recover Library reads and create/import unpublished drafts",
      "e2e-31"
    ],
    "keywords": [
      "recover",
      "library",
      "reads",
      "and",
      "create",
      "import",
      "unpublished",
      "drafts"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/artifact-library",
    "guide": "/docs/journeys/14-artifact-library-governance.html#e2e-31-recover-library-reads-and-create-import-unpublished-drafts",
    "steps": [
      "Open `/artifact-library`, filter Search/Type and inspect accessible owned/shared/system provenance. An unavailable read must be reported separately from an empty search; Retry Library after recovery.",
      "Choose Create Draft, enter its type, slug, name, SemVer and supported Artifact envelope JSON. Closing the form leaves no source mutation.",
      "Correct JSON/API validation failures without losing the draft, then Create Draft explicitly. Inspect the new unpublished lineage.",
      "Expand Import validated portable package, paste the package JSON and choose Validate and import as Drafts.",
      "Correct syntax/checksum/dependency errors while retaining the reviewed package. Confirm imported Drafts and that nothing was published/deployed."
    ],
    "prerequisites": [
      "An authenticated Investor and a supported artifact envelope/package."
    ],
    "warnings": [
      "Creation/import produces Drafts, never automatic publication, enablement or deployment. Actual portable packages require server checksum/dependency validation."
    ]
  },
  {
    "id": "E2E-32",
    "title": "How do I save and publish an immutable version with exact dependencies?",
    "aliases": [
      "Save and publish an immutable version with exact dependencies",
      "e2e-32"
    ],
    "keywords": [
      "save",
      "and",
      "publish",
      "immutable",
      "version",
      "with",
      "exact",
      "dependencies"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/artifact-library/:uuid",
    "guide": "/docs/journeys/14-artifact-library-governance.html#e2e-32-save-and-publish-an-immutable-version-with-exact-dependencies",
    "steps": [
      "Inspect `/artifact-library/:uuid`; retry an unavailable initial detail read or return to Library. Inspect provenance, versions, dependencies and any binding before mutation.",
      "Edit Draft envelope/documentation/change summary and Save Draft. Syntax or lock/validation errors retain the draft for correction/review; save only against the current reviewed lock version.",
      "Choose Validate and publish and review the exact dependency array. Cancelling leaves the Draft. Correct publication validation errors before retrying.",
      "Inspect the published version/hash/dependencies and confirm existing bindings remain pinned. Publication alone does not enable or deploy.",
      "If sharing is intended, Share only to the intended existing account through the exact published version; cancelled/failed requests do not claim access was granted.",
      "For later changes choose New version Draft and a forward SemVer. Inspect the separate unpublished version and preserved published history."
    ],
    "prerequisites": [
      "Owner access to an artifact with an editable Draft and its supported schema/dependencies."
    ],
    "warnings": [
      "One active Draft per lineage and optimistic locking prevent lost updates. Published content cannot be edited; later changes require a forward Draft/version. Sharing grants access to an exact published version and dependencies."
    ]
  },
  {
    "id": "E2E-33",
    "title": "How do I inspect differences and explicitly manage a Portfolio binding?",
    "aliases": [
      "Inspect differences and explicitly manage a Portfolio binding",
      "e2e-33"
    ],
    "keywords": [
      "inspect",
      "differences",
      "and",
      "explicitly",
      "manage",
      "portfolio",
      "binding"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/14-artifact-library-governance.html#e2e-33-inspect-differences-and-explicitly-manage-a-portfolio-binding",
    "steps": [
      "Inspect the desired published version and exact dependencies, then Bind to Portfolio explicitly. Failure leaves the existing binding state available for inspection/retry.",
      "Inspect disabled state and usability reasons. Resolve genuine compatibility conditions before enabling; reload to inspect current readiness/lock version.",
      "Enable deliberately and verify confirmed binding status. Failed enable/disable retains the previous confirmed status.",
      "Compare distinct immutable versions and inspect their structural changes/dependency impact. Equal endpoints cannot compare; retry unavailable comparison without inventing changes.",
      "Choose Upgrade binding on the reviewed version. For a concurrent-update conflict, reload current binding state/lock before retrying. Verify explicit forward activation and unchanged published history.",
      "Export the intended published version and inspect its portable package. A failed download remains recoverable and does not change binding state.",
      "Archive only when intended. Dismiss its history/binding warning to preserve state; after successful confirmation published history and valid active bindings remain intact."
    ],
    "prerequisites": [
      "Access to published versions and the intended owned active Portfolio."
    ],
    "warnings": [
      "Bind creates disabled deployment settings; Enable is separate. Blocked usability cannot be enabled. A newly published version never upgrades an existing binding automatically."
    ]
  },
  {
    "id": "E2E-34",
    "title": "How do I fork shared versions and confirm a transactional Bundle plan?",
    "aliases": [
      "Fork shared versions and confirm a transactional Bundle plan",
      "e2e-34"
    ],
    "keywords": [
      "fork",
      "shared",
      "versions",
      "and",
      "confirm",
      "transactional",
      "bundle",
      "plan"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/artifact-library/:uuid",
    "guide": "/docs/journeys/14-artifact-library-governance.html#e2e-34-fork-shared-versions-and-confirm-a-transactional-bundle-plan",
    "steps": [
      "Inspect `/artifact-library/:uuid` source permission and published definition/dependencies. Fork, name the new slug/lineage, and confirm only the intended operation. Cancelled/failed prompts preserve the source.",
      "Inspect the new local Draft and unchanged immutable source. Modification occurs on the fork, not the shared published version.",
      "For a published Bundle, choose Deploy Bundle to obtain its server-validated transactional plan. Inspect failure instead of claiming deployment.",
      "Review the planned member-count confirmation for the active Portfolio. Dismiss to leave deployment unapplied.",
      "Confirm only the intended validated plan. If application is unconfirmed, inspect existing deployment evidence before retrying; do not assume a transport error proves rollback.",
      "Inspect confirmed deployed member bindings/provenance independently; the Bundle does not become a trading controller."
    ],
    "prerequisites": [
      "Access to the exact shared/system published source, or a published Bundle and the intended owned Portfolio."
    ],
    "warnings": [
      "Recipients cannot edit/share/publish someone else's source. Fork is a new local unpublished 1.0.0 lineage. Bundle deployment plans are atomic evidence, not perpetual runtime controllers; nested Bundles are unsupported."
    ]
  },
  {
    "id": "E2E-35",
    "title": "How do I read dated stock price history without sync authority?",
    "aliases": [
      "Read dated stock price history without sync authority",
      "e2e-35"
    ],
    "keywords": [
      "read",
      "dated",
      "stock",
      "price",
      "history",
      "without",
      "sync",
      "authority"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/holdings/42/prices",
    "guide": "/docs/journeys/15-research-persisted-review.html#e2e-35-read-dated-stock-price-history-without-sync-authority",
    "steps": [
      "Open `/holdings/42/prices` for the security being reviewed. A failed read offers Retry price history; unavailable data must not appear fetched.",
      "Inspect dated OHLCV rows, source, chart, All history from and Since Buy from. All means available instrument history; Since Buy means the current position episode.",
      "Switch All/Since Buy. A confirmed range with no cached prices stays empty/unavailable instead of inventing zero-priced bars.",
      "Switch back to inspect the available range. Investors cannot use Force sync historical prices; request source-coverage review from an administrator when needed."
    ],
    "prerequisites": [
      "An authenticated Investor and a known security."
    ],
    "warnings": [
      "Price-history reads do not initiate a provider backfill or change the transaction ledger. Admin source repair is a separate governed action."
    ]
  },
  {
    "id": "E2E-36",
    "title": "How do I recover index research and retain local comparison choices?",
    "aliases": [
      "Recover index research and retain local comparison choices",
      "e2e-36"
    ],
    "keywords": [
      "recover",
      "index",
      "research",
      "and",
      "retain",
      "local",
      "comparison",
      "choices"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/indices",
    "guide": "/docs/journeys/15-research-persisted-review.html#e2e-36-recover-index-research-and-retain-local-comparison-choices",
    "steps": [
      "Open `/indices`. If overview/comparison reads fail, choose Retry index data.",
      "Use the Broad market/Sector legend to select available relative-performance series; inspect the dated baseline. Selection affects presentation only.",
      "Expand an index to inspect exchange, latest dated close, cached range, session count and change periods.",
      "If history is unavailable, choose Retry index history. An authoritative empty response differs from a failed read.",
      "If constituent lookup fails, choose Retry index constituents. Filter the returned names/symbols; a constituent link leads to Watchlist research.",
      "Collapse/reopen as needed. Global sync remains an administrator source-coverage responsibility."
    ],
    "prerequisites": [
      "An authenticated Investor and available index master records."
    ],
    "warnings": [
      "Missing benchmark history is not replaced with another index. Comparison selection is a Portfolio-scoped presentation preference, not a strategy or ledger change. VIX is excluded from relative-performance charts."
    ]
  },
  {
    "id": "E2E-37",
    "title": "How do I review dated market breadth with unavailable metrics disclosed?",
    "aliases": [
      "Review dated market breadth with unavailable metrics disclosed",
      "e2e-37"
    ],
    "keywords": [
      "review",
      "dated",
      "market",
      "breadth",
      "with",
      "unavailable",
      "metrics",
      "disclosed"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/market-depth",
    "guide": "/docs/journeys/15-research-persisted-review.html#e2e-37-review-dated-market-breadth-with-unavailable-metrics-disclosed",
    "steps": [
      "Open `/market-depth`. An unavailable read offers Retry market breadth rather than claiming that no snapshots exist.",
      "Review Date and the snapshot matrix. Choose percent or Count using Values; these are two views of the same observed evidence.",
      "Choose another available date. Missing metrics display an unavailable dash in both modes, not an invented zero.",
      "Inspect available history/series independently of the selected matrix date. If no snapshots are authoritatively returned, request administrator source-coverage review."
    ],
    "prerequisites": [
      "An authenticated Investor and cached market-breadth snapshots."
    ],
    "warnings": [
      "Cached market breadth is observational research, not a live execution quote. Investor UI must not instruct users to execute server backfill scripts."
    ]
  },
  {
    "id": "E2E-38",
    "title": "How do I separate observational review from persisted report generation?",
    "aliases": [
      "Separate observational review from persisted report generation",
      "e2e-38"
    ],
    "keywords": [
      "separate",
      "observational",
      "review",
      "from",
      "persisted",
      "report",
      "generation"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/review",
    "guide": "/docs/journeys/15-research-persisted-review.html#e2e-38-separate-observational-review-from-persisted-report-generation",
    "steps": [
      "Open `/review`. Failed reads stop loading and offer recovery through Refresh; this observational path does not execute, cancel or reconcile an order.",
      "Choose Reports to open stored report history. An unavailable list offers Retry rather than claiming no reports exist.",
      "Open a report and inspect its immutable ID, period, generation/status, persisted metrics and methodology. An unavailable detail offers Retry review report; only an authoritative missing report is labelled not found.",
      "Return to reports. Enter optional From/To and choose Generate deliberately. Failure retains the chosen period; retry creates/opens the separately identified report.",
      "Verify the new report period and inspect methodology. Original report/source identity remains intact; browser fixtures do not qualify the actual ReviewEngine calculation."
    ],
    "prerequisites": [
      "An owned Portfolio and permission to read/generate its review reports."
    ],
    "warnings": [
      "Accepted recommendations are not executed transactions. A stored ReviewEngine snapshot is separate from the live Review dashboard; generating a new report must not rewrite old source/report evidence."
    ]
  },
  {
    "id": "E2E-39",
    "title": "How do I recover fundamental research and use educational pattern references?",
    "aliases": [
      "Recover fundamental research and use educational pattern references",
      "e2e-39"
    ],
    "keywords": [
      "recover",
      "fundamental",
      "research",
      "and",
      "use",
      "educational",
      "pattern",
      "references"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/fundamentals/insights/TCS",
    "guide": "/docs/journeys/15-research-persisted-review.html#e2e-39-recover-fundamental-research-and-use-educational-pattern-references",
    "steps": [
      "Open `/fundamentals/insights/TCS` for the intended security. Retry fundamental insights repeats ordinary symbol/evidence reads after a failed resolution or payload read.",
      "Inspect the security identity, As of date, Freshness, deterministic evidence and any optional AI limitations. Missing insight content is disclosed separately.",
      "Use Full fundamentals on Watchlist for deeper research. Refresh AI insights is an explicit optional interpretation action, not order execution.",
      "Open `/patterns`, review the educational notice and select Chart patterns/Candlesticks. Filter names/characteristics and clear an unmatched filter to recover the reference list.",
      "Reopen the guide to retain the local section choice. Pattern links identify a reference entry; they do not submit a recommendation or transaction."
    ],
    "prerequisites": [
      "An authenticated Investor and a resolvable security symbol."
    ],
    "warnings": [
      "Fundamentals and patterns are research, not investment advice or permission to trade. Deterministic evidence and optional AI interpretation have separate sufficiency/freshness; an absent AI or insight payload does not erase valid evidence or invent a signal."
    ]
  },
  {
    "id": "E2E-40",
    "title": "How do I recover personal Notes and preserve failed changes?",
    "aliases": [
      "Recover personal Notes and preserve failed changes",
      "e2e-40"
    ],
    "keywords": [
      "recover",
      "personal",
      "notes",
      "and",
      "preserve",
      "failed",
      "changes"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/knowledge-board",
    "guide": "/docs/journeys/16-personal-knowledge-calendar.html#e2e-40-recover-personal-notes-and-preserve-failed-changes",
    "steps": [
      "Open Knowledge Board (`/knowledge-board`). Retry knowledge notes or Retry knowledge tags recovers unavailable reads; a failed read is not a confirmed empty Board.",
      "Choose New note, enter title and research content, and inspect the save status. A rejected save keeps the draft available for manual Save. Close and reopen the confirmed note to verify persistence.",
      "Choose Manage notes. Pin, Duplicate and Archive are explicit actions; a rejected action preserves the original note. Archive removes it from the active list only after confirmation from the service.",
      "Use Show archived, search and tags to find personal research. Duplicating creates a distinct note; editing the copy does not replace its source.",
      "Select notes and Export to review Markdown. A failed clipboard copy leaves the export available to copy again.",
      "Cancel a bulk deletion to leave selection and notes intact. On a failed confirmed deletion, inspect the retained selection and retry deliberately."
    ],
    "prerequisites": [
      "An authenticated Investor and an owned Portfolio."
    ],
    "warnings": [
      "Blank notes are not saved. Autosave and manual Save use the same personal note; a transport failure cannot establish whether a write committed. Inspect the retained note before retrying. Copy/export does not publish a note."
    ]
  },
  {
    "id": "E2E-41",
    "title": "How do I maintain personal tags with deliberate merge and deletion?",
    "aliases": [
      "Maintain personal tags with deliberate merge and deletion",
      "e2e-41"
    ],
    "keywords": [
      "maintain",
      "personal",
      "tags",
      "with",
      "deliberate",
      "merge",
      "and",
      "deletion"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/knowledge-board/tags",
    "guide": "/docs/journeys/16-personal-knowledge-calendar.html#e2e-41-maintain-personal-tags-with-deliberate-merge-and-deletion",
    "steps": [
      "Open Manage tags (`/knowledge-board/tags`). Retry knowledge tags recovers an unavailable list.",
      "Enter a nonblank New tag name and choose Add. A failed creation retains the name for correction/retry.",
      "Rename a tag. Cancel preserves its original name; a rejected Save keeps the entered name available.",
      "Select distinct Source and Target tags before Merge. On failure, retain both selections and inspect the current list before retrying.",
      "Cancel deletion to preserve the tag. A rejected confirmed deletion retains it; retry removes only the intended tag."
    ],
    "prerequisites": [
      "Owned Portfolio notes and tags."
    ],
    "warnings": [
      "Merging consolidates the source tag into the selected target. Deleting a tag does not delete its notes."
    ]
  },
  {
    "id": "E2E-42",
    "title": "How do I author private Wiki Pages and recover history and portability?",
    "aliases": [
      "Author private Wiki Pages and recover history and portability",
      "e2e-42"
    ],
    "keywords": [
      "author",
      "private",
      "wiki",
      "pages",
      "and",
      "recover",
      "history",
      "portability"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/knowledge-board/wiki",
    "guide": "/docs/journeys/16-personal-knowledge-calendar.html#e2e-42-author-private-wiki-pages-and-recover-history-and-portability",
    "steps": [
      "Open Wiki (`/knowledge-board/wiki`); Retry Wiki hierarchy and Retry Wiki Page recover unavailable reads without treating them as a missing page or navigating away silently.",
      "Create a root page or a child beneath the selected parent. Cancelling the prompt creates nothing; rejected creation retains existing hierarchy.",
      "Edit title/Markdown and Insert Wiki Link to a selected page. Preview failure retains source; retry displays safe derived content. Save failure retains the draft; verify confirmed source/history after retry.",
      "Compare a stored revision with current Markdown. Cancel Restore to leave history intact; retry a rejected confirmed restore to create a forward revision while retaining old evidence.",
      "Use Move Wiki Page to reparent explicitly. A failed move preserves the old parent and permits retrying the same target. Stable links remain usable.",
      "Insert image. Upload and page attachment must both succeed before the image is inserted into Markdown. Save deliberately to persist the Markdown change. Partial upload failures do not imply rollback of uploaded files.",
      "Export page, Export branch or Export Wiki deliberately. Read failures leave the page available to retry. Exports do not publish or alter source/history.",
      "Cancel branch deletion to preserve all pages. A rejected confirmed deletion retains the branch; inspect and retry deliberately."
    ],
    "prerequisites": [
      "An owned Portfolio and access to its Wiki."
    ],
    "warnings": [
      "Markdown is the canonical source. Rendered preview is derived. Moving/renaming preserves immutable page identity and managed links. Restoring creates a new revision; branch deletion requires explicit confirmation of the branch size."
    ]
  },
  {
    "id": "E2E-43",
    "title": "How do I share one Wiki Page and revoke its public capability?",
    "aliases": [
      "Share one Wiki Page and revoke its public capability",
      "e2e-43"
    ],
    "keywords": [
      "share",
      "one",
      "wiki",
      "page",
      "and",
      "revoke",
      "its",
      "public",
      "capability"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/knowledge-board/wiki",
    "guide": "/docs/journeys/16-personal-knowledge-calendar.html#e2e-43-share-one-wiki-page-and-revoke-its-public-capability",
    "steps": [
      "On the owned private Wiki Page (`/knowledge-board/wiki`) choose Share publicly. Failure displays no confirmed link; retry shows the link field after service confirmation. If clipboard access fails, copy the displayed field manually.",
      "Regenerate link explicitly. Failure preserves the current confirmed link; success supplies a replacement. Private Markdown/history is unchanged.",
      "Open the link as a guest. An unavailable read offers Retry shared Wiki Page. An authoritative invalid/revoked link shows a generic unavailable message.",
      "Inspect only the shared page and its attached images. Private hierarchy, revision history, linked-page content and personal contextual notes are absent.",
      "Stop sharing deliberately. Failure retains the confirmed capability for inspection/retry; confirmed revocation removes the link field and makes guest access unavailable."
    ],
    "prerequisites": [
      "A page owner for sharing; a current public link for guest reads."
    ],
    "warnings": [
      "Anyone with the public link may read the shared page and its attached images. Linked private pages, tree, breadcrumbs, revisions and editing remain inaccessible. Regeneration invalidates the old capability; Stop sharing revokes access."
    ]
  },
  {
    "id": "E2E-44",
    "title": "How do I maintain recurring Calendar events without holiday authority?",
    "aliases": [
      "Maintain recurring Calendar events without holiday authority",
      "e2e-44"
    ],
    "keywords": [
      "maintain",
      "recurring",
      "calendar",
      "events",
      "without",
      "holiday",
      "authority"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/calendar",
    "guide": "/docs/journeys/16-personal-knowledge-calendar.html#e2e-44-maintain-recurring-calendar-events-without-holiday-authority",
    "steps": [
      "Open Calendar (`/calendar`). Retry calendar recovers an unavailable year/occurrence read.",
      "Open New event. Cancel makes no changes. Enter Title, Anchor date and Repeat; inspect the conditional recurrence fields and optional Repeat until.",
      "For a monthly event on day 31, shorter months use the existing clamping rule. Select Event reminder and reminder offsets deliberately; custom offsets extend the selected list.",
      "Save explicitly. A failure retains the entered event and an uncertain-write explanation. Inspect and retry; open a displayed occurrence to edit the underlying series.",
      "A rejected series edit retains its draft. Retry and inspect another occurrence of the same series.",
      "View a global Trade Holiday. Its fields are read-only for Investors and there are no Save/Delete actions.",
      "Cancel Delete to preserve an owned event. A rejected confirmed deletion retains the series; retry removes only that series, preserving global holidays."
    ],
    "prerequisites": [
      "An authenticated Investor and an owned Portfolio."
    ],
    "warnings": [
      "Global Trade Holidays are administrator-owned and shown on every Portfolio. An Investor cannot edit/delete them. Recurrence uses one event series; reminders use the account's enabled, verified notification channels and do not prove provider delivery."
    ]
  },
  {
    "id": "E2E-45",
    "title": "How do I recover route-scoped contextual notes without overwriting unknown content?",
    "aliases": [
      "Recover route-scoped contextual notes without overwriting unknown content",
      "e2e-45"
    ],
    "keywords": [
      "recover",
      "route",
      "scoped",
      "contextual",
      "notes",
      "without",
      "overwriting",
      "unknown",
      "content"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/screeners",
    "guide": "/docs/journeys/16-personal-knowledge-calendar.html#e2e-45-recover-route-scoped-contextual-notes-without-overwriting-unknown-content",
    "steps": [
      "Open contextual notes on a supported route, such as Screeners (`/screeners`). If the existing note cannot be read, Retry contextual note first; Save remains disabled to prevent overwriting unknown content.",
      "Enter a private note after a confirmed empty read and Save. Failure retains entered text; retry and reopen the pane to inspect the confirmed note in the same context.",
      "Edit and Save deliberately. A rejected update retains the entered draft and existing record for recovery.",
      "Cancel Delete to preserve the note. A rejected confirmed deletion retains it; retry clears only the current personal contextual note.",
      "Switching Portfolio/context must not expose or replace the next context's personal content with a delayed response from the previous one."
    ],
    "prerequisites": [
      "An authenticated Investor and a contextual-note surface."
    ],
    "warnings": [
      "The note belongs to its owner, route context and active Portfolio where applicable. It is personal research, not shared advice or a trading instruction."
    ]
  },
  {
    "id": "E2E-46",
    "title": "How do I recover Portfolio settings without overwriting unknown safeguards?",
    "aliases": [
      "Recover Portfolio settings without overwriting unknown safeguards",
      "e2e-46"
    ],
    "keywords": [
      "recover",
      "portfolio",
      "settings",
      "without",
      "overwriting",
      "unknown",
      "safeguards"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/settings/portfolio",
    "guide": "/docs/journeys/17-account-delivery-settings.html#e2e-46-recover-portfolio-settings-without-overwriting-unknown-safeguards",
    "steps": [
      "Open Settings → Portfolio (`/settings/portfolio`). If the current configuration cannot be read, Retry Portfolio settings before saving; failed reads must not expose default-looking editable safeguards.",
      "Inspect and deliberately edit the intended fields. Leave unrelated limits, notification schedules and daily Kite-readiness reminder settings intact.",
      "Save portfolio settings explicitly. A rejected/unconfirmed write retains the draft and warns to inspect existing settings before retrying.",
      "Retry deliberately, then inspect the confirmed values from the refreshed configuration. If the write succeeded but refresh failed, recover the read without assuming the saved state is absent.",
      "Notification channels are configured through Settings → Notifications at account scope. The separate recall-period override and execution-mode/halt controls retain their existing explicit safeguards."
    ],
    "prerequisites": [
      "An owned active Portfolio."
    ],
    "warnings": [
      "Cash reserve, minimum actionable buy, position limits, lending and recall settings affect policy/capacity; they do not move cash or submit trades. Portfolio stop-loss and trailing-stop fields retain their accepted classic Portfolio meanings; Strategy ATR policy is configured separately."
    ]
  },
  {
    "id": "E2E-47",
    "title": "How do I maintain verified notification channels and recipient ownership?",
    "aliases": [
      "Maintain verified notification channels and recipient ownership",
      "e2e-47"
    ],
    "keywords": [
      "maintain",
      "verified",
      "notification",
      "channels",
      "and",
      "recipient",
      "ownership"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/settings/notifications",
    "guide": "/docs/journeys/17-account-delivery-settings.html#e2e-47-maintain-verified-notification-channels-and-recipient-ownership",
    "steps": [
      "Open Settings → Notifications (`/settings/notifications`). An unavailable read offers Retry and hides default-looking channel controls.",
      "Inspect Telegram, Email and Webhook health. Configure the intended channel explicitly. An existing hidden Telegram bot token is preserved when its replacement field is blank.",
      "Save a channel. Failure retains the entered destination/settings. While an operation is pending, other channel/recipient mutations remain disabled to avoid overlapping writes.",
      "Test channel deliberately on the intended authorized destination. Failed verification is distinct from healthy/verified status; inspect and retry. Enable and Save only after the current configuration is eligible.",
      "Add an additional email recipient. A failed addition retains the address; a confirmed addition remains verification pending until the signed verification flow completes. The account email is retained and cannot be removed here.",
      "Remove only an optional recipient deliberately. A failed removal retains it for recovery; success preserves the account destination.",
      "Webhook requires HTTPS. Correct rejected input and retry. Copy the signing secret shown once at the governed creation/test point; later configuration shows only that the secret is configured/hidden.",
      "Optional product-email preferences are a separate explicit save flow (E2E-17). Failed delivery through one channel does not fabricate success through another."
    ],
    "prerequisites": [
      "An authenticated account with permission to manage its own channels."
    ],
    "warnings": [
      "Test channel may send a real provider message when used in production. Only execute it with an authorized destination. Automated journeys use isolated synthetic localhost responses and qualify no actual delivery. External channels require enabled, verified configuration; in-app history remains available independently."
    ]
  },
  {
    "id": "E2E-48",
    "title": "How do I customize, persist and recover a named Dashboard layout?",
    "aliases": [
      "Customize, persist and recover a named Dashboard layout",
      "e2e-48"
    ],
    "keywords": [
      "customize",
      "persist",
      "and",
      "recover",
      "named",
      "dashboard",
      "layout"
    ],
    "synonyms": [
      "customize dashboard",
      "named dashboard",
      "default dashboard",
      "layout recovery."
    ],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/08-investor-overview-alerts.html#e2e-48-customize-persist-and-recover-a-named-dashboard-layout",
    "steps": [
      "Open the Dashboard at `/` and choose Customize under Dashboard layout. Select the Desktop or Mobile variant, then review which summary fields and cards are visible, their order and supported size.",
      "Required portfolio summary information cannot be hidden. Save the reviewed configuration as a named dashboard, or choose an existing named dashboard before editing it.",
      "Named edits save after a short pause. If that save fails, the device-local layout remains available and the toolbar shows Failed. Choose Retry named dashboard save explicitly; do not treat the local copy as confirmed account persistence.",
      "If the named-dashboard list is unavailable, retry the list read. An unavailable list is not an empty list and blocks selection/default decisions until recovered.",
      "Choose Set default for the reviewed named layout. If confirmation fails, keep the same selection and retry. Confirm the server-accepted record is labelled default.",
      "Lock the layout when accidental editing should be prevented. Export/import operates on the layout definition only; compatibility adjustments must be disclosed, and importing never changes portfolio, transaction or broker state."
    ],
    "prerequisites": [
      "an authenticated investor with an active portfolio."
    ],
    "warnings": []
  },
  {
    "id": "E2E-49",
    "title": "How do I customize the local Dashboard safely across devices?",
    "aliases": [
      "Customize the local Dashboard safely across devices",
      "e2e-49"
    ],
    "keywords": [
      "customize",
      "the",
      "local",
      "dashboard",
      "safely",
      "across",
      "devices"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/18-dashboard-combo-charts.html#e2e-49-customize-the-local-dashboard-safely-across-devices",
    "steps": [
      "Open the Dashboard (`/`) and inspect current portfolio values. Refresh recovery is E2E-14.",
      "Choose Customize. Mandatory Portfolio Value, Invested Value, Total Gain/Loss and the portfolio summary card remain visible.",
      "Choose Desktop or Mobile explicitly. Hide optional fields/cards, choose a supported card size and use accessible Up/Down moves. Desktop drag-and-drop is an additional move interaction.",
      "Confirm changes in one variant preserve the other. Supported cards remain bounded and at least the mandatory summary remains visible.",
      "Lock layout to prevent accidental edits and persist it locally. Reopen, Unlock and inspect the preserved variant.",
      "Reset to defaults restores the unnamed local factory definition; it does not delete named dashboards or another account's layout."
    ],
    "prerequisites": [
      "An authenticated Investor and the main Dashboard."
    ],
    "warnings": [
      "Local layouts belong to the current account and browser/device; another account's preferences must not be inherited."
    ]
  },
  {
    "id": "E2E-50",
    "title": "How do I maintain named dashboards and portable configuration with recovery?",
    "aliases": [
      "Maintain named dashboards and portable configuration with recovery",
      "e2e-50"
    ],
    "keywords": [
      "maintain",
      "named",
      "dashboards",
      "and",
      "portable",
      "configuration",
      "with",
      "recovery"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/18-dashboard-combo-charts.html#e2e-50-maintain-named-dashboards-and-portable-configuration-with-recovery",
    "steps": [
      "Open the Dashboard (`/`). Existing local working preferences take precedence over an account default. An unavailable named list offers Retry without replacing the current local definition.",
      "Save as named dashboard with an explicit name. Failed creation leaves the existing list and local working layout intact; inspect and repeat deliberately.",
      "Select a named layout. Edit its variant and inspect Saving, Saved or Failed. Failed autosave keeps the reviewed local definition; Retry named dashboard save submits that exact current definition. Changing layout/account does not silently retry the rejected write.",
      "Set one account default. A failed update retains the prior indication. Correct/retry explicitly and verify the confirmed default.",
      "Rename or Duplicate a named dashboard. On failure, the original record remains intact. Duplication creates a separate record; it never replaces a sibling.",
      "Copy a selected named dashboard to this device to detach the unnamed local working layout. Reopening preserves local precedence.",
      "Delete only after confirmation. Cancellation produces no delete request; a rejected deletion retains the record for deliberate recovery.",
      "Import a JSON file within 32 KB. Invalid syntax/size fails before persistence. Compatible fields/card preferences survive; unsupported entries are skipped with a warning and mandatory core visibility is restored.",
      "A service failure after a valid import is identified as an unconfirmed import, rather than invalid JSON. The original file remains unchanged and can be reselected for deliberate retry.",
      "Export JSON and inspect schema/version, lock and both variants. It contains no account identifiers, holdings, portfolio values or live data. Data export jobs and basket recovery are separately E2E-18/19."
    ],
    "prerequisites": [
      "An authenticated account with its own main Dashboard and reviewed layout definition."
    ],
    "warnings": [
      "Imported/exported layout JSON is configuration only. An unconfirmed server write is not a confirmed import/save/delete; inspect and deliberately retry. JSON import always creates a new named layout."
    ]
  },
  {
    "id": "E2E-51",
    "title": "How do I explore stock combo presets and recover saved defaults?",
    "aliases": [
      "Explore stock combo presets and recover saved defaults",
      "e2e-51"
    ],
    "keywords": [
      "explore",
      "stock",
      "combo",
      "presets",
      "and",
      "recover",
      "saved",
      "defaults"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/watchlist/TCS",
    "guide": "/docs/journeys/18-dashboard-combo-charts.html#e2e-51-explore-stock-combo-presets-and-recover-saved-defaults",
    "steps": [
      "Open Stock Details (`/watchlist/TCS`). Existing Price + Volume and individual analytical charts remain available; only one combo preset is active.",
      "Inspect the grouped preset selector. Available explicit account default wins; otherwise use Price + Volume, then the first available preset. A failed saved-preference read is disclosed and is not labelled as a known saved default.",
      "Retry an unavailable account preference or historical read explicitly. These issue ordinary read requests only; unavailable presets remain visible and disabled until their observations support use.",
      "Select a preset or use Previous/Next with cyclic wrapping, skipping unavailable entries. No arbitrary metric combination, favorites or custom axes are offered.",
      "Temporarily show/hide visible series using named legend controls. Inspect range and Daily/Monthly/Quarterly sampling inside the chart. Fundamental values remain on their dated observations, with preserved units/dual axes and aligned visible-series tooltips.",
      "Choose Set as default deliberately. A failed write retains the selected chart and explains the failure; retry then verify the confirmed account default. A new Stock Details open honors it across devices, while temporary legend/range choices do not override it.",
      "Use keyboard/touch controls and inspect unavailable reasons; targeted full WCAG checks cover the combo chart. Source/calendar cadence and actual backend persistence remain independent domain contracts."
    ],
    "prerequisites": [
      "Authenticated Stock Details with canonical dated price and metric observations available for supported presets."
    ],
    "warnings": [
      "Insufficient or unavailable history disables the affected preset with a reason. No fabricated observations, fundamental forward filling, provider synchronization or training is authorized by a chart retry."
    ]
  },
  {
    "id": "E2E-52",
    "title": "How do I recover squared-off transaction evidence and deliberate corrections?",
    "aliases": [
      "Recover squared-off transaction evidence and deliberate corrections",
      "e2e-52"
    ],
    "keywords": [
      "recover",
      "squared",
      "off",
      "transaction",
      "evidence",
      "and",
      "deliberate",
      "corrections"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/transactions/closed",
    "guide": "/docs/journeys/19-persisted-history.html#e2e-52-recover-squared-off-transaction-evidence-and-deliberate-corrections",
    "steps": [
      "Open Squared-off transactions (`/transactions/closed`). A failed read explains unavailability and offers Retry, without claiming there are no transactions.",
      "Retry the current read and inspect dated transaction type, quantity, price, realization and fees. Search by symbol/name using the current closed scope and pagination.",
      "A failed filtered read retains known rows with an unavailable warning; it does not fabricate an empty result. Retry uses the same filter.",
      "Edit uses the existing Transactions form and its retained-draft safeguards (EXE-16). Inspect the target stored evidence before changing it.",
      "Delete only the intended record after confirmation. Cancelling sends no delete request. A rejected deletion keeps the row and explains the unconfirmed operation.",
      "Inspect/retry deliberately. Confirmed deletion removes only the target, refreshes the current filtered view and invalidates affected dashboard data. Other transactions and strategy attribution remain intact.",
      "Switch Portfolios using the real selector. The previous Portfolio’s rows and counts clear before the new read; failure never presents those rows as the new Portfolio’s evidence. Retry recovers only the selected Portfolio. A late response to a prior Portfolio’s deletion cannot refresh, notify or replace this view."
    ],
    "prerequisites": [
      "An authenticated Investor with stored squared-off transaction records."
    ],
    "warnings": [
      "Deleting stored financial evidence affects portfolio calculations. Inspect the intended record and confirm deliberately. An unconfirmed deletion is not success; no broker order is placed or cancelled by this flow."
    ]
  },
  {
    "id": "E2E-53",
    "title": "How do I inspect a stored evaluation run and recover unavailable observations?",
    "aliases": [
      "Inspect a stored evaluation run and recover unavailable observations",
      "e2e-53"
    ],
    "keywords": [
      "inspect",
      "stored",
      "evaluation",
      "run",
      "and",
      "recover",
      "unavailable",
      "observations"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/candidates",
    "guide": "/docs/journeys/19-persisted-history.html#e2e-53-inspect-a-stored-evaluation-run-and-recover-unavailable-observations",
    "steps": [
      "Open Candidates (`/candidates`). Current discovery failure/retry remains the existing recommendation/discovery journey; historical evaluations are a separate named panel.",
      "An unavailable history list explains the read failure and offers Retry evaluation history. It does not report that no runs exist.",
      "Retry and choose a specific run by its stable identifier/status/result count.",
      "If that run's result read fails, preserve its selection and existing known observations. Explain unavailability rather than declaring no results; Retry selected evaluation run reads the same identity.",
      "Inspect rank, symbol, score, confidence and explanation from the stored result. Source observations remain unchanged.",
      "Select a different run. A successfully read empty run may correctly report no results, while the previous run's values are never relabelled as the new identity."
    ],
    "prerequisites": [
      "An authenticated Investor with access to portfolio discovery/evaluation history."
    ],
    "warnings": [
      "Stored evaluation scores are observations; opening or retrying history does not create recommendations, run training, place orders or move cash."
    ]
  },
  {
    "id": "E2E-54",
    "title": "How do I recover the Screener compatibility registry and import a reviewed runtime definition?",
    "aliases": [
      "Recover the Screener compatibility registry and import a reviewed runtime definition",
      "e2e-54"
    ],
    "keywords": [
      "recover",
      "the",
      "screener",
      "compatibility",
      "registry",
      "and",
      "import",
      "reviewed",
      "runtime",
      "definition"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/screeners/registry",
    "guide": "/docs/journeys/20-runtime-registry-compatibility.html#e2e-54-recover-the-screener-compatibility-registry-and-import-a-reviewed-runtime-definition",
    "steps": [
      "Open Screener Registry (`/screeners/registry`). Inspect account/shared/factory ownership and filters. An unavailable list offers Retry without reporting that no Screeners match; a failed filtered read retains known rows with an unavailability warning.",
      "Open an owned artifact by its stable identity. An unavailable envelope offers Retry registry artifact and blocks actions requiring that unknown definition.",
      "Inspect the definition hash and immutable version history. Changing the active Portfolio clears the previous artifact and reads the same identity with the new scope; inaccessible evidence stays unavailable. A failed history read retains the known artifact, explains unavailability and offers Retry registry versions without claiming there are no versions.",
      "Export the reviewed definition as portable JSON. An unconfirmed export explains failure; retry preserves definition and ownership.",
      "Inspect a shared source through its read-only detail view. Open editor is unavailable and private version history is not requested. Import copy creates an account-owned Screener on My screens; a rejected copy keeps the original source visible and unchanged.",
      "Paste Screener registry JSON with the documented condition root. Validate first; a validation failure keeps the JSON and Import disabled. Changing validated JSON invalidates validation.",
      "Import the exact validated JSON. An unconfirmed import retains the draft for deliberate retry; confirmed import opens the account-owned runtime Screener editor. Inspect its definition before using it."
    ],
    "prerequisites": [
      "An authenticated Investor and access to the account-scoped Screener compatibility registry."
    ],
    "warnings": [
      "A shared source is read-only. Copy/import creates an owned runtime definition; it does not modify the source, execute an order or qualify market data."
    ]
  },
  {
    "id": "E2E-55",
    "title": "How do I inspect portable Strategy registry evidence and deliberately enable or archive its runtime instance?",
    "aliases": [
      "Inspect portable Strategy registry evidence and deliberately enable or archive its runtime instance",
      "e2e-55"
    ],
    "keywords": [
      "inspect",
      "portable",
      "strategy",
      "registry",
      "evidence",
      "and",
      "deliberately",
      "enable",
      "archive",
      "its",
      "runtime",
      "instance"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/20-runtime-registry-compatibility.html#e2e-55-inspect-portable-strategy-registry-evidence-and-deliberately-enable-or-archive-its-runtime-instance",
    "steps": [
      "Open Strategy Registry (`/strategy/registry`) and inspect enabled/draft/archived runtime entries. An unavailable list offers Retry without reporting that no Strategies match, and a failed filtered read retains known rows. Open a specific artifact's detail by stable identity.",
      "Recover an unavailable envelope explicitly. Retry preserves the target identity and does not create or enable a Strategy.",
      "Inspect definition hash, dependencies and portable Screener references. Changing Portfolio clears prior owned evidence and reloads the current scoped identity; prior-scope responses cannot populate the new view. Recover unavailable version history separately; known immutable version evidence is preserved.",
      "Export portable JSON without portfolio-local Screener ids. Failed export retains the artifact and explains the unconfirmed action; retry downloads the reviewed definition.",
      "Enable only after reviewing the intended definition and confirming. Cancelling sends no activation. A rejected activation does not report success or disable another Strategy; review and retry deliberately.",
      "Archive only the intended enabled Strategy after confirmation. Cancelling sends no archive; rejection preserves enabled evidence. Confirmed archive preserves other enabled Strategies and past attribution.",
      "Paste and validate portable Strategy JSON with eligible Screener references and scoring configuration. Rejected validation/import retains JSON. Successful import creates a runtime Strategy draft and opens its editor for review before explicit enable; a separate Library publish/binding is not required."
    ],
    "prerequisites": [
      "An authenticated Investor with account-owned Strategy definitions and eligible Screener references."
    ],
    "warnings": [
      "Enable changes future recommendation eligibility; archive stops future recommendations for that Strategy. Other enabled Strategies and historical holdings/recommendation attribution remain intact. Neither action places an order."
    ]
  },
  {
    "id": "EXE-01",
    "title": "How do I execute an approved recommendation manually?",
    "aliases": [
      "Execute an approved recommendation manually",
      "exe-01"
    ],
    "keywords": [
      "execute",
      "approved",
      "recommendation",
      "manually"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-01-execute-an-approved-recommendation-manually",
    "steps": [
      "Open `/transactions/pending`.",
      "Select the approved recommendation.",
      "Verify action, stock, strategy, quantity and expected amount.",
      "Place the corresponding trade manually with the broker.",
      "Wait for the actual broker result.",
      "Record the actual execution in StoX using the supported transaction flow.",
      "Use actual quantity/price/fees where required rather than copying an estimate blindly.",
      "Confirm the pending recommendation is reconciled to the resulting transaction/holding."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-02",
    "title": "How do I record a manually executed transaction?",
    "aliases": [
      "Record a manually executed transaction",
      "exe-02"
    ],
    "keywords": [
      "record",
      "manually",
      "executed",
      "transaction"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-02-record-a-manually-executed-transaction",
    "steps": [
      "Open `/transactions`.",
      "Start the supported add/record transaction flow.",
      "Select the correct stock and BUY/SELL side.",
      "Enter the actual trade date/time, quantity and execution price as required.",
      "Enter charges/fees or other required accounting data where supported.",
      "If the transaction completes a pending recommendation, use the supported linkage/reconciliation path rather than creating unrelated duplicate intent.",
      "Save.",
      "Verify holdings/cash/closed-position effects."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-03",
    "title": "How do I execute in Semi-Automatic mode?",
    "aliases": [
      "Execute in Semi-Automatic mode",
      "exe-03"
    ],
    "keywords": [
      "execute",
      "semi",
      "automatic",
      "mode"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-03-execute-in-semi-automatic-mode",
    "steps": [
      "Ensure the Zerodha/Kite connection is valid for the day/session.",
      "Open `/transactions/pending`.",
      "Select the approved recommendation/order candidate.",
      "Review stock, strategy, BUY/SELL side, quantity, order type and estimated amount.",
      "Start the Semi-Automatic execution action.",
      "Complete the StoX execution authorization challenge when prompted.",
      "Confirm submission.",
      "Wait for broker acknowledgement/state rather than assuming a click means a fill.",
      "Review the resulting order status and later fill/reconciliation."
    ],
    "prerequisites": [
      "A valid Kite connection for the current session."
    ],
    "warnings": [
      "StoX requires explicit execution authorization, and broker submission does not confirm a fill."
    ]
  },
  {
    "id": "EXE-04",
    "title": "How do I authorize a Semi-Automatic execution?",
    "aliases": [
      "Authorize a Semi-Automatic execution",
      "exe-04"
    ],
    "keywords": [
      "authorize",
      "semi",
      "automatic",
      "execution"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-04-authorize-a-semi-automatic-execution",
    "steps": [
      "Kite authentication/session: Used to establish the broker connection. UI text should explicitly identify this as Kite when a Kite-provided code/login is required.",
      "StoX execution authorization: Used to authorize sensitive Semi-Automatic trade submission. Use the authenticator application registered for StoX; the UI should identify the registered authenticator name where available.",
      "If a recovery flow is being used, use a StoX recovery code only in the recovery-code field; an ordinary rotating authenticator code is not a recovery code."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-05",
    "title": "How do I review Pending Execution?",
    "aliases": [
      "Review Pending Execution",
      "exe-05"
    ],
    "keywords": [
      "review",
      "pending",
      "execution"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-05-review-pending-execution",
    "steps": [
      "Open `/transactions/pending`.",
      "Locate the approved recommendation.",
      "Confirm the strategy and stock.",
      "Confirm side/action: OPEN/INCREASE normally implies BUY; REDUCE/EXIT implies SELL.",
      "Confirm actual executable quantity and amount rather than only the original desired target.",
      "Confirm the execution window/lifetime has not expired.",
      "Confirm capital reservation/readiness for a BUY.",
      "Confirm the broker session is usable for broker-assisted modes.",
      "Continue with execution or cancel the pending intent."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-06",
    "title": "How do I submit a BUY order?",
    "aliases": [
      "Submit a BUY order",
      "exe-06"
    ],
    "keywords": [
      "submit",
      "buy",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-06-submit-a-buy-order",
    "steps": [
      "Start from `/transactions/pending`.",
      "Verify recommendation identity and strategy.",
      "Verify stock/instrument mapping, quantity, variety and order type shown by the UI.",
      "Confirm available/reserved capital and actual execution amount.",
      "Complete required execution authorization.",
      "Submit once.",
      "Wait for the broker acknowledgement/status.",
      "If the response is uncertain, do not immediately resubmit; follow EXE-13.",
      "After fill, verify the StoX transaction and strategy-owned holding."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-07",
    "title": "How do I submit a REDUCE or EXIT SELL order?",
    "aliases": [
      "Submit a REDUCE or EXIT SELL order",
      "exe-07"
    ],
    "keywords": [
      "submit",
      "reduce",
      "exit",
      "sell",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-07-submit-a-reduce-or-exit-sell-order",
    "steps": [
      "Open the pending REDUCE/EXIT item.",
      "Verify strategy identity and the holding episode it owns.",
      "Confirm whether the intent is partial REDUCE or full EXIT.",
      "Verify sell quantity against that strategy's owned quantity.",
      "Complete required authorization and submit.",
      "Monitor broker status.",
      "After fill, verify the remaining holding for REDUCE or closure for EXIT.",
      "If another strategy owns the same security, confirm that its position remains unchanged."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-08",
    "title": "How do I cancel before broker submission?",
    "aliases": [
      "Cancel before broker submission",
      "exe-08"
    ],
    "keywords": [
      "cancel",
      "before",
      "broker",
      "submission"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-08-cancel-before-broker-submission",
    "steps": [
      "Open `/transactions/pending`.",
      "Select the pending item.",
      "Confirm that no broker order has already been submitted.",
      "Use the supported cancel action.",
      "Confirm the recommendation becomes cancelled and relevant reservation is released/reconciled."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-09",
    "title": "How do I cancel a submitted broker order?",
    "aliases": [
      "Cancel a submitted broker order",
      "exe-09"
    ],
    "keywords": [
      "cancel",
      "submitted",
      "broker",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-09-cancel-a-submitted-broker-order",
    "steps": [
      "Open the submitted order/execution detail.",
      "Verify the broker order is still cancellable.",
      "Request cancellation.",
      "Treat cancellation requested as an intermediate state while broker confirmation is pending.",
      "Wait for reconciliation/confirmation from Kite.",
      "Only after broker confirmation treat the order as cancelled.",
      "Verify recommendation status, fills and reserved capital are reconciled correctly."
    ],
    "prerequisites": [],
    "warnings": [
      "Cancellation requested is an intermediate state; wait for Kite confirmation before treating the order as cancelled."
    ]
  },
  {
    "id": "EXE-10",
    "title": "How do I handle an unfilled or cancelled order?",
    "aliases": [
      "Handle an unfilled or cancelled order",
      "exe-10"
    ],
    "keywords": [
      "handle",
      "unfilled",
      "cancelled",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-10-handle-an-unfilled-or-cancelled-order",
    "steps": [
      "Inspect broker order status and confirm filled quantity is zero.",
      "Confirm the cancellation/rejection is final rather than pending reconciliation.",
      "Verify no transaction was created for an unfilled quantity.",
      "Review the recommendation lifecycle. Where the current product policy retains the recommendation for retry, confirm it remains in the appropriate pending state and funding reservation remains consistent.",
      "Retry later only through the supported execution path."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-11",
    "title": "How do I handle a partial fill?",
    "aliases": [
      "Handle a partial fill",
      "exe-11"
    ],
    "keywords": [
      "handle",
      "partial",
      "fill"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-11-handle-a-partial-fill",
    "steps": [
      "Inspect broker-reported ordered quantity and filled quantity.",
      "Confirm StoX records the filled part once and only once.",
      "Verify holding/cash changes use the actual fill.",
      "Inspect the remaining unfilled quantity/order state.",
      "If cancellation is requested for the remainder, wait for broker confirmation.",
      "Do not recreate the already filled quantity during a retry/reconciliation."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-12",
    "title": "How do I handle broker rejection?",
    "aliases": [
      "Handle broker rejection",
      "exe-12"
    ],
    "keywords": [
      "handle",
      "broker",
      "rejection"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-12-handle-broker-rejection",
    "steps": [
      "Read the broker rejection reason.",
      "Confirm that no fill occurred.",
      "Verify StoX has not created a completed transaction merely because submission was attempted.",
      "Correct the cause if it is user-actionable, such as session/order/input readiness.",
      "Recheck the recommendation and execution window before retrying.",
      "Retry through the existing intent rather than creating a duplicate recommendation/order unless the lifecycle explicitly requires replacement."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-13",
    "title": "How do I reconcile uncertain broker state?",
    "aliases": [
      "Reconcile uncertain broker state",
      "exe-13"
    ],
    "keywords": [
      "reconcile",
      "uncertain",
      "broker",
      "state"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-13-reconcile-uncertain-broker-state",
    "steps": [
      "Do not submit the same trade again immediately.",
      "Keep the StoX execution/order record in its pending/uncertain state.",
      "Trigger or wait for the supported broker reconciliation process.",
      "Compare StoX order identity with Kite's order state.",
      "If Kite confirms the order/fill, reconcile that existing order.",
      "If Kite confirms cancellation/rejection/no submission, allow the normal lifecycle to expose the safe next action."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-14",
    "title": "How do I retry without creating a duplicate order?",
    "aliases": [
      "Retry without creating a duplicate order",
      "exe-14"
    ],
    "keywords": [
      "retry",
      "without",
      "creating",
      "duplicate",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-14-retry-without-creating-a-duplicate-order",
    "steps": [
      "First complete EXE-13 when prior broker state was uncertain.",
      "Confirm the previous order is finally cancelled/rejected/unfilled as required by policy.",
      "Confirm the recommendation is still current, not expired or superseded.",
      "Confirm the intended quantity has not already been partially filled.",
      "Reuse the supported retry path for the existing recommendation/execution intent.",
      "Authorize and submit once."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-15",
    "title": "How do I verify transaction and holding after execution?",
    "aliases": [
      "Verify transaction and holding after execution",
      "exe-15"
    ],
    "keywords": [
      "verify",
      "transaction",
      "and",
      "holding",
      "after",
      "execution"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-15-verify-transaction-and-holding-after-execution",
    "steps": [
      "Open `/transactions` and locate the resulting transaction.",
      "Confirm stock, side, quantity, actual price and linkage/provenance.",
      "Open `/holdings`.",
      "For BUY, verify the strategy-owned position increased/was created as expected.",
      "For REDUCE, verify the remaining strategy-owned quantity.",
      "For EXIT, verify the relevant holding episode is closed and inspect `/transactions/closed` where applicable.",
      "Check cash/reservation effects when relevant.",
      "If the same stock belongs to another strategy, verify that unrelated strategy ownership was not changed."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-16",
    "title": "How do I correct or delete recorded transaction evidence?",
    "aliases": [
      "Correct or delete recorded transaction evidence",
      "exe-16"
    ],
    "keywords": [
      "correct",
      "delete",
      "recorded",
      "transaction",
      "evidence"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/transactions",
    "guide": "/docs/journeys/13-financial-source-lifecycle.html#exe-16-correct-or-delete-recorded-transaction-evidence",
    "steps": [
      "Open `/transactions`, find the recorded row and choose Edit. Inspect its stock, side, actual date, quantity, price and notes.",
      "Cancel Edit to leave source evidence unchanged, or enter a supported correction and choose Update Transaction.",
      "If saving is unconfirmed, retain the draft and inspect existing evidence before explicit retry. A correction updates the same transaction, not a new trade.",
      "Verify the confirmed row and derived holdings/cash/history through the accounting contract.",
      "If deletion is required, choose Delete and review its ledger/recommendation warning. Dismiss to preserve the row; confirm only the intended correction.",
      "A failed deletion preserves the visible row for inspection/retry. After confirmation inspect refreshed source and any reopened Pending Execution intent."
    ],
    "prerequisites": [
      "An owned portfolio and the actual original transaction/source evidence."
    ],
    "warnings": [
      "Corrections/deletions change financial source state and derived accounting. Deleting a recommendation-linked transaction can return its intent to Pending Execution; it does not cancel a broker fill."
    ]
  },
  {
    "id": "REC-01",
    "title": "How do I generate recommendations through the decision pipeline?",
    "aliases": [
      "Generate recommendations through the decision pipeline",
      "rec-01"
    ],
    "keywords": [
      "generate",
      "recommendations",
      "through",
      "the",
      "decision",
      "pipeline"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-01-generate-recommendations-through-the-decision-pipeline",
    "steps": [
      "Ensure the intended strategy is configured and enabled.",
      "Ensure required market data/discovery/evaluation prerequisites are available and current.",
      "Trigger the supported decision-pipeline flow, or allow its scheduled run to execute.",
      "Wait for completion; do not interpret a failed/incomplete pipeline as a valid “no recommendations” result.",
      "Open `/recommendations`.",
      "Review newly generated records by strategy, stock, action, score/evidence, capital state and status."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-02",
    "title": "How do I review new recommendations?",
    "aliases": [
      "Review new recommendations",
      "rec-02"
    ],
    "keywords": [
      "review",
      "new",
      "recommendations"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-02-review-new-recommendations",
    "steps": [
      "Open `/recommendations` or `/review` as appropriate to the current UI flow.",
      "Focus on open/current recommendations before browsing historical records.",
      "Select a recommendation.",
      "Confirm the stock and strategy.",
      "Read the proposed action and quantity/amount.",
      "Review the evidence, failed checks, market-gate result, sizing and capital status.",
      "Choose Approve, Reject or Defer according to your decision.",
      "Add review notes where useful."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-03",
    "title": "How do I understand why StoX recommended an action?",
    "aliases": [
      "Understand why StoX recommended an action",
      "rec-03"
    ],
    "keywords": [
      "understand",
      "why",
      "stox",
      "recommended",
      "action"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-03-understand-why-stox-recommended-an-action",
    "steps": [
      "Open the recommendation detail.",
      "Confirm the strategy and strategy version/configuration.",
      "Inspect the discovery/screener evidence that made the stock eligible.",
      "Inspect factor facts and strategy weighting/score.",
      "Inspect threshold/label interpretation.",
      "Check market-gate status.",
      "Check whether the strategy already owns a position in the stock.",
      "Review desired position sizing and whole-share constraints.",
      "Review capital status separately from investment opinion.",
      "For REDUCE/EXIT, inspect the primary exit reason."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-04",
    "title": "How do I understand OPEN, INCREASE, REDUCE and EXIT?",
    "aliases": [
      "Understand OPEN, INCREASE, REDUCE and EXIT",
      "rec-04"
    ],
    "keywords": [
      "understand",
      "open",
      "increase",
      "reduce",
      "and",
      "exit"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-04-understand-open-increase-reduce-and-exit",
    "steps": [
      "Open the recommendation and read its action type.",
      "Match the action to the strategy-owned position: OPEN starts a position, INCREASE adds, REDUCE sells part, and EXIT closes it.",
      "Before approval, verify that the action matches the current strategy-owned position."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-05",
    "title": "How do I understand WATCH and HOLD?",
    "aliases": [
      "Understand WATCH and HOLD",
      "rec-05"
    ],
    "keywords": [
      "understand",
      "watch",
      "and",
      "hold"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-05-understand-watch-and-hold",
    "steps": [
      "Open the recommendation and identify whether it is WATCH or HOLD_POSITION.",
      "Treat WATCH as monitoring guidance and HOLD_POSITION as informational guidance to retain the strategy-owned position.",
      "Do not approve or execute either action as a trade."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-06",
    "title": "How do I preview a recommendation for one stock?",
    "aliases": [
      "Preview a recommendation for one stock",
      "rec-06"
    ],
    "keywords": [
      "preview",
      "recommendation",
      "for",
      "one",
      "stock"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-06-preview-a-recommendation-for-one-stock",
    "steps": [
      "Open the UI surface that exposes recommendation preview for the selected stock/strategy.",
      "Select the stock.",
      "Select the strategy.",
      "Run/view the preview.",
      "Read eligibility, evaluation, market-gate and explanation information.",
      "If prerequisites are unavailable, read the reason instead of treating the preview as a valid neutral result."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-07",
    "title": "How do I approve a recommendation?",
    "aliases": [
      "Approve a recommendation",
      "rec-07"
    ],
    "keywords": [
      "approve",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-07-approve-a-recommendation",
    "steps": [
      "Open the actionable recommendation.",
      "Verify stock, strategy, action and intended quantity/amount.",
      "Review the evidence and capital state.",
      "Select Approve.",
      "Complete any confirmation/review-note step presented by the UI.",
      "Confirm the resulting status."
    ],
    "prerequisites": [
      "An actionable recommendation with capital readiness."
    ],
    "warnings": [
      "`pending_execution` means approved and waiting for execution workflow. It does not mean the broker filled an order."
    ]
  },
  {
    "id": "REC-08",
    "title": "How do I reject a recommendation?",
    "aliases": [
      "Reject a recommendation",
      "rec-08"
    ],
    "keywords": [
      "reject",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-08-reject-a-recommendation",
    "steps": [
      "Open the recommendation.",
      "Review the evidence.",
      "Select Reject.",
      "Record a note when the reason will be useful later.",
      "Confirm the recommendation is no longer in the normal approval queue."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-09",
    "title": "How do I defer and later reopen?",
    "aliases": [
      "Defer and later reopen",
      "rec-09"
    ],
    "keywords": [
      "defer",
      "and",
      "later",
      "reopen"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-09-defer-and-later-reopen",
    "steps": [
      "Open the recommendation and select Defer.",
      "Add a note explaining what you are waiting for if useful.",
      "Later locate the deferred recommendation/history.",
      "Confirm it is still eligible to be reopened.",
      "Select the supported Reopen action.",
      "Review it again using current context before approving/rejecting."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-10",
    "title": "How do I handle partial funding?",
    "aliases": [
      "Handle partial funding",
      "rec-10"
    ],
    "keywords": [
      "handle",
      "partial",
      "funding"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-10-handle-partial-funding",
    "steps": [
      "Open the recommendation detail.",
      "Compare the desired/target amount with the currently fundable/actual execution amount.",
      "Inspect own cash, recall/lending/capital-resolution information where relevant.",
      "Do not interpret partial funding as a weaker investment score unless the strategy evidence separately says so.",
      "Proceed to approval only when the lifecycle says the capital state is eligible.",
      "In Pending Execution, verify the executable amount/quantity again."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-11",
    "title": "How do I handle an unfunded recommendation?",
    "aliases": [
      "Handle an unfunded recommendation",
      "rec-11"
    ],
    "keywords": [
      "handle",
      "unfunded",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-11-handle-an-unfunded-recommendation",
    "steps": [
      "Open the recommendation.",
      "Confirm that the underlying action is still OPEN/INCREASE rather than assuming “no cash = WATCH”.",
      "Inspect the funding gap and capital-resolution state.",
      "Resolve funding through the supported cash/recall/lending flow if desired.",
      "Return to the recommendation after capital status changes.",
      "Approve only when readiness rules permit movement to pending execution."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-12",
    "title": "How do I handle a superseded recommendation?",
    "aliases": [
      "Handle a superseded recommendation",
      "rec-12"
    ],
    "keywords": [
      "handle",
      "superseded",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-12-handle-a-superseded-recommendation",
    "steps": [
      "When a recommendation is marked superseded, do not treat it as the current executable instruction.",
      "Follow the old-to-new relationship to the replacement recommendation.",
      "Compare action, target, quantity, reason and strategy version/evidence.",
      "Review the replacement recommendation normally.",
      "Confirm reservations/readiness associated with the old intent have been reconciled according to lifecycle rules."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-01",
    "title": "How do I create a screener from scratch?",
    "aliases": [
      "Create a screener from scratch",
      "scr-01",
      "new screener",
      "create screener"
    ],
    "keywords": [
      "create",
      "screener",
      "from",
      "scratch"
    ],
    "synonyms": [
      "stock filter",
      "screening rule"
    ],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-01-create-a-screener-from-scratch",
    "steps": [
      "Open Screeners at `/screeners`.",
      "Start creation of a new screener.",
      "Enter a descriptive name, for example Price Above MA200. Prefer names that describe the rule rather than names such as “My Screener 1”.",
      "Add the first condition.",
      "Select the price/close indicator on one side.",
      "Select the supported moving-average indicator and set its period to `200` on the other side.",
      "Select the `>` comparison.",
      "Review the definition and save it.",
      "Validate the screener before depending on it in a strategy."
    ],
    "prerequisites": [],
    "warnings": [
      "If the stock does not have enough historical observations to calculate the requested indicator, StoX should treat the input as insufficient rather than fabricate a value."
    ]
  },
  {
    "id": "SCR-02",
    "title": "How do I create a multi-condition AND screener?",
    "aliases": [
      "Create a multi-condition AND screener",
      "scr-02",
      "new screener",
      "create screener"
    ],
    "keywords": [
      "create",
      "multi",
      "condition",
      "and",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-02-create-a-multi-condition-and-screener",
    "steps": [
      "Open `/screeners` and create a new screener.",
      "Name it, for example Momentum Entry — MA200 + RSI.",
      "Add condition 1: Price > MA(200).",
      "Add condition 2: RSI(14) < 70.",
      "Put both conditions in an AND group.",
      "Save and validate the definition.",
      "Run or inspect the screener results."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-03",
    "title": "How do I create nested AND/OR logic?",
    "aliases": [
      "Create nested AND/OR logic",
      "scr-03"
    ],
    "keywords": [
      "create",
      "nested",
      "and",
      "logic"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-03-create-nested-and-or-logic",
    "steps": [
      "Create or edit the screener at `/screeners`.",
      "Create the outer AND group.",
      "Add the long-term trend condition.",
      "Add a nested OR group.",
      "Add the alternative momentum conditions inside the OR group.",
      "Review the visual grouping carefully. Group placement changes the meaning of the rule.",
      "Save and validate.",
      "Run the screener and inspect examples near the boundaries to confirm that the logic means what you intended."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-04",
    "title": "How do I edit an existing screener?",
    "aliases": [
      "Edit an existing screener",
      "scr-04"
    ],
    "keywords": [
      "edit",
      "existing",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-04-edit-an-existing-screener",
    "steps": [
      "Open `/screeners`.",
      "Locate the intended screener. Confirm the name/identity before editing, especially when similarly named definitions exist.",
      "Open the screener editor.",
      "Change the required condition, parameter, threshold or group.",
      "Validate the new definition.",
      "Save the change.",
      "Run the screener again when you need candidate evidence from the changed definition."
    ],
    "prerequisites": [],
    "warnings": [
      "Editing and saving a screener does not silently rewrite historical candidate evidence or automatically execute a strategy."
    ]
  },
  {
    "id": "SCR-05",
    "title": "How do I validate a screener?",
    "aliases": [
      "Validate a screener",
      "scr-05"
    ],
    "keywords": [
      "validate",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-05-validate-a-screener",
    "steps": [
      "Open the screener in `/screeners`.",
      "Use the available validation/save validation flow.",
      "If validation succeeds, review the definition once more for business meaning. Syntactically valid is not the same as economically sensible.",
      "If validation fails, read the reported condition/indicator/parameter problem.",
      "Correct the definition and validate again."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-06",
    "title": "How do I run a screener and inspect matches?",
    "aliases": [
      "Run a screener and inspect matches",
      "scr-06"
    ],
    "keywords": [
      "run",
      "screener",
      "and",
      "inspect",
      "matches"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-06-run-a-screener-and-inspect-matches",
    "steps": [
      "Open `/screeners` and select the screener.",
      "Trigger the supported screener run, or use the normal scheduled pipeline if that is the intended workflow.",
      "Wait for the run to complete rather than interpreting an in-progress/failed run as an empty result.",
      "Inspect the candidate/match results and run metadata.",
      "For an interesting stock, inspect the evidence showing which predicates/indicators matched."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-07",
    "title": "How do I reuse or import an existing screener?",
    "aliases": [
      "Reuse or import an existing screener",
      "scr-07"
    ],
    "keywords": [
      "reuse",
      "import",
      "existing",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-07-reuse-or-import-an-existing-screener",
    "steps": [
      "Check `/screeners` and `/screeners/registry` for the required definition.",
      "Inspect the screener's description, conditions and version rather than selecting it only by name.",
      "If it is a reusable/shared/factory artifact, use the supported import/create/binding flow for the current portfolio.",
      "Confirm that the resulting runtime screener belongs to or is valid for the intended portfolio/user scope.",
      "Validate it before attaching it to strategy policy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-08",
    "title": "How do I retire or archive a screener?",
    "aliases": [
      "Retire or archive a screener",
      "scr-08"
    ],
    "keywords": [
      "retire",
      "archive",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-08-retire-or-archive-a-screener",
    "steps": [
      "Open the screener management/registry surface.",
      "Identify where the screener is currently used before retiring it.",
      "If a live strategy depends on it, change the strategy to another valid screener first or otherwise resolve the dependency.",
      "Use the supported archive/retire lifecycle action.",
      "Confirm that it is no longer offered for new runtime use while historical runs remain understandable."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-09",
    "title": "How do I diagnose a match or non-match?",
    "aliases": [
      "Diagnose a match or non-match",
      "scr-09"
    ],
    "keywords": [
      "diagnose",
      "match",
      "non"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-09-diagnose-a-match-or-non-match",
    "steps": [
      "Identify the exact screener and run being investigated.",
      "Confirm the screener/version used by that run.",
      "Inspect each condition and its indicator parameters.",
      "Check whether enough historical bars existed for each indicator.",
      "For volume-dependent rules, check whether valid volume history existed. Missing volume is not zero.",
      "Check dataset freshness/data-quality status.",
      "Check whether the security was active and inside the configured universe at the relevant time.",
      "For historical runs, confirm that the investigation uses data available at that historical point rather than today's mutable state.",
      "Distinguish three outcomes: a genuine condition failure, unavailable/insufficient input, and an actual processing failure."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-01",
    "title": "How do I create a strategy using an existing screener?",
    "aliases": [
      "Create a strategy using an existing screener",
      "str-01",
      "new screener",
      "create screener",
      "new strategy",
      "create strategy"
    ],
    "keywords": [
      "create",
      "strategy",
      "using",
      "existing",
      "screener"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/screeners",
    "guide": "/docs/journeys/02-strategies.html#str-01-create-a-strategy-using-an-existing-screener",
    "steps": [
      "Confirm the required screener exists and is valid under `/screeners` or `/screeners/registry`.",
      "Open Strategy at `/strategy` or the management surface at `/strategy/registry`.",
      "Start creation of a new strategy.",
      "Give it a descriptive name, for example Momentum Core.",
      "Select/bind the intended screener for entry eligibility.",
      "Configure the remaining strategy policy: factors/weights, thresholds, exit policy, position sizing, limits, capital allocation and market gates as required.",
      "Review the complete policy. A screener is only one input to the strategy.",
      "Save the strategy.",
      "Enable it when it is ready for live decision-pipeline runs."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-02",
    "title": "How do I create a strategy when the required screener does not exist?",
    "aliases": [
      "Create a strategy when the required screener does not exist",
      "str-02",
      "new screener",
      "create screener",
      "new strategy",
      "create strategy"
    ],
    "keywords": [
      "create",
      "strategy",
      "when",
      "the",
      "required",
      "screener",
      "does",
      "not",
      "exist"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/screeners",
    "guide": "/docs/journeys/02-strategies.html#str-02-create-a-strategy-when-the-required-screener-does-not-exist",
    "steps": [
      "Go to `/screeners`.",
      "Create Momentum Entry — MA200 + RSI.",
      "Add Price > MA(200).",
      "Add RSI(14) < 70.",
      "Combine the conditions with AND.",
      "Save and validate the screener.",
      "Optionally run it and inspect representative matches to confirm the rule behaves as intended.",
      "Go to `/strategy` or `/strategy/registry`.",
      "Create the new strategy.",
      "Select the newly created screener as the relevant eligibility/discovery input.",
      "Configure scoring, thresholds, exit policy, sizing, limits and market gates.",
      "Save the strategy.",
      "Enable it only after reviewing the complete policy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-03",
    "title": "How do I configure entry criteria?",
    "aliases": [
      "Configure entry criteria",
      "str-03"
    ],
    "keywords": [
      "configure",
      "entry",
      "criteria"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-03-configure-entry-criteria",
    "steps": [
      "Open the intended strategy at `/strategy`.",
      "Confirm the strategy identity before changing policy.",
      "Select the intended screener/eligibility inputs.",
      "Configure relevant scoring factors and thresholds.",
      "Configure any entry market gates.",
      "Configure position sizing, minimum actionable amount, maximum position/holding constraints and stagger/cooldown behavior where exposed.",
      "Save the configuration.",
      "Run the decision pipeline later to consume the changed policy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-04",
    "title": "How do I configure exit criteria?",
    "aliases": [
      "Configure exit criteria",
      "str-04"
    ],
    "keywords": [
      "configure",
      "exit",
      "criteria"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-04-configure-exit-criteria",
    "steps": [
      "Open the strategy at `/strategy`.",
      "Locate the exit-policy configuration.",
      "Configure the supported exit conditions relevant to the strategy.",
      "Configure stop-loss/trailing-stop/horizon rules where part of the intended policy.",
      "Review precedence where several exit reasons could apply at once.",
      "Save the strategy.",
      "Allow a later pipeline run to evaluate existing strategy-owned holdings against the new policy."
    ],
    "prerequisites": [],
    "warnings": [
      "Entry market gates must not be treated as a reason to suppress a valid exit."
    ]
  },
  {
    "id": "STR-05",
    "title": "How do I use separate entry and exit definitions?",
    "aliases": [
      "Use separate entry and exit definitions",
      "str-05"
    ],
    "keywords": [
      "use",
      "separate",
      "entry",
      "and",
      "exit",
      "definitions"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/screeners",
    "guide": "/docs/journeys/02-strategies.html#str-05-use-separate-entry-and-exit-definitions",
    "steps": [
      "Create/validate the entry discovery rule under `/screeners` if required.",
      "Create/validate any separately modeled exit definition required by the supported strategy configuration.",
      "Open the strategy at `/strategy`.",
      "Bind/select the entry eligibility definition in the entry part of policy.",
      "Configure the exit definition/policy separately.",
      "Verify that the two have not accidentally been reversed.",
      "Save and later run the pipeline."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-06",
    "title": "How do I configure factors, weights and thresholds?",
    "aliases": [
      "Configure factors, weights and thresholds",
      "str-06"
    ],
    "keywords": [
      "configure",
      "factors",
      "weights",
      "and",
      "thresholds"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-06-configure-factors-weights-and-thresholds",
    "steps": [
      "Open the strategy.",
      "Review the supported factor/indicator set.",
      "Enable only factors the strategy intends to use.",
      "Assign weights according to the intended importance. Disabled factors should not contribute.",
      "Configure thresholds/labels in a valid order.",
      "Review the normalized result shown by StoX where applicable.",
      "Save the configuration."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-07",
    "title": "How do I configure position sizing and portfolio limits?",
    "aliases": [
      "Configure position sizing and portfolio limits",
      "str-07"
    ],
    "keywords": [
      "configure",
      "position",
      "sizing",
      "and",
      "portfolio",
      "limits"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-07-configure-position-sizing-and-portfolio-limits",
    "steps": [
      "Open the strategy.",
      "Configure target allocation/sizing policy.",
      "Review maximum position size, maximum holdings and other portfolio constraints.",
      "Review minimum actionable amount and whole-share behavior.",
      "Configure staggered-entry/cooldown rules where required.",
      "Save the strategy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-08",
    "title": "How do I configure stop-loss, trailing stop and exit policy?",
    "aliases": [
      "Configure stop-loss, trailing stop and exit policy",
      "str-08"
    ],
    "keywords": [
      "configure",
      "stop",
      "loss",
      "trailing",
      "and",
      "exit",
      "policy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-08-configure-stop-loss-trailing-stop-and-exit-policy",
    "steps": [
      "Open the strategy.",
      "Locate exit/risk controls.",
      "Configure the intended stop-loss rule.",
      "Configure trailing-stop behavior if used.",
      "Configure horizon or other supported exit causes.",
      "Review which reason should take precedence if multiple exit causes become true together.",
      "Save the strategy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-09",
    "title": "How do I configure market gates?",
    "aliases": [
      "Configure market gates",
      "str-09"
    ],
    "keywords": [
      "configure",
      "market",
      "gates"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-09-configure-market-gates",
    "steps": [
      "Open the strategy.",
      "Locate market-regime/gate configuration.",
      "Configure how bullish/neutral/bearish conditions affect entry policy.",
      "Save the strategy.",
      "On a later recommendation, inspect whether the gate blocked or adjusted an otherwise valid entry."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-10",
    "title": "How do I edit an existing strategy?",
    "aliases": [
      "Edit an existing strategy",
      "str-10"
    ],
    "keywords": [
      "edit",
      "existing",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-10-edit-an-existing-strategy",
    "steps": [
      "Open `/strategy/registry` and select the intended strategy, or open it through `/strategy`.",
      "Confirm its name/status and current configuration/version.",
      "Make the required changes.",
      "Review all affected policy sections, not only the edited field, when the change can interact with sizing/exit/capital rules.",
      "Save.",
      "Run the decision pipeline separately when new recommendations are desired."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-11",
    "title": "How do I change the screener without rebuilding the strategy?",
    "aliases": [
      "Change the screener without rebuilding the strategy",
      "str-11"
    ],
    "keywords": [
      "change",
      "the",
      "screener",
      "without",
      "rebuilding",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-11-change-the-screener-without-rebuilding-the-strategy",
    "steps": [
      "Validate the replacement screener first.",
      "Open the existing strategy.",
      "Replace the current screener/binding with the intended definition.",
      "Leave unrelated scoring, sizing and exit policy unchanged unless deliberately modifying them.",
      "Save.",
      "On the next pipeline run, verify candidate/recommendation evidence identifies the intended definition/version."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-12",
    "title": "How do I change exit policy without changing entry policy?",
    "aliases": [
      "Change exit policy without changing entry policy",
      "str-12"
    ],
    "keywords": [
      "change",
      "exit",
      "policy",
      "without",
      "changing",
      "entry"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-12-change-exit-policy-without-changing-entry-policy",
    "steps": [
      "Open the strategy.",
      "Record/confirm the current entry screener and entry policy.",
      "Modify only the required exit settings.",
      "Review precedence with existing stop/trailing/horizon rules.",
      "Save.",
      "Run the pipeline later and inspect recommendations for strategy-owned holdings."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-13",
    "title": "How do I enable a strategy?",
    "aliases": [
      "Enable a strategy",
      "str-13"
    ],
    "keywords": [
      "enable",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-13-enable-a-strategy",
    "steps": [
      "Open `/strategy/registry`.",
      "Select the strategy.",
      "Confirm its configuration is complete and valid.",
      "Enable/activate it using the available lifecycle action.",
      "Confirm its status is active/enabled."
    ],
    "prerequisites": [
      "The strategy configuration is complete and valid."
    ],
    "warnings": []
  },
  {
    "id": "STR-14",
    "title": "How do I archive or disable a strategy?",
    "aliases": [
      "Archive or disable a strategy",
      "str-14"
    ],
    "keywords": [
      "archive",
      "disable",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-14-archive-or-disable-a-strategy",
    "steps": [
      "Open `/strategy/registry`.",
      "Select the strategy to retire.",
      "Check for live recommendations, pending execution and owned holdings that need deliberate handling.",
      "Use the supported disable/archive lifecycle action.",
      "Confirm the strategy is no longer enabled for future runs."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-15",
    "title": "How do I operate multiple strategies concurrently?",
    "aliases": [
      "Operate multiple strategies concurrently",
      "str-15"
    ],
    "keywords": [
      "operate",
      "multiple",
      "strategies",
      "concurrently"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-15-operate-multiple-strategies-concurrently",
    "steps": [
      "Configure and validate each strategy independently.",
      "Enable each required strategy in `/strategy/registry`.",
      "Run the normal decision pipeline.",
      "In `/recommendations`, inspect strategy identity on recommendations rather than treating all recommendations for a stock as one combined opinion.",
      "Review/approve each actionable recommendation in its own strategy context."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-16",
    "title": "How do I same stock used by multiple strategies?",
    "aliases": [
      "Same stock used by multiple strategies",
      "str-16"
    ],
    "keywords": [
      "same",
      "stock",
      "used",
      "multiple",
      "strategies"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-16-same-stock-used-by-multiple-strategies",
    "steps": [
      "Open the recommendation for Strategy A and confirm the strategy identity.",
      "Inspect the strategy-owned position/quantity associated with Strategy A.",
      "Confirm Strategy B's position remains separately attributable.",
      "Review/approve Strategy A's EXIT if appropriate.",
      "Execute only the quantity owned by Strategy A's holding episode.",
      "After execution, verify Strategy B's holding remains intact."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "VIZ-01",
    "title": "How do I compare Stock Details combo charts?",
    "aliases": [
      "Compare Stock Details combo charts",
      "viz-01"
    ],
    "keywords": [
      "compare",
      "stock",
      "details",
      "combo",
      "charts"
    ],
    "synonyms": [],
    "category": "Stock Details visualization",
    "route": "/watchlist/TCS",
    "guide": "/docs/journeys/07-stock-details-combo-charts.html#viz-01-compare-stock-details-combo-charts",
    "steps": [
      "Open a stock in Watchlist, for example `/watchlist/TCS`.",
      "Review the existing Price + Volume chart and the combo chart in the same Stock Details area. Individual metric charts elsewhere remain available.",
      "Use the grouped preset dropdown to choose Price + P/E, Price + P/B, Price + P/S, Price + EPS, Price + Revenue, or Price + Net Profit. Only the selected preset is drawn.",
      "If a preset is unavailable for the stock, read its disabled reason in the catalogue. Use the adjacent previous/next controls to cycle among available presets; navigation wraps at each end and stays synchronized with the dropdown.",
      "Choose a time range and sampling frequency inside the chart. Fundamental observations stay on their reported dates; price sampling and volume aggregation do not invent additional fundamental observations.",
      "Hover or tap the chart and compare the aligned date/period, visible series labels and formatted values. Use keyboard or touch to show/hide a legend series temporarily.",
      "To make the current available preset the account-wide default, select Set as default and wait for the saved state. This is an account preference, not a favorite.",
      "Reopen Stock Details. The explicit default opens when available for that stock. If it is unavailable, StoX uses Price + Volume, or the first available preset when Price + Volume is unavailable. Without an explicit default, StoX uses Price + Volume with the same safety fallback."
    ],
    "prerequisites": [],
    "warnings": []
  }
]);

const STOP_WORDS = new Set(['a', 'an', 'and', 'do', 'how', 'i', 'the', 'to']);
export function normalizeHelpQuery(value) { return String(value || '').toLowerCase().normalize('NFKD').replace(/\p{M}/gu, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
function tokens(value) { return normalizeHelpQuery(value).split(/\s+/).filter((token) => token && !STOP_WORDS.has(token)); }
function editDistanceAtMostOne(left, right) { if (Math.abs(left.length - right.length) > 1) return false; let i = 0; let j = 0; let edits = 0; while (i < left.length && j < right.length) { if (left[i] === right[j]) { i++; j++; continue; } if (++edits > 1) return false; if (left.length > right.length) i++; else if (right.length > left.length) j++; else { i++; j++; } } return edits + (i < left.length || j < right.length ? 1 : 0) <= 1; }
function scoreTopic(topic, query, currentPath = '', history = []) { const normalized = normalizeHelpQuery(query); if (!normalized) return 0; const qTokens = new Set(tokens(normalized)); let score = 0; const titleAliases = [topic.title, ...(topic.aliases || [])]; if (titleAliases.map(normalizeHelpQuery).includes(normalized)) score += 100; if ((topic.synonyms || []).map(normalizeHelpQuery).includes(normalized)) score += 90; for (const token of qTokens) { if (tokens(topic.title).includes(token)) score += 24; if ((topic.aliases || []).some((value) => tokens(value).includes(token))) score += 16; if ((topic.keywords || []).includes(token)) score += 10; if ((topic.synonyms || []).some((value) => tokens(value).includes(token))) score += 7; if (token.length >= 4 && titleAliases.some((value) => tokens(value).some((candidate) => editDistanceAtMostOne(token, candidate)))) score += 5; } if (currentPath && topic.route === currentPath) score += 6; if (history.includes(topic.id)) score += 2; return score; }
export function searchJourneyTopics(query, { currentPath = '', history = [], limit = 6, exclude = [] } = {}) { return JOURNEY_TOPICS.map((topic) => { const textScore = scoreTopic(topic, query); const score = textScore + (currentPath && topic.route === currentPath ? 6 : 0) + (history.includes(topic.id) ? 2 : 0); return { ...topic, score, textScore }; }).filter((topic) => topic.textScore > 0 && !exclude.includes(topic.id)).sort((a, b) => b.textScore - a.textScore || b.score - a.score || a.id.localeCompare(b.id)).slice(0, limit); }
export function explainJourneyMatch(topic, query) { const q = new Set(tokens(query)); const matched = [topic.title, ...(topic.aliases || []), ...(topic.keywords || []), ...(topic.synonyms || [])].flatMap(tokens).filter((token) => q.has(token)); return matched.length ? 'Matched ' + [...new Set(matched)].slice(0, 3).join(', ') : 'Related StoX journey'; }
