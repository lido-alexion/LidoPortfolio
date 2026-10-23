"""Bounded local adapter for starting, observing, and recovering an owned task."""
from __future__ import annotations

import fcntl
import json
import os
import re
import signal
import subprocess
import threading
import time
import uuid
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

TERMINAL = {"completed", "failed", "cancelled"}
STATES = {"starting", "waiting_input", "waiting_approval", "running", *TERMINAL}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def process_start_ticks(pid: int) -> int | None:
    """Linux /proc starttime (field 22), robust to spaces in comm."""
    try:
        raw = Path(f"/proc/{pid}/stat").read_text()
        return int(raw[raw.rfind(")") + 2:].split()[19])
    except (OSError, ValueError, IndexError):
        return None


def parse_jsonl_activity(text: str) -> dict[str, Any]:
    """Interpret only observed Codex JSONL metadata transitions, in stream order."""
    state = "starting"
    useful = False
    latest_meaningful_at = None
    meaningful_count = 0
    schema_recognized = False
    known_events = {"thread.started", "turn.started", "turn.completed", "turn.failed",
                    "item.started", "item.completed", "error", "input.required",
                    "waiting_for_input", "prompt", "input.submitted", "approval.requested",
                    "approval.resolved", "approval.granted"}
    for line in text.splitlines():
        try:
            event = json.loads(line)
        except (ValueError, TypeError):
            continue
        if not isinstance(event, dict):
            continue
        kind = str(event.get("type", "")).lower()
        item = event.get("item", {})
        item_type = str(item.get("type", "")).lower() if isinstance(item, dict) else ""
        schema_recognized = schema_recognized or kind in known_events
        if kind in {"input.required", "waiting_for_input", "prompt"}:
            state = "waiting_input"
            useful = False
        elif kind in {"input.submitted", "approval.resolved", "approval.granted"}:
            state = "starting"
            useful = False
        elif "approval" in kind or "approval" in item_type:
            state = "waiting_approval"
            useful = False
        elif kind == "turn.started":
            state, useful = "starting", False
        elif kind == "item.started" and item_type in {"command_execution", "file_change"}:
            useful = True
            state = "running"
            ts = event.get("timestamp") or event.get("time")
            meaningful_count += 1
            latest_meaningful_at = ts if isinstance(ts, str) else None
        elif kind == "item.completed" and item_type in {"agent_message", "command_execution", "file_change"}:
            useful = True
            state = "running"
            ts = event.get("timestamp") or event.get("time")
            meaningful_count += 1
            latest_meaningful_at = ts if isinstance(ts, str) else None
        elif kind == "turn.completed":
            # A completed turn is not a completed process or active model work.
            state, useful = "turn_ended", False
        elif kind in {"turn.failed", "error"}:
            state, useful = "turn_ended", False
    return {"state": state, "useful_activity": useful,
            "latest_meaningful_at": latest_meaningful_at,
            "meaningful_count": meaningful_count,
            "schema_recognized": schema_recognized}


@contextmanager
def _file_lock(path: Path) -> Iterator[None]:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a+") as stream:
        fcntl.flock(stream, fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(stream, fcntl.LOCK_UN)


def _read_state(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text())


def _write_state(path: Path, record: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(f".{path.name}.{os.getpid()}.{threading.get_ident()}.{uuid.uuid4().hex}.tmp")
    try:
        with tmp.open("x") as stream:
            json.dump(record, stream, sort_keys=True, indent=2)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(tmp, path)
        dir_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    finally:
        tmp.unlink(missing_ok=True)


def _proc_pgid(pid: int) -> int | None:
    try:
        return os.getpgid(pid)
    except (OSError, ProcessLookupError):
        return None


def _group_members(pgid: int) -> set[int]:
    found = set()
    for entry in Path("/proc").iterdir():
        if entry.name.isdigit() and _proc_pgid(int(entry.name)) == pgid:
            found.add(int(entry.name))
    return found


class OwnedTask:
    def __init__(self, state_file: Path, proc: subprocess.Popen | None = None,
                 pointer_file: Path | None = None, lock_file: Path | None = None):
        self.state_file, self.proc = state_file, proc
        self.pointer_file = pointer_file
        self.lock_file = lock_file or (pointer_file or state_file).with_suffix((pointer_file or state_file).suffix + ".lock")
        self._thread_lock = threading.RLock()
        self._done = threading.Event()
        self._monitor_started = False

    @property
    def record(self) -> dict[str, Any]:
        with self._thread_lock, _file_lock(self.lock_file):
            return _read_state(self.state_file)

    def _identity_matches(self, record: dict[str, Any]) -> bool:
        pid, ticks = record.get("pid"), record.get("start_ticks")
        return (isinstance(pid, int) and isinstance(ticks, int)
                and process_start_ticks(pid) == ticks
                and _proc_pgid(pid) == record.get("pgid") == pid)

    def _persist(self, record: dict[str, Any]) -> None:
        _write_state(self.state_file, record)
        if self.pointer_file and self.pointer_file.exists():
            try:
                current = _read_state(self.pointer_file)
            except (OSError, ValueError):
                return
            # Callers hold the shared lock. A stale handle may update its own
            # receipt, but can never roll the current-attempt pointer backward.
            if current.get("task_uuid") == record.get("task_uuid"):
                _write_state(self.pointer_file, record)

    def _mark_recovery_unresolved(self, blocker: str) -> dict[str, Any]:
        with self._thread_lock, _file_lock(self.lock_file):
            record = _read_state(self.state_file)
            if record["state"] not in TERMINAL:
                record["recovery_status"] = "unresolved"
                record["blocker"] = blocker
                self._persist(record)
            return record

    def identity_matches(self) -> bool:
        return self._identity_matches(self.record)

    def wait(self, timeout: float | None = None) -> dict[str, Any]:
        """Wait for this adapter's communication/final-receipt thread."""
        if not self._done.wait(timeout):
            raise TimeoutError("owned task has no final adapter receipt yet")
        return self.record

    def start_monitor(self) -> None:
        if self.proc is None:
            raise RuntimeError("task has no owned subprocess handle")
        if self._monitor_started:
            raise RuntimeError("task monitor already started")
        thread = threading.Thread(target=self._finish, name=f"owned-task-{self.record['task_uuid'][:8]}", daemon=True)
        try:
            thread.start()
            self._monitor_started = True
        except RuntimeError:
            # Preserve prompt delivery and child reaping even if a monitor thread cannot start.
            self._finish()

    def observe(self) -> dict[str, Any]:
        with self._thread_lock, _file_lock(self.lock_file):
            record = _read_state(self.state_file)
            if record["state"] in TERMINAL:
                record["observed_at"] = utc_now()
                self._persist(record)
                return record
            if record.get("recovery_status") in {"in_progress", "unresolved"}:
                return record
            output = Path(record["output_path"])
            try:
                content = output.read_text(errors="replace")
            except OSError:
                content = ""
            activity = parse_jsonl_activity(content)
            alive = self._identity_matches(record)
            if activity["state"] in {"waiting_input", "waiting_approval"} and alive:
                record["state"] = activity["state"]
                if activity["state"] == "waiting_input":
                    record["blocker"] = "input required"
                elif record.get("approval_policy") == "never":
                    record["blocker"] = "approval interaction observed; policy is never and no approval was issued"
                else:
                    record["blocker"] = "approval interaction observed; caller command policy is unverified"
            elif activity["state"] == "turn_ended" and alive:
                record["state"], record["blocker"] = "starting", "model turn ended; awaiting process exit receipt"
            elif activity["useful_activity"] and alive:
                record["state"], record["blocker"] = "running", None
            elif alive:
                record["state"] = "starting"
                record["blocker"] = ("unrecognized JSONL event schema; task activity is unknown"
                                     if content and not activity["schema_recognized"]
                                     else "no meaningful model activity observed yet")
            elif not record.get("final_receipt"):
                # Use the exact Popen wait status when available; a reloaded adapter
                # cannot infer success from a missing PID and stays explicitly failed.
                rc = self.proc.poll() if self.proc else None
                if self.proc and rc is None:
                    record["state"] = "starting"
                    record["blocker"] = "process identity unavailable; awaiting exact child exit receipt"
                else:
                    record["state"] = "completed" if rc == 0 else "failed"
                    record["blocker"] = None if rc == 0 else "owned process inactive; exit status unavailable"
                    record["returncode"] = rc
                    record["finished_at"] = utc_now()
                    record["final_receipt"] = "exit status recorded; output retained" if rc is not None else "process inactive; exit status unavailable; output retained"
            record["observed_at"] = utc_now()
            previous_count = int(record.get("meaningful_event_count", 0))
            if activity["meaningful_count"] > previous_count:
                record["latest_activity_at"] = activity["latest_meaningful_at"] or utc_now()
            record["meaningful_event_count"] = max(previous_count, activity["meaningful_count"])
            self._persist(record)
            return record

    def _finish(self) -> None:
        try:
            self._finish_impl()
        finally:
            try:
                if self.proc and self.proc.stdin is not None and not self.proc.stdin.closed:
                    self.proc.stdin.close()
            except BaseException:
                pass
            self._done.set()

    def _finish_impl(self) -> None:
        assert self.proc is not None
        try:
            self.proc.communicate(self._prompt)
            rc = self.proc.returncode
            final_state = "completed" if rc == 0 else "failed"
            receipt = "exit status recorded; output retained"
            blocker = None if rc == 0 else "child exited unsuccessfully"
        except BaseException as exc:
            # A communication exception is not evidence the child exited; keep the claim and retry wait.
            try:
                if self.proc.stdin is not None and not self.proc.stdin.closed:
                    self.proc.stdin.close()
            except BaseException:
                pass
            with self._thread_lock, _file_lock(self.lock_file):
                current = _read_state(self.state_file)
                if current.get("recovery_status") != "in_progress":
                    current.update(state="starting", blocker=f"launcher communication failed ({type(exc).__name__}); checking child status")
                    self._persist(current)
            while self.proc.poll() is None:
                try:
                    self.proc.wait(timeout=0.1)
                except subprocess.TimeoutExpired:
                    continue
                except BaseException:
                    time.sleep(0.05)
            rc = self.proc.returncode
            final_state = ("completed" if rc == 0 else "failed") if rc is not None else None
            receipt = "exit status recorded after communication error; output retained" if rc is not None else None
            blocker = (None if rc == 0 else "child exited unsuccessfully") if rc is not None else f"launcher communication failed ({type(exc).__name__}); child may remain active"
        try:
            if self.proc.stdin is not None and not self.proc.stdin.closed:
                self.proc.stdin.close()
        except BaseException:
            pass
        with self._thread_lock, _file_lock(self.lock_file):
            record = _read_state(self.state_file)
            if record.get("recovery_status") == "in_progress":
                record.update(observed_returncode=rc,
                              blocker="owned process exited; recovery owner is confirming the process group")
                self._persist(record)
                return
            if record["state"] in TERMINAL:
                return
            if _group_members(record["pgid"]):
                record.update(state="failed", recovery_status="unresolved",
                              blocker="owned process exited but its process group still has members",
                              returncode=rc, finished_at=utc_now())
                self._persist(record)
                return
            if final_state is not None:
                record.update(state=final_state, returncode=rc, blocker=blocker,
                              finished_at=utc_now(), final_receipt=receipt)
            else:
                record.update(state="failed", blocker=blocker)
            if record.get("recovery_status") == "unresolved":
                record["recovery_status"] = "resolved"
            self._persist(record)

    def recover_cancel(self, grace: float = 2.0) -> dict[str, Any]:
        """Cancel only the exact owned single-member process group, rechecking before signal."""
        if not hasattr(os, "pidfd_open") or not hasattr(signal, "pidfd_send_signal"):
            self._mark_recovery_unresolved("safe pidfd signalling unavailable; owned process may still be active")
            raise RuntimeError("safe pidfd signalling is unavailable; no cancellation attempted")
        pidfd = None
        signal_sent = False
        with self._thread_lock, _file_lock(self.lock_file):
            record = _read_state(self.state_file)
            if record["state"] in TERMINAL:
                if self._identity_matches(record):
                    raise RuntimeError("terminal receipt conflicts with active owned process")
                return record
            if not self._identity_matches(record):
                record["recovery_status"] = "unresolved"
                record["blocker"] = "owned process identity mismatch; recovery refused and status unresolved"
                self._persist(record)
                raise RuntimeError("owned process identity changed or is inactive; refusing cancellation")
            if _group_members(record["pgid"]) != {record["pid"]}:
                record["recovery_status"] = "unresolved"
                record["blocker"] = "owned process group has unexpected members; no signal sent"
                self._persist(record)
                raise RuntimeError("owned process group has unexpected children; refusing cancellation")
            try:
                # pidfd binds later signals to this process instance even if its PID is reused.
                pidfd = os.pidfd_open(record["pid"], 0)
            except (AttributeError, OSError) as exc:
                record["recovery_status"] = "unresolved"
                record["blocker"] = "pidfd unavailable; owned process may still be active; no signal sent"
                self._persist(record)
                raise RuntimeError("pidfd unavailable; no cancellation signal sent") from exc
            if not self._identity_matches(record) or _group_members(record["pgid"]) != {record["pid"]}:
                os.close(pidfd)
                record["recovery_status"] = "unresolved"
                record["blocker"] = "owned process/group changed before cancellation; no signal sent"
                self._persist(record)
                raise RuntimeError("owned identity changed before cancellation; refusing action")
            record["recovery_status"] = "in_progress"
            record["blocker"] = "exact-owned cancellation requested; awaiting inactive confirmation"
            self._persist(record)
        try:
            try:
                signal.pidfd_send_signal(pidfd, signal.SIGTERM)
                signal_sent = True
            except ProcessLookupError:
                pass  # This exact process exited before the signal; do not claim cancellation.
            deadline = time.monotonic() + grace
            while signal_sent and time.monotonic() < deadline and self._identity_matches(record):
                time.sleep(0.02)
            if signal_sent and self._identity_matches(record):
                if not self._identity_matches(record) or _group_members(record["pgid"]) != {record["pid"]}:
                    raise RuntimeError("process identity/group changed during recovery; refusing escalation")
                signal.pidfd_send_signal(pidfd, signal.SIGKILL)
                signal_sent = True
        except BaseException as exc:
            self._mark_recovery_unresolved(f"owned recovery interrupted ({type(exc).__name__}); process may still be active")
            raise RuntimeError("owned recovery interrupted; unresolved task remains claimed") from exc
        finally:
            if pidfd is not None:
                try:
                    os.close(pidfd)
                except OSError as exc:
                    self._mark_recovery_unresolved(
                        f"pidfd close failed ({type(exc).__name__}); process status remains unresolved")
                    raise RuntimeError("pidfd close failed; recovery remains unresolved") from exc
        if self.proc and self._monitor_started:
            if not self._done.wait(timeout=max(1.0, grace + 1)):
                self._mark_recovery_unresolved("task monitor did not confirm child exit; recovery unresolved")
                raise RuntimeError("task monitor did not confirm child exit; cancellation not finalized")
        elif self.proc:
            try:
                self.proc.wait(timeout=max(1.0, grace + 1))
            except BaseException as exc:
                self._mark_recovery_unresolved(f"owned recovery wait failed ({type(exc).__name__}); process status unresolved")
                raise RuntimeError("owned recovery wait failed; task remains claimed") from exc
        if self.proc is not None and self.proc.poll() is None:
            self._mark_recovery_unresolved("owned child remains active; recovery unresolved")
            raise RuntimeError("owned child remains active; cancellation not finalized")
        if self._identity_matches(record):
            self._mark_recovery_unresolved("owned process remains active; recovery unresolved")
            raise RuntimeError("owned process remains active; cancellation not finalized")
        if _group_members(record["pgid"]):
            self._mark_recovery_unresolved("process group still has members after owner exit; recovery unresolved")
            raise RuntimeError("process group still has members after owner exit; cancellation not finalized")
        with self._thread_lock, _file_lock(self.lock_file):
            current = _read_state(self.state_file)
            if current["state"] in TERMINAL:
                return current
            if not signal_sent:
                rc = self.proc.returncode if self.proc else None
                current.update(state="completed" if rc == 0 else "failed", returncode=rc,
                               blocker=None if rc == 0 else "process exited before cancellation signal; status unavailable",
                               finished_at=utc_now(), final_receipt="process exit confirmed before cancellation signal; output retained",
                               recovery_status="resolved")
                self._persist(current)
                return current
            current.update(state="cancelled", recovery_status="resolved", blocker=None, finished_at=utc_now(),
                           final_receipt="owned process confirmed inactive after cancellation; partial output retained")
            self._persist(current)
            return current


def codex_command(executable: str | Path, *, cwd: Path, output: Path,
                  sandbox: str = "read-only", add_dirs: tuple[Path, ...] = (),
                  model: str = "gpt-6-luna", reasoning_effort: str = "medium") -> list[str]:
    """Build the constrained Codex invocation used by the existing watcher lane."""
    if sandbox not in {"read-only", "workspace-write"}:
        raise ValueError("unsupported Codex sandbox mode")
    if model != "gpt-6-luna" or reasoning_effort != "medium":
        raise ValueError("this launcher lane is fixed to gpt-6-luna with medium reasoning")
    command = [str(executable), "-a", "never", "exec", "-m", model,
               "-c", f"model_reasoning_effort={reasoning_effort}", "--json",
               "--cd", str(cwd), "--sandbox", sandbox]
    for directory in add_dirs:
        command.extend(("--add-dir", str(directory)))
    command.extend(("-o", str(output), "-"))
    return command


def _start_task(state_file: Path, task_id: str, command: list[str], prompt: str,
                *, cwd: Path, codex_command_enforced: bool, start_monitor: bool,
                final_result_path: Path | None = None) -> OwnedTask:
    """Atomically claim a task identity, spawn once, supply prompt, and close stdin.

    Arbitrary commands remain supported for adapters and fixtures; their approval
    behavior is explicitly recorded as caller-supplied/unverified.
    """
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}", task_id):
        raise ValueError("task_id must be a short opaque identifier")
    if not command or not isinstance(prompt, str):
        raise ValueError("command and prompt are required")
    state_file = state_file.resolve()
    lock_file = state_file.with_suffix(state_file.suffix + ".lock")
    pointer_file = state_file.with_suffix(state_file.suffix + ".task.json")
    with _file_lock(lock_file):
        if pointer_file.exists():
            prior = _read_state(pointer_file)
            pid, ticks = prior.get("pid"), prior.get("start_ticks")
            # A terminal label cannot release a still-identical PID. Group mismatch
            # is a refusal for recovery, but still blocks replacement while alive.
            same_process = isinstance(pid, int) and isinstance(ticks, int) and process_start_ticks(pid) == ticks
            lingering_group = isinstance(pid, int) and prior.get("pgid") == pid and bool(_group_members(pid))
            if (prior.get("state") not in TERMINAL or same_process or lingering_group
                    or prior.get("recovery_status") in {"in_progress", "unresolved"}):
                raise RuntimeError("prior task is not coherently finalized or recovery is unresolved; refusing replacement")
        task_uuid = str(uuid.uuid4())
        receipt_file = state_file.parent / f"{state_file.stem}.{task_uuid}.task.json"
        output = state_file.parent / f"{state_file.stem}.{task_uuid}.jsonl"
        receipt = {"task_id": task_id, "task_uuid": task_uuid, "state": "starting",
                   "pid": None, "pgid": None, "start_ticks": None, "started_at": utc_now(),
                   "latest_activity_at": None, "output_path": str(output),
                   "meaningful_event_count": 0,
                   "final_result_path": str(final_result_path or output), "blocker": "process startup",
                   "approval_policy": "never" if codex_command_enforced else "caller-supplied command; approval behavior unverified",
                   "stdin_contract": "prompt sent with communicate; stdin closes after prompt"}
        state_file.parent.mkdir(parents=True, exist_ok=True)
        # Create unique output and initial receipt before spawn; setup failures cannot leak a child.
        with output.open("xb"):
            pass
        _write_state(receipt_file, receipt)
        _write_state(pointer_file, receipt)
        try:
            stream = output.open("ab")
            try:
                proc = subprocess.Popen(command, cwd=cwd, stdin=subprocess.PIPE, stdout=stream,
                                        stderr=subprocess.STDOUT, text=True, start_new_session=True)
            finally:
                stream.close()
            receipt.update(pid=proc.pid, pgid=proc.pid, start_ticks=process_start_ticks(proc.pid),
                           blocker="no meaningful model activity observed yet")
            _write_state(receipt_file, receipt)
            _write_state(pointer_file, receipt)
        except BaseException as exc:
            # Spawn succeeded but receipt persistence failed: only our newly-created child may be cleaned up.
            if "proc" in locals():
                if proc.poll() is None:
                    try:
                        proc.terminate()
                    except ProcessLookupError:
                        pass
                proc.wait()
                try:
                    if proc.stdin is not None and not proc.stdin.closed:
                        proc.stdin.close()
                except BaseException:
                    pass
                receipt.update(pid=proc.pid, pgid=proc.pid, start_ticks=process_start_ticks(proc.pid))
            receipt.update(state="failed", blocker=f"process launch/setup failed ({type(exc).__name__})",
                           finished_at=utc_now(), final_receipt="launch failed; output retained")
            _write_state(receipt_file, receipt)
            _write_state(pointer_file, receipt)
            raise
    task = OwnedTask(receipt_file, pointer_file=pointer_file, lock_file=lock_file)
    task.proc = proc
    task._prompt = prompt
    if start_monitor:
        task.start_monitor()
    return task


def start_task(state_file: Path, task_id: str, command: list[str], prompt: str,
               *, cwd: Path) -> OwnedTask:
    """Start an arbitrary caller command with stdin supplied/closed; policy is unverified."""
    return _start_task(state_file, task_id, command, prompt, cwd=cwd,
                       codex_command_enforced=False, start_monitor=True)


def start_codex_task(state_file: Path, task_id: str, executable: str | Path, prompt: str,
                     *, cwd: Path, output: Path, sandbox: str = "read-only",
                     add_dirs: tuple[Path, ...] = ()) -> OwnedTask:
    command = codex_command(executable, cwd=cwd, output=output, sandbox=sandbox, add_dirs=add_dirs)
    return _start_task(state_file, task_id, command, prompt, cwd=cwd,
                       codex_command_enforced=True, start_monitor=True,
                       final_result_path=output)
