import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.js',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://127.0.0.1:4177',
        headless: true,
        trace: 'off',
        screenshot: 'off',
        video: 'off',
    },
    webServer: {
        command: 'npx vite --config tests/e2e/vite.config.js',
        url: 'http://127.0.0.1:4177/',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        cwd: '../..',
    },
});
