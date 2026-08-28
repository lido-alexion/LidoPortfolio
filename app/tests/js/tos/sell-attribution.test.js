import { describe, expect, it } from 'vitest';
import {
    buildSellPrefillFromHolding,
    ownerLabelFromKey,
} from '../../../resources/js/src/utils/sellTransactionPrefill.js';

describe('sell transaction prefill (V4-SPEC-005)', () => {
    it('includes owner_key from the holding row', () => {
        const prefill = buildSellPrefillFromHolding({
            quantity: 12,
            owner_key: 'strategy:9',
            strategy_id: 9,
            is_unmanaged: false,
            stock: { id: 3, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' },
            stoploss_summary: { latest_close: 1500 },
        });
        expect(prefill.owner_key).toBe('strategy:9');
        expect(prefill.strategy_id).toBe(9);
        expect(prefill.quantity).toBe(12);
    });

    it('labels unmanaged and strategy owners', () => {
        expect(ownerLabelFromKey('unmanaged')).toBe('Unmanaged');
        expect(ownerLabelFromKey('strategy:4')).toBe('Strategy 4');
    });
});
