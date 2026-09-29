# StoX V9 Email Notifications & Account Lifecycle Messaging Specification

| Field | Value |
|---|---|
| **Epic** | `V9-COMM-001` — StoX Email Notifications & Account Lifecycle Messaging |
| **Version target** | V9 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |

## 1. Product intent

Add a reusable StoX notification framework with email as an external delivery channel and in-app notifications as the persistent canonical history. Preserve the existing account-invitation semantics and automate the manual email-sending step without redesigning working invitation behavior.

The design must distinguish mandatory account/security communication from optional product notifications, respect user preferences, avoid exposing sensitive financial details in email, and preserve existing StoX authorization and execution safeguards.

## 2. Scope

In scope:

- automated email delivery for the existing account invitation/account lifecycle flows;
- mandatory account/security messages;
- selected critical product notifications;
- a canonical notification-event model shared by in-app history and email delivery;
- a global in-app notification bell with unread count and recent items;
- a full Notifications page with history, filters and keyword search;
- per-account optional-email preferences, quiet hours and daily digest time;
- delivery-state visibility and safe retry/fallback behavior;
- HTML and plain-text email rendering from the same canonical content;
- authenticated SMTP using the dedicated StoX mailbox;
- version-controlled notification templates;
- operational auditability, queueing, retry, idempotency and deduplication.

Out of scope:

- push notifications, SMS, WhatsApp or other external channels;
- public/unverified secondary email addresses;
- multiple notification addresses per account;
- per-portfolio notification preferences;
- direct approval, execution, cancellation or other state-mutating actions from email;
- Admin-editable email templates;
- user deletion of notification-history records;
- a full generic communications/marketing platform.

## 3. Existing invitation behavior to preserve

StoX already:

- creates the invitation/account-setup token;
- creates the invitation link;
- generates the invitation email content;
- applies the current single-use and expiry behavior.

Today, an Admin copies that generated content and sends it manually from the mailbox.

V9-COMM-001 must reuse that existing invitation generation/content flow. The epic automates delivery and adds delivery state, retry, diagnostics and fallback UX. It must not introduce a parallel token model or duplicate the existing invitation-content logic unless implementation audit identifies a concrete defect that must be fixed.

Invitation behavior remains:

- single-use;
- expiring;
- invalid after successful use;
- resend issues a new token/link and invalidates the superseded one according to existing StoX rules;
- passwords are never emailed.

## 4. Canonical notification-event model

StoX shall create one canonical notification event and route it to one or more delivery channels.

The same event may feed:

- in-app notification history;
- email delivery where mandatory or enabled by user preference;
- future channels in later versions without duplicating business-event logic.

The canonical event should contain only the structured information required to render channels safely, including a stable event type/category, recipient account, title/message payload, target route/context where applicable, severity/mandatory classification, occurrence time and channel-delivery state.

Exact schema and persistence details are implementation-level decisions.

## 5. Notification classification

### 5.1 Mandatory account/security notifications

Mandatory notifications are not suppressible by optional-email preferences or quiet hours where immediate delivery is required.

Examples include:

- account invitation/setup;
- account activation/confirmation;
- password-reset/security events;
- material account-state or credential changes;
- other events explicitly classified as mandatory account/security communication.

An event must not silently bypass user preference while still being labelled optional. If StoX determines that delivery is mandatory, the event must be explicitly classified as mandatory.

### 5.2 Optional product notifications

Initial optional categories should include critical/high-value product events such as:

- recommendation status changes;
- order/execution state changes;
- broker/Kite connection issues;
- material scheduled-job/data/operational failures;
- similar actionable product events approved during implementation.

All optional product-email categories are **OFF by default for newly created accounts**.

The canonical in-app event history still records the event regardless of optional email preference.

## 6. User email preferences

Preferences are account-level only.

Provide:

- a master **Optional emails** switch;
- per-category optional-email controls;
- one account-level quiet-hours window for non-critical optional emails;
- one account-level preferred daily digest time in the user's configured timezone.

Rules:

- disabling the master switch suppresses all optional product emails;
- mandatory account/security emails remain enabled;
- per-category selections remain stored while the master switch is off and resume if re-enabled;
- optional means optional: no silent override of the master/category preference;
- quiet hours affect only non-critical optional email;
- mandatory/critical account-security messages bypass quiet hours when immediate delivery is required.

## 7. Immediate vs digest delivery

- Critical/time-sensitive eligible events use immediate delivery.
- Non-urgent optional events may be configured for daily digest delivery.
- The user has one preferred daily digest time in the account timezone.
- Exact classification of individual optional event types as immediate-capable, digest-capable or both is an implementation catalogue decision consistent with this product contract.

Digest processing must preserve event identity and must not create duplicate emails on retries.

## 8. Email privacy and action boundaries

Email content must be intentionally minimal.

Do not include unnecessary sensitive financial details such as:

- portfolio values;
- detailed holdings;
- order values;
- quantities;
- other sensitive trading/account data unless strictly required for the notification's purpose.

Prefer a concise description of what happened plus a secure authenticated link back to StoX.

Email buttons/links are **navigation-only**. They may open pages such as:

- View Recommendation;
- Review Order;
- Reconnect Kite;
- Open StoX;
- relevant account/security page.

They must never directly approve, execute, place, cancel or mutate financial/account state merely by following the email link.

Normal StoX authentication and authorization apply after navigation.

## 9. In-app notification experience

StoX shall provide:

- a persistent global notification bell/icon in the authenticated app shell;
- unread count;
- a recent-notifications panel/dropdown;
- a link to a full Notifications page.

The full Notifications page shall support:

- chronological history;
- read/unread state;
- mark individual notification read/unread;
- mark all as read;
- basic filtering by category;
- read/unread filter;
- channel/delivery status filter where relevant;
- date-range filter;
- simple keyword search over notification title/message.

Users may not delete notification-history records.

Notification history is retained indefinitely unless StoX later adopts an explicit retention policy.

No favorites/pinning/archive/snooze capability is required for this epic.

## 10. Delivery-state visibility

Where relevant, surface channel state such as:

- in-app delivered/read;
- email queued;
- email accepted/sent where known;
- email failed;
- retry pending or retried.

Both Admin and end users may see appropriate delivery status.

End-user views must remain simple and must not expose provider credentials, sensitive transport diagnostics or internal stack traces.

Admin may see deeper safe operational detail and controls, including where applicable:

- failure reason;
- retry action;
- copy generated email content;
- copy invitation link;
- invitation-specific fallback controls already supported by StoX.

## 11. Failure and fallback behavior

Email delivery failure must not roll back an already valid account/invitation/business event.

For invitation/account-lifecycle email failures:

- preserve the created invitation/account state;
- mark email delivery failed;
- expose a safe retry;
- preserve the current manual-send fallback through copyable generated content;
- expose the invitation link where appropriate and authorized.

Normal application requests should not block on SMTP delivery.

Use asynchronous queued delivery after the relevant database transaction commits.

## 12. Queueing, retries, deduplication and idempotency

Implementation shall use Laravel mail/notification capabilities through the existing database-backed queue.

Requirements:

- dispatch only after the originating business transaction commits;
- bounded automatic retries with backoff;
- idempotent delivery/event processing;
- no duplicate invitations or status emails from queue retries;
- repeated identical **non-critical** product emails may be deduplicated/suppressed within a short implementation-defined window;
- mandatory account/security messages and genuine critical state transitions must not be silently suppressed;
- in-app history may record each occurrence or deterministic aggregation according to event semantics, but must remain auditable.

Exact retry counts/backoff/deduplication windows are implementation-level decisions.

## 13. Email rendering

Each email shall have:

- branded HTML rendering;
- equivalent plain-text fallback;
- both generated from the same canonical notification/event data;
- centralized version-controlled templates;
- appropriate escaping and safe link generation.

Notification templates are not editable through the Admin UI.

## 14. Sender identity and SMTP configuration

The earlier production proof of concept successfully verified the StoX VPS -> GoDaddy SMTP path using the temporary `admin@lidoalexion.com` mailbox.

Production implementation must create and use:

`stox@lidoalexion.com`

Before enabling production application mail with that identity:

1. create/verify `stox@lidoalexion.com` in the existing GoDaddy/cPanel mail service;
2. verify authenticated SMTP from the production VPS;
3. verify SPF, DKIM and DMARC for the selected sender/service;
4. update production environment settings;
5. run a controlled delivery smoke test and confirm receipt.

Expected environment configuration remains based on the tested GoDaddy SMTP endpoint, subject to actual mailbox settings:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=bom1plzcpnl502771.prod.bom1.secureserver.net
MAIL_PORT=465
MAIL_USERNAME=stox@lidoalexion.com
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=stox@lidoalexion.com
MAIL_FROM_NAME=StoX
```

`MAIL_PASSWORD` must exist only in the protected deployment environment or approved secret store, never source control or logs.

Keep matching non-secret keys in `.env.example` with password blank/placeholder-only.

## 15. Recipient policy

StoX sends notification email only to the account's verified primary email address.

This epic does not support:

- additional notification addresses;
- per-category recipient addresses;
- per-portfolio recipients.

## 16. Access-request/account lifecycle integration

Integrate with the existing V8 account-access/invitation lifecycle rather than replacing it.

The beta-resilience behavior for unreliable inbox delivery is normative in [`V9-Account-Access-Request-Email-Resilience-Specification.md`](V9-Account-Access-Request-Email-Resilience-Specification.md). In particular, email verification is advisory rather than a prerequisite for Admin review/approval; unverified requests are Admin-visible immediately; unverified pending requests expire from the active queue after seven days; and the manual Copy invitation email fallback must remain available after invite creation.

Account creation, access-request approval, invitation generation, invitation acceptance and activation remain distinct auditable steps.

Email delivery is a channel outcome, not the authority that grants access.

## 17. Diagnostics and observability

Record enough operational state to answer:

- which canonical event was created;
- which channels were selected;
- when an email was queued;
- whether delivery was accepted/failed as far as the SMTP layer can determine;
- retry count/state;
- whether an event was suppressed by user preference, quiet hours, digest routing or non-critical deduplication.

Do not claim confirmed inbox delivery when SMTP can only confirm provider acceptance.

Provider failures must remain recoverable and visible without blocking unrelated StoX workflows.

## 18. Testing requirements

Automated tests must cover at minimum:

- canonical event creation/routing;
- mandatory vs optional classification;
- optional-email master/category preferences;
- new-account defaults (optional email OFF);
- quiet-hours behavior;
- digest scheduling/routing;
- queue retry/idempotency;
- non-critical duplicate suppression;
- invitation-flow reuse without duplicate token/content logic;
- failed delivery and manual fallback;
- HTML/plain-text rendering;
- read/unread notification behavior;
- filters/search;
- authorization on target navigation;
- prevention of direct state mutation from email links;
- environment/secret-safe behavior using fake mail transport in automated tests.

A controlled staging/production smoke test shall verify real SMTP delivery with the dedicated sender.

## 19. Acceptance criteria

V9-COMM-001 is complete only when:

- existing invitation generation/content behavior is preserved and automated email delivery replaces the manual send as the normal path;
- failed invitation email can be retried and manually sent using preserved copy-content/link fallback;
- one canonical notification event feeds in-app and email channels;
- mandatory account/security email semantics are distinct from optional product email;
- optional email is OFF by default for new accounts;
- master and per-category optional-email controls work;
- quiet hours and daily digest time work for eligible optional mail;
- in-app bell, unread count, recent panel and full Notifications page exist;
- history supports read/unread, mark-all-read, filters and keyword search;
- notification records cannot be deleted by users and are retained indefinitely under the current policy;
- email content is minimal and navigation-only;
- users and Admin can see appropriate delivery state;
- Admin has safe retry/diagnostic/fallback controls where relevant;
- HTML plus plain-text rendering is implemented;
- templates are version-controlled;
- only the verified primary account email is used;
- SMTP uses the dedicated `stox@lidoalexion.com` identity and protected environment secrets;
- queueing/retry/idempotency/deduplication are tested;
- no optional product email silently overrides user preference;
- real SMTP smoke validation passes;
- the linked beta access-request resilience specification is implemented, including optional verification for Admin approval, seven-day unverified cleanup, verification-state visibility, resend support, and manual Copy invitation email fallback.

## 20. Frozen PO decisions

The following product decisions are frozen:

- Scope: account lifecycle plus critical/high-value product notifications.
- Optional email preferences are per category; mandatory account/security messages remain enabled.
- Critical eligible notifications may be immediate; non-urgent optional notifications may use digest delivery.
- Email content contains minimal sensitive financial detail.
- Email links/buttons are navigation-only and never perform financial/account mutations.
- Preserve the existing single-use/expiring invitation behavior and existing generated mail content; automate delivery rather than redesigning it.
- Failed automated invitation mail preserves the invitation and offers Admin retry plus manual copy/send fallback.
- Maintain an in-app notification history.
- One canonical notification event feeds all delivery channels.
- Show relevant per-channel delivery state.
- Both Admin and end user can see appropriate delivery status; Admin receives deeper safe diagnostics/controls.
- Support read/unread and Mark all as read.
- Users cannot delete notification-history records.
- Retain notification history indefinitely unless a later retention policy supersedes this.
- In-app history records the event regardless of optional email preference.
- Deduplicate/suppress repeated identical non-critical emails within a short window.
- Provide global notification bell plus full Notifications page.
- Provide basic filters and simple keyword search.
- Provide HTML email plus plain-text fallback.
- Templates remain version-controlled in code, not Admin-editable.
- Notification preferences are account-level only.
- Provide account-level quiet hours for non-critical optional email.
- Provide one account-level preferred daily digest time in user timezone.
- Use only the verified primary account email address.
- Provide master Optional emails switch plus category controls.
- All optional product email categories are OFF by default for new accounts.
- Optional events never silently bypass the user's email preference; truly mandatory events must be explicitly classified mandatory.
- During beta, email verification is advisory for access-request approval; Admin may approve an explicitly unverified request.
- Unverified pending access requests expire from the active queue after seven days without hard deletion.
- Preserve/reintroduce the manual Copy invitation email fallback using the same existing invite after automated delivery is attempted.
