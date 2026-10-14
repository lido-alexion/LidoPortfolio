import assert from 'node:assert/strict';
import { chmod, mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

async function source(path) {
    return readFile(resolve(repositoryRoot, path), 'utf8');
}

test('missing mandatory smoke identity blocks activation without exposing configured values', async () => {
    const workflow = await source('.github/workflows/deploy-stoxla-production.yml');
    const label = 'Require mandatory production smoke identity before activation';
    const start = workflow.indexOf(`- name: ${label}`);
    const end = workflow.indexOf('\n      - name:', start + 1);
    assert.ok(start > workflow.indexOf('\n  deploy:'));
    assert.ok(start < workflow.indexOf('- name: Configure SSH'));
    assert.ok(start < workflow.indexOf('- name: Upload and activate release'));
    const step = workflow.slice(start, end);
    const script = step.split('run: |\n')[1].split('\n').map(line => line.replace(/^          /, '')).join('\n');
    for (const [email, password, status] of [['', '', 1], ['synthetic@example.invalid', '', 1], ['', 'synthetic-password', 1], ['synthetic@example.invalid', 'synthetic-password', 0]]) {
        const result = spawnSync('bash', ['-c', script], { encoding: 'utf8', env: { PATH: process.env.PATH, STOX_SMOKE_EMAIL: email, STOX_SMOKE_PASSWORD: password } });
        assert.equal(result.status, status);
        assert.ok(!(result.stdout + result.stderr).includes('synthetic@example.invalid'));
        assert.ok(!(result.stdout + result.stderr).includes('synthetic-password'));
    }
    assert.ok(workflow.indexOf('- name: Run non-destructive production browser smoke') > workflow.indexOf('- name: Upload and activate release'));
});

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
    assert.match(health, /production app key is missing from cached config/);
    assert.match(health, /LIDO_AGENT_DEBUG_ENABLED=false/);
    assert.match(health, /lido_telemetry\.otel_sdk_disabled/);
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
    assert.match(deploy, /normalize_official_otel_config/);
    assert.match(deploy, /OTEL_SDK_DISABLED=false/);
    assert.match(deploy, /effective production app key is missing from cached config/);
    assert.match(deploy, /validate_cached_app_config "\$RELEASE_DIR"/);
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

test('deploy writable-path normalization preserves private ML acceptance sources and campaign evidence', async () => {
    const deploy = await source('deploy/scripts/stoxla-deploy-release.sh');
    const start = deploy.indexOf('prepare_writable_tree() {');
    const end = deploy.indexOf('\n}\n\nprepare_fundamentals_python()', start) + 2;
    assert.ok(start >= 0 && end > start);

    const fixture = await mkdtemp(resolve(tmpdir(), 'stox-private-storage-'));
    try {
        const sources = resolve(fixture, 'app/private/ml-acceptance/sources/29fbcda4-77ae-4850-802d-f15a297f061c');
        const campaign = resolve(fixture, 'app/private/ml-acceptance/campaigns/campaign-1/1m');
        const ordinary = resolve(fixture, 'app/framework/cache');
        await mkdir(sources, { recursive: true, mode: 0o700 });
        await mkdir(campaign, { recursive: true, mode: 0o700 });
        await mkdir(ordinary, { recursive: true });
        const sealed = resolve(sources, 'payload');
        const journal = resolve(campaign, 'pit-evidence.jsonl.gz');
        const cache = resolve(ordinary, 'cache.php');
        await writeFile(sealed, 'sealed-source');
        await writeFile(journal, 'private-evidence');
        await writeFile(cache, 'ordinary-cache');
        await chmod(sources, 0o700);
        await chmod(sealed, 0o400);
        await chmod(campaign, 0o700);
        await chmod(journal, 0o400);

        const script = `${deploy.slice(start, end)}\nPHP_FPM_GROUP="$(id -gn)"\nprepare_writable_tree "$1"`;
        const result = spawnSync('bash', ['-c', script, 'bash', fixture], { encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        const mode = async path => (await (await import('node:fs/promises')).stat(path)).mode & 0o7777;
        assert.equal(await mode(sources), 0o700);
        assert.equal(await mode(sealed), 0o400);
        assert.equal(await mode(campaign), 0o700);
        assert.equal(await mode(journal), 0o400);
        assert.equal(await readFile(sealed, 'utf8'), 'sealed-source');
        assert.equal(await readFile(journal, 'utf8'), 'private-evidence');
        assert.equal(await mode(ordinary), 0o2775);
        assert.equal(await mode(cache), 0o664);
    } finally {
        await chmod(resolve(fixture, 'app/private/ml-acceptance/sources/29fbcda4-77ae-4850-802d-f15a297f061c'), 0o700).catch(() => {});
        await chmod(resolve(fixture, 'app/private/ml-acceptance/campaigns/campaign-1/1m'), 0o700).catch(() => {});
        await chmod(resolve(fixture, 'app/private/ml-acceptance/sources'), 0o700).catch(() => {});
        await chmod(resolve(fixture, 'app/private/ml-acceptance'), 0o700).catch(() => {});
        await chmod(resolve(fixture, 'app/private'), 0o700).catch(() => {});
        await rm(fixture, { recursive: true, force: true });
    }
});

test('private acceptance permission repair is dry-run by default and narrowly guarded', async () => {
    const script = await source('app/scripts/repair-ml-acceptance-private-permissions.php');
    const ci = await source('.github/workflows/ci.yml');
    const help = spawnSync('php', ['scripts/repair-ml-acceptance-private-permissions.php', '--help'], { encoding: 'utf8', cwd: resolve(repositoryRoot, 'app') });
    assert.equal(help.status, 0, help.stderr);
    assert.match(help.stdout, /\[--actor OPERATOR\] \[--apply\]/);
    const unsafeApply = spawnSync('php', ['scripts/repair-ml-acceptance-private-permissions.php', '--apply'], { encoding: 'utf8', cwd: resolve(repositoryRoot, 'app') });
    assert.equal(unsafeApply.status, 2);
    assert.match(unsafeApply.stderr, /--apply requires --actor/);

    assert.match(script, /const REPAIR_ROOT = '\/var\/www\/stoxla\/shared\/storage\/app\/private\/ml-acceptance'/);
    assert.match(script, /where\('queue', 'ml-acceptance'\)->count\(\)/);
    assert.match(script, /whereIn\('status', \['preflight', 'training'\]\)/);
    assert.match(script, /\['uploading', 'queued', 'sealed', 'failed', 'cancelled'\]/);
    assert.match(script, /sealed-payload/);
    assert.match(script, /writable-upload-payload/);
    assert.match(script, /hash_file\('sha256'/);
    assert.match(script, /before_mode/);
    assert.match(script, /before_uid/);
    assert.match(script, /before_gid/);
    assert.match(script, /is_link\(/);
    assert.match(script, /realpath\(\$path\)/);
    assert.match(script, /cannot read\/traverse shared parent/);
    assert.match(script, /\$object\['path'\]/);
    assert.match(script, /sha256=%s/);
    assert.doesNotMatch(script, /setfacl|chmod\s+-R|chown\s+-R/);
    assert.match(ci, /app\/scripts\/\*\*\|app\/tests\/\*\.php/);
});

test('acceptance worker must drain before shared Python changes or rollback activation', async () => {
    const [deploy, rollback, unit] = await Promise.all([
        source('deploy/scripts/stoxla-deploy-release.sh'),
        source('deploy/scripts/stoxla-rollback-release.sh'),
        source('deploy/systemd/stoxla-ml-acceptance.service'),
    ]);
    const guard = 'is-active stoxla-ml-acceptance.service';
    assert.ok(deploy.indexOf(guard) < deploy.indexOf('prepare_ml_python "$RELEASE_DIR/'));
    assert.ok(rollback.indexOf(guard) < rollback.indexOf('log "switching current symlink'));
    for (const script of [deploy, rollback]) {
        assert.match(script, /inactive\|failed\|unknown\) ;;/);
        assert.match(script, /drain and stop stoxla-ml-acceptance.service/);
        assert.doesNotMatch(script, /(?:stop|restart|enable) stoxla-ml-acceptance\.service\s*$/m);
    }
    assert.match(unit, /queue:work ml-acceptance --queue=ml-acceptance .*--timeout=14400/);
    assert.match(unit, /KillMode=mixed/);
});

test('acceptance worker private-source override and stopped access preflight preserve source permissions', async () => {
    const [override, preflight, runbook] = await Promise.all([
        source('deploy/systemd/stoxla-ml-acceptance.service.d/20-private-source-access.conf'),
        source('deploy/scripts/stoxla-ml-acceptance-worker-preflight.sh'),
        source('deploy/STOXLA-VPS-DEPLOY.md'),
    ]);
    const syntax = spawnSync('bash', ['-n'], { input: preflight, encoding: 'utf8' });

    assert.equal(syntax.status, 0, syntax.stderr);
    assert.match(override, /User=www-data/);
    assert.match(override, /Group=www-data/);
    assert.match(preflight, /--property=User/);
    assert.match(preflight, /--property=Group/);
    assert.match(preflight, /"\$service_state" == inactive \|\| "\$service_state" == failed/);
    assert.match(preflight, /! -perm -0700/);
    assert.match(preflight, /-perm \/0077/);
    assert.match(preflight, /source_dir_violations/);
    assert.match(preflight, /source_file_violations/);
    assert.match(preflight, /evidence_dir_violations/);
    assert.match(preflight, /evidence_file_violations/);
    assert.match(preflight, /! -readable/);
    assert.match(preflight, /import sklearn/);
    assert.match(preflight, /CACHE_ROOT/);
    assert.match(preflight, /MODEL_ROOT/);
    assert.doesNotMatch(preflight, /\b(?:chmod|chown|setfacl)\b/);
    assert.match(runbook, /systemctl daemon-reload[\s\S]*worker-preflight\.sh && \\\s*sudo systemctl restart stoxla-ml-acceptance\.service/);
});
