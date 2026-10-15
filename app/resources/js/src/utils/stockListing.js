/** Resolve a symbol-only route to the canonical NSE listing when dual listed. */
export function resolvePreferredStockListing(rows, symbol) {
    const needle = String(symbol || '').trim().toUpperCase();
    if (!needle) return null;
    const exact = (rows || []).filter((row) => String(row?.symbol || '').trim().toUpperCase() === needle);
    return exact.find((row) => String(row.exchange || '').toUpperCase() === 'NSE') || exact[0] || null;
}
