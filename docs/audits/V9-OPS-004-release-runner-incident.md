# V9-OPS-004 release runner incident (AC07–08)

## Latest qualified recovery — normal CI1554, 23 October 2026

Root accepts current normal recovery on exact master `4e223f6f805ae959eedd04c454af6890ebdcf8b7` through [CI1554](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39971543972), attempt1. Package120492231521/deploy120492613340 passed. Release ZIP12511612049 and smoke ZIP12511982415 were independently downloaded and hash-matched; embedded release metadata and decoded smoke report match the exact commit/run. Current-master equality passed15:15:02UTC, release activated15:15:20UTC, exact-SHA runtime/public health passed, mandatory authenticated smoke2/2 passed15:16:46UTC with no unexpected/flaky/skipped cases. Fresh public15:35:59UTC confirmed HTTP200/build1554. See the [evidence index](V9-OPS-004-release-runner-evidence-index.json) for full hashes/timestamps.

New sanitized diagnostics show DNS success/oneIPv4, supplemental150ms TCP failure, then decisive host-key-scan success. A short failed sample does not contradict the subsequent successful strict SSH/authenticated upload or establish an enduring TCP outage. The underlying1510/1550 cause remains UNKNOWN. This is bounded later normal recovery, not proof of a network fix. AC07/08 are satisfied under the frozen specification; only future legitimate recurrence requires fresh diagnosis. All host-key/security/release gates remain unchanged.

The following earlier findings and recovery/dependency records remain historical;1554 supersedes their unresolved-current-delivery statements.

## Finding

The 2026-10-22 failed deploy for run 39715911187, attempt 1, job 119627574129, at commit `ae3976e5957bb10332681e2475498e83b771f691`, failed during SSH host-key discovery. The precise failing command is `ssh-keyscan -H "$STOXLA_HOST" >> ~/.ssh/known_hosts`; the job ended with exit code 1 at 2026-10-22 03:49:15.624 UTC, about five seconds after the command began. The step had already checked that host and private-key variables were nonempty and written the key file, but no `ssh`/`scp` authentication attempt followed. This is not evidence of a credential or authentication failure.

The log proves failure in the host-key-scan stage, before upload, activation, post-deploy health, and smoke. It does not expose keyscan stderr or an explicit resolver/connection error. Therefore DNS lookup, IPv4/IPv6 selection, TCP reachability/firewall/path, and host-key scan behavior are unresolved possibilities; none is established as the cause. The bounded conclusion is a transient or otherwise unresolved runner-to-host key-discovery path failure. No code fix is evidenced. Do not weaken normal host verification or blindly trust a replacement fingerprint.

## Normal recovery receipt

CI run 39853227890, run number 1544, attempt 1, was a normal `master` CI release on commit `24bae26435efb586455e9a0f4e1516f25b1eb138` (merge PR #257), not a manual rerun or dummy trigger. Its deploy job (120096555245) invokes `.github/workflows/deploy-stoxla-production.yml` at that commit. The run shows artifact digest verification, the existing production deployment concurrency gate, normal `ssh-keyscan`, and normal strict SSH behavior; it then records `Current master matches candidate 24bae...`, deployment staging/live, and successful runtime health checks. At 2026-10-22 21:22:50.818 UTC runtime health is green for the exact commit; public build identity and application/browser-module checks appear at 21:22:58 UTC. The authenticated, non-destructive production smoke artifact records two expected tests passed, including the dedicated read-only identity opening an investor page. Run conclusion is success, updated at 21:24:22 UTC.

Release artifact 12459414675 has SHA-256 `968c0d9e3a139fe2fcf6ddc70a44cded48b6bc11e756a6bf3bf60e1bd73f4f53`, matching the downloaded artifact digest. Its embedded release metadata records `built_at` as 2026-10-22T21:21:57.657Z, workflow `CI`, run 39853227890, `master`, and the exact SHA above. Smoke artifact 12460180778 has SHA-256 `4541cafd29cdf16a68582664739d1eb39657ed1e271db5ecb7993b33d13282d2`, also matching. Its separate smoke report records expected 2/2, unexpected 0, flaky 0, skipped 0; it is not the source of the release `built_at` value.

## Acceptance and residual dependency

- **AC07: satisfied as bounded diagnosis.** Full failed log is retained and hashed; stage is specific; root cause remains explicitly unknown rather than guessed.
- **AC08: satisfied by existing normal release receipt.** CI1544 demonstrates strict host-key verification, authentication through successful remote steps, artifact verification, existing current-master equality gate, activation, exact runtime SHA/public health, and authenticated read-only smoke.
- **Residual unknown:** which transient or persistent runner-side DNS/address-family/TCP/host-key-scan condition caused the earlier scan to fail. No direct probe or infrastructure change was made. Revisit only if another authorized normal release reproduces it, using that run's detailed runner output and approved diagnostics. The incident does not establish present-day runner connectivity beyond the successful CI1544 receipt.

The deployment workflow read at failed SHA `ae3976e...` retains normal `ssh-keyscan`/known_hosts use, strict checkout verification, serialized deployment concurrency, current-master equality before activation, exact SHA runtime health, and smoke. The successful run identifies the workflow revision as `24bae264...`; its log reflects the same path and successful stages. The requested `git show e5efb6d4:docs/current/V9-OPS-001-IMPLEMENTATION-AUDIT.md` could not be completed from the C lane's available checkout: that abbreviated commit was absent from its local object database. The separate CI1544 artifact-inspection and release-evidence receipts were inspected instead; no OPS001 audit claims were inferred from that unavailable file at the time.

## Root corroboration added 2026-10-23

Root later read the latest canonical `docs/current/V9-OPS-001-IMPLEMENTATION-AUDIT.md` at source commit `35ee24901cc5e30f9363defc4ee9f075591c4668` and corroborated the qualified CI1544 normal-release record. The earlier C-lane unavailable-file finding remains as a dated historical observation. Release `built_at` and smoke completion/report metadata are distinct, as detailed above and in the evidence index.


## Recurrence on normal CI1550 — 23 October 2026

The legitimate PR261 merge produced normal master run39926556873 / build1550, attempt1, SHA `ef4ed62e2bfe1a298f93e4339f38c8794630c743`. All applicable checks and package job120333526017 passed. Release artifact12491236137 was uploaded and downloaded with matching SHA-256 `d7a03022ce5ddaec35fd6b1a95ced545bb550f9243d333131d294a70ba01cb98`; root independently verified the downloaded ZIP bytes and embedded exact-SHA metadata.

Deploy job120333863030 again failed during `Configure SSH`: keyscan started at09:06:54.113UTC and exited1 at09:06:59.1455754UTC. No authentication, upload, activation, health or authenticated smoke followed, so the new candidate is not a successful release and no rollback occurred. Public identity subsequently remained HTTP200/build1544, exact SHA `24bae26435efb586455e9a0f4e1516f25b1eb138`.

The failure log does not distinguish DNS, address family, TCP/path or keyscan behavior. Recurrence prevents treating historical1544 success as proof of present runner reachability. Historical AC08 success remains retained, but current delivery is unresolved. No evidenced code remedy is available from these logs. Preserve normal host verification and do not infer invalid credentials when authentication never ran. Additional approved runner diagnostics or a later independently legitimate normal release are needed; no manual rerun/dispatch/dummy release or infrastructure changes are authorized.
