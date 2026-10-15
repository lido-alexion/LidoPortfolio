import test from 'node:test';
import assert from 'node:assert/strict';
import { hasPresetChartGeometry, safeAuthenticatedSmokeStage, waitForPresetChartGeometry } from '../e2e/productionSmokeDiagnostics.js';

test('authenticated smoke reports only fixed, stage-level diagnostics', () => {
    for (const stage of [
        'configuration', 'browser_launch', 'login', 'review', 'account_onboarding_readiness', 'combo_navigation',
        'combo_preference_response', 'combo_preference_scope', 'combo_chart_attached', 'combo_chart_visible',
        'combo_default_selection', 'combo_default_options', 'combo_default_resolution', 'combo_default_precedence', 'combo_chart_data_rendered', 'combo_keyboard_navigation',
        'combo_legend_control', 'combo_legend_read', 'combo_legend_toggle_off',
        'account_onboarding_prompt', 'combo_legend_toggle_restore', 'combo_legend_verify_restore',
        'combo_selection_restore', 'request_safety',
    ]) {
        assert.equal(safeAuthenticatedSmokeStage(stage), stage);
    }
    assert.equal(safeAuthenticatedSmokeStage('secret@example.test'), 'unknown');
    assert.equal(safeAuthenticatedSmokeStage('provider-response-with-private-data'), 'unknown');
});

test('combo geometry acceptance requires non-empty preset-appropriate plotted paths', () => {
    assert.equal(hasPresetChartGeometry({ linePathData: ['M0 0L1 1', 'M0 1L1 0'] }, 'price-pe'), true);
    assert.equal(hasPresetChartGeometry({ linePathData: ['M0 0L1 1'], barPathData: ['M0 0h2v3z'] }, 'price-volume'), true);
    assert.equal(hasPresetChartGeometry({ linePathData: [' '], barPathData: [''] }, 'price-volume'), false);
    assert.equal(hasPresetChartGeometry({ linePathData: ['M0 0L1 1'] }, 'price-pe'), false);
});

test('geometry polling stops at its raw deadline when a chart remains empty', async () => {
    const budgetMs = 25;
    let elapsedMs = 0;
    let reads = 0;
    const accepted = await waitForPresetChartGeometry({
        presetId: 'price-volume',
        deadline: budgetMs,
        now: () => elapsedMs,
        wait: async milliseconds => {
            elapsedMs += milliseconds;
            if (elapsedMs > budgetMs) throw new Error('geometry polling exceeded its deterministic deadline');
        },
        readGeometry: async () => {
            reads += 1;
            return { linePathData: [''], barPathData: [' '] };
        },
    });
    assert.equal(accepted, false);
    assert.equal(elapsedMs, budgetMs);
    assert.ok(reads > 0);
    assert.ok(reads < 10);
});

test('geometry reads that resolve after the deadline are rejected by the watchdog', async () => {
    const budgetMs = 25;
    let elapsedMs = 0;
    let resolveLateGeometry;
    const lateGeometry = new Promise(resolve => { resolveLateGeometry = resolve; });
    const accepted = await waitForPresetChartGeometry({
        presetId: 'price-pe',
        deadline: budgetMs,
        now: () => elapsedMs,
        scheduleTimeout: (callback, milliseconds) => { elapsedMs += milliseconds; callback(); return 1; },
        cancelTimeout: () => {},
        readGeometry: () => lateGeometry,
    });
    assert.equal(accepted, false);
    assert.equal(elapsedMs, budgetMs);
    resolveLateGeometry({ linePathData: ['M0 0L1 1', 'M1 0L0 1'] });
});
