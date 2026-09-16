# Notifications, Calendar, And Alerts

## Current Behaviour

StoX has three related communication systems:

- user/business notifications for recommendation, execution, recall, alert, and operational contexts;
- alert policies and portfolio alerts for user-defined or system-triggered conditions;
- admin operational alerts for infrastructure/data/process issues.

Notification channels include Telegram legacy support, email destinations with signed verification, webhooks, in-app notification center records, delivery attempts, retries, health checks, reminders, and channel settings.

The calendar supports user-defined events, recurring occurrences, upcoming dashboard events, trade-holiday categories, exchange-synced holiday metadata, and scheduled reminders. Holiday-aware scheduled execution relies on this calendar model and market sync state.

Alerts can be acknowledged, expired, cleared, dismissed, evaluated from policies, and rendered with contextual message templates.

## Technical Contract

Key API routes:

- Notification center: `/api/notification-center`, mark/read/show routes
- Notification settings: `/api/notification-settings`, email destinations, channel update/test, signed email verification
- Trading OS notifications: `/api/v1/notifications`, `/api/v1/notifications/{id}/retry`
- Calendar: `/api/calendar/events`, `/api/calendar/occurrences`, `/api/calendar/upcoming`
- Alerts: `/api/alerts`, `/api/alerts/expire-all`, `/api/alerts/{alert}/acknowledge`
- Alert policies: `/api/alert-policies/*`
- Admin operational alerts: `/api/operational-alerts/*`

Primary models include `Alert`, `AlertPolicy`, `OperationalAlert`, `CalendarEvent`, `CalendarReminderSend`, `NotificationSource`, `NotificationOccurrence`, `NotificationDelivery`, `NotificationDeliveryAttempt`, `NotificationChannelSetting`, `NotificationEmailDestination`, `RecipientNotification`, and `TosNotification`.

Primary services include `AlertService`, `AlertExpirationService`, `AlertNotificationService`, `AlertPolicyService`, `AlertPolicyEvaluationService`, `AlertMessageRenderer`, `FormulaEvaluator`, `CalendarEventService`, `CalendarRecurrenceService`, `CalendarReminderService`, `NotificationPublisher`, `NotificationDeliveryPlanner`, `NotificationDeliveryProcessor`, `NotificationMessageComposer`, `NotificationReminderService`, `NotificationChannelSettingsService`, `NotificationChannelTester`, `NotificationChannelHealthService`, `LegacyTelegramChannelMigrator`, `TelegramNotificationService`, `AdminOperationalAlertService`, `IndiaVixAlertService`, and `KiteReadinessReminderService`.

Console commands include notification delivery processing, calendar reminders, operational alert checks, decision pipeline scheduling, due screener runs, recommendation execution-window expiry, and Kite readiness reminders.

## Data Rules

- Channel settings and delivery attempts must preserve enough detail to debug why a notification did or did not send.
- Email destinations require signed verification before use.
- Retry should create auditable attempts rather than mutating history invisibly.
- Calendar recurrence/reminder dedupe must prevent repeated sends for the same occurrence.
- Exchange holidays are market-data inputs and execution scheduling constraints, not cosmetic calendar events.

## Debugging Sources

- Missing notification: check source occurrence, recipient, channel settings, verified destination, delivery planner, delivery attempts, channel health, and retry path.
- Duplicate reminder: check recurrence expansion, reminder send records, and calendar event IDs.
- Alert not firing: inspect policy definition, formula fields, holding/market data dependencies, expiration state, and acknowledgement state.

## Related Docs

- [Execution, Broker, And Safety](./execution-broker-safety.md)
- [Strategy And Recommendations](./strategy-and-recommendations.md)
- [Market Data And Data Quality](./market-data-and-data-quality.md)
- [Administration, Security, And API](./administration-security-api.md)

## Historical Context

Telegram began as the main notification channel. Current notification architecture is channel-agnostic and persists delivery state, while legacy Telegram settings/migration remain for compatibility.

