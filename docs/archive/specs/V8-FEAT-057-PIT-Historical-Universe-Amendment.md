# V8 FEAT-057 PIT Historical Universe Amendment

This amendment records the issue #18 correction. The `active_eligible_nse` training universe is reconstructed from dated NSE MII security files where available, otherwise dated NSE cash-market bhavcopies (legacy or UDiFF). Only `EQ`, `BE`, and `BZ` company-equity rows are eligible; funds and ETFs are excluded. Canonical identity is resolved by ISIN first and historical symbol second.

No current stock universe may fill a missing date. A snapshot below 90% canonical mapping is rejected and remains a durable backfill failure. Successful boundaries record requested/effective date, source/file, format/version, source member count, mapped/unmapped identifiers, mapping percentage, and parser version.

Historical sector features use `sector_snapshot` through `sectorForDate(stock_id, reference_date)`. Missing dated sector evidence produces `__unknown` for categorical `sector` and `null` for `sector_relative_strength_3m`; current `portfolio_stocks.sector` is never projected backwards. Both features remain challenger / evidence-required until authoritative dated sector coverage exists.

Production acceptance remains open until the deployed archive is populated, required campaign dates reach 100% snapshot coverage, and fresh 1m/3m/6m evidence is independently verified.
