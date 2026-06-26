export const PORTFOLIO_DASHBOARD_REFRESH = 'portfolio-dashboard-refresh';
export const PORTFOLIO_CHANGED = 'portfolio-changed';

export function notifyPortfolioDashboardRefresh() {
    window.dispatchEvent(new CustomEvent(PORTFOLIO_DASHBOARD_REFRESH));
}

export function notifyPortfolioChanged(portfolioId) {
    window.dispatchEvent(new CustomEvent(PORTFOLIO_CHANGED, {
        detail: { portfolioId },
    }));
    notifyPortfolioDashboardRefresh();
}
