# V9-OPS-004 — CI Watcher Recovery & Release Runner Diagnostics

| Field | Value |
|---|---|
| Epic | V9-OPS-004 |
| Created | 2026-10-22 |
| Status | FROZEN — implementation-ready; implementation not started |
| Owner | Root orchestration owner; publish implementation-thread ownership in the V9 register before starting |
| Tracking | [Issue #251](https://github.com/lido-alexion/LidoPortfolio/issues/251) |
| Register | [V9 planning register](LidoPortfolio-V9-Wishlist.md) |
| Origin | PO-requested separation of operational follow-ups from V9-UX-001 |

## 1. Problem and intended outcome

Operational follow-up can stall when a running watcher retains obsolete configuration, a reviewer launch waits for input while appearing active, or a release runner cannot obtain the VPS SSH host key. Make these states observable, recoverable through authorized procedures, and independently tracked without expanding the investor-journey epic.

This is a small operations epic, not a new orchestration platform. Reuse the existing watcher, process/session tools, GitHub Actions release workflow, evidence store and checkpoint conventions. Root owns review, acceptance, normal integration and verified completion; no external reviewer is assumed.

## 2. Scope and boundaries

In scope:
- supported configuration reload/restart and ownership handoff for the existing CI follow-up watcher;
- accurate blocked/startup/running/finished task states and safe recovery of an owned reviewer launch;
- diagnosis of the runner-to-VPS SSH host-key path and evidence of normal release recovery;
- bounded offline regression coverage, an operator runbook and acceptance receipts.

Out of scope:
- investor UI changes, new journey requirements, ML/PIT acquisition or training, provider changes and financial actions;
- a new scheduler, duplicate watcher, daemon, credential store, telemetry product or arbitrary process manager;
- broad infrastructure hardening, paid infrastructure, firewall/SSH service/ACL changes or credential rotation without separate authority;
- changing protected-branch rules, weakening CI, manual deployment or dummy commits to trigger releases;
- treating speculative ContextualNotes timing as an accepted defect.

Production safety: no heavy verifier, installation, browser, database or migration work on the production VPS. Use hosted verification for relevant code. Never print secrets, terminate unrelated processes, delete lock files, force-push or rewrite shared history.

Existing automatic-approval denials remain binding. This specification does not authorize retrying a rejected EOF, cancellation or restart through a different route. An unavailable recovery capability must be reported accurately and resolved through a permitted action or explicit applicable authority.

## 3. Work package A — Watcher configuration lifecycle

### Required behavior

1. Inspect the current watcher implementation and choose the smallest supported reload procedure: safe in-process reload or a documented normal restart under existing authority.
2. Expose the effective loaded configuration revision, its load time and the configured targets in diagnostic evidence. Disk configuration and a global list of active CI runs must not be presented as proof of an effective dispatch allowlist.
3. Validate a proposed configuration before activating it. On invalid input, retain the last valid effective configuration and report a sanitized error.
4. Serialize reload with source/publication ownership. Preserve singleton and cooperative locks, PAUSED/STOPPED semantics, pending children, event deduplication, attempt caps and API backoff.
5. Never launch a second dispatcher or replay an already handled event merely because configuration reloads.
6. Record the owner, requested/effective revisions, action, result and remaining dependency. If restart is unavailable, root can continue direct CI review without claiming autonomous dispatch works.

### Deliverables

A bounded implementation where necessary; offline lifecycle tests; a runbook describing inspection, safe reload, failure recovery, handoff and verification.

## 4. Work package B — Reviewer startup and recovery

### Required behavior

1. Distinguish process existence from useful work: starting, waiting for input/approval, running model task, completed, failed and cancelled.
2. Record task identity, exact owned process/session, start time, latest meaningful activity, output/final-result location and current blocker without exposing prompt secrets.
3. Prevent unattended launches from silently waiting on missing stdin or an interactive approval. Detect this state and notify the root owner; do not automatically broaden authority.
4. Recover through a supported EOF/close or explicitly permitted cancellation of the exact owned process after checking identity and children. Preserve partial output and final status.
5. Do not start a replacement until the original task is confirmed inactive or independently isolated with explicit ownership. Do not label an input-waiting process as a productive reviewer.
6. Test the launcher/recovery contract with harmless isolated processes, never unrelated production workers.

Historical example: PID2154507 was observed waiting for input with no productive model task. PIDs are historical identifiers, not standing permission to act on a possibly reused process.

## 5. Work package C — Release runner SSH diagnosis

### Required investigation and recovery

1. Preserve the full failed workflow's SHA, run/attempt/job, timestamps, sanitized logs and artifact hashes. Classify the stage precisely: name resolution, TCP reachability, host-key discovery, authenticated SSH, upload, activation or smoke.
2. Investigate runner-side DNS/address-family/network reachability and host-key-scan behavior using existing authorized diagnostics. VPS SSH being healthy on loopback does not establish external runner reachability.
3. Preserve normal host-key verification. Never use StrictHostKeyChecking=no or blindly trust an unverified replacement fingerprint. Any change in host-key acquisition must use a trusted verification procedure.
4. Propose only an evidenced bounded fix. Do not infer missing credentials when authentication was never attempted, or infer no connection from inaccessible system logs.
5. Verify recovery through an authorized normal release workflow on its actual commit. A product change's legitimate release may supply this evidence. No dummy change, manual rerun/dispatch/deploy or new infrastructure authority is granted here.
6. Qualify the full release: current-master equality under the existing deployment lock, verified artifact, upload/activation, exact runtime SHA, public health and mandatory authenticated non-destructive smoke. A failure before activation must not be described as an activated unhealthy release or rollback.

Historical incident: [build1510 / CI39715911187](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39715911187) failed at ssh-keyscan before authentication, upload or activation. Product checks/package passed; build1507 remained healthy. This is a baseline observation, not a claim about current deployment state.

## 6. Acceptance criteria

| ID | Acceptance evidence |
|---|---|
| AC01 | Register, spec, issue and implementation owner link to one another; all three packages have explicit status/owner. |
| AC02 | A changed valid watcher configuration becomes effective through the supported procedure, with an observable matching revision and no duplicate singleton. |
| AC03 | Invalid configuration and active-child/owned-PAUSED cases preserve valid state and ownership; offline tests prove no lost pending work or duplicate event dispatch. |
| AC04 | Loaded allowlist, disk configuration and global CI discovery are distinguishable in diagnostics. |
| AC05 | A harmless blocked launch is detected as waiting, a real task as running, and completion/failure produces a final receipt. |
| AC06 | Exact-owned blocked-launch recovery is demonstrated through a permitted capability, preserves output and leaves no duplicate worker. Approval denial is never represented as execution. |
| AC07 | SSH incident has an evidence-based stage/cause conclusion, or an explicitly bounded transient-path conclusion with retained evidence; unknown cause is never reported as diagnosed. |
| AC08 | A normal release successfully passes host-key verification, authentication, artifact verification, activation, exact SHA/public health and authenticated smoke. Transient recovery may satisfy this outcome without unnecessary code changes; residual root-cause investigation remains explicitly tracked. |
| AC09 | Relevant hosted/offline checks pass; failure/retry/flakiness and artifacts are reviewed by root. Documentation-only changes do not trigger unnecessary production tests. |
| AC10 | Runbook covers inspection, ownership, supported recovery, denial handling, evidence and rollback/failure handling. No secrets or unsafe instructions are committed. |
| AC11 | No duplicate automation/service, protected-gate bypass, destructive financial action, unrelated termination, permission expansion or heavy production test occurred. |
| AC12 | Root maps every criterion to evidence, resolves or explicitly scopes all implementation gaps, and updates register/issue truthfully. Unresolved required recovery capability means partial implementation, not verified completion. |

## 7. Delivery and parallelism

A, B and read-only C diagnosis may proceed independently using distinct task ownership and isolated worktrees when editing. Serialize Git publication and release decisions; respect the existing watcher lock contract and actual operators. Reconcile fresh master before publication, preserve unrelated changes, use normal protected PR integration and relevant hosted CI.

Do not manufacture implementation changes when diagnosis or a documented supported procedure solves the problem. Additional infrastructure requirements become separately reviewed follow-up scope.

## 8. Relationship to V9-UX-001 and other epics

Watcher administration and reviewer cleanup are not UX-001 product acceptance gates. The SSH incident is owned here; UX-001 still requires a healthy accepted release and non-destructive production smoke. Separate incident tracking does not waive that required outcome.

FEAT-056/057 retain historical PIT/training acceptance. OPS-002/003 retain application failure reporting/AI triage. Reuse them where relevant rather than creating new integrations.

## 9. Evidence and closure

Create a compact implementation audit under docs/audits/ with criterion, implementation/reference, test or operational receipt, current result and owner. Link issue251 and the root checkpoint receipts; preserve historical observations with dates. No user-specific secrets or raw credentials belong in the audit.

Status progression: FROZEN → IMPLEMENTATION IN PROGRESS (named owner) → IMPLEMENTED / VERIFIED only after all acceptance criteria pass. Required unresolved permissions/capabilities remain visible blockers; no silent closure. The spec itself authorizes implementation within existing boundaries, not execution of previously denied operations.
