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


if __name__ == "__main__":
    unittest.main()
