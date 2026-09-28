"""Resolve configured equity and index symbols to Kite instrument tokens."""

from __future__ import annotations

import json
import os
from pathlib import Path

DEFAULT_MAP_PATH = Path(__file__).resolve().parent / "data" / "instrument_tokens.json"


def load_token_map(path: str | Path | None = None) -> dict[str, int]:
    map_path = Path(path or os.environ.get("STOXLA_KITE_INSTRUMENT_MAP", DEFAULT_MAP_PATH))
    if not map_path.is_file():
        return {}
    payload = json.loads(map_path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("Instrument map must be a JSON object of SYMBOL -> token.")
    return {str(symbol).upper(): int(token) for symbol, token in payload.items()}


def resolve_instrument_token(symbol: str, path: str | Path | None = None) -> int | None:
    return load_token_map(path).get(symbol.upper())


def load_universe_symbols(path: str | Path | None = None) -> list[str]:
    """Return explicitly configured current-universe symbols in stable order."""
    return sorted(load_token_map(path).keys())


def load_index_map(path: str | Path) -> dict[str, int]:
    """Load the operator-supplied broad/sector index symbol-token manifest."""
    return load_token_map(path)
