const DEFAULT_TIMEZONE = 'Asia/Kolkata';

/**
 * Format an ISO timestamp in the configured scheduler timezone with an explicit label.
 */
export function formatSchedulerTimestamp(value, timezone = DEFAULT_TIMEZONE) {
    if (!value) {
        return '—';
    }

    const tz = timezone?.trim() || DEFAULT_TIMEZONE;

    try {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: tz,
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZoneName: 'short',
        }).formatToParts(date);

        const pick = (type) => parts.find((part) => part.type === type)?.value ?? '';
        const dayPeriod = pick('dayPeriod').toUpperCase();
        const dateTime = `${pick('day')} ${pick('month')}, ${pick('year')}, ${pick('hour')}:${pick('minute')} ${dayPeriod}`;
        const zoneLabel = pick('timeZoneName');
        if (zoneLabel) {
            return `${dateTime} ${zoneLabel}`;
        }

        return `${dateTime} ${tz}`;
    } catch {
        return String(value);
    }
}
