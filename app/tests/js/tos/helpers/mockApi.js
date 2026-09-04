import { vi } from 'vitest';
import {
    CAPITAL_RESOLUTION,
    DEFAULT_SCREENER,
    DISCOVERY_CANDIDATE,
    EMPTY_REVIEW_DASHBOARD,
    OPEN_BUY_RECOMMENDATION,
    SAMPLE_REVIEW_REPORT,
    TEST_PORTFOLIO,
    TEST_USER,
    apiEnvelope,
    axiosOk,
} from '../fixtures/tosApi.js';

export const apiMock = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    request: vi.fn(),
};

function pathOf(url) {
    return String(url || '').split('?')[0];
}

export function axiosError(message, { status = 500, body } = {}) {
    const error = new Error(message);
    error.response = {
        status,
        data: body ?? {
            success: false,
            error: { code: 'SERVER_ERROR', message },
        },
        headers: {},
    };
    return error;
}

export function resetApiMock() {
    Object.values(apiMock).forEach((fn) => {
        if (typeof fn.mockReset === 'function') {
            fn.mockReset();
        }
    });
}

/**
 * Default authenticated TOS handlers. Override per-test via options or further mockImplementation.
 */
export function installDefaultTosHandlers({
    recommendations = [OPEN_BUY_RECOMMENDATION],
    candidates = [DISCOVERY_CANDIDATE],
    screeners = [DEFAULT_SCREENER],
    failRecommendations = false,
    failCandidates = false,
    delayRecommendationsMs = 0,
    delayCandidatesMs = 0,
    pipelineError = null,
    executionMode = 'manual',
    entitled = false,
    totpEnabled = false,
    modeBlockers = ['entitlement', 'totp', 'broker'],
    canSubmitSemiAutomatic = false,
    canSubmitAutomatic = false,
    reviews = [],
    reviewsMeta = null,
    getReviews = null,
    reviewById = null,
    reviewDashboard = EMPTY_REVIEW_DASHBOARD,
    generatedReport = SAMPLE_REVIEW_REPORT,
    generateError = null,
} = {}) {
    let recs = recommendations.map((r) => ({ ...r }));

    apiMock.get.mockImplementation(async (url, config) => {
        const path = pathOf(url);
        if (path === '/auth/me') {
            return axiosOk({ user: TEST_USER });
        }
        if (path === '/portfolios') {
            return axiosOk({ data: [TEST_PORTFOLIO] });
        }
        if (path === '/v1/recommendations') {
            if (failRecommendations) {
                throw axiosError('Recommendations unavailable', {
                    status: 500,
                    body: {
                        success: false,
                        error: { code: 'SERVER_ERROR', message: 'Recommendations unavailable' },
                    },
                });
            }
            if (delayRecommendationsMs > 0) {
                await new Promise((resolve) => {
                    setTimeout(resolve, delayRecommendationsMs);
                });
            }
            return axiosOk(apiEnvelope(recs));
        }
        const recMatch = path.match(/^\/v1\/recommendations\/(\d+)$/);
        if (recMatch) {
            const id = Number(recMatch[1]);
            const rec = recs.find((r) => r.id === id);
            if (!rec) {
                throw axiosError('Recommendation not found', {
                    status: 404,
                    body: { success: false, error: { code: 'NOT_FOUND', message: 'Recommendation not found.' } },
                });
            }
            return axiosOk(apiEnvelope(rec));
        }
        if (path.match(/^\/v1\/recommendations\/\d+\/capital-resolution$/)) {
            return axiosOk(apiEnvelope(CAPITAL_RESOLUTION));
        }
        if (path === '/v1/candidates') {
            if (failCandidates) {
                throw axiosError('Candidates unavailable', {
                    status: 500,
                    body: {
                        success: false,
                        error: { code: 'SERVER_ERROR', message: 'Candidates unavailable' },
                    },
                });
            }
            if (delayCandidatesMs > 0) {
                await new Promise((resolve) => {
                    setTimeout(resolve, delayCandidatesMs);
                });
            }
            return axiosOk(apiEnvelope(candidates));
        }
        if (path === '/screeners') {
            return axiosOk({ data: screeners });
        }
        if (path === '/v1/recommendations/pending-execution') {
            return axiosOk(apiEnvelope(recs.filter((r) => r.status === 'pending_execution' || r.can_execute_manually), {
                cash: { cash_balance: 0, reserved_cash: 0, available_investable_cash: 0 },
            }));
        }
        if (path === '/v1/execution/mode') {
            return axiosOk(apiEnvelope({
                execution_mode: executionMode,
                entitled,
                totp_enabled: totpEnabled,
                blockers: modeBlockers,
                can_submit_semi_automatic: canSubmitSemiAutomatic,
                can_submit_automatic: canSubmitAutomatic,
            }));
        }
        if (path === '/v1/totp') {
            return axiosOk(apiEnvelope({ enabled: false, pending: false, confirmed_at: null }));
        }
        if (path === '/v1/broker/status') {
            return axiosOk(apiEnvelope({
                configured: false,
                connected: false,
                usable: false,
                provider: 'kite',
            }));
        }
        if (path === '/v1/reviews') {
            if (getReviews) {
                const payload = await getReviews(config);
                return axiosOk(apiEnvelope(payload.items, payload.meta));
            }
            const page = Number(config?.params?.page) || 1;
            const pageSize = Number(config?.params?.pageSize) || 20;
            const meta = reviewsMeta ?? {
                page,
                pageSize,
                total: reviews.length,
                lastPage: 1,
            };
            return axiosOk(apiEnvelope(reviews, meta));
        }
        const reviewMatch = path.match(/^\/v1\/reviews\/(\d+)$/);
        if (reviewMatch) {
            const id = Number(reviewMatch[1]);
            const report = (reviewById && reviewById[id])
                || reviews.find((row) => Number(row.id) === id)
                || (Number(generatedReport?.id) === id ? generatedReport : null);
            if (!report) {
                throw axiosError('Review report not found.', {
                    status: 404,
                    body: { success: false, error: { code: 'NOT_FOUND', message: 'Review report not found.' } },
                });
            }
            return axiosOk(apiEnvelope(report));
        }
        if (path === '/v1/review/dashboard') {
            return axiosOk(apiEnvelope(reviewDashboard));
        }
        if (path === '/v1/orders') {
            return axiosOk(apiEnvelope([]));
        }
        throw new Error(`Unexpected GET ${path}`);
    });

    apiMock.post.mockImplementation(async (url, body, config) => {
        const path = pathOf(url);
        if (path === '/v1/reviews/generate') {
            if (generateError) {
                throw generateError;
            }
            void config;
            return axiosOk(apiEnvelope({
                report: generatedReport,
                metrics: generatedReport?.metrics ?? [],
            }), { status: 201 });
        }
        if (path === '/v1/pipeline/run') {
            if (pipelineError) {
                throw pipelineError;
            }
            return axiosOk(apiEnvelope({
                pipeline_run: { id: 9 },
                stages: {
                    discovery: { candidates: 4 },
                    evaluation: { results: 4 },
                    recommendation: { count: 2 },
                },
            }), { status: 201 });
        }
        const reviewMatch = path.match(/^\/v1\/recommendations\/(\d+)\/review$/);
        if (reviewMatch) {
            const id = Number(reviewMatch[1]);
            recs = recs.filter((r) => r.id !== id);
            return axiosOk(apiEnvelope({ id, status: 'pending_execution', review_decision: body?.decision }));
        }
        if (path === '/v1/discovery/runs') {
            return axiosOk(apiEnvelope({ id: 3 }), { status: 201 });
        }
        const screenerRunMatch = path.match(/^\/screeners\/(\d+)\/run$/);
        if (screenerRunMatch) {
            return axiosOk({
                data: {
                    id: 19,
                    status: 'completed',
                    stats: { matched: 14, scanned: 500 },
                },
                completed: true,
            });
        }
        if (path === '/v1/evaluation/runs') {
            return axiosOk(apiEnvelope({ id: 5 }), { status: 201 });
        }
        if (path === '/auth/logout') {
            return axiosOk({ ok: true });
        }
        if (path === '/v1/execution/submit-selected') {
            return axiosOk(apiEnvelope((body?.recommendation_ids || []).map((id) => ({
                recommendation_id: id,
                outcome: 'submitted',
            }))));
        }
        throw new Error(`Unexpected POST ${path}`);
    });

    return {
        getRecommendations: () => recs,
    };
}
