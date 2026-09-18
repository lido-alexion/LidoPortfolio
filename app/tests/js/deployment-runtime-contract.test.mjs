import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

async function source(path) {
    return readFile(resolve(repositoryRoot, path), 'utf8');
}

test('release and rollback require a PHP-FPM refresh plus public build identity verification', async () => {
    const [deploy, rollback, health] = await Promise.all([
        source('deploy/scripts/stoxla-deploy-release.sh'),
        source('deploy/scripts/stoxla-rollback-release.sh'),
        source('deploy/scripts/stoxla-runtime-health-check.sh'),
    ]);

    for (const script of [deploy, rollback]) {
        assert.match(script, /SYSTEMCTL_BIN" reload "\$PHP_FPM_SERVICE/);
        assert.match(script, /SYSTEMCTL_BIN" restart "\$QUEUE_SERVICE/);
        assert.match(script, /STOXLA_EXPECTED_COMMIT="\$(RELEASE|TARGET)_COMMIT/);
        assert.match(script, /stoxla-runtime-health-check/);
    }

    assert.match(health, /public build-info commit .* does not match active release/);
    assert.match(health, /scheduler heartbeat is stale/);
});

test('managed queue configuration explicitly consumes every current named queue', async () => {
    const [deploy, unit, health, runbook, workflow] = await Promise.all([
        source('deploy/scripts/stoxla-deploy-release.sh'),
        source('deploy/systemd/stoxla-queue.service'),
        source('deploy/scripts/stoxla-runtime-health-check.sh'),
        source('deploy/STOXLA-VPS-DEPLOY.md'),
        source('.github/workflows/deploy-stoxla-production.yml'),
    ]);

    assert.match(unit, /queue:work --queue=notifications,default/);
    assert.match(deploy, /install -m 0644 "\$QUEUE_UNIT_TEMPLATE"/);
    assert.match(deploy, /SYSTEMCTL_BIN" daemon-reload/);
    assert.match(health, /STOXLA_REQUIRED_QUEUES:-notifications,default/);
    assert.match(health, /does not consume required queues/);
    assert.match(runbook, /Cmnd_Alias STOXLA_RUNTIME/);
    assert.match(workflow, /deploy\/systemd\/stoxla-queue\.service/);
});
