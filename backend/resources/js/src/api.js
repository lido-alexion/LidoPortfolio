import axios from 'axios';
import { getRequestCsrfToken, resetCsrfCookie } from './auth/csrf';
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
        config.headers['X-XSRF-TOKEN'] = csrf;
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
        const isAuthRoute = url.includes('/auth/login') || url.includes('/auth/register');

        if (status === 401 && !isAuthRoute) {
            window.dispatchEvent(new CustomEvent('portfolio-unauthorized'));
            showToast('Your session has expired. Please sign in again.', 'warning');
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

        if (!isAuthRoute && !skipErrorToast) {
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
