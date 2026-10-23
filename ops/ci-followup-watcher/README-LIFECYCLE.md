# Configuration lifecycle source notes

The watcher validates configuration at startup and at serialized poll boundaries under the existing `watcher.lock` lease. A valid changed file becomes effective after the prior poll and synchronous child work completes. Reload does not create another dispatcher or reset event state.

The effective revision is the SHA-256 of the loaded bytes. State and heartbeat distinguish that revision and target summary from the current disk revision, repository-wide public run discovery, and the effective dispatch allowlist. Invalid or unreadable edits retain the last valid in-memory configuration and produce sanitized audit evidence. Pending events are revalidated against the full current target identity and policy before dispatch; removed, disabled, or unconfigured targets remain pending.

`PAUSED` defers reload and dispatch while polling continues. `STOPPED` prevents polling and dispatch while preserving state. Neither marker nor a source edit updates an older running process. Never unlink the cooperative lock or clear state to recover.

These notes describe source behavior and offline fixtures. They are not live activation instructions or proof of AC02. Previously denied restart, EOF, cancellation, and direct-live-source actions remain binding. See the implementation ledger for the current dependency.
