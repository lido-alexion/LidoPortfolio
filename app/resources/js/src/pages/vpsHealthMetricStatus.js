export const VPS_HEALTH_STATUS = Object.freeze({
    normal: { label: 'Normal', color: 'success' },
    warning: { label: 'Attention', color: 'warning' },
    critical: { label: 'Action needed', color: 'danger' },
    unknown: { label: 'No data', color: 'secondary' },
});

function numericValue(value) {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

export function getVpsHealthMetricStatus(metric, value) {
    const number = numericValue(value);
    if (number === null) return 'unknown';

    switch (metric) {
        case 'loadPerCore':
            if (number >= 2) return 'critical';
            return number >= 1 ? 'warning' : 'normal';
        case 'ramAvailable':
            if (number < 10) return 'critical';
            return number < 20 ? 'warning' : 'normal';
        case 'rootDiskUsed':
            if (number >= 90) return 'critical';
            return number >= 80 ? 'warning' : 'normal';
        case 'swapUsed':
            if (number > 80) return 'critical';
            return number >= 50 ? 'warning' : 'normal';
        case 'nginx5xx':
            if (number >= 5) return 'critical';
            return number > 0 ? 'warning' : 'normal';
        default:
            return 'unknown';
    }
}

export function getVpsFpmStatus({ queue, active, maxChildren }) {
    const queued = numericValue(queue);
    if (queued === null) return 'unknown';
    if (queued > 0) return 'critical';

    const activeCount = numericValue(active);
    const capacity = numericValue(maxChildren);
    if (activeCount !== null && capacity !== null && capacity > 0 && activeCount / capacity >= 0.9) {
        return 'warning';
    }
    return 'normal';
}
