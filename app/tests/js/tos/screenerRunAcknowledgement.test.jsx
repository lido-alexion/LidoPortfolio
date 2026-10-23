import { describe, expect, it } from 'vitest';
import {
    hasScreenerRunId,
    runCompletionNotice,
    screenerRunIdsMatch,
} from '../../../resources/js/src/components/screener/screenerTableHelpers.jsx';

describe('screener run acknowledgement contract', () => {
    it('accepts only positive safe integer IDs and matches canonical numeric/string identities', () => {
        for (const id of [1, 42, Number.MAX_SAFE_INTEGER, '1', '42', String(Number.MAX_SAFE_INTEGER)]) {
            expect(hasScreenerRunId({ id })).toBe(true);
        }
        for (const id of [0, -1, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1,
            '0', '-1', '01', '1.0', '1e2', '9007199254740992', '/42', '?run=42', 'abc']) {
            expect(hasScreenerRunId({ id })).toBe(false);
        }
        expect(screenerRunIdsMatch(42, '42')).toBe(true);
        expect(screenerRunIdsMatch('42', 42)).toBe(true);
        expect(screenerRunIdsMatch(42, '043')).toBe(false);
        expect(screenerRunIdsMatch(42, '42?next=43')).toBe(false);
    });

    it('acknowledges only completed runs and gives fixed notices for other states', () => {
        expect(runCompletionNotice({ id: 42, status: 'completed', stats: { matched: 0 } })).toBeNull();
        expect(runCompletionNotice({ id: 42, status: 'failed' })).toEqual({
            message: 'Run ID 42 failed. Review run details for the recorded error.',
            variant: 'danger',
        });
        expect(runCompletionNotice({ id: 42, status: 'running' })).toEqual({
            message: 'Run ID 42 is not complete yet. Review run details before retrying.',
            variant: 'warning',
        });
        for (const run of [null, {}, { id: 42 }, { id: 42, status: 'queued' }]) {
            expect(runCompletionNotice(run)).toEqual({
                message: 'Run completion could not be confirmed. Review run history before retrying.',
                variant: 'warning',
            });
        }
    });
});
