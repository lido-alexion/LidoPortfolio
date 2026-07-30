import api from '../api';
import { showToast } from '../toast';
import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';

/**
 * Core quick-action handlers (kept out of bootstrap to avoid circular imports).
 */
export const CORE_QUICK_ACTION_HANDLERS = {
    'refresh-market-data': async () => {
        try {
            showToast('Refreshing market data…', 'info');
            const res = await api.post('/sync/daily', { force: true }, { skipErrorToast: true });
            const message = res.data?.message || 'Market data refresh started.';
            showToast(message, 'success');
            notifyPortfolioDashboardRefresh();
        } catch (err) {
            const msg = err?.response?.data?.message || err.message || 'Could not refresh market data.';
            showToast(msg, 'danger');
        }
    },
};
