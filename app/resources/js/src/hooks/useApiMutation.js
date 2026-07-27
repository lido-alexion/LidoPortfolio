import { useCallback, useState } from 'react';
import { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

/**
 * Run an API mutation with optional success toast and shared error extraction.
 * Returns { ok, data } on success or { ok: false, error } on failure.
 */
export async function runApiMutation(fn, {
    successMessage,
    errorFallback = 'Request failed',
} = {}) {
    try {
        const data = await fn();
        if (successMessage) {
            showToast(successMessage, 'success');
        }
        return { ok: true, data };
    } catch (e) {
        showToast(getApiErrorMessage(e, errorFallback), 'danger');
        return { ok: false, error: e };
    }
}

/**
 * Shared mutation pattern with a single busy flag.
 * For per-row busy state, use runApiMutation directly with local id tracking.
 */
export default function useApiMutation({ errorFallback = 'Request failed' } = {}) {
    const [busy, setBusy] = useState(false);

    const run = useCallback(async (fn, options = {}) => {
        setBusy(true);
        try {
            return await runApiMutation(fn, { errorFallback, ...options });
        } finally {
            setBusy(false);
        }
    }, [errorFallback]);

    return { busy, run };
}
