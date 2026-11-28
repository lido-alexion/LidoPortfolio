import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    testMatch: 'auth-context-live.spec.js',
    outputDir: process.env.STOX_AUTH_E2E_OUTPUT_DIR || 'test-results/auth-live',
    fullyParallel: false,
    workers: 1,
    reporter: 'list',
    use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://127.0.0.1:4178',
        headless: true,
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'npx vite --config tests/e2e/auth-vite.config.js',
        url: 'http://127.0.0.1:4178/',
        reuseExistingServer: false,
        timeout: 120_000,
        cwd: '../..',
    },
});
