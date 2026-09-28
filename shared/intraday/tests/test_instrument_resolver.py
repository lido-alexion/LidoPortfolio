import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from instrument_resolver import load_token_map, load_universe_symbols, resolve_instrument_token  # noqa: E402


class InstrumentResolverTest(unittest.TestCase):
    def test_resolve_from_map_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tokens.json"
            path.write_text(json.dumps({"INFY": 408065}), encoding="utf-8")
            self.assertEqual(resolve_instrument_token("infy", path), 408065)
            self.assertIsNone(resolve_instrument_token("MISSING", path))

    def test_load_token_map_normalizes_symbols(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tokens.json"
            path.write_text(json.dumps({"reliance": 1}), encoding="utf-8")
            self.assertEqual(load_token_map(path)["RELIANCE"], 1)

    def test_current_universe_symbols_are_stable_and_sorted(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tokens.json"
            path.write_text(json.dumps({"ZETA": 3, "alpha": 1, "BETA": 2}), encoding="utf-8")
            self.assertEqual(load_universe_symbols(path), ["ALPHA", "BETA", "ZETA"])


if __name__ == "__main__":
    unittest.main()
