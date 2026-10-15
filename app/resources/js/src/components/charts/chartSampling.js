export const DENSE_DAILY_CHART_POINT_THRESHOLD = 1200;

/** Automatically use monthly buckets for a long default timeline until the user chooses a cadence. */
export function resolveChartSampling({ rowCount, userSelection = null, defaultSampling = '1d', adaptive = false }) {
    if (userSelection) return userSelection;
    return adaptive && rowCount > DENSE_DAILY_CHART_POINT_THRESHOLD ? '1m' : defaultSampling;
}
