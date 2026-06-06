const cache = new Map();

export function stockValidationCacheKey(exchange, symbol) {
    return `${exchange}:${String(symbol || '').trim().toUpperCase()}`;
}

export function getCachedStockValidation(exchange, symbol) {
    return cache.get(stockValidationCacheKey(exchange, symbol)) ?? null;
}

export function setCachedStockValidation(exchange, symbol, entry) {
    cache.set(stockValidationCacheKey(exchange, symbol), entry);
}

export function clearCachedStockValidation(exchange, symbol) {
    cache.delete(stockValidationCacheKey(exchange, symbol));
}
