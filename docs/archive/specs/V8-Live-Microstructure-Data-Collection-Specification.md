# StoX V8 Live Microstructure Data Collection Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-063 — Live Microstructure Data Collection |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready |
| **Priority** | HIGH — implement early in V8 |
| **Canonical path** | `docs/archive/specs/V8-Live-Microstructure-Data-Collection-Specification.md` |
| **Supersedes** | `docs/archive/specs/V8-Live-Microstructure-Data-Collection.md` |
| **Related historical-data epic** | V4-FEAT-065 — Intraday ML Historical Data Platform |
| **Primary source** | Zerodha Kite Connect WebSocket, `full` mode |
| **Primary implementation agent** | Codex |

---

## 1. Purpose

V4-FEAT-063 starts reliable prospective collection of live market-microstructure data that cannot later be reconstructed from Kite historical OHLCV.

The epic is intentionally limited to:

```text
subscribe -> receive -> aggregate -> validate -> persist -> finalize -> back up
```

It does not train models or decide whether any collected field is predictive.

This epic should be implemented early in V8 because every missed trading day permanently reduces the available prospective Dataset C history.

---

## 2. Frozen product decisions

| Decision | Frozen choice |
|---|---|
| 063-01 | Run the live collector on the existing StoX VPS. |
| 063-02 | Use local VPS Parquet as primary durable storage with periodic backup/replication. |
| 063-03 | Keep only a short bounded raw-tick spool for recovery/debugging; raw ticks are not retained permanently. |
| 063-04 | Persist partial minutes with explicit quality/coverage metadata; never invent missing market observations. |
| 063-05 | Use NSE trading-calendar-aware scheduling with pre-market startup, in-session health checks and post-market finalization. |
| 063-06 | On material market-hours failure, continue automatic recovery and immediately raise an Admin operational alert with duplicate suppression. |
| 063-07 | Periodically adopt current NIFTY 500 constituent changes while preserving historical instrument identity and prior data. |
| 063-08 | Back up each finalized trading-day partition after successful post-market validation. |
| 063-09 | Persist the full agreed 1-minute aggregate schema from day one. |
| 063-10 | Add a minimal StoX Admin operational status page for the collector. |
| 063-11 | Provide bounded Admin controls: Start/Reconnect, Stop, Force Resubscribe, Retry Finalization, Retry Backup, Refresh Universe. |
| 063-12 | A deliberate manual Stop creates an operational hold that scheduled startup cannot override until explicitly resumed. |
| 063-13 | Successful daily Kite authentication automatically starts/reconnects the collector when appropriate unless a manual operational hold is active. |
| 063-14 | Failed post-market finalization is preserved, retried automatically with bounded backoff, surfaced operationally, and blocks backup until finalization succeeds. |

---

## 3. Collection universe

The V8 collection universe is the current NIFTY 500 equity universe.

The implementation SHALL:

- subscribe to the configured current constituents;
- deliberately refresh constituent membership when StoX adopts a new NIFTY 500 list;
- begin collecting newly included constituents;
- stop future collection for removed constituents;
- retain all historical records for removed constituents;
- preserve stable instrument identity across membership changes.

Historical NIFTY 500 constituent reconstruction is out of scope for this epic.

---

## 4. Source and connection model

Use the Zerodha Kite Connect WebSocket in `full` mode through the official Kite client library where practical.

The collector SHALL restore subscriptions and `full` mode automatically after reconnect.

The collector SHALL be a dedicated long-running service on the existing StoX VPS. Python is the preferred implementation runtime unless repository/runtime review establishes a stronger implementation fit with another existing StoX component.

---

## 5. Durable data model

The canonical durable dataset is one record per instrument per trading minute in Apache Parquet.

Minimum identity fields:

```text
instrument_id
source_instrument_token
exchange
tradingsymbol
minute_timestamp
source
schema_version
```

The full V8 aggregate schema SHALL include, where available/applicable:

### 5.1 Price/trade state

- minute open/high/low/close reconstructed from received ticks;
- minute traded-volume delta from cumulative volume;
- tick/update count;
- last-traded-quantity summaries;
- average-traded-price summaries;
- last exchange timestamp observed.

### 5.2 Spread/liquidity/depth

- closing best bid and best ask;
- average/minimum/maximum absolute spread;
- average/minimum/maximum relative spread;
- five-level bid depth summaries;
- five-level ask depth summaries;
- bid/ask order-count summaries.

### 5.3 Imbalance/microstructure

- five-level quantity imbalance;
- order-count imbalance;
- total-buy-versus-total-sell quantity imbalance;
- microprice statistics where defined.

### 5.4 Derivative-compatible fields

Where supplied/applicable:

- OI close;
- OI change during the minute;
- OI day high/low.

Schema evolution SHALL be explicit and versioned.

---

## 6. Raw-tick spool

Raw ticks are not a permanent analytical dataset.

The collector MAY maintain a short local crash-recovery/debug spool, but it SHALL be:

- bounded by time and/or size;
- automatically cleaned after successful aggregation/finalization;
- excluded from long-term retention expectations;
- protected from unbounded disk growth.

Exact spool limits are implementation configuration.

---

## 7. Quality and partial coverage

A minute with incomplete observations SHALL be retained when useful, but explicitly marked as partial.

Quality metadata SHALL distinguish, where determinable:

- full-minute coverage;
- partial-minute coverage;
- no trade/no update;
- collector/network outage;
- reconnect window;
- exchange/session anomaly;
- market halt.

StoX SHALL NOT synthesize order-book or trade observations that were not actually received.

Coverage reporting SHALL be available per trading day and instrument.

---

## 8. Storage and partitioning

Use local VPS storage as the active canonical Dataset C store.

Use coarse Parquet partitioning, initially along the lines of:

```text
data/ml/microstructure/
  schema_v1/
    year=YYYY/
      month=MM/
        date=YYYY-MM-DD/
          part-*.parquet
```

Avoid one-file-per-instrument or one-file-per-minute layouts.

Writes/finalization SHALL be atomic or otherwise corruption-safe and duplicate-safe.

Storage thresholds SHALL be observable so migration to larger/object storage can be planned before capacity becomes critical.

---

## 9. Daily lifecycle

Use NSE trading-calendar awareness.

Normal lifecycle:

```text
trading-day pre-market
    -> check Kite authentication
    -> successful authentication
    -> collector starts/reconnects automatically
    -> subscribe current universe in full mode
    -> collect + aggregate through session
    -> monitor health and gaps
    -> market close
    -> finalize daily Parquet partition
    -> validate finalized output
    -> back up finalized partition
```

Known NSE weekends/holidays SHALL suppress routine collection startup and authentication reminders.

---

## 10. Authentication and mobile workflow

The existing StoX/Kite authentication approach remains the operator entry point.

StoX SHALL expose a stable, bookmarkable, mobile-friendly authentication URL. The URL itself remains constant day to day.

Successful Kite authentication SHALL:

- store credentials/token material server-side only;
- return the operator to the StoX authentication/status experience;
- make the session available to the collector automatically;
- automatically start/reconnect the collector when appropriate;
- require no SSH, token copy/paste, or manual service restart.

A manual operational hold from Decision 063-12 takes precedence over automatic collector startup.

Existing security requirements remain: no secrets in URLs, source control, unnecessary client-side output, or user-visible diagnostics.

---

## 11. Authentication reminders

On NSE trading days, when the current Kite session is missing:

- send a pre-market Telegram reminder using the same static StoX authentication link;
- repeat once per hour while authentication remains missing;
- stop reminders immediately after successful authentication or when the trading session ends;
- continue after market open if missing authentication is causing Dataset C loss;
- suppress routine reminders on known weekends/holidays.

A deliberate Disconnect/Kill Switch invalidates the current session. Restoration requires a fresh Zerodha login.

---

## 12. Reliability and recovery

The collector SHALL support:

- automatic WebSocket reconnect;
- automatic resubscription and restoration of `full` mode;
- heartbeat/liveness monitoring;
- bounded retry/backoff;
- short write-interruption buffering;
- clean process/server restart;
- duplicate-safe minute finalization;
- persistent identification of gaps;
- safe post-market finalization;
- retryable backup.

Because live microstructure history cannot be recreated later, reliability is prioritized over maximum throughput.

---

## 13. Failure handling

### 13.1 Market-hours collector failure

For material market-hours degradation:

- continue automatic recovery attempts;
- raise an Admin operational alert promptly;
- show concise context such as last packet timestamp, connection state, subscription count and reconnect attempts;
- indicate when prospective data loss is occurring;
- suppress repetitive duplicate notifications for the same ongoing incident.

### 13.2 Finalization failure

If post-market finalization fails:

- preserve all unfinalized working/spool data;
- retry automatically using bounded backoff;
- expose failure in Admin operational status;
- raise an Admin operational alert;
- do not begin backup until finalization succeeds.

### 13.3 Backup failure

Backup failure SHALL NOT invalidate or block the canonical local finalized partition.

The failed backup SHALL be visible and retryable independently.

---

## 14. Backup/replication

After a trading-day partition successfully finalizes and validates, StoX SHALL replicate it to a secondary storage location.

The exact secondary target is an implementation/configuration choice unless a later cost/security constraint requires a PO decision.

Backup processing SHALL:

- be asynchronous from market-hours collection;
- record success/failure;
- support retry;
- never delete the canonical local partition merely because backup failed.

---

## 15. Admin operational UI

Provide a minimal Admin/operator surface, not a market-data analytics dashboard.

Display at least:

- Kite authentication/session state;
- manual-hold state;
- collector/WebSocket connected state;
- subscribed instrument count;
- last packet timestamp;
- reconnect count;
- current-day coverage summary;
- latest finalized partition;
- backup status;
- disk/storage status;
- latest operational error.

Bounded controls:

- Start / Resume / Reconnect;
- Stop;
- Force Resubscribe;
- Retry Finalization;
- Retry Backup;
- Refresh NIFTY 500 Universe.

No arbitrary command/shell execution shall be exposed.

---

## 16. Manual stop semantics

A deliberate Admin Stop is persistent operational intent.

When manually stopped:

- collector state is `Manually stopped`;
- scheduler/pre-market startup does not restart it;
- successful daily authentication alone does not override the hold;
- explicit Start/Resume clears the hold.

This is distinct from a temporary connection failure, which the collector should recover from automatically.

---

## 17. Observability

At minimum expose or log:

- collector process health;
- authentication state;
- WebSocket state;
- subscribed instrument count;
- last packet timestamp;
- packets received per minute;
- instruments observed per minute;
- rows finalized per minute/day;
- reconnect count;
- dropped/malformed packet count;
- partial/no-coverage minutes;
- Parquet finalization status;
- raw-spool size if enabled;
- backup status;
- disk free-space threshold.

FEAT-052 OpenTelemetry integration may later instrument these technical signals where appropriate, but FEAT-063 does not depend on FEAT-052 to function correctly.

---

## 18. Out of scope

FEAT-063 does not own:

- historical 1-minute OHLCV backfill — V4-FEAT-065;
- permanent raw-tick retention;
- ML feature-selection research;
- model training/evaluation;
- model promotion/deployment;
- proving predictive value of microstructure fields;
- historical NIFTY 500 membership reconstruction;
- ClickHouse/enterprise data-lake deployment.

Former FEAT-058/059 references in the earlier wishlist draft are superseded by the consolidated FEAT-057 ML training/validation contract.

---

## 19. Acceptance criteria

Implementation is complete when all of the following hold:

1. Collector runs reliably on the existing StoX VPS.
2. It authenticates through the existing mobile-friendly Kite flow without SSH/manual token handling.
3. Successful authentication automatically makes the session available to the collector and starts/reconnects it unless manually held.
4. It subscribes to the configured current NIFTY 500 universe in Kite `full` mode.
5. Reconnect restores subscriptions and full mode automatically.
6. One canonical minute aggregate per observed instrument/minute is produced using the full agreed V8 schema.
7. Partial/gap conditions are preserved with explicit quality metadata rather than synthesized observations.
8. Durable output is schema-versioned Parquet using coarse partitions on local VPS storage.
9. Temporary raw-tick retention is bounded and self-cleaning.
10. Finalization is corruption-safe and duplicate-safe.
11. Failed finalization preserves source/working data and retries automatically.
12. Successfully finalized daily partitions are validated before backup.
13. Each validated daily partition is replicated to secondary storage with retryable status.
14. Market-hours collector failure triggers automatic recovery and an Admin operational alert.
15. Admin operational UI exposes collector/session/coverage/finalization/backup/storage health.
16. Bounded Admin controls work without exposing arbitrary shell capability.
17. Manual Stop persists across scheduled startup until explicitly resumed.
18. Trading-calendar awareness suppresses normal startup/reminders on known NSE weekends/holidays.
19. Telegram authentication reminders continue hourly while a required trading-day login remains missing and stop after success/session end.
20. Current NIFTY 500 membership can be deliberately refreshed while preserving historical identity/data.
21. Storage and process load remain compatible with the current StoX VPS under normal one-user production workload.

---

## 20. Implementation freedom

Codex may choose implementation-level details that do not alter the frozen product contract, including:

- exact service/process manager packaging;
- exact Python/library versions;
- raw-spool duration/size bounds;
- Parquet row-group/file sizing;
- retry intervals/backoff parameters;
- health-check cadence;
- alert deduplication mechanics;
- exact secondary backup target/configuration;
- exact Admin API/component decomposition.

Any implementation finding that materially changes data retained, privacy/security, operating cost, manual-control semantics, recoverability, or the frozen product boundary must return to Product/Architecture for review.