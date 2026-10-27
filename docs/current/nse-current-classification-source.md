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

The existing `StockClassificationService` and shared `NseHttpClient` remain owners. Refresh fetches metadata, resolves an offered series, then requests quote data. A stored series must be offered exactly; without one, EQ is the ordinary offered default, or a single alternative is unambiguous. Other ambiguous choices fail. The returned market selector is bounded and carried through without hardcoding a new market policy.

Metadata must match canonical NSE symbol and any nonempty current master ISIN. The one quote must agree with metadata on symbol, ISIN, selected series and market, and contain nonempty sector/industry strings. Missing, ambiguous, foreign, malformed or legacy-shaped current responses fail closed. These source fields never rewrite Stock identity, series, lifecycle, holdings, prices or historical membership.

Observations retain a full decoded-response envelope (`quote_metadata` plus `equity_quote`), its canonical JSON SHA-256 and the actual quote operation URL. Legacy direct `recordObservation` payloads retain their original legacy URL. Existing rows and first-observed timestamps are not rewritten. Manual overrides keep precedence, and observation time does not become an official historical effective date.

The existing canonical taxonomy and provider identifier remain unchanged. The sole added compatibility translation is the observed INDUSTRY label `IT - Software` to existing canonical `IT Services`, following the already approved `Software` to `IT Services` convention. This is an application mapping; it is not a claim that NSE itself uses the canonical label `IT Services`. The separate `Software Products` mapping is unchanged. No policy derives from `basicIndustry`, and other unknown labels remain explicit failures.

HTTP, JSON, identity, shape and taxonomy failures all stay inside the refresh attempt's bounded retry/backoff path; they cannot advance successful-check state or create an observation. Existing approved provider observation and request behavior remain enabled. The new route has fixed public operation `nse.quote_api`; function, symbol, series, market and body values never enter public incident identity or titles.

## Verification and limits

The canonical hosted MySQL/PHP gate owns application verification. Regressions cover real response shape, exact request selection, source/hash/first-observed provenance, stored and offered series, identity mismatch, malformed/ambiguous HTTP 200, genuine HTTP 403, unknown labels/backoff, manual overrides, historical membership preservation, unchanged Software Products and fixed public-route redaction. No application suite ran on production.

The acquisition evidence proves that the existing deployed client can reach this current documented endpoint. Deployment qualification must still verify the exact new source and a bounded real current preview or normal collector result before claiming recovered observations. Unrelated stock validation, other provider failures, unavailable taxonomy labels and historical #277 causes remain separately qualified.
