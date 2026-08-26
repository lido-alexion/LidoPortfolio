const DEFAULT_TIMEZONE = 'Asia/Kolkata';

/**
 * Format an ISO timestamp in the configured scheduler timezone with an explicit label.
 *
 * Contract (deterministic; not machine-locale dependent):
 *   `DD Mon YYYY, HH:mm:ss <shortOffset> (<IANA>)`
 * Example:
 *   `07 Jul 2026, 05:00:04 GMT+5:30 (Asia/Kolkata)`
 *
 * Uses fixed `en-GB` + 24-hour clock + seconds. Zone display always includes the
 * IANA id passed by the caller so the label does not depend on ICU short names.
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
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
            timeZoneName: 'shortOffset',
        }).formatToParts(date);

        const pick = (type) => parts.find((part) => part.type === type)?.value ?? '';
        const hour = String(pick('hour')).padStart(2, '0');
        const minute = String(pick('minute')).padStart(2, '0');
        const second = String(pick('second')).padStart(2, '0');
        const dateTime = `${pick('day')} ${pick('month')} ${pick('year')}, ${hour}:${minute}:${second}`;
        const offset = pick('timeZoneName');

        if (offset) {
            return `${dateTime} ${offset} (${tz})`;
        }

        return `${dateTime} (${tz})`;
    } catch {
        return String(value);
    }
}
