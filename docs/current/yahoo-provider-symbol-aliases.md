# Reviewed Yahoo provider symbols

Defect [#292](https://github.com/lido-alexion/LidoPortfolio/issues/292), a concrete mapping cause found during [#272](https://github.com/lido-alexion/LidoPortfolio/issues/272) investigation.

## TEAM24 (BSE 500458)

Both retained official BSE active-equity snapshots acquired on 25 and 26 October 2026 identify scrip 500458 as TEAM24, ISIN INE601A01017. TEAM24 is the correct current canonical symbol. An [official BSE filing](https://www.bseindia.com/xml-data/corpfiling/AttachHis/f614aea1-516d-4dd7-8a85-392b179e597f.pdf) is a further issuer-name-history reference; this repair's identity evidence is the retained official master plus Yahoo's own matching issuer metadata below, rather than an inferred rename date or an unretained PDF extraction.

A bounded native Yahoo chart request on 27 October 2026 for KORE.BO, daily bars from 15 through 23 October in Asia/Kolkata, returned HTTP 200 and six bars. Yahoo's own metadata identifies symbol KORE.BO, shortName Kore Foods Limited, longName Team24 Consumer Products Limited, exchange BSE, instrumentType EQUITY, currency INR and exchangeTimezoneName Asia/Kolkata. The corresponding TEAM24.BO request previously returned HTTP 404 for the same valid range. This is evidence of a provider-specific retained symbol, not delisting or a reason to rename the security.

## Resolution boundary

`config/provider_symbol_aliases.php` contains only reviewed aliases. Read resolution requires the exact canonical exchange, symbol and BSE code. An absent ISIN is permitted because existing master records may still await the separate audited identity repair; a nonempty conflicting ISIN refuses the alias. Benchmarks are excluded. An explicit stored Yahoo symbol other than the canonical/default-derived TEAM24.BO keeps precedence.

`ProviderResolverService::providerSymbolsForStock()` and `yahooSymbolCandidates()` resolve on a clone. They return KORE.BO for the qualified identity without changing any original Stock attributes. Canonical price-history calls use the alias as the actual request symbol and protected correlation input. TEAM24.BO remains the existing fallback if the reviewed alias fails; normal HTTP observation and provider failure behavior remain enabled. Raw symbol-only lookups without canonical stock/code evidence do not acquire the alias.

This does not save provider fields, rewrite security identity or lifecycle, change Alpha Vantage/NSE/BSE symbols, mutate holdings/prices, backfill history, or change daily exchange-bulk collection. It establishes one concrete provider mapping correction. It does not resolve MIDPOLY availability, reconstruct the original Yahoo 400 request, or prove every historical #272 occurrence had this cause.

## Verification

The focused regression covers exact identity guards, absent/matching/conflicting ISINs, explicit custom precedence, all-attribute immutability, actual canonical Yahoo requests, failure correlation and unchanged fallback. Full MySQL/PHP validation remains the canonical hosted CI gate; no application suite or provider canary runs on production.
