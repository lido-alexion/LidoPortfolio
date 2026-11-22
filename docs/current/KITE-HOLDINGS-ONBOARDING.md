# Kite Holdings Onboarding

The onboarding wizard at `/portfolio/kite-onboarding` stores a resumable draft separately from the transaction ledger. It stages currently held Kite equities, lets users edit lots, and requires stock-level verification before a partial commit.

The API is scoped to the active portfolio. `POST /api/kite/holdings-onboarding` reads the existing read-only Kite holdings snapshot and starts a draft; `PUT /stocks/{id}` edits staged entries; `POST /stocks/{id}/verify` checks net quantity, FIFO-covered sells, and remaining cost basis against Kite; `POST /commit` accepts verified stock IDs, rechecks Kite quantity and average cost, refuses existing StoX transaction history, then writes selected stock entries atomically through `TransactionWriteService`.

Console CSV uploads are parsed from the temporary upload stream and are not retained. Rows match currently staged symbols and support symbol/tradingsymbol, buy/sell side, quantity, price, date, and optional broker trade ID columns. Repeated IDs are deduplicated. Uploads replace the provisional Kite opening row for affected stocks and append imported trades; users should upload all available periods and review missing history, transfers, splits, bonuses, and uncovered sells. The current importer accepts CSV only; XLS/XLSX parsing is not implemented.

Opening position dates default to today and are visibly marked as placeholders. Users must correct them when they have evidence. Uncommitted staging does not affect portfolio accounting. Commit source notes preserve that placeholder warning. Existing StoX transaction history blocks commit so the wizard cannot overwrite or duplicate an existing ledger position.
