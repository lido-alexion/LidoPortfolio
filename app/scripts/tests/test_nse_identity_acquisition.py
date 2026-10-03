"""Evidence acquisition must never mark HTML error responses as official proof."""

import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "nse_identity_acquire", Path(__file__).parents[1] / "nse_identity" / "acquire.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class EvidenceAcquisitionTest(unittest.TestCase):
    def test_error_page_remains_retryable_and_success_is_hash_verified_on_resume(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            urls = root / "urls.json"
            url = "https://archives.nseindia.com/content/circulars/test.pdf"
            urls.write_text(json.dumps([url]))

            def response(data):
                def run(command, **kwargs):
                    Path(command[command.index("--output") + 1]).write_bytes(data)
                    return subprocess.CompletedProcess(command, 0, "200", "")

                return run

            def invoke(data):
                with patch(
                    "sys.argv", ["acquire.py", str(root), str(urls)]
                ), patch.object(
                    MODULE.subprocess, "run", side_effect=response(data)
                ) as run, patch(
                    "builtins.print"
                ):
                    MODULE.main()
                return run.call_count

            invoke(b"<html>access denied</html>")
            self.assertEqual(
                "retryable_public_acquisition_failure",
                json.loads((root / "downloads.json").read_text())[url]["status"],
            )
            # A JSON official report is also supported; no optional PDF dependency required.
            invoke(b'{"data": []}')
            record = json.loads((root / "downloads.json").read_text())[url]
            self.assertEqual("acquired", record["status"])
            self.assertEqual(0, invoke(b"ignored cached response"))
            (root / record["artifact"]).write_bytes(b"corrupt")
            self.assertEqual(1, invoke(b'{"data": []}'))
            self.assertEqual(
                3,
                len(json.loads((root / "downloads.json").read_text())[url]["attempts"]),
            )

    def test_non_public_or_non_https_urls_are_rejected_before_downloading(self):
        for url in [
            "http://archives.nseindia.com/file",
            "https://example.com/file",
            "https://user:secret@archives.nseindia.com/file",
            "https://archives.nseindia.com:8443/file",
        ]:
            self.assertFalse(MODULE.allowed_url(url))
