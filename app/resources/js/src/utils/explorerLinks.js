/**
 * Deep-link helpers for Explorer relative-strength comparisons.
 */

export function buildExplorerComparePath(symbol, benchmarkSymbol) {
    const params = new URLSearchParams();
    const sym = String(symbol || '').trim().toUpperCase();
    const bench = String(benchmarkSymbol || '').trim().toUpperCase();
    if (sym) {
        params.set('symbol', sym);
    }
    if (bench) {
        params.set('benchmark', bench);
    }
    const query = params.toString();

    return query ? `/explorer?${query}` : '/explorer';
}

export function compareStrengthLabel(indexName) {
    const name = String(indexName || '').trim() || 'index';

    return `Compare strength against ${name}`;
}

export function pickPrimaryBenchmark(indexes) {
    const list = Array.isArray(indexes) ? indexes : [];
    return list.find((row) => row.is_primary)
        || list.find((row) => String(row.symbol || '').toUpperCase() === 'NIFTY50')
        || list[0]
        || { symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE', is_primary: true };
}
