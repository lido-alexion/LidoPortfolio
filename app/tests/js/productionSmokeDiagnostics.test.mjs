import test from 'node:test';
import assert from 'node:assert/strict';
import { hasPresetChartGeometry, safeAuthenticatedSmokeStage } from '../e2e/productionSmokeDiagnostics.js';

test('authenticated smoke reports only fixed, stage-level diagnostics', () => {
    for (const stage of [
        'configuration', 'browser_launch', 'login', 'review', 'combo_navigation',
        'combo_preference_response', 'combo_preference_scope', 'combo_chart_attached', 'combo_chart_visible',
        'combo_default_selection', 'combo_default_options', 'combo_default_resolution', 'combo_default_precedence', 'combo_chart_data_rendered', 'combo_keyboard_navigation',
        'combo_legend_toggle', 'combo_selection_restore', 'request_safety',
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
