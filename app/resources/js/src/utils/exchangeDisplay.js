/**
 * Display label for stock exchange in the UI.
 * Dual-listed NSE rows use "NSE+" (canonical row; BSE duplicate not stored).
 */
export function stockExchangeLabel(stock) {
    if (!stock) {
        return '';
    }
    if (stock.exchange_label) {
        return stock.exchange_label;
    }
    if (stock.exchange === 'NSE' && stock.is_dual_listed) {
        return 'NSE+';
    }
    return stock.exchange || 'NSE';
}
