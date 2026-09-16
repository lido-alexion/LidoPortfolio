import test from 'node:test';
import assert from 'node:assert/strict';

function getAppBaseFrom(metaContent, windowBase, pathname) {
    const raw = metaContent || windowBase || '';
    const base = String(raw).replace(/\/$/, '');
    return base === '/' ? '' : base;
}

function appUrl(path, metaContent, windowBase, pathname) {
    const base = getAppBaseFrom(metaContent, windowBase, pathname);
    const suffix = path.startsWith('/') ? path : `/${path}`;
    return `${base}${suffix}` || '/';
}

test('appUrl uses meta app-base for subdirectory', () => {
    assert.equal(
        appUrl('/sanctum/csrf-cookie', '/portfolio', '', '/portfolio/'),
        '/portfolio/sanctum/csrf-cookie',
    );
});

test('appUrl falls back to window.__LIDO_APP_BASE__', () => {
    assert.equal(
        appUrl('/api/auth/login', '', '/portfolio', '/portfolio/'),
        '/portfolio/api/auth/login',
    );
});

test('appUrl does not infer /portfolio from deep links when meta is empty', () => {
    assert.equal(
        appUrl('/api/auth/me', '', '', '/portfolio/settings'),
        '/api/auth/me',
    );
});

test('appUrl is root-local when no subdirectory', () => {
    assert.equal(
        appUrl('/api/auth/me', '', '', '/settings'),
        '/api/auth/me',
    );
});
