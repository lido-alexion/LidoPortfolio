import { describe, expect, it, vi } from 'vitest';
import {
    protectionMenuItems,
    protectionStateLabel,
    protectionTypeLabel,
} from '../../../resources/js/src/utils/positionProtection.js';

describe('position protection UI helpers', () => {
    const holding = { id: 1, is_unmanaged: false };

    it('does not offer place actions in manual or automatic mode', () => {
        expect(protectionMenuItems({
            executionMode: 'manual',
            holding,
            onPlaceTarget: vi.fn(),
            onPlaceStop: vi.fn(),
        })).toEqual([]);
        expect(protectionMenuItems({
            executionMode: 'automatic',
            holding,
            protection: { state: 'active', protection_type: 'stop' },
            onPlaceTarget: vi.fn(),
            onPlaceStop: vi.fn(),
        })).toEqual([]);
    });

    it('offers explicit Target or Stop placement in semi-automatic mode', () => {
        const onPlaceTarget = vi.fn();
        const onPlaceStop = vi.fn();
        const items = protectionMenuItems({
            executionMode: 'semi_automatic',
            holding,
            onPlaceTarget,
            onPlaceStop,
        });
        expect(items.map((i) => i.label)).toEqual(['Place GTT Target', 'Place GTT Stop-Loss']);
        items[0].onClick();
        items[1].onClick();
        expect(onPlaceTarget).toHaveBeenCalledTimes(1);
        expect(onPlaceStop).toHaveBeenCalledTimes(1);
    });

    it('labels GTT states for the holdings badge', () => {
        expect(protectionTypeLabel('stop')).toBe('GTT Stop-Loss');
        expect(protectionTypeLabel('target')).toBe('GTT Target');
        expect(protectionStateLabel('needs_attention')).toBe('Needs attention');
        expect(protectionStateLabel('synchronizing')).toBe('Synchronizing');
    });
});
