import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import collector.kite_ticker_bridge as bridge_module  # noqa: E402
from collector.kite_ticker_bridge import KiteTickerBridge  # noqa: E402


class FakeTicker:
    MODE_FULL = "full"

    def __init__(self, api_key, access_token):
        self.api_key = api_key
        self.access_token = access_token
        self.subscriptions = []
        self.modes = []
        self.closed = False
        self.on_ticks = None
        self.on_connect = None
        self.on_close = None
        self.on_error = None
        self.on_reconnect = None
        self.on_noreconnect = None

    def connect(self, threaded=True):
        self.on_connect(self, {})

    def subscribe(self, tokens):
        self.subscriptions.append(list(tokens))

    def set_mode(self, mode, tokens):
        self.modes.append((mode, list(tokens)))

    def close(self):
        self.closed = True


class KiteTickerBridgeTest(unittest.TestCase):
    def test_reconnect_resubscribes_complete_universe_in_full_mode(self):
        changes = []
        bridge = KiteTickerBridge(lambda _meta, _tick: None, changes.append)
        universe = [
            {"source_instrument_token": 101, "instrument_id": 1},
            {"source_instrument_token": 202, "instrument_id": 2},
        ]
        with patch.object(bridge_module, "KiteTicker", FakeTicker):
            bridge.configure("key", "token", universe)
            bridge.start()
            ticker = bridge._ticker
            self.assertIsInstance(ticker, FakeTicker)
            ticker.on_reconnect(ticker, 1)

        self.assertEqual(ticker.subscriptions, [[101, 202], [101, 202]])
        self.assertEqual(ticker.modes, [("full", [101, 202]), ("full", [101, 202])])
        self.assertEqual(changes, [True, True])


if __name__ == "__main__":
    unittest.main()
