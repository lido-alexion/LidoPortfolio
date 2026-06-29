import axios from 'axios';
import { ensureCsrfCookie, getRequestCsrfToken, isPlainCsrfToken, resetCsrfCookie } from './auth/csrf';
import { getActivePortfolioId } from './portfolio/activePortfolioStorage';
import {
    isPortfolioNotFoundError,
    recoverStaleActivePortfolio,
} from './portfolio/portfolioRecovery';
import logger, { createRequestId } from './services/logger';
import { showToast } from './toast';
import { appUrl } from './appBase';

const api = axios.create({
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
});

function getCsrfToken() {
    return getRequestCsrfToken();
}

function shouldAttachProfileHeader(url, method) {
    if (!url) {
        return true;
    }
    const path = url.split('?')[0];
    const verb = (method || 'get').toLowerCase();
    if (path === '/portfolios' && (verb === 'get' || verb === 'post')) {
        return false;
    }
    return true;
}

api.interceptors.request.use((config) => {
    config.baseURL = appUrl('/api');

    const csrf = getCsrfToken();
    if (csrf) {
        if (isPlainCsrfToken()) {
            config.headers['X-CSRF-TOKEN'] = csrf;
        } else {
            config.headers['X-XSRF-TOKEN'] = csrf;
        }
    }

    const requestId = createRequestId();
    config.headers['X-Request-ID'] = requestId;
    config.metadata = { requestId };

    const portfolioId = getActivePortfolioId();
    if (portfolioId && shouldAttachProfileHeader(config.url, config.method)) {
        config.headers['X-Profile-Id'] = portfolioId;
    }

    logger.debug('API request', {
        category: 'API',
        requestId,
        method: config.method,
        url: config.url,
    });

    return config;
});

function resolveApiErrorMessage(error) {
    const status = error?.response?.status;
    const data = error?.response?.data ?? {};
    let msg = null;

    const validationErrors = data.errors;
    if (validationErrors && typeof validationErrors === 'object') {
        const first = Object.values(validationErrors).flat().find(Boolean);
        if (first) {
            msg = first;
        }
    }

    if (!msg && Array.isArray(data.errors?.sync)) {
        msg = data.errors.sync[0];
    }

    if (!msg && data.message) {
        msg = data.message;
    }

    const trimmed = typeof msg === 'string' ? msg.trim() : '';
    const isGenericAuth = trimmed === '' || /^unauthor/i.test(trimmed);

    if (status === 403) {
        return isGenericAuth
            ? "You don't have permission to perform this action. Try signing out and back in if this seems wrong."
            : trimmed;
    }

    if (status === 404) {
        return trimmed || 'The requested item could not be found.';
    }

    if (status === 422 && trimmed) {
        return trimmed;
    }

    if (status >= 500) {
        const requestId = error?.response?.data?.request_id
            || error?.response?.headers?.['x-request-id'];
        if (trimmed && trimmed !== 'Server Error') {
            return requestId ? `${trimmed} (request ${requestId})` : trimmed;
        }
        return requestId
            ? `Server error (request ${requestId}). Check server logs or run migrations.`
            : 'Server error. Check server logs or run migrations.';
    }

    return trimmed || 'Something went wrong. Please try again.';
}

api.interceptors.response.use(
    (response) => {
        logger.debug('API response', {
            category: 'API',
            requestId: response.config?.metadata?.requestId,
            status: response.status,
            url: response.config?.url,
        });
        return response;
    },
    async (error) => {
        const requestId = error?.config?.metadata?.requestId;
        const status = error?.response?.status;
        const url = error?.config?.url || '';
        const isPublicAuthRoute = url.includes('/auth/login')
            || url.includes('/auth/me')
            || url.includes('/invites/')
            || url.includes('/reset-password/');

        if (
            isPortfolioNotFoundError(error)
            && error?.config
            && !error.config._portfolioRecoveryAttempted
            && shouldAttachProfileHeader(url, error.config.method)
        ) {
            error.config._portfolioRecoveryAttempted = true;
            const recovered = await recoverStaleActivePortfolio();
            if (recovered) {
                return api.request(error.config);
            }
        }

        if (status === 401 && !isPublicAuthRoute) {
            window.dispatchEvent(new CustomEvent('portfolio-unauthorized'));
            return Promise.reject(error);
        }

        if (status === 419) {
            if (error?.config && !error.config._csrfRetried) {
                error.config._csrfRetried = true;
                resetCsrfCookie();
                try {
                    await ensureCsrfCookie({ force: true });
                    return api.request(error.config);
                } catch {
                    // Fall through to user-visible handling below.
                }
            }

            const isAuthMutation = url.includes('/auth/login')
                || url.includes('/auth/logout')
                || url.includes('/invites/accept')
                || url.includes('/reset-password/accept');

            resetCsrfCookie();
            if (!isAuthMutation) {
                showToast('Security token error. Please try again.', 'warning');
            }
            return Promise.reject(error);
        }

        const msg = resolveApiErrorMessage(error);

        const skipErrorToast = Boolean(error?.config?.skipErrorToast);

        if (!isPublicAuthRoute && !skipErrorToast) {
            logger.error('API request failed', {
                category: 'API',
                requestId,
                status,
                api: url,
                message: msg,
            });
            showToast(msg, 'danger');
        }

        return Promise.reject(error);
    },
);

export default api;
