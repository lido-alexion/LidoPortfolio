# V8 FEAT-055 Acceptance and Security Audit

Date: 2026-09-28
Status: **REVIEW — implementation evidence strong; production provider validation pending**

Authoritative contract: `docs/archive/specs/V8-Account-Access-Request-Admin-Approval-Specification.md`.

## Requirement matrix

| Area | Status | Evidence |
|---|---|---|
| Login entry point and dedicated public route | PASS | `LoginPage.jsx`, `RequestAccountPage.jsx`, `VerifyAccessRequestPage.jsx`, public routes and JS route tests. |
| Form collects only name/email plus CAPTCHA token | PASS | Public controller validation and request page; no role/password/broker fields. |
| Server CAPTCHA verification and independent rate limits | PASS | `HumanVerificationService`, Turnstile adapter, `throttle:access-request` and `throttle:access-request-verify`; production testing driver now fails closed. |
| Loading/error/success and responsive public UX | PASS | `RequestAccountPage.jsx`, `TurnstileWidget.jsx`, frontend build/unit tests; manual mobile review remains useful. |
| Generic enumeration-resistant public messaging | PASS | `genericSubmitMessage`, blocked verification response, public controller has no status lookup. |
| Cryptographic verification token, hash storage, expiry, single use | PASS | `AccessRequestVerificationService`, unique `token_hash`, expiry/used checks, purge command, workflow tests. |
| Verification does not log in/create account/invite | PASS | Verification only creates `AccessRequest`; account remains existing invite flow. |
| Existing-user, invite, pending, ban, cooldown conflict matrix | PASS | `AccessRequestPolicyService`, workflow tests for existing user, invite, ban, pending and cooldown. |
| Concurrent duplicate submission/verification protection | PASS | Email-scoped cache lock around submission and verification; two-token regression test creates one pending request. Database/live multi-worker contention remains external runtime evidence. |
| Admin list/detail/history/pending count | PASS | `AccessRequestAdminService`, Admin controller/UI, workflow history/audit assertions. |
| Admin Create revalidation and secure invite reuse | PASS | `createInvite` row lock, policy revalidation, `UserInviteService`, concurrent Create test. |
| Admin Ignore/cooldown/private reason/neutral email | PASS | Admin service, mail class, cooldown test; payload exposes only `has_admin_reason`. |
| Admin Reject/reversible ban/private reason/generic email | PASS | Admin service, ban model/API, reject/clear test; applicant never receives reason or ban state. |
| Clear ban is Admin-only and does not reopen old request | PASS | Admin route authorization, `clearBan`, workflow test. |
| Notifications and failure isolation | PASS | Notification service catches/logs delivery failures; domain transitions are not dependent on mail success. Provider delivery remains external validation. |
| Auditability | PASS | `AccessRequestAuditLogger`, verification/pending/admin decision/ban events with actor and timestamps. |
| No public request-status lookup | PASS | Public API contains submit and verify only; no status endpoint or UI. |
| Retention/purge | PASS | `PurgeAccessRequestVerificationsCommand`, scheduled command, verification retention config. Long-term request-history retention follows operational database policy. |
| Authorization and privacy | PASS | Admin middleware/controller policy tests; applicant cannot choose role/entitlement; admin reasons omitted from public payloads. |

## Verification executed

- `AccessRequestWorkflowTest` plus `AccessRequestSecurityAuditTest`: **12 passed, 74 assertions**.
- Full Laravel V8 suite after the audit changes: **148 passed, 546 assertions**.
- Full JS suite after the audit changes: node tests **190/190**, Vitest **101/101**, build/typecheck/docs checks passed.
- Focused full WCAG 2A/2AA axe journeys: **4/4 passed** for guided-tour dialogs, fundamentals, Screener editor, and the public Request an account form; native screen-reader and deployed-provider acceptance remain external.
- PHP syntax and `git diff --check` run on focused slices.

## External validation pending

1. Production Cloudflare Turnstile site/secret configuration and invalid-token behavior against the real provider.
2. Production mail transport delivery for verification, invite, ignore, reject, and Admin notifications.
3. Multi-worker/database concurrency proof under the deployed cache/queue configuration.

FEAT-055 should remain **REVIEW**, not COMPLETE, until those provider/runtime checks are verified. No PO decision is required.


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Provider setup | PASS (configuration only) | Build `ef66133c`, VPS, 2026-10-01 18:26 UTC: Turnstile driver and site/secret configured, SMTP transport selected; three existing request rows. No token or mail-provider result inferred. |
| Valid/invalid/expired CAPTCHA, email delivery/single use, Admin Create/Ignore/Reject and notifications | NOT YET RUN | Requires controlled test addresses and Admin session; record provider IDs privately. |
| Enumeration-neutral response, mail failure isolation and concurrent duplicate submissions across workers/cache | NOT YET RUN | Perform bounded deployed test with cleanup. |

### Public deployed UI inspection — 2026-10-01 about 18:40 UTC

Cloud Chrome at `https://stoxla.in/request-account` rendered the invite-only description, Full name and Email fields, Cloudflare Turnstile "Verify you are human" widget, and Send verification email action. **PASS for page/widget rendering only.** No CAPTCHA was solved, no address submitted, and no provider token/mail delivery or enumeration/concurrency outcome was inferred. Authenticated Admin/Investor UX remains unrun because the browser session is at public login.


## 2026-10-05 production negative-path checkpoint (about 03:15 UTC)

On the deployed StoX production runtime, effective configuration reports the Turnstile driver, configured site/secret keys and SMTP mailer; secret values were not read or printed. Before the probe there were 3 access requests and 4 verification rows.

A public `POST /api/auth/access-requests` with a unique `example.invalid` address and deliberately invalid CAPTCHA token returned HTTP 422 with only the `captcha_token` validation error. Counts remained 3 requests and 4 verifications; no row for that synthetic address exists in either table. **PASS for this invalid-token/no-state-change production slice.** This does not prove valid provider-token verification, real mail delivery, or the behavior under provider outage.

An unauthenticated `GET /api/access-requests` returned 401. A well-formed 64-character but invalid verification token submitted to `POST /api/auth/access-requests/verify/{token}` returned 422. **PASS for these narrow access and invalid-link checks.** A malformed token instead misses the route's 64-character constraint and returns 404; it is not a valid test of the verification handler.

**REVIEW remains.** Next controlled test: a human solves the production Turnstile widget using a dedicated mailbox they can access, confirms the verification email and single-use link, then exercises Admin Create/Ignore/Reject with disposable test addresses and cleanup. Record provider and mail results without disclosing tokens; test cross-worker duplicate submissions separately.


## 2026-10-05 reconciliation of prior human test (2026-09-29 records)

The account owner clarified that the reported verification email arrival and successful link completion were from **previous testing**, not a new 2026-10-05 submission. The unchanged production totals (3 requests, 4 verification rows) therefore do not contradict that report. No new production submission is claimed today.

A privacy-limited read of the existing audit ledger and request state found the following September 29 sequence, without retrieving addresses, names, tokens, or reason text:

| Stored evidence | Result |
|---|---|
| Verification initiation and pending creation | Three `pending_created` events produced request IDs 1–3 with non-null `verified_at`; the owner reports receipt of a verification email and successful link completion in the earlier test. This supports the prior valid Turnstile → email → verification path, but does not identify which exact row corresponds to the owner's report. |
| Admin Reject | Request 1 is `rejected`; `admin_reject` exists, a ban row was created, and `ban_cleared` later cleared it. An internal reason is stored. |
| Admin Ignore | Request 2 is `ignored`; `admin_ignore` exists, and `resubmit_allowed_after` is populated. An internal reason is stored. |
| Admin Create | Request 3 is `created`; `admin_create` exists, and `user_invite_id` is populated. This is evidence of existing invite linkage, not proof the invite was accepted. |

These records demonstrate the deployed lifecycle and Admin decisions in bounded prior testing. They do **not** establish receipt of every outcome/Admin notification, that all three submissions used the real provider rather than a different earlier configuration, multi-worker duplicate contention, or current browser/mobile UX. Keep FEAT-055 **REVIEW** until the remaining acceptance scope is explicitly resolved or transferred to product functional testing.
