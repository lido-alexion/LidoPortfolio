import json
import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "ml_adapter.py"
SKLEARN_AVAILABLE = importlib.util.find_spec("sklearn") is not None


class MlAdapterContractTest(unittest.TestCase):
    def run_adapter(self, operation, payload):
        env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1"}
        return subprocess.run(
            [sys.executable, str(SCRIPT), operation],
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            env=env,
            check=False,
        )

    def test_drift_returns_machine_readable_insufficient_data(self):
        result = self.run_adapter("drift", {"predictions": [], "minimum_predictions": 2})
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stderr, "")
        self.assertEqual(json.loads(result.stdout)["status"], "insufficient_data")

    def test_invalid_operation_fails_without_stdout_noise(self):
        result = self.run_adapter("unknown", {})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")
        self.assertIn("operation must be train, predict, or drift", result.stderr)

    def test_drift_reports_warning_for_matured_degradation_and_old_model(self):
        rows = [
            {"score": 80, "confidence": 0.8, "success": False, "relative_return": -0.10, "max_drawdown": -0.25}
            for _ in range(2)
        ]
        result = self.run_adapter("drift", {
            "predictions": rows,
            "minimum_predictions": 2,
            "model_age_days": 400,
            "age_warning_days": 365,
            "baseline_metrics": {"hit_rate": 0.8, "benchmark_relative_return": 0.02},
        })
        body = json.loads(result.stdout)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(body["status"], "warning")
        self.assertIn("model_age_exceeds_warning_threshold", body["warnings"])
        self.assertIn("live_hit_rate_deterioration", body["warnings"])

    @unittest.skipUnless(SKLEARN_AVAILABLE, "scikit-learn is provided by the managed ML runtime")
    def test_jsonl_training_stream_reports_diagnostics_and_aligns_baseline_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            paths = {}
            partitions = {
                "train": [(index, index % 2) for index in range(16)],
                "validation": [(index + 16, index % 2) for index in range(4)],
                "test": [(index + 20, index % 2) for index in range(4)],
            }
            for partition, entries in partitions.items():
                path = directory / f"{partition}.jsonl"
                paths[partition] = str(path)
                with path.open("w", encoding="utf-8") as handle:
                    for index, label in entries:
                        handle.write(json.dumps({
                            "stock_id": index + 1,
                            "reference_date": f"2024-01-{index + 1:02d}",
                            "features": {"momentum_score": float(index), "sector": "A" if index % 2 else "B"},
                            "label": label,
                            "relative_return": 0.02 if label else -0.01,
                            "max_drawdown": -0.05,
                        }) + "\n")
            baseline_path = directory / "baseline.jsonl"
            with baseline_path.open("w", encoding="utf-8") as handle:
                for index, _ in partitions["test"]:
                    handle.write(json.dumps({
                        "stock_id": index + 1,
                        "reference_date": f"2024-01-{index + 1:02d}",
                        "probability": 0.75 if index % 2 else 0.25,
                        "positive_decision": index % 2 == 1,
                    }) + "\n")
            artifact = directory / "model.joblib"
            result = self.run_adapter("train", {
                "dataset_paths": paths,
                "deterministic_baseline_path": str(baseline_path),
                "numeric_features": ["momentum_score"],
                "categorical_features": ["sector"],
                "partitions": {"train": {"start": "2024-01-01", "end": "2024-01-16"}},
                "artifact_path": str(artifact),
            })
            self.assertEqual(result.returncode, 0, result.stderr)
            body = json.loads(result.stdout)
            self.assertEqual(body["diagnostics"]["raw_transport"], "jsonl")
            self.assertEqual(body["diagnostics"]["train_rows"], 16)
            self.assertEqual(body["diagnostics"]["validation_rows"], 4)
            self.assertEqual(body["diagnostics"]["test_rows"], 4)
            self.assertGreaterEqual(body["diagnostics"]["feature_count"], 2)
            self.assertIsInstance(body["diagnostics"]["python_peak_rss_mb"], (int, float))
            self.assertTrue(artifact.is_file())

    @unittest.skipUnless(SKLEARN_AVAILABLE, "scikit-learn is provided by the managed ML runtime")
    def test_jsonl_training_rejects_baseline_identity_mismatch(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            paths = {}
            for partition, count, offset in (("train", 16, 0), ("validation", 4, 16), ("test", 4, 20)):
                path = directory / f"{partition}.jsonl"
                paths[partition] = str(path)
                with path.open("w", encoding="utf-8") as handle:
                    for index in range(offset, offset + count):
                        label = index % 2
                        handle.write(json.dumps({
                            "stock_id": index + 1,
                            "reference_date": f"2024-02-{index + 1:02d}",
                            "features": {"momentum_score": float(index), "sector": "A"},
                            "label": label,
                            "relative_return": 0.01 if label else -0.01,
                            "max_drawdown": -0.05,
                        }) + "\n")
            baseline_path = directory / "baseline.jsonl"
            with baseline_path.open("w", encoding="utf-8") as handle:
                for index in range(20, 24):
                    handle.write(json.dumps({
                        "stock_id": 999 if index == 20 else index + 1,
                        "reference_date": f"2024-02-{index + 1:02d}",
                        "probability": 0.5,
                        "positive_decision": False,
                    }) + "\n")
            result = self.run_adapter("train", {
                "dataset_paths": paths,
                "deterministic_baseline_path": str(baseline_path),
                "numeric_features": ["momentum_score"],
                "categorical_features": ["sector"],
                "artifact_path": str(directory / "model.joblib"),
            })
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("identity does not match", result.stderr)


if __name__ == "__main__":
    unittest.main()
