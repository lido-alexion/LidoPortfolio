# PO decision: temporary fundamental-field availability exceptions

**Date:** 2026-10-05  
**Owner:** Product Owner  
**Applies to:** FEAT-054 field-coverage acceptance and fundamental Screener operands

## Decision

The PO temporarily exempts the following 13 canonical primary facts from both the non-empty-field and 50%-of-covered-stocks acceptance thresholds because reliable coverage has not been demonstrated from the sources currently available:

- `capital_work_in_progress`
- `gross_npa`
- `gross_npa_ratio`
- `net_npa`
- `net_npa_ratio`
- `capital_adequacy_ratio`
- `provisions`
- `net_interest_margin`
- `promoter_holding`
- `fii_holding`
- `dii_holding`
- `public_holding`
- `promoter_pledge`

This adds to the earlier PO exceptions `dividends_paid` and `trade_receivables`, for 15 field exceptions total. The stock coverage targets remain unchanged: at least 98% of the verified current Nifty 500, and at least 50% of the eligible all-stocks universe. For the remaining non-exempt canonical primary facts, require at least one covered stock and at least 50% coverage among covered stocks.

## Product behavior

- Keep these keys in the canonical fact catalogue so a later reliable source can populate them.
- Missing values remain unavailable. Do not substitute zero or infer from unrelated fields.
- Do not publish Screener indicators/conditions for the 13 exception fields.
- Derived-metric coverage remains a separate measure; this exception does not claim derived metrics are populated.
- Reconsider each exception only after implementing a reliable source and reviewing a fresh coverage report. Any new licensed source ingestion is a separate epic.

Yahoo sample checks found promising aliases for `capital_work_in_progress` (provider label `Construction In Progress`) and `provisions` (current plus long-term balance-sheet provisions). These are candidates for a targeted Yahoo rerun after normalization is implemented; they do not remove the PO exception until persisted coverage is verified.

## Derivation review

| Fields | Safe derivation from currently stored general facts? | Reason |
|---|---|---|
| `capital_work_in_progress` | No derivation needed; source alias candidate | Yahoo returned `Construction In Progress`; a targeted rerun is needed to persist it. |
| `provisions` | Conditional source-component roll-up only | Sum current and long-term provisions for the same balance-sheet period only when both exist and no explicit total exists. Cash-flow provision/write-off adjustments are not equivalent. |
| Gross/net NPA amounts and ratios | No | Require specific NPA and gross/net advances disclosures. General liabilities, loans or provisions do not establish them. |
| `capital_adequacy_ratio` | No | Requires regulatory capital and risk-weighted-asset disclosures. |
| `net_interest_margin` | No | Net interest income alone is insufficient; the denominator requires average interest-earning assets. |
| Promoter/FII/DII/public holding and promoter pledge | No | These are distinct shareholding/encumbrance disclosures. Total shares, float, or aggregate institutional/insider percentages cannot safely reconstruct them. |

## Change control

These are temporary acceptance exceptions, not deletion of canonical fields. A PO-approved review with reliable source coverage is required before restoring a field to acceptance thresholds or adding its Screener indicator.
