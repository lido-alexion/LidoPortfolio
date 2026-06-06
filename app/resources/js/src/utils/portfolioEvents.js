export const PORTFOLIO_DASHBOARD_REFRESH = 'portfolio-dashboard-refresh';

export function notifyPortfolioDashboardRefresh() {
    window.dispatchEvent(new CustomEvent(PORTFOLIO_DASHBOARD_REFRESH));
}
