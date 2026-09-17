import { describe, expect, it } from 'vitest';
import {
    capitalAllocationStatusLabel,
    proceedsStatusLabel,
    recallStateLabel,
} from '../../../resources/js/src/utils/capitalRecallUi.js';

describe('multi-strategy state semantics', () => {
    it('keeps unavailable, pending, and committed funding states distinct', () => {
        expect(capitalAllocationStatusLabel('awaiting_lender_selection')).toBe('Awaiting lender');
        expect(capitalAllocationStatusLabel('capital_committed')).toBe('Capital committed');
        expect(capitalAllocationStatusLabel('unfunded')).toBe('Capital required');
    });

    it('keeps recall and sale proceeds lifecycle states explicit', () => {
        expect(recallStateLabel('pending_held')).toBe('Pending — funds being arranged');
        expect(recallStateLabel('completed')).toBe('Completed');
        expect(proceedsStatusLabel('pending')).toBe('Sale executed — proceeds pending');
        expect(proceedsStatusLabel('available')).toBe('Proceeds available');
    });
});
