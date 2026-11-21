import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { summarizeProductionSmokeBlocks } from './productionSmokePolicy.js';
import { readSafeAuthenticatedSmokeMarkers } from './productionSmokeDiagnostics.js';
const run = promisify(execFile);

test('production smoke is available and non-destructive', async ({ page }) => {
    test.skip(!process.env.STOX_E2E_BASE_URL, 'Set STOX_E2E_BASE_URL for controlled production smoke.');
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page).toHaveTitle(/StoX|Lido/i);
    await expect(page.locator('body')).not.toContainText(/Internal Server Error/i);

    const moduleScript = page.locator('script[type="module"][src]').first();
    await expect(moduleScript).toHaveCount(1);
    const moduleUrl = await moduleScript.getAttribute('src');
    expect(moduleUrl).toBeTruthy();
    const moduleResponse = await page.request.get(new URL(moduleUrl, process.env.STOX_E2E_BASE_URL).toString());
    expect(moduleResponse.ok()).toBeTruthy();
    expect(moduleResponse.headers()['content-type']).toMatch(/javascript/i);
});

test('dedicated smoke identity reports read-only feature eligibility honestly', async () => {
    test.setTimeout(70_000);
    test.skip(!process.env.STOX_E2E_BASE_URL, 'Set STOX_E2E_BASE_URL for controlled production smoke.');
    if (!process.env.STOX_SMOKE_EMAIL || !process.env.STOX_SMOKE_PASSWORD) {
        throw new Error('Mandatory authenticated production smoke requires a dedicated read-only identity configured as STOX_SMOKE_EMAIL and STOX_SMOKE_PASSWORD Actions secrets.');
    }
    // Run outside the test reporter: even untraced page.fill calls retain credential
    // arguments in HTML step metadata. This child prints only allowlisted outcomes.
    let diagnosticStage = 'child_execution';
    try {
        const { stdout } = await run(process.execPath,
            [fileURLToPath(new URL('./authenticatedProductionSmoke.mjs', import.meta.url))],
            { env: process.env, timeout: 60_000 });
        const lines = stdout.trim().split(/\r?\n/);
        diagnosticStage = 'outcome_markers';
        expect(lines).toContain('AUTHENTICATED_SMOKE_PASSED');
        const markers = readSafeAuthenticatedSmokeMarkers(stdout);
        expect(markers).not.toBeNull();
        // Reconstruct only fixed enum output. Never forward child stdout.
        console.log(`AUTHENTICATED_SMOKE_IDENTITY:${markers.identity}`);
        console.log(`AUTHENTICATED_SMOKE_KITE_COVERAGE:${markers.kiteCoverage}`);
        console.log(`AUTHENTICATED_SMOKE_KITE_STATUS:${markers.kiteStatus}`);
        console.log(`AUTHENTICATED_SMOKE_RECONCILIATION:${markers.reconciliation}`);
        console.log('AUTHENTICATED_SMOKE_PASSED');
    } catch (error) {
        const stage = String(error.stdout || '').match(/AUTHENTICATED_SMOKE_FAILED:([a-z_]+)/)?.[1] || diagnosticStage;
        const blocked = summarizeProductionSmokeBlocks(error.stdout);
        throw new Error(`Authenticated production smoke failed at ${stage}${blocked.length ? ` (blocked categories: ${blocked.join(', ')})` : ''}; credentials and provider responses are omitted.`);
    }
});
