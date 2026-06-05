/**
 * Build navigation state to prefill a sell transaction from a holdings row.
 *
 * @param {object} holding
 * @returns {object|null}
 */
export function buildSellPrefillFromHolding(holding) {
    const stock = holding?.stock;
    if (!stock?.symbol || !stock?.id) {
        return null;
    }

    const rawQty = Number(holding.quantity);
    if (!Number.isFinite(rawQty) || rawQty <= 0) {
        return null;
    }

    const quantity = Number.isInteger(rawQty) ? rawQty : Math.floor(rawQty);
    if (quantity < 1) {
        return null;
    }

    const summary = holding.stoploss_summary || holding.summary || {};
    const latestClose = summary.latest_close != null ? Number(summary.latest_close) : null;
    const price = latestClose != null && latestClose > 0 ? latestClose : null;

    return {
        stock: {
            id: stock.id,
            symbol: stock.symbol,
            name: stock.name || stock.symbol,
            exchange: stock.exchange || 'NSE',
        },
        quantity,
        price,
    };
}
