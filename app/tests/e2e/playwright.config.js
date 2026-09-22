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
    projects: [
        {
            name: 'chromium',
            testIgnore: /responsive-shell\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'mobile-390x844',
            testMatch: /responsive-shell\.spec\.js/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 390, height: 844 },
                isMobile: true,
                hasTouch: true,
            },
        },
        {
            name: 'tablet-1024x768',
            testMatch: /responsive-shell\.spec\.js/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1024, height: 768 },
            },
        },
        {
            name: 'desktop-1440x900',
            testMatch: /responsive-shell\.spec\.js/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
            },
        },
        {
            name: 'ultrawide-2560x1440',
            testMatch: /responsive-shell\.spec\.js/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 2560, height: 1440 },
            },
        },
        {
            name: 'large-4k-3840x2160',
            testMatch: /responsive-shell\.spec\.js/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 3840, height: 2160 },
            },
        },
    ],
    webServer: {
        command: 'npx vite --config tests/e2e/vite.config.js',
        url: 'http://127.0.0.1:4177/',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        cwd: '../..',
    },
});
