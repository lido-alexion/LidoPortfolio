import { describe, expect, it } from 'vitest';
import { COMBO_CHART_PRESETS, availableComboPresets, cycleComboPreset } from '../../../resources/js/src/components/charts/comboChartPresets.js';

describe('combo chart preset contracts', () => {
    it('keeps the frozen catalogue deterministic and preserves unavailable entries', () => {
        const presets = availableComboPresets({ 'price-pe': { available: false, reason: 'Insufficient historical P/E data' } });
        expect(presets.map((preset) => preset.id)).toEqual(COMBO_CHART_PRESETS.map((preset) => preset.id));
        expect(presets.find((preset) => preset.id === 'price-pe')).toMatchObject({ available: false, unavailableReason: 'Insufficient historical P/E data' });
    });

    it('wraps navigation and skips unavailable presets', () => {
        const presets = availableComboPresets({ 'price-volume': { available: false } });
        expect(cycleComboPreset(presets, 'price-net-profit', 1).id).toBe('price-pe');
        expect(cycleComboPreset(presets, 'price-pe', -1).id).toBe('price-net-profit');
    });
});
