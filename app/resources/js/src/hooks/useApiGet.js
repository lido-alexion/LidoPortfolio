import { useCallback, useEffect, useState } from 'react';
import { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

/**
 * Shared GET/load pattern: loading flag, optional data state, toast on failure.
 * Pass `deps` for values that should trigger a reload (same as useEffect deps for fetch).
 * Use `skipErrorToast: true` on underlying api calls to avoid duplicate interceptor toasts.
 */
export default function useApiGet({
    request,
    deps = [],
    enabled = true,
    errorFallback = 'Request failed',
    initialData = null,
    onError,
}) {
    const [data, setData] = useState(initialData);
    const [loading, setLoading] = useState(Boolean(enabled));
    const [error, setError] = useState(null);

    const reload = useCallback(async () => {
        if (!enabled) {
            setData(initialData);
            setLoading(false);
            setError(null);
            return null;
        }
        setLoading(true);
        setError(null);
        try {
            const result = await request();
            setData(result);
            return result;
        } catch (e) {
            setError(e);
            if (onError) {
                onError(e);
            }
            showToast(getApiErrorMessage(e, errorFallback), 'danger');
            return null;
        } finally {
            setLoading(false);
        }
    // request is intentionally omitted; list reactive inputs in deps.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, errorFallback, initialData, onError, ...deps]);

    useEffect(() => {
        reload();
    }, [reload]);

    return { data, setData, loading, error, reload };
}
