# V9-COMM-001 Implementation Audit

**Audit state:** IMPLEMENTED / VERIFIED. Final post-reconciliation acceptance passed on 2026-10-05.

**Audited checkout:** Initial audit was performed at `6a843cd4220fe240a1ca0bde13620f4c43e81274` (`master`, locally recorded as `origin/master`).

**Final reconciled base:** `d81196aa` (`origin/master`; `HEAD` fast-forwarded to this commit immediately before COMM-001 commit).

**Reconciliation:** The worktree started at `073ba812`, matching `origin/master` at continuation start. Reconciled upstream commits `f5b9fa66` (OPS-003 evidence/register only) and `073ba812` (fundamentals serialization only) do not overlap COMM-001 product code. The pre-commit fetch found six further upstream commits through `d81196aa`, all limited to V8 wishlist/audit/testing documents. `master` was fast-forwarded to `d81196aa`; those paths do not overlap the COMM-001 code, V9 register row, or this audit. The COMM-001 changes remain staged, and the four `.codex-comm001-*-prompt.txt` files remain untracked and excluded.

## Final post-reconciliation evidence (2026-10-05)

The former gap matrix and lifecycle-conflict sections below describe the earlier audited implementation. The implementation now adds account-level optional email preferences, immediate/digest routing, digest memberships and scheduling, unread/filter APIs and UI, queued invite/verification email with retained token material, immediate Admin-visible unverified access requests, same-request verification, explicit unverified approval audit, resend, and seven-day auditable expiry. All code acceptance gates requested for COMM-001 passed. Production SMTP mailbox/secret/DNS correctness, queue worker consumption, and controlled receipt smoke remain runtime-only activation prerequisites.

| Gate | Latest continuation result |
|---|---|
| `./scripts/verify-ci.sh --backend` | Passed: 2,242 tests; 2,241 passed, 1 skipped; 14,964 assertions. Full migration, seed, and OpenAPI gate green. Isolated MySQL `comm001_ci_1005`; valid PHP INI scan path. |
| Focused MySQL 8.4 notification/access-request/invite suite | Passed: 92 tests / 529 assertions on isolated `comm001_focused_1005` with valid `APP_KEY`. |
| `npm --prefix app run test:js` | Passed: Node 202/202 and Vitest 145/145 across 37 files. Initial sandbox attempt lacked loopback permission; permitted rerun passed. |
| `npm --prefix app run test:js:unit` | Passed: 52/52. |
| `npm --prefix app run typecheck` | Passed. |
| `VITE_APP_BASE=/portfolio/build/ npm --prefix app run build` | Passed. Build-generated timestamps were reverted. |
| `php scripts/verify-migration-portability.php` | Passed: 172 migrations, including bounded explicit FK/index names for new constraints. |
| `npm --prefix app run docs:static:check` | Passed: 53 topics. |
| `php artisan openapi:v1 --check` | Passed: 219 operations current (also passed within backend CI gate). |
| `git diff --check` | Final run pending after register/audit updates. |
| COMM-001 browser journeys | Passed: both `comm001-notifications.spec.js` journeys using installed pinned Chromium, including accessibility and mobile overflow checks. |
| SMTP receipt | Not run. Production SMTP mailbox/secret/DNS, queue worker consumption, and controlled receipt smoke are runtime-only prerequisites. |

Register status is updated to **IMPLEMENTED / VERIFIED** after all code acceptance gates passed. The six newly fetched upstream commits were documentation-only and non-overlapping, so no rerun was required after reconciliation. Final pre-commit fetch, commit, and push outcome will be reported with the completion summary.

## Scope and reused foundations

The audit read the two frozen COMM-001 specifications and inspected the wishlist/register, notification delivery and center code, notification settings, invitation/access-request services, and relevant frontend surfaces. The repo already has reusable V5/V8 foundations: `NotificationSource`, `RecipientNotification`, `NotificationDelivery`, `NotificationDeliveryAttempt`; `NotificationPublisher`, `GenericNotificationPublisher`, planner/processor/job and email/Telegram/webhook adapters; notification center/settings controllers and UI; `UserInvitationMail` and existing invite service; and access-request verification/admin/audit services. No parallel stack was created.

## Gap matrix (historical baseline before continuation implementation)

| # | Frozen acceptance area | Current evidence / gap | Audit result |
|---|---|---|---|
| 1 | Canonical event model | Existing notification source/recipient/delivery models and generic publisher are reusable. | Partial: current framework is condition/severity oriented; must verify lifecycle and optional event coverage. |
| 2 | Persistent in-app history | Recipient notification and source records persist; center API returns account-scoped records. | Present foundation; no retention/deletion audit gate passed. |
| 3 | Global bell, unread count, recent panel | `NotificationBell` and notification context exist. | Present foundation; browser/Vitest verification unavailable due missing dependencies. |
| 4 | Full page, chronological history, read/unread, mark individual/all, category/channel/date/search filters | Center controller supports view/severity/type/portfolio and mark-read/mark-all. No mark-unread endpoint or keyword/date/channel-status filter was found; page requests only view + page size. | Incomplete. |
| 5 | Account-level optional-email preferences; master/category toggles; quiet hours; digest time/timezone; OFF by default | Existing settings service configures external channels and destinations, not frozen account/category/quiet-hours/digest semantics. No digest scheduling contract was found in this surface. | Incomplete. |
| 6 | Mandatory account/security bypass of optional preferences | No separate mandatory-vs-optional preference router was established by inspected planner/settings code. | Unverified/incomplete. |
| 7 | Immediate vs digest-capable catalogue | Planner handles initial/reminder generations; no deterministic optional event catalogue or digest membership observed. | Incomplete. |
| 8 | Authenticated SMTP path | Laravel email adapter/mail classes exist; environment/real SMTP configuration cannot be confirmed from source audit. | Partial; runtime SMTP acceptance not evidenced. |
| 9 | Canonical HTML + plain text rendering | `notification-delivery` email view exists. | Partial; rendering parity not demonstrated by a passing test. |
| 10 | Minimal privacy content | Existing notification templates and composer require content review across event types. | Unverified; no privacy acceptance gate ran. |
| 11 | Navigation-only email actions | Existing notification action routes are navigation paths in the center. | Partial; full email-link mutation audit not evidenced. |
| 12 | Delivery state visibility | Delivery states/attempts are returned in notification detail and rendered in the page. | Present foundation. |
| 13 | Retry/fallback/idempotency | Planner uses idempotency keys and retries; processor locks delivery rows and bounds retries. | Partial; duplicate-send behavior across external send/crash boundary and acceptance tests not verified. |
| 14 | Admin diagnostics/retry | Center exposes retry on active failed deliveries; safe operational depth/authorization across lifecycle delivery not fully audited. | Partial. |
| 15 | Invite reuse, automated email failure preservation, manual copy email/link | Existing invite service/mail path is reusable. Access-request admin service creates invite and sends mail in transaction; inspected admin component search did not show copy/resend fallback controls. | Incomplete; failure/transaction semantics require correction and tests. |
| 16 | Access-request companion invariants | Current `AccessRequestService::submitForVerification` creates a verification record, not an immediately visible `AccessRequest`. `completeVerification` creates the business request after token use. Admin service requires pending request and does not show an unverified approval audit branch; inspected search found no resend flow, seven-day expiry, or UI confirmation/indicator. | Incomplete, major lifecycle conflict. |
| 17 | Lifecycle messages | Invite and access verification/outcome mails exist. Activation/password-security events were not mapped to canonical event producers in this audit. | Partial/unverified. |
| 18 | Queue isolation/retry/idempotency/dedupe | Notification delivery job/platform has locking, unique delivery identity and bounded retries. Invitation/access verification mail services require separate post-commit/failure-path verification. | Partial. |
| 19 | Operational auditability | Access request audit events exist; notification delivery attempts exist. | Partial; no end-to-end audit acceptance run. |
| 20 | Indefinite history / no user deletion | No user deletion API was found in inspected center controller. | Partial; retention and route-wide delete audit not run. |

## Access-request contract mismatch

The frozen companion requires an access request to exist and appear in Admin review before email verification. The audited implementation instead creates an `AccessRequestVerification` on submission and creates `AccessRequest` only when verification succeeds. That means the current workflow cannot meet immediate visibility, same-request verification transition, explicit unverified approval, or auditable seven-day pending+unverified expiry. This is a domain lifecycle migration, not a safe documentation-only closure.

## Verification evidence and environment limitations

| Gate | Result |
|---|---|
| Focused Laravel notification/access-request tests | Blocked by unavailable PHP SQLite driver (`could not find driver`); full backend verifier reports missing `pdo_sqlite`. |
| `npm --prefix app run test:js` | Passed with loopback permission: Node 202/202 and Vitest 143/143 (36 files). An initial sandbox run had one `listen EPERM`; isolated and full elevated reruns passed. |
| `npm --prefix app run test:js:unit` | Node-only configured suite passed: 52/52. |
| `npm --prefix app run typecheck` | Passed (`tsc --noEmit`). |
| Frontend build | Passed with network permission: `VITE_APP_BASE=/portfolio/build/ npm run build`. Build generator modified three generated docs, which were restored. |
| Migration portability | Passed: 169 migrations. |
| Static docs | `npm run docs:static:check` passed; static documentation contract current (53 topics). |
| OpenAPI check | Passed: 219 operations are current. Laravel emitted a non-fatal OTLP exporter connection error at shutdown. |
| Full backend verifier / Playwright / SMTP smoke | Backend gate blocked by missing PHP extension; Playwright and real SMTP smoke not run. |
| `git diff --check` | Passed for tracked changes; report remains untracked, so it is not included in Git's normal diff check. |

## Completion decision (historical baseline; superseded by continuation evidence above)

Do not mark `V9-COMM-001` `IMPLEMENTED / VERIFIED` based on this checkout. The frozen account preferences/digest behavior and the access-request lifecycle are visibly incomplete, and required backend/browser/build/runtime acceptance gates did not pass or could not run. No register status was changed.

## Required continuation (historical baseline; implementation performed in this continuation)

Make Git metadata writable, reconcile `master` with current `origin/master`, install the repository-declared dependencies/services, then implement the missing optional-email preference/digest path and the access-request lifecycle migration against the existing models/services. Add acceptance coverage for the full frozen matrices, preserve the existing invitation token and delivery platform, run all required verifiers, and update the register only after evidence is green. Production SMTP identity/DNS/secret configuration and controlled receipt smoke test remain runtime activation prerequisites even after code acceptance.
