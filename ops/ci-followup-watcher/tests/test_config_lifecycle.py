import json
import threading
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import watcher


def config(extra=None):
    targets = [
        {"name": "pr-240-disabled", "kind": "pull_request", "number": 240, "workflow": "ci.yml", "worktree": ""},
        {"name": "nightly", "kind": "nightly_master", "workflow": "journey-nightly.yml", "worktree": ""},
    ]
    if extra is not None:
        targets.insert(1, {"name": "pr-extra", "kind": "pull_request", "number": extra, "workflow": "ci.yml", "worktree": "/owned/checkout"})
    return {"repository": "lido-alexion/LidoPortfolio", "poll_seconds": 300, "targets": targets}


class ConfigLifecycleTests(unittest.TestCase):
    def setup_supervisor(self, td, conf=None):
        root = Path(td)
        path = root / "config.json"
        conf = conf or config()
        path.write_text(json.dumps(conf))
        sup = watcher.Supervisor(conf, config_path=path, config_revision=watcher.read_snapshot(path)[1])
        sup.state_path = root / "state.json"
        sup.audit_path = root / "audit.jsonl"
        sup.heartbeat_path = root / "heartbeat.json"
        sup.lease_path = root / "watcher.lock"
        return sup, path

    def test_valid_change_applies_at_leased_poll_and_records_distinct_sources(self):
        with tempfile.TemporaryDirectory() as td:
            s, path = self.setup_supervisor(td)
            old_revision = s.config_record["revision"]
            path.write_text(json.dumps(config(241)))
            s.fetch_runs = lambda: ([], False)
            s.dispatch = lambda *args: None
            s.once()
            self.assertNotEqual(old_revision, s.config_record["revision"])
            self.assertEqual(241, s.config["targets"][1]["number"])
            state = json.loads(s.state_path.read_text())
            self.assertEqual(state["effective_config"]["revision"], state["config_sources"]["disk_revision"])
            self.assertIn("not an allowlist", state["config_sources"]["global_discovery"])
            self.assertEqual(state["effective_config"]["targets"], state["config_sources"]["effective_dispatch_allowlist"])

    def test_invalid_edit_retains_last_valid_and_error_does_not_echo_content(self):
        with tempfile.TemporaryDirectory() as td:
            s, path = self.setup_supervisor(td)
            previous = s.config_record.copy()
            path.write_text('{"repository":"DO_NOT_ECHO_SECRET"')
            s.fetch_runs = lambda: ([], False)
            s.dispatch = lambda *args: None
            s.once()
            self.assertEqual(previous, s.config_record)
            audit = s.audit_path.read_text()
            self.assertIn('config_reload_invalid', audit)
            self.assertNotIn('DO_NOT_ECHO_SECRET', audit)
            path.write_bytes(b'\xff\xfe')
            s.once()
            self.assertEqual(previous, s.config_record)
            malformed_type = config()
            malformed_type["targets"][0]["report_only"] = "true"
            path.write_text(json.dumps(malformed_type))
            s.once()
            self.assertEqual(previous, s.config_record)

    def test_removed_or_disabled_pending_target_is_retained_until_exact_target_reenabled(self):
        with tempfile.TemporaryDirectory() as td:
            s, path = self.setup_supervisor(td)
            key = "8:1:abc:9"
            old = {"name": "pr-extra", "kind": "pull_request", "number": 241, "workflow": "ci.yml", "worktree": "/old/checkout"}
            s.state["pending"] = {key: {"target": old, "run": {"id": 8, "head_sha": "abc"}, "job": {"id": 9}}}
            s.state["events"] = {key: {"attempts": 1, "status": "deferred"}, "done": {"attempts": 1, "status": "finished"}}
            # Same display name with a different PR does not inherit queued authority.
            path.write_text(json.dumps(config(242)))
            calls = []
            s.fetch_runs = lambda: ([], False)
            s.dispatch = lambda *args: calls.append(args)
            s.once()
            state = json.loads(s.state_path.read_text())
            self.assertIn(key, state["pending"])
            self.assertIn("done", state["events"])
            self.assertEqual([], calls)
            # Exact target is present but blank worktree disables dispatch; pending attempt is unchanged.
            conf = config(241)
            conf["targets"][1]["worktree"] = ""
            path.write_text(json.dumps(conf))
            s.once()
            self.assertEqual([], calls)
            self.assertEqual(1, state["events"][key]["attempts"])
            conf["targets"][1]["worktree"] = "/owned/checkout"
            conf["targets"][1]["enabled"] = False
            path.write_text(json.dumps(conf))
            s.once()
            self.assertEqual([], calls)
            # Reenable the same identity and strengthen to report-only. Current policy is passed to dispatch.
            conf["targets"][1]["enabled"] = True
            conf["targets"][1]["worktree"] = "/new/owned/checkout"
            conf["targets"][1]["report_only"] = True
            path.write_text(json.dumps(conf))
            def dispatch(target, run, job):
                calls.append((target.copy(), run, job))
                s.state["events"][key]["status"] = "finished"
                s.state["pending"].pop(key, None)
            s.dispatch = dispatch
            s.once()
            self.assertEqual(1, len(calls))
            self.assertEqual("/new/owned/checkout", calls[0][0]["worktree"])
            self.assertTrue(calls[0][0]["report_only"])
            self.assertEqual(1, s.state["events"][key]["attempts"])
            s.once()
            self.assertEqual(1, len(calls))

    def test_target_allowlist_validation_rejects_duplicates_and_malformed_entries(self):
        bad = config()
        bad["targets"].append(dict(bad["targets"][0]))
        with self.assertRaises(ValueError):
            watcher.validate_config(bad)
        with self.assertRaises(ValueError):
            watcher.validate_config({"repository": "x", "targets": ["secret"]})

    def test_owned_lease_prevents_a_competing_poll_from_reloading(self):
        with tempfile.TemporaryDirectory() as td:
            s, path = self.setup_supervisor(td)
            revision = s.config_record["revision"]
            path.write_text(json.dumps(config(241)))
            with s.lease_path.open("a+") as owner:
                import fcntl
                fcntl.flock(owner, fcntl.LOCK_EX | fcntl.LOCK_NB)
                s.once()
            self.assertEqual(revision, s.config_record["revision"])
            self.assertIn('"kind": "lease_busy"', s.audit_path.read_text())

    def test_paused_marker_prevents_reload_dispatch_and_preserves_pending(self):
        with tempfile.TemporaryDirectory() as td:
            initial = config(241)
            s, path = self.setup_supervisor(td, initial)
            revision = s.config_record["revision"]
            root = Path(td) / "root"
            root.mkdir()
            (root / "PAUSED").touch()
            key = "8:1:abc:9"
            s.state["pending"] = {key: {"target": initial["targets"][1], "run": {"id": 8, "head_sha": "abc"}, "job": None}}
            s.fetch_runs = lambda: ([], False)
            calls = []
            s.dispatch = lambda *args: calls.append(args)
            path.write_text(json.dumps(config(242)))
            with patch.object(watcher, "ROOT", root):
                s.once()
            self.assertEqual(revision, s.config_record["revision"])
            self.assertIn(key, s.state["pending"])
            self.assertEqual([], calls)
            self.assertEqual("paused", json.loads(s.heartbeat_path.read_text())["status"])

    def test_stopped_marker_skips_reload_and_discovery_but_keeps_receipt(self):
        with tempfile.TemporaryDirectory() as td:
            initial = config(241)
            s, path = self.setup_supervisor(td, initial)
            revision = s.config_record["revision"]
            root = Path(td) / "root"
            root.mkdir()
            (root / "STOPPED").touch()
            key = "8:1:abc:9"
            s.state["pending"] = {key: {"target": initial["targets"][1], "run": {"id": 8}, "job": None}}
            s.fetch_runs = lambda: (_ for _ in ()).throw(AssertionError("STOPPED must skip discovery"))
            path.write_text(json.dumps(config(242)))
            with patch.object(watcher, "ROOT", root):
                s.once()
            self.assertEqual(revision, s.config_record["revision"])
            self.assertIn(key, s.state["pending"])
            self.assertEqual("stopped", json.loads(s.heartbeat_path.read_text())["status"])

    def test_config_change_waits_for_synchronous_child_to_finish(self):
        with tempfile.TemporaryDirectory() as td:
            initial = config(241)
            s, path = self.setup_supervisor(td, initial)
            old_revision = s.config_record["revision"]
            key = "8:1:abc:9"
            s.state["pending"] = {key: {"target": initial["targets"][1], "run": {"id": 8, "head_sha": "abc"}, "job": {"id": 9}}}
            s.state["events"] = {key: {"attempts": 1, "status": "leased"}}
            s.fetch_runs = lambda: ([], False)
            entered = threading.Event()
            release = threading.Event()
            def child(*_args):
                entered.set()
                release.wait(timeout=3)
                s.state["events"][key]["status"] = "finished"
                s.state["pending"].pop(key, None)
            s.dispatch = child
            thread = threading.Thread(target=s.once)
            thread.start()
            self.assertTrue(entered.wait(timeout=2))
            path.write_text(json.dumps(config(242)))
            self.assertEqual(old_revision, s.config_record["revision"])
            release.set()
            thread.join(timeout=3)
            self.assertFalse(thread.is_alive())
            self.assertEqual(old_revision, s.config_record["revision"])
            s.once()
            self.assertNotEqual(old_revision, s.config_record["revision"])


if __name__ == "__main__":
    unittest.main()
