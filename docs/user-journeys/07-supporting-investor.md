# Supporting investor journeys

These existing investor flows were discovered during the V9-UX-001 completeness review. They are included by the frozen specification's account settings, watchlist/stock-detail and Kite scope. This chapter describes current behavior; it does not authorize live broker actions from tests.

## E2E-09 — Research a stock and maintain a watchlist

**Goal:** Review a stock before deciding whether to use it in investment policy.

- Requires: An authenticated investor and an existing private watchlist.

1. Open `/watchlist` and confirm the active watchlist.
2. Search for a symbol or company and select the intended security and exchange.
3. Inspect the stock's price-history availability and Fundamentals evidence. Missing history remains unavailable.
4. Add the stock to the active watchlist. Confirm it appears in that list.
5. Refresh and confirm the membership remains visible.
6. Remove the stock from the watchlist when it is no longer being researched.
7. If adding fails, read the failure, retry explicitly, and confirm only one membership exists.

**Expected result:** Research and watchlist membership change without creating a recommendation, transaction or broker order. Removing a watchlist item does not sell a holding.

## E2E-10 — Recover a Kite connection and disconnect safely

**Goal:** Understand account connection readiness independently of portfolio execution mode.

- Requires: An authenticated investor. Real connection recovery requires login on Kite itself.
- Warning: Broker login credentials and OTPs belong on Kite/Zerodha. CI uses deterministic responses and never contacts Kite.

1. Open `/settings/account` and inspect Zerodha / Kite.
2. Distinguish Not connected, Session expired and Connected. Manual mode works without a broker connection.
3. Choose Connect Kite. Complete account login only on the provider's page, then return to StoX.
4. If the connection cannot be started, read the error and retry explicitly.
5. Choose Disconnect when the account should no longer be connected and confirm Not connected.

**Expected result:** The connection status reflects the account session. No order is submitted, altered or cancelled by this journey. The deterministic browser test stops at a local simulated provider handoff; a current real read-only Kite boundary check remains separate acceptance evidence.

## E2E-11 — Save portfolio safeguards independently of execution

**Goal:** Set stop-loss and trailing percentages for the active portfolio.

- Requires: An authenticated investor with an active portfolio.

1. Open `/settings/portfolio` and confirm the intended active portfolio.
2. Inspect the current Portfolio Stop-Loss % and Portfolio Trailing Stop %.
3. Set the two values independently and choose Save portfolio settings.
4. Confirm the save feedback, refresh, and verify the saved values.
5. If a save fails, keep the intended values available and retry explicitly before relying on them.

**Expected result:** Only the active portfolio's settings change. Saving does not run the decision pipeline, change execution mode, or submit/cancel an order. Strategy ATR controls remain in the Strategy editor under the existing approved contract.


## E2E-12 — Filter notification history and manage attention

**Goal:** Read product activity without confusing attention state with investment decisions.

1. Open `/notification-history`.
2. Search for the relevant event and apply the filters.
3. Inspect the matching item. Mark it unread if attention is still needed, or read after review.
4. Refresh and confirm attention state is retained.
5. If history cannot load, use Retry rather than treating missing history as an empty result.

**Expected result:** Attention controls change notification state only. They do not approve a recommendation, resolve a broker condition, submit an order, or send an external message.

## E2E-13 — Switch the active portfolio in one tab

**Goal:** Work in the intended portfolio without silently changing another open tab.

- Requires: An authenticated investor with at least two portfolios.

1. Open `/holdings` and confirm the active portfolio name in the header.
2. Open the portfolio switcher and choose the intended portfolio.
3. Confirm that holdings are requested for that portfolio and its name appears in the header.
4. Refresh and confirm the active portfolio remains selected in this tab.
5. Open StoX in a separate tab and confirm it starts with its own active portfolio selection.

**Expected result:** The active portfolio changes per tab; switching does not move holdings or cash, edit the account's default portfolio, or submit orders. A fresh tab uses its valid stored/default context rather than inheriting a switch from an unrelated tab.
