import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const ORIGIN = 'http://127.0.0.1:4177';
const FINANCIAL_YEAR = '2026-27';
const DATE = '2026-10-01';
const AMOUNT = '125';
const REFERENCE = 'synthetic-bank-dividend-2026-10';
const IMPORT_REFERENCE = 'broker-statement-import-771';
const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

const preferences = {
    primary_benchmark_id: 1,
    comparison_benchmark_ids: [],
    include_in_account_performance: true,
    include_in_account_tax: true,
    risk_free_rate: 0.04,
    annualization_days: 252,
};

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
        received_on: '2026-09-15',
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
    let commitOnNextAttempt = mode === 'commit-uncertain';
    let nextId = 701;

    page.on('request', request => {
        const url = new URL(request.url());
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
        const record = { request, url, terminal: false, response: null, failure: null };
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

    await page.route('**/api/portfolios', route => route.fulfill({ json: { data: [{ id: 1, name: 'Main', is_default: true }] } }));
    await page.route('**/api/stocks?*', route => route.fulfill({ json: { data: [{ id: 42, symbol: 'TCS', name: 'Tata Consultancy Services' }] } }));
    await page.route(/\/api\/(analysis\/(performance|account-performance|attribution|preferences|benchmarks)|tax\/report)(\?.*)?$/, route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(request.method()).toBe('GET');
        expect(request.headers()['x-profile-id']).toBe('1');
        if (url.pathname === '/api/tax/report') {
            expect([...url.searchParams.entries()]).toEqual([['financial_year', FINANCIAL_YEAR]]);
        } else if (url.pathname === '/api/analysis/preferences') {
            expect([...url.searchParams.entries()]).toEqual(url.searchParams.has('scope') ? [['scope', 'account']] : []);
        } else if (url.pathname === '/api/analysis/performance' || url.pathname === '/api/analysis/account-performance') {
            expect([...url.searchParams.keys()].sort()).toEqual(['from', 'to']);
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
        return route.fulfill({ json: { data } });
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
                dividends.push({ id: nextId++, stock_id: null, received_on: DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE });
            }
            return route.fulfill({ status: 503, json: { message: 'Synthetic dividend write could not be confirmed.' } });
        }
        expect(mode).toBe('not-committed-then-retried');
        expect(attempts).toHaveLength(2);
        expect(dividends.some(row => row.source_reference === REFERENCE)).toBe(false);
        const row = { id: nextId++, stock_id: null, received_on: DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE };
        dividends.push(row);
        return route.fulfill({ status: 201, json: { data: row } });
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
            return route.fulfill({ json: { data: {
                definition_version: 'generic-dividend-csv.v1', dry_run: true, valid: true,
                candidate_rows: 1, duplicates: 0, imported: 0, errors: [],
            } } });
        }
        expect(mode).toBe('import-uncertain');
        expect(imports.filter(item => !item.dryRun)).toHaveLength(1);
        const row = {
            id: nextId++, stock_id: 42, received_on: '2026-10-02', amount: '37.5000',
            currency: 'INR', source: 'broker_statement', source_reference: IMPORT_REFERENCE,
        };
        dividends.push(row);
        return route.fulfill({ status: 503, json: { message: 'Synthetic dividend import could not be confirmed.' } });
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
        return route.fulfill({
            status: 200,
            contentType: 'text/csv; charset=UTF-8',
            headers: {
                'content-disposition': `attachment; filename="${filename}"`,
                'cache-control': 'private, no-store',
                'x-content-type-options': 'nosniff',
            },
            body,
        });
    });

    await page.route('**/api/ops/api-failures', route => {
        const body = route.request().postDataJSON();
        expect(route.request().method()).toBe('POST');
        expect(body).toMatchObject({ method: 'post', status: 503 });
        expect(['/api/tax/dividends', '/api/tax/dividends/import']).toContain(body.endpoint);
        diagnosticReports.push(body);
        return route.fulfill({ status: 204, body: '' });
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
        finish: async () => {
            await expect.poll(() => [...requests.values()].every(record => record.terminal)).toBe(true);
            for (const response of responses) {
                expect(await response.finished()).toBeNull();
            }
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

async function readDividendExport(page, fixture) {
    const responsePromise = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/api/tax/exports/dividends');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export dividends', exact: true }).click();
    const [response, download] = await Promise.all([responsePromise, downloadPromise]);
    expect(response.status()).toBe(200);
    expect(await response.finished()).toBeNull();
    const responseBytes = await response.body();
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) chunks.push(Buffer.from(chunk));
    const downloadedBytes = Buffer.concat(chunks);
    expect(await download.failure()).toBeNull();
    expect(download.suggestedFilename()).toBe(fixture.filename);
    expect(downloadedBytes).toEqual(responseBytes);
    expect(responseBytes.toString('utf8')).toBe(fixture.body);
    expect(response.headers()['content-disposition']).toBe(`attachment; filename="${fixture.filename}"`);
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
            await page.goto('/portfolio/performance-tax');
            const panel = page.getByRole('region', { name: 'Record tax evidence', exact: true });
            await expect(panel).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Account Tax · FY 2026-27', exact: true })).toBeVisible();
            expect(fixture.attempts).toHaveLength(0);
            expect(fixture.dividends.some(row => row.source_reference === REFERENCE)).toBe(false);

            await panel.getByLabel('Dividend date', { exact: true }).fill(DATE);
            await panel.getByLabel('Dividend amount', { exact: true }).fill(AMOUNT);
            await panel.getByLabel('Dividend reference', { exact: true }).fill(REFERENCE);
            const failedSavePromise = waitForManualDividendResponse(page);
            await panel.getByRole('button', { name: 'Record dividend', exact: true }).click();
            const failedSave = await failedSavePromise;
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
            const expectedRow = { id: 701, stock_id: null, received_on: DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE };
            const expected = dividendExport([fixture.dividends[0], expectedRow]);
            await readDividendExport(page, { body: expected, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
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
            await page.goto('/portfolio/performance-tax');
            const panel = page.getByRole('region', { name: 'Record tax evidence', exact: true });
            await expect(panel).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Account Tax · FY 2026-27', exact: true })).toBeVisible();

            await panel.getByLabel('Dividend date', { exact: true }).fill(DATE);
            await panel.getByLabel('Dividend amount', { exact: true }).fill(AMOUNT);
            await panel.getByLabel('Dividend reference', { exact: true }).fill(REFERENCE);
            const failedSavePromise = waitForManualDividendResponse(page);
            await panel.getByRole('button', { name: 'Record dividend', exact: true }).click();
            const failedSave = await failedSavePromise;
            expect(failedSave.status()).toBe(503);
            expect(await failedSave.finished()).toBeNull();
            expect(await failedSave.json()).toEqual({ message: 'Synthetic dividend write could not be confirmed.' });
            await expect(panel.getByRole('alert')).toHaveText('Tax evidence could not be recorded. Review existing evidence before retrying.');
            await expect(panel.getByLabel('Dividend amount', { exact: true })).toHaveValue(AMOUNT);
            expect(fixture.attempts).toEqual([{ received_on: DATE, amount: AMOUNT, source_reference: REFERENCE }]);
            expect(fixture.dividends.filter(row => row.source_reference === REFERENCE)).toHaveLength(0);

            const absentBody = dividendExport(fixture.dividends);
            await readDividendExport(page, { body: absentBody, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
            expect(fixture.exports.at(-1).rows).toEqual([{
                id: 640, stock_id: null, received_on: '2026-09-15', amount: '25.0000', currency: 'INR',
                source: 'manual', source_reference: 'unrelated-existing-evidence',
            }]);
            expect(absentBody).toContain('640,,2026-09-15,25.0000,INR,manual,unrelated-existing-evidence');
            expect(absentBody).not.toContain(REFERENCE);

            const savedPromise = waitForManualDividendResponse(page);
            await panel.getByRole('button', { name: 'Record dividend', exact: true }).click();
            const saved = await savedPromise;
            expect(saved.status()).toBe(201);
            expect(await saved.finished()).toBeNull();
            expect(await saved.json()).toEqual({ data: {
                id: 701, stock_id: null, received_on: DATE, amount: '125.0000', currency: 'INR',
                source: 'manual', source_reference: REFERENCE,
            } });
            await expect(panel.getByRole('alert')).toHaveCount(0);
            await expect.poll(() => fixture.attempts.length).toBe(2);
            await expect.poll(() => fixture.dividends.filter(row => row.source_reference === REFERENCE).length).toBe(1);
            expect(fixture.attempts[1]).toEqual(fixture.attempts[0]);
            await expect.poll(() => fixture.reportReads.some(read => read.dividendIds.includes(701))).toBe(true);
            expect(fixture.reportReads.at(-1).financialYear).toBe(FINANCIAL_YEAR);
            expect(fixture.reportReads.at(-1).report.summary.dividend_income).toBe(150);
            await expect(page.getByText('₹ 150.00', { exact: true })).toBeVisible();

            const savedRow = { id: 701, stock_id: null, received_on: DATE, amount: '125.0000', currency: 'INR', source: 'manual', source_reference: REFERENCE };
            const savedBody = dividendExport([...fixture.dividends]);
            expect(savedBody).toContain('701,,2026-10-01,125.0000,INR,manual,synthetic-bank-dividend-2026-10');
            await readDividendExport(page, { body: savedBody, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
            expect(fixture.exports.at(-1).rows).toEqual([fixture.dividends[0], savedRow]);
            expect(fixture.dividends.filter(row => row.source_reference === REFERENCE)).toHaveLength(1);
            expect(fixture.attempts).toHaveLength(2);
            await fixture.finish();
            expect(fixture.diagnosticReports).toHaveLength(1);
        });

        test('inspects existing rows after an uncertain validated CSV import without applying twice', async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-27');
            const fixture = await prepareTaxEvidenceJourney(page, viewport, 'import-uncertain');
            await page.goto('/portfolio/performance-tax');
            const panel = page.getByRole('region', { name: 'Record tax evidence', exact: true });
            await expect(panel).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Account Tax · FY 2026-27', exact: true })).toBeVisible();
            const upload = panel.getByLabel('Dividend CSV · generic-dividend-csv.v1', { exact: true });
            await upload.setInputFiles({
                name: 'synthetic-dividend.csv',
                mimeType: 'text/csv',
                buffer: Buffer.from('date,symbol,amount,reference\n2026-10-02,TCS,37.50,broker-statement-import-771\n'),
            });
            await panel.getByRole('button', { name: 'Preview', exact: true }).click();
            await expect(panel).toContainText('Valid true · candidates 1 · duplicates 0 · imported 0');
            expect(fixture.imports.map(item => item.dryRun)).toEqual([true]);
            expect(fixture.dividends.some(row => row.source_reference === IMPORT_REFERENCE)).toBe(false);
            await expect(panel.getByRole('button', { name: 'Import validated rows', exact: true })).toBeEnabled();

            const failedImportPromise = page.waitForResponse(response => response.request().method() === 'POST'
                && new URL(response.url()).pathname === '/api/tax/dividends/import');
            await panel.getByRole('button', { name: 'Import validated rows', exact: true }).click();
            const failedImport = await failedImportPromise;
            expect(failedImport.status()).toBe(503);
            expect(await failedImport.finished()).toBeNull();
            expect(await failedImport.json()).toEqual({ message: 'Synthetic dividend import could not be confirmed.' });
            await expect(panel.getByRole('alert')).toHaveText('Dividend CSV import could not be confirmed. Review existing evidence before retrying.');
            await expect(panel).toContainText('Valid true · candidates 1 · duplicates 0 · imported 0');
            expect(fixture.imports.map(item => item.dryRun)).toEqual([true, false]);
            expect(fixture.dividends.filter(row => row.source_reference === IMPORT_REFERENCE)).toHaveLength(1);

            const importedRow = {
                id: 701, stock_id: 42, received_on: '2026-10-02', amount: '37.5000', currency: 'INR',
                source: 'broker_statement', source_reference: IMPORT_REFERENCE,
            };
            const expected = dividendExport([fixture.dividends[0], importedRow]);
            await readDividendExport(page, { body: expected, filename: `stox-tax-dividends-${FINANCIAL_YEAR}-20261019-100000.csv` });
            expect(fixture.exports.at(-1).rows).toEqual([fixture.dividends[0], importedRow]);
            expect(fixture.exports.at(-1).body).toContain('701,42,2026-10-02,37.5000,INR,broker_statement,broker-statement-import-771');
            expect(fixture.imports.map(item => item.dryRun)).toEqual([true, false]);
            expect(fixture.dividends.filter(row => row.source_reference === IMPORT_REFERENCE)).toHaveLength(1);
            await fixture.finish();
            expect(fixture.diagnosticReports).toHaveLength(1);
        });
    });
}
