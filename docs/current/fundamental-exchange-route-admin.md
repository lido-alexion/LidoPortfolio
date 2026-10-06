# Configure an approved fundamentals exchange route

This guide explains the built-in NSE filing route and the optional normalized-feed bridge used by the FEAT-054 fundamentals fallback. Yahoo remains the primary provider. An exchange route is used only when Yahoo returns no usable facts and the corresponding Admin fallback switch is on.

## Configure it in Admin

1. Open **Admin → Fundamental Data**.
2. For the built-in NSE filing route, leave the NSE URL blank. It discovers official integrated filings for the stock and parses XBRL XML and inline-XBRL HTML. The server-side direct-access feature and authorization flags must be enabled after exchange access is approved.
3. Enter a URL only when using an operator-managed normalized JSON bridge. Saving only stores the URL; it does not contact or test the endpoint.
4. After the VPS outbound IP is allowlisted and source access is ready, turn on the matching **Enable NSE/BSE fallback** switch and save.
5. Fetch through the existing bounded workflow. Do not start a broad backfill as a connectivity test.

The application appends the `symbol`, `cadence`, and `exchange` query parameters. The endpoint must return either an array of canonical fact rows or an object with a `facts` array. Each usable row includes `fact_key`, `period_end`, and `value`; optional fields include `statement_type`, `cadence`, `statement_basis`, `period_start`, `availability_date`, `currency`, and `source_meta`.

Example response:

```json
{
  "facts": [
    {
      "fact_key": "revenue",
      "statement_type": "income_statement",
      "cadence": "annual",
      "statement_basis": "consolidated",
      "period_end": "2025-03-31",
      "value": 1234.5,
      "currency": "INR"
    }
  ]
}
```

## Route restrictions

- Use an HTTPS URL on an approved hostname. The default approved domains are NSE and BSE subdomains.
- For a custom normalized-feed host, add its hostname to `FUNDAMENTALS_APPROVED_FEED_HOSTS` in the server environment and refresh Laravel's config cache during deployment. This is a hostname allowlist only; the full route URL stays editable in Admin.
- Do not include credentials, query strings, or fragments in the saved URL. Credentials are not supported.
- Redirects are disabled for feed requests.
- The built-in NSE adapter calls NSE's integrated-filing index, accepts only allowlisted NSE archive document URLs, and parses mapped XBRL XML or inline-XBRL HTML facts. Unknown concepts remain ignored. Direct access stays protected by server-side feature and authorization flags.
- BSE currently supports the normalized JSON contract only. StoX does not yet discover BSE filing links or parse raw BSE PDFs.
- An Admin URL selects the optional normalized-feed bridge. It does not change the server-side authorization flags for the built-in NSE route.

## Safeguards

The Admin route is off by default. Saving an endpoint does not issue a request. The existing shared exchange gate serializes requests, spaces them by at least five seconds, caps them at 200 per day, and pauses for at least six hours after HTTP 403/429 (or longer for `Retry-After`). These limits remain server-enforced.

No “Test connection” button is provided because testing would itself contact the exchange/endpoint. Use a single approved stock fetch only after the whitelist is confirmed.
