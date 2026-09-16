# V5-FEAT-037 — Dashboard-first daily Kite readiness and reconnect

Status: **FROZEN / IMPLEMENTED**
Started: 2026-09-04

## Frozen behaviour

- Only an Automatic portfolio with an unusable Kite session gets a prominent Dashboard warning.
- The warning distinguishes server configuration failure from a missing/expired user session.
- **Connect Kite** starts the existing encrypted, ten-minute login-state flow.
- A Dashboard-initiated login returns to Dashboard with `?kite=connected`; Account-initiated login continues returning to Account settings.
- Manual and Semi-Automatic dashboards are unchanged. A usable Automatic connection suppresses the warning.
- Interactive Zerodha authentication remains mandatory; StoX does not claim silent renewal.
- A portfolio-local reminder time uses the existing application timezone. Clearing it disables reminders.
- At most one Telegram reminder is sent per portfolio/day while Automatic remains unusable; usable sessions suppress it.

## Architecture and security

The encrypted Kite login state now carries an allowlisted `return_to` value (`dashboard` or `account`). The callback derives its destination only from decrypted state; arbitrary redirect URLs are never accepted. Dashboard reads the existing broker status endpoint and does not cache the session-readiness response with portfolio analytics.

## Acceptance criteria

- Automatic + unusable renders a high-visibility warning and Connect action.
- Automatic + usable, Manual, and Semi-Automatic do not render the warning.
- Dashboard login returns to the application root after successful exchange.
- Invalid state still fails closed to Account settings and never contacts Kite.
- Focused backend and frontend tests pass.
- Settings exposes the daily reminder time and persists it per portfolio.
- The every-minute scheduler sends only at the configured minute, only for Automatic portfolios, and records a successful daily send before permitting another day.
- Failed Telegram delivery is not recorded as sent, allowing a later operational retry.
