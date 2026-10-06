import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE = Path(__file__).parents[3] / "app/scripts/vps-health/monitor.py"
spec = importlib.util.spec_from_file_location("monitor", MODULE)
monitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(monitor)


class MonitorTests(unittest.TestCase):
    def test_fpm_status_uses_cgi_parameters_and_parses_status(self):
        output = "listen queue: 0\nactive processes: 3\nidle processes: 2\n"
        with patch.dict(os.environ, {"VPS_HEALTH_FPM_STATUS_ENABLED":"true",
                                     "VPS_HEALTH_FPM_STATUS_HOST":"127.0.0.1",
                                     "VPS_HEALTH_FPM_STATUS_PORT":"9001",
                                     "VPS_HEALTH_TELEGRAM_BOT_TOKEN":"test-secret"}), \
             patch.object(monitor.shutil, "which", return_value="/usr/bin/cgi-fcgi"), \
             patch.object(monitor.subprocess, "run", return_value=type("P", (), {"returncode":0,"stdout":output})()) as run:
            self.assertEqual(monitor.fpm_status(), {"listen queue":0,"active processes":3,"idle processes":2})
            env = run.call_args.kwargs["env"]
            self.assertEqual({k:env[k] for k in ("SCRIPT_NAME","SCRIPT_FILENAME","REQUEST_METHOD","QUERY_STRING","SERVER_PROTOCOL","GATEWAY_INTERFACE")},
                             {"SCRIPT_NAME":"/fpm-status","SCRIPT_FILENAME":"/fpm-status","REQUEST_METHOD":"GET","QUERY_STRING":"","SERVER_PROTOCOL":"HTTP/1.1","GATEWAY_INTERFACE":"CGI/1.1"})
            self.assertNotIn("VPS_HEALTH_TELEGRAM_BOT_TOKEN", env)

    def test_30_minute_summary_reads_full_window_counts_once(self):
        with patch.object(monitor, "log_counts", return_value={"502": 4}) as counts:
            result = monitor.summary_from([])
        counts.assert_called_once_with(1800)
        self.assertEqual(result["nginx_counts"], {"502": 4})

    def test_bounded_tail_respects_byte_limit(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "log"
            p.write_bytes(b"older line\nnewest\n")
            self.assertLessEqual(sum(len(x) + 1 for x in monitor.bounded_tail(p, 7)), 8)

    def test_access_scan_ignores_query_values_and_old_rows(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "access.log"
            old = "01/Jan/2000:00:00:00 +0000"
            recent = monitor.now().strftime("%d/%b/%Y:%H:%M:%S +0000")
            p.write_text(f'127.0.0.1 - - [{old}] "GET /old?secret=x HTTP/1.1" 502 2\n'
                         f'127.0.0.1 - - [{recent}] "GET /new?secret=x HTTP/1.1" 502 2\n')
            with patch.dict(os.environ, {"VPS_HEALTH_ACCESS_LOG": str(p)}):
                self.assertEqual(monitor.log_counts(), {"502": 1})
                self.assertEqual(monitor.top_paths(), [("/new", 1)])

    def test_load_and_fpm_saturation_require_previous_high_check(self):
        base = {"fpm": {"active processes": 4, "max children": 4}, "nginx": {},
                "root_used_percent": 20, "ram_available_percent": 80,
                "swap_used_percent": 0, "load_per_core": 3}
        with patch.dict(os.environ, {"VPS_HEALTH_LOAD_PER_CORE_CRITICAL": "2",
                                     "VPS_HEALTH_FPM_MAX_CHILDREN": "4"}):
            issues, flags = monitor.criticals(base, {"history": []})
            self.assertFalse(issues)
            issues, _ = monitor.criticals(base, {"history": [flags]})
            self.assertTrue(any("load" in x for x in issues))
            self.assertTrue(any("FPM active" in x for x in issues))

    def test_diagnostics_mask_ips_and_bound_excerpt(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "error.log"
            p.write_text("upstream failed http://example.test/path?token=secret from 10.2.3.4\n")
            with patch.dict(os.environ, {"VPS_HEALTH_ERROR_LOG": str(p),
                                         "VPS_HEALTH_SLOW_LOG": str(Path(td)/"absent"),
                                         "VPS_HEALTH_ACCESS_LOG": str(Path(td)/"absent")}):
                with patch.object(monitor, "collect", return_value={"ok": True}), \
                     patch.object(monitor, "run", return_value="pid comm\n1 init"):
                    d = monitor.diagnose()
            rendered = str(d)
            self.assertNotIn("secret", rendered)
            self.assertNotIn("10.2.3.4", rendered)

    def test_diagnostics_add_bounded_optional_sysstat(self):
        with patch.object(monitor, "collect", return_value={"ok": True}), \
             patch.object(monitor, "top_paths", return_value=[]), \
             patch.object(monitor, "bounded_tail", return_value=[]), \
             patch.object(monitor.shutil, "which", return_value="/usr/bin/tool"), \
             patch.object(monitor, "run", return_value="row\n"*100) as run:
            result = monitor.diagnose()
        self.assertEqual(set(result["sysstat"]), {"vmstat", "iostat", "pidstat"})
        self.assertEqual(run.call_count, 4)  # ps plus three optional samplers
        for rows in result["sysstat"].values():
            self.assertEqual(len(rows), 30)

    def test_log_line_sanitizes_bare_query_strings(self):
        line = monitor.sanitize_log_line('request /path?key=private status=500')
        self.assertNotIn("private", line)

    def test_state_and_snapshot_permissions_and_pruning(self):
        with tempfile.TemporaryDirectory() as td:
            directory = monitor.secure_dir(Path(td) / "state")
            path = monitor.save_diagnostic({"sample": 1}, directory)
            self.assertEqual(path.stat().st_mode & 0o777, 0o640)
            self.assertEqual(directory.stat().st_mode & 0o777, 0o750)
            self.assertTrue(path.exists())


if __name__ == "__main__":
    unittest.main()
