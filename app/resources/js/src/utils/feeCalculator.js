/**
 * Transaction fee calculator — canonical logic (FE is source of truth on save).
 * Each component: proportional (%) or fixed (₹ per transaction), buy/sell flags,
 * optional exchange filter, per-component GST %.
 */

export const FEE_MODES = {
    PERCENTAGE: 'percentage',
    FIXED: 'fixed',
};

export const FEE_EXCHANGES = {
    BOTH: 'both',
    NSE: 'NSE',
    BSE: 'BSE',
};

/** Zerodha equity delivery defaults (Jun 2026). SEBI: ₹10/crore = 0.0001%. */
export const DEFAULT_FEE_COMPONENTS = [
    {
        id: 'brokerage',
        label: 'Brokerage',
        value: '0',
        mode: FEE_MODES.PERCENTAGE,
        applies_buy: true,
        applies_sell: true,
        exchange: FEE_EXCHANGES.BOTH,
        gst_percent: '18',
    },
    {
        id: 'stt',
        label: 'STT/CTT',
        value: '0.1',
        mode: FEE_MODES.PERCENTAGE,
        applies_buy: true,
        applies_sell: true,
        exchange: FEE_EXCHANGES.BOTH,
        gst_percent: '0',
    },
    {
        id: 'txn_nse',
        label: 'Transaction charges (NSE)',
        value: '0.00307',
        mode: FEE_MODES.PERCENTAGE,
        applies_buy: true,
        applies_sell: true,
        exchange: FEE_EXCHANGES.NSE,
        gst_percent: '18',
    },
    {
        id: 'txn_bse',
        label: 'Transaction charges (BSE)',
        value: '0.00375',
        mode: FEE_MODES.PERCENTAGE,
        applies_buy: true,
        applies_sell: true,
        exchange: FEE_EXCHANGES.BSE,
        gst_percent: '18',
    },
    {
        id: 'sebi',
        label: 'SEBI charges',
        value: '0.0001',
        mode: FEE_MODES.PERCENTAGE,
        applies_buy: true,
        applies_sell: true,
        exchange: FEE_EXCHANGES.BOTH,
        gst_percent: '18',
    },
    {
        id: 'stamp',
        label: 'Stamp charges',
        value: '0.015',
        mode: FEE_MODES.PERCENTAGE,
        applies_buy: true,
        applies_sell: false,
        exchange: FEE_EXCHANGES.BOTH,
        gst_percent: '0',
    },
];

function roundMoney(value) {
    return Math.round(Number(value) * 100) / 100;
}

function componentApplies(component, type) {
    if (type === 'buy') {
        return Boolean(component.applies_buy);
    }
    if (type === 'sell') {
        return Boolean(component.applies_sell);
    }

    return false;
}

function componentMatchesExchange(component, exchange) {
    const filter = component.exchange || FEE_EXCHANGES.BOTH;
    if (filter === FEE_EXCHANGES.BOTH) {
        return true;
    }

    return filter === exchange;
}

export function normalizeFeeComponents(raw) {
    if (!Array.isArray(raw) || raw.length === 0) {
        return DEFAULT_FEE_COMPONENTS.map((c) => ({ ...c }));
    }

    return raw.map((item, index) => ({
        id: String(item.id || `fee_${index + 1}`),
        label: String(item.label || `Fee ${index + 1}`),
        value: String(item.value ?? '0'),
        mode: item.mode === FEE_MODES.FIXED ? FEE_MODES.FIXED : FEE_MODES.PERCENTAGE,
        applies_buy: item.applies_buy !== false,
        applies_sell: item.applies_sell !== false,
        exchange: [FEE_EXCHANGES.NSE, FEE_EXCHANGES.BSE, FEE_EXCHANGES.BOTH].includes(item.exchange)
            ? item.exchange
            : FEE_EXCHANGES.BOTH,
        gst_percent: String(item.gst_percent ?? '0'),
    }));
}

/**
 * @returns {{ total: number, breakdown: Array<{ id, label, base, gst, total }> }}
 */
export function calculateTransactionFees({
    quantity,
    price,
    type,
    exchange = 'NSE',
    feeComponents = DEFAULT_FEE_COMPONENTS,
}) {
    const qty = Number(quantity);
    const px = Number(price);
    if (!Number.isFinite(qty) || !Number.isFinite(px) || qty <= 0 || px <= 0) {
        return { total: 0, breakdown: [] };
    }

    const turnover = qty * px;
    const normalized = normalizeFeeComponents(feeComponents);
    const breakdown = [];
    let total = 0;

    for (const component of normalized) {
        if (!componentApplies(component, type)) {
            continue;
        }
        if (!componentMatchesExchange(component, exchange)) {
            continue;
        }

        const rate = Number(component.value) || 0;
        const base = component.mode === FEE_MODES.FIXED
            ? rate
            : turnover * (rate / 100);
        const gstRate = Number(component.gst_percent) || 0;
        const gst = base * (gstRate / 100);
        const lineTotal = base + gst;

        breakdown.push({
            id: component.id,
            label: component.label,
            base: roundMoney(base),
            gst: roundMoney(gst),
            total: roundMoney(lineTotal),
        });
        total += lineTotal;
    }

    return {
        total: roundMoney(total),
        breakdown,
    };
}

function formatMoneyAmount(value) {
    return `₹${Number(value).toFixed(2)}`;
}

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function buildFeeBreakdownRows(breakdown, total) {
    if (!breakdown.length) {
        return { empty: true, rows: [] };
    }

    const rows = breakdown.map((line) => ({
        label: line.label,
        amount: formatMoneyAmount(line.base),
    }));

    const gstParts = breakdown
        .map((line) => line.gst)
        .filter((gst) => gst > 0);

    if (gstParts.length === 1) {
        rows.push({ label: 'GST', amount: formatMoneyAmount(gstParts[0]) });
    } else if (gstParts.length > 1) {
        const gstSum = roundMoney(gstParts.reduce((sum, gst) => sum + gst, 0));
        const gstExpression = gstParts.map((gst) => formatMoneyAmount(gst)).join('+');
        rows.push({
            label: 'GST',
            amount: `${gstExpression} = ${formatMoneyAmount(gstSum)}`,
        });
    }

    rows.push({
        label: 'Total fees',
        amount: formatMoneyAmount(total),
        emphasis: true,
    });

    return { empty: false, rows };
}

export function formatFeeBreakdownText(breakdown, total) {
    const built = buildFeeBreakdownRows(breakdown, total);
    if (built.empty) {
        return 'No fees apply for this transaction.';
    }

    return built.rows.map((row) => `${row.label}: ${row.amount}`).join('\n');
}

export function formatFeeBreakdownHtml(breakdown, total) {
    const built = buildFeeBreakdownRows(breakdown, total);
    if (built.empty) {
        return '<div class="lido-fee-breakdown-tooltip-content">No fees apply for this transaction.</div>';
    }

    const rowHtml = built.rows.map((row) => (
        `<div class="lido-fee-breakdown-row${row.emphasis ? ' lido-fee-breakdown-row--total' : ''}">`
        + `<span class="lido-fee-breakdown-label">${escapeHtml(row.label)}</span>`
        + `<span class="lido-fee-breakdown-amount">${escapeHtml(row.amount)}</span>`
        + '</div>'
    )).join('');

    return `<div class="lido-fee-breakdown-tooltip-content">${rowHtml}</div>`;
}
