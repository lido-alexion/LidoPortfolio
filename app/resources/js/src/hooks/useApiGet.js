import { useCallback, useEffect, useRef, useState } from 'react';
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
    const requestRef = useRef(request);
    const initialDataRef = useRef(initialData);
    const onErrorRef = useRef(onError);
    const errorFallbackRef = useRef(errorFallback);

    useEffect(() => {
        requestRef.current = request;
        initialDataRef.current = initialData;
        onErrorRef.current = onError;
        errorFallbackRef.current = errorFallback;
    }, [request, initialData, onError, errorFallback]);

    const reload = useCallback(async () => {
        if (!enabled) {
            setData(initialDataRef.current);
            setLoading(false);
            setError(null);
            return null;
        }
        setLoading(true);
        setError(null);
        try {
            const result = await requestRef.current();
            setData(result);
            return result;
        } catch (e) {
            setError(e);
            if (onErrorRef.current) {
                onErrorRef.current(e);
            }
            showToast(getApiErrorMessage(e, errorFallbackRef.current), 'danger');
            return null;
        } finally {
            setLoading(false);
        }
    // request, initialData, onError, and errorFallback are kept in refs so callers
    // can pass inline functions/objects without creating a fetch loop.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, ...deps]);

    useEffect(() => {
        reload();
    }, [reload]);

    return { data, setData, loading, error, reload };
}
