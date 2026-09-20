import json
import os
import subprocess
import sys
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "ml_adapter.py"


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


if __name__ == "__main__":
    unittest.main()
