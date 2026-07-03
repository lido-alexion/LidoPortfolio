const ALLOCATION_VIEW_KEY = 'portfolio_dashboard_allocation_view';
const ALLOCATION_MOBILE_METRIC_KEY = 'portfolio_dashboard_allocation_mobile_metric';

const VALID_ALLOCATION_VIEWS = new Set(['table', 'visual']);
const VALID_ALLOCATION_MOBILE_METRICS = new Set(['market', 'invested']);

export function loadAllocationViewPreference() {
    try {
        const stored = localStorage.getItem(ALLOCATION_VIEW_KEY);
        if (stored && VALID_ALLOCATION_VIEWS.has(stored)) {
            return stored;
        }
    } catch {
        // private mode — ignore
    }
    return 'table';
}

export function saveAllocationViewPreference(mode) {
    if (!VALID_ALLOCATION_VIEWS.has(mode)) {
        return;
    }
    try {
        localStorage.setItem(ALLOCATION_VIEW_KEY, mode);
    } catch {
        // Quota or private mode — ignore.
    }
}

export function loadAllocationMobileMetricPreference() {
    try {
        const stored = localStorage.getItem(ALLOCATION_MOBILE_METRIC_KEY);
        if (stored && VALID_ALLOCATION_MOBILE_METRICS.has(stored)) {
            return stored;
        }
    } catch {
        // private mode — ignore
    }
    return 'market';
}

export function saveAllocationMobileMetricPreference(metric) {
    if (!VALID_ALLOCATION_MOBILE_METRICS.has(metric)) {
        return;
    }
    try {
        localStorage.setItem(ALLOCATION_MOBILE_METRIC_KEY, metric);
    } catch {
        // Quota or private mode — ignore.
    }
}
