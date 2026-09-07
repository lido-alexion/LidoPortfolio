# V5-FEAT-004 — Notification Service and Multi-Channel Delivery

**Status:** FROZEN / IMPLEMENTATION IN PROGRESS
**Product Owner freeze:** 2026-09-06  
**Supersedes:** V4-FEAT-003 as a separate feature

## 1. Problem

StoX already has in-app/Telegram notification capability and notification history, but V5 needs a coherent notification product rather than feature-specific alerts and channel calls. Notifications must distinguish user attention from underlying business-condition state, provide durable audit/history, support multiple delivery channels, survive channel/process failures, and work across the separated Investor and Admin applications.

FEAT-004 therefore becomes the single notification infrastructure and UX for StoX. The former FEAT-003 persistent critical banner is not implemented as a separate subsystem; its useful presentation behaviour is absorbed here.

## 2. Frozen behaviour

### 2.1 Global Notification Center

1. Both Investor and Admin applications have a global header notification bell available from every page.
2. The bell badge counts unread notifications only.
3. Opening the bell panel does not mark notifications read.
4. Opening a notification or invoking its primary action marks it read.
5. The panel shows recent notifications and links to a full Notification Center/history page.
6. The Notification Center is account-level and independent of the currently selected portfolio.
7. Notification Center defaults to All notifications ordered by latest activity and provides quick views for Needs attention, Unread, Critical, Resolved, and All, plus suitable secondary filters such as severity, date, type/origin and Portfolio where applicable.
8. Active deduplicated conditions order by latest occurrence/activity, not merely first creation time.
9. Delivery channels are not separate notification inboxes.
10. Individual Mark as read and Mark all as read are supported. There is no bulk resolve, dismiss or delete.

### 2.2 Ownership, audience and context

1. One notification model serves the product.
2. Recipient notification state belongs to an authenticated account.
3. Investor notifications appear only in the Investor application; Admin notifications appear only in the Admin Portal.
4. Admins do not automatically receive Investor notifications merely because they are Admins.
5. An originating source explicitly targets Investor, Admin or both audiences.
6. If both are targeted, recipient notification state/lifecycle is independent for each recipient.
7. Admin notifications are never portfolio-owned.
8. Investor notifications may carry optional context references such as Portfolio, Strategy, Recommendation, Order, Reconciliation or other domain objects.
9. A system-wide source condition/event is canonical once; FEAT-004 owns fan-out to applicable recipient accounts rather than requiring business features to construct per-user notifications.
10. Source-condition resolution propagates to still-active recipient notification instances.
11. Read/unread and delivery history remain recipient-specific.

### 2.3 Lifecycle

Notification lifecycle has two independent dimensions:

- **Attention:** `Unread` or `Read`.
- **Condition:** `Active`, `Resolved`, or `N/A` for event/informational notifications that do not represent an ongoing condition.

Rules:

1. Users control attention state.
2. The originating business/system condition controls resolution.
3. A user cannot resolve a real Active condition merely to remove it.
4. Resolution does not automatically mark a notification read.
5. Redetection of an already-read Active condition does not make it unread again.
6. Severity escalation does make the notification unread again.
7. A recurrence after a previously resolved condition is a new logical notification.

### 2.4 Severity

Exactly three severities exist:

- `Info`
- `Action required`
- `Critical`

Severity is supplied by the originating business feature. FEAT-004 does not infer business severity. Severity and lifecycle are independent.

Severity may change while a condition remains Active:

- Escalation to Critical makes the notification unread and triggers immediate enabled external delivery.
- A downgrade is recorded in history but does not make the notification unread or trigger immediate delivery.
- Future reminder behaviour uses current severity.
- A later re-escalation follows normal escalation behaviour.

### 2.5 In-app Critical presentation

1. If one or more `Critical + Active` notifications exist, the recipient application shows one persistent app-wide critical banner/strip on every page.
2. The banner cannot be dismissed while the underlying Critical condition remains Active.
3. Reading the notification does not remove the banner.
4. Multiple Critical conditions do not stack multiple banners; the compact banner indicates that multiple critical issues exist and links to their details.
5. The banner disappears automatically when no `Critical + Active` notifications remain.
6. `Action required` notifications remain visible through the bell/Notification Center and Needs attention view but do not receive this app-wide banner.
7. This presentation rule is the useful behaviour formerly represented by FEAT-003; there is no separate FEAT-003 state/store/subsystem.

### 2.6 Channels and preferences

V5 supports four channels:

- In-app — mandatory and cannot be disabled.
- Telegram — optional per recipient account.
- Email — optional per recipient account.
- Webhook — optional per recipient account.

Preferences are channel-level only. V5 has no per-severity, per-feature, per-portfolio or per-notification-type subscription matrix.

Business features publish notifications without choosing individual delivery channels.

Default delivery policy:

- `Info`: in-app only.
- `Action required`: in-app plus all enabled external channels immediately.
- `Critical`: prominent in-app plus all enabled external channels immediately.

A specifically frozen business rule may mark an Info notification for external delivery. Such delivery still routes through FEAT-004 and uses the recipient's enabled external channels; the business feature never directly calls Telegram/email/webhook.

V5 has no quiet-hours feature. Deliverable notifications/reminders are dispatched when due.

### 2.7 Active-condition deduplication

1. A continuously Active business condition has one logical notification identified by a stable condition/deduplication identity.
2. Redetection updates that notification rather than creating another.
3. Maintain at least first detected time, latest detected time, occurrence count, current severity/details/context and occurrence history.
4. Current content reflects the latest state while previous meaningful states remain auditable.
5. Ordinary detail/value changes do not make the notification unread or force immediate external delivery.
6. Severity escalation follows the escalation rules above.
7. Once resolved, a later recurrence creates a new notification.
8. `Condition=N/A` event notifications represent genuine individual events and are not collapsed as an ongoing condition.

### 2.8 Reminders

1. Initial Action-required/Critical conditions are delivered immediately to enabled external channels.
2. While still Active, they repeat externally every 48 hours.
3. Reading does not stop reminders; resolution does.
4. The reminder clock is notification-level and based on the last successful external delivery.
5. Failure of one channel does not prevent successful delivery through others.
6. Escalation to Critical causes immediate external delivery and resets the reminder clock after successful delivery.
7. Downgrade does not cause immediate delivery.
8. An Info notification does not acquire reminders merely because it has an external-delivery exception unless a future explicitly frozen business rule says otherwise.

### 2.9 External delivery failures

1. Every external delivery attempt/result is retained in delivery history.
2. Retryable failures use bounded automatic retry/backoff before the delivery is considered failed.
3. Clearly permanent failures may fail immediately.
4. Exact retry counts/backoff intervals are implementation configuration, not PO policy.
5. A persistent production delivery problem activates/updates a separate deduplicated `Action required` channel-health condition.
6. A channel-health notification never attempts delivery through the failing channel itself; other enabled working channels may deliver it.
7. Failure conditions do not recursively generate unbounded failure notifications.
8. A later successful production delivery through the affected channel automatically resolves its channel-health condition.
9. Disabling the failing channel also resolves the condition because delivery is no longer expected.
10. Failure of an optional additional email recipient is retained in delivery history/configuration health but does not mark the whole Email channel broken. Failure of the mandatory account email drives the Email channel-health condition.

### 2.10 Durable asynchronous delivery

1. Notification creation and required delivery work are persisted durably.
2. Originating business operations do not wait for Telegram, Email or Webhook network calls.
3. In-app state is available when the notification transaction commits.
4. Pending deliveries survive worker/process restart and deployment.
5. Delivery processing is idempotent where technically possible and must avoid uncontrolled duplicate sends.
6. Delivery status is subsequently visible in Notification Detail.
7. "Immediate" external delivery means queued for processing immediately, not synchronous coupling to the originating transaction.

If queued delivery is delayed:

- Event (`Condition=N/A`) notifications remain deliverable because the event occurred.
- Before sending a delayed Active-condition initial/reminder delivery, FEAT-004 checks current condition state. If already Resolved, stale delivery is suppressed and recorded as such.
- V5 does not automatically send an external "all clear" on resolution unless the originating feature defines resolution as a separate genuine notification event.

### 2.11 Email

1. The recipient account's login email is the mandatory default notification recipient when Email is enabled.
2. Additional verified notification email addresses may be configured.
3. The mandatory account email cannot be removed from notification recipients while Email is enabled.
4. Additional addresses gain no StoX identity/application access.
5. Email configuration is account-level, not portfolio-level.
6. No CC/BCC distinction or per-recipient subscription preferences exist in V5.
7. Each address receives an individually addressed delivery; recipients do not see other configured addresses.
8. Delivery result/history is tracked per recipient.
9. One recipient's failure does not prevent attempts to the others.
10. Newly added optional recipients must be verified before receiving production notifications.

### 2.12 Webhook

1. One webhook endpoint may be configured per recipient account.
2. Configuration includes HTTPS URL, enabled/disabled state and a generated StoX signing secret.
3. Requests are cryptographically signed (HMAC or equivalent secure keyed signature) so receivers can verify StoX as sender.
4. A Test webhook action exercises the real adapter/configuration.
5. Normal HTTP 2xx means successful delivery; timeout/network/non-2xx is failure subject to retry classification.
6. Payload is structured/versioned and includes stable notification identity/type, severity, timestamps, title/message, condition state and relevant safe context/action identifiers or links where applicable.
7. V5 has no multiple webhook endpoints, arbitrary custom headers, custom payload templates, event-subscription matrix or user-authored transformations.

### 2.13 Telegram

Telegram becomes an adapter behind FEAT-004 rather than something business features call directly. Existing Telegram behaviour/configuration should be migrated/reused where compatible. Telegram configuration is per recipient account and follows the same enable/disable, verification, delivery-history, retry and health-condition semantics as other external channels.

### 2.14 Channel configuration and verification

1. External channel configuration lives in a dedicated Notification Settings area in both role applications.
2. In-app is shown as always enabled.
3. Telegram, Email and Webhook expose appropriate configuration, enable/disable state, verification/health state and Test actions.
4. New external destinations/configuration must successfully verify before activation.
5. Material changes to configuration return the destination/channel to Unverified until successfully tested again.
6. A later production delivery failure does not automatically disable the channel.
7. Disabling a channel preserves its configuration and historical delivery evidence.
8. Disabled channels receive no production or reminder deliveries.
9. Re-enabling does not retroactively backfill notifications missed while disabled.
10. Test messages exercise the real delivery adapter but do not create normal Notification Center records, unread counts, deduplication occurrences, reminders or lifecycle changes.
11. Failed explicit tests do not create channel-health Action-required notifications.
12. There is no general-purpose manual "Send notification" composer in V5.

### 2.15 Notification content contract and actions

Business features publish a channel-neutral structured notification containing the required semantic information, including conceptually:

- stable notification type,
- severity,
- title and human-readable message/details,
- audience,
- condition/event classification,
- stable condition/deduplication identity where applicable,
- safe optional domain context references,
- optional primary action,
- optional explicitly frozen Info external-delivery exception.

Business features do not author separate Telegram text, email HTML or webhook payloads.

A notification supports zero or one canonical primary action:

1. It points to the owning feature/context rather than implementing remediation inside FEAT-004.
2. In-app renders the action directly; Email/Telegram render an appropriate link where possible; Webhook receives structured action/context data.
3. Performing the action marks the notification Read but does not itself resolve the condition.
4. V5 has no multiple CTA workflow, arbitrary external action URLs or notification-specific workflow engine.

### 2.16 Permanent history and detail

1. V5 retains notification history permanently.
2. Users cannot delete notification history.
3. Resolved conditions and event notifications remain searchable/viewable.
4. Occurrence and delivery history remain attached to the logical notification.
5. Historical evidence is not rewritten merely because current state changes.
6. Notification Detail exposes current content/severity/state, first/latest detection, resolution where applicable, occurrence count, safe domain context, primary action and a chronological meaningful activity/delivery timeline.
7. Timeline may include detection, redetection/detail changes, deliveries, failures, reminders, severity changes and resolution.
8. User-facing delivery failure details are sanitized; stack traces, credentials, secrets, webhook signatures and other sensitive implementation internals are never exposed.
9. Permanent retention does not imply unbounded page loads; UI uses pagination/filtering/bounded retrieval.
10. V5 has no configurable archive/purge/retention period.

## 3. Architecture

### 3.1 Ownership boundary

FEAT-004 owns:

- canonical notification/source-event representation,
- audience fan-out,
- recipient attention/lifecycle projection,
- condition deduplication plumbing,
- occurrence history,
- Notification Center and Notification Detail,
- Critical app-wide presentation,
- channel preferences/configuration/verification,
- channel-neutral-to-channel rendering,
- durable delivery jobs,
- retry/reminder scheduling,
- delivery audit/history,
- channel-health conditions.

Originating business features own:

- whether a meaningful event/condition exists,
- stable business condition identity,
- severity,
- business content/context,
- whether the condition is Active/Resolved,
- optional primary destination/action,
- any explicitly frozen Info external-delivery exception.

FEAT-004 does not determine whether a reconciliation mismatch, execution blocker, Kite problem, calendar problem or other business condition exists.

### 3.2 Conceptual entities

Implementation may choose exact table/class names, but the architecture must represent these concepts distinctly:

1. **Notification source/event/condition** — canonical business meaning and source identity.
2. **Recipient notification** — per-account attention/current projection and recipient lifecycle.
3. **Occurrence/activity history** — immutable meaningful changes/redetections/severity transitions/resolution evidence.
4. **Delivery** — requested delivery for a recipient/channel/destination and its state.
5. **Delivery attempt** — retry-level evidence as needed.
6. **Channel configuration/destination** — per-account Telegram/Email/Webhook configuration, verification and enablement.

Source and recipient separation is especially important for system-wide fan-out.

### 3.3 Admin/Investor separation

FEAT-004 must respect FEAT-042 server-side role boundaries. The shared service/infrastructure may be common code, but APIs, queries and UI must never expose another role/account's notification data or configuration. Admin notification records cannot create an ownership relationship to Investor portfolios.

### 3.4 Existing capability migration

Existing Telegram/in-app/history functionality should be migrated into or adapted behind FEAT-004 rather than duplicated. Historical compatibility/migration must preserve useful existing records where practical and avoid breaking already-shipped notification-producing features.

## 4. Algorithms

### 4.1 Publish event notification

1. Business feature publishes a channel-neutral event with `Condition=N/A` and audience/context.
2. FEAT-004 persists the canonical source/event.
3. FEAT-004 fans out recipient notification state.
4. Recipient instances begin Unread.
5. In-app availability is committed.
6. Delivery policy is evaluated from severity plus any frozen Info exception and current recipient channel settings.
7. Required external deliveries are durably queued.

### 4.2 Publish/update Active condition

1. Business feature publishes stable condition identity + current semantic state.
2. Locate the current Active logical source/condition for that identity.
3. If none exists, create a new Active source and recipient instances, record first occurrence, mark recipient instances Unread and schedule initial delivery according to severity.
4. If one exists, update current safe content/context/severity and append meaningful occurrence/activity evidence.
5. Increment/update occurrence metadata.
6. Ordinary detail changes do not alter read state or trigger immediate delivery.
7. Upward severity transition to Critical makes recipient instances Unread and schedules immediate external delivery.
8. Downgrade records history only; reminder policy subsequently uses current severity.

### 4.3 Resolve condition

1. Owning business feature reports the stable condition identity resolved.
2. Canonical source condition becomes Resolved with timestamp/evidence as appropriate.
3. All still-active recipient instances resolve.
4. Read/unread remains unchanged.
5. Future condition reminders are cancelled/suppressed.
6. Any queued stale condition delivery checks current state before send and is suppressed if resolution has already occurred.
7. Critical app-wide presentation recalculates from remaining `Critical + Active` recipient notifications.
8. A later recurrence creates a new logical notification/source occurrence rather than reopening historical evidence.

### 4.4 External delivery selection

For a recipient notification:

1. In-app always exists.
2. Determine whether external delivery is required: Action required, Critical, or explicit frozen Info exception.
3. Read current recipient channel settings at the appropriate delivery-policy point.
4. For each enabled/verified external channel, create/queue required delivery to configured destination(s).
5. Disabled/unverified channels are not production delivery targets.
6. Do not backfill periods during which a channel was disabled.

### 4.5 Reminder eligibility

A reminder is eligible only when:

1. condition is still Active,
2. current severity is Action required or Critical,
3. 48 hours have elapsed since the last successful external delivery for the notification,
4. at least one external channel is currently enabled/verified.

Queue delivery through currently enabled external channels. Successful delivery resets the notification-level reminder clock. Reading is irrelevant to reminder eligibility.

### 4.6 Channel failure condition

1. Execute delivery using adapter-specific classification and bounded retry policy.
2. On success, record delivery success and resolve that channel's existing health condition if applicable.
3. On exhausted/permanent failure, record failure.
4. If the failure satisfies the channel-health rule, publish/update one stable Action-required channel-health condition for that account/channel.
5. Exclude the failing channel when externally delivering that health condition.
6. Prevent recursive notification-failure chains.
7. Disabling the channel resolves the health condition as no longer applicable.

Email optional-recipient failures are recorded but only mandatory-account-email failure drives Email channel health.

### 4.7 Critical banner

For the authenticated recipient account/role shell:

1. Query/count current `Critical + Active` recipient notifications.
2. Zero => no banner.
3. One => render compact persistent critical presentation linked to that notification/action.
4. More than one => render one aggregate critical presentation with count and navigation to Critical/Needs-attention Notification Center view.
5. Read state does not affect this calculation.

## 5. UX

### 5.1 Header bell panel

- Available on every page in the applicable Investor/Admin shell.
- Badge = unread count.
- Recent items show severity, concise title/message, relative/absolute time as appropriate and read state.
- Opening panel does not mark items read.
- View all navigates to Notification Center.

### 5.2 Notification Center

Primary views/filters:

- All
- Needs attention (`Active` Action required + Critical)
- Unread
- Critical
- Resolved

Secondary filters may include severity, date range, notification type/origin and Portfolio where applicable. Results are paginated/bounded.

### 5.3 Notification Detail

Shows current semantic state plus meaningful lifecycle/activity/delivery timeline and primary action. It is not a raw job/log viewer.

### 5.4 Critical banner

One persistent, non-dismissible app-wide region for one-or-more active Critical notifications. It remains compact and routes to the relevant detail/list rather than stacking banners.

### 5.5 Notification Settings

Separate from Notification Center. Shows:

- In-app: always enabled.
- Telegram: configuration, enablement, verification/health, Test.
- Email: account email, optional verified recipients, enablement/health, Test.
- Webhook: HTTPS endpoint, signing-secret management, enablement, verification/health, Test.

Settings should make states such as Enabled, Disabled, Unverified and Delivery problem understandable without exposing secrets.

## 6. Acceptance criteria

FEAT-004 is acceptable when at minimum:

1. Investor and Admin role shells expose their own global bell/Notification Center with strict authorization separation.
2. Unread badge semantics match the frozen attention rules.
3. Read and condition resolution are independently represented and enforced.
4. Info, Action required and Critical severities follow the frozen default delivery rules.
5. Active-condition deduplication prevents repeated scheduler detections from creating notification spam while retaining occurrence history.
6. Resolved-then-recurrent conditions create new notifications.
7. Critical Active conditions create the single persistent app-wide presentation; Action required does not.
8. In-app cannot be disabled.
9. Telegram, Email and Webhook can be independently configured/enabled/disabled per account and require verification before activation.
10. Business producers use the channel-neutral FEAT-004 contract and do not directly call individual delivery channels for migrated notification flows.
11. Email supports mandatory account email plus individually delivered verified additional recipients.
12. Webhook supports one signed HTTPS endpoint per account and a real Test action.
13. Action-required/Critical conditions receive immediate enabled external delivery and 48-hour unresolved reminders.
14. Reading does not suppress reminders; resolution does.
15. Severity escalation to Critical makes the notification unread and triggers immediate external delivery.
16. Ordinary redetection/detail change does not create duplicates, unread resets or immediate extra sends.
17. External deliveries are durable/asynchronous and survive worker/process restart without coupling business transactions to network latency.
18. Retryable failures are retried; persistent channel failures produce one deduplicated Action-required channel-health condition without recursive failure loops.
19. A failing channel does not prevent delivery through other working channels.
20. Delayed condition delivery is suppressed when the condition has already resolved; event delivery is retained.
21. Channel disablement preserves configuration/history, stops new delivery and does not backfill on re-enable.
22. Explicit Test operations do not pollute normal notification history/lifecycle or generate channel-health notifications when they fail.
23. Notification and delivery/occurrence history is retained and users cannot delete it.
24. Notification Detail exposes a sanitized meaningful lifecycle/delivery timeline.
25. Mark all as read affects attention only and cannot resolve/dismiss/delete active conditions.
26. Existing shipped notification-producing features continue to function during migration or are explicitly migrated to FEAT-004 without semantic regression.
27. FEAT-038/039/040 notification rules integrate through FEAT-004 rather than bespoke alert stores/channel calls.

## 7. Dependencies

- **FEAT-042** — role-separated Admin/Investor shells and authorization boundaries.
- **FEAT-038** — established notification severity/lifecycle/dedup/reminder principles and calendar-originated conditions.
- **FEAT-039** — execution notifications including the frozen one-time externally delivered Info expiry case.
- **FEAT-040** — reconciliation Action-required conditions and state-driven resolution.
- Existing authentication/account-email model.
- Existing Telegram/in-app notification/history implementation to migrate/reuse.
- Durable application queue/scheduler capability; exact technology is implementation detail.

FEAT-004 becomes the notification dependency for future StoX features rather than those features introducing their own channel-specific delivery architecture.

## 8. Non-goals

V5 FEAT-004 does **not** include:

1. A separate FEAT-003 notification/banner subsystem.
2. User-configurable severity or business-condition classification.
3. Per-severity, per-feature, per-portfolio or per-notification-type subscriptions.
4. Quiet hours / Do Not Disturb schedules.
5. Manual user/Admin notification composition/broadcast tooling.
6. Multiple webhook endpoints per account.
7. Custom webhook headers, templates, transformations or subscription rules.
8. Channel-specific notification inboxes.
9. Multiple notification CTA workflow buttons or a remediation/workflow engine.
10. User-driven resolution/dismissal/deletion of active business conditions.
11. Automatic external "all clear" messages on resolution.
12. Retroactive backfill to channels that were disabled when a notification was generated.
13. Configurable notification retention/purge/archive policy.
14. Exposure of credentials, secrets, signatures, stack traces or raw operational internals in Notification Detail.
15. Business features directly selecting/calling Telegram, Email or Webhook after migration to FEAT-004.

## 9. Cross-feature reconciliation

This freeze resolves the earlier apparent tension between the notification architecture and FEAT-003:

- `Action required` remains **persistent as an unresolved notification condition** and remains in Needs attention until the business condition resolves.
- Only `Critical + Active` receives the **persistent app-wide banner presentation**.
- Therefore persistence of condition state does not imply every Action-required notification consumes permanent app-wide banner space.

Previously frozen FEAT-038/039/040 rules remain intact, except that references to direct Telegram delivery are interpreted through FEAT-004's channel abstraction where the business meaning is external delivery. The explicit FEAT-039 Info-expiry exception remains externally deliverable through FEAT-004 rather than being a bespoke Telegram call.

## 10. Implementation progress

The account-level persistence and lifecycle foundation is implemented in the canonical source, recipient projection and immutable occurrence tables. The shared authenticated `/api/notification-center` API provides bounded account-isolated list views, secondary filters, unread and active-Critical counts, detail timelines, and individual/all mark-read actions. Both authenticated role shells now render the global unread bell and recent panel, full Notification Center and one persistent aggregate active-Critical banner. Opening the bell panel does not mutate attention; opening a notification marks it read without resolving its condition, and Admin access does not require or create an Investor Portfolio.

The shared `/api/notification-settings` foundation represents In-app as mandatory and stores Telegram, Email and Webhook configuration encrypted per account. External channels cannot be enabled before successful verification; material destination changes clear verification and disable delivery. Webhook URLs require HTTPS and receive a generated signing secret that is never returned by normal settings responses. The account login email is seeded as the mandatory Email destination. Optional-address verification and settings UI remain part of the next delivery phases.

The explicit channel Test action now exercises Telegram, the configured application mailer to the account email, or a versioned HMAC-SHA256-signed Webhook request. Successful tests verify the channel; failures retain only sanitized status and never create normal notifications or channel-health conditions. Obvious local/private Webhook destinations are rejected, and a new signing secret is exposed exactly once at initial configuration while remaining encrypted and hidden thereafter.

Notification publication now atomically plans a durable external-delivery outbox for each eligible recipient, enabled/verified channel and verified destination. Destinations are encrypted at rest and keyed by a non-secret hash; idempotency keys prevent duplicate initial or escalation delivery work. Info remains In-app only unless explicitly excepted, ordinary Active redetection does not resend, and escalation to Critical creates one separate delivery generation. Reminders and channel-health projection remain subsequent implementation phases.

New outbox rows dispatch unique queue jobs after transaction commit. The production adapters deliver through Telegram, individually addressed Email or the versioned signed Webhook contract. Before network access the processor suppresses stale work for resolved conditions while retaining genuine event delivery. Retryable network/408/429/5xx failures use bounded queue backoff; permanent 4xx and exhausted failures stop. Every attempt records only sanitized status/error codes and HTTP status, and success advances the recipient notification's last-successful-external-delivery clock. Reminders and channel-health projection remain subsequent phases.
