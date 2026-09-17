import { describe, expect, it } from 'vitest';
import { strategyAllocationStatus } from '../../../resources/js/src/utils/strategyAllocationStatus.js';

describe('strategy allocation status', () => {
    it('distinguishes above, unused, exact, and unavailable allocation', () => {
        expect(strategyAllocationStatus({ strategy_capital_allocation: 30_000, strategy_deployed_capital: 41_000 }))
            .toEqual({ status: 'above_allocation', amount: 11_000 });
        expect(strategyAllocationStatus({ strategy_capital_allocation: 70_000, strategy_deployed_capital: 41_000 }))
            .toEqual({ status: 'unused_allocation', amount: 29_000 });
        expect(strategyAllocationStatus({ strategy_capital_allocation: 41_000, strategy_deployed_capital: 41_000 }))
            .toEqual({ status: 'within_allocation', amount: 0 });
        expect(strategyAllocationStatus({ strategy_capital_allocation: null, strategy_deployed_capital: 0 }))
            .toEqual({ status: 'unavailable', amount: null });
    });
});
