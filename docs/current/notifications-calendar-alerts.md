# Notifications, Calendar, And Alerts

## 1. Purpose And Scope

This document owns notification occurrence, recipient history, delivery, attempts, channel settings/health, reminders, portfolio alerts, calendar events, and Admin operational alerts. Notifications observe domain state and communicate it; they do not own recommendation, execution, accounting, or broker state.

Execution-related event semantics belong in [Execution, Broker, And Safety](./execution-broker-safety.md). Cash/lending/recall source state belongs in [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md). Security verification/capability rules belong in [Administration, Security, And API](./administration-security-api.md).

## 2. Notification Architecture

The durable notification pipeline separates:

`Domain event or condition -> NotificationSource -> NotificationOccurrence -> RecipientNotification -> NotificationDelivery -> NotificationDeliveryAttempt`.

`NotificationSource` represents an event or active condition. `NotificationOccurrence` records detected, redetected, escalated, or resolved activity. `RecipientNotification` is each user's in-app/history and attention state. A `NotificationDelivery` is channel-specific outbox work; an attempt records a provider interaction. Channel settings and destinations determine whether external work is planned.

**Notification state is not domain state.** A failed email, Telegram, or webhook never changes a recommendation, order, alert policy, accounting entry, broker connection, or execution state.

**Current implementation anchors:** `NotificationPublisher`, `NotificationDeliveryPlanner`, `NotificationDeliveryProcessor`, `NotificationSource`, `RecipientNotification`, `NotificationDelivery`, and `NotificationDeliveryAttempt`.

## 3. Notification Lifecycle

The current lifecycle is:

`domain event -> occurrence -> recipient fan-out -> channel selection -> queued delivery -> processing -> delivered / suppressed / failed -> bounded retry where eligible -> history and attention state`.

- Source/condition state records the domain-observed condition, including active/resolved where applicable.
- Delivery status is independent: `queued`, `processing`, `delivered`, `suppressed`, or `failed`.
- Attempt status records a particular provider attempt as `succeeded` or `failed`.
- Recipient attention state is `unread` or `read`; it does not resolve a condition.

An intentionally disabled, excluded, unverified, or unsupported external channel produces no delivery work. A resolved active condition may suppress queued work before a network send.

## 4. Notification Occurrence And Idempotency

Every event or active condition has stable identity, audience, severity, title/message snapshot, and user fan-out. Active conditions use a stable `condition_key`; repeat detection updates the active source and records a new occurrence instead of creating duplicate active source state. Resolution clears the active key while retaining history. A later recurrence after resolution creates a new source/lifecycle.

Recipient fan-out is unique per source/user. External delivery idempotency uses recipient, generation/kind, channel, and destination hash. Repeated scheduler runs therefore must not create duplicate initial, escalation, or reminder deliveries for the same delivery key.

**Current implementation anchors:** `NotificationPublisher::publishCondition()`, `NotificationPublisher::resolveCondition()`, `NotificationDeliveryPlanner`, and `NotificationPublisherTest`.

## 5. Channel Selection

In-app history is created through recipient fan-out. External channels are planned only for action-required or critical sources, or for an information source that explicitly allows external delivery. The planner selects a user's enabled, verified channel settings and excludes channels requested in source context.

Supported external channels are email, Telegram, and webhook. A source may fan out to more than one eligible channel; failure of one does not block planning or success accounting for another. Disabled, unverified, excluded, missing-destination, and unsupported channels are explicit non-delivery conditions, not inferred provider failures.

**Current implementation anchors:** `NotificationDeliveryPlanner`, `NotificationChannelSetting`, `NotificationEmailDestination`, and `NotificationSettingsApiTest`.

## 6. In-App Notifications

The notification bell, notification center/history, and critical banner consume the recipient notification record. History is account-scoped and supports unread/read state, condition state, recipient counts/views, and detail access. Opening a detail does not itself mark it read; explicit read actions change attention state only.

Critical escalation may make recipient instances unread again so that increased severity becomes visible. In-app history is durable user evidence even when no external channel is enabled.

**Current implementation anchors:** `NotificationCenterController`, `NotificationContext`, `NotificationBell`, `NotificationHistoryPage`, and `NotificationCenterApiTest`.

## 7. Critical Notification Banner

`CriticalNotificationBanner` surfaces critical current notification conditions prominently. It is a presentation of recipient/source attention and condition state, not a control over the underlying domain. Dismissing or marking a banner read must not resolve an execution halt, broker mismatch, capital condition, or other source condition.

A banner may remain visible while its source condition is active even after a related UI read/dismiss action; source resolution controls condition resolution. Notification history retains the evidence.

**Current implementation anchors:** `CriticalNotificationBanner`, `NotificationPublisher::resolveCondition()`, and `NotificationCenterApiTest`.

## 8. Email Channel

Email uses a verified destination. The account email is available as a protected account destination; optional destinations require signed verification before they can be enabled. Material destination changes return the channel to unverified and disabled. Delivery is recorded per persisted destination, with sanitized failure evidence and bounded retry handling.

Signed email-verification links authorize destination verification only. See administration/security documentation for temporary signed URL boundaries.

**Current implementation anchors:** `NotificationChannelSettingsService`, `NotificationSettingsController`, `NotificationEmailDestination`, `NotificationDeliveryMail`, and `NotificationSettingsApiTest`.

## 9. Telegram Channel

Telegram destination/configuration is user-scoped, verified before enablement, and delivered through the common outbox/adapter path. A legacy Telegram configuration may be migrated only when it is unambiguous; conflicting legacy configurations must not be silently selected. Delivery failure follows the common attempt/retry/health process.

**Current implementation anchors:** `TelegramNotificationService`, `LegacyTelegramChannelMigrator`, notification delivery adapters, `LegacyTelegramChannelMigratorTest`, and `SettingsTelegramTest`.

## 10. Webhook Channel

Webhook settings require a valid HTTPS endpoint and protect generated secret material: secrets are revealed only at the relevant creation/test point and persisted configuration is protected. Test delivery is versioned and HMAC-signed. Invalid/local destinations are rejected before ordinary notification delivery.

Webhook delivery uses the same durable delivery/attempt model and bounded retry rules as other external channels. A failed webhook does not alter the source condition or fabricate delivery in another channel.

**Current implementation anchors:** `NotificationChannelSettingsService`, `NotificationChannelTester`, notification delivery adapter, and `NotificationSettingsApiTest`.

## 11. Delivery Attempts And Retries

Every provider send records an auditable attempt number, status, response status, error code, and time. A successful attempt marks the delivery `delivered` and updates the recipient's latest successful external delivery time. Retryable failure requeues the delivery; retry is bounded to fewer than three attempts. Permanent failure or exhausted retries ends in `failed`.

Intentional non-planning and condition suppression do not retry. Error evidence must be sanitized. Requeuing a failed source/channel uses the existing durable delivery instead of creating a duplicate occurrence.

**Current implementation anchors:** `NotificationDeliveryProcessor`, `NotificationDeliveryAttempt`, `ProcessNotificationDelivery`, `NotificationDeliveryProcessorTest`, and `TosNotificationMigrationTest`.

## 12. Channel Health

Terminal delivery failure creates or updates a deduplicated per-user/per-channel health condition, normally surfaced as action-required. A later successful delivery resolves that health condition. Health-notification channels exclude the failing channel to prevent recursive self-notification.

Channel health is communication operational state only. It never changes the underlying recommendation/order/accounting/alert state and is not evidence of success on another channel.

**Current implementation anchors:** `NotificationChannelHealthService` and `NotificationDeliveryProcessorTest`.

## 13. Read, Acknowledge, Dismiss, And Resolve Semantics

| Action | Changes | Does not change |
| --- | --- | --- |
| Mark notification read | Recipient attention state and read timestamp | Source condition, alert policy, execution/cash state, delivery history. |
| Acknowledge portfolio/Admin alert | Alert-specific acknowledgement/expiry state where defined | Historical notification occurrence/delivery evidence. |
| Dismiss UI/banner | Presentation/attention behavior where supported | Domain condition or safety control. |
| Resolve condition | Source and recipient condition state | Prior occurrences, attempts, and recipient history. |

These states must not be conflated in future UI or service work.

## 14. Notification History

Notification history is account-scoped durable evidence of source, occurrence, recipient attention, source condition, channel delivery, and attempts. Views/counts follow the current attention and condition rules. History is not financial, recommendation, execution, or alert-policy source-of-truth.

Delivery destinations remain protected from ordinary response payloads. Retention policy beyond durable current records requires verification before any purge policy is documented.

**Current implementation anchors:** `NotificationQueryRepository`, `NotificationCenterController`, `NotificationHistoryPage`, `NotificationDelivery`, and `NotificationCenterApiTest`.

## 15. Notification Settings

Settings are account-scoped. In-app is mandatory; external channels require verified configuration before enablement. Settings include enable/disable state, destination/configuration, verification, and controlled test delivery. Material endpoint/destination change invalidates verification and disables the external channel until reverified.

Admin settings routes intentionally operate without an Investor portfolio but remain Admin-authorized; Investor settings remain user/profile scoped as applicable.

**Current implementation anchors:** `NotificationSettingsController`, `NotificationChannelSettingsService`, `NotificationChannelTester`, and `NotificationSettingsApiTest`.

## 16. Recommendation Notifications

Notification sources may communicate actionable recommendations, capital-required `UNFUNDED`/`PARTIAL` conditions, review/execution lifecycle attention, expiry, supersession, and completion/failure outcomes when an owning domain publishes them. HOLD/WATCH are informational and are not converted into action-required notification merely by notification transport.

Notifications describe these events; they do not approve, execute, expire, or supersede recommendations. Recommendation lifecycle semantics remain in [Strategy And Recommendations](./strategy-and-recommendations.md).

**Current implementation anchors:** `RecommendationExecutionNotificationService`, `NotificationReminderService`, `Section30RecommendationNotifyTest`, and `NotificationMessageComposer`.

## 17. Execution And Broker Notifications

Execution/broker communication includes Kite readiness, approaching execution expiry, execution failure/attention, reconciliation discrepancies/recovery, position-protection issues, and emergency safety events where published by the execution domain. Automatic portfolios with unusable Kite readiness are reminded at most once per day and are silent outside their eligible conditions.

These sources provide attention and evidence only. They never change broker/order/reconciliation state merely because a channel was read, skipped, or failed.

**Current implementation anchors:** `KiteReadinessReminderService`, `RecommendationExecutionNotificationService`, execution safety/reconciliation publishers, and `KiteReadinessReminderTest`.

## 18. Capital, Lending, And Recall Notifications

Capital request, lender selection, approval/rejection, recall, pending settlement, bridge creation/partial/completion, repayment, and proceeds/capital-resolution terminology are notification events from their owning workflows. They communicate an explicit capital lifecycle; they do not create a loan, release a reservation, or settle cash.

**Current implementation anchors:** `RecallNotificationService`, lending/capital services, and `RecallNotificationTest`.

## 19. Portfolio Alerts

Portfolio alert policies define conditions over portfolio/holding/market fields. Evaluation creates/updates alert occurrences with severity and message context. Active conditions deduplicate while true; expiry/acknowledgement/clear behavior changes the alert lifecycle, and a later valid recurrence may establish a new lifecycle. Alert fan-out is a notification concern layered on top of alert state.

Portfolio alert acknowledgement is not equivalent to marking a notification read, and delivery success/failure does not activate, clear, or expire the alert.

**Current implementation anchors:** `AlertPolicyService`, `AlertPolicyEvaluationService`, `AlertService`, `AlertExpirationService`, `AlertNotificationService`, and alert feature tests.

## 20. Calendar Model

Calendar events are profile-scoped user events unless they are global Admin-created trade holidays. Events carry anchor date, active state, category, title/description/color, recurrence configuration, and reminder configuration. Occurrences are expanded from the event rule for a requested date range; they are not independently owned financial records.

Trade-holiday categories and exchange-sync metadata inform market/execution scheduling but do not replace market-data calendar source-of-truth rules. Calendar events and reminders remain distinct from notification delivery records.

**Current implementation anchors:** `CalendarEvent`, `CalendarEventService`, `CalendarRecurrenceService`, `TradingCalendar`, and `CalendarEventTest`.

## 21. Recurrence

Supported recurrence types are none, daily, weekly, monthly by day, monthly by weekday, yearly by day, and yearly by weekday. Rules use an anchor, interval/configuration, and optional recurrence end date. Monthly date expansion clamps invalid days to a month end; monthly/yearly weekday forms support a configured occurrence including the final weekday form.

Occurrences are calculated as date-based calendar results within the requested range. The current model does not document a series-count termination or per-occurrence edit/delete contract; do not infer one.

**Current implementation anchors:** `CalendarEvent` recurrence constants, `CalendarRecurrenceService`, and `CalendarRecurrenceServiceTest`.

## 22. Reminders

The reminder scheduler expands active reminder-enabled events for the relevant occurrence date and configured days-before offsets. It creates an action-required calendar-reminder notification for the profile owner and records `CalendarReminderSend` by event, occurrence date, and offset. This send record prevents repeated normal scheduler runs from sending the same reminder twice.

If the event/profile/source is no longer valid, the run skips it rather than fabricating delivery. The resulting notification follows ordinary channel-selection and delivery behavior. Reminder time is currently date/offset based; detailed timezone behavior requires runtime verification.

**Current implementation anchors:** `CalendarReminderService`, `CalendarReminderSend`, `SendCalendarRemindersCommand`, and calendar reminder tests/verification.

## 23. Admin Operational Alerts

Admin operational alerts report system/process conditions such as scheduler, sync, market-data, or provider health. They have Admin audience, severity, source evidence, acknowledgement, dismissal/clear handling, and optional notification fan-out. They do not leak into Investor notification history simply because an Investor portfolio exists.

Clearing/dismissing an operational alert affects that operational-alert lifecycle, not the underlying system condition or logs. A manually cleared alert may remain hidden while its condition persists according to the operational-alert rules.

**Current implementation anchors:** `OperationalAlert`, `AdminOperationalAlertService`, `OperationalAlertController`, `AdminAlertsPage`, and `AdminOperationalAlertTest`.

## 24. Notification Data Model

`NotificationSource` represents a publishable event/condition and its severity/audience/context. `NotificationOccurrence` preserves activity snapshots. `RecipientNotification` binds source/history/attention/condition state to a user. `NotificationDelivery` is encrypted-destination channel work; `NotificationDeliveryAttempt` preserves provider evidence. `NotificationChannelSetting` and `NotificationEmailDestination` represent user channel configuration and verification.

`Alert`/`AlertPolicy` own portfolio alert state and rules. `CalendarEvent` owns event/recurrence/reminder configuration; `CalendarReminderSend` is reminder dedupe evidence. `OperationalAlert` owns global Admin operational-alert state. Legacy `TosNotification` remains compatibility history, not the preferred architecture.

## 25. API Contract

- **Notification center/history:** `/api/notification-center/*` and V1 notification read/retry endpoints.
- **Settings/channels:** `/api/notification-settings/*`, email-destination verification, channel update/test operations.
- **Portfolio alerts:** `/api/alerts*` and `/api/alert-policies/*`.
- **Calendar:** `/api/calendar/events`, `/occurrences`, and `/upcoming`.
- **Admin operational alerts:** `/api/operational-alerts/*` and related Admin sync alert actions.

Routes are authenticated and account/profile scoped unless explicitly Admin/global or capability-verification routes. The exact payload/middleware inventory belongs in `routes/api.php`; delivery attempts and settings must not expose protected destination secrets.

## 26. Services And Orchestration

`NotificationPublisher` owns source/occurrence/recipient fan-out. `NotificationDeliveryPlanner` selects verified enabled channels and creates idempotent durable work. `NotificationDeliveryProcessor` and adapter own provider attempts, terminal/retry status, and legacy compatibility sync. `NotificationChannelHealthService` owns channel health conditions. Settings/tester/migrator services own configuration, verification, test sends, and legacy Telegram migration.

`CalendarRecurrenceService` expands events, `CalendarReminderService` publishes due reminders, alert policy/evaluation/expiration services own portfolio-alert lifecycle, and `AdminOperationalAlertService` owns global operational alerts. Domain services publish meaningful events but do not delegate their state transitions to notifications.

## 27. Scheduling And Queues

`ProcessNotificationDelivery` runs durable external delivery work on the notifications queue. Calendar reminder scheduling invokes `SendCalendarRemindersCommand`; alert evaluation/notifications, Kite readiness, execution-window expiry, reconciliation/operational checks, and other domain schedules publish their own occurrence sources.

Each scheduled publisher must be idempotent: use stable source/condition keys, delivery keys, send records, and bounded retry rather than assuming the scheduler runs once. Market-hour/trade-holiday guards are owned by the relevant alert/execution job, not by generic delivery.

## 28. Critical Notification Invariants

- Notification state never becomes domain state.
- Channel failure never changes recommendation, order, broker, alert-policy, or accounting status.
- Duplicate scheduler runs must not duplicate source/recipient/delivery/reminder work.
- Delivery retry is bounded and idempotent.
- Disabled, excluded, or unverified channels are explicit non-delivery/skips, not provider failures.
- Read/dismiss never resolves an underlying domain condition.
- Domain resolution does not erase historical occurrences or attempts.
- One channel's failure cannot fabricate success/failure for another.
- History and attempts remain auditable; destinations and sensitive errors remain protected/sanitized.

## 29. Error And Recovery Semantics

| Condition | Required behavior |
| --- | --- |
| Unverified/disabled/no destination | Do not plan external delivery; retain in-app history. |
| Provider outage/timeout | Record failed attempt; requeue only when adapter marks retryable and retry budget remains. |
| Permanent provider rejection | Record attempt and terminal failure; create/update channel health when appropriate. |
| Invalid webhook/Telegram configuration | Reject/disable at settings validation or report sanitized failed test/delivery. |
| Duplicate event/condition | Deduplicate active source and delivery keys rather than send a duplicate. |
| Retry exhaustion | Preserve failed delivery/attempt evidence and health condition; no unbounded loop. |
| Reminder source changed/deleted | Skip stale reminder work; do not publish invented event state. |
| Condition resolved before send | Suppress queued delivery before network call. |

## 30. Test And Verification Anchors

| Area | Test anchor | What it proves |
| --- | --- | --- |
| Occurrence/idempotency | `app/tests/Feature/Notification/NotificationPublisherTest.php` | Condition dedupe, escalation unread behavior, resolution/history, role-safe fan-out. |
| Planning/channels/reminders | `NotificationDeliveryPlannerTest.php` | Verified-channel fan-out, info default in-app-only, escalation/reminder idempotency. |
| Attempts/retry/health | `NotificationDeliveryProcessorTest.php` | Attempt evidence, bounded retry, permanent failure, health condition/recovery, resolved suppression. |
| Settings/verification | `NotificationSettingsApiTest.php` | Mandatory in-app, verification before enablement, secret handling, webhook validation/signature, test delivery, signed email destination verification. |
| History/read UX/API | `NotificationCenterApiTest.php` and `app/tests/js/notificationCenterShell.test.mjs` | Account scope, explicit read-only attention change, counts/views, shell behavior. |
| Legacy Telegram | `LegacyTelegramChannelMigratorTest.php`, `TosNotificationMigrationTest.php` | Safe migration/ambiguity handling and legacy history/retry linkage. |
| Recommendations/capital | `Section30RecommendationNotifyTest.php`, `RecallNotificationTest.php` | Actionable/unfunded notification selection, HOLD/WATCH skips, recall/bridge events. |
| Calendar/recurrence | `CalendarEventTest.php`, `CalendarRecurrenceServiceTest.php` | Profile scoping, global trade holidays, occurrence rules. |
| Portfolio alerts | `AlertPolicyTest.php`, `AlertLifecycleOrderingTest.php`, `AlertExpirationTest.php` | Policy evaluation, active dedupe/recreation, expiry/acknowledgement and ownership. |
| Execution reminders | `KiteReadinessReminderTest.php` | Daily dedupe and eligibility conditions for automatic-portfolio readiness. |
| Admin alerts | `AdminOperationalAlertTest.php`, `SyncFailureNotificationMigrationTest.php` | Admin-only operational state, acknowledgement/clear behavior, durable Admin fan-out. |

**Test coverage gap — implementation audit follow-up:** end-to-end browser critical-banner persistence, real provider retry/backoff timing, timezone behavior of reminders, webhook production failure modes, and all execution/reconciliation notification reachability require runtime verification.

## 31. Debugging Guide

| Symptom | Likely layer |
| --- | --- |
| Notification never created | Owning domain publisher, source/condition key, recipient audience/fan-out. |
| Created but not delivered | Channel settings/verification/destination, planner, queue, delivery/attempt status. |
| Duplicate notification | Condition key, recipient uniqueness, delivery idempotency key, reminder send record, scheduler trigger. |
| Wrong channel | Channel settings, source severity/exclusions, verified destination selection. |
| Verification stuck | Signed email destination flow or Telegram/webhook test/configuration. |
| Banner persists unexpectedly | Source/recipient condition state versus read/dismiss behavior; do not treat as execution resolution. |
| Read state resets | Recipient attention state, critical escalation, center read action. |
| Reminder duplicated/missing | Recurrence expansion, event active/reminder configuration, `CalendarReminderSend`, scheduler date. |
| Webhook/Telegram failing | Adapter error/attempt evidence, sanitized health condition, settings/tester and retry status. |
| Alert acknowledged but notification remains | Alert lifecycle versus durable notification history; inspect source resolution separately. |
| Admin alert not visible | Admin audience/role, operational alert condition, Admin alert API/page, notification settings. |

## 32. Implementation Alignment Notes

The following accepted contracts require V1-V7 implementation/runtime verification and are not defect conclusions:

- Full UI accessibility, responsive behavior, filtering/grouping, banner visibility, and delivery-attempt presentation.
- Scheduler/queue deployment behavior and real provider timeout/backoff/retry behavior.
- Exact timezone interpretation and race-safe reminder dedupe under concurrent scheduler runs.
- All execution, reconciliation, and protection event publication/reachability.
- Long-term history retention, privacy/redaction, and channel-health operational behavior.

## 33. Historical Context

Early alert/calendar and Telegram features established the baseline. V3 added richer business events. V5 FEAT-004 introduced the unified durable source/recipient/delivery/attempt notification service. Later execution readiness, expiry, reconciliation, and operational sources use that architecture.

Current behavior is defined by this document and linked current-domain contracts, not earlier transport-specific narration.
