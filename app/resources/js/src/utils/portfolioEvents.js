import { clearAllDashboardCaches } from './dashboardCache';

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

export function notifyPortfolioDashboardRefresh() {
    clearAllDashboardCaches();
    window.dispatchEvent(new CustomEvent(PORTFOLIO_DASHBOARD_REFRESH));
}

export function notifyPortfolioChanged(portfolioId) {
    window.dispatchEvent(new CustomEvent(PORTFOLIO_CHANGED, {
        detail: { portfolioId },
    }));
    notifyPortfolioDashboardRefresh();
}

export function notifyPortfolioDeleted(portfolioId) {
    getCrossTabChannel()?.postMessage({
        type: 'deleted',
        portfolioId: portfolioId == null ? null : String(portfolioId),
    });
}

export function subscribePortfolioCrossTab(handler) {
    const channel = getCrossTabChannel();
    if (!channel) {
        return () => {};
    }
    const onMessage = (event) => {
        handler(event.data);
    };
    channel.addEventListener('message', onMessage);
    return () => channel.removeEventListener('message', onMessage);
}
