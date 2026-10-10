import { test, expect } from '@playwright/test';
import { TEST_USER } from '../js/tos/fixtures/tosApi.js';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

function acceptanceReport(status, blockers) {
    return {
        campaign_id: 88,
        campaign_status: status,
        readiness: { ready: status === 'ready', reason: blockers.length ? 'Required production evidence is missing.' : 'All production gates passed.' },
        runtime: { queue_configuration_ready: true },
        horizons: ['1m', '3m', '6m'].map((horizon) => ({
            horizon,
            ready: blockers.length === 0,
            blocking_reasons: blockers,
            required_source_dates: ['2026-09-01', '2026-09-02'],
        })),
        linked_runs: [],
        feature_coverage: { status: 'unknown' },
        calibration: { status: 'unknown' },
        folds: [],
        baseline: { status: 'unknown' },
        candidate: { status: 'unknown' },
    };
}

async function installAdminMlMocks(page) {
    let campaignStatus = null;
    let report = acceptanceReport('blocked', ['Production source-date evidence is unknown.']);
    const actions = [];
    await page.route(/\/(sanctum\/csrf-cookie|api\/)/, async (route) => {
        const { pathname, searchParams } = new URL(route.request().url());
        const method = route.request().method();
        const json = (data, status = 200) => route.fulfill({
            status,
            contentType: 'application/json',
            body: JSON.stringify(data),
        });
        if (pathname.endsWith('/sanctum/csrf-cookie')) return route.fulfill({ status: 204, body: '' });
        if (pathname.endsWith('/api/auth/csrf-token')) return json({ token: 'e2e-csrf' });
        if (pathname.endsWith('/api/auth/me')) return json({ user: { ...TEST_USER, is_admin: true } });
        if (pathname.endsWith('/api/portfolios')) return json({ data: [] });
        if (pathname.endsWith('/api/guided-tour')) return json({ success: true, data: { eligible: false, show_welcome_prompt: false } });
        if (pathname.endsWith('/api/v1/admin/ml/acceptance') && method === 'GET') return json({ success: true, data: report });
        if (pathname.endsWith('/api/v1/admin/ml/acceptance/sources') && method === 'GET') {
            return json({ success: true, data: { data: [], current_page: Number(searchParams.get('page') || 1), last_page: 1 } });
        }
        if (pathname.endsWith('/api/v1/admin/ml/acceptance/campaigns') && method === 'POST') {
            campaignStatus = 'preflight';
            report = acceptanceReport(campaignStatus, ['Required dated source mapping is unresolved.']);
            return json({ success: true, data: { id: 88, status: campaignStatus } }, 201);
        }
        const campaignAction = pathname.match(/\/api\/v1\/admin\/ml\/acceptance\/campaigns\/88\/(start|resume|cancel)$/);
        if (campaignAction && method === 'POST') {
            actions.push(campaignAction[1]);
            if (campaignAction[1] === 'start') {
                campaignStatus = 'training';
                report = acceptanceReport(campaignStatus, []);
            }
            return json({ success: true, data: { id: 88, status: campaignStatus } });
        }
        if (pathname.endsWith('/api/v1/admin/ml')) return json({ success: true, data: {} });
        if (pathname.endsWith('/api/v1/admin/ml/runs')) return json({ success: true, data: { runs: [] } });
        if (pathname.endsWith('/api/v1/admin/ml/retention-plan')) return json({ success: true, data: { artifacts: [], applied: [] } });
        return json({ success: false, error: { code: 'UNMOCKED', message: `${method} ${pathname}` } }, 501);
    });
    return actions;
}

test.describe('V9-UX-001 composite journey coverage', () => {
    for (const viewport of VIEWPORTS) {
        test(`E2E-08 uses production ML acceptance preflight and explicit training controls (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-08');
            await seedDeterministicJourney(page, `e2e08-production-ml-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const actions = await installAdminMlMocks(page);
            await page.goto('/settings/ml-scoring');

            const acceptance = page.getByRole('heading', { name: 'Production ML acceptance' }).locator('..');
            await expect(acceptance).toContainText('Campaign: blocked');
            await expect(acceptance).toContainText('Production source-date evidence is unknown.');
            await expect(acceptance.getByRole('button', { name: 'Start 1m/3m/6m training' })).toHaveCount(0);

            await acceptance.getByRole('button', { name: 'Queue campaign preflight' }).click();
            await expect(acceptance).toContainText('Campaign: preflight');
            await expect(acceptance).toContainText('Required dated source mapping is unresolved.');
            await expect(acceptance.getByRole('button', { name: 'Start 1m/3m/6m training' })).toHaveCount(0);
            expect(actions).toEqual([]);

            // The guided panel exposes Start only after a fresh passing preflight.
            // Keep the mocked campaign blocked here: actual source validation and
            // production qualification require the protected admin acceptance service.
        });
    }
});
