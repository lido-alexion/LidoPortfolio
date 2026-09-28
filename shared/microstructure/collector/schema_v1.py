"""Canonical minute-bar field names for FEAT-063 schema_v1."""

from __future__ import annotations

SCHEMA_VERSION = "schema_v1"
SOURCE = "kite_full_ws"

MINUTE_IDENTITY_FIELDS = (
    "instrument_id",
    "source_instrument_token",
    "exchange",
    "tradingsymbol",
    "minute_timestamp",
    "source",
    "schema_version",
)

MINUTE_OHLCV_FIELDS = (
    "open",
    "high",
    "low",
    "close",
    "volume_delta",
    "tick_count",
    "last_traded_quantity_sum",
    "last_traded_quantity_avg",
    "last_traded_quantity_max",
    "average_traded_price_avg",
    "average_traded_price_close",
    "last_exchange_timestamp",
)

MINUTE_QUALITY_FIELDS = (
    "coverage_class",
    "partial_reason",
)

# Extended depth / microstructure fields are populated when full-mode ticks supply them.
MINUTE_DEPTH_FIELDS = (
    "best_bid",
    "best_ask",
    "avg_spread_abs",
    "avg_spread_rel",
    "min_spread_abs",
    "max_spread_abs",
    "min_spread_rel",
    "max_spread_rel",
    "bid_depth_qty_sum",
    "ask_depth_qty_sum",
    "bid_depth_qty_avg",
    "ask_depth_qty_avg",
    "bid_depth_qty_min",
    "ask_depth_qty_min",
    "bid_depth_qty_max",
    "ask_depth_qty_max",
    "bid_order_count_sum",
    "ask_order_count_sum",
    "bid_order_count_avg",
    "ask_order_count_avg",
    "bid_order_count_min",
    "ask_order_count_min",
    "bid_order_count_max",
    "ask_order_count_max",
    "quantity_imbalance_5",
    "quantity_imbalance_5_avg",
    "order_count_imbalance_5",
    "total_buy_quantity_5",
    "total_sell_quantity_5",
    "total_quantity_imbalance",
)

MINUTE_MICROPRICE_FIELDS = (
    "microprice_close",
    "microprice_avg",
)

MINUTE_DERIVATIVE_FIELDS = (
    "oi_close",
    "oi_delta",
    "oi_day_high",
    "oi_day_low",
)

ALL_FIELDS = (
    *MINUTE_IDENTITY_FIELDS,
    *MINUTE_OHLCV_FIELDS,
    *MINUTE_QUALITY_FIELDS,
    *MINUTE_DEPTH_FIELDS,
    *MINUTE_MICROPRICE_FIELDS,
    *MINUTE_DERIVATIVE_FIELDS,
)
