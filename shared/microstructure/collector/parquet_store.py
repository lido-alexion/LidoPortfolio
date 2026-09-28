"""Schema-versioned Parquet partition writer for Dataset C."""

from __future__ import annotations

import json
from datetime import date, datetime
from pathlib import Path
from typing import Any

try:
    import pyarrow as pa
    import pyarrow.parquet as pq
except ImportError:  # pragma: no cover - optional until venv installed
    pa = None
    pq = None

from collector.schema_v1 import ALL_FIELDS, SCHEMA_VERSION


def partition_dir(data_root: Path, trading_day: date) -> Path:
    return (
        data_root
        / SCHEMA_VERSION
        / f"year={trading_day.year:04d}"
        / f"month={trading_day.month:02d}"
        / f"date={trading_day.isoformat()}"
    )


def append_rows(data_root: Path, trading_day: date, rows: list[dict[str, Any]], part_name: str) -> Path | None:
    if not rows:
        return None
    if pa is None or pq is None:
        raise RuntimeError("pyarrow is required for Parquet writes")

    target_dir = partition_dir(data_root, trading_day)
    target_dir.mkdir(parents=True, exist_ok=True)
    final_path = target_dir / part_name
    staging_path = target_dir / f".{part_name}.staging"

    table = pa.Table.from_pylist(rows)
    pq.write_table(table, staging_path, compression="zstd")
    staging_path.replace(final_path)
    return final_path


def write_finalization_manifest(data_root: Path, trading_day: date, payload: dict[str, Any]) -> Path:
    target_dir = partition_dir(data_root, trading_day)
    target_dir.mkdir(parents=True, exist_ok=True)
    manifest = target_dir / "_FINALIZED.json"
    staging = target_dir / ".FINALIZED.json.staging"
    staging.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    staging.replace(manifest)
    return manifest


def is_partition_finalized(data_root: Path, trading_day: date) -> bool:
    return (partition_dir(data_root, trading_day) / "_FINALIZED.json").is_file()


def partition_row_count(data_root: Path, trading_day: date) -> int:
    """Return the committed row count without counting a staging file."""
    target_dir = partition_dir(data_root, trading_day)
    if pq is None:
        raise RuntimeError("pyarrow is required to validate Parquet partitions")
    total = 0
    for path in sorted(target_dir.glob("*.parquet")):
        total += pq.ParquetFile(path).metadata.num_rows
    return total


def validate_finalized_partition(data_root: Path, trading_day: date) -> dict[str, Any]:
    """Validate the manifest, schema, and row count before treating a day as final."""
    return validate_finalized_directory(partition_dir(data_root, trading_day))


def validate_finalized_directory(target_dir: Path) -> dict[str, Any]:
    """Validate a partition directory, including a backup staging directory."""
    manifest_path = target_dir / "_FINALIZED.json"
    if not manifest_path.is_file():
        raise ValueError("finalization manifest is missing")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"finalization manifest is unreadable: {exc}") from exc
    if not isinstance(manifest, dict) or not isinstance(manifest.get("row_count"), int):
        raise ValueError("finalization manifest has no integer row_count")
    if pq is None:
        raise RuntimeError("pyarrow is required to validate Parquet partitions")
    files = sorted(target_dir.glob("*.parquet"))
    total = 0
    for path in files:
        parquet = pq.ParquetFile(path)
        names = set(parquet.schema_arrow.names)
        missing = [field for field in ALL_FIELDS if field not in names]
        if missing:
            raise ValueError(f"{path.name} is missing schema fields: {', '.join(missing)}")
        total += parquet.metadata.num_rows
    if total != manifest["row_count"]:
        raise ValueError(f"manifest row_count={manifest['row_count']} but partition has {total} rows")
    return {"manifest": manifest, "row_count": total, "files": [path.name for path in files]}
