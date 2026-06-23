const inrFormatter2 = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const inrFormatter0 = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const inrFormatterCompact = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    notation: 'compact',
    maximumFractionDigits: 1,
});

const inrFormatterCompact0 = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    notation: 'compact',
    maximumFractionDigits: 0,
});

/** Ensures a single space after ₹ (Intl omits it). */
function withRupeeSpace(formatted) {
    return formatted.replace(/₹\s*/g, '₹ ');
}

/** Indian rupee with lakh grouping (e.g. ₹ 1,23,456.78). */
export function formatInr(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '—' : withRupeeSpace(inrFormatter2.format(num));
}

/** Whole rupees, no paise (e.g. ₹ 12,34,567) — dashboard totals. */
export function formatInrWhole(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '—' : withRupeeSpace(inrFormatter0.format(num));
}

/** Shorter INR for chart axes (e.g. ₹ 12.3L). */
export function formatInrCompact(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '—' : withRupeeSpace(inrFormatterCompact.format(num));
}

/** Compact whole rupees for chart axes (e.g. ₹ 12L). */
export function formatInrCompactWhole(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '—' : withRupeeSpace(inrFormatterCompact0.format(num));
}

const integerFormatter = new Intl.NumberFormat('en-IN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

/** Whole numbers with Indian grouping (e.g. 12,34,567) — qty, volume, etc. */
export function formatTableInteger(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '—' : integerFormatter.format(Math.round(num));
}

export function formatTableMoney2(value) {
    return formatInr(value);
}

/** Percentage with 2 decimal places (e.g. 12.34%). */
export function formatTablePercent2(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '—' : `${num.toFixed(2)}%`;
}

/** Percentage rounded to whole number (e.g. 12%). */
export function formatTablePercent0(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '—' : `${Math.round(num)}%`;
}

/** Rounded % change of latest close vs avg buy; null when not computable. */
export function percentGainLossFromAvgBuy(latestClose, avgBuyPrice) {
    const latest = Number(latestClose);
    const avg = Number(avgBuyPrice);
    if (Number.isNaN(latest) || Number.isNaN(avg) || avg <= 0) {
        return null;
    }
    return Math.round(((latest - avg) / avg) * 100);
}

/** e.g. +10% or -5% (no decimals). */
export function formatSignedPercentRounded(percent) {
    const n = Number(percent);
    if (Number.isNaN(n)) {
        return '';
    }
    if (n > 0) {
        return `+${n}%`;
    }
    if (n < 0) {
        return `${n}%`;
    }
    return '0%';
}

export function percentChangeColorClass(percent) {
    const n = Number(percent);
    if (Number.isNaN(n) || n === 0) {
        return 'text-body';
    }
    return n > 0 ? 'text-success' : 'text-danger';
}

/** ((LTP − highest close) / highest close) × 100, rounded; null when not computable. */
export function ltpDrawdownFromHighPercent(ltp, highestClose) {
    const ltpN = Number(ltp);
    const highN = Number(highestClose);
    if (Number.isNaN(ltpN) || Number.isNaN(highN) || highN <= 0) {
        return null;
    }
    return Math.round(((ltpN - highN) / highN) * 100);
}

/** Label for holdings highest-close subline, e.g. LTP: -5%. */
export function formatLtpDrawdownLabel(percent) {
    const n = Number(percent);
    if (Number.isNaN(n)) {
        return '';
    }
    if (n > 0) {
        return `LTP: +${n}%`;
    }
    return `LTP: ${n}%`;
}

/**
 * Green at/above high (0%), orange between 0 and −stoploss%, red at/below −stoploss%.
 */
export function ltpDrawdownColorClass(percent, stoplossPercent) {
    const p = Number(percent);
    if (Number.isNaN(p)) {
        return 'text-body';
    }
    if (p >= 0) {
        return 'text-success';
    }
    const stop = Number(stoplossPercent);
    if (Number.isNaN(stop) || stop <= 0) {
        return 'text-allocation-elevated';
    }
    if (p <= -stop) {
        return 'text-danger';
    }
    return 'text-allocation-elevated';
}
