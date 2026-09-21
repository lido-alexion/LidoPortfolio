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
    def test_int8_label_metrics_use_python_width_counts_for_large_partitions(self):
        import numpy as np

        spec = importlib.util.spec_from_file_location("ml_adapter_metrics", SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        labels = np.empty(4474, dtype=np.int8)
        labels[:] = np.arange(4474) % 2
        probabilities = [0.75 if int(label) else 0.25 for label in labels]
        metrics = module.classification_metrics(labels, probabilities, [0.01] * 4474, [-0.05] * 4474)

        self.assertEqual(metrics["class_distribution"]["positive"] + metrics["class_distribution"]["negative"], 4474)
        self.assertEqual(metrics["class_distribution"]["rows"], 4474)
        self.assertTrue(0.0 <= metrics["hit_rate"] <= 1.0)

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

    @unittest.skipUnless(SKLEARN_AVAILABLE, "scikit-learn is provided by the managed ML runtime")
    def test_training_excludes_features_absent_from_train_and_prediction_uses_effective_artifact_schema(self):
        configured_numeric = ["relative_strength_3m", "momentum_score", "trend_score", "roe", "debt_equity", "revenue_growth_proxy"]
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            paths = {}
            partitions = {"train": 16, "validation": 4, "test": 4}
            offsets = {"train": 0, "validation": 16, "test": 20}
            for partition, count in partitions.items():
                path = directory / f"{partition}.jsonl"
                paths[partition] = str(path)
                with path.open("w", encoding="utf-8") as handle:
                    for position in range(count):
                        index = offsets[partition] + position
                        label = index % 2
                        features = {
                            "relative_strength_3m": None if partition == "train" and position == 0 else float(index),
                            "momentum_score": float(index + 1),
                            "trend_score": float(index + 2),
                            "roe": None if partition == "train" else 10.0,
                            "debt_equity": None if partition == "train" else 0.5,
                            "revenue_growth_proxy": None if partition == "train" else 12.0,
                            "sector": "Technology",
                        }
                        handle.write(json.dumps({
                            "stock_id": index + 1,
                            "reference_date": f"2024-03-{index + 1:02d}",
                            "features": features,
                            "label": label,
                            "relative_return": 0.02 if label else -0.01,
                            "max_drawdown": -0.05,
                        }) + "\n")
            baseline_path = directory / "baseline.jsonl"
            with baseline_path.open("w", encoding="utf-8") as handle:
                for index in range(20, 24):
                    handle.write(json.dumps({
                        "stock_id": index + 1,
                        "reference_date": f"2024-03-{index + 1:02d}",
                        "probability": 0.75 if index % 2 else 0.25,
                        "positive_decision": index % 2 == 1,
                    }) + "\n")
            artifact = directory / "model.joblib"
            result = self.run_adapter("train", {
                "dataset_paths": paths,
                "deterministic_baseline_path": str(baseline_path),
                "numeric_features": configured_numeric,
                "categorical_features": ["sector"],
                "artifact_path": str(artifact),
            })
            self.assertEqual(result.returncode, 0, result.stderr)
            body = json.loads(result.stdout)
            metadata = body["metadata"]
            self.assertEqual(metadata["effective_feature_set"], ["relative_strength_3m", "momentum_score", "trend_score", "sector"])
            self.assertEqual([item["feature"] for item in metadata["excluded_features"]], ["roe", "debt_equity", "revenue_growth_proxy"])
            self.assertIn("relative_strength_3m__missing", metadata["feature_names"])
            self.assertNotIn("roe", metadata["preprocessing"]["medians"])
            self.assertEqual(metadata["feature_training_coverage"]["roe"], {"non_null": 0, "total": 16})

            prediction = self.run_adapter("predict", {
                "artifact_path": str(artifact),
                "artifact_sha256": body["artifact_sha256"],
                "features": {
                    "relative_strength_3m": 25.0,
                    "momentum_score": 26.0,
                    "trend_score": 27.0,
                    "roe": 11.0,
                    "debt_equity": 0.4,
                    "revenue_growth_proxy": 13.0,
                    "sector": "Technology",
                },
            })
            self.assertEqual(prediction.returncode, 0, prediction.stderr)
            self.assertIn("score", json.loads(prediction.stdout))


if __name__ == "__main__":
    unittest.main()
