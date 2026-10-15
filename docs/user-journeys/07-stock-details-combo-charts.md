# StoX User Journey — Stock Details Combo Charts

[Back to journey index](README.md)

## VIZ-01 — Compare Stock Details combo charts

**Goal:** Compare a stock's price with a StoX-defined valuation or fundamental series while preserving the source cadence and the user's account-wide chart default.

1. Open a stock in Watchlist, for example `/watchlist/TCS`.
2. Review the existing Price + Volume chart and the combo chart in the same Stock Details area. Individual metric charts elsewhere remain available.
3. Use the grouped preset dropdown to choose Price + P/E, Price + P/B, Price + P/S, Price + EPS, Price + Revenue, or Price + Net Profit. Only the selected preset is drawn.
4. If a preset is unavailable for the stock, read its disabled reason in the catalogue. Use the adjacent previous/next controls to cycle among available presets; navigation wraps at each end and stays synchronized with the dropdown.
5. Choose a time range and sampling frequency inside the chart. Fundamental observations stay on their reported dates; price sampling and volume aggregation do not invent additional fundamental observations.
6. Hover or tap the chart and compare the aligned date/period, visible series labels and formatted values. Use keyboard or touch to show/hide a legend series temporarily.
7. To make the current available preset the account-wide default, select **Set as default** and wait for the saved state. This is an account preference, not a favorite.
8. Reopen Stock Details. The explicit default opens when available for that stock. If it is unavailable, StoX uses Price + Volume, or the first available preset when Price + Volume is unavailable. Without an explicit default, StoX uses Price + Volume with the same safety fallback.

**Success condition:** One chart is visible at a time; the selector, arrows, chart and legend describe the same preset; sparse fundamentals retain their reported observation dates; changing the default is account-scoped; and the single-metric chart library remains reachable.

**Recovery path:** If preference loading fails, continue with the system fallback and retry when offered. If a history request fails, the affected preset remains visible but disabled with a reason. A failed save leaves the current chart selected and reports that the account default was not confirmed; retry only after the save control becomes available.

**Automation:** `app/tests/e2e/v9-viz001-combo-charts.spec.js` exercises desktop keyboard and mobile/touch interactions with deterministic fixture data. `app/tests/e2e/production-smoke.spec.js` opens the deployed chart under the dedicated read-only smoke identity and does not write the account preference.
