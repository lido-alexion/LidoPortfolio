# V9-OPS-004 implementation audit

Status: **IMPLEMENTED / VERIFIED — 23 October 2026.** Root accepts all frozen AC01–AC12. Watcher/launcher source merged through [PR261](https://github.com/lido-alexion/LidoPortfolio/pull/261), runner diagnostics/live receipts through [PR263](https://github.com/lido-alexion/LidoPortfolio/pull/263). Normal [CI1554](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39971543972), attempt1, released exact master `4e223f6f805ae959eedd04c454af6890ebdcf8b7` as build1554 and passed mandatory authenticated non-destructive smoke **2/2**. This documentation-only closure records the accepted runtime; it does not require another application release.

Canonical [frozen specification](../archive/specs/V9-OPS-004-CI-Watcher-Recovery-Release-Runner-Diagnostics-Specification.md), [register](../archive/specs/LidoPortfolio-V9-Wishlist.md), [tracking issue #251](https://github.com/lido-alexion/LidoPortfolio/issues/251). Root owns packages A, B, C and final acceptance; no external reviewer is required.

## Final frozen acceptance

| AC | Accepted evidence |
|---|---|
| AC01 | Spec, register, issue, ownership PR260, implementation PR261/263 and this audit link to the same epic/root owner. |
| AC02 | [Actual live lifecycle receipt](V9-OPS-004-live-lifecycle-qualification.json): authorized update/restart, changed-valid revision applied in process2967357, matching effective revision and one singleton. |
| AC03 | Same receipt: invalid retention, real lease/owned-PAUSED deferral, exact config restoration, pending/history preservation. Offline active-child/dedupe regressions pass within the59-test suite. |
| AC04 | Actual heartbeat/state receipt distinguishes disk/effective revision, loaded configured/enabled/report_only allowlist and global discovery. |
| AC05 | [Reviewer qualification](V9-OPS-004-reviewer-qualification.json): actual current Codex starting/running/completed and harmless blocked/failure receipts. |
| AC06 | Same qualification: exact-owned harmless recovery preserves output, confirms inactivity, refuses duplicate and permits clean replacement. Legacy2154507 remains untouched. |
| AC07 | [Incident](V9-OPS-004-release-runner-incident.md) and [hashed full evidence](V9-OPS-004-release-runner-evidence-index.json):1510/1550 failed host-key discovery before authentication; underlying cause explicitly UNKNOWN.1554 supplies bounded normal recovery. |
| AC08 | CI1554: strict host-key path, authenticated upload, independent artifact digest/metadata checks, current-master equality under existing serialized deployment, activation, exact runtime SHA/public health and mandatory authenticated smoke2/2. |
| AC09 | Root reviewed full relevant CI1553 retry and CI1554 logs/artifacts.1554:247 Node checks,618 component tests,488 browser cases/57 governed skips,2444 PHP tests/17540 assertions/5 notices/2 skips,56 Python contracts and59 watcher/launcher/diagnostic tests. Typecheck/build and188-migration portability pass. |
| AC10 | Versioned watcher/launcher operator runbooks retain inspection, ownership, supported recovery, denial handling, rollback/failure limits and evidence procedures. |
| AC11 | Existing singleton/cooperative locks and gates preserved. Explicit watcher/live-probe/publication/one-job-retry approvals were applied only to their disclosed scope. No duplicate service/automation, unrelated termination, manual deployment, speculative infrastructure fix or heavy production verifier. |
| AC12 | Root mapped all12 criteria to retained operational/source/release evidence and updates this canonical audit/register for verified closure. Tracking issue closes after this documentation passes normal CI/integration. No required implementation gap remains. |

## Current release and diagnostic limits

Package12511612049 ZIP SHA256 `04e39c66e88350043df0ec06b6dad40a831e219cc32bed151338d97c7afe0ac5` and smoke12511982415 ZIP SHA256 `8c405a22e3af900fd10893dc78c4339e02fe764c31efc3eb4e176c058e1d5032` independently match GitHub metadata and upload/download logs. Embedded metadata identifies build1554/attempt1/exact4e223f6f, built15:14:21.942UTC. The decoded smoke report records expected2, unexpected0, flaky0, skipped0; dedicated read-only identity opens an investor page. Runtime health passed at15:15:18 and15:15:26UTC. Fresh public root/build-info at15:35:59UTC returned200/exact4e223f6f. Full log hashes and timestamps are in the evidence index.

At15:14:45UTC DNS resolved one IPv4 address. The supplemental150ms TCP probe failed; the unchanged decisive host-key scan succeeded, followed by authenticated release. These observations do not diagnose the earlier failure or establish persistent TCP unreachability. Earlier1510/1550 cause remains UNKNOWN; frozen AC07 permits this bounded conclusion and AC08 accepts ordinary verified recovery. No speculative network correction is claimed or needed for closure. Reopen diagnosis on a future independently legitimate recurrence with its actual evidence.

CI1553 initially timed out at45minutes after slow dependency downloads, leaving one browser case unfinished. The PO explicitly approved one frontend-job rerun on the unchanged commit with all tests/limits preserved; that retry passed. The exception is consumed. CI1554 then passed on normal attempt1. Original failure/retry evidence remains retained; no source change was manufactured to obtain a rerun.

## Retained historical implementation record

Everything below is a dated historical record. Earlier open/blocked/current-production statements are superseded by the final acceptance and1554 receipt above; they do not describe current status.


Status: **IMPLEMENTATION IN PROGRESS**. Reviewed source merged through [PR261](https://github.com/lido-alexion/LidoPortfolio/pull/261) at `ef4ed62e2bfe1a298f93e4339f38c8794630c743`. Root now accepts actual watcher activation and live lifecycle criteria AC02–04. Current normal release remains unresolved after CI1550 failed SSH host-key discovery before activation; this epic is not verified closed. Root owns final diagnosis, normal delivery and acceptance.

Canonical [frozen specification](../archive/specs/V9-OPS-004-CI-Watcher-Recovery-Release-Runner-Diagnostics-Specification.md), [register](../archive/specs/LidoPortfolio-V9-Wishlist.md), [issue #251](https://github.com/lido-alexion/LidoPortfolio/issues/251).

## Current finite acceptance — 23 October 2026, after 10:08 UTC

| AC | Current evidence and result | Owner / remaining dependency |
|---|---|---|
| AC01 | Spec/register/issue/source/ownership PR260 links retained. | Root; current evidence publication pending normal CI/integration. |
| AC02 | **ACCEPTED:** explicitly authorized controlled restart loaded reviewed code. Changed valid whitespace-only configuration revision became effective in the same new process, with matching effective revision and exactly one singleton. | Root; [actual qualification receipts](V9-OPS-004-live-lifecycle-qualification.json). |
| AC03 | **ACCEPTED:** real operator lease excluded poll, owned PAUSED deferred changed-valid revision, invalid candidate retained last valid configuration. Exact original bytes restored; pending work, event receipts and launch history unchanged. Existing offline active-child/dedupe fixtures passed. | Root; live receipts plus qualified 50-test source suite. No duplicate worker or lost pending work. |
| AC04 | **ACCEPTED:** actual heartbeat/state distinguish effective loaded revision/allowlist, configured/enabled/report_only flags, disk revision and global CI discovery. | Root; actual source diagnostics retained in live receipt. |
| AC05 | **ACCEPTED:** real current Codex task starting/running/completed and harmless blocked/failure observations. | Root; [reviewer qualification](V9-OPS-004-reviewer-qualification.json). |
| AC06 | **ACCEPTED:** newly owned harmless blocked-task recovery preserved output, checked exact identity/inactive group, refused duplicate and confirmed clean replacement. | Root; legacy reviewer2154507 remains untouched and outside the watcher-only grant. |
| AC07 | **ACCEPTED bounded conclusion:** retained CI1510 and recurring CI1550 failure at host-key discovery before authentication/upload/activation. Underlying DNS/TCP/address-family cause remains UNKNOWN. | Root; [incident](V9-OPS-004-release-runner-incident.md) and [evidence index](V9-OPS-004-release-runner-evidence-index.json). New instrumentation is not proof of a cause or recovery. |
| AC08 | Historical ordinary CI1544 strict SSH/artifact/exact runtime/public/authenticated smoke recovery accepted. Latest normal CI1550 failed before activation; **current delivery pending**. | Root; successful legitimate normal current-master release/artifacts/runtime/public health/authenticated smoke required. No manual rerun/dispatch/dummy release. |
| AC09 | CI1549/1550 source checks passed Python56/watcher50/component618/browser488 (57 governed skips)/PHP2444, 17,540 assertions (5 notices/2 skips). Package1550 passed; root verified full logs and ZIP digest. | Root; new diagnostic source and current normal release still require fresh hosted qualification. |
| AC10 | Operator runbook covers ownership, supported recovery, invalid retention, denial handling, failures, rollback limits and evidence. | Root; live activation procedure below preserves the same existing singleton/cooperative locks. |
| AC11 | **Maintained:** no foreign process termination, duplicate dispatcher, lock deletion, protected-gate weakening, heavy production application test or infrastructure/data/provider action. | Root; previous denials outside the newly explicit watcher/probe grants remain binding. |
| AC12 | Finite ledger remains truthful and epic stays open. | Root; resolve current delivery, review terminal evidence, then update register/issue to verified only when qualified. |

## Explicitly permitted watcher activation and qualification

The user first explicitly authorized updating only the existing watcher to reviewed PR261 and controlled restart of PID2043767, preserving configuration, pending state and singleton protection. Root confirmed exact cwd/command and no active children/task, established its own PAUSED and existing watcher.lock lease, stopped only that watcher, and verified exit before changing code. Root held the existing daemon.lock during the four-file source update, preserving lock inodes, exact config/state bytes and the untouched token file. The unchanged existing ensure-running.sh singleton launcher started wrapper2967356/watcher2967357 at10:02:54UTC. No service/cron or unrelated process changed.

Root's initial proposed live config/pause/lease/wake probes were rejected before execution as outside that restart grant. The rejection was disclosed; the user then explicitly authorized those bounded live checks. Root did not treat the earlier rejection as execution or bypass it. Qualification completed at10:08:01UTC, exit0: real lease_busy; owned-PAUSED deferred a valid changed revision; same-process reload applied it; invalid config retained the last valid effective revision; exact original configuration was restored and reapplied. No new launch, event mutation or pending loss occurred. No PAUSED remains. Original configuration SHA-256 `4ff13823a657f050b7f9177afbebd57913c9db14e09378eef96120243cbf77bc` again matches the effective live revision. No target authority was expanded.

The [retained live receipt](V9-OPS-004-live-lifecycle-qualification.json) records reviewed source hashes and actual diagnostics. Source watcher SHA-256 is `02f464df4c18fa6c4f7cd3c56c5ab561761298e08d8fbe96bfab65e1783f575a`. Legacy2154507 still waits for input and is not productive; it remains untouched and may defer autonomous dispatch through the conservative worker-busy gate. Root direct CI review remains available. The same hourly task is enabled again with the original cadence; no duplicate schedule was created.

## Current release diagnosis work

A new isolated Luna6/medium source session is addressing the repeated observation gap with a minimal normal-runner DNS/family/TCP/keyscan diagnostic helper. This is useful incident instrumentation, not a speculative network remedy or a dummy release trigger. Root rejected its first candidate because an unbounded resolver call could delay the decisive keyscan. Corrective review requires a small global diagnostic budget and ensures diagnostic failure cannot suppress or replace the original decisive `ssh-keyscan -H` result/output. The existing scan timeout/retry behavior, host-key verification, current-master lock, artifact, runtime/public health and authenticated smoke gates must remain unchanged. Root accepted the corrected source after full review: a killable supplemental diagnostic subprocess has a two-second wall budget; timeout/failure emits sanitized metadata and the unchanged decisive scan still executes exactly once. Nine mocked diagnostic tests cover deadline/error/main wiring, family/cap/deduplication, scan result and output preservation. Candidate source is not yet hosted-qualified or deployed.

Production was last read healthy at09:37UTC on build1544 / `24bae26435efb586455e9a0f4e1516f25b1eb138`; this does not claim a fresh authenticated smoke or activation of1550. Root owns source review, normal publication, hosted checks, terminal release diagnosis and full closure. No current successful release is inferred from an older healthy runtime.

## Historical evidence before the newly explicit activation grants

The following retained snapshot explains the earlier unavailable activation dependency and CI1550 failure. Its old activation-blocked statements are superseded by the actual qualified procedure above; the failed current-release facts remain applicable.

## Package contents and boundaries

`ops/ci-followup-watcher/` contains watcher source, lifecycle/config validation, evidence access, original unit fixtures, safe disabled sample config, and source notes. `ensure-running.sh` and live operational state are excluded. The sample keeps PR 240 explicitly present, blanks all worktree paths, and disables every illustrative target. No `.git`, tokens, raw logs, or operational state were copied.

The incident evidence index retains run/job/SHA identifiers and artifact/log digests while replacing machine-specific absolute paths with evidence-store descriptions. Root corroboration is dated and tied to source commit `35ee24901cc5e30f9363defc4ee9f075591c4668`; the earlier C-lane unavailable-file finding remains historical. The build-log digest and release-vs-smoke timestamps are corrected. The underlying DNS/TCP/address-family cause remains unknown. B launcher source, current-schema metadata fixture, tests and root-owned actual/harmless qualification are included. The historical legacy waiting reviewer remains untouched.

## Root source review and finite remaining gates — 23 October 2026

Root reviewed the completed A/B correction packages and the integration patch. A closes current-allowlist queue bypass, separates disk/effective/global diagnostics, and preserves lease, pause, stop, pending work, caps and backoff. B closes duplicate launch, stale pointer and finish/recovery races; its constrained builder supplies and closes stdin with approval never. Root rejected the first integration scope override, required its removal, and verified the original backend/frontend/deployable classification remains authoritative.

Root rechecked C log bytes and corrected the build log digest; the current OPS001 canonical audit at source35ee2490 independently corroborates the accepted normal1544 recovery. Current public build identity was read at 2026-10-23T07:48UTC and returned HTTP200/build1544; this is read-only current identity, not a new authenticated smoke.

AC02 remains the concrete live gate: the old watcher process cannot acquire the new implementation merely through file edits. Prior denied process/source actions remain binding. Do not substitute fixture reloads, a STOPPED/signal route, source swap, or a second dispatcher for an unavailable permitted activation. AC03/04 source regressions are covered, with live diagnostic qualification dependent on AC02. AC09 source checks are now qualified, while current delivery failed before activation; AC12 and verified closure await the required operational gates. The issue and register must remain implementation in progress.

Root combined offline verification on 23 October 2026: **50 watcher/launcher tests, 16 scope/release-contract tests and 8 CI graph tests passed**, compilation and whitespace checks passed. Earlier failing fixture/setup iterations remain retained in the root JSONL logs; no retry, timeout, assertion, skip, or original classifier scope was weakened. This does not replace hosted application verification required by the workflow change.


## Historical terminal delivery review — 23 October 2026 before activation

[PR261](https://github.com/lido-alexion/LidoPortfolio/pull/261) merged normally with expected source head `40ec8d8b1d02377e3be18f2091018dbc90ac0479` at master `ef4ed62e2bfe1a298f93e4339f38c8794630c743`. [PR CI1549](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39920976193) and normal [master CI1550](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39926556873) passed all applicable source checks: **56 Python contracts, 50 watcher/launcher tests, 618 component tests, 488 browser cases (57 governed skips), and 2,444 PHP tests / 17,540 assertions (5 notices, 2 skips)**. Neither required a failed retry. Root retained and reviewed the full logs; no assertion, skip, timeout, retry or classifier gate was weakened.

CI1550 packaging job120333526017 passed. Root downloaded release artifact12491236137 and verified its ZIP SHA-256 `d7a03022ce5ddaec35fd6b1a95ced545bb550f9243d333131d294a70ba01cb98` against both package and download logs. Its archive metadata identifies build1550, attempt1, exact master `ef4ed62e...`, built at2026-10-23T09:06:27.389Z. This proves the candidate artifact, not a deployed runtime.

Deploy job120333863030 failed at2026-10-23T09:06:59.1455754Z in `Configure SSH`, about five seconds after `ssh-keyscan`. Authentication, upload, activation, runtime health and authenticated smoke were skipped. There is no new smoke artifact. The complete failed log is retained and hashed in the [release evidence index](V9-OPS-004-release-runner-evidence-index.json). The current log again lacks discriminating DNS/TCP/address-family evidence; root cannot establish the underlying cause or an evidenced source fix. Do not weaken verification, change infrastructure or trigger a release solely to probe this path.

Root read public `/` and `/api/build-info` after this failure: both HTTP200; runtime remains qualified **build1544 / `24bae26435efb586455e9a0f4e1516f25b1eb138`**. This is a current read-only identity check, not a new authenticated smoke. The failed candidate was never activated; no rollback occurred. UX001, UX004 and OPS001 retain their existing verified closures.

### Historical dependencies before explicit activation authority (superseded below)

| Required outcome | Current result | Owner / next permitted action |
|---|---|---|
| AC02 and dependent live AC03/04 watcher evidence | Blocked: daemon2043767 still runs original source SHA-256 `85b4537d6ccf774097d266ec2ec77c80b28cedc5b0c44654779dd88bb6e805ba`; no effective new revision receipt. | Root owns acceptance; an authorized operator must supply and execute a genuinely permitted activation procedure, preserving singleton/lease/pending/dedupe/owner semantics and retaining valid/invalid/PAUSED/diagnostic receipts. Prior denied process/source routes remain binding. |
| Current normal release of merged implementation | CI1550 failed before authentication/upload/activation; underlying cause unknown. | Root owns evidence review. Requires approved runner-path diagnostic evidence or the next independently legitimate normal release to demonstrate strict SSH, exact-current-master/artifact activation, runtime SHA/public health and authenticated smoke. No manual rerun/dispatch, dummy release or infrastructure change is authorized. |
| Verified epic closure | Required operational evidence absent. | Root keeps register/issue IMPLEMENTATION IN PROGRESS. Resume acceptance when the concrete missing capability/evidence is available; source tests cannot waive it. |

All A/B/C correction/integration/helper results have been root-reviewed; no productive coding worker or ready patch remains. Appservers are not work sessions. Watcher2043767 is healthy/idle on old source; legacy reviewer2154507 remains input-waiting/nonproductive and untouched. No useful additional source fix is evidenced by the available failure logs. The remaining activation dependency cannot be advanced within the current permitted capabilities. Root will stop only this epic's scheduled follow-up after this truthful documentation is integrated; no unrelated task or service is affected.
