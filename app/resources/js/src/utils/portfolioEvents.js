import { clearDashboardCachesForUser } from './dashboardCache';
import { getAuthSessionUserId } from '../auth/sessionContext';

export const PORTFOLIO_DASHBOARD_REFRESH = 'portfolio-dashboard-refresh';
export const PORTFOLIO_CHANGED = 'portfolio-changed';

const CROSS_TAB_CHANNEL = 'lido-portfolio-sync';

let crossTabChannel = null;

function getCrossTabChannel() {
    if (typeof BroadcastChannel === 'undefined') {
        return null;
    }
    if (!crossTabChannel) {
        crossTabChannel = new BroadcastChannel(CROSS_TAB_CHANNEL);
    }
    return crossTabChannel;
}

export function notifyPortfolioDashboardRefresh(userId = getAuthSessionUserId()) {
    clearDashboardCachesForUser(userId);
    window.dispatchEvent(new CustomEvent(PORTFOLIO_DASHBOARD_REFRESH));
}

export function notifyPortfolioChanged(portfolioId, userId = getAuthSessionUserId()) {
    window.dispatchEvent(new CustomEvent(PORTFOLIO_CHANGED, {
        detail: { portfolioId },
    }));
    notifyPortfolioDashboardRefresh(userId);
}

export function notifyPortfolioDeleted(portfolioId, userId) {
    getCrossTabChannel()?.postMessage({
        type: 'deleted',
        portfolioId: portfolioId == null ? null : String(portfolioId),
        userId: userId == null ? null : String(userId),
    });
}

export function subscribePortfolioCrossTab(userId, handler) {
    const channel = getCrossTabChannel();
    if (!channel) {
        return () => {};
    }
    const onMessage = (event) => {
        if (String(event.data?.userId ?? '') === String(userId ?? '')) handler(event.data);
    };
    channel.addEventListener('message', onMessage);
    return () => channel.removeEventListener('message', onMessage);
}
