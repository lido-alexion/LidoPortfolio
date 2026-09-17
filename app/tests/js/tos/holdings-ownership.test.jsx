import { describe, expect, it } from 'vitest';
import {
    holdingOwnershipLabel,
    holdingOwnershipTypeLabel,
    ownershipEpisodeCount,
    siblingOwnershipNames,
} from '../../../resources/js/src/utils/holdingOwnership.js';

describe('holdings ownership presentation', () => {
    it('keeps unmanaged explicit and renders named strategy ownership without owner keys', () => {
        expect(holdingOwnershipLabel({ is_unmanaged: true, owner_key: 'unmanaged' })).toBe('Unmanaged');
        expect(holdingOwnershipLabel({ strategy_id: 12, strategy_name: 'Momentum Strategy', owner_key: 'strategy:12' }))
            .toBe('Momentum Strategy');
        expect(holdingOwnershipLabel({ strategy_id: 12, owner_key: 'strategy:12' })).toBe('Strategy-managed');
        expect(holdingOwnershipTypeLabel({ strategy_id: 12 })).toBe('Strategy-managed');
    });

    it('counts distinct ownership episodes and names sibling strategy episodes', () => {
        const holdings = [
            { id: 1, stock_id: 7, strategy_id: 12, strategy_name: 'Momentum Strategy' },
            { id: 2, stock_id: 7, strategy_id: 14, strategy_name: 'Value Strategy' },
            { id: 3, stock_id: 7, is_unmanaged: true },
            { id: 4, stock_id: 8, strategy_id: 99, strategy_name: 'Other Strategy' },
        ];

        expect(ownershipEpisodeCount(holdings[0], holdings)).toBe(3);
        expect(siblingOwnershipNames(holdings[0], holdings)).toEqual(['Value Strategy']);
        expect(ownershipEpisodeCount(holdings[3], holdings)).toBe(1);
    });
});
