import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    APP_DOCUMENTATION,
    APP_DOCUMENTATION_BY_SPECIFICITY,
} from '../../resources/js/src/data/appDocumentation.js';
import {
    DOCUMENTATION_ROUTE_FALLBACKS,
    resolveDocKeywordFromPath,
} from '../../resources/js/src/utils/documentationLinks.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const appRoot = path.resolve(here, '../..');
const appSource = fs.readFileSync(path.join(appRoot, 'resources/js/src/App.jsx'), 'utf8');

function routePaths() {
    return [...appSource.matchAll(/<Route\s+path="([^"]+)"/g)]
        .map((match) => match[1])
        .filter((route) => route !== '*');
}

function concretePath(route) {
    return route
        .replace(/\/:[^/]+\?/g, '/sample')
        .replace(/\/:[^/]+/g, '/sample')
        .replace(/\?$/, '') || '/';
}

function fallbackCovers(pathname) {
    return DOCUMENTATION_ROUTE_FALLBACKS.some(({ prefix }) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}

test('documentation catalog has unique ids and keywords', () => {
    const ids = APP_DOCUMENTATION.map((doc) => doc.id);
    const keywords = APP_DOCUMENTATION.map((doc) => doc.keyword);
    assert.equal(new Set(ids).size, ids.length);
    assert.equal(new Set(keywords).size, keywords.length);
    assert.ok(APP_DOCUMENTATION.some((doc) => doc.keyword === 'overview'));
});

test('live route families resolve to explicit documentation topics', () => {
    const docs = new Set(APP_DOCUMENTATION.map((doc) => doc.keyword));
    const resolved = new Map();
    for (const route of routePaths()) {
        const pathname = concretePath(route);
        const keyword = resolveDocKeywordFromPath(pathname);
        assert.ok(docs.has(keyword), `${route} resolved to missing topic ${keyword}`);
        if (keyword === 'overview') {
            assert.ok(
                pathname === '/documentation' || fallbackCovers(pathname),
                `${route} silently fell back to overview`,
            );
        }
        resolved.set(route, keyword);
    }

    assert.equal(resolved.get('/'), 'dashboard');
    assert.equal(resolved.get('/knowledge-board/wiki/:pageId?'), 'knowledge');
    assert.equal(resolved.get('/artifact-library'), 'artifact-library');
    assert.equal(resolved.get('/artifact-library/:uuid'), 'artifact-library');
    assert.equal(resolved.get('/portfolio/compare'), 'portfolio-compare');
    assert.equal(resolved.get('/portfolio/performance-tax'), 'performance-tax');
    assert.equal(resolved.get('/evaluations'), 'discovery');
    assert.equal(resolved.get('/wiki/shared/:token'), 'knowledge');
    assert.equal(resolved.get('/invite/:token'), 'overview');
});

test('specific route topics win over broad parent matchers', () => {
    assert.ok(APP_DOCUMENTATION_BY_SPECIFICITY.find((doc) => doc.keyword === 'knowledge-tags'));
    assert.equal(resolveDocKeywordFromPath('/knowledge-board/tags'), 'knowledge-tags');
    assert.equal(resolveDocKeywordFromPath('/settings/strategy-registry/abc'), 'strategy-registry');
    assert.equal(resolveDocKeywordFromPath('/unknown/internal/path'), 'overview');
});

test('generated static docs cover every catalog topic and expose a current index', () => {
    const docsDir = path.join(appRoot, 'public/docs');
    const index = fs.readFileSync(path.join(docsDir, 'index.html'), 'utf8');
    for (const doc of APP_DOCUMENTATION) {
        assert.ok(fs.existsSync(path.join(docsDir, `${doc.keyword}.html`)), `${doc.keyword}.html is missing`);
        assert.match(index, new RegExp(`href="${doc.keyword}\\.html"`));
    }
    assert.ok(fs.existsSync(path.join(appRoot, 'scripts/check-static-docs.mjs')));
});

test('static documentation check script is runnable', async () => {
    const script = path.join(appRoot, 'scripts/check-static-docs.mjs');
    const { spawn } = await import('node:child_process');
    await new Promise((resolve, reject) => {
        const child = spawn(process.execPath, [script], { cwd: appRoot, stdio: 'ignore' });
        child.on('error', reject);
        child.on('exit', (code) => code === 0 ? resolve() : reject(new Error(`docs check exited ${code}`)));
    });
});
