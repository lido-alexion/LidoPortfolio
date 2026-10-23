import json
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

import launcher


class LauncherTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="ops004-launcher-")
        self.root = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def start(self, task_id, code, state_file=None, start_monitor=True):
        state_file = state_file or self.root / f"{task_id}.json"
        command = [sys.executable, "-c", code]
        if start_monitor:
            return launcher.start_task(state_file, task_id, command, "harmless fixture prompt", cwd=self.root)
        return launcher._start_task(state_file, task_id, command, "harmless fixture prompt",
                                    cwd=self.root, codex_command_enforced=False, start_monitor=False)

    def wait_for(self, task, predicate, timeout=3):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            result = task.observe()
            if predicate(result):
                return result
            time.sleep(.01)
        self.fail(f"timed out waiting for task state; latest={result.get('state')}")

    def test_wait_resolves_to_activity_and_terminal_receipt(self):
        code = ("import sys,time; sys.stdin.read(); "
                "print('{\"type\":\"input.required\"}',flush=True); time.sleep(.1); "
                "print('{\"type\":\"input.submitted\"}',flush=True); "
                "print('{\"type\":\"item.completed\",\"item\":{\"type\":\"agent_message\"}}',flush=True); time.sleep(.2)")
        task = self.start("wait-flow", code)
        self.wait_for(task, lambda r: r["state"] == "waiting_input")
        # Resolution is based on the latest transition, while useful work remains current.
        result = self.wait_for(task, lambda r: r["state"] == "running")
        self.assertIsNotNone(result["latest_activity_at"])
        receipt = task.wait(timeout=2)
        self.assertEqual(receipt["state"], "completed")
        self.assertEqual(receipt["returncode"], 0)
        self.assertTrue(Path(receipt["output_path"]).exists())

    def test_turn_started_is_not_useful_and_missing_timestamp_uses_observation_time(self):
        parsed = launcher.parse_jsonl_activity('{"type":"turn.started"}\n')
        self.assertFalse(parsed["useful_activity"])
        self.assertEqual(parsed["state"], "starting")
        task = self.start("no-ts", "import sys,time; sys.stdin.read(); print('{\"type\":\"item.completed\",\"item\":{\"type\":\"agent_message\"}}',flush=True); time.sleep(.3)")
        result = self.wait_for(task, lambda r: r["state"] == "running")
        self.assertTrue(result["latest_activity_at"])
        task.wait(timeout=2)
        self.assertTrue(task.proc.stdin.closed)

    def test_waiting_approval_is_a_blocker_for_unverified_caller_command(self):
        task = self.start("approval", "import sys,time; sys.stdin.read(); print('{\"type\":\"approval.requested\"}',flush=True); time.sleep(.3)")
        result = self.wait_for(task, lambda r: r["state"] == "waiting_approval")
        self.assertIn("policy is unverified", result["blocker"])
        self.assertEqual(task.wait(timeout=2)["state"], "completed")

    def test_latest_transition_clears_stale_input_and_approval_events(self):
        parsed = launcher.parse_jsonl_activity(
            '{"type":"item.completed","item":{"type":"agent_message"}}\n'
            '{"type":"input.required"}\n{"type":"input.submitted"}\n')
        self.assertEqual(parsed["state"], "starting")
        self.assertFalse(parsed["useful_activity"])
        approval = launcher.parse_jsonl_activity(
            '{"type":"approval.requested"}\n{"type":"approval.granted"}\n')
        self.assertEqual(approval["state"], "starting")
        activity = launcher.parse_jsonl_activity(
            '{"type":"turn.started","timestamp":"later-but-not-activity"}\n'
            '{"type":"item.completed","timestamp":"meaningful-time","item":{"type":"agent_message"}}\n')
        self.assertEqual(activity["latest_meaningful_at"], "meaningful-time")

    def test_sanitized_actual_codex_metadata_schema_and_activity_clock(self):
        fixture = Path(__file__).parent / "fixtures" / "codex-jsonl-metadata.jsonl"
        metadata = fixture.read_text()
        parsed = launcher.parse_jsonl_activity(metadata)
        self.assertTrue(parsed["schema_recognized"])
        self.assertEqual(parsed["meaningful_count"], 4)
        self.assertEqual(parsed["state"], "turn_ended")
        self.assertFalse(parsed["useful_activity"])
        self.assertIsNone(parsed["latest_meaningful_at"])

        code = ("import pathlib,sys,time; sys.stdin.read(); "
                f"sys.stdout.write(pathlib.Path({str(fixture)!r}).read_text()); sys.stdout.flush(); time.sleep(.2); "
                "print('{\"type\":\"thread.started\"}',flush=True); time.sleep(.2)")
        task = self.start("actual-schema", code)
        result = self.wait_for(task, lambda r: r["meaningful_event_count"] == 4)
        self.assertEqual(result["state"], "starting")
        self.assertIn("awaiting process exit", result["blocker"])
        first_activity_at = result["latest_activity_at"]
        self.assertTrue(first_activity_at)
        deadline = time.monotonic() + 2
        while time.monotonic() < deadline:
            output = Path(task.record["output_path"]).read_text()
            if output.count('"type":"thread.started"') > 1:
                break
            time.sleep(.01)
        later = task.observe()
        self.assertEqual(later["latest_activity_at"], first_activity_at)
        self.assertEqual(later["meaningful_event_count"], 4)
        self.assertEqual(task.wait(timeout=2)["state"], "completed")

    def test_unknown_event_schema_is_explicit(self):
        parsed = launcher.parse_jsonl_activity('{"type":"future.opaque_event"}\n')
        self.assertFalse(parsed["schema_recognized"])
        self.assertEqual(parsed["state"], "starting")

    def test_unresolved_startup_claim_blocks_replacement(self):
        base = self.root / "orphan-claim.json"
        pointer = base.with_suffix(base.suffix + ".task.json")
        pointer.write_text(json.dumps({"state": "starting", "pid": None, "start_ticks": None}))
        with self.assertRaisesRegex(RuntimeError, "not coherently finalized"):
            self.start("replacement", "import sys; sys.stdin.read()", base)

    def test_receipt_write_failure_reaps_new_child_and_keeps_failure_receipt(self):
        real_write = launcher._write_state
        real_popen = launcher.subprocess.Popen
        spawned = []
        calls = [0]
        def spy_popen(*args, **kwargs):
            proc = real_popen(*args, **kwargs)
            spawned.append(proc)
            return proc
        def fail_once(path, record):
            calls[0] += 1
            if calls[0] == 3:
                raise OSError("synthetic receipt failure")
            return real_write(path, record)
        with mock.patch.object(launcher.subprocess, "Popen", side_effect=spy_popen), \
             mock.patch.object(launcher, "_write_state", side_effect=fail_once):
            with self.assertRaisesRegex(OSError, "synthetic"):
                self.start("receipt-fail", "import sys,time; sys.stdin.read(); time.sleep(10)")
        self.assertEqual(len(spawned), 1)
        self.assertIsNotNone(spawned[0].poll())
        pointer = json.loads((self.root / "receipt-fail.json.task.json").read_text())
        self.assertEqual(pointer["state"], "failed")
        self.assertTrue(Path(pointer["output_path"]).exists())

    def test_nonzero_exit_is_failed_with_receipt(self):
        task = self.start("nonzero", "import sys; sys.stdin.read(); print('fixture failure',flush=True); sys.exit(7)")
        record = task.wait(timeout=2)
        self.assertEqual((record["state"], record["returncode"]), ("failed", 7))
        self.assertTrue(record["final_receipt"])

    def test_concurrent_start_claim_and_per_attempt_output_isolation(self):
        state_file = self.root / "same.json"
        barrier = threading.Barrier(3)
        results = []

        def contender(number):
            barrier.wait()
            try:
                results.append(self.start(f"attempt{number}", "import sys,time; sys.stdin.read(); time.sleep(.5)", state_file))
            except RuntimeError as exc:
                results.append(exc)

        workers = [threading.Thread(target=contender, args=(n,)) for n in (1, 2)]
        for worker in workers: worker.start()
        barrier.wait()
        for worker in workers: worker.join()
        tasks = [r for r in results if isinstance(r, launcher.OwnedTask)]
        self.assertEqual(len(tasks), 1)
        self.assertEqual(len([r for r in results if isinstance(r, RuntimeError)]), 1)
        first = tasks[0]
        first.wait(timeout=2)
        second = self.start("attempt-next", "import sys; sys.stdin.read(); print('new attempt',flush=True)", state_file)
        self.assertNotEqual(first.record["output_path"], second.record["output_path"])
        second.wait(timeout=2)
        self.assertIn("new attempt", Path(second.record["output_path"]).read_text())

    def test_delayed_finish_blocks_replacement_and_old_observe_cannot_repoint(self):
        base = self.root / "delayed.json"
        old = self.start("delayed-old", "import time; time.sleep(.1)", base, start_monitor=False)
        entered, release = threading.Event(), threading.Event()
        communicate = old.proc.communicate

        def delayed_communicate(*args, **kwargs):
            entered.set()
            if not release.wait(2):
                raise TimeoutError("fixture gate was not released")
            return communicate(*args, **kwargs)

        old.proc.communicate = delayed_communicate
        old.start_monitor()
        self.assertTrue(entered.wait(2))
        self.assertEqual(old.proc.wait(timeout=2), 0)
        with self.assertRaisesRegex(RuntimeError, "not coherently finalized"):
            self.start("too-early", "pass", base)
        release.set()
        self.assertEqual(old.wait(timeout=2)["state"], "completed")

        new = self.start("delayed-new", "import sys; sys.stdin.read(); time.sleep(.15)", base)
        new_uuid = new.record["task_uuid"]
        old.observe()
        self.assertEqual(json.loads(new.pointer_file.read_text())["task_uuid"], new_uuid)
        self.assertNotEqual(old.record["task_uuid"], new_uuid)
        new.wait(timeout=2)

    def test_recovery_refusal_and_escalation_failure_remain_unresolved(self):
        unavailable = self.start("pidfd-failure", "import time; time.sleep(10)", start_monitor=False)
        unavailable.proc.stdin.close()
        with mock.patch.object(launcher.os, "pidfd_open", side_effect=OSError("fixture pidfd refusal")):
            with self.assertRaisesRegex(RuntimeError, "pidfd unavailable"):
                unavailable.recover_cancel()
        record = unavailable.record
        self.assertNotIn(record["state"], launcher.TERMINAL)
        self.assertEqual(record["recovery_status"], "unresolved")
        self.assertIsNone(unavailable.proc.poll())
        with self.assertRaisesRegex(RuntimeError, "unresolved"):
            self.start("blocked-by-recovery", "pass", unavailable.pointer_file.with_name("pidfd-failure.json"))
        unavailable.recover_cancel(grace=.05)
        self.assertEqual(unavailable.proc.wait(timeout=2), -15)

        escalation = self.start("escalation-failure", "import signal,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); print('ready',flush=True); time.sleep(10)", start_monitor=False)
        escalation.proc.stdin.close()
        deadline = time.monotonic() + 2
        while time.monotonic() < deadline and "ready" not in Path(escalation.record["output_path"]).read_text(errors="replace"):
            time.sleep(.01)
        sender = launcher.signal.pidfd_send_signal
        calls = [0]
        def fail_kill(pidfd, sig, *args, **kwargs):
            calls[0] += 1
            if calls[0] == 1:
                return sender(pidfd, sig, *args, **kwargs)
            raise OSError("fixture escalation refusal")
        with mock.patch.object(launcher.signal, "pidfd_send_signal", side_effect=fail_kill):
            with self.assertRaisesRegex(RuntimeError, "recovery interrupted"):
                escalation.recover_cancel(grace=.03)
        record = escalation.record
        self.assertNotIn(record["state"], launcher.TERMINAL)
        self.assertEqual(record["recovery_status"], "unresolved")
        self.assertIsNone(escalation.proc.poll())
        escalation.recover_cancel(grace=.03)
        self.assertIsNotNone(escalation.proc.wait(timeout=2))

    def test_group_change_refusal_keeps_active_claim(self):
        task = self.start("group-change", "import time; time.sleep(10)", start_monitor=False)
        task.proc.stdin.close()
        real_members = launcher._group_members
        calls = [0]
        def changing_members(pgid):
            calls[0] += 1
            return {task.proc.pid} if calls[0] == 1 else {task.proc.pid, task.proc.pid + 1000000}
        with mock.patch.object(launcher, "_group_members", side_effect=changing_members):
            with self.assertRaisesRegex(RuntimeError, "identity changed"):
                task.recover_cancel()
        record = task.record
        self.assertEqual(record["recovery_status"], "unresolved")
        self.assertNotIn(record["state"], launcher.TERMINAL)
        self.assertIsNone(task.proc.poll())
        self.assertTrue(real_members(task.proc.pid))
        task.recover_cancel(grace=.05)
        task.proc.wait(timeout=2)

    def test_exact_owned_recovery_refuses_mismatch_and_unexpected_children(self):
        mismatch = self.start("mismatch", "import sys,time; sys.stdin.read(); time.sleep(5)")
        record = mismatch.record
        receipt_file = mismatch.state_file
        original = json.loads(receipt_file.read_text())
        receipt_file.write_text(json.dumps(dict(original, start_ticks=-1)))
        with self.assertRaisesRegex(RuntimeError, "identity"):
            mismatch.recover_cancel()
        receipt_file.write_text(json.dumps(original))
        mismatch.recover_cancel(grace=.05)
        mismatch.wait(timeout=2)

        code = ("import subprocess,sys,time; sys.stdin.read(); "
                "subprocess.Popen([sys.executable,'-c','import time; time.sleep(.7)']); time.sleep(.4)")
        with_child = self.start("child", code)
        time.sleep(.1)
        with self.assertRaisesRegex(RuntimeError, "children"):
            with_child.recover_cancel()
        with_child.proc.wait(timeout=2)
        with_child.wait(timeout=2)

    def test_recovery_and_finish_race_preserves_cancelled_receipt(self):
        task = self.start("race", "import signal,sys,time; sys.stdin.read(); signal.signal(signal.SIGTERM,signal.SIG_IGN); print('partial',flush=True); time.sleep(10)")
        deadline = time.monotonic() + 2
        while time.monotonic() < deadline and "partial" not in Path(task.record["output_path"]).read_text(errors="replace"):
            time.sleep(.01)
        receipt = task.recover_cancel(grace=.05)
        self.assertEqual(receipt["state"], "cancelled")
        self.assertIn("confirmed inactive", receipt["final_receipt"])
        self.assertIn("partial", Path(receipt["output_path"]).read_text())
        time.sleep(.05)
        self.assertEqual(task.record["state"], "cancelled")
        task.wait(timeout=2)
        self.assertTrue(task.proc.stdin.closed)

    def test_communication_exception_with_live_child_does_not_release_claim(self):
        task = self.start("comm-error", "import sys,time; sys.stdin.read(); print('still alive',flush=True); time.sleep(10)", start_monitor=False)
        original_wait = task.proc.wait
        first = [True]
        def wait_with_one_error(*args, **kwargs):
            if first[0]:
                first[0] = False
                raise OSError("synthetic fixture error")
            return original_wait(*args, **kwargs)
        task.proc.wait = wait_with_one_error
        task.proc.communicate = lambda *_a, **_k: (_ for _ in ()).throw(OSError("synthetic communication error"))
        task.start_monitor()
        time.sleep(.05)
        self.assertIsNone(task.proc.poll())
        self.assertIn("checking child status", task.record["blocker"])
        pointer = task.pointer_file
        prior_pointer = json.loads(pointer.read_text())
        pointer.write_text(json.dumps(dict(prior_pointer, state="failed")))
        with self.assertRaisesRegex(RuntimeError, "not coherently finalized"):
            base = task.pointer_file.with_name(task.pointer_file.name[:-len(".task.json")])
            self.start("replacement", "import sys; sys.stdin.read()", base)
        pointer.write_text(json.dumps(prior_pointer))
        # Recovery still rechecks identity and can wait using the same owned handle.
        task.recover_cancel(grace=.05)
        task.wait(timeout=2)
        self.assertEqual(task.record["state"], "cancelled")
        self.assertNotIn("harmless fixture prompt", json.dumps(task.record))

    def test_codex_builder_enforces_noninteractive_policy(self):
        argv = launcher.codex_command("/fixture/codex", cwd=self.root, output=self.root / "out.md")
        self.assertEqual(argv[1:4], ["-a", "never", "exec"])
        self.assertEqual(argv[-1], "-")
        with self.assertRaises(ValueError):
            launcher.codex_command("codex", cwd=self.root, output=self.root / "x", sandbox="unknown")
        with self.assertRaises(ValueError):
            launcher.codex_command("codex", cwd=self.root, output=self.root / "x", sandbox="danger-full-access")
        with self.assertRaises(ValueError):
            launcher.codex_command("codex", cwd=self.root, output=self.root / "x", model="other-model")

    def test_codex_adapter_uses_never_flags_and_records_final_location(self):
        executable = self.root / "fixture-codex"
        executable.write_text(
            "#!/usr/bin/env python3\n"
            "import json,pathlib,sys\n"
            "assert sys.argv[1:4] == ['-a','never','exec']\n"
            "assert sys.argv[sys.argv.index('-m')+1] == 'gpt-6-luna'\n"
            "assert sys.argv[sys.argv.index('-c')+1] == 'model_reasoning_effort=medium'\n"
            "assert '--add-dir' in sys.argv and sys.argv[sys.argv.index('--add-dir')+1] == sys.argv[sys.argv.index('--cd')+1]\n"
            "assert sys.argv[-1] == '-'\n"
            "sys.stdin.read()\n"
            "print(json.dumps({'type':'item.completed','item':{'type':'agent_message'}}),flush=True)\n"
            "pathlib.Path(sys.argv[sys.argv.index('-o')+1]).write_text('fixture final')\n")
        executable.chmod(0o700)
        final = self.root / "final.md"
        task = launcher.start_codex_task(self.root / "codex.json", "codex-fixture", executable,
                                         "SYNTH_SECRET_fixture", cwd=self.root, output=final,
                                         add_dirs=(self.root,))
        record = task.wait(timeout=2)
        self.assertEqual(record["approval_policy"], "never")
        self.assertEqual(record["final_result_path"], str(final))
        self.assertEqual(final.read_text(), "fixture final")
        self.assertNotIn("SYNTH_SECRET_fixture", json.dumps(record))


if __name__ == "__main__":
    unittest.main()
