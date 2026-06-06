import test from 'node:test';
import assert from 'node:assert/strict';
import { consumeRedirectPath, saveRedirectPath } from '../../resources/js/src/auth/redirect.js';

test('saveRedirectPath stores path in sessionStorage', () => {
    global.sessionStorage = {
        store: {},
        setItem(k, v) { this.store[k] = v; },
        getItem(k) { return this.store[k] ?? null; },
        removeItem(k) { delete this.store[k]; },
    };

    saveRedirectPath('/holdings');
    assert.equal(sessionStorage.getItem('portfolio_auth_redirect'), '/holdings');
});

test('consumeRedirectPath returns and clears stored path', () => {
    global.sessionStorage = {
        store: { portfolio_auth_redirect: '/explorer' },
        setItem(k, v) { this.store[k] = v; },
        getItem(k) { return this.store[k] ?? null; },
        removeItem(k) { delete this.store[k]; },
    };

    assert.equal(consumeRedirectPath(), '/explorer');
    assert.equal(sessionStorage.getItem('portfolio_auth_redirect'), null);
});

test('consumeRedirectPath defaults to home', () => {
    global.sessionStorage = {
        store: {},
        setItem(k, v) { this.store[k] = v; },
        getItem(k) { return this.store[k] ?? null; },
        removeItem(k) { delete this.store[k]; },
    };

    assert.equal(consumeRedirectPath(), '/');
});
