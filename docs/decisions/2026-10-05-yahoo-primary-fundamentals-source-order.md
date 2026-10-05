# Fundamentals source order: Yahoo primary

Date: 2026-10-05
Status: Product-owner decision

## Decision

Yahoo is the primary source for historical fundamentals. The matching exchange source (NSE for NSE/NSE+ listings; BSE for BSE-only listings) is a fallback.

For each stock and cadence, the ingest service:

1. Tries Yahoo first.
2. Accepts Yahoo only when it returns at least one usable numeric fact with a complete fact identity.
3. Uses the configured matching exchange source only when Yahoo returns no usable facts.
4. Does not merge Yahoo and exchange facts within one stock/cadence response.

This replaces FEAT-054's earlier official-first/per-fact fallback ordering. It does not change point-in-time rules: provider provenance and availability quality remain attached to facts, and non-PIT facts remain excluded from historical readers and training data.

## Exchange-source access condition

Exchange website automation remains disabled unless explicitly authorized. Prefer an exchange-provided licensed feed/API when available. A website endpoint being reachable does not grant permission to automate collection. Yahoo-first selection does not enable NSE or BSE feeds by itself.

## Implementation evidence

`FundamentalHistoricalIngestService` orders Yahoo before NSE and BSE, returns the first source with usable rows, skips malformed rows, and deduplicates repeated fact identities. Tests verify that Yahoo wins without querying NSE and that NSE/BSE are used only after Yahoo returns no usable facts.
