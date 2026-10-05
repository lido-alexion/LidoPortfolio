# FEAT-065 Mac Corpus Acceptance Plan

**Status:** OPEN — waiting for a representative verified batch from SKR-001 / V9-DATA-002.

## Ownership

- **SKR-001 — StoX-Kite-Rain:** Windows receive/download, transfer integrity checks, NTFS archival, manual Mac copy, and checksum verification for the handoff.
- **V9-DATA-002:** VPS historical-data staging, sealed batches, and secure Windows delivery surface.
- **FEAT-065:** canonical Parquet corpus on the Mac, non-destructive adoption, metadata/schema compatibility, coverage reporting, and DuckDB/Polars research access.
- **FEAT-057:** feature engineering, model datasets, training, and validation. Do not start training as part of this plan.

The FEAT-065 POC begins after SKR-001 has placed a representative verified batch on the Mac. It does not repeat the Windows downloader or transfer acceptance.

## Preconditions

- A representative batch has completed the SKR-001 Windows → NTFS → Mac handoff.
- Its sealed manifest and file checksums are available alongside the copied batch.
- The Mac has the FEAT-065 Python environment and enough free disk space for the POC and DuckDB temporary work.
- The V8 fixed-universe and survivorship-bias assumptions are recorded in the imported corpus metadata.

## Bounded POC

1. **Verify handoff evidence**
   - Confirm the copied manifest identifies the batch, trading date, schema version, file inventory, row counts, and SHA-256 values.
   - Confirm the Windows app/archive checks reported the same hashes before the Mac copy.
   - Record transfer ownership/evidence under SKR-001; report only Mac-side input compatibility here.

2. **Validate canonical Parquet adoption**
   - Read every POC Parquet file with the supported Parquet runtime.
   - Check schema/version, required identity and OHLCV fields, timestamp type/time zone convention, row counts, uniqueness, and ordering.
   - Compare file hashes/row counts to the sealed manifest without mutating source files.
   - Adopt the batch into the canonical FEAT-065 corpus non-destructively; repeat the adoption and confirm idempotency and unchanged accepted rows.

3. **Validate research access**
   - Query the adopted partitions directly with DuckDB and confirm trading date, instrument, timestamp, row count, and basic OHLCV aggregates against the manifest/source batch.
   - Scan the same partitions with Polars and confirm schema, key uniqueness, time ordering, and equivalent row/aggregate counts.
   - Confirm selected index partitions remain distinguishable from equity partitions where included.

4. **Validate coverage and quality reporting**
   - Generate the FEAT-065 coverage report for the POC date range.
   - Reconcile expected versus observed instruments, dates, and minute rows; list missing instruments/windows explicitly.
   - Keep unavailable provider history distinct from empty/non-trading days and data-quality failures.
   - Do not extrapolate POC coverage into a full-corpus coverage claim.

5. **Measure representative Mac performance**
   - Record Mac model, OS, Python/runtime versions, storage location/type, free space, dataset size, and exact queries.
   - Measure Parquet scan, DuckDB query, Polars scan, and bounded dataset-construction elapsed time and peak memory where available.
   - Confirm workloads finish without loading the whole corpus into memory and without unexpected spill/storage exhaustion.
   - Define any material performance issue before full backfill; preserve raw measurements.

6. **Record the downstream boundary**
   - Confirm the output can be read by FEAT-057 without adding FEAT-065 intraday features or changing FEAT-057 readiness thresholds.
   - Record the PIT-safe handoff contract. Do not train or promote a model.

## Exit criteria

- All POC files match their sealed manifest and are readable under the supported Parquet runtime.
- Canonical adoption is non-destructive and repeatable.
- DuckDB and Polars return consistent rows and representative aggregates.
- Coverage output correctly identifies present and missing data for the bounded range.
- Mac resource/performance measurements are recorded and acceptable for proceeding with the planned corpus backfill.
- Any failure is assigned to the owning boundary: transfer/checksum mismatch to SKR-001/V9-DATA-002; corpus/schema/adoption/query/coverage failure to FEAT-065.

## Full-corpus acceptance (after backfill)

The POC does not close the full-corpus gate. After the intended corpus is populated, separately report:

- instrument/date/window coverage and unresolved failures;
- index coverage and alignment;
- total rows, Parquet files, storage size, and quality findings;
- full-corpus DuckDB/Polars query performance on the Mac;
- durable checkpoints, retries, and pause/resume evidence for the actual campaign;
- manual external-disk backup completion according to the frozen workflow.

Only then can FEAT-065 move from IMPLEMENTED to COMPLETE, provided all applicable frozen exit criteria have evidence.
