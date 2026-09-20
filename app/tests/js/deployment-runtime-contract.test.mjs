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
    assert.match(health, /production DebugAgent is enabled/);
    assert.match(health, /LIDO_AGENT_DEBUG_ENABLED=false/);
    assert.match(health, /storage\/logs/);
});

test('managed queue configuration is root-owned and deployment only verifies coverage', async () => {
    const [deploy, rollback, unit, health, runbook, workflow] = await Promise.all([
        source('deploy/scripts/stoxla-deploy-release.sh'),
        source('deploy/scripts/stoxla-rollback-release.sh'),
        source('deploy/systemd/stoxla-queue.service'),
        source('deploy/scripts/stoxla-runtime-health-check.sh'),
        source('deploy/STOXLA-VPS-DEPLOY.md'),
        source('.github/workflows/deploy-stoxla-production.yml'),
    ]);

    assert.match(unit, /queue:work --queue=notifications,default/);
    assert.match(unit, /User=nitty/);
    assert.match(unit, /Group=www-data/);
    assert.match(unit, /WorkingDirectory=\/var\/www\/stoxla/);

    for (const script of [deploy, rollback]) {
        assert.doesNotMatch(script, /\/etc\/systemd\/system/);
        assert.doesNotMatch(script, /daemon-reload/);
    }

    assert.match(health, /STOXLA_REQUIRED_QUEUES:-notifications,default/);
    assert.match(health, /does not consume required queues/);
    assert.match(runbook, /Cmnd_Alias STOXLA_RUNTIME/);
    assert.match(runbook, /root:root 644/);
    assert.doesNotMatch(runbook, /\/usr\/bin\/install -m 0644 \/home\/nitty/);
    assert.doesNotMatch(workflow, /deploy\/systemd\/stoxla-queue\.service/);
});

test('production activation fails closed for debug auth and normalizes PHP writable paths', async () => {
    const [deploy, health, config, middleware, logging] = await Promise.all([
        source('deploy/scripts/stoxla-deploy-release.sh'),
        source('deploy/scripts/stoxla-runtime-health-check.sh'),
        source('app/config/portfolio.php'),
        source('app/app/Http/Middleware/DebugAgentToken.php'),
        source('app/config/logging.php'),
    ]);

    assert.match(config, /env\('LIDO_AGENT_DEBUG_ENABLED', false\)/);
    assert.match(config, /env\('LIDO_AGENT_DEBUG_TOKEN'\)/);
    assert.match(middleware, /app\(\)->environment\('production'\)/);
    assert.doesNotMatch(middleware, /query\('debug_token'\)/);
    assert.match(deploy, /production shared \.env must explicitly set LIDO_AGENT_DEBUG_ENABLED=false/);
    assert.match(deploy, /prepare_writable_tree/);
    assert.match(health, /PHP_FPM_GROUP/);
    assert.match(health, /perm -2000/);
    assert.match(deploy, /FUNDAMENTALS_SHARED_DIR/);
    assert.match(deploy, /fundamentals-requirements\.txt/);
    assert.match(deploy, /ML_SHARED_DIR/);
    assert.match(deploy, /ml-requirements\.txt/);
    assert.match(health, /import yfinance/);
    assert.match(health, /FUNDAMENTALS_YFINANCE_VERSION/);
    assert.match(health, /import sklearn/);
    assert.match(health, /ML_SKLEARN_VERSION/);
    assert.match(health, /STOXLA_ML_PYTHON/);
    assert.match(health, /ML_MODEL_DIRECTORY/);
    assert.match(deploy, /prepare_writable_tree "\$SHARED_DIR\/ml"/);
    for (const channel of ['single', 'daily', 'frontend', 'provider', 'scheduler', 'emergency']) {
        assert.match(logging, new RegExp(`'${channel}'[\\s\\S]*?'permission'\\s*=>\\s*0664`));
    }
});
