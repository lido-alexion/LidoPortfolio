# StoX User Journeys — Portfolio Settings and Delivery Channels

[Back to journey index](README.md)

Account channel settings use the current [Notifications contract](../current/notifications-calendar-alerts.md). Portfolio safeguards preserve accepted STR-07/08 and [Execution broker safety](../current/execution-broker-safety.md). Saving settings is not authorization to submit an order.

## E2E-46 — Recover Portfolio settings without overwriting unknown safeguards

- Requires: An owned active Portfolio.
- Warning: Cash reserve, minimum actionable buy, position limits, lending and recall settings affect policy/capacity; they do not move cash or submit trades. Portfolio stop-loss and trailing-stop fields retain their accepted classic Portfolio meanings; Strategy ATR policy is configured separately.

1. Open Settings → Portfolio (`/settings/portfolio`). If the current configuration cannot be read, Retry Portfolio settings before saving; failed reads must not expose default-looking editable safeguards.
2. Inspect and deliberately edit the intended fields. Leave unrelated limits, notification schedules and daily Kite-readiness reminder settings intact.
3. Save portfolio settings explicitly. A rejected/unconfirmed write retains the draft and warns to inspect existing settings before retrying.
4. Retry deliberately, then inspect the confirmed values from the refreshed configuration. If the write succeeded but refresh failed, recover the read without assuming the saved state is absent.
5. Notification channels are configured through Settings → Notifications at account scope. The separate recall-period override and execution-mode/halt controls retain their existing explicit safeguards.

**Expected result:** Unknown reads block unsafe overwrite; failed saves preserve the intended draft and policy meaning without trading activity.

## E2E-47 — Maintain verified notification channels and recipient ownership

- Requires: An authenticated account with permission to manage its own channels.
- Warning: Test channel may send a real provider message when used in production. Only execute it with an authorized destination. Automated journeys use isolated synthetic localhost responses and qualify no actual delivery. External channels require enabled, verified configuration; in-app history remains available independently.

1. Open Settings → Notifications (`/settings/notifications`). An unavailable read offers Retry and hides default-looking channel controls.
2. Inspect Telegram, Email and Webhook health. Configure the intended channel explicitly. An existing hidden Telegram bot token is preserved when its replacement field is blank.
3. Save a channel. Failure retains the entered destination/settings. While an operation is pending, other channel/recipient mutations remain disabled to avoid overlapping writes.
4. Test channel deliberately on the intended authorized destination. Failed verification is distinct from healthy/verified status; inspect and retry. Enable and Save only after the current configuration is eligible.
5. Add an additional email recipient. A failed addition retains the address; a confirmed addition remains verification pending until the signed verification flow completes. The account email is retained and cannot be removed here.
6. Remove only an optional recipient deliberately. A failed removal retains it for recovery; success preserves the account destination.
7. Webhook requires HTTPS. Correct rejected input and retry. Copy the signing secret shown once at the governed creation/test point; later configuration shows only that the secret is configured/hidden.
8. Optional product-email preferences are a separate explicit save flow (E2E-17). Failed delivery through one channel does not fabricate success through another.

**Expected result:** Channel configuration, verification, enablement and actual provider delivery remain distinct; account destination and hidden-secret boundaries are preserved.
