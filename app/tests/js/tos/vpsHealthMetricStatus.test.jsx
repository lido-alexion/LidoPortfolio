import { describe, expect, it } from 'vitest';
import { formatVpsSampleAge, getVpsFpmStatus, getVpsHealthMetricStatus, getVpsSampleAgeStatus } from '../../../resources/js/src/pages/vpsHealthMetricStatus';

describe('VPS health metric severity thresholds', () => {
    it.each([
        ['loadPerCore', 0.99, 'normal'],
        ['loadPerCore', 1, 'warning'],
        ['loadPerCore', 2, 'critical'],
        ['ramAvailable', 20, 'normal'],
        ['ramAvailable', 19.9, 'warning'],
        ['ramAvailable', 9.9, 'critical'],
        ['rootDiskUsed', 79.9, 'normal'],
        ['rootDiskUsed', 80, 'warning'],
        ['rootDiskUsed', 90, 'critical'],
        ['swapUsed', 49.9, 'normal'],
        ['swapUsed', 50, 'warning'],
        ['swapUsed', 80, 'warning'],
        ['swapUsed', 80.1, 'critical'],
        ['nginx5xx', 0, 'normal'],
        ['nginx5xx', 1, 'warning'],
        ['nginx5xx', 5, 'critical'],
    ])('classifies %s=%s as %s', (metric, value, expected) => {
        expect(getVpsHealthMetricStatus(metric, value)).toBe(expected);
    });

    it('marks missing and non-numeric metrics as unknown', () => {
        expect(getVpsHealthMetricStatus('ramAvailable', null)).toBe('unknown');
        expect(getVpsHealthMetricStatus('ramAvailable', 'n/a')).toBe('unknown');
    });

    it('marks queued requests critical and near-capacity workers as attention', () => {
        expect(getVpsFpmStatus({ queue: 0, active: 3, maxChildren: 5 })).toBe('normal');
        expect(getVpsFpmStatus({ queue: 0, active: 5, maxChildren: 5 })).toBe('warning');
        expect(getVpsFpmStatus({ queue: 1, active: 1, maxChildren: 5 })).toBe('critical');
    });
});

describe('VPS health sample age', () => {
    it('formats rounded ages and keeps the color boundary at two minutes', () => {
        expect(formatVpsSampleAge(27.43923)).toBe('28 seconds ago');
        expect(formatVpsSampleAge(119.999)).toBe('1m ago');
        expect(formatVpsSampleAge(120)).toBe('2m ago');
        expect(getVpsSampleAgeStatus(119.999)).toBe('normal');
        expect(getVpsSampleAgeStatus(120)).toBe('critical');
        expect(getVpsSampleAgeStatus(null)).toBe('unknown');
    });
});
