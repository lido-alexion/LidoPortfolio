import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const ORIGIN = 'http://127.0.0.1:4177';
const FINANCIAL_YEAR = '2026-27';
const DATE = '2026-10-01';
// Eloquent's date cast serializes through Carbon::toJSON(); this app and its
// locked Laravel/Carbon dependencies use UTC ISO-8601 with microseconds.
const SERIALIZED_DATE = '2026-10-01T00:00:00.000000Z';
const AMOUNT = '125';
const REFERENCE = 'synthetic-bank-dividend-2026-10';
const IMPORT_REFERENCE = 'broker-statement-import-771';
const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const EMPTY_NOTIFICATION_CENTER_RESPONSE = {
    data: [],
    meta: {
        current_page: 1,
        last_page: 1,
        per_page: 5,
        total: 0,
        unread_count: 0,
        active_critical_count: 0,
    },
};

const preferences = {
    primary_benchmark_id: 1,
    comparison_benchmark_ids: [],
    include_in_account_performance: true,
    include_in_account_tax: true,
    risk_free_rate: 0.04,
    annualization_days: 252,
};

function receiptKey(request) {
    const url = new URL(request.url());
    const query = url.pathname === '/api/analysis/preferences'
        ? (url.searchParams.get('scope') === 'account' ? '?scope=account' : '')
        : url.pathname === '/api/tax/report' || url.pathname === '/api/tax/exports/dividends'
            ? `?financial_year=${url.searchParams.get('financial_year')}`
            : url.pathname === '/api/stocks'
                ? `?limit=${url.searchParams.get('limit')}`
                : url.pathname === '/api/notification-center'
                    ? `?per_page=${url.searchParams.get('per_page')}`
                : '';
    return `${request.method()} ${url.pathname}${query}`;
}

const INITIAL_RECEIPT_KEYS = [
    'GET /api/portfolios',
    'GET /api/stocks?limit=100',
    'GET /api/analysis/performance',
    'GET /api/analysis/account-performance',
    'GET /api/analysis/attribution',
    `GET /api/tax/report?financial_year=${FINANCIAL_YEAR}`,
    'GET /api/analysis/preferences',
    'GET /api/analysis/preferences?scope=account',
    'GET /api/analysis/benchmarks',
];

function isReceiptRelevant(request) {
    const path = new URL(request.url()).pathname;
    return path === '/api/portfolios'
        || path === '/api/stocks'
        || /^\/api\/analysis\/(performance|account-performance|attribution|preferences|benchmarks)$/.test(path)
        || path === '/api/tax/report'
        || path === '/api/tax/dividends'
        || path === '/api/tax/dividends/import'
        || path === '/api/tax/exports/dividends'
        || path === '/api/notification-center'
        || path === '/api/ops/api-failures';
}

const taxReport = {
    financial_year: FINANCIAL_YEAR,
    period: { from: '2026-04-01', to: '2027-03-31' },
    calculation_mode: 'configured',
    request_cutoff_at: '2026-10-19 10:00:00',
    portfolio_ids: [1],
    summary: { short_term_realized_gain: 0, long_term_realized_gain: 0, dividend_income: 25, estimated_tax: 0 },
    realized_disposals: [],
    open_lots_informational: [],
    dividends: [],
    losses: [],
    confirmed_carryforward_losses: [],
    tax_rule_versions: [],
    completeness: 'incomplete',
    limitations: ['Synthetic fixture evidence only.'],
    assumptions: {
        jurisdiction: 'India', lot_method: 'fifo', canonical_accounting_method: 'wavg',
        long_term_holding_days: 'effective_dated_platform_rule', tax_advice: false,
    },
};

function csvCell(value) {
    const text = String(value ?? '');
    return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

function dividendExport(rows) {
    const lines = [
        ['schema_version', 'stox.tax-export.v1'],
        ['dataset', 'dividends'],
        ['account_id', '1'],
        ['financial_year', FINANCIAL_YEAR],
        ['calculation_mode', 'configured'],
        ['portfolio_ids', '1'],
        ['currency', 'INR'],
        ['completeness', 'incomplete'],
        ['request_cutoff', '2026-10-19T10:00:00+00:00'],
        [],
        ['id', 'stock_id', 'received_on', 'amount', 'currency', 'source', 'source_reference'],
        ...rows.map(row => [row.id, row.stock_id, row.received_on, row.amount, row.currency, row.source, row.source_reference]),
    ];
    return `${lines.map(line => line.map(csvCell).join(',')).join('\n')}\n`;
}

async function prepareTaxEvidenceJourney(page, viewport, mode) {
    await seedDeterministicJourney(page, `e2e27-tax-inspection-${mode}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);

    const dividends = [{
        id: 640,
        stock_id: null,
        received_on: '2026-09-15T00:00:00.000000Z',
        amount: '25.0000',
        currency: 'INR',
        source: 'manual',
        source_reference: 'unrelated-existing-evidence',
    }];
    const attempts = [];
    const imports = [];
    const exports = [];
    const reportReads = [];
    const blocked = [];
    const requests = new Map();
    const responses = [];
    const diagnosticReports = [];
    const notificationReads = [];
    const analysisReads = [];
    let commitOnNextAttempt = mode === 'commit-uncertain';
    let nextId = 701;
    let nextRequestSequence = 0;
    const expectedReceipts = new Map();
    const verifiedReceipts = new Set();

    function recordReceipt(request, status, kind, body) {
        expectedReceipts.set(request, { status, kind, body: kind === 'json' ? structuredClone(body) : body });
    }
    function fulfillJson(route, body, status = 200) {
        recordReceipt(route.request(), status, 'json', body);
        return route.fulfill({ status, json: body });
    }
    function fulfillText(route, body, status, contentType, headers = {}) {
        recordReceipt(route.request(), status, 'text', body);
        return route.fulfill({ status, ...(contentType ? { contentType } : {}), headers, body });
    }

    page.on('request', request => {
        const url = new URL(request.url());
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
        const record = { request, url, sequence: nextRequestSequence++, terminal: false, response: null, failure: null };
        requests.set(request, record);
        if (url.origin !== ORIGIN) blocked.push(`origin ${url.origin}`);
    });
    page.on('response', response => {
        const record = requests.get(response.request());
        if (record) {
            record.response = response;
            responses.push(response);
        }
    });
    page.on('requestfinished', request => {
        const record = requests.get(request);
        if (record) record.terminal = true;
    });
    page.on('requestfailed', request => {
        const record = requests.get(request);
        if (record) {
            record.terminal = true;
            record.failure = request.failure()?.errorText ?? 'unknown request failure';
            blocked.push(`${request.method()} ${new URL(request.url()).pathname}: ${record.failure}`);
        }
    });

    await page.route('**/api/portfolios', route => {
        expect(route.request().method()).toBe('GET');
        return fulfillJson(route, { data: [{ id: 1, name: 'Main', is_default: true }] });
    });
    await page.route('**/api/stocks?*', route => {
        const request = route.request();
        expect(request.method()).toBe('GET');
        expect(request.headers()['x-profile-id']).toBe('1');
        expect([...new URL(request.url()).searchParams.entries()]).toEqual([['limit', '100']]);
        return fulfillJson(route, { data: [{ id: 42, symbol: 'TCS', name: 'Tata Consultancy Services' }] });
    });
    await page.route(/\/api\/(analysis\/(performance|account-performance|attribution|preferences|benchmarks)|tax\/report)(\?.*)?$/, route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(request.method()).toBe('GET');
        expect(request.headers()['x-profile-id']).toBe('1');
        if (url.pathname === '/api/tax/report') {
            expect([...url.searchParams.entries()]).toEqual([['financial_year', FINANCIAL_YEAR]]);
        } else if (url.pathname === '/api/analysis/preferences') {
            expect([...url.searchParams.entries()]).toEqual(url.searchParams.has('scope') ? [['scope', 'account']] : []);
        } else if (['/api/analysis/performance', '/api/analysis/account-performance', '/api/analysis/attribution'].includes(url.pathname)) {
            expect([...url.searchParams.keys()].sort()).toEqual(['from', 'to']);
            const from = url.searchParams.get('from');
            const to = url.searchParams.get('to');
            expect(from).toMatch(/^\d{4}-\d{2}-\d{2}$/);
            expect(to).toMatch(/^\d{4}-\d{2}-\d{2}$/);
            expect(from < to).toBe(true);
            analysisReads.push({ path: url.pathname, from, to });
        } else {
            expect([...url.searchParams.entries()]).toEqual([]);
        }
        const data = url.pathname === '/api/analysis/preferences'
            ? preferences
            : url.pathname === '/api/analysis/benchmarks'
                ? [{ id: 1, name: 'NIFTY 50 TRI', return_type: 'tri' }]
                : url.pathname === '/api/analysis/performance'
                    ? { xirr_percent: null, twr_percent: null, excess_return_percent: null, maximum_drawdown_percent: null, completeness: 'incomplete', benchmark: null, volatility_percent: null, sharpe_ratio: null }
                : url.pathname === '/api/analysis/account-performance'
                    ? { portfolio_ids: [1], calculation_mode: 'configured', xirr_percent: null, twr_percent: null }
                    : url.pathname === '/api/analysis/attribution'
                        ? { method: 'synthetic', explained_net_realized: 0, reconciliation_residual: null, limitations: ['Synthetic fixture evidence only.'] }
                        : (() => {
                            const report = {
                                ...taxReport,
                                financial_year: url.searchParams.get('financial_year'),
                                dividends: structuredClone(dividends),
                                summary: {
                                    ...taxReport.summary,
                                    dividend_income: dividends.reduce((sum, row) => sum + Number(row.amount), 0),
                                },
                            };
                            reportReads.push({ financialYear: url.searchParams.get('financial_year'), dividendIds: dividends.map(row => row.id), report });
                            return report;
                        })();
        return fulfillJson(route, { data });
    });

    await page.route('**/api/tax/dividends', route => {
        const request = route.request();
        expect(request.method()).toBe('POST');
        expect(request.headers()['x-profile-id']).toBe('1');
        const body = request.postDataJSON();
        expect(body).toEqual({ received_on: DATE, amount: AMOUNT, source_reference: REFERENCE });
        attempts.push(structuredClone(body));
        if (attempts.length === 1) {
            if (commitOnNextAttempt) {
                dividends.push({ id: nextId++, stock_id: null, received_on: SERIALIZED_DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE });
            }
            return fulfillJson(route, { message: 'Synthetic dividend write could not be confirmed.' }, 503);
        }
        expect(mode).toBe('not-committed-then-retried');
        expect(attempts).toHaveLength(2);
        expect(dividends.some(row => row.source_reference === REFERENCE)).toBe(false);
        const row = { id: nextId++, stock_id: null, received_on: SERIALIZED_DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE };
        dividends.push(row);
        return fulfillJson(route, { data: { ...row, received_on: SERIALIZED_DATE, stock: null } }, 201);
    });

    await page.route('**/api/tax/dividends/import', route => {
        const request = route.request();
        expect(request.method()).toBe('POST');
        expect(request.headers()['x-profile-id']).toBe('1');
        expect(request.headers()['content-type']).toMatch(/^multipart\/form-data; boundary=/);
        const body = request.postDataBuffer()?.toString('utf8') ?? '';
        expect(body).toContain('name="definition_version"\r\n\r\ngeneric-dividend-csv.v1');
        expect(body).toContain('date,symbol,amount,reference\n2026-10-02,TCS,37.50,broker-statement-import-771');
        expect(body).toMatch(/name="dry_run"\r\n\r\n[01]/);
        const dryRun = /name="dry_run"\r\n\r\n1/.test(body);
        if (!dryRun) expect(body).toContain('name="dry_run"\r\n\r\n0');
        imports.push({ dryRun, body });
        if (dryRun) {
            return fulfillJson(route, { data: {
                definition_version: 'generic-dividend-csv.v1', dry_run: true, valid: true,
                candidate_rows: 1, duplicates: 0, imported: 0, errors: [],
            } });
        }
        expect(mode).toBe('import-uncertain');
        expect(imports.filter(item => !item.dryRun)).toHaveLength(1);
        const row = {
            id: nextId++, stock_id: 42, received_on: '2026-10-02T00:00:00.000000Z', amount: '37.5000',
            currency: 'INR', source: 'broker_statement', source_reference: IMPORT_REFERENCE,
        };
        dividends.push(row);
        return fulfillJson(route, { message: 'Synthetic dividend import could not be confirmed.' }, 503);
    });

    await page.route('**/api/tax/exports/dividends?*', route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(request.method()).toBe('GET');
        expect([...url.searchParams.entries()]).toEqual([['financial_year', FINANCIAL_YEAR]]);
        expect(request.headers()['x-profile-id']).toBe('1');
        const body = dividendExport(dividends);
        const filename = `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv`;
        exports.push({ rows: structuredClone(dividends), body, filename, query: url.search });
        return fulfillText(route, body, 200, 'text/csv; charset=UTF-8', {
                'content-disposition': `attachment; filename="${filename}"`,
                'cache-control': 'private, no-store',
                'x-content-type-options': 'nosniff',
        });
    });

    await page.route('**/api/notification-center*', route => {
        const request = route.request();
        const url = new URL(request.url());
        const profileId = request.headers()['x-profile-id'] || null;
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(request.method()).toBe('GET');
        expect(url.pathname).toBe('/api/notification-center');
        expect(url.search).toBe('?per_page=5');
        expect([null, '1']).toContain(profileId);
        notificationReads.push({ request, profileId });
        return fulfillJson(route, EMPTY_NOTIFICATION_CENTER_RESPONSE);
    });

    await page.route('**/api/ops/api-failures', route => {
        const request = route.request();
        const url = new URL(request.url());
        const body = request.postDataJSON();
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(url.pathname).toBe('/api/ops/api-failures');
        expect(url.search).toBe('');
        expect(request.method()).toBe('POST');
        expect(request.headers()['x-profile-id']).toBe('1');
        expect(Object.keys(body).sort()).toEqual(['endpoint', 'message', 'method', 'request_id', 'status']);
        expect(body.method).toBe('post');
        expect(body.status).toBe(503);
        expect(['/api/tax/dividends', '/api/tax/dividends/import']).toContain(body.endpoint);
        expect(body.message === null || (typeof body.message === 'string' && body.message.length <= 500)).toBe(true);
        expect(body.request_id === null || (typeof body.request_id === 'string' && body.request_id.length <= 128)).toBe(true);
        diagnosticReports.push(body);
        return fulfillJson(route, { data: { accepted: true } }, 202);
    });

    await page.route('**/*', route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin !== ORIGIN) {
            blocked.push(`origin ${url.origin}`);
            return route.abort();
        }
        const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (write && !(
            request.method() === 'POST' && url.pathname === '/api/tax/dividends'
            || request.method() === 'POST' && url.pathname === '/api/tax/dividends/import'
            || request.method() === 'POST' && url.pathname === '/api/ops/api-failures'
        )) {
            blocked.push(`${request.method()} ${url.pathname}`);
            return route.abort();
        }
        return route.fallback();
    });

    async function settlePhase(startSequence = 0, requiredKeys = []) {
        await expect.poll(() => {
            const phaseReceipts = [...requests.values()]
                .filter(record => record.sequence >= startSequence && isReceiptRelevant(record.request))
                .map(record => record.request);
            const keys = new Set(phaseReceipts.map(receiptKey));
            return requiredKeys.every(key => keys.has(key))
                && phaseReceipts.every(request => {
                    const record = requests.get(request);
                    return expectedReceipts.has(request) && record?.terminal === true && record.response !== null;
                });
        }).toBe(true);

        const phaseReceipts = [...requests.values()]
            .filter(record => record.sequence >= startSequence && isReceiptRelevant(record.request))
            .map(record => record.request);
        for (const request of phaseReceipts) {
            if (verifiedReceipts.has(request)) continue;
            const record = requests.get(request);
            const expected = expectedReceipts.get(request);
            expect(record, `${request.method()} ${record?.url.pathname} must have an observed request receipt`).toBeDefined();
            expect(record.failure).toBeNull();
            expect(record.response, `${request.method()} ${record.url.pathname} must receive its HTTP response`).not.toBeNull();
            expect(record.response.status()).toBe(expected.status);
            expect(await record.response.finished()).toBeNull();
            const responseBody = await record.response.body();
            if (expected.kind === 'json') {
                expect(JSON.parse(responseBody.toString('utf8'))).toEqual(expected.body);
            } else {
                expect(responseBody.toString('utf8')).toBe(expected.body);
            }
            verifiedReceipts.add(request);
        }
    }

    return {
        attempts,
        blocked,
        diagnosticReports,
        dividends,
        exports,
        imports,
        reportReads,
        requests,
        responses,
        startPhase: () => nextRequestSequence,
        settlePhase,
        assertAnalysisRange: async (page) => {
            const from = await page.getByLabel('Performance from', { exact: true }).inputValue();
            const to = await page.getByLabel('Performance to', { exact: true }).inputValue();
            const paths = new Set(analysisReads.map(read => read.path));
            expect([...paths].sort()).toEqual([
                '/api/analysis/performance',
                '/api/analysis/account-performance',
                '/api/analysis/attribution',
            ].sort());
            for (const read of analysisReads) expect({ from: read.from, to: read.to }).toEqual({ from, to });
        },
        finish: async () => {
            await expect.poll(() => [...requests.values()].every(record => record.terminal)).toBe(true);
            await settlePhase(0);
            expect(notificationReads.length).toBeGreaterThan(0);
            for (const { request, profileId } of notificationReads) {
                const url = new URL(request.url());
                expect(request.method()).toBe('GET');
                expect(url.origin).toBe(ORIGIN);
                expect(url.pathname).toBe('/api/notification-center');
                expect(url.search).toBe('?per_page=5');
                expect([null, '1']).toContain(profileId);
            }
            for (const response of responses) expect(await response.finished()).toBeNull();
            for (const record of requests.values()) expect(record.failure).toBeNull();
            for (const { request, url } of requests.values()) {
                expect(url.origin).toBe(ORIGIN);
                if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method())) {
                    expect(request.method()).toBe('POST');
                    expect(['/api/tax/dividends', '/api/tax/dividends/import', '/api/ops/api-failures']).toContain(url.pathname);
                }
            }
            expect(blocked).toEqual([]);
        },
    };
}

async function readDividendExport(page, fixture, expected) {
    const phase = fixture.startPhase();
    const responsePromise = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/api/tax/exports/dividends');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export dividends', exact: true }).click();
    const [response, download] = await Promise.all([responsePromise, downloadPromise]);
    await fixture.settlePhase(phase, [`GET /api/tax/exports/dividends?financial_year=${FINANCIAL_YEAR}`]);
    expect(response.status()).toBe(200);
    expect(await response.finished()).toBeNull();
    const responseBytes = await response.body();
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) chunks.push(Buffer.from(chunk));
    const downloadedBytes = Buffer.concat(chunks);
    expect(await download.failure()).toBeNull();
    expect(download.suggestedFilename()).toBe(expected.filename);
    expect(downloadedBytes).toEqual(responseBytes);
    expect(responseBytes.toString('utf8')).toBe(expected.body);
    expect(response.headers()).toMatchObject({
        'content-type': 'text/csv; charset=UTF-8',
        'content-disposition': `attachment; filename="${expected.filename}"`,
        'cache-control': 'private, no-store',
        'x-content-type-options': 'nosniff',
    });
    return responseBytes.toString('utf8');
}

function waitForManualDividendResponse(page) {
    return page.waitForResponse(response => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/api/tax/dividends');
}

for (const viewport of VIEWPORTS) {
    test.describe(`E2E-27 tax source-evidence inspection · ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });

        test('inspects an existing dividend after an uncertain save before deciding not to repeat it', async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-27');
            const fixture = await prepareTaxEvidenceJourney(page, viewport, 'commit-uncertain');
            const initialPhase = fixture.startPhase();
            await page.goto('/portfolio/performance-tax');
            await fixture.settlePhase(initialPhase, INITIAL_RECEIPT_KEYS);
            await fixture.assertAnalysisRange(page);
            const panel = page.getByRole('region', { name: 'Record tax evidence', exact: true });
            await expect(panel).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Account Tax · FY 2026-27', exact: true })).toBeVisible();
            expect(fixture.attempts).toHaveLength(0);
            expect(fixture.dividends.some(row => row.source_reference === REFERENCE)).toBe(false);

            await panel.getByLabel('Dividend date', { exact: true }).fill(DATE);
            await panel.getByLabel('Dividend amount', { exact: true }).fill(AMOUNT);
            await panel.getByLabel('Dividend reference', { exact: true }).fill(REFERENCE);
            const failedSavePhase = fixture.startPhase();
            const failedSavePromise = waitForManualDividendResponse(page);
            await panel.getByRole('button', { name: 'Record dividend', exact: true }).click();
            const failedSave = await failedSavePromise;
            await fixture.settlePhase(failedSavePhase, ['POST /api/tax/dividends']);
            expect(failedSave.status()).toBe(503);
            expect(await failedSave.finished()).toBeNull();
            expect(await failedSave.json()).toEqual({ message: 'Synthetic dividend write could not be confirmed.' });
            await expect(panel.getByRole('alert')).toHaveText('Tax evidence could not be recorded. Review existing evidence before retrying.');
            await expect(page.getByText('Tax evidence recorded', { exact: true })).toHaveCount(0);
            await expect(panel.getByLabel('Dividend date', { exact: true })).toHaveValue(DATE);
            await expect(panel.getByLabel('Dividend amount', { exact: true })).toHaveValue(AMOUNT);
            await expect(panel.getByLabel('Dividend reference', { exact: true })).toHaveValue(REFERENCE);
            expect(fixture.attempts).toEqual([{ received_on: DATE, amount: AMOUNT, source_reference: REFERENCE }]);
            expect(fixture.dividends.filter(row => row.source_reference === REFERENCE)).toHaveLength(1);

            const currentExport = fixture.exports.length;
            const expectedRow = { id: 701, stock_id: null, received_on: SERIALIZED_DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE };
            const expected = dividendExport([fixture.dividends[0], expectedRow]);
            const committedExport = await readDividendExport(page, fixture, { body: expected, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
            expect(committedExport.split(`701,,${SERIALIZED_DATE},125.0000,INR,manual,${REFERENCE}`)).toHaveLength(2);
            expect(fixture.exports).toHaveLength(currentExport + 1);
            expect(fixture.exports.at(-1).rows).toEqual([fixture.dividends[0], expectedRow]);
            expect(fixture.attempts).toHaveLength(1);
            expect(fixture.dividends.filter(row => row.source_reference === REFERENCE)).toHaveLength(1);
            await fixture.finish();
            expect(fixture.diagnosticReports).toHaveLength(1);
        });

        test('checks the exact candidate is absent before an explicit retry, then verifies one exported record', async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-27');
            const fixture = await prepareTaxEvidenceJourney(page, viewport, 'not-committed-then-retried');
            const initialPhase = fixture.startPhase();
            await page.goto('/portfolio/performance-tax');
            await fixture.settlePhase(initialPhase, INITIAL_RECEIPT_KEYS);
            await fixture.assertAnalysisRange(page);
            const panel = page.getByRole('region', { name: 'Record tax evidence', exact: true });
            await expect(panel).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Account Tax · FY 2026-27', exact: true })).toBeVisible();

            await panel.getByLabel('Dividend date', { exact: true }).fill(DATE);
            await panel.getByLabel('Dividend amount', { exact: true }).fill(AMOUNT);
            await panel.getByLabel('Dividend reference', { exact: true }).fill(REFERENCE);
            const failedSavePhase = fixture.startPhase();
            const failedSavePromise = waitForManualDividendResponse(page);
            await panel.getByRole('button', { name: 'Record dividend', exact: true }).click();
            const failedSave = await failedSavePromise;
            await fixture.settlePhase(failedSavePhase, ['POST /api/tax/dividends']);
            expect(failedSave.status()).toBe(503);
            expect(await failedSave.finished()).toBeNull();
            expect(await failedSave.json()).toEqual({ message: 'Synthetic dividend write could not be confirmed.' });
            await expect(panel.getByRole('alert')).toHaveText('Tax evidence could not be recorded. Review existing evidence before retrying.');
            await expect(panel.getByLabel('Dividend amount', { exact: true })).toHaveValue(AMOUNT);
            expect(fixture.attempts).toEqual([{ received_on: DATE, amount: AMOUNT, source_reference: REFERENCE }]);
            expect(fixture.dividends.filter(row => row.source_reference === REFERENCE)).toHaveLength(0);

            const absentBody = dividendExport(fixture.dividends);
            const absentExport = await readDividendExport(page, fixture, { body: absentBody, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
            expect(fixture.exports.at(-1).rows).toEqual([{
                id: 640, stock_id: null, received_on: '2026-09-15T00:00:00.000000Z', amount: '25.0000', currency: 'INR',
                source: 'manual', source_reference: 'unrelated-existing-evidence',
            }]);
            expect(absentExport).toContain('640,,2026-09-15T00:00:00.000000Z,25.0000,INR,manual,unrelated-existing-evidence');
            expect(absentExport).not.toContain(REFERENCE);

            const savedPhase = fixture.startPhase();
            const refreshedReportPromise = page.waitForResponse(response => response.request().method() === 'GET'
                && new URL(response.url()).pathname === '/api/tax/report'
                && new URL(response.url()).searchParams.get('financial_year') === FINANCIAL_YEAR);
            const savedPromise = waitForManualDividendResponse(page);
            await panel.getByRole('button', { name: 'Record dividend', exact: true }).click();
            const saved = await savedPromise;
            const refreshedReportResponse = await refreshedReportPromise;
            await fixture.settlePhase(savedPhase, ['POST /api/tax/dividends', `GET /api/tax/report?financial_year=${FINANCIAL_YEAR}`]);
            expect(saved.status()).toBe(201);
            expect(await saved.finished()).toBeNull();
            expect(await saved.json()).toEqual({ data: {
                id: 701, stock_id: null, received_on: SERIALIZED_DATE, amount: '125.0000', currency: 'INR',
                source: 'manual', source_reference: REFERENCE, stock: null,
            } });
            await expect(panel.getByRole('alert')).toHaveCount(0);
            await expect.poll(() => fixture.attempts.length).toBe(2);
            await expect.poll(() => fixture.dividends.filter(row => row.source_reference === REFERENCE).length).toBe(1);
            expect(fixture.attempts[1]).toEqual(fixture.attempts[0]);
            expect(refreshedReportResponse.status()).toBe(200);
            expect(fixture.reportReads.at(-1).dividendIds).toContain(701);
            expect(fixture.reportReads.at(-1).financialYear).toBe(FINANCIAL_YEAR);
            expect(fixture.reportReads.at(-1).report.summary.dividend_income).toBe(150);
            await expect(page.getByText('₹ 150.00', { exact: true })).toBeVisible();

            const savedRow = { id: 701, stock_id: null, received_on: SERIALIZED_DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE };
            const savedBody = dividendExport([...fixture.dividends]);
            expect(savedBody).toContain(`701,,${SERIALIZED_DATE},125.0000,INR,manual,synthetic-bank-dividend-2026-10`);
            const savedExport = await readDividendExport(page, fixture, { body: savedBody, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
            const savedRowLine = `701,,${SERIALIZED_DATE},125.0000,INR,manual,${REFERENCE}`;
            expect(savedExport.split(savedRowLine)).toHaveLength(2);
            expect(fixture.exports.at(-1).rows).toEqual([fixture.dividends[0], savedRow]);
            expect(fixture.dividends.filter(row => row.source_reference === REFERENCE)).toHaveLength(1);
            expect(fixture.attempts).toHaveLength(2);
            await fixture.finish();
            expect(fixture.diagnosticReports).toHaveLength(1);
        });

        test('inspects existing rows after an uncertain validated CSV import without applying twice', async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-27');
            const fixture = await prepareTaxEvidenceJourney(page, viewport, 'import-uncertain');
            const initialPhase = fixture.startPhase();
            await page.goto('/portfolio/performance-tax');
            await fixture.settlePhase(initialPhase, INITIAL_RECEIPT_KEYS);
            await fixture.assertAnalysisRange(page);
            const panel = page.getByRole('region', { name: 'Record tax evidence', exact: true });
            await expect(panel).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Account Tax · FY 2026-27', exact: true })).toBeVisible();
            const upload = panel.getByLabel('Dividend CSV · generic-dividend-csv.v1', { exact: true });
            await upload.setInputFiles({
                name: 'synthetic-dividend.csv',
                mimeType: 'text/csv',
                buffer: Buffer.from('date,symbol,amount,reference\n2026-10-02,TCS,37.50,broker-statement-import-771\n'),
            });
            const previewPhase = fixture.startPhase();
            const previewPromise = page.waitForResponse(response => response.request().method() === 'POST'
                && new URL(response.url()).pathname === '/api/tax/dividends/import');
            await panel.getByRole('button', { name: 'Preview', exact: true }).click();
            const previewResponse = await previewPromise;
            await fixture.settlePhase(previewPhase, ['POST /api/tax/dividends/import']);
            expect(previewResponse.status()).toBe(200);
            await expect(panel).toContainText('Valid true · candidates 1 · duplicates 0 · imported 0');
            expect(fixture.imports.map(item => item.dryRun)).toEqual([true]);
            expect(fixture.dividends.some(row => row.source_reference === IMPORT_REFERENCE)).toBe(false);
            await expect(panel.getByRole('button', { name: 'Import validated rows', exact: true })).toBeEnabled();

            const failedImportPhase = fixture.startPhase();
            const failedImportPromise = page.waitForResponse(response => response.request().method() === 'POST'
                && new URL(response.url()).pathname === '/api/tax/dividends/import');
            await panel.getByRole('button', { name: 'Import validated rows', exact: true }).click();
            const failedImport = await failedImportPromise;
            await fixture.settlePhase(failedImportPhase, ['POST /api/tax/dividends/import']);
            expect(failedImport.status()).toBe(503);
            expect(await failedImport.finished()).toBeNull();
            expect(await failedImport.json()).toEqual({ message: 'Synthetic dividend import could not be confirmed.' });
            await expect(panel.getByRole('alert')).toHaveText('Dividend CSV import could not be confirmed. Review existing evidence before retrying.');
            await expect(panel).toContainText('Valid true · candidates 1 · duplicates 0 · imported 0');
            expect(fixture.imports.map(item => item.dryRun)).toEqual([true, false]);
            expect(fixture.dividends.filter(row => row.source_reference === IMPORT_REFERENCE)).toHaveLength(1);

            const importedRow = {
                id: 701, stock_id: 42, received_on: '2026-10-02T00:00:00.000000Z', amount: '37.5000', currency: 'INR',
                source: 'broker_statement', source_reference: IMPORT_REFERENCE,
            };
            const expected = dividendExport([fixture.dividends[0], importedRow]);
            const importedExport = await readDividendExport(page, fixture, { body: expected, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
            expect(fixture.exports.at(-1).rows).toEqual([fixture.dividends[0], importedRow]);
            expect(importedExport.split('701,42,2026-10-02T00:00:00.000000Z,37.5000,INR,broker_statement,broker-statement-import-771')).toHaveLength(2);
            expect(fixture.imports.map(item => item.dryRun)).toEqual([true, false]);
            expect(fixture.dividends.filter(row => row.source_reference === IMPORT_REFERENCE)).toHaveLength(1);
            await fixture.finish();
            expect(fixture.diagnosticReports).toHaveLength(1);
        });
    });
}
