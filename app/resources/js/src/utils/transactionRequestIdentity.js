const STORAGE_PREFIX = 'stox:transaction-create-pending:v1';
const memoryPending = new Map();

function storageKey(userId, portfolioId) {
    if (userId == null || portfolioId == null) return null;
    return `${STORAGE_PREFIX}:${encodeURIComponent(String(userId))}:${encodeURIComponent(String(portfolioId))}`;
}

function stableValue(value) {
    if (Array.isArray(value)) return value.map(stableValue);
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.keys(value).sort().map((key) => [key, stableValue(value[key])]));
    }
    return value;
}

function availableSessionStorage() {
    try {
        return globalThis.sessionStorage ?? null;
    } catch {
        // Accessing the browser storage property can itself throw in restricted contexts.
        return null;
    }
}

export function transactionRequestFingerprint(payload) {
    const canonical = JSON.stringify(stableValue(payload));
    let hash = 2166136261;
    for (let index = 0; index < canonical.length; index += 1) {
        hash ^= canonical.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }
    return `${(hash >>> 0).toString(16).padStart(8, '0')}:${canonical.length}`;
}

function readPending(key, storage = availableSessionStorage()) {
    if (!key || !storage) return null;
    try {
        const parsed = JSON.parse(storage.getItem(key) || 'null');
        return parsed && typeof parsed.key === 'string' && typeof parsed.fingerprint === 'string'
            ? { ...parsed, persisted: true }
            : null;
    } catch {
        return null;
    }
}

export function getPendingTransactionRequest(userId, portfolioId) {
    const key = storageKey(userId, portfolioId);
    const storage = availableSessionStorage();
    return readPending(key, storage) || (key ? memoryPending.get(key) || null : null);
}

export function getOrCreateTransactionRequest(userId, portfolioId, payload) {
    const key = storageKey(userId, portfolioId);
    if (!key) return { unavailable: true };
    const fingerprint = transactionRequestFingerprint(payload);
    const storage = availableSessionStorage();
    const existing = readPending(key, storage) || memoryPending.get(key);
    if (existing && existing.fingerprint !== fingerprint) return { blocked: true, fingerprint: existing.fingerprint };
    if (existing) return { ...existing, persisted: existing.persisted === true };

    const request = {
        key: globalThis.crypto?.randomUUID?.().replaceAll('-', '')
            || `${Date.now()}${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`,
        fingerprint,
        persisted: false,
    };
    memoryPending.set(key, request);
    if (!storage) return { ...request };
    try {
        storage.setItem(key, JSON.stringify({ key: request.key, fingerprint }));
        request.persisted = true;
        memoryPending.set(key, request);
        return { key: request.key, fingerprint, persisted: true };
    } catch {
        memoryPending.set(key, request);
        return { ...request };
    }
}

export function clearPendingTransactionRequest(userId, portfolioId) {
    const key = storageKey(userId, portfolioId);
    if (!key) return;
    memoryPending.delete(key);
    const storage = availableSessionStorage();
    if (!storage) return;
    try {
        storage.removeItem(key);
    } catch {
        // The caller will surface that this browser could not retain retry identity.
    }
}
