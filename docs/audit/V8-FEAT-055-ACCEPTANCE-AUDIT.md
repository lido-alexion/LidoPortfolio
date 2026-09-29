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
- Full JS suite after the audit changes: node tests **190/190**, Vitest **99/99**, build/typecheck/docs checks passed.
- PHP syntax and `git diff --check` run on focused slices.

## External validation pending

1. Production Cloudflare Turnstile site/secret configuration and invalid-token behavior against the real provider.
2. Production mail transport delivery for verification, invite, ignore, reject, and Admin notifications.
3. Multi-worker/database concurrency proof under the deployed cache/queue configuration.

FEAT-055 should remain **REVIEW**, not COMPLETE, until those provider/runtime checks are verified. No PO decision is required.
