import json
import fcntl
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import watcher


class WatcherTests(unittest.TestCase):
    def test_failed_job_detected_while_other_jobs_run(self):
        jobs = [{"id": 7, "status": "completed", "conclusion": "failure"}, {"id": 8, "status": "in_progress", "conclusion": None}]
        self.assertEqual([7], [j["id"] for j in watcher.failed_jobs(jobs)])

    def test_stale_head_skips_without_jobs_request(self):
        class GH:
            def __init__(self): self.calls = []
            def get(self, path, cache_key=None):
                self.calls.append(path)
                if "/pulls/" in path: return ({"state": "open", "head": {"sha": "new", "ref": "feature"}}, True, {})
                raise AssertionError("unexpected request")
        with tempfile.TemporaryDirectory() as td:
            s = watcher.Supervisor({"repository": "lido-alexion/LidoPortfolio", "targets": []})
            s.audit_path = Path(td) / "audit.jsonl"
            s.state = {"events": {}, "etags": {}, "launches": [], "seen_runs": {"pr:1:1": "old"}}
            s.gh = GH()
            run = {"id": 1, "head_sha": "old", "head_branch": "feature", "event": "pull_request", "status": "in_progress", "updated_at": "new", "path": ".github/workflows/ci.yml"}
            self.assertEqual([], s.inspect_target({"name": "pr", "kind": "pull_request", "number": 240}, [run], True))
            self.assertEqual(1, len(s.gh.calls))

    def test_event_deduplication_identity_includes_attempt_and_job(self):
        base = {"run_id": 1, "attempt": 1, "head_sha": "abc", "job_id": 9}
        self.assertEqual(watcher.event_key(base), watcher.event_key(dict(base)))
        self.assertNotEqual(watcher.event_key(base), watcher.event_key({**base, "attempt": 2}))
        self.assertNotEqual(watcher.event_key(base), watcher.event_key({**base, "job_id": 10}))

    def test_api_error_propagates_without_data(self):
        gh = watcher.GitHub({"etags": {}})
        with patch("watcher.urllib.request.urlopen", side_effect=RuntimeError("offline")):
            with self.assertRaises(RuntimeError): gh.get("/repos/example")

    def test_atomic_state_round_trip(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "state.json"
            watcher.atomic_json(p, {"x": 1})
            self.assertEqual({"x": 1}, json.loads(p.read_text()))

    def test_restart_restores_event_state(self):
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "state.json"
            watcher.atomic_json(state, {"events": {"1:1:a:2": {"attempts": 1}}, "etags": {}, "launches": []})
            s = watcher.Supervisor({"repository": "lido-alexion/LidoPortfolio", "targets": []})
            s.state_path = state
            s.state = json.loads(state.read_text())
            self.assertEqual(1, s.state["events"]["1:1:a:2"]["attempts"])

    def test_file_lease_rejects_second_poller(self):
        with tempfile.TemporaryDirectory() as td:
            s = watcher.Supervisor({"repository": "lido-alexion/LidoPortfolio", "targets": []})
            s.lease_path = Path(td) / "watcher.lock"
            s.audit_path = Path(td) / "audit.jsonl"
            with s.lease_path.open("a+") as held:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                s.once()
            self.assertIn('"kind": "lease_busy"', s.audit_path.read_text())

    def test_child_timeout_sends_term_then_kill(self):
        class Proc:
            pid = 1234
            calls = 0
            def communicate(self, *_args, **_kwargs):
                self.calls += 1
                if self.calls <= 2: raise subprocess.TimeoutExpired("codex", 1)
                return ("", None)
        proc = Proc()
        with patch("watcher.os.killpg") as kill:
            output, timed_out = watcher.bounded_communicate(proc, "prompt", timeout=1, grace=1)
        self.assertTrue(timed_out)
        self.assertEqual("", output)
        self.assertEqual([1234, 1234], [c.args[0] for c in kill.call_args_list])

    def test_fault_backoff_grows_and_poll_fails_closed(self):
        with tempfile.TemporaryDirectory() as td:
            s = watcher.Supervisor({"repository": "lido-alexion/LidoPortfolio", "targets": [], "poll_seconds": 120})
            s.state_path = Path(td) / "state.json"
            s.audit_path = Path(td) / "audit.jsonl"
            s.heartbeat_path = Path(td) / "heartbeat.json"
            s.lease_path = Path(td) / "watcher.lock"
            s.fetch_runs = lambda: (_ for _ in ()).throw(RuntimeError("offline"))
            with self.assertRaises(RuntimeError): s.once()
            self.assertEqual(240, s.state["backoff"])
            self.assertEqual("fault", json.loads(s.heartbeat_path.read_text())["status"])

    def test_config_enforces_poll_floor_and_single_pr_allowlist(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "c.json"
            p.write_text('{"repository":"lido-alexion/LidoPortfolio","poll_seconds":60,"targets":[{"kind":"pull_request"}]}')
            with self.assertRaises(ValueError): watcher.load_config(p)

    def test_green_review_dedup_key_is_run_attempt_head(self):
        run = {"id": 3, "run_attempt": 2, "head_sha": "def"}
        event = {"run_id": run["id"], "attempt": run["run_attempt"], "head_sha": run["head_sha"], "job_id": "review"}
        self.assertEqual("3:2:def:review", watcher.event_key(event))


if __name__ == "__main__":
    unittest.main()
