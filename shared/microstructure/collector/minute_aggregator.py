"""Aggregate Kite full-mode ticks into one-minute bars with explicit coverage metadata."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any

from collector.schema_v1 import SCHEMA_VERSION, SOURCE


def floor_minute(ts: datetime) -> datetime:
    return ts.replace(second=0, microsecond=0, tzinfo=timezone.utc)


@dataclass
class MinuteBucket:
    instrument_id: int
    source_instrument_token: int
    exchange: str
    tradingsymbol: str
    minute_timestamp: datetime
    open: float | None = None
    high: float | None = None
    low: float | None = None
    close: float | None = None
    volume_delta: float = 0.0
    tick_count: int = 0
    last_traded_quantity_sum: float = 0.0
    last_traded_quantity_avg: float | None = None
    last_traded_quantity_max: float | None = None
    average_traded_price_avg: float | None = None
    average_traded_price_close: float | None = None
    last_exchange_timestamp: datetime | None = None
    best_bid: float | None = None
    best_ask: float | None = None
    avg_spread_abs: float | None = None
    avg_spread_rel: float | None = None
    min_spread_abs: float | None = None
    max_spread_abs: float | None = None
    min_spread_rel: float | None = None
    max_spread_rel: float | None = None
    bid_depth_qty_sum: float | None = None
    ask_depth_qty_sum: float | None = None
    bid_depth_qty_avg: float | None = None
    ask_depth_qty_avg: float | None = None
    bid_depth_qty_min: float | None = None
    ask_depth_qty_min: float | None = None
    bid_depth_qty_max: float | None = None
    ask_depth_qty_max: float | None = None
    bid_order_count_sum: float = 0.0
    ask_order_count_sum: float = 0.0
    bid_order_count_avg: float | None = None
    ask_order_count_avg: float | None = None
    bid_order_count_min: float | None = None
    ask_order_count_min: float | None = None
    bid_order_count_max: float | None = None
    ask_order_count_max: float | None = None
    quantity_imbalance_5: float | None = None
    quantity_imbalance_5_avg: float | None = None
    order_count_imbalance_5: float | None = None
    total_buy_quantity_5: float | None = None
    total_sell_quantity_5: float | None = None
    total_quantity_imbalance: float | None = None
    microprice_close: float | None = None
    microprice_avg: float | None = None
    oi_close: float | None = None
    oi_delta: float = 0.0
    oi_day_high: float | None = None
    oi_day_low: float | None = None
    coverage_class: str = "partial"
    partial_reason: str | None = "awaiting_ticks"
    _last_cumulative_volume: float | None = field(default=None, repr=False)
    _last_oi: float | None = field(default=None, repr=False)
    _microprice_sum: float = field(default=0.0, repr=False)
    _microprice_samples: int = field(default=0, repr=False)
    _spread_abs_sum: float = field(default=0.0, repr=False)
    _spread_rel_sum: float = field(default=0.0, repr=False)
    _spread_samples: int = field(default=0, repr=False)
    _last_traded_quantity_samples: int = field(default=0, repr=False)
    _average_traded_price_sum: float = field(default=0.0, repr=False)
    _average_traded_price_samples: int = field(default=0, repr=False)
    _depth_samples: int = field(default=0, repr=False)
    _quantity_imbalance_sum: float = field(default=0.0, repr=False)

    def ingest_tick(self, tick: dict[str, Any]) -> None:
        price = tick.get("last_price")
        if price is None:
            return
        price_f = float(price)
        if self.open is None:
            self.open = price_f
        self.high = price_f if self.high is None else max(self.high, price_f)
        self.low = price_f if self.low is None else min(self.low, price_f)
        self.close = price_f
        self.tick_count += 1

        last_qty = tick.get("last_traded_quantity")
        if last_qty is not None:
            last_qty_f = float(last_qty)
            self.last_traded_quantity_sum += last_qty_f
            self._last_traded_quantity_samples += 1
            self.last_traded_quantity_avg = self.last_traded_quantity_sum / self._last_traded_quantity_samples
            self.last_traded_quantity_max = last_qty_f if self.last_traded_quantity_max is None else max(self.last_traded_quantity_max, last_qty_f)
        avg_price = tick.get("average_traded_price")
        if avg_price is not None:
            avg_price_f = float(avg_price)
            self.average_traded_price_close = avg_price_f
            self._average_traded_price_sum += avg_price_f
            self._average_traded_price_samples += 1
            self.average_traded_price_avg = self._average_traded_price_sum / self._average_traded_price_samples

        cum = tick.get("volume_traded")
        if cum is not None:
            cum_f = float(cum)
            if self._last_cumulative_volume is not None:
                delta = cum_f - self._last_cumulative_volume
                if delta > 0:
                    self.volume_delta += delta
            self._last_cumulative_volume = cum_f

        depth = tick.get("depth") or {}
        buy = depth.get("buy") or []
        sell = depth.get("sell") or []
        bid_qty = 0.0
        ask_qty = 0.0
        bid_orders = 0.0
        ask_orders = 0.0
        for level in buy[:5]:
            bid_qty += float(level.get("quantity") or 0)
            bid_orders += float(level.get("orders") or 0)
        for level in sell[:5]:
            ask_qty += float(level.get("quantity") or 0)
            ask_orders += float(level.get("orders") or 0)
        self.bid_order_count_sum += bid_orders
        self.ask_order_count_sum += ask_orders
        if buy:
            self.best_bid = float(buy[0].get("price", self.best_bid or 0) or 0)
        if sell:
            self.best_ask = float(sell[0].get("price", self.best_ask or 0) or 0)
        if bid_qty + ask_qty > 0:
            self.quantity_imbalance_5 = (bid_qty - ask_qty) / (bid_qty + ask_qty)
        self._depth_samples += 1
        self.bid_depth_qty_sum = (self.bid_depth_qty_sum or 0.0) + bid_qty
        self.ask_depth_qty_sum = (self.ask_depth_qty_sum or 0.0) + ask_qty
        self.bid_depth_qty_avg = self.bid_depth_qty_sum / self._depth_samples
        self.ask_depth_qty_avg = self.ask_depth_qty_sum / self._depth_samples
        self.bid_depth_qty_min = bid_qty if self.bid_depth_qty_min is None else min(self.bid_depth_qty_min, bid_qty)
        self.ask_depth_qty_min = ask_qty if self.ask_depth_qty_min is None else min(self.ask_depth_qty_min, ask_qty)
        self.bid_depth_qty_max = bid_qty if self.bid_depth_qty_max is None else max(self.bid_depth_qty_max, bid_qty)
        self.ask_depth_qty_max = ask_qty if self.ask_depth_qty_max is None else max(self.ask_depth_qty_max, ask_qty)
        self.bid_order_count_avg = self.bid_order_count_sum / self._depth_samples
        self.ask_order_count_avg = self.ask_order_count_sum / self._depth_samples
        self.bid_order_count_min = bid_orders if self.bid_order_count_min is None else min(self.bid_order_count_min, bid_orders)
        self.ask_order_count_min = ask_orders if self.ask_order_count_min is None else min(self.ask_order_count_min, ask_orders)
        self.bid_order_count_max = bid_orders if self.bid_order_count_max is None else max(self.bid_order_count_max, bid_orders)
        self.ask_order_count_max = ask_orders if self.ask_order_count_max is None else max(self.ask_order_count_max, ask_orders)
        self.order_count_imbalance_5 = ((bid_orders - ask_orders) / (bid_orders + ask_orders)) if bid_orders + ask_orders > 0 else None
        self.total_buy_quantity_5 = bid_qty
        self.total_sell_quantity_5 = ask_qty
        self.total_quantity_imbalance = self.quantity_imbalance_5
        if self.quantity_imbalance_5 is not None:
            self._quantity_imbalance_sum += self.quantity_imbalance_5
            self.quantity_imbalance_5_avg = self._quantity_imbalance_sum / self._depth_samples
        if self.best_bid is not None and self.best_ask is not None and self.best_ask > 0:
            spread = self.best_ask - self.best_bid
            mid = (self.best_ask + self.best_bid) / 2
            spread_rel = spread / mid if mid > 0 else None
            self.min_spread_abs = spread if self.min_spread_abs is None else min(self.min_spread_abs, spread)
            self.max_spread_abs = spread if self.max_spread_abs is None else max(self.max_spread_abs, spread)
            if spread_rel is not None:
                self.min_spread_rel = spread_rel if self.min_spread_rel is None else min(self.min_spread_rel, spread_rel)
                self.max_spread_rel = spread_rel if self.max_spread_rel is None else max(self.max_spread_rel, spread_rel)
            self._spread_abs_sum += spread
            if spread_rel is not None:
                self._spread_rel_sum += spread_rel
            self._spread_samples += 1
            self.avg_spread_abs = self._spread_abs_sum / self._spread_samples
            self.avg_spread_rel = self._spread_rel_sum / self._spread_samples if self._spread_samples > 0 else None
            if bid_qty + ask_qty > 0:
                micro = (self.best_bid * ask_qty + self.best_ask * bid_qty) / (bid_qty + ask_qty)
                self.microprice_close = micro
                self._microprice_sum += micro
                self._microprice_samples += 1
                self.microprice_avg = self._microprice_sum / self._microprice_samples

        oi = tick.get("oi")
        if oi is not None:
            oi_f = float(oi)
            if self.oi_day_high is None or oi_f > self.oi_day_high:
                self.oi_day_high = oi_f
            if self.oi_day_low is None or oi_f < self.oi_day_low:
                self.oi_day_low = oi_f
            if self._last_oi is not None:
                delta = oi_f - self._last_oi
                self.oi_delta += delta
            self._last_oi = oi_f
            self.oi_close = oi_f

        ts = tick.get("timestamp") or tick.get("last_trade_time")
        if isinstance(ts, datetime):
            self.last_exchange_timestamp = ts
        elif isinstance(ts, str):
            try:
                self.last_exchange_timestamp = datetime.fromisoformat(ts.replace("Z", "+00:00"))
            except ValueError:
                pass

        self.coverage_class = "full" if self.tick_count >= 1 else "partial"
        self.partial_reason = None if self.coverage_class == "full" else "sparse_ticks"

    def to_row(self) -> dict[str, Any]:
        return {
            "instrument_id": self.instrument_id,
            "source_instrument_token": self.source_instrument_token,
            "exchange": self.exchange,
            "tradingsymbol": self.tradingsymbol,
            "minute_timestamp": self.minute_timestamp.isoformat(),
            "source": SOURCE,
            "schema_version": SCHEMA_VERSION,
            "open": self.open,
            "high": self.high,
            "low": self.low,
            "close": self.close,
            "volume_delta": self.volume_delta,
            "tick_count": self.tick_count,
            "last_traded_quantity_sum": self.last_traded_quantity_sum,
            "last_traded_quantity_avg": self.last_traded_quantity_avg,
            "last_traded_quantity_max": self.last_traded_quantity_max,
            "average_traded_price_avg": self.average_traded_price_avg,
            "average_traded_price_close": self.average_traded_price_close,
            "last_exchange_timestamp": self.last_exchange_timestamp.isoformat() if self.last_exchange_timestamp else None,
            "best_bid": self.best_bid,
            "best_ask": self.best_ask,
            "avg_spread_abs": self.avg_spread_abs,
            "avg_spread_rel": self.avg_spread_rel,
            "min_spread_abs": self.min_spread_abs,
            "max_spread_abs": self.max_spread_abs,
            "min_spread_rel": self.min_spread_rel,
            "max_spread_rel": self.max_spread_rel,
            "bid_depth_qty_sum": self.bid_depth_qty_sum,
            "ask_depth_qty_sum": self.ask_depth_qty_sum,
            "bid_depth_qty_avg": self.bid_depth_qty_avg,
            "ask_depth_qty_avg": self.ask_depth_qty_avg,
            "bid_depth_qty_min": self.bid_depth_qty_min,
            "ask_depth_qty_min": self.ask_depth_qty_min,
            "bid_depth_qty_max": self.bid_depth_qty_max,
            "ask_depth_qty_max": self.ask_depth_qty_max,
            "bid_order_count_sum": self.bid_order_count_sum,
            "ask_order_count_sum": self.ask_order_count_sum,
            "bid_order_count_avg": self.bid_order_count_avg,
            "ask_order_count_avg": self.ask_order_count_avg,
            "bid_order_count_min": self.bid_order_count_min,
            "ask_order_count_min": self.ask_order_count_min,
            "bid_order_count_max": self.bid_order_count_max,
            "ask_order_count_max": self.ask_order_count_max,
            "quantity_imbalance_5": self.quantity_imbalance_5,
            "quantity_imbalance_5_avg": self.quantity_imbalance_5_avg,
            "order_count_imbalance_5": self.order_count_imbalance_5,
            "total_buy_quantity_5": self.total_buy_quantity_5,
            "total_sell_quantity_5": self.total_sell_quantity_5,
            "total_quantity_imbalance": self.total_quantity_imbalance,
            "microprice_close": self.microprice_close,
            "microprice_avg": self.microprice_avg,
            "oi_close": self.oi_close,
            "oi_delta": self.oi_delta,
            "oi_day_high": self.oi_day_high,
            "oi_day_low": self.oi_day_low,
            "coverage_class": self.coverage_class,
            "partial_reason": self.partial_reason,
        }


class MinuteAggregator:
    def __init__(self) -> None:
        self._buckets: dict[tuple[int, datetime], MinuteBucket] = {}

    def ingest_tick(self, meta: dict[str, Any], tick: dict[str, Any], at: datetime | None = None) -> None:
        self.ingest(meta, tick, at=at)

    def ingest(self, meta: dict[str, Any], tick: dict[str, Any], at: datetime | None = None) -> None:
        now = at or datetime.now(timezone.utc)
        minute = floor_minute(now)
        key = (int(meta["instrument_id"]), minute)
        bucket = self._buckets.get(key)
        if bucket is None:
            bucket = MinuteBucket(
                instrument_id=int(meta["instrument_id"]),
                source_instrument_token=int(meta["source_instrument_token"]),
                exchange=str(meta.get("exchange") or "NSE"),
                tradingsymbol=str(meta.get("tradingsymbol") or ""),
                minute_timestamp=minute,
            )
            self._buckets[key] = bucket
        bucket.ingest_tick(tick)

    def flush_before(self, cutoff_minute: datetime) -> list[dict[str, Any]]:
        cutoff = floor_minute(cutoff_minute)
        rows: list[dict[str, Any]] = []
        stale = [k for k in self._buckets if k[1] < cutoff]
        for key in sorted(stale):
            rows.append(self._buckets.pop(key).to_row())
        return rows
