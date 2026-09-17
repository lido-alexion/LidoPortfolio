export function normalizeDashboardChartNumber(value) {
    if (value == null || (typeof value === 'string' && value.trim() === '')) {
        return null;
    }
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : null;
}
