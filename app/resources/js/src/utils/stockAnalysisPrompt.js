/**
 * Build the AI stock-analysis prompt with ticker/name and latest OHLCV rows.
 *
 * @param {{
 *   symbol?: string|null,
 *   name?: string|null,
 *   ohlcvRows?: Array<Record<string, mixed>>,
 * }} opts
 * @returns {string}
 */
export function buildStockAnalysisPrompt({ symbol, name, ohlcvRows = [] } = {}) {
    const ticker = String(symbol || '').trim().toUpperCase() || 'UNKNOWN';
    const company = String(name || '').trim();
    const label = company && company.toUpperCase() !== ticker
        ? `${company} (${ticker})`
        : ticker;

    const ohlcvBlock = formatSevenDayOhlcv(ohlcvRows);

    return `Please provide a comprehensive stock analysis for ${label}. I need the analysis structured into eight distinct sections based on the latest available market data, corporate filings, quarterly reports, and recent transaction data:

1. Current Price & Fundamentals: Include LTP, daily changes, 52-week ranges, key valuation metrics (Market Cap, TTM P/E vs. Industry P/E, EPS, Debt-to-Equity), and recent quarterly growth metrics or catalysts.

2. Shareholding Shift & Smart Money Tracking: Break down the latest quarterly shareholding pattern. Highlight recent trends in Foreign Institutional Investor (FII) and Domestic Institutional Investor (DII/Mutual Fund) stakes. Note the retail ownership percentage and what it implies for price volatility.

3. Revenue Visibility & Order Book: Analyze the company's forward order book, major contract wins, pipeline strength, and structural sector tailwinds (e.g., domestic infrastructure spend, export market expansion, macro tech trends).

4. Operational Efficiency & Governance Risks: Detail the company's working capital health, focusing specifically on debtor days (receivables collection time) and cash flow metrics. Check for any red flags regarding corporate liquidity or promoter share pledging.

5. Mid-Term Technical Outlook (6 Months - 1 Year): Analyze the broader structural trend, multi-month price performance, and key institutional accumulation/distribution zones on the weekly/monthly charts.

6. Short-Term Technical Outlook: Detail immediate short-term chart data, specifically the positioning of the 20-day and 50-day moving averages, momentum oscillators (RSI/MACD status), and precise pivot points (Support 1/2/3 and Resistance 1/2/3).

7. 7-Day OHLC & Volume Pattern Analysis: ${ohlcvBlock} Analyze this specific multi-day data block for prominent multi-candle formations (reversals, continuations, rejections) and evaluate price-volume delivery to determine if the short-term momentum is strongly leaning bullish or bearish.

8. Balanced Investment Thesis Summary: Synthesize the analysis into a balanced summary. Explicitly contrast the core 'Bull Case' (catalysts, value proposition, technical momentum) against the core 'Bear Case' (valuation traps, technical overhead resistance, operational bottlenecks) so I can make an informed risk-reward decision.`;
}

/**
 * Take newest-first OHLCV rows and format the latest 7 sessions chronologically.
 *
 * @param {Array<Record<string, mixed>>} rows
 * @returns {string}
 */
export function formatSevenDayOhlcv(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const latestSeven = list.slice(0, 7);
    if (latestSeven.length === 0) {
        return '[No local 7-day OHLCV data available for this stock.]';
    }

    const chronological = [...latestSeven].reverse();
    const lines = chronological.map((row) => {
        const date = formatPriceDate(row.price_date);
        const open = formatPriceNum(row.open_price);
        const high = formatPriceNum(row.high_price);
        const low = formatPriceNum(row.low_price);
        const close = formatPriceNum(row.close_price);
        const volume = formatVolume(row.volume);
        return `${date}: O=${open} H=${high} L=${low} C=${close} V=${volume}`;
    });

    const note = latestSeven.length < 7
        ? ` (only ${latestSeven.length} session${latestSeven.length === 1 ? '' : 's'} available)`
        : '';

    return `[${lines.join('; ')}]${note}.`;
}

function formatPriceDate(value) {
    if (!value) {
        return '—';
    }
    if (typeof value === 'string') {
        return value.slice(0, 10);
    }
    try {
        return new Date(value).toISOString().slice(0, 10);
    } catch {
        return String(value);
    }
}

function formatPriceNum(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    if (Number.isNaN(num)) {
        return String(value);
    }
    return num.toFixed(2);
}

function formatVolume(value) {
    if (value == null || value === '') {
        return '—';
    }
    const num = Number(value);
    if (Number.isNaN(num)) {
        return String(value);
    }
    return Math.round(num).toLocaleString('en-IN');
}
