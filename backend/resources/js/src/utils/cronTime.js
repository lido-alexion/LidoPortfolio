const CRON_TIME_RE = /^([01]\d|2[0-3]):([0-5]\d)$/;

/** Validates HH:mm in 24-hour format (00:00–23:59). */
export function isValidCronTime(value) {
    if (value == null || String(value).trim() === '') {
        return true;
    }
    return CRON_TIME_RE.test(String(value).trim());
}

/** Normalize native time input value to HH:mm. */
export function normalizeCronTime(value) {
    if (value == null || String(value).trim() === '') {
        return '';
    }
    const trimmed = String(value).trim();
    if (!isValidCronTime(trimmed)) {
        return trimmed;
    }
    return trimmed;
}
