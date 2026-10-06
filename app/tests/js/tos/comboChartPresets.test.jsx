import { describe, expect, it, vi } from 'vitest';
import React from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import ComboChart from '../../../resources/js/src/components/charts/ComboChart.jsx';
import { alignComboChartData, rangeAndSamplingRows } from '../../../resources/js/src/components/charts/comboChartData.js';
import { COMBO_CHART_PRESETS, availableComboPresets, cycleComboPreset, resolveComboDefault, comboPresetAvailability } from '../../../resources/js/src/components/charts/comboChartPresets.js';

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

    it('resolves explicit defaults before the fallback and then uses the first available preset', () => {
        expect(resolveComboDefault('price-pe')).toBe('price-pe');
        expect(resolveComboDefault('price-pe', { 'price-pe': { available: false } })).toBe('price-volume');
        expect(resolveComboDefault(null, { 'price-volume': { available: false }, 'price-pe': { available: false }, 'price-pb': { available: true }, 'price-ps': { available: false }, 'price-eps': { available: false }, 'price-revenue': { available: false }, 'price-net-profit': { available: false } })).toBe('price-pb');
    });

    it('applies centralized coverage thresholds with an accessible reason', () => {
        const availability = comboPresetAvailability({ prices: [{ price_date: '2025-01-01', close_price: 10, volume: 100 }, { price_date: '2025-02-01', close_price: 11, volume: 120 }], series: { pe: { points: [{ value: 1 }, { value: 2 }] } } });
        expect(availability['price-volume']).toMatchObject({ available: true });
        expect(availability['price-pe']).toMatchObject({ available: false, reason: expect.stringContaining('need 4 periods') });
    });

    it('aligns exact observation dates without carrying fundamentals forward and samples range in-chart', () => {
        const rows = alignComboChartData({
            prices: [{ price_date: '2025-01-02', close_price: 10, volume: 100 }, { price_date: '2025-02-03', close_price: 12, volume: 120 }],
            fundamentals: { revenue: { points: [{ as_of: '2025-01-02', value: 50 }, { as_of: '2025-02-01', value: 60 }] } },
        });
        expect(rows.find((row) => row.date === '2025-02-03').revenue).toBeNull();
        expect(rangeAndSamplingRows(rows, 'all', '1m')).toHaveLength(3);
        expect(rangeAndSamplingRows(rows, 'all', '1q')).toHaveLength(3);
    });

    it('keeps dropdown, cyclic arrows, legend visibility and default action synchronized', () => {
        const availability = Object.fromEntries(COMBO_CHART_PRESETS.map(({ id }) => [id, { available: true }]));
        const rows = [{ date: '2025-01-01', price: 10, volume: 100, pe: 2 }, { date: '2025-02-01', price: 11, volume: 120, pe: 3 }];
        const onSetDefault = vi.fn();
        render(<ComboChart rows={rows} availability={availability} defaultPresetId="price-volume" onSetDefault={onSetDefault} />);
        const selector = screen.getByLabelText('Combo chart preset');
        fireEvent.change(selector, { target: { value: 'price-pe' } });
        expect(selector.value).toBe('price-pe');
        expect(screen.getByRole('checkbox', { name: 'Show P/E' })).toBeChecked();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Show P/E' }));
        expect(screen.getByRole('checkbox', { name: 'Show P/E' })).not.toBeChecked();
        fireEvent.click(screen.getByRole('button', { name: 'Next combo chart' }));
        expect(selector.value).toBe('price-pb');
        fireEvent.click(screen.getByRole('button', { name: 'Set as default' }));
        expect(onSetDefault).toHaveBeenCalledWith('price-pb');
        const range = screen.getByRole('group', { name: 'Combo chart time range' });
        fireEvent.click(within(range).getByRole('button', { name: '1Y' }));
        expect(within(range).getByRole('button', { name: '1Y' })).toHaveAttribute('aria-pressed', 'true');
        const sampling = screen.getByRole('group', { name: 'Combo chart sampling frequency' });
        fireEvent.click(within(sampling).getByRole('button', { name: 'Quarterly' }));
        expect(within(sampling).getByRole('button', { name: 'Quarterly' })).toHaveAttribute('aria-pressed', 'true');
    });
});
