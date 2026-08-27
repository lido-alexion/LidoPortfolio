import { describe, expect, it } from 'vitest';
import { tosData, tosList, tosMeta } from '../../../resources/js/src/utils/tosEnvelope.js';
import { apiEnvelope, axiosOk } from './fixtures/tosApi.js';

describe('tosEnvelope', () => {
    it('unwraps ApiEnvelope list, data, and meta the same way pages did inline', () => {
        const response = axiosOk(apiEnvelope([{ id: 1 }], { cash: { cash_balance: 10 } }));

        expect(tosList(response)).toEqual([{ id: 1 }]);
        expect(tosData(response)).toEqual([{ id: 1 }]);
        expect(tosMeta(response)).toEqual({ cash: { cash_balance: 10 } });
    });

    it('treats missing or non-array data as an empty list', () => {
        expect(tosList(axiosOk(apiEnvelope(null)))).toEqual([]);
        expect(tosList({ data: { success: true } })).toEqual([]);
        expect(tosData({ data: { success: true } })).toBeNull();
        expect(tosMeta({ data: { success: true } })).toEqual({});
    });
});
