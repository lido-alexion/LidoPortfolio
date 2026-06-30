export const DEFAULT_TIME_RANGE = 'all';
export const DEFAULT_SAMPLING = '1d';

export const TIME_RANGE_OPTIONS = [
    { value: 'all', label: 'All' },
    { value: '1m', label: '1M' },
    { value: '3m', label: '3M' },
    { value: '6m', label: '6M' },
    { value: '1y', label: '1Y' },
];

export const SAMPLING_OPTIONS = [
    { value: '1d', label: '1 day', step: 1 },
    { value: '5d', label: '5 days', step: 5 },
    { value: '10d', label: '10 days', step: 10 },
    { value: '1m', label: '1 month', step: 30 },
];

export const VOLUME_COLOR_UP = '#198754';
export const VOLUME_COLOR_DOWN = '#dc3545';
export const VOLUME_COLOR_NEUTRAL = '#6c757d';

export function samplingStepForValue(samplingValue) {
    const option = SAMPLING_OPTIONS.find((item) => item.value === samplingValue);
    return option?.step ?? 1;
}
