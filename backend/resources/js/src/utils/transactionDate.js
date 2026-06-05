const MONTH_ABBREV = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

const MONTH_LOOKUP = Object.fromEntries(
    MONTH_ABBREV.map((label, index) => [label.toLowerCase(), index + 1]),
);

const DISPLAY_DATE_RE = /^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/;

/** Local calendar date as YYYY-MM-DD (not UTC). */
export function getLocalTodayDateString() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

export function isValidIsoDateString(value) {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

/** Normalize API / ISO values to YYYY-MM-DD in local calendar. */
export function normalizeToIsoDateString(value) {
    if (!value) {
        return null;
    }
    if (isValidIsoDateString(value)) {
        return value;
    }
    const match = String(value).match(/^(\d{4}-\d{2}-\d{2})/);
    if (match) {
        return match[1];
    }
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) {
        return null;
    }
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/** Format as dd-mmm-yyyy (e.g. 28-May-2026). */
export function formatTransactionDateDisplay(value) {
    const iso = normalizeToIsoDateString(value);
    if (!iso) {
        return '';
    }
    const [year, month, day] = iso.split('-').map(Number);
    const monthLabel = MONTH_ABBREV[month - 1];
    if (!monthLabel) {
        return '';
    }
    return `${String(day).padStart(2, '0')}-${monthLabel}-${year}`;
}

/** Shorter date for chart x-axis ticks (e.g. 28-May). */
export function formatChartAxisDate(value) {
    const iso = normalizeToIsoDateString(value);
    if (!iso) {
        return '';
    }
    const [, month, day] = iso.split('-').map(Number);
    const monthLabel = MONTH_ABBREV[month - 1];
    if (!monthLabel) {
        return '';
    }
    return `${String(day).padStart(2, '0')}-${monthLabel}`;
}

/** Parse dd-mmm-yyyy to YYYY-MM-DD, or null if invalid. */
export function parseTransactionDateDisplay(value) {
    if (typeof value !== 'string') {
        return null;
    }
    const match = value.trim().match(DISPLAY_DATE_RE);
    if (!match) {
        return null;
    }
    const day = Number(match[1]);
    const month = MONTH_LOOKUP[match[2].toLowerCase()];
    const year = Number(match[3]);
    if (!month || day < 1 || day > 31 || year < 1000 || year > 9999) {
        return null;
    }
    const iso = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const check = new Date(iso);
    if (
        Number.isNaN(check.getTime())
        || check.getFullYear() !== year
        || check.getMonth() + 1 !== month
        || check.getDate() !== day
    ) {
        return null;
    }
    return iso;
}

export function resolveTransactionDateIso(displayOrIso, fallbackIso = null) {
    const fromDisplay = parseTransactionDateDisplay(displayOrIso);
    if (fromDisplay) {
        return fromDisplay;
    }
    const normalized = normalizeToIsoDateString(displayOrIso);
    if (normalized) {
        return normalized;
    }
    return normalizeToIsoDateString(fallbackIso);
}

/** True when date is after today in the user's local timezone. */
export function isTransactionDateInFuture(dateStr) {
    const iso = normalizeToIsoDateString(dateStr);
    if (!iso) {
        return false;
    }
    return iso > getLocalTodayDateString();
}

export function isValidTransactionDate(dateStr) {
    const iso = normalizeToIsoDateString(dateStr);
    return Boolean(iso) && !isTransactionDateInFuture(iso);
}
