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

/** Zerodha equity delivery defaults (current). NSE cash rate is date-resolved below. */
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
    {
        id: 'dp_zerodha', label: 'Depository participant (DP)', value: '13',
        mode: FEE_MODES.FIXED, applies_buy: false, applies_sell: true,
        exchange: FEE_EXCHANGES.BOTH, gst_percent: '18', aggregation: 'isin_day_sell',
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
        aggregation: item.aggregation === 'isin_day_sell' ? 'isin_day_sell' : undefined,
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
    transactionDate = null,
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

        const rate = rateForDate(component, transactionDate);
        const base = component.mode === FEE_MODES.FIXED
            ? rate
            : turnover * (rate / 100);
        const roundedBase = ['stt', 'stamp'].includes(component.id) ? Math.floor(base + 0.5) : base;
        const gstRate = Number(component.gst_percent) || 0;
        const gst = roundedBase * (gstRate / 100);
        const lineTotal = roundedBase + gst;

        breakdown.push({
            id: component.id,
            label: component.label,
            base: roundMoney(roundedBase),
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

function rateForDate(component, transactionDate) {
    if (component.id === 'txn_nse' && transactionDate && component.value === '0.00307') {
        return transactionDate >= '2024-10-01' ? 0.00307 : 0.00322;
    }
    return Number(component.value) || 0;
}

function allocateCents(amount, weights) {
    const cents = Math.round(amount * 100);
    const total = weights.reduce((sum, weight) => sum + weight, 0);
    if (!total || !weights.length) return weights.map(() => 0);
    const exact = weights.map((weight) => cents * weight / total);
    const result = exact.map(Math.floor);
    const order = exact.map((value, index) => ({ index, remainder: value - Math.floor(value) }))
        .sort((a, b) => b.remainder - a.remainder || a.index - b.index);
    for (let i = 0, left = cents - result.reduce((sum, value) => sum + value, 0); left > 0; i += 1, left -= 1) {
        result[order[i % order.length].index] += 1;
    }
    return result.map((value) => value / 100);
}

/** Calculate a complete same-day batch and allocate invoice totals deterministically. */
export function calculateGroupedTradeDateFees(rows, feeComponents = DEFAULT_FEE_COMPONENTS) {
    const normalized = normalizeFeeComponents(feeComponents);
    const results = rows.map(() => ({ total: 0, breakdown: [] }));
    const groups = new Map();
    rows.forEach((row, index) => {
        const type = String(row.type || '').toLowerCase();
        const quantity = Number(row.quantity);
        const price = Number(row.price);
        if (!['buy', 'sell'].includes(type) || !(quantity > 0) || !(price > 0)) return;
        const exchange = row.execution_exchange || 'NSE';
        const date = row.transaction_date || 'undated';
        const turnover = quantity * price;
        normalized.forEach((component) => {
            if (!componentApplies(component, type) || !componentMatchesExchange(component, exchange)) return;
            if (component.aggregation === 'isin_day_sell') return;
            if (component.mode === FEE_MODES.FIXED) {
                const one = calculateTransactionFees({ quantity, price, type, exchange, transactionDate: date, feeComponents: [component] });
                results[index].breakdown.push(...one.breakdown);
                results[index].total += one.total;
                return;
            }
            const aggregationSide = component.id === 'stt' || component.id === 'sebi' ? 'delivery' : type;
            const aggregationExchange = ['stt', 'stamp', 'sebi'].includes(component.id) ? 'all' : exchange;
            const key = [date, aggregationSide, aggregationExchange, component.id].join('|');
            if (!groups.has(key)) groups.set(key, { component, date, indices: [], turnovers: [] });
            groups.get(key).indices.push(index);
            groups.get(key).turnovers.push(turnover);
        });
    });
    groups.forEach(({ component, date, indices, turnovers }) => {
        const baseRaw = turnovers.reduce((sum, value) => sum + value, 0) * rateForDate(component, date) / 100;
        const baseInvoice = ['stt', 'stamp'].includes(component.id)
            ? Math.floor(baseRaw + 0.5) : roundMoney(baseRaw);
        const gstInvoice = roundMoney(baseInvoice * (Number(component.gst_percent || 0) / 100));
        const bases = allocateCents(baseInvoice, turnovers);
        const gstParts = allocateCents(gstInvoice, turnovers);
        indices.forEach((index, position) => {
            const line = { id: component.id, label: component.label, base: bases[position], gst: gstParts[position], total: roundMoney(bases[position] + gstParts[position]) };
            results[index].breakdown.push(line);
            results[index].total += line.total;
        });
    });
    return results.map((result) => ({ ...result, total: roundMoney(result.total) }));
}

/** Allocate ISIN/day DP charges once across a complete, ordered transaction set.
 * Rows without an ISIN are intentionally left without an estimated DP charge. */
export function calculateGroupedTransactionFees(rows, feeComponents = DEFAULT_FEE_COMPONENTS) {
    const normalized = normalizeFeeComponents(feeComponents);
    const dp = normalized.filter((component) => component.aggregation === 'isin_day_sell');
    const ordinary = normalized.filter((component) => component.aggregation !== 'isin_day_sell');
    const results = calculateGroupedTradeDateFees(rows, ordinary);
    const charged = new Set();
    rows.forEach((row, index) => {
        const isin = String(row.isin || '').trim().toUpperCase();
        if (String(row.type).toLowerCase() !== 'sell' || !isin) return;
        const key = `${row.profile_id || row.account_id || 'default'}:${row.transaction_date}:${isin}`;
        if (charged.has(key)) return;
        charged.add(key);
        for (const component of dp) {
            if (component.exchange !== 'both' && component.exchange !== (row.execution_exchange || 'NSE')) continue;
            const base = component.mode === FEE_MODES.FIXED
                ? Number(component.value) : Number(row.quantity) * Number(row.price) * Number(component.value) / 100;
            const gst = base * Number(component.gst_percent || 0) / 100;
            const line = { id: component.id, label: component.label, base: roundMoney(base), gst: roundMoney(gst), total: roundMoney(base + gst) };
            results[index].breakdown.push(line);
            results[index].total = roundMoney(results[index].total + line.total);
        }
    });
    return results;
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
