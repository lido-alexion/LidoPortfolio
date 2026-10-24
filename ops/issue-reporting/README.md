# Production issue-reporting credential bridge

Tracks [#267](https://github.com/lido-alexion/LidoPortfolio/issues/267).

The owner can enter `STOX_GITHUB_TOKEN` under the repository's existing
`production` environment secrets from a mobile browser. No token is committed,
emailed, passed through chat, or retrieved through the GitHub connector.

The normal qualified production deployment invokes `transfer_secret.py` after
its authoritative current-master check and before application activation.
The existing deployment SSH identity is reused. The credential channel pins
the server's reviewed Ed25519 public host key instead of trusting a new scan.
Only the fixed production account, repository, master ref and trusted event
types are accepted. The token travels only on the installer SSH process's
stdin; child environments, command arguments and receipts exclude it.

The remote installer checks the public/active release identity, locks its
configuration operation and updates only `STOX_GITHUB_TOKEN` in the persistent
production `.env`. It preserves other settings and the file's existing owner,
group and restrictive mode. Symlinks, duplicate credential entries, world
permissions, concurrent edits and active-release changes fail closed. An
atomic replacement is idempotent and temporary files are removed on handled
failures. No credential backup or token value is logged.

The normal release that follows performs its existing configuration cache,
worker restart, PHP service and health/smoke operations. The bridge itself does
not enable issue reporting, alter AI settings, replay pending incidents or
send a GitHub canary. Root must verify the effective credential and dedicated
worker, perform controlled reporting enablement and one marked end-to-end
create-or-link test before closing #267.

If the optional environment secret is absent, the bridge reports
`skipped_secret_not_configured` and leaves the existing environment untouched.
That receipt is not proof of reporter readiness. An invalid supplied secret,
SSH/host-key failure, stale active build or unsafe environment file fails the
deployment before activation. Application activation is never performed by
this package. A host-key rotation requires independently verifying and
reviewing the new public key before changing the committed pin.

Offline tests use temporary files, fake credentials and fake SSH execution:

```sh
python3 -m unittest discover -s ops/issue-reporting/tests -v
```

These tests are part of the existing required Python CI gate. Canonical backend,
frontend, release and production smoke gates remain unchanged.
