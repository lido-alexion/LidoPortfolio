# StoX — Kite Holdings Onboarding and Transaction Staging
Status: FROZEN PRODUCT DECISIONS (2026-11-22). Specification only; implementation not authorized by this decision record.

## Scope
- Only equities currently held in the connected Zerodha Kite account. Do not onboard fully exited historical stocks, derivatives or mutual funds in this feature.
- Kite holdings are the authoritative snapshot for current quantities and reported average acquisition cost. Kite does not provide historical purchase dates for existing holdings.
- Two optional, complementary ways to refine the same staging draft:
  1. Quick import: create provisional opening entries for current holdings, using Kite quantity and average price. Today's date is an editable placeholder, explicitly NOT a verified purchase date.
  2. User-uploaded Zerodha Console tradebook (CSV/Excel, potentially multiple periods): reconstruct relevant buys/sells and refine the staged entries. No programmatic Console scraping or unofficial endpoints.
- User can manually edit dates, quantities and prices, including multiple purchase lots per currently held stock.

## Staging wizard
1. Fetch current Kite holdings.
2. Build persistent, resumable, editable staging entries; no live portfolio mutation.
3. Optionally upload tradebooks and merge/deduplicate with staged entries; user can return later.
4. Reconcile each stock independently against Kite quantity and cost basis; show provenance, discrepancies and any unresolved effects of splits, bonuses, transfers or missing older transactions. Do not invent historical purchase details or silently drop unmatched sells.
5. User explicitly verifies individual stocks, separately from committing.
6. User may commit any subset of verified stocks. Others remain staged. Recheck Kite holdings before commit and flag intervening changes. Commit all transactions for each selected stock atomically, preserving audit history and avoiding duplicates against existing StoX transactions.

## Safety and data semantics
- Clearly distinguish provisional opening positions from actual dated trades. Unverified placeholders must not distort historical performance/XIRR.
- Preserve broker trade IDs where available for idempotent deduplication; repeated imports must not duplicate.
- Historical tradebook data may be incomplete. Only use sells relevant to reconstructing currently held stocks, and handle unmatched historical sells/opening positions cautiously; do not infer unsupported dates/costs.
- Tradebook can include splits/bonuses/transfers or incomplete windows: discrepancies require user review, not silent adjustment.
- Current quantity matching alone does not prove historical cost-basis accuracy.
- Preview changes, preserve manual corrections and audit trail, and make import operations reversible where practical.
- Existing StoX portfolio entries must be reconciled, not overwritten or duplicated.
- Individual stock verification/commit status: draft → needs review → verified → committed; verification is not commit.
- Uncommitted staged entries must not affect live portfolio accounting or trading decisions.

## Explicit decisions
- Partial commits are allowed and preferred: e.g. commit 18 verified stocks while 2 remain in staging.
- Historical fully sold stocks are excluded for now.
- This product area is frozen for discussion; move on to another Kite capability. Technical design and implementation details can be worked out later without changing the above decisions.
