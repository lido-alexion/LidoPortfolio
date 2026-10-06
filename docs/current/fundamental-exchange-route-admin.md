# Configure an approved fundamentals exchange route

This guide configures the normalized-feed route used by the FEAT-054 fundamentals fallback. Yahoo remains the primary provider. An exchange route is used only when Yahoo returns no usable facts and the corresponding Admin fallback switch is on.

## Configure it in Admin

1. Open **Admin → Fundamental Data**.
2. Under **Historical bootstrap exchange fallbacks**, enter the NSE or BSE **approved normalized-feed URL**.
3. Save the settings. Saving only stores the URL; it does not contact or test the endpoint.
4. After the exchange has allowlisted the VPS outbound IP and the route has been confirmed to return the required JSON, turn on the matching **Enable NSE/BSE fallback** switch and save.
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
- The BSE adapter currently consumes this normalized JSON contract; it does not parse arbitrary raw BSE website responses.
- NSE's built-in direct filing client remains a separate route protected by its server-side feature and authorization flags. The Admin feed URL selects the normalized-feed adapter; it does not change those direct-access flags.

## Safeguards

The Admin route is off by default. Saving an endpoint does not issue a request. The existing shared exchange gate serializes requests, spaces them by at least five seconds, caps them at 200 per day, and pauses for at least six hours after HTTP 403/429 (or longer for `Retry-After`). These limits remain server-enforced.

No “Test connection” button is provided because testing would itself contact the exchange/endpoint. Use a single approved stock fetch only after the whitelist is confirmed.
