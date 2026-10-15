import test from 'node:test';
import assert from 'node:assert/strict';
import { safeAuthenticatedSmokeStage } from '../e2e/productionSmokeDiagnostics.js';

test('authenticated smoke reports only fixed, stage-level diagnostics', () => {
    for (const stage of [
        'configuration', 'browser_launch', 'login', 'review', 'combo_navigation',
        'combo_preference_response', 'combo_preference_scope', 'combo_chart_visible',
        'combo_default_selection', 'combo_default_options', 'combo_default_precedence', 'combo_keyboard_navigation',
        'combo_legend_toggle', 'combo_selection_restore', 'request_safety',
    ]) {
        assert.equal(safeAuthenticatedSmokeStage(stage), stage);
    }
    assert.equal(safeAuthenticatedSmokeStage('secret@example.test'), 'unknown');
    assert.equal(safeAuthenticatedSmokeStage('provider-response-with-private-data'), 'unknown');
});
