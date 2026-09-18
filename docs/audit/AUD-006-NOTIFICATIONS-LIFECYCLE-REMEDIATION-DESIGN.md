# AUD-006 - Notifications Lifecycle UX / Runtime Remediation Design

## 1. Finding Recap

AUD-006 is currently `PARTIALLY_IMPLEMENTED`. StoX has a substantial notification foundation, including in-app notification records, account-scoped recipients, external channel settings, persisted delivery attempts, bounded retry, channel health, banners, history, and reminder scheduling. The remaining evidence gap is whether those pieces form one coherent user-visible lifecycle across read state, acknowledgement, delivery failure, retry, reminders, and runtime queue/provider execution.

This audit separates:

- notification occurrence and recipient attention state;
- domain-specific acknowledgement or resolution;
- external delivery and delivery-attempt state;
- retry after delivery failure;
- reminder after a previously successful delivery while an active condition remains unresolved;
- browser/UI reachability versus queue, scheduler, provider, and credential runtime behavior.

## 2. Accepted Notification Model

The current contract and implementation use this model:

```text
domain event or condition
  -> NotificationSource
  -> NotificationOccurrence
  -> RecipientNotification
  -> NotificationDelivery
  -> NotificationDeliveryAttempt
```

`NotificationSource` is the durable communication-level representation of the event or condition. A source may be a one-time event or a deduplicated active condition. `NotificationOccurrence` preserves the activity timeline and message snapshot. `RecipientNotification` stores per-user attention and condition state. External channels are represented separately as deliveries and attempts.

The architectural invariant is explicit in `NotificationPublisher` and the current notification contract: a notification is a communication artifact about a domain event. Delivery failure does not roll back or rewrite a valid recommendation, order, transaction, accounting, broker, or execution state.

The implementation uses three different state axes:

| Axis | Current states / meaning | Source of truth |
| --- | --- | --- |
| Recipient attention | `unread`, `read` | `portfolio_recipient_notifications.attention_state` and `read_at` |
| Condition lifecycle | `active`, `resolved` for condition-backed notifications; event sources use `na` | source and recipient condition fields |
| External delivery | `queued`, `processing`, `delivered`, `suppressed`, `failed`; attempts are `succeeded` or `failed` | delivery and attempt tables |

## 3. Persistence / Source of Truth

| Concept | Model/table | Key fields | Source of truth |
| --- | --- | --- | --- |
| Notification source | `NotificationSource` / `portfolio_notification_sources` | type, condition key, audience, severity, title, message, context, primary action, condition state, occurrence count, timestamps | `NotificationPublisher` |
| Notification activity | `NotificationOccurrence` / `portfolio_notification_occurrences` | source, activity type, severity, snapshot, occurred at | publisher event/condition transitions |
| Per-user notification | `RecipientNotification` / `portfolio_recipient_notifications` | user, source, attention state, read time, condition state, resolved time, latest activity, last successful external delivery | publisher fan-out and resolution |
| External delivery | `NotificationDelivery` / `portfolio_notification_deliveries` | recipient, channel setting, channel, delivery kind, encrypted destination, idempotency key, status, availability, delivered time, suppression, last error | `NotificationDeliveryPlanner` and `NotificationDeliveryProcessor` |
| Delivery attempt | `NotificationDeliveryAttempt` / `portfolio_notification_delivery_attempts` | delivery, attempt number, status, error code, provider response status, attempted time | delivery processor |
| Channel configuration | `NotificationChannelSetting` / `portfolio_notification_channel_settings` | user, channel, encrypted configuration, enabled, verified time, health, last test, last error | `NotificationChannelSettingsService` |
| Additional email destination | `NotificationEmailDestination` / corresponding email destination table | user, email, verification, account-email flag | settings service |
| Domain acknowledgement | `Alert` or `OperationalAlert` records | acknowledged time and domain-specific expiry/clear state | alert/operational-alert services, not Notification Center |

Destinations are encrypted or deliberately hidden from presentation. Delivery idempotency uses recipient, generation, channel, and destination hashing; the delivery table has a unique idempotency key. Recipient rows are unique per source/user.

## 4. Categories / Severity

The canonical notification publisher validates these severities:

| Severity | Current behavior | In-app treatment | External delivery |
| --- | --- | --- | --- |
| `info` | informational event or condition | notification center and bell; unread until read | only when the source explicitly requests external informational delivery |
| `action_required` | user action or operational attention is needed | notification center, unread state, and relevant action/context | planned for enabled and verified configured channels |
| `critical` | persistent high-priority condition | active critical count and persistent banner while the condition remains active | planned for enabled and verified configured channels |

Observed notification types cover recommendation, execution/broker, capital/lending/recall, portfolio alert, calendar reminder, data-quality/operational, and channel-health concerns. The type is stored on the source; severity controls the general attention and external-delivery policy. Some domain-specific alert and operational-alert surfaces retain their own acknowledgement semantics.

## 5. Event Creation / Idempotency

`NotificationPublisher::publishEvent` creates a source, one occurrence, and recipient rows in a transaction, then plans initial external deliveries after commit. `publishCondition` creates or locks a source by stable active condition key, records first detection or re-detection/severity escalation, fans out recipients, and plans initial/escalation delivery. Repeated processing of a condition does not create an unbounded set of active source rows.

The legacy `NotificationEngine` bridges older recommendation/domain notifications into the newer source/delivery model. It retains legacy `TosNotification` history and an idempotency key while attaching the newer source through context. This is a compatibility path, not a second notification policy.

Source context retains source/domain identifiers where supplied, and `NotificationOccurrence.snapshot` preserves the user-visible content at the time of activity. Duplicate domain processing is controlled by condition keys, recipient uniqueness, and delivery idempotency. A full cross-domain duplicate-event proof is not established by the current UI tests and remains a targeted test/runtime assurance item.

## 6. Recipient Resolution

Recipients are resolved from explicit users passed to the publisher and filtered by the source audience (`investor`, `admin`, or `both`). `NotificationPublisher::fanOut` de-duplicates users and creates one recipient row per source/user. The notification-center API then scopes every query to the authenticated user's `user_id`.

The notification settings APIs are account/user scoped. The current tests prove that one user's notification cannot be read through another user's notification-center API and that Admin can use the same account-level center without acquiring a portfolio. Portfolio and domain ownership checks remain owned by the originating domain services; this audit does not replace AUD-012 authorization evidence.

## 7. Channel Model

The supported current channels are:

| Channel | Enabled/configured by | Provider/delivery path | Retry | UI health |
| --- | --- | --- | --- | --- |
| In-app | mandatory; cannot be disabled | notification-center API, bell, critical banner, history | not an external send | always presented healthy by settings contract |
| Email | verified account/additional destination plus channel setting | `NotificationDeliveryAdapter` and `NotificationDeliveryMail` | common bounded delivery retry | settings exposes verified state, health, last test state |
| Telegram | verified channel configuration and chat ID; legacy migration supported | adapter/provider path | common bounded delivery retry | settings exposes configuration/health and test action |
| Webhook | verified HTTPS endpoint and protected signing secret | adapter/provider path with signed delivery | common bounded delivery retry | settings exposes configured/health and test action |

Disabled, unverified, missing-destination, excluded, and unsupported channels are distinct planning conditions. In-app remains mandatory even when all external channels are disabled.

## 8. In-App Surfaces

| Surface | Route/component | Current behavior | Reachability |
| --- | --- | --- | --- |
| Header bell | `AppHeader` -> `NotificationBell` | polls the account notification center, shows unread count, opens a five-item menu, marks a selected item read before navigating | Investor and Admin authenticated shells |
| Critical banner | `CriticalNotificationBanner` mounted in `App` | reads `active_critical_count`, persists while active, links to one detail or critical history; no dismiss control | authenticated shell |
| Notification Center | `/notification-history`, `NotificationHistoryPage` | all/needs-attention/unread/critical/resolved views, detail timeline, mark one/all read, explicit loading/error/empty states | authenticated shell |
| Notification settings | `/settings/notifications`, `NotificationSettingsPage` | channel configuration, email verification destinations, test delivery, health badge | authenticated shell |
| Legacy Trading OS history | `/api/v1/notifications`, legacy engine consumers | paginated legacy notification rows and retry endpoint | API-level/legacy workflow; not rendered by the current Notification Center page |
| Domain alert surfaces | Dashboard alert table, Admin Alerts, Universe Price Sync | alert-specific acknowledgement/clear workflows | domain- and role-specific |

The current in-app surfaces are reachable. The Notification Center presents source/recipient state and occurrence timeline, but its response does not include delivery rows or attempt detail. The current history page therefore cannot independently show which external channel failed, how many attempts occurred, or whether an external channel is queued versus exhausted.

## 9. Read / Acknowledgement Semantics

These states are intentionally not synonyms:

| User action/state | Effect | Does not do |
| --- | --- | --- |
| Open bell item or detail | current UI posts `/notification-center/{id}/read` when selecting/opening from the bell/detail flow | does not resolve the condition or acknowledge a domain alert |
| Mark read | sets `RecipientNotification.attention_state=read` and `read_at`; idempotent | does not change external delivery or condition state |
| Mark all read | updates unread recipient rows for the authenticated account | does not resolve active conditions or acknowledge domain alerts |
| Acknowledge portfolio alert | domain-specific `Alert` acknowledgement and expiry behavior | does not mean external delivery succeeded |
| Acknowledge Admin operational alert | persists `OperationalAlert.acknowledged_at` and affects that operational surface | does not mark a Notification Center recipient read unless separately performed |
| Dismiss critical banner | not offered by the current critical banner; active condition remains visible | does not resolve the underlying condition |
| Resolve condition | publisher clears active condition and updates recipient condition state while retaining history | does not rewrite prior read state or erase occurrences |

This separation is supported by `NotificationCenterApiTest`, which proves read changes attention only while `condition_state` remains active. It is also consistent with the current contract that critical presentation persists until the source condition is resolved.

## 10. Notification History

The Notification Center history is durable and account-scoped. It exposes source title/message, severity, notification type, condition state, occurrence count, first/latest detection timestamps, primary action/context, and an occurrence timeline. It supports server-side view, severity, type, portfolio, and pagination parameters.

The history does not currently expose:

- per-channel delivery rows;
- delivery status (`queued`, `delivered`, `suppressed`, `failed`);
- attempt count, response status, or provider error code;
- retry action for the new source/delivery model;
- explicit external-channel skipped/unconfigured reason.

Those omissions do not erase the notification itself, but they make external delivery recovery and channel-specific diagnosis unavailable from the main Investor history surface.

## 11. Delivery / Attempt Lifecycle

`NotificationDeliveryPlanner` creates one delivery per eligible recipient/channel/destination after commit. It excludes channels from source context, requires enabled and verified settings, handles configured destinations, and uses a generation plus destination hash for idempotency.

`ProcessNotificationDelivery` dispatches the delivery on the `notifications` queue. `NotificationDeliveryProcessor` locks the delivery, prevents duplicate terminal work, suppresses a delivery when an active condition has resolved, sends through the adapter, and records a `NotificationDeliveryAttempt` with success/failure, provider response status, error code, and attempt number.

Current delivery statuses observed in code are:

```text
queued -> processing -> delivered
                    -> queued (retryable failure, attempt < 3)
                    -> failed (terminal failure/exhausted)
queued -> suppressed (condition resolved before send)
```

On success the recipient records `last_successful_external_delivery_at`; on terminal failure channel health becomes unhealthy and a channel-health condition is published, excluding the failing channel to prevent recursive notification failure.

## 12. Provider Failure Semantics

Provider failures are stored on the attempt (`error_code`, optional response status) and summarized on the delivery (`last_error_code`). Secrets and destinations are not exposed in model serialization. Retryable versus terminal behavior is returned by the adapter result and enforced by the processor/queue path.

The system correctly distinguishes:

- a notification existing in-app from an external channel succeeding;
- a disabled or unverified channel from a failed attempt;
- a channel failure from a valid domain failure;
- a terminal failed delivery from a missing notification.

The main Notification Center UI does not currently surface these delivery details. Channel settings does surface health and test results, and the channel-health notification informs the account that configuration or channel attention is needed.

## 13. Retry

Automatic delivery retry is bounded at fewer than three attempts. `ProcessNotificationDelivery` uses queue attempts and backoff values of 60 and 300 seconds; retryable processor results are thrown back to the queue. Terminal failures store the error and update channel health.

There are two manual retry paths:

1. `NotificationDeliveryPlanner::requeueFailedForSourceChannel` can requeue failed source/channel deliveries.
2. The legacy `/api/v1/notifications/{id}/retry` endpoint calls `NotificationEngine::retry` for a portfolio-scoped `TosNotification` and bridges it to the newer source/delivery model.

The current Notification Center page does not offer a visible retry action or expose the delivery row needed to choose a channel. The legacy retry endpoint is therefore API-reachable but not proven as part of the current Investor history workflow. Repeated delivery planning is protected by idempotency keys and terminal-state checks.

## 14. Reminder / Re-notification

Reminder is separate from retry.

- Retry follows a failed or retryable delivery attempt and uses delivery attempt count/backoff.
- Reminder follows a prior successful external delivery when an `action_required` or `critical` condition remains active for 48 hours.

`NotificationReminderService::queueDue` calls `NotificationDeliveryPlanner::planReminder`, which uses a generation based on the last successful external delivery timestamp. This prevents a reminder from being treated as a failed-send retry. The scheduler registers `portfolio:queue-notification-reminders` hourly. Calendar reminders are a separate scheduled occurrence/deduplication path using event, occurrence date, and offset identity.

The current source/UI contract does not provide a user-facing reminder history label distinct from the occurrence activity timeline. The underlying activity and delivery generation are persisted, but browser-visible reminder behavior requires runtime scheduler verification.

## 15. Settings / Channel Health

`/api/notification-settings` and `NotificationSettingsPage` expose account-level external channel settings. The settings surface supports:

- in-app mandatory status;
- Telegram configuration and test;
- email account/additional destination verification and removal;
- webhook HTTPS configuration and test;
- enabled/disabled state;
- health status;
- last test state/time where returned.

Material configuration changes clear verification, mark the channel unverified, and disable it until a successful test/verification. Disabling a channel recovers its channel-health condition. A terminal delivery failure publishes an action-required channel-health condition and failing channels are excluded from recursive external delivery.

The settings page uses a toast on initial-load failure rather than an inline error state and does not show historical last failure detail beyond the current health/status payload. This is a presentation limitation, not evidence that provider failure is silently treated as success.

Settings are evaluated when delivery planning occurs. A pending delivery already queued may still be processed using its persisted destination/channel setting; the exact behavior of changing settings between planning and worker execution requires runtime/queue verification.

## 16. Source Links / Context

Sources carry structured `context` and optional `primary_action` route data. The current Notification Center API returns both, allowing domain producers to supply safe context and an action route. The main history page currently renders context text/timeline but does not render a generic primary-action link from the returned `primary_action` field.

The bell navigates to Notification Center detail by notification ID, which is account-scoped. Missing or inaccessible source objects do not erase a recipient row because the recipient/source relationship is durable; however, route/action reachability after the source domain object changes is not proven statically and should be checked in the browser.

No raw provider credentials or encrypted destinations are exposed through the notification summary API.

## 17. Error / Loading / Empty States

Notification History has explicit `DataState` branches for loading, request failure with Retry, and successful empty result. This avoids the failure-to-empty collapse for the main history page.

The bell provider intentionally swallows refresh failures so shell rendering is not blocked. Its initial `meta` is `{ unread_count: 0, active_critical_count: 0 }`, which can temporarily present zero before the first response and does not expose a loading/error indicator. This is a static missing-versus-zero concern for unread and critical counts.

Notification Settings uses a loading text state but converts initial load failure into a toast and then renders the page with its initial/default shape. Channel-level test and save failures preserve server messages in toasts. Critical banner uses explicit `role="alert"` and only appears for an active critical count.

The principal confirmed state-semantics risks are therefore:

- unread/critical count is initialized to zero before the first successful fetch;
- external delivery failure is persisted but not visible in the current Notification Center history;
- settings-load failure has no durable inline unavailable state.

## 18. Concurrency / Idempotency

The source/recipient/delivery implementation contains several concrete controls:

- source row locking for condition publication/resolution;
- unique active condition key;
- unique source/user recipient;
- unique delivery idempotency key;
- unique delivery/attempt number;
- delivery row lock and terminal-state checks;
- queue uniqueness by delivery ID;
- bounded processor/queue attempts;
- post-commit dispatch for delivery jobs.

Read operations are idempotent. Mark-all-read is an account-scoped bulk update. The current tests cover recipient isolation and read semantics, while a complete duplicate-event plus duplicate-retry integration scenario is not represented in the inspected frontend shell tests. That is an assurance gap rather than proof of a race defect.

## 19. Queue / Scheduler / Runtime Boundaries

The following require runtime infrastructure beyond static browser code:

| Capability | Runtime dependency | Static evidence |
| --- | --- | --- |
| External delivery | queue worker on `notifications`, provider network, valid credentials/destinations | job, planner, processor, adapter, and processor tests exist |
| Automatic retry | queue worker retry/backoff | `ProcessNotificationDelivery` and processor behavior |
| 48-hour condition reminder | Laravel scheduler plus queue/provider | console command and hourly schedule |
| Calendar reminder | scheduled calendar reminder command and provider path | schedule/command code and calendar notification services |
| Channel recovery | later successful provider send or settings disable | channel health service and processor/settings code |
| Real email/Telegram/webhook delivery | deployed provider configuration and external service response | adapter/provider implementation; not proven by repository-only tests |

Queue, scheduler, credentials, provider response, deployed stacking, and actual delivery latency are runtime verification items. They should not be classified as missing implementation solely because they cannot be proven in static tests.

## 20. Test Coverage

| Requirement | Existing evidence | What it proves | Missing coverage |
| --- | --- | --- | --- |
| Source/event publication | `NotificationPublisherTest` | source, occurrence, recipient, audience/severity behavior | representative cross-domain duplicate-event journey |
| Recipient isolation | `NotificationCenterApiTest` | account-scoped list/detail/read behavior | broader role/domain matrix belongs with authorization audit |
| Read semantics | `NotificationCenterApiTest` | read changes attention only; active condition remains active | browser read/banner continuity |
| History views/counts | `NotificationCenterApiTest` and notification shell source tests | views, counts, routes, loading/error/empty source contract | pagination/read-count behavior in a real browser |
| Settings | `NotificationSettingsApiTest`, `notificationCenterShell.test.mjs` | config, verification, test-channel surface | deployed provider credentials |
| Delivery success/failure | `NotificationDeliveryProcessorTest`, planner tests | attempts, retryable/terminal handling, health transitions | full event-to-history UI journey |
| Reminder | reminder service tests/command and scheduler code | due-condition selection and separate reminder generation | scheduler/worker elapsed-time runtime proof |
| Legacy retry | legacy notification controller/engine tests and code | profile-scoped retry bridge | current Notification Center retry reachability |
| Critical banner | `notificationCenterShell.test.mjs` and source | active critical count and no dismiss control | visual persistence and multi-surface runtime behavior |

The test inventory supports a strong domain/service foundation but not a single deterministic end-to-end sequence from event through failed external attempt, retry success, read, and acknowledgement in the current UI.

## 21. Representative Lifecycle Scenario

The recommended deterministic assurance scenario is:

1. Publish a domain event or active condition for a test user through `NotificationPublisher`.
2. Assert one source, occurrence, recipient, and unread in-app record.
3. Configure a fake verified external channel and assert one queued delivery with a stable idempotency key.
4. Process the delivery with a fake retryable provider failure; assert a failed attempt, queued retry state, unchanged in-app recipient, and no mutation to the originating domain event.
5. Process the retry with a fake success; assert a second successful attempt, delivered delivery, recipient successful-external-delivery timestamp, and recovered channel health.
6. Load Notification Center history and assert the source/occurrence remains present independent of external delivery state.
7. Mark the recipient read; assert attention becomes read while condition state remains active.
8. Resolve the condition; assert the active critical presentation disappears on the next refresh while historical source/occurrence evidence remains.
9. For an action-required/critical condition with a prior successful external delivery, advance time beyond 48 hours and run `portfolio:queue-notification-reminders`; assert a new reminder delivery generation rather than a retry attempt on the old delivery.

This scenario should use a fake adapter/provider and queue assertions, not real Telegram, email, or webhook credentials. It is the smallest end-to-end proof needed to close the current lifecycle assurance gap.

## 22. UI Reachability Map

| User goal | Route/component | API/service | Status |
| --- | --- | --- | --- |
| See unread notification | Header bell | `GET /notification-center` | `UI_REACHABLE` |
| Open notification detail | Bell/history | `GET /notification-center/{id}` | `UI_REACHABLE` |
| Mark one/all read | Bell/history | POST read endpoints | `UI_REACHABLE` |
| See active critical condition | Global critical banner | notification-center meta + active source | `UI_REACHABLE` |
| Resolve a domain condition | Domain workflow/service | `NotificationPublisher::resolveCondition` or domain service | `API_ONLY` / domain-specific; not a generic Notification Center action |
| Acknowledge portfolio alert | Dashboard alert surface | `/alerts/{id}/acknowledge` | `UI_REACHABLE` for alert category |
| Acknowledge Admin operational alert | Admin Alerts / sync surface | operational-alert acknowledge endpoints | `UI_REACHABLE` for Admin category |
| Configure email/Telegram/webhook | Notification Settings | notification-settings APIs | `UI_REACHABLE` |
| Test external channel | Notification Settings | channel test endpoints | `UI_REACHABLE` |
| Inspect channel health | Settings badge / channel-health notification | settings + `NotificationChannelHealthService` | `UI_REACHABLE`, detail limited |
| Inspect failed channel attempt | No current Notification Center delivery view | delivery/attempt tables | `API_ONLY` / internal persistence |
| Manually retry current source delivery | Planner/service; legacy retry controller for old notification | source/channel requeue or `/api/v1/notifications/{id}/retry` | `API_ONLY` / legacy reachability |
| Receive automatic external delivery | queue worker/provider | planner, job, adapter | `RUNTIME_VERIFICATION_REQUIRED` |
| Receive unresolved-condition reminder | scheduler + queue/provider | reminder service/command | `RUNTIME_VERIFICATION_REQUIRED` |
| View calendar reminder occurrence | Notification Center source history | calendar reminder publisher | `UI_REACHABLE` after occurrence; scheduler delivery runtime-only |

## 23. Gap Register

| ID | Finding | Classification | Severity | Confidence | User impact / evidence |
| --- | --- | --- | --- | --- | --- |
| NTF-001 | Notification Center history does not expose per-channel delivery status, attempt count, provider response/error, or skipped/unconfigured reason. | `PARTIALLY_IMPLEMENTED` | Medium | High | Users can see the notification but cannot diagnose whether Telegram/email/webhook delivered or failed from the main history workflow. Delivery/attempt persistence exists. |
| NTF-002 | Current Notification Center has no visible manual retry action for the source/delivery model; retry support is planner/API/legacy-controller based. | `PARTIALLY_IMPLEMENTED` | Medium | High | A user-facing recovery path is not proven for a failed current delivery. The legacy retry endpoint is not wired to `NotificationHistoryPage`. |
| NTF-003 | Bell unread and active-critical counts initialize to zero before the first successful fetch and refresh failures are swallowed. | `PARTIALLY_IMPLEMENTED` | Medium | High | A slow or failed refresh can look like a valid zero-count state. This is a static missing-versus-zero concern, though it does not delete notifications. |
| NTF-004 | Notification settings initial-load failure becomes a toast plus default rendering rather than an explicit unavailable settings state. | `PARTIALLY_IMPLEMENTED` | Low | High | Users may see incomplete/default channel controls after settings retrieval fails. Mutation errors preserve useful server messages. |
| NTF-005 | Reminder and provider delivery require queue/scheduler/provider runtime proof; repository tests do not prove deployed delivery, elapsed-time reminders, or real credential behavior. | `RUNTIME_VERIFICATION_REQUIRED` | Medium | High | External delivery may be unavailable operationally despite correct static implementation. |
| NTF-006 | A single current UI journey from event -> failed attempt -> retry success -> read -> condition resolution is not represented by an integrated fake-provider test. | `RUNTIME_VERIFICATION_REQUIRED` | Medium | Medium | The separate service tests are strong but do not prove lifecycle composition and cross-surface continuity. |
| NTF-007 | The main history detail does not render `primary_action` as a generic source-context link, although structured action data is returned. | `PARTIALLY_IMPLEMENTED` | Low | High | Domain context may be visible only as text/timeline, reducing direct recovery/navigation. The source route remains available to producers. |
| NTF-008 | Reminder occurrences are persisted through the common occurrence/delivery path but are not explicitly labeled as reminders in the current history presentation. | `PARTIALLY_IMPLEMENTED` | Low | Medium | Users may not distinguish re-notification from an original occurrence; underlying generation semantics remain distinct. |

No confirmed High-severity static defect was found. In particular, the inspected code does not conflate read with acknowledgement, external failure with notification absence, retry with reminder, or domain failure with delivery failure. The highest-risk remaining concern is the medium-severity lack of delivery failure/recovery visibility in the main history workflow, followed by the bell's zero-before-load semantics.

## 24. Remediation Groups

### A - In-app state / read / acknowledgement

- Preserve the existing separate axes.
- Add explicit loading/unavailable state to the bell provider if product behavior permits.
- Keep alert acknowledgement and Notification Center read semantics separate.

### B - History / delivery visibility

- Add a bounded, authorization-safe delivery summary to Notification Center detail/history, without exposing destinations or secrets.
- Show channel, status, attempt count, terminal error class, and skipped/unconfigured state where authoritative.
- Keep the source notification visible when an external channel fails.

### C - Retry / reminder

- Decide whether current Investor history should expose a channel-specific retry action.
- If exposed, call the existing planner/service and preserve idempotency; do not create a second retry state machine.
- Label reminder activity separately from failed-delivery retry where useful.

### D - Channel configuration / health

- Preserve settings verification and channel-health condition behavior.
- Consider an inline unavailable state for settings load failure and a last-failure summary where the existing API already supplies it.

### E - Error / unavailable states

- Prevent initial zero-count presentation from being interpreted as loaded zero.
- Keep the explicit history `DataState` pattern.
- Distinguish disabled/unverified/skipped from failed delivery in any future history presentation.

### F - Runtime provider / scheduler verification

- Run fake-provider queue tests for the full representative journey.
- Verify queue worker, scheduler, provider credentials, retry backoff, and 48-hour reminder operation in a deployed-like environment.

## 25. Recommended Order

1. Add the integrated fake-provider lifecycle assurance scenario, including retry success, read, resolution, and reminder generation.
2. Decide and implement the smallest delivery-summary contract for Notification Center detail, keeping destinations and provider secrets private.
3. Add a user-facing retry path only if the accepted Investor workflow requires it; reuse existing planner/idempotency behavior.
4. Correct bell loading/count semantics so not-yet-loaded is not rendered as authoritative zero.
5. Give Notification Settings an explicit unavailable state on initial-load failure.
6. Optionally expose safe source primary-action links and reminder activity labels.
7. Verify queue, scheduler, provider, and credentials in runtime environments, then reassess AUD-006.

## 26. Final AUD-006 Assessment

`PARTIALLY_IMPLEMENTED` with `RUNTIME_VERIFICATION_REQUIRED` boundaries.

The core notification model, persistence separation, account scoping, read semantics, critical-condition behavior, external channels, bounded retry, channel health, settings, and reminder-versus-retry distinction are implemented in the current codebase. Notification history also has explicit loading, failure, and empty handling.

AUD-006 is not yet statically complete because the current main history surface does not expose delivery/attempt failure state or a direct current-model recovery path, the bell has a zero-before-load ambiguity, and the integrated event-to-delivery-to-read/resolve assurance journey is not present. Queue workers, scheduler execution, provider credentials, and actual external delivery remain runtime verification items rather than static implementation failures.

## 27. Open Questions

1. Should Investor Notification Center history expose channel-level delivery status and a manual retry action, or is the existing settings/channel-health surface the accepted recovery boundary?
2. Should reminder occurrences be explicitly labeled in history, or is the occurrence timeline plus activity type sufficient?
3. Should `primary_action` routes be rendered generically by Notification Center, or should each domain surface own its action link?
4. Is the legacy `/api/v1/notifications/{id}/retry` endpoint still an accepted Investor workflow, or should it be retired after a current-model recovery path is chosen?
5. What deployed queue/provider environment is authoritative for runtime sign-off of Telegram, email, webhook, and scheduled reminders?

## Batch 1 Implementation Outcome

Batch 1 added `Tests\Feature\Notification\NotificationLifecycleAssuranceTest` as a product-contract assurance suite. It exercises the current production path rather than constructing final states directly:

```text
NotificationPublisher
  -> NotificationDeliveryPlanner
  -> NotificationDeliveryProcessor
  -> Notification Center API
  -> NotificationReminderService
```

The suite uses an enabled and verified Telegram channel with Laravel's HTTP fake provider. It covers five deterministic scenarios:

1. An active condition creates one source, occurrence, unread recipient, and independent queued delivery; a retryable provider failure persists attempt one; the same delivery succeeds on attempt two; a terminal re-process does not send again; the recipient can be read; the condition can be resolved without deleting source, occurrence, delivery, or attempts.
2. A successful external delivery does not queue a reminder before 48 hours, queues a distinct `reminder` delivery generation after the threshold, and is idempotent when the reminder service is run again.
3. A resolved queued condition is suppressed before provider send, while a disabled channel is excluded during planning rather than represented as a failed provider attempt.
4. Telegram failure and webhook success remain channel-specific delivery outcomes under one source; the source remains active and is not globally marked failed.
5. Repeated condition publication and repeated initial planning preserve one source/recipient/delivery through condition and delivery idempotency rules.

The assurance suite verifies the following state separations: unread versus read, active versus resolved, queued/retryable versus delivered, delivery attempt versus reminder generation, suppressed versus failed, disabled versus failed, and one channel failure versus a sibling channel success. It also verifies the Notification Center API continues to return the source timeline after provider failure and after successful retry.

No product defect was found and no production notification class was changed. Existing notification tests remain green: the new suite passes 5 tests and 55 assertions; the full notification feature directory passes 50 tests and 244 assertions.

`NTF-006` is now `IMPLEMENTED` for the current-model composition assurance scope. `NTF-005` is split: static/runtime composition assurance is covered for the fake-provider path, while deployment runtime verification remains required for the queue worker, scheduler, provider credentials, real Telegram/email/webhook responses, and elapsed-time reminder operation. AUD-006 remains `PARTIALLY_IMPLEMENTED`; NTF-001 through NTF-004 and NTF-007/NTF-008 remain outside this batch's scope.

## Batch 2 Implementation Outcome

Batch 2 adds bounded delivery visibility and current-model retry support without changing notification publication, condition, reminder, or provider architecture.

### Delivery detail contract

`GET /api/notification-center/{notification}` now includes a `deliveries` array only on the detail response. Each entry contains:

```text
id
channel
delivery_kind
status
attempt_count
last_error_code
last_response_status
delivered_at
retryable
```

The API loads attempts only for the selected detail record. It does not expose encrypted destinations, email addresses, chat IDs, bot tokens, webhook URLs, signing secrets, verification tokens, raw provider response bodies, or stack traces. Delivery state remains per channel; a failed Telegram row and delivered webhook row are presented independently and do not produce a global notification failure state. Suppressed deliveries are labeled as intentionally not sent after condition resolution rather than as failures.

### Retry contract

The current-model endpoint is:

```text
POST /api/notification-center/{notification}/deliveries/{delivery}/retry
```

The controller first scopes the recipient notification to the authenticated user, then scopes the delivery to that recipient. A retry is accepted only when the recipient condition is still active and the delivery status is `failed`. Delivered, queued, processing, and suppressed deliveries return a bounded `422` response; foreign notification/delivery identifiers return `404` through the account-scoped lookup. The retry operation calls `NotificationDeliveryPlanner::requeueFailedDelivery`, clears only the terminal error marker, queues the same delivery row, and dispatches the existing `ProcessNotificationDelivery` job. It does not create a new delivery generation or reset attempt history. Repeated clicks cannot create duplicate sendable rows.

### Notification Center presentation

Notification detail now contains a compact Delivery section with channel, delivery kind, status, attempt count, safe error code/HTTP status, delivery timestamp, and a keyboard-accessible Retry button only for authoritative eligible failures. Accepted retry shows `Delivery queued for retry`; it does not claim provider success. Refreshing detail after worker processing shows the resulting delivered state while retaining the attempt count and failure evidence.

### Evidence and disposition

The new `NotificationCenterDeliveryApiTest` covers safe multi-channel detail serialization, secret/destination exclusion, owner authorization, foreign-resource isolation, failed-to-queued retry, repeated retry rejection, delivered ineligibility, and suppressed ineligibility. The existing `NotificationLifecycleAssuranceTest` remains green, preserving automatic retry, reminder, suppression, multi-channel, read/resolution, and idempotency assurance. `notificationCenterShell.test.mjs` covers the delivery section and current-model retry wiring.

`NTF-001` is now `IMPLEMENTED`: detail exposes safe per-channel status, delivery kind, attempt count, failure evidence, suppression, and timestamps without sensitive fields. `NTF-002` is now `IMPLEMENTED`: an authenticated owner can retry an eligible failed current-model delivery through the existing planner/job path, while non-failed and resolved/suppressed states cannot create duplicate work.

AUD-006 remains `PARTIALLY_IMPLEMENTED`. Remaining items are NTF-003 (bell count loading semantics), NTF-004 (settings unavailable state), NTF-005 deployment runtime verification, NTF-007 (generic primary-action presentation), and NTF-008 (explicit reminder labeling).

## Batch 3 Implementation Outcome

Batch 3 corrects notification state semantics without changing notification domain behavior or Batch 2 delivery/retry behavior.

### Bell and critical banner

`NotificationProvider` now starts with `meta = null`, `loading = true`, and an explicit `error` value. A successful response installs the returned metadata, including authoritative zero counts. A failed initial request leaves metadata unknown; a failed later poll preserves the last successful metadata rather than replacing it with zero. The existing 60-second polling interval is unchanged.

`NotificationBell` renders no numeric badge until metadata is known, labels the initial/failure state as loading or count unavailable, and remains a usable navigation control. `CriticalNotificationBanner` renders only when `active_critical_count` is known and positive, so it does not assert a false zero during initial loading. If a known critical count exists and a later refresh fails, the last-known banner remains visible. A later successful zero response removes it normally.

### Notification Settings

`NotificationSettingsPage` now tracks initial settings load failure separately from loading and successful configuration. While loading, editable channel cards are not rendered. When either the channel settings or email-destination request fails, the page renders an inline accessible `DataState` error with Retry and does not expose Save, Test, Enable, Disable, or default-looking channel controls. A successful retry replaces the unavailable state with the authoritative settings response. Existing mutation error messages and channel verification behavior are unchanged.

### Evidence and disposition

The notification shell source tests now cover nullable initial metadata, unavailable count labeling, critical-banner unknown-state handling, retained-provider error state, and the settings unavailable/Retry branch. The focused shell suite passes 9 tests; the broader Node source suite passes 165 tests. PHP notification and API behavior remains unchanged and Batch 1/2 assurance suites remain green.

`NTF-003` is now `IMPLEMENTED`: unknown counts are distinct from loaded zero, initial failure does not create a false zero, known counts/banner state survive transient refresh failure, and successful recovery replaces stale metadata. `NTF-004` is now `IMPLEMENTED`: settings failures render persistent unavailable state with Retry and cannot expose mutations against placeholder defaults.

AUD-006 remains `PARTIALLY_IMPLEMENTED`. Remaining work is NTF-005 deployed runtime verification, NTF-007 generic primary-action navigation, and NTF-008 explicit reminder labeling. No master audit verdict was changed in this batch.

## Batch 4 Implementation Outcome

Batch 4 completes the two remaining static Notification Center presentation contracts without changing publisher, retry, reminder threshold, scheduler, or delivery semantics.

### Primary action contract

Current producers store `primary_action` as an object with `label` and `route`. The inspected producers use internal SPA destinations, including `/dashboard`, `/screeners`, `/recommendations`, `/calendar`, `/settings/universe-price-sync`, and `/settings/admin-alerts`. The API already serialized this authoritative field; no new field or producer behavior was added.

Notification History detail now renders the producer label as a React Router `Link` only when the action is a non-empty object with a non-empty label and a single-slash local route. Protocol routes and protocol-relative routes are rejected. Missing or malformed actions render no link and do not affect notification detail, timeline, read state, resolution, or delivery state. Destination authorization remains owned by the target route/API.

### Reminder semantics

The existing `delivery_kind` values remain authoritative. The detail Delivery section now renders `Initial`, `Escalation`, and `Reminder` labels. Attempt count changes do not alter the delivery-kind label, so retries remain attempts on the same delivery while a reminder remains a separate delivery generation. Suppressed, queued, processing, delivered, and failed statuses continue to use the existing per-delivery semantics; a failed reminder remains eligible for the existing specific-delivery retry path and is not re-planned as a new reminder.

### Evidence and final disposition

`NotificationCenterDeliveryApiTest` now verifies authoritative primary-action serialization. `notificationCenterShell.test.mjs` verifies safe local action filtering and initial/escalation/reminder labeling. Existing Batch 1 lifecycle assurance, Batch 2 delivery/retry API tests, Batch 3 bell/settings tests, planner/processor/reminder tests, and the notification PHP suite remain the regression set.

`NTF-007` is now `IMPLEMENTED`: valid producer actions render safely, absent/malformed actions do not render unsafe links, and action navigation does not introduce acknowledgement or resolution behavior. `NTF-008` is now `IMPLEMENTED`: reminder generations are visibly distinct from initial/escalation deliveries, and retry attempts do not become reminder generations.

AUD-006 is now statically `IMPLEMENTED`. `NTF-005` remains a runtime verification boundary for the deployed queue worker, scheduler, credentials, real Telegram/email/webhook providers, provider/network failures, elapsed-time reminders, and browser runtime behavior. No further static Notification Center remediation is required by this audit.
