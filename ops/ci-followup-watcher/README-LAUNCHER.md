# Work package B: reviewer startup and owned recovery

`launcher.py` is a small adapter for one caller-owned task, not a scheduler or
daemon. `start_task(state_file, task_id, argv, prompt, cwd=...)` starts the exact
caller command in a new session and returns an `OwnedTask`. Its monitor calls
`communicate(prompt)` immediately in a background thread, supplying the prompt
and closing stdin. This arbitrary-command API records approval behavior as
unverified. For Codex, use `start_codex_task(...)`: its builder permits only the
fixed `gpt-6-luna` model, `medium` reasoning, `-a never`, the selected read-only
or workspace-write sandbox, provided working directory/output, and explicit
`--add-dir` paths. It accepts no arbitrary flags or prompt/cwd/output overrides.
Callers must review each add-dir path as context/authority. Per-attempt receipts
and output use unique paths; a locked pointer records the current attempt.
Older handles may update only their own receipt; task UUID matching prevents
them from changing a newer pointer. Prompts and raw exception text are never
written to diagnostics.

## State recognition

`observe()` distinguishes `starting`, `waiting_input`, `waiting_approval`,
`running`, `completed`, `failed`, and `cancelled`. Useful activity is inferred
from the metadata shape in `tests/fixtures/codex-jsonl-metadata.jsonl`:
`item.started` for `command_execution` or `file_change`, and `item.completed`
for `agent_message`, `command_execution`, or `file_change`. `turn.started`
alone is not useful. Transitions are read in order, so a later input/approval
wait or turn completion supersedes older activity. `turn.completed` while the
child is alive means awaiting process exit, not productive work or task
completion. Latest meaningful activity advances only when a new meaningful
event appears. Event timestamps are used when available; otherwise local
observation time is stored. Later noise and output-file mtime do not advance it.
Unknown schemas are reported explicitly and remain `starting`. Process existence
alone never means `running`.

## Recovery procedure

Use `recover_cancel()` only for a task created by this adapter. It rechecks
Linux process start ticks, PID, and isolated process-group identity; it refuses
a changed or inactive PID and any group with unexpected members. It sends TERM,
then uses KILL only if the same single-member group remains after the grace
period. Identity and group membership are checked immediately before each
signal. Partial output remains at `output_path`; cancellation is persisted only
after the owned process is confirmed inactive.

Start, observe, finish, and recovery updates serialize through one file lock.
Replacement requires a terminal finalized receipt, no matching process, no
remaining group members, and no unresolved recovery gate. An inactive child
whose finish thread has not committed its receipt still blocks replacement.
Recovery identity, group, pidfd, signal, or wait failures preserve a nonterminal
state with `recovery_status=unresolved`; they do not claim completion or
cancellation and block replacement. There are no automatic recovery retries.
An interrupted startup claim without a recorded PID also blocks replacement
for root inspection.

Do not use recovery for legacy tasks, historical PIDs, or processes whose
ownership was not established by this adapter. If identity or children do not
match, stop and use only an independently authorized supported capability.
Never retry a denied EOF, cancellation, or restart through another route.

## Integration contract for root

The watcher may use `start_codex_task` after its existing eligibility, dedupe,
attempt-cap, backoff, worktree, and singleton checks. Keep the current command
and prompt policy, assign a stable task ID, and put state under the existing
reports/evidence area. Root owns canonical wiring and decides whether a
background task handle fits the dispatch loop. This package does not edit
`watcher.py`, activate a process, or claim full validation of the live CLI JSONL
schema. The synchronous watcher already supplies prompt text through
`bounded_communicate`; this adapter adds persisted identity, task observation,
and exact-owner fixture recovery.

## Offline fixture evidence

Run only from this checkout:

```sh
python3 -W error::ResourceWarning -m unittest discover -s tests -p 'test_launcher.py' -v
```

Temporary-process fixtures cover waiting/resolution, metadata-only actual-schema
recognition, nonmeaningful output after useful activity, turn completion while
the child remains alive, nonzero exits, concurrent claims, delayed finish versus
replacement, stale-handle pointer protection, per-attempt output isolation,
identity/group refusal, pidfd/escalation failures while fixtures remain alive,
communication exceptions, and finish-versus-cancel with retained partial
output. They establish this adapter contract only; they are not live acceptance.

## Recognition limits and remaining dependencies

The metadata fixture was derived from
`/home/nitty/stox-v9-ops004-control-20261023/lane-B-review.jsonl` by retaining
event/type/status metadata only; it contains no prompt, output text, or secrets.
It does not represent every Codex CLI version. Unknown schemas remain explicit
and unclassified. The adapter does not prove model progress, parse semantic task
completion, inspect arbitrary descendants, or recover legacy sessions.
Recovery permits only a process group containing the exact owned PID and no
other members. Signals use a Linux pidfd bound to the process instance. If the
owner exits but any group member remains, recovery stays unresolved and
replacement remains blocked. A real task with child processes may be refused
for cancellation. Root must wire and review the adapter against the canonical
watcher contract. No live acceptance, activation, or legacy process action is
claimed here.

## Root observation and blocker procedure

The root caller keeps its supervisor alive until a final receipt: starting the task and exiting the Python parent is not a supported unattended launch. Use `start_codex_task` for the constrained Codex lane, retain the returned handle, call `observe()` at bounded intervals, and then `wait()` for the final adapter receipt. The caller reads the returned `task_id`, task UUID, exact PID/start ticks/group, state, output/final paths, activity time and blocker. Persist state transitions to the existing root evidence/checkpoint. These records contain task identity and status, not prompt text.

On `waiting_input`, `waiting_approval`, unknown activity, or an unresolved recovery, root records the exact blocker and evidence path immediately and reports it in the current progress update. The adapter itself sends no external messages and grants no additional approval. A live process with no meaningful activity stays starting or blocked; it must not be counted as a productive reviewer. The calling root is the notification recipient and resolution owner.

For a newly adapter-owned harmless task, only an independently permitted exact-owned recovery may call `recover_cancel()`. Preserve partial output; inspect the final receipt and inactive group before any replacement. For PID/group mismatch, unexpected members, missing pidfd support, or interrupted recovery, retain the unresolved claim and stop automatic replacement. Root records the dependency and uses direct source/CI review while awaiting a genuinely permitted capability. Do not use this adapter to recover legacy sessions or retry a prior denied action. Never delete the pointer/lock or change its terminal state to force a replacement.

If adapter source fails, retain its receipt/output and source revision, and prepare a correction or known-good rollback through normal protected version control. Applying a live source rollback or changing a running process still requires its own permitted operator procedure; no recovery workaround is provided here. The main package runbook covers watcher ownership and live activation limits.
