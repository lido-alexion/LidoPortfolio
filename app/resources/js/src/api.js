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
            || url.includes('/auth/register')
            || url.includes('/auth/me');

        if (status === 401 && !isPublicAuthRoute) {
            window.dispatchEvent(new CustomEvent('portfolio-unauthorized'));
            return Promise.reject(error);
        }

        if (status === 419) {
            resetCsrfCookie();
            showToast('Security token error. Please try again.', 'warning');
            return Promise.reject(error);
        }

        const syncErrors = error?.response?.data?.errors?.sync;
        const msg = (Array.isArray(syncErrors) ? syncErrors[0] : null)
            || error?.response?.data?.message
            || 'Request failed';

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
