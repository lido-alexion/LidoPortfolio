import test from 'node:test';
import assert from 'node:assert/strict';

function browserSessionStorage() {
    const values = new Map();
    return {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
    };
}

test('sign-in context is selected per tab and defaults to investor', async () => {
    globalThis.window = { location: { search: '' } };
    globalThis.sessionStorage = browserSessionStorage();
    const context = await import('../../resources/js/src/auth/sessionContext.js?context-test=1');

    assert.equal(context.getAuthSessionContext(), 'investor');
    window.location.search = '?auth_context=admin';
    assert.equal(context.getAuthSessionContext(), 'admin');
    window.location.search = '';
    assert.equal(context.getAuthSessionContext(), 'admin');
    context.setAuthSessionUserId(42);
    assert.equal(context.getAuthSessionUserId(), '42');
    assert.equal(context.isCurrentAuthSessionUserId(42), true);
    assert.equal(context.isCurrentAuthSessionUserId(7), false);
    context.setAuthSessionUserId(null);
    assert.equal(context.getAuthSessionUserId(), null);
});
