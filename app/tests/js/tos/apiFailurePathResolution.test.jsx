import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';

vi.unmock('../../../resources/js/src/api');

vi.mock('../../../resources/js/src/services/logger', () => ({
    default: { debug: vi.fn(), error: vi.fn() },
    createRequestId: () => 'safe-request-id',
}));
vi.mock('../../../resources/js/src/portfolio/activePortfolioStorage', () => ({
    getActivePortfolioId: () => 'profile-42',
}));
vi.mock('../../../resources/js/src/portfolio/portfolioRecovery', () => ({
    isPortfolioNotFoundError: () => false,
    recoverStaleActivePortfolio: vi.fn(),
}));
vi.mock('../../../resources/js/src/telemetry/otelBrowser', () => ({
    isBrowserOpenTelemetryRegistered: () => false,
}));
vi.mock('../../../resources/js/src/telemetry/lidoTelemetry', () => ({ getTraceparent: () => null }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

import api from '../../../resources/js/src/api';

function setAppBase(base) {
    let meta = document.querySelector('meta[name="app-base"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'app-base';
        document.head.append(meta);
    }
    meta.setAttribute('content', base);
}

function createHttpError(config, status) {
    const response = status === undefined
        ? undefined
        : {
            config,
            data: { message: 'private response body must not be forwarded' },
            headers: {},
            status,
            statusText: 'Failure',
        };

    return new AxiosError(
        status === undefined ? 'Network Error' : `Request failed with status code ${status}`,
        status === undefined ? AxiosError.ERR_NETWORK : AxiosError.ERR_BAD_RESPONSE,
        config,
        undefined,
        response,
    );
}

function installLocalAdapter({ reportFails = false } = {}) {
    const requests = [];
    let sourceError;

    api.defaults.adapter = async (config) => {
        const uri = api.getUri(config);
        const pathname = new URL(uri, window.location.origin).pathname;
        const isReport = pathname.endsWith('/api/ops/api-failures');
        requests.push({ config, isReport, uri });

        if (isReport && !reportFails) {
            return {
                config,
                data: { accepted: true },
                headers: {},
                status: 202,
                statusText: 'Accepted',
            };
        }

        const failure = createHttpError(config, isReport ? 502 : 503);
        if (!isReport) sourceError = failure;
        throw failure;
    };

    return { requests, get sourceError() { return sourceError; } };
}

async function issueAndCollect(url) {
    const local = installLocalAdapter();
    const error = await api.get(url).catch((caught) => caught);

    await vi.waitFor(() => {
        expect(local.requests.filter((request) => request.isReport)).toHaveLength(1);
    });

    return { error, ...local };
}

describe('central API failure path resolution (V9-OPS-002)', () => {
    beforeEach(() => {
        document.querySelector('meta[name="app-base"]')?.remove();
        delete window.__LIDO_APP_BASE__;
        api.defaults.adapter = undefined;
    });

    it('uses Axios URI joining for leading and non-leading paths at root and under a subdirectory', async () => {
        expect(api.getUri({ baseURL: '/api', url: '/tax/dividends' })).toBe('/api/tax/dividends');
        expect(api.getUri({ baseURL: '/portfolio/api', url: '/tax/dividends' })).toBe('/portfolio/api/tax/dividends');

        const cases = [
            { base: '', url: '/tax/dividends?fy=2025-26&account=private', endpoint: '/api/tax/dividends' },
            { base: '', url: 'tax/dividends?fy=2025-26', endpoint: '/api/tax/dividends' },
            { base: '/portfolio', url: '/tax/dividends?fy=2025-26', endpoint: '/api/tax/dividends' },
            { base: '/portfolio', url: 'tax/dividends?fy=2025-26', endpoint: '/api/tax/dividends' },
        ];

        for (const testCase of cases) {
            setAppBase(testCase.base);
            const { error, requests, sourceError } = await issueAndCollect(testCase.url);
            expect(error).toBe(sourceError);

            const report = requests.find((request) => request.isReport);
            expect(JSON.parse(report.config.data)).toEqual({
                method: 'get',
                endpoint: testCase.endpoint,
                status: 503,
                message: 'Request failed with status code 503',
                request_id: 'safe-request-id',
            });
            expect(JSON.stringify(JSON.parse(report.config.data))).not.toContain('2025-26');

            const source = requests.find((request) => !request.isReport);
            expect(source.config.headers.get('X-CSRF-TOKEN')).toBe('test-csrf');
            expect(source.config.headers.get('X-Profile-Id')).toBe('profile-42');
        }
    });

    it('recognizes an absolute same-origin StoX API URI and strips its query values', async () => {
        setAppBase('/portfolio');
        const { error, requests, sourceError } = await issueAndCollect(
            `${window.location.origin}/portfolio/api/tax/dividends?token=private&fy=2025-26`,
        );

        expect(error).toBe(sourceError);
        expect(JSON.parse(requests.find((request) => request.isReport).config.data).endpoint)
            .toBe('/api/tax/dividends');
        expect(JSON.stringify(JSON.parse(requests.find((request) => request.isReport).config.data)))
            .not.toMatch(/private|2025-26/);
    });

    it.each([
        ['external origin', () => 'https://outside.example/api/tax/dividends'],
        ['same-origin non-API path', () => `${window.location.origin}/portfolio/tax/dividends`],
        ['auth route', () => '/auth/me'],
    ])('does not report %s failures', async (_label, urlFactory) => {
        setAppBase('/portfolio');
        const local = installLocalAdapter();
        const error = await api.get(urlFactory()).catch((caught) => caught);

        expect(error).toBe(local.sourceError);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(local.requests.filter((request) => request.isReport)).toHaveLength(0);
    });

    it('reports transport failures with a null status and no recursive report', async () => {
        setAppBase('/portfolio');
        const requests = [];
        api.defaults.adapter = async (config) => {
            const uri = api.getUri(config);
            const pathname = new URL(uri, window.location.origin).pathname;
            const isReport = pathname.endsWith('/api/ops/api-failures');
            requests.push({ config, isReport });
            if (isReport) {
                return { config, data: { accepted: true }, headers: {}, status: 202, statusText: 'Accepted' };
            }
            throw new AxiosError('Network Error', AxiosError.ERR_NETWORK, config);
        };

        await api.get('/tax/dividends').catch(() => {});
        await vi.waitFor(() => expect(requests.filter((request) => request.isReport)).toHaveLength(1));
        expect(JSON.parse(requests.find((request) => request.isReport).config.data).status).toBeNull();
        expect(requests).toHaveLength(2);
    });

    it('preserves the original business error when the diagnostic POST also fails', async () => {
        setAppBase('/portfolio');
        const local = installLocalAdapter({ reportFails: true });
        const error = await api.get('/tax/dividends').catch((caught) => caught);

        expect(error).toBe(local.sourceError);
        await vi.waitFor(() => expect(local.requests.filter((request) => request.isReport)).toHaveLength(1));
        expect(local.requests).toHaveLength(2);
        expect(local.requests.filter((request) => request.isReport)).toHaveLength(1);
    });
});
