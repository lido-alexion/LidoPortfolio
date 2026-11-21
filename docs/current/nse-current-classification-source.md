# Current NSE classification source

Tracked under [#291](https://github.com/lido-alexion/LidoPortfolio/issues/291). This fixes the scheduled classification collector's obsolete quote protocol; it does not establish that every historical generic NSE 403 had this cause or that all NSE consumers use the same protocol.

## Primary protocol and deployed-client evidence

The current official quote page, `https://www.nseindia.com/get-quote/equity/Infy/Infosys-Limited`, was observed to load `https://www.nseindia.com/_next/static/chunks/c1e5e22f1c971efe.js`. Its observed fetch calls use the existing public endpoint `/api/NextApi/apiClient/GetQuoteApi` with `functionName=getMetaData&symbol=...`, then `functionName=getSymbolData&marketType=...&series=...&symbol=...`. The first investigator retained the exact observed code snippets but not full JavaScript bytes. An independent JavaScript acquisition on 27 October timed out; no successful raw-JavaScript capture or hash is claimed.

Independent ordinary native requests on 27 October 2026 retained:

| Acquisition (UTC) | Source | HTTP / bytes | SHA-256 |
| --- | --- | --- | --- |
| 04:45:24 | `getMetaData`, INFY | 200 / 383 | `c627c803369556c10d0e152129102e168fd346715c6d7c2b75deb2ee9d96b7bf` |
| 04:45:49 | `getSymbolData`, N / EQ / INFY | 200 / 3424 | `6dc676ebc2826811d5f3594d86103955179ca3f7c45b9f0350afc7ace493ef5c` |
| 04:46:34 | Same quote operation through the existing deployed `NseHttpClient` | 200 / 3419 | `bc52d0fa25024598e910945bfec140ac8d85be1c17cd6b4cc3d55a82f99ab2fd` |

The deployed client used its existing normal initialization, headers, cookies and transport. Its one target request ran inside an enforced database READ ONLY transaction, rolled back afterward; no classification was persisted. There was no denial retry grid, fingerprint change, source-access workaround or production configuration change. Full responses remain in protected operator evidence. Public test fixtures are small faithful projections of the observed metadata, quote identity and classification fields; they omit unrelated quote prices/order-book data and do not claim the original HTTP byte hashes.

The metadata identifies INFY / INE009A01021, market N and offered EQ/T0 series. The quote identifies that same symbol/ISIN/market/selected series under `equityResponse[0].metaData`. Classification is in `equityResponse[0].secInfo`: `macro=Information Technology`, `sector=Information Technology`, `industryInfo=IT - Software`, and `basicIndustry=Computers - Software & Consulting`. It is not the legacy top-level `industryInfo` object.

## Owner and safety contract

The existing `StockClassificationService` remains the classification owner. Its wire/identity adapter is now shared with stock validation through [`NseEquityQuoteService`](./nse-equity-quote-adapter.md), retaining the existing shared `NseHttpClient`. Refresh fetches metadata, resolves an offered series, then requests quote data. A stored series must be offered exactly; without one, EQ is the ordinary offered default, or a single alternative is unambiguous. Other ambiguous choices fail. The returned market selector is bounded and carried through without hardcoding a new market policy.

Metadata must match canonical NSE symbol and any nonempty current master ISIN. The one quote must agree with metadata on symbol, ISIN, selected series and market, and contain nonempty sector/industry strings. Missing, ambiguous, foreign, malformed or legacy-shaped current responses fail closed. These source fields never rewrite Stock identity, series, lifecycle, holdings, prices or historical membership.

Observations retain a full decoded-response envelope (`quote_metadata` plus `equity_quote`), its canonical JSON SHA-256 and the actual quote operation URL. Legacy direct `recordObservation` payloads retain their original legacy URL. Existing rows and first-observed timestamps are not rewritten. Manual overrides keep precedence, and observation time does not become an official historical effective date.

The existing canonical taxonomy and provider identifier remain unchanged. The initial compatibility translation mapped the observed INDUSTRY label `IT - Software` to approved `IT Services` following the existing `Software` convention. PR #306 subsequently added 14 exact NSE source-pair translations into already-approved canonical sector/industry pairs. These are application mappings, not claims that NSE itself uses our canonical labels. The separate `Software Products` mapping is unchanged. No policy derives from `basicIndustry`; unresolved labels remain explicit failures.

## Remaining source-pair coverage (15 November 2026)

The supplied read-only source-label snapshot contained 49 failing stocks across 28 pairs. The mappings already present after PR #306 cover 14 pairs and 24 stocks. The other 25 stocks span the 14 exact pairs below. None has a defensible one-to-one destination in `nse-indices-4-tier-v1`; the current pair mapping deliberately leaves each unresolved, and the service must continue to reject it without creating an observation.

| NSE source sector | NSE source industry | Stocks | Coverage gap |
| --- | --- | ---: | --- |
| Healthcare | Pharmaceuticals & Biotechnology | 5 | The canonical taxonomy has Pharmaceuticals but no Biotechnology industry. The combined source label cannot safely be reduced to only Pharmaceuticals. |
| Chemicals | Chemicals & Petrochemicals | 4 | No Chemicals or Petrochemicals canonical sector or industry exists. |
| Services | Commercial Services & Supplies | 3 | No approved Services sector or corresponding industry exists. |
| Construction Materials | Cement & Cement Products | 2 | No approved construction-materials or cement industry exists. |
| Consumer Services | Leisure Services | 2 | The canonical Other Consumer Services industry is broader and does not establish an exact leisure mapping. |
| Fast Moving Consumer Goods | Agricultural Food & other Products | 1 | The combined source label includes “other products”; mapping all of it to Food Products would overstate equivalence. |
| Diversified | Diversified | 1 | No Diversified canonical sector or industry exists. |
| Capital Goods | Industrial Manufacturing | 1 | Industrial Products is not an evidenced synonym for all industrial manufacturing. |
| Realty | Realty | 1 | No Realty or Real Estate canonical sector or industry exists. |
| Services | Transport Services | 1 | No approved Services or transport-services canonical pair exists. |
| Metals & Mining | Metals & Minerals Trading | 1 | Trading is not equivalent to the canonical Minerals & Mining extraction industry. |
| Services | Transport Infrastructure | 1 | No approved Services or transport-infrastructure canonical pair exists. |
| Capital Goods | Aerospace & Defense | 1 | No Aerospace or Defense industry exists; mapping to Industrial Products or Electrical Equipment would be speculative. |
| Chemicals | Fertilizers & Agrochemicals | 1 | No Chemicals, Fertilizers or Agrochemicals canonical industry exists. |

These gaps do not justify changing the approved four-tier taxonomy or creating generic fallback labels. To cover them, the owner would need to approve a versioned taxonomy policy grounded in the official classification structure and explicit treatment of mixed/broader labels. Until then, the supported choices are to keep these observations unknown or use a separately reviewed canonical manual override where appropriate. Any taxonomy extension must preserve sector/industry relationships and add evidence plus regression coverage before mappings are enabled. `StockClassificationAdminTest` now exercises every pair above and verifies that rejection retains the exact source labels and writes no observation.

HTTP, JSON, identity, shape and taxonomy failures all stay inside the refresh attempt's bounded retry/backoff path; they cannot advance successful-check state or create an observation. Existing approved provider observation and request behavior remain enabled. The new route has fixed public operation `nse.quote_api`; function, symbol, series, market and body values never enter public incident identity or titles.

## Verification and limits

The canonical hosted MySQL/PHP gate owns application verification. Regressions cover real response shape, exact request selection, source/hash/first-observed provenance, stored and offered series, identity mismatch, malformed/ambiguous HTTP 200, genuine HTTP 403, unknown labels/backoff, manual overrides, historical membership preservation, unchanged Software Products and fixed public-route redaction. No application suite ran on production.

The acquisition evidence proves that the existing deployed client can reach this current documented endpoint. Deployment qualification must still verify the exact new source and a bounded real current preview or normal collector result before claiming recovered observations. Unrelated stock validation, other provider failures, unavailable taxonomy labels and historical #277 causes remain separately qualified.
