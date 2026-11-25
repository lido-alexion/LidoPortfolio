"""Exercise the wrapper without invoking PHP or a database."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

WRAPPER = Path(__file__).resolve().parents[3] / "scripts/run-ci-phpunit.sh"


class PhpunitDiagnosticsTests(unittest.TestCase):
    def run_wrapper(self, hosted, status):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            php = folder / "php"
            php.write_text(
                '#!/usr/bin/env bash\n'
                'printf "%s\\n" "$@" > "$TEST_ARGS"\n'
                'if [[ "$*" == *--log-events-text* ]]; then\n'
                '  printf "Test Started (diagnostic-marker)\\n" > "$5"\n'
                'fi\n'
                'echo php-stderr-marker >&2\n'
                'exit "$TEST_STATUS"\n'
            )
            php.chmod(0o700)
            args_path = folder / "args"
            env = dict(os.environ, PATH=f"{folder}:{os.environ['PATH']}",
                       GITHUB_ACTIONS="true" if hosted else "false",
                       RUNNER_TEMP=directory, TEST_ARGS=str(args_path),
                       TEST_STATUS=str(status), LC_ALL="C")
            result = subprocess.run(["bash", str(WRAPPER)], cwd=folder,
                                    env=env, capture_output=True, text=True)
            return result, args_path.read_text().splitlines(), list(folder.glob("stox-phpunit-events.*"))

    @unittest.skipUnless(sys.platform.startswith("linux"), "Hosted runner uses GNU time on Linux")
    def test_hosted_failure_keeps_full_selection_and_exit_code(self):
        result, args, leftovers = self.run_wrapper(True, 2)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(args[:4], ["-d", "memory_limit=512M", "vendor/bin/phpunit", "--log-events-text"])
        self.assertEqual(len(args), 5)
        self.assertIn("diagnostic-marker", result.stderr)
        self.assertIn("php-stderr-marker", result.stderr)
        self.assertIn("Maximum resident set size", result.stderr)
        self.assertFalse(leftovers)

    @unittest.skipUnless(sys.platform.startswith("linux"), "Hosted runner uses GNU time on Linux")
    def test_hosted_success_stays_successful(self):
        result, args, leftovers = self.run_wrapper(True, 0)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(len(args), 5)
        self.assertNotIn("diagnostic-marker", result.stderr)
        self.assertFalse(leftovers)

    def test_local_command_unchanged(self):
        result, args, leftovers = self.run_wrapper(False, 7)
        self.assertEqual(result.returncode, 7)
        self.assertEqual(args, ["-d", "memory_limit=512M", "vendor/bin/phpunit"])
        self.assertFalse(leftovers)


if __name__ == "__main__":
    unittest.main()
