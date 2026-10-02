import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';

test('seven stock affordances share the same component with exactly three inline placements', () => {
    const placements = { HoldingsPage: [1, 0], DashboardPage: [2, 0], WatchlistPage: [2, 1], StockExplorerPage: [2, 2] };
    for (const [page, [total, inline]] of Object.entries(placements)) {
        const source = readFileSync(new URL(`../../resources/js/src/pages/${page}.jsx`, import.meta.url), 'utf8');
        assert.equal((source.match(/<AnalyseStockButton\b/g) || []).length, total);
        assert.equal((source.match(/<AnalyseStockButton\s+presentation="inline"/g) || []).length, inline);
    }
});

test('published embedded API schemas match the runtime/cache contracts', () => {
    const api = JSON.parse(readFileSync(new URL('../../openapi/embedded-ai.json', import.meta.url), 'utf8'));
    for (const capability of ['stock_analysis_insight', 'strategy_designer']) {
        const schema = JSON.parse(readFileSync(new URL(`../../../docs/architecture/ai-schemas/${capability}.v1.json`, import.meta.url), 'utf8'));
        delete schema.$id;
        delete schema.$schema;
        assert.deepEqual(api.components.schemas[capability], schema);
    }
    assert.equal(api.paths['/api/ai/insights/strategy/draft'].post.requestBody.content['application/json'].schema.required[0], 'fingerprint');
});
