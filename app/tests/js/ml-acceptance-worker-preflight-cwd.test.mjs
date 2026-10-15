import assert from 'node:assert/strict';
import { chmod, mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const scriptPath = path.resolve(import.meta.dirname, '../../../deploy/scripts/stoxla-ml-acceptance-worker-preflight.sh');

test('worker preflight commands use a neutral cwd when the invoking directory is private', async (t) => {
    if (process.platform !== 'linux') return t.skip('runuser cwd behavior is Linux-specific');
    if (process.getuid?.() === 0) return t.skip('root bypasses the directory permissions that reproduce this failure');

    const script = await readFile(scriptPath, 'utf8');
    const workerFunction = script.match(/as_worker\(\) \{[\s\S]*?\n\}/)?.[0];
    assert.ok(workerFunction, 'preflight defines the as_worker command boundary');
    assert.match(workerFunction, /\/bin\/sh -c 'cd \/ && exec "\$@"'/);

    const root = await mkdtemp(path.join(os.tmpdir(), 'stoxla-preflight-cwd-'));
    const work = path.join(root, 'private-work');
    const data = path.join(root, 'readable-data');
    const runner = path.join(root, 'runuser-shim');

    try {
        await mkdir(work, { mode: 0o700 });
        await mkdir(data, { mode: 0o755 });
        await writeFile(path.join(data, 'fixture.txt'), 'fixture\n');
        await writeFile(runner, '#!/bin/sh\n[ "$1" = "-u" ] || exit 91\nshift 2\n[ "$1" = "--" ] || exit 92\nshift\nexec "$@"\n');
        await chmod(runner, 0o755);

        const harness = `${workerFunction}
set -u
DATA_ROOT=${JSON.stringify(data)}
WORK_DIR=${JSON.stringify(work)}
RUNUSER_BIN=${JSON.stringify(runner)}
WORKER_USER=www-data
chmod 000 "$WORK_DIR"
set +e
baseline="$(find "$DATA_ROOT" -maxdepth 1 -type f -print 2>&1)"
baseline_status=$?
set -e
chmod 700 "$WORK_DIR"
if [ "$baseline_status" -eq 0 ] || ! printf '%s' "$baseline" | grep -q 'Failed to restore initial working directory'; then
  printf 'fixture did not reproduce inaccessible-cwd failure: %s\n' "$baseline" >&2
  exit 93
fi
chmod 000 "$WORK_DIR"
as_worker find "$DATA_ROOT" -maxdepth 1 -type f -print
result=$?
chmod 700 "$WORK_DIR"
exit "$result"
`;
        const result = spawnSync('/bin/bash', ['-c', harness], { cwd: work, encoding: 'utf8' });
        assert.equal(result.status, 0, `${result.stderr}\n${result.stdout}`);
        assert.match(result.stdout, /fixture\.txt/);
        assert.doesNotMatch(result.stderr, /Failed to restore initial working directory/);
    } finally {
        await chmod(work, 0o700).catch(() => {});
        await rm(root, { recursive: true, force: true });
    }
});
