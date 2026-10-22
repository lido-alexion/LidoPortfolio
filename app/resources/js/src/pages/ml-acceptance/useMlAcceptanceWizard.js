import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../../api';

const base = '/v1/admin/ml/acceptance';
const isActive = (wizard) => ['preflight', 'training'].includes(wizard?.campaign?.status)
    || ['queued', 'running'].includes(wizard?.backfill?.status)
    || (wizard?.required_sources || []).some((item) => item.sources?.some((source) => ['uploading', 'queued'].includes(source.status)));

export default function useMlAcceptanceWizard() {
    const [wizard, setWizard] = useState(null);
    const [sourcesPage, setSourcesPage] = useState({ data: [], current_page: 1, last_page: 1 });
    const [page, setPage] = useState(1);
    const [busyAction, setBusyAction] = useState('');
    const [error, setError] = useState('');
    const [stale, setStale] = useState(false);
    const [lastChecked, setLastChecked] = useState(null);
    const mounted = useRef(true);
    const errorOrigin = useRef('');
    const pollStartedAt = useRef(null);
    const requestSequence = useRef(0);
    const [pollCycle, setPollCycle] = useState(0);

    const refresh = useCallback(async (sourcePage = page, { preserveError = false, clearError = false } = {}) => {
        const sequence = ++requestSequence.current;
        try {
            const [w, s] = await Promise.allSettled([
                api.get(`${base}/wizard`),
                api.get(`${base}/sources${sourcePage > 1 ? `?page=${sourcePage}` : ''}`),
            ]);
            if (!mounted.current || sequence !== requestSequence.current) return;
            if (w.status === 'fulfilled') setWizard(w.value.data.data);
            if (s.status === 'fulfilled') {
                setSourcesPage(s.value.data.data);
                setPage(s.value.data.data.current_page || sourcePage);
            }
            if (w.status === 'rejected' || s.status === 'rejected') throw w.status === 'rejected' ? w.reason : s.reason;
            setStale(false);
            if (clearError || (!preserveError && errorOrigin.current === 'refresh')) {
                errorOrigin.current = '';
                setError('');
            }
            setLastChecked(new Date());
        } catch (e) {
            if (!mounted.current || sequence !== requestSequence.current) return;
            setStale(true);
            if (!preserveError && !errorOrigin.current) {
                errorOrigin.current = 'refresh';
                setError('Status refresh failed. The last saved server state is still shown. Retry when the connection is available.');
            }
        } finally {
            if (mounted.current) setPollCycle((cycle) => cycle + 1);
        }
    }, [page]);

    useEffect(() => {
        mounted.current = true;
        return () => { mounted.current = false; };
    }, []);

    useEffect(() => { refresh(page); }, [page]);

    useEffect(() => {
        if (!isActive(wizard)) {
            pollStartedAt.current = null;
            return undefined;
        }
        if (pollStartedAt.current === null) pollStartedAt.current = Date.now();
        if (document.visibilityState === 'hidden') return undefined;
        const elapsed = Date.now() - pollStartedAt.current;
        const delay = elapsed < 120000 ? 5000 : elapsed < 600000 ? 15000 : 30000;
        const timer = window.setTimeout(() => {
            if (document.visibilityState !== 'hidden') refresh(page);
        }, delay);
        return () => window.clearTimeout(timer);
    }, [wizard, page, refresh, pollCycle, stale]);

    useEffect(() => {
        const onVisibility = () => {
            if (document.visibilityState === 'visible') {
                pollStartedAt.current = Date.now();
                refresh(page);
            }
        };
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, [refresh, page]);

    const run = useCallback(async (key, operation) => {
        setBusyAction(key);
        errorOrigin.current = '';
        setError('');
        try {
            await operation();
            await refresh(page);
        } catch (e) {
            const timedOut = ['ECONNABORTED', 'ETIMEDOUT', 'ERR_NETWORK'].includes(e?.code) || !e?.response;
            errorOrigin.current = 'mutation';
            if (timedOut) {
                setError('The request did not return a confirmation. StoX may have accepted the action, so status is being checked before you retry.');
                await refresh(page, { preserveError: true });
            } else {
                const fieldErrors = Object.values(e?.response?.data?.errors || {}).flat().join(' ');
                const message = fieldErrors || e?.response?.data?.message || e?.response?.data?.error?.message || e?.message || 'The action could not be completed.';
                setError(message);
                await refresh(page, { preserveError: true });
            }
        } finally {
            if (mounted.current) setBusyAction('');
        }
    }, [page, refresh]);

    return { base, wizard, sourcesPage, page, setPage, busyAction, error, setError, stale, lastChecked,
        refresh: () => refresh(page, { clearError: true }), run };
}
