import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


SPEC = importlib.util.spec_from_file_location(
    "yahoo_classification_trial",
    "scripts/yahoo_classification_trial.py",
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class InfoTicker:
    def __init__(self, info):
        self.info = info

    def get_info(self):
        return self.info


class YahooClassificationTrialTest(unittest.TestCase):
    def test_normal_client_response_records_identity_labels_and_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory) / "trial.json"
            result = MODULE.run_trial(
                ["TCS.NS"],
                cache,
                ticker_factory=lambda symbol: InfoTicker({
                    "symbol": symbol,
                    "longName": "TCS Limited",
                    "exchange": "NSI",
                    "quoteType": "EQUITY",
                    "sector": "Technology",
                    "industry": "Information Technology Services",
                }),
                sleep=lambda _: None,
            )
            row = result["results"][0]
            self.assertEqual(row["status"], "success")
            self.assertEqual(row["raw_labels"]["sector"], "Technology")
            self.assertEqual(row["raw_labels"]["industry"], "Information Technology Services")
            self.assertEqual(len(row["raw_payload_sha256"]), 64)
            self.assertFalse(row["cache_hit"])

            cached = MODULE.run_trial(["TCS.NS"], cache, ticker_factory=lambda _: (_ for _ in ()).throw(AssertionError("cache miss")))
            self.assertTrue(cached["results"][0]["cache_hit"])

    def test_invalid_crumb_or_other_provider_error_is_failed_and_retryable(self):
        calls = []

        class BrokenTicker:
            def get_info(self):
                calls.append("get_info")
                raise RuntimeError("HTTP 401: Invalid Crumb")

        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory) / "trial.json"
            result = MODULE.run_trial(["RELIANCE.NS"], cache, retries=2, ticker_factory=lambda _: BrokenTicker(), sleep=lambda _: None)
            row = result["results"][0]
            self.assertEqual(row["status"], "failed")
            self.assertEqual(row["attempts"], 3)
            self.assertIn("Invalid Crumb", row["error"])
            self.assertEqual(len(calls), 3)
            self.assertTrue(json.loads(cache.read_text())["RELIANCE.NS"]["status"] == "failed")

    def test_ambiguous_or_missing_labels_are_not_claimed_mapped(self):
        with tempfile.TemporaryDirectory() as directory:
            result = MODULE.run_trial(
                ["NEWIPO.NS"],
                Path(directory) / "trial.json",
                ticker_factory=lambda _: InfoTicker({"symbol": "NEWIPO.NS", "exchange": "NSI", "sector": None, "industry": ""}),
                sleep=lambda _: None,
            )
            row = result["results"][0]
            self.assertEqual(row["missing_fields"], ["sector", "industry"])
            self.assertEqual(row["mapping_status"], "pending_nse_taxonomy_gate")
            self.assertFalse(result["production_observations_written"])


if __name__ == "__main__":
    unittest.main()
