import { afterEach, beforeEach, expect, it, vi } from 'vitest';

let identity;

beforeEach(async () => {
    vi.resetModules();
    identity = await import('../../../resources/js/src/utils/transactionRequestIdentity.js');
});

afterEach(() => {
    try {
        globalThis.sessionStorage?.clear();
    } catch {
        // Restricted storage is covered explicitly below.
    }
    vi.restoreAllMocks();
});

it('retains the same create key across retries and reload-like reads, scoped to actor and portfolio', () => {
    const payload = { stock_id: 42, type: 'buy', quantity: 2, price: 100 };
    const first = identity.getOrCreateTransactionRequest(7, 11, payload);
    const retry = identity.getOrCreateTransactionRequest(7, 11, { price: 100, quantity: 2, type: 'buy', stock_id: 42 });

    expect(first.key).toBeTruthy();
    expect(retry.key).toBe(first.key);
    expect(identity.getPendingTransactionRequest(7, 11).key).toBe(first.key);
    expect(identity.getOrCreateTransactionRequest(7, 12, payload).key).not.toBe(first.key);
    expect(identity.getOrCreateTransactionRequest(8, 11, payload).key).not.toBe(first.key);
});

it('blocks changed payloads until the uncertain request is resolved or explicitly cleared', () => {
    const first = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 2 });
    expect(identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 3 })).toMatchObject({ blocked: true });
    identity.clearPendingTransactionRequest(7, 11);
    const replacement = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 3 });
    expect(replacement.key).not.toBe(first.key);
});

it('does not invent retry identity without authenticated actor and Portfolio scope', () => {
    expect(identity.getOrCreateTransactionRequest(null, 11, { stock_id: 42 })).toEqual({ unavailable: true });
    expect(identity.getOrCreateTransactionRequest(7, null, { stock_id: 42 })).toEqual({ unavailable: true });
});

it('keeps memory-only retry identity marked non-durable and blocks changed payloads', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new DOMException('blocked', 'SecurityError'); });

    const first = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 2 });
    const retry = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 2 });

    expect(first).toMatchObject({ persisted: false });
    expect(retry).toMatchObject({ key: first.key, persisted: false });
    expect(identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 3 })).toMatchObject({ blocked: true });
});

it('handles a throwing sessionStorage property getter without sending unscoped durability claims', () => {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage');
    Object.defineProperty(globalThis, 'sessionStorage', {
        configurable: true,
        get() { throw new DOMException('blocked', 'SecurityError'); },
    });

    try {
        const first = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 2 });
        const retry = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 2 });
        expect(first).toMatchObject({ persisted: false });
        expect(retry).toMatchObject({ key: first.key, persisted: false });
        expect(identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 3 })).toMatchObject({ blocked: true });
    } finally {
        if (descriptor) Object.defineProperty(globalThis, 'sessionStorage', descriptor);
        else delete globalThis.sessionStorage;
    }
});

it('marks retry identity non-durable when sessionStorage is absent', () => {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage');
    Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: undefined });

    try {
        const first = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 2 });
        const retry = identity.getOrCreateTransactionRequest(7, 11, { stock_id: 42, quantity: 2 });
        expect(first).toMatchObject({ persisted: false });
        expect(retry).toMatchObject({ key: first.key, persisted: false });
    } finally {
        if (descriptor) Object.defineProperty(globalThis, 'sessionStorage', descriptor);
        else delete globalThis.sessionStorage;
    }
});
