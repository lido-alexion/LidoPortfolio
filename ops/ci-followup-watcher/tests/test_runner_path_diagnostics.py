import io
import socket
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from runner_path_diagnostics import (
    CONNECT_TIMEOUT_SECONDS,
    DIAGNOSTIC_WALL_TIMEOUT_SECONDS,
    MAX_ADDRESSES,
    diagnose,
    main,
    run_diagnostics,
    run_keyscan,
)


class RunnerPathDiagnosticsTests(unittest.TestCase):
    def test_dns_failure_is_sanitized_and_does_not_attempt_tcp(self):
        output = io.StringIO()
        with patch("runner_path_diagnostics.socket.getaddrinfo",
                   side_effect=socket.gaierror(socket.EAI_NONAME, "private.example")), \
             patch("runner_path_diagnostics.socket.socket") as make_socket, \
             patch("sys.stdout", output):
            diagnose("private.example")
        self.assertIn("stage=dns status=failed family=none address_count=0", output.getvalue())
        self.assertNotIn("private.example", output.getvalue())
        make_socket.assert_not_called()

    def test_tcp_outcomes_are_grouped_by_family_without_addresses(self):
        answers = [
            (socket.AF_INET, socket.SOCK_STREAM, 0, "", ("192.0.2.8", 22)),
            (socket.AF_INET6, socket.SOCK_STREAM, 0, "", ("2001:db8::8", 22, 0, 0)),
        ]
        v4 = Mock()
        v4.__enter__ = Mock(return_value=v4)
        v4.__exit__ = Mock(return_value=False)
        v6 = Mock()
        v6.__enter__ = Mock(return_value=v6)
        v6.__exit__ = Mock(return_value=False)
        v6.connect.side_effect = OSError("2001:db8::8")
        output = io.StringIO()
        with patch("runner_path_diagnostics.socket.getaddrinfo", return_value=answers), \
             patch("runner_path_diagnostics.socket.socket", side_effect=[v4, v6]), \
             patch("sys.stdout", output):
            diagnose("private.example")
        lines = output.getvalue()
        self.assertIn("stage=dns status=ok family=ipv4,ipv6 address_count=2", lines)
        self.assertIn(
            "stage=tcp22 status=partial family=ipv4,ipv6 "
            "reachable_family=ipv4 failed_family=ipv6 address_count=2 probe_count=2",
            lines,
        )
        self.assertNotIn("192.0.2.8", lines)
        self.assertNotIn("2001:db8::8", lines)
        v4.settimeout.assert_called_once_with(CONNECT_TIMEOUT_SECONDS)
        v6.settimeout.assert_called_once_with(CONNECT_TIMEOUT_SECONDS)

    def test_probe_cap_deduplicates_and_reserves_a_probe_for_each_family(self):
        v4_addresses = [
            (socket.AF_INET, socket.SOCK_STREAM, 0, "", (f"192.0.2.{index}", 22))
            for index in range(1, 11)
        ]
        answers = v4_addresses + [v4_addresses[0],
            (socket.AF_INET6, socket.SOCK_STREAM, 0, "", ("2001:db8::8", 22, 0, 0))]
        connections = []

        def make_socket(family, socktype, proto):
            connection = Mock()
            connection.__enter__ = Mock(return_value=connection)
            connection.__exit__ = Mock(return_value=False)
            if family == socket.AF_INET6:
                connection.connect.side_effect = OSError("private endpoint")
            connections.append((family, connection))
            return connection

        output = io.StringIO()
        with patch("runner_path_diagnostics.socket.getaddrinfo", return_value=answers), \
             patch("runner_path_diagnostics.socket.socket", side_effect=make_socket), \
             patch("sys.stdout", output):
            diagnose("private.example")

        lines = output.getvalue()
        self.assertIn("stage=dns status=ok family=ipv4,ipv6 address_count=11", lines)
        self.assertIn(f"probe_count={MAX_ADDRESSES}", lines)
        self.assertIn("reachable_family=ipv4 failed_family=ipv6", lines)
        self.assertEqual(MAX_ADDRESSES, len(connections))
        self.assertIn(socket.AF_INET6, [family for family, _ in connections])
        self.assertNotIn("192.0.2.", lines)
        self.assertNotIn("2001:db8::8", lines)

    def test_diagnostic_timeout_is_sanitized_and_bounded(self):
        output = io.StringIO()
        probe = Mock(side_effect=subprocess.TimeoutExpired("diagnostic", DIAGNOSTIC_WALL_TIMEOUT_SECONDS))
        with patch.dict("os.environ", {"STOXLA_SSH_PRIVATE_KEY": "private-key-secret"}), \
             patch("runner_path_diagnostics.subprocess.run", probe), \
             patch("sys.stdout", output):
            run_diagnostics("secret-host")
        self.assertIn("stage=diagnostic status=timeout exit_kind=deadline", output.getvalue())
        self.assertNotIn("secret-host", output.getvalue())
        kwargs = probe.call_args.kwargs
        self.assertEqual(DIAGNOSTIC_WALL_TIMEOUT_SECONDS, kwargs["timeout"])
        self.assertEqual({"PATH", "STOXLA_HOST"}, set(kwargs["env"]))
        self.assertEqual("secret-host", kwargs["env"]["STOXLA_HOST"])
        self.assertNotIn("private-key-secret", str(kwargs["env"]))

    def test_diagnostic_exception_and_nonzero_exit_are_sanitized(self):
        for failure, expected in (
            (OSError("secret-host failure"), "exit_kind=unavailable"),
            (Mock(returncode=9, stdout="", stderr="secret-host"), "exit_kind=nonzero"),
        ):
            with self.subTest(expected=expected):
                output = io.StringIO()
                side_effect = failure if isinstance(failure, BaseException) else None
                with patch("runner_path_diagnostics.subprocess.run",
                           side_effect=side_effect, return_value=failure), \
                     patch("sys.stdout", output):
                    run_diagnostics("secret-host")
                self.assertIn("stage=diagnostic status=failed", output.getvalue())
                self.assertIn(expected, output.getvalue())
                self.assertNotIn("secret-host", output.getvalue())

    def test_worker_exception_does_not_emit_exception_text(self):
        output = io.StringIO()
        with patch.dict("os.environ", {"STOXLA_HOST": "secret-host"}, clear=True), \
             patch("runner_path_diagnostics.diagnose", side_effect=RuntimeError("secret-host detail")), \
             patch("sys.stdout", output):
            self.assertEqual(0, main(["--diagnostic-worker"]))
        self.assertIn("stage=diagnostic status=failed exit_kind=exception", output.getvalue())
        self.assertNotIn("secret-host", output.getvalue())

    def test_keyscan_output_is_preserved_but_not_logged_on_success_or_failure(self):
        for code in (0, 1):
            with self.subTest(code=code), tempfile.TemporaryDirectory() as directory:
                known_hosts = Path(directory) / ".ssh" / "known_hosts"
                output = io.StringIO()

                def fake_run(args, stdout, stderr, check):
                    self.assertEqual(["ssh-keyscan", "-H", "secret-host"], args)
                    stdout.write(b"hashed-host ssh-ed25519 PUBLIC_KEY_MATERIAL\n")
                    self.assertIs(stderr, subprocess.DEVNULL)
                    self.assertFalse(check)
                    return Mock(returncode=code)

                with patch("runner_path_diagnostics.subprocess.run", side_effect=fake_run), \
                     patch("sys.stdout", output):
                    result = run_keyscan("secret-host", known_hosts)
                self.assertEqual(code, result)
                self.assertEqual(b"hashed-host ssh-ed25519 PUBLIC_KEY_MATERIAL\n", known_hosts.read_bytes())
                self.assertNotIn("secret-host", output.getvalue())
                self.assertNotIn("PUBLIC_KEY_MATERIAL", output.getvalue())
                self.assertIn("stage=hostkey", output.getvalue())

    def test_main_runs_keyscan_after_diagnostic_deadline_and_returns_scan_status(self):
        with tempfile.TemporaryDirectory() as directory:
            known_hosts = str(Path(directory) / "known_hosts")
            output = io.StringIO()
            calls = []

            def fake_run(*args, **kwargs):
                calls.append(args[0])
                if "--diagnostic-worker" in args[0]:
                    raise subprocess.TimeoutExpired("diagnostic", DIAGNOSTIC_WALL_TIMEOUT_SECONDS)
                kwargs["stdout"].write(b"verified-by-normal-scan\n")
                return Mock(returncode=1)

            with patch.dict("os.environ", {"STOXLA_HOST": "secret-host", "STOXLA_KNOWN_HOSTS": known_hosts},
                            clear=True), \
                 patch("runner_path_diagnostics.subprocess.run", side_effect=fake_run), \
                 patch("sys.stdout", output):
                result = main([])

            self.assertEqual(1, result)
            self.assertEqual(2, len(calls))
            self.assertEqual(["ssh-keyscan", "-H", "secret-host"], calls[1])
            self.assertTrue(Path(known_hosts).exists())
            self.assertEqual(b"verified-by-normal-scan\n", Path(known_hosts).read_bytes())
            self.assertIn("stage=diagnostic status=timeout", output.getvalue())
            self.assertIn("stage=hostkey status=failed exit_kind=nonzero", output.getvalue())
            self.assertNotIn("secret-host", output.getvalue())

    def test_main_normal_wiring_calls_bounded_diagnostics_then_original_scan(self):
        with tempfile.TemporaryDirectory() as directory:
            host = "private-host"
            known_hosts = str(Path(directory) / "known_hosts")
            calls = []

            def diagnostics(value):
                calls.append(("diagnostics", value))

            def keyscan(value, path):
                calls.append(("keyscan", value, path))
                return 0

            with patch.dict("os.environ", {"STOXLA_HOST": host, "STOXLA_KNOWN_HOSTS": known_hosts},
                            clear=True), \
                 patch("runner_path_diagnostics.run_diagnostics", side_effect=diagnostics), \
                 patch("runner_path_diagnostics.run_keyscan", side_effect=keyscan):
                self.assertEqual(0, main([]))
            self.assertEqual([("diagnostics", host), ("keyscan", host, Path(known_hosts))], calls)


if __name__ == "__main__":
    unittest.main()
