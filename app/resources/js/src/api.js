import axios from 'axios';
import { getRequestCsrfToken, isPlainCsrfToken, resetCsrfCookie } from './auth/csrf';
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
    (error) => {
        const requestId = error?.config?.metadata?.requestId;
        const status = error?.response?.status;
        const url = error?.config?.url || '';
        const isPublicAuthRoute = url.includes('/auth/login')
            || url.includes('/auth/me')
            || url.includes('/invites/');

        if (status === 401 && !isPublicAuthRoute) {
            window.dispatchEvent(new CustomEvent('portfolio-unauthorized'));
            return Promise.reject(error);
        }

        if (status === 419) {
            resetCsrfCookie();
            showToast('Security token error. Please try again.', 'warning');
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
