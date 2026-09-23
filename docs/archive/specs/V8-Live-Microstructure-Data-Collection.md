# StoX V8 Wishlist — Live Microstructure Data Collection

| Field | Value |
|---|---|
| **Epic** | V4-FEAT-063 |
| **Title** | Live Microstructure Data Collection |
| **Release** | V8 |
| **Priority intent** | START EARLY — prospective data cannot be backfilled later from Kite |
| **Status** | WISHLIST / ARCHITECTURE DIRECTION AGREED |
| **Primary source** | Zerodha Kite Connect WebSocket, `full` mode |
| **Universe** | Current NIFTY 500 fixed universe |
| **Storage cadence** | 1-minute aggregated records |
| **Storage format** | Apache Parquet |
| **Out of scope for this epic** | ML training, feature selection, historical OHLCV backfill |

## 1. Product intent

Start collecting the live market-microstructure information that Zerodha Kite exposes only prospectively and that cannot later be reconstructed from the historical OHLCV API.

This epic is deliberately separated from V4-FEAT-062 so it can be implemented and activated immediately, while the historical 8-year NIFTY 500 backfill can happen later.

The purpose of V4-FEAT-063 is only:

```text
subscribe -> receive -> aggregate -> validate -> persist
```

It does **not** decide whether these fields are predictive and does **not** train or deploy ML models.

## 2. Source and capacity

Use one Kite Connect WebSocket connection in `full` mode for the current NIFTY 500 equity universe.

Kite currently allows up to 3,000 instruments on one WebSocket connection and up to three connections per API key. Full mode contains the quote fields plus five bid levels and five offer levels, so the ~500-stock target fits comfortably within one connection.

Use the official Kite client library where practical so binary-packet parsing, reconnection and resubscription behaviour do not need to be reimplemented unnecessarily.

## 3. Source fields available in full mode

Capture the values needed to build minute-level microstructure summaries from the live stream, including:

- instrument token;
- exchange timestamp / last-trade timestamp where supplied;
- last traded price;
- last traded quantity;
- average traded price;
- cumulative traded volume for the day;
- total pending buy quantity;
- total pending sell quantity;
- Open Interest / OI day high / OI day low where applicable;
- five bid levels, each containing price, quantity and order count;
- five offer levels, each containing price, quantity and order count.

For cash equities OI is normally not applicable; keep the schema extensible rather than special-casing the transport layer.

## 4. Do not persist every raw tick indefinitely

The default V8 design SHALL NOT retain every WebSocket packet forever.

Reasons:

- update frequency is market-driven and can be high;
- raw tick volume is much larger and less predictable than minute data;
- the initial ML goal operates at minute-scale observations;
- most useful microstructure state can be summarized inside each minute;
- permanent raw-tick retention would create storage/operational complexity before its incremental ML value is established.

The collector MAY maintain a short bounded local spool/buffer for crash recovery and debugging, but the durable canonical dataset for this epic is the **1-minute aggregate**.

## 5. Minute aggregate schema

Produce one record per instrument per trading minute.

Minimum identity fields:

```text
instrument_id
source_instrument_token
exchange
tradingsymbol
minute_timestamp
source = kite
schema_version
```

Candidate aggregate fields to retain include:

### Price/trade state

- minute open/high/low/close reconstructed from received ticks;
- minute traded-volume delta derived from cumulative volume;
- tick/update count;
- last traded quantity: mean/max/last;
- average traded price: last and/or time-weighted average where justified;
- last exchange timestamp observed.

### Order-book spread/liquidity

- best bid / best ask at minute close;
- average/minimum/maximum absolute spread;
- average/minimum/maximum relative spread;
- bid depth: average/max/close across five levels;
- ask depth: average/max/close across five levels;
- total order counts on bid/ask sides: average/close.

### Imbalance

- average/closing five-level quantity imbalance;
- average/closing order-count imbalance;
- average/closing total-buy-vs-total-sell quantity imbalance;
- microprice: average/close where defined.

### Derivative-compatible fields

- OI close;
- OI change during minute;
- OI day high/low where supplied.

The exact retained aggregate set may be refined during implementation, but the collector should preserve enough summary statistics to evaluate microstructure signals later without needing the original tick stream.

## 6. Scale and storage estimate

For the fixed current NIFTY 500 universe:

```text
500 stocks × 375 trading minutes/day
= 187,500 rows/day
```

Using ~250 trading days/year:

```text
187,500 × 250
= 46,875,000 rows/year
```

At 2 years of collection:

```text
~93.75 million rows
```

At 3 years:

```text
~140.6 million rows
```

A minute-microstructure row will be wider than OHLCV. A practical planning assumption is roughly **150–300 bytes/row uncompressed logical payload** after compact numeric typing, depending on the final number of aggregates.

That implies roughly:

- **~28–56 MB/day uncompressed logical data**;
- **~7–14 GB/year uncompressed logical data**;
- with Parquet compression, a reasonable planning range is approximately **3–8 GB/year** for the canonical minute aggregates;
- budget **10 GB/year** to remain conservative and leave room for metadata/schema expansion.

Therefore even **3 years** of Dataset C minute aggregates should normally remain around **30 GB or less** under the conservative planning budget, excluding backups and temporary spool files.

## 7. Network/bandwidth estimate

Kite full-mode packets for tradeable instruments are 184 bytes each before WebSocket/message framing.

The exact number of packets per second is not fixed; Kite streams updates as received from the exchanges. Therefore network usage depends on market activity and cannot be calculated precisely from instrument count alone.

The collector must be designed as a streaming service rather than around a fixed tick frequency.

For capacity planning, even an illustrative average of 1 update/second for all 500 stocks would be only about:

```text
500 × 184 bytes ≈ 92 KB/s raw packet payload
```

An average of 5 updates/second per stock would be about:

```text
~460 KB/s raw packet payload
```

before framing/application overhead. These rates are easily within ordinary VPS networking; processing reliability and reconnection correctness are more important than bandwidth.

## 8. Processing/time requirement

There is no historical backfill time for this epic: collection occurs during live market hours.

The service should run during the NSE trading session and finalize each instrument-minute shortly after the minute boundary.

Expected durable writes:

```text
~187,500 records per trading day
```

This is a small ingestion workload for Parquet when records are buffered and written in batches rather than one file/write per row.

Daily compaction/finalization can occur after market close. It should take minutes, not hours, on the existing StoX infrastructure for this volume.

## 9. Recommended architecture

Initial architecture:

```text
Kite WebSocket (full mode)
        |
        v
Live collector service
        |
        +-- reconnect/resubscribe/session handling
        +-- in-memory state per instrument/minute
        +-- short bounded crash-recovery spool (optional)
        |
        v
1-minute aggregator
        |
        +-- price/trade summaries
        +-- spread/depth summaries
        +-- imbalance summaries
        +-- quality counters
        |
        v
Daily Parquet writer
        |
        v
Dataset C archive
```

The collector should be a lightweight long-running service, preferably Python for straightforward Kite client + Parquet/Polars integration unless the implementation review finds a stronger reason to use another existing StoX runtime.

## 10. Storage layout

Prefer coarse daily/monthly partitions rather than one file per stock or one file per minute.

Recommended starting layout:

```text
data/ml/microstructure/
  schema_v1/
    year=2026/
      month=09/
        date=2026-09-24/
          part-000.parquet
```

A trading day's ~187,500 rows can normally fit into one or a small number of healthy Parquet files.

Partition columns and file sizing should remain compatible with DuckDB/Polars predicate pruning.

## 11. Reliability requirements

Because the data cannot be recreated later from Kite historical APIs, reliability matters more than maximum throughput.

The collector SHALL support:

- automatic WebSocket reconnect;
- automatic resubscription and restoration of `full` mode;
- heartbeat/liveness monitoring;
- explicit market-session start/stop handling;
- detection of large data gaps;
- bounded retry/backoff;
- local buffering during short write interruptions;
- atomic/finalized daily Parquet output;
- duplicate-safe minute finalization;
- clean restart after process/server reboot;
- observable collector health and last-received timestamp;
- per-day/per-instrument coverage report.

A gap must be recorded as a gap; do not invent order-book observations that were never received.

## 12. Authentication/session handling and mobile UX

Kite access tokens are session-bound and the collector must not assume one credential is permanently valid.

The daily authentication step SHALL be deliberately optimized for **minimum friction from a mobile phone**. The operator should not need SSH, a desktop browser, shell commands, copy/paste of request tokens, or manual editing of configuration files.

### 12.1 Dedicated authentication route

StoX SHALL expose a dedicated, bookmarkable URL route for Kite collector authentication. The exact final path is an implementation detail, but it should be short and memorable, for example:

```text
https://stoxla.in/kite-auth
```

or an equivalent authenticated Admin route.

The page SHALL be:

- fully responsive and comfortable on a phone-sized screen;
- designed as a focused task rather than a generic settings form;
- reachable directly from a Telegram reminder/deep link;
- protected by the normal StoX authentication/authorization boundary;
- usable without navigating through multiple Admin menus;
- visually clear about whether today's Kite session is ready.

### 12.2 Minimum-click login flow

When today's Kite access token is missing/expired, the page SHOULD present one dominant action such as **Authenticate with Zerodha**.

Preferred flow:

```text
Open Telegram reminder
    -> tap StoX Kite-auth link
        -> page shows NOT AUTHENTICATED
            -> tap Authenticate with Zerodha
                -> Zerodha login / required verification
                    -> redirect back to StoX
                        -> server exchanges request token
                            -> access token stored securely
                                -> collector starts/reconnects
                                    -> page shows READY
```

StoX should preserve state across the external Zerodha login redirect so the operator is returned to the same mobile-friendly page rather than a generic dashboard.

Where Kite's security flow requires user interaction, StoX SHALL minimize additional StoX-side clicks around that unavoidable interaction.

### 12.3 Authentication status UX

The page SHOULD prominently show a simple status state such as:

- **Ready for market** — valid session available and collector can connect;
- **Authentication required** — today's session is missing/expired;
- **Connecting** — authentication succeeded and collector is establishing the stream;
- **Collector active** — WebSocket connected with expected subscription count;
- **Problem detected** — authentication exists but the collector is not healthy.

Useful supporting details MAY include:

- authenticated date/time;
- current Kite user/account identity where safe/useful;
- collector connection state;
- subscribed instrument count;
- last packet timestamp;
- latest error in concise human-readable form.

The normal view should remain intentionally uncluttered; diagnostic detail may live behind an expandable section.

### 12.4 Security requirements

The convenience UX must not weaken the existing security boundary.

At minimum:

- Kite API secret and generated access token never appear in the URL;
- access tokens are never rendered unnecessarily into HTML/JavaScript;
- sensitive credentials remain server-side and outside source control;
- callback `request_token` is validated/exchanged server-side;
- OAuth/login `state` or equivalent anti-CSRF correlation SHALL be used where supported/applicable;
- the auth route is Admin/operator-only unless a narrower dedicated permission is introduced;
- successful authentication is auditable;
- failure messages shown on mobile do not expose secrets.

### 12.5 Persistent StoX authorization vs daily Kite session

StoX SHALL distinguish between:

1. **Persistent operator authorization** for StoX to use Kite automatically when a valid session exists; and
2. the **daily Zerodha/Kite access session**, which still requires the operator to complete Zerodha's interactive login flow when the prior session has expired.

Normal overnight Kite session expiry MUST NOT revoke the persistent StoX authorization. Once the user completes the daily Zerodha login, the collector may start/reconnect automatically without an additional StoX-side approval step.

A deliberate manual **Disconnect**, execution **Kill Switch**, or equivalent explicit safety action SHALL revoke/suspend the persistent automatic-use authorization. StoX MUST NOT silently reconnect after such an explicit user action. Re-enabling automatic Kite use requires an explicit **Reconnect & Authorize StoX** (or equivalent) action.

This distinction prevents normal daily token expiry from creating unnecessary friction while preserving the semantic meaning of a manual disconnect or kill switch.

### 12.6 Collector handoff

Successful authentication SHALL automatically make the new session available to the collector without requiring SSH or a manual service restart.

The implementation MAY signal/reload/reconnect the long-running collector, but from the operator perspective the flow should finish with a clear **Ready / Collector active** result.

If authentication succeeds outside market hours, the collector may remain idle until its normal session start while showing that credentials are ready.

### 12.7 Telegram authentication reminders

StoX SHALL use the existing Telegram notification capability to remind the operator when daily Kite authentication is required.

The reminder SHALL include a direct HTTPS link to the dedicated mobile auth page so the common action is:

```text
Telegram notification
    -> tap link
    -> authenticate with Zerodha
    -> redirect to StoX
    -> collector ready
```

The notification policy is deliberately persistent because each unauthenticated trading hour loses prospective Dataset C observations.

Required behaviour:

- on every NSE trading day, begin checking for a valid current Kite session before market open;
- if already authenticated for the current day, send no authentication reminder;
- if authentication is missing and persistent StoX authorization remains enabled, send a pre-market Telegram reminder containing the auth-page deep link;
- while authentication remains missing, repeat the reminder **once per hour**;
- hourly reminders continue until the daily Kite authentication succeeds, the trading session ends, or persistent authorization is explicitly disabled through Disconnect/Kill Switch;
- successful authentication immediately cancels all remaining reminders for that trading day;
- manual Disconnect/Kill Switch suppresses daily-login reminders until the user explicitly re-authorizes StoX;
- if market opens without a valid session/collector connection, reminders continue hourly and the notification should clearly state that Dataset C is currently being lost;
- do not send routine daily-login reminders on known NSE weekends/holidays.

Exact first-reminder time is configurable in detailed design; the cadence after that is fixed at one hour while the actionable condition remains true.

The user may also maintain an independent mobile alarm as an additional human reminder; that alarm is outside StoX scope and does not replace the Telegram/session-health checks.

## 13. Instrument-universe policy

Use the **current NIFTY 500 fixed universe** selected for StoX ML research.

For V8, the user explicitly accepts survivorship bias and does not require historical NIFTY 500 membership reconstruction.

For prospective collection, refresh the configured current-universe mapping deliberately when StoX chooses to adopt constituent changes. Preserve instrument identity so previously collected records remain queryable after such changes.

## 14. Existing VPS suitability

This collector is lightweight enough to run on the current StoX VPS:

- 2 vCPU;
- ~8 GB RAM;
- ~100 GB local disk, currently lightly loaded.

The collector itself should consume modest CPU/RAM because it only parses the stream and maintains current-minute state for ~500 instruments.

At a conservative **10 GB/year** Parquet planning budget, current free disk is enough for the early collection period, but the system should expose storage thresholds and make migration to larger/object storage straightforward.

The heavy historical backfill, large-scale feature engineering and ML training remain separable from this always-on collector.

## 15. Operational monitoring

At minimum expose/log:

- collector process healthy/unhealthy;
- WebSocket connected/disconnected;
- authenticated/not authenticated;
- subscribed instrument count;
- last packet timestamp;
- packets received per minute;
- instruments observed per minute;
- finalized rows per minute/day;
- reconnect count;
- dropped/malformed packet count;
- minutes with partial/no coverage;
- Parquet write success/failure;
- local spool size if enabled;
- disk free-space threshold.

A failed collector during market hours should raise an Admin operational alert because lost Dataset C history cannot be fully reconstructed later.

## 16. Data-quality metadata

Each minute record or associated partition metadata should make it possible to distinguish:

- full-minute coverage;
- partial-minute coverage;
- no updates because the stock did not trade/change;
- collector/network outage;
- WebSocket reconnect window;
- market halt/session anomaly.

Do not treat "no tick" and "collector failed" as equivalent states.

## 17. Out of scope

V4-FEAT-063 does **not** include:

- 8-year historical OHLCV backfill;
- ML model training;
- feature-selection experiments;
- model promotion/deployment;
- ClickHouse or enterprise data-lake deployment;
- permanent retention of every raw tick;
- historical reconstruction of old NIFTY 500 membership;
- proving that any microstructure field has predictive value.

Those concerns belong to V4-FEAT-062, V4-FEAT-058, V4-FEAT-059 and the existing ML lifecycle as appropriate.

## 18. Initial acceptance criteria

1. The service can connect to Kite WebSocket using configured credentials.
2. It subscribes to the configured current NIFTY 500 instruments in `full` mode on one connection.
3. Reconnection automatically restores subscriptions and full mode.
4. It consumes the full quote/depth fields required by the aggregation contract.
5. It creates exactly one canonical minute record per observed instrument/minute.
6. It calculates the agreed spread/depth/imbalance/trade summary fields without future data.
7. Daily data is persisted as schema-versioned Parquet using a coarse partition layout.
8. Reprocessing/finalization is duplicate-safe.
9. Temporary process/write failures do not corrupt already finalized Parquet partitions.
10. Data gaps and partial coverage are recorded rather than silently filled.
11. Collector health, last packet time, subscription count, daily row count and disk space are observable.
12. A material market-hours collector failure can generate an Admin operational alert.
13. The service can run on the current StoX VPS without materially degrading the one-user production application under normal load.
14. The collector and Parquet schema are independent of future ML model choices.
15. ML training can consume the accumulated files later without requiring a migration from MySQL.
16. StoX provides a dedicated, bookmarkable, mobile-friendly Kite authentication URL that does not require navigating through the Admin UI.
17. When authentication is required, the page exposes one clear primary action to start the Zerodha login flow.
18. Successful Zerodha callback/token exchange returns the user to the same mobile authentication experience and clearly shows that the session is ready.
19. Successful authentication is handed to the collector without SSH, shell commands, token copy/paste or manual service restart.
20. The page clearly distinguishes authentication state from collector/WebSocket health.
21. Kite secrets/access tokens are never exposed in URLs or unnecessary client-side content.
22. Normal daily Kite-session expiry does not disable persistent StoX authorization to use Kite once the user logs in again.
23. Manual Disconnect/Kill Switch prevents automatic reuse/reconnection until the user explicitly re-authorizes StoX.
24. On trading days, Telegram sends a direct-link reminder when the daily Kite session is missing.
25. While authentication remains missing, reminders repeat hourly until login succeeds, the market session ends, or persistent authorization is explicitly disabled.
26. Authentication reminders stop immediately after successful authentication and are not routinely sent on known non-trading days.
27. Missing authentication after market open is clearly identified as active Dataset C data loss.

## 19. Priority rationale

This epic should be prioritised ahead of historical Dataset A backfill because the two datasets have different recoverability:

- **Historical OHLCV:** can be fetched later from Kite in controlled batches.
- **Live order-book/microstructure history:** cannot be recreated later at comparable depth from Kite.

Every trading day before V4-FEAT-063 starts is therefore a permanently lost day of potential Dataset C history.