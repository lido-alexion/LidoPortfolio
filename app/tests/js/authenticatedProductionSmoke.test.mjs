import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const script = fileURLToPath(new URL('../e2e/authenticatedProductionSmoke.mjs', import.meta.url));
for (const baseURL of ['', 'http://example.test']) {
    test(`authenticated smoke fails closed without exposing configuration (${baseURL ? 'non-HTTPS' : 'missing URL'})`, () => {
        const result = spawnSync(process.execPath, [script], {
            env: { ...process.env, STOX_E2E_BASE_URL: baseURL, STOX_SMOKE_EMAIL: 'privacy-marker@example.test', STOX_SMOKE_PASSWORD: 'privacy-password-marker' },
            encoding: 'utf8', timeout: 10_000,
        });
        assert.equal(result.status, 1);
        assert.equal(result.stdout.trim(), 'AUTHENTICATED_SMOKE_FAILED:configuration');
        assert.equal(result.stderr, '');
    });
}
