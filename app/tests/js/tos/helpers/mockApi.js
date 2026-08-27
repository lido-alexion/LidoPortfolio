import { vi } from 'vitest';
import {
    CAPITAL_RESOLUTION,
    DISCOVERY_CANDIDATE,
    OPEN_BUY_RECOMMENDATION,
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
    failRecommendations = false,
    delayRecommendationsMs = 0,
    pipelineError = null,
} = {}) {
    let recs = recommendations.map((r) => ({ ...r }));

    apiMock.get.mockImplementation(async (url) => {
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
            return axiosOk(apiEnvelope(candidates));
        }
        throw new Error(`Unexpected GET ${path}`);
    });

    apiMock.post.mockImplementation(async (url, body) => {
        const path = pathOf(url);
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
        if (path === '/v1/evaluation/runs') {
            return axiosOk(apiEnvelope({ id: 5 }), { status: 201 });
        }
        if (path === '/auth/logout') {
            return axiosOk({ ok: true });
        }
        throw new Error(`Unexpected POST ${path}`);
    });

    return {
        getRecommendations: () => recs,
    };
}
