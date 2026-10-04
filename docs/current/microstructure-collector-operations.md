# FEAT-063 Kite quick-connect operations

The collector uses the StoX account configured by `MICROSTRUCTURE_KITE_USER_ID` (production is user ID 1). Use the bookmarkable [Kite connection page](/kite-connect) from a signed-in StoX browser session. A different StoX user cannot see collector status or request a collector login URL. The page reports the configured session separately from collector WebSocket and recent packet evidence; a stored token alone is not proof that collection is live.

At 09:00 Asia/Kolkata on a scheduled NSE trading day, StoX sends the configured collector user a Telegram reminder linking to `/kite-connect`. If the session is still missing, the existing daily bounded hourly repeats continue through market close. Weekend and exchange holiday checks suppress reminders. The collector health check sends one condition-deduplicated alert from 09:20 when a usable session exists but the WebSocket is disconnected or no packet has arrived in the previous five minutes. The five-minute grace allows the collector to start after the 09:15 open and avoids alerting on normal connection setup. Recovery resolves that alert condition.

Select **Connect Kite** to launch the existing encrypted-state Kite redirect. Enter credentials and OTP only on Zerodha's official page. StoX does not collect or send OTPs. The callback continues to bind the login to the initiating StoX user and returns quick-connect logins to `/kite-connect`; existing dashboard and account return destinations remain supported. Return destinations are a strict server allowlist.

The direct page is a small Blade document with inline styling and a small status script; it avoids booting React and loading the dashboard's larger SPA bundle. Its status and login URL still use the authenticated Sanctum session APIs. The dashboard card uses the same server status classification but is part of the existing SPA bundle.

Kite's next expiry is represented as the UTC instant equivalent of 06:00 Asia/Kolkata on the expiry date. Existing `broker_connections` rows are not migrated or rewritten; they keep their prior `expires_at` values and normal `isUsable()` behavior. Only a newly exchanged Kite session receives the corrected timestamp.

At finalization, zero partition rows produce `finalization_failed` with a diagnostic and no finalized marker or backup. A pre-existing legacy zero-row finalized partition is rejected as successful but left unchanged as evidence; investigate it manually rather than rewriting a day or inventing observations.

## Local acceptance

After deployment, verify on the next NSE trading day after the configured user logs in:

1. Open `/kite-connect` on mobile and desktop while signed in as the configured user; verify the three status signals and the Connect Kite action.
2. Verify another StoX user is denied collector status and cannot request the collector login URL.
3. Sign in through Zerodha's official flow and verify the encrypted callback returns quick-connect logins to `/kite-connect`; verify dashboard/account callers retain their existing destinations.
4. Confirm the 09:00 Telegram reminder links to `/kite-connect`; confirm reminder suppression on a weekend/holiday and bounded repeats while session is missing.
5. Confirm collector WebSocket and recent packet heartbeat become live. If not, verify the 09:20 alert and its recovery/deduplication behavior.
6. Confirm a positive-row trading day finalizes normally, and inspect any zero-row finalization failure without treating it as successful data collection.

These steps are deployment acceptance work; local tests do not establish a live next-trading-day result or close FEAT-063 REVIEW.
