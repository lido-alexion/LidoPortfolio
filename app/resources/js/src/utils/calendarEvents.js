export const RECURRENCE_TYPES = {
    none: 'One time',
    daily: 'Daily',
    weekly: 'Weekly (day of week)',
    monthly_day: 'Monthly (day of month)',
    monthly_weekday: 'Monthly (weekday of month)',
    yearly_day: 'Yearly (fixed date)',
    yearly_weekday: 'Yearly (weekday of month)',
};

export const WEEKDAY_OPTIONS = [
    { value: 0, label: 'Sunday' },
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
];

export const WEEK_OF_MONTH_OPTIONS = [
    { value: 1, label: '1st' },
    { value: 2, label: '2nd' },
    { value: 3, label: '3rd' },
    { value: 4, label: '4th' },
    { value: 5, label: '5th' },
    { value: -1, label: 'Last' },
];

export const MONTH_OPTIONS = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

export const CALENDAR_COLOR_PRESETS = [
    '#6366f1',
    '#2563eb',
    '#0891b2',
    '#059669',
    '#ca8a04',
    '#ea580c',
    '#dc2626',
    '#db2777',
    '#7c3aed',
    '#475569',
    '#b45309',
];

export const TRADE_HOLIDAY_CATEGORY = 'trade_holiday';
export const TRADE_HOLIDAY_COLOR = '#b45309';

export const CALENDAR_EVENT_PRESETS = [
    {
        label: 'F&O expiry (last Thu)',
        title: 'F&O expiry',
        color: '#ea580c',
        recurrence_type: 'monthly_weekday',
        recurrence_config: { week_of_month: -1, weekday: 4 },
    },
    {
        label: 'Options expiry (last Thu)',
        title: 'Options expiry',
        color: '#2563eb',
        recurrence_type: 'monthly_weekday',
        recurrence_config: { week_of_month: -1, weekday: 4 },
    },
];

export function defaultEventForm() {
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');

    return {
        title: '',
        description: '',
        color: '#6366f1',
        category: null,
        anchor_date: `${yyyy}-${mm}-${dd}`,
        recurrence_type: 'monthly_weekday',
        recurrence_config: {
            interval: 1,
            weekday: 4,
            month_day: today.getDate(),
            month: today.getMonth() + 1,
            week_of_month: -1,
        },
        recurrence_end_date: '',
        reminder_enabled: false,
        reminder_days_before: [0],
        is_active: true,
    };
}

export function eventToForm(event) {
    if (!event) {
        return defaultEventForm();
    }

    return {
        title: event.title ?? '',
        description: event.description ?? '',
        color: event.is_trade_holiday ? TRADE_HOLIDAY_COLOR : (event.color ?? '#6366f1'),
        category: event.category ?? null,
        anchor_date: event.anchor_date ?? '',
        recurrence_type: event.recurrence_type ?? 'none',
        recurrence_config: {
            interval: 1,
            weekday: 4,
            month_day: 1,
            month: 1,
            week_of_month: 1,
            ...(event.recurrence_config ?? {}),
        },
        recurrence_end_date: event.recurrence_end_date ?? '',
        reminder_enabled: Boolean(event.reminder_enabled),
        reminder_days_before: Array.isArray(event.reminder_days_before) && event.reminder_days_before.length
            ? event.reminder_days_before
            : [0],
        is_active: event.is_active !== false,
    };
}

export function calendarMonthsToRender(referenceDate = new Date()) {
    const year = referenceDate.getFullYear();
    const month = referenceDate.getMonth();
    const months = [];

    for (let m = 0; m < 12; m += 1) {
        months.push({ year, month: m });
    }

    if (month >= 9) {
        for (let m = 0; m < 3; m += 1) {
            months.push({ year: year + 1, month: m });
        }
    }

    return months;
}

export function monthLabel(year, monthIndex) {
    return new Date(year, monthIndex, 1).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    });
}

export function buildMonthGrid(year, monthIndex) {
    const first = new Date(year, monthIndex, 1);
    const startWeekday = first.getDay();
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
    const cells = [];

    for (let i = 0; i < startWeekday; i += 1) {
        cells.push(null);
    }
    for (let day = 1; day <= daysInMonth; day += 1) {
        cells.push(new Date(year, monthIndex, day));
    }

    return cells;
}

export function dateKey(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

export function groupOccurrencesByDate(occurrences) {
    const map = {};
    (occurrences ?? []).forEach((occurrence) => {
        const key = occurrence.date;
        if (!map[key]) {
            map[key] = [];
        }
        map[key].push(occurrence);
    });
    return map;
}

export function distinctColorsForDate(events) {
    const seen = new Set();
    const colors = [];
    (events ?? []).forEach((event) => {
        const color = event.color || '#6366f1';
        if (!seen.has(color)) {
            seen.add(color);
            colors.push(color);
        }
    });
    return colors;
}

export function conicGradientForColors(colors) {
    if (!colors?.length) {
        return null;
    }
    if (colors.length === 1) {
        return colors[0];
    }
    const slice = 360 / colors.length;
    const stops = colors.map((color, index) => {
        const start = (index * slice).toFixed(2);
        const end = ((index + 1) * slice).toFixed(2);
        return `${color} ${start}deg ${end}deg`;
    });
    return `conic-gradient(${stops.join(', ')})`;
}

export function formatDaysAhead(daysAhead) {
    const n = Number(daysAhead);
    if (Number.isNaN(n)) {
        return '';
    }
    if (n === 0) {
        return 'Today';
    }
    if (n === 1) {
        return 'Tomorrow';
    }
    return `${n} days ahead`;
}

export function recurrenceSummary(event) {
    if (!event) {
        return '';
    }
    const type = event.recurrence_type;
    const config = event.recurrence_config ?? {};
    const weekday = WEEKDAY_OPTIONS.find((w) => w.value === Number(config.weekday));
    const weekOf = WEEK_OF_MONTH_OPTIONS.find((w) => w.value === Number(config.week_of_month));
    const month = MONTH_OPTIONS.find((m) => m.value === Number(config.month));

    switch (type) {
    case 'daily':
        return 'Repeats daily';
    case 'weekly':
        return weekday ? `Repeats every ${weekday.label}` : 'Repeats weekly';
    case 'monthly_day':
        return `Repeats monthly on day ${config.month_day ?? '?'}`;
    case 'monthly_weekday':
        return weekOf && weekday
            ? `Repeats ${weekOf.label} ${weekday.label} of each month`
            : 'Repeats monthly on weekday';
    case 'yearly_day':
        return month
            ? `Repeats yearly on ${month.label} ${config.month_day ?? '?'}`
            : 'Repeats yearly';
    case 'yearly_weekday':
        return month && weekOf && weekday
            ? `Repeats ${weekOf.label} ${weekday.label} of ${month.label}`
            : 'Repeats yearly on weekday';
    default:
        return 'One-time event';
    }
}
