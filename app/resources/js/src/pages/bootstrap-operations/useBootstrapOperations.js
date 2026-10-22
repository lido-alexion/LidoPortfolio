import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../../api';

export const ROOT = '/v1/admin/fundamentals/bootstrap-operations';
export const unwrap = (response) => response?.data?.data ?? response?.data ?? null;
export const pageRows = (value) => Array.isArray(value) ? value : (value?.data ?? value?.items ?? []);
export const pageMeta = (value) => value?.meta ?? value ?? {};
export const actionIdentity = (action, payload) => {
    const input = JSON.stringify(payload ?? null);
    let hash = 2166136261;
    for (let i = 0; i < input.length; i += 1) hash = Math.imul(hash ^ input.charCodeAt(i), 16777619);
    return `${action}-${(hash >>> 0).toString(36)}`;
};
const keyFor = (action) => {
    const storageKey = `bootstrap-ops-key:${action}`;
    try {
        const existing = sessionStorage.getItem(storageKey);
        if (existing) return existing;
        const key = `ops-${action}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`}`;
        sessionStorage.setItem(storageKey, key);
        return key;
    } catch {
        return `ops-${action}-${globalThis.crypto?.randomUUID?.() || Date.now()}`;
    }
};
const clearKey = (action) => { try { sessionStorage.removeItem(`bootstrap-ops-key:${action}`); } catch { /* storage is optional */ } };

export default function useBootstrapOperations() {
    const [health, setHealth] = useState(null);
    const [coverage, setCoverage] = useState(null);
    const [runs, setRuns] = useState([]);
    const [runMeta, setRunMeta] = useState({});
    const [detail, setDetail] = useState(null);
    const [gaps, setGaps] = useState([]);
    const [gapMeta, setGapMeta] = useState({});
    const [warnings, setWarnings] = useState([]);
    const [warningMeta, setWarningMeta] = useState({});
    const [presets, setPresets] = useState([]);
    const [presetMeta,setPresetMeta]=useState({});
    const [schedules, setSchedules] = useState([]);
    const [scheduleMeta,setScheduleMeta]=useState({});
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [stale, setStale] = useState(false);
    const [needsReconciliation, setNeedsReconciliation] = useState(false);
    const [filters, setFilters] = useState({ runPage: 1, gapPage: 1, warningPage: 1, status: '', trigger: '', gapState: 'actionable', gapReason: '', metric: '', cadence: '', warningState: 'unresolved', warningCategory: '' });
    const activeRef = useRef(false);
    const readSequenceRef = useRef(0);
    const detailSequenceRef = useRef(0);
    const busyCountRef = useRef(0);
    const beginBusy = () => { busyCountRef.current += 1; setBusy(true); };
    const endBusy = () => { busyCountRef.current = Math.max(0, busyCountRef.current - 1); if (busyCountRef.current === 0) setBusy(false); };
    const runPath = `${ROOT}/runs`;
    const get = useCallback(async (path, params = {}) => unwrap(await api.get(path, { params })), []);
    const load = useCallback(async ({ quiet = false, preserveError = false } = {}) => {
        const ticket = ++readSequenceRef.current;
        if (!quiet) beginBusy();
        try {
            const [h, c, r, g, w, p, s] = await Promise.all([
                get(`${ROOT}/health`), get(`${ROOT}/coverage`),
                get(runPath, { page: filters.runPage, per_page: 25, status: filters.status || undefined, trigger: filters.trigger || undefined }),
                get(`${ROOT}/gaps`, { page: filters.gapPage, per_page: 25, state: filters.gapState || undefined, reason: filters.gapReason || undefined, metric: filters.metric || undefined, cadence: filters.cadence || undefined }),
                get(`${ROOT}/warnings`, { page: filters.warningPage, per_page: 25, state: filters.warningState || undefined, category: filters.warningCategory || undefined }),
                get(`${ROOT}/presets`, { page: filters.presetPage || 1, per_page: 25 }), get(`${ROOT}/schedules`, { page: filters.schedulePage || 1, per_page: 25 }),
            ]);
            if (ticket !== readSequenceRef.current) return null;
            setHealth(h); setCoverage(c); setRuns(pageRows(r)); setRunMeta(pageMeta(r));
            setGaps(pageRows(g)); setGapMeta(pageMeta(g)); setWarnings(pageRows(w)); setWarningMeta(pageMeta(w));
            setPresets(pageRows(p)); setSchedules(pageRows(s)); setStale(false); setNeedsReconciliation(false); if (!preserveError) setError('');
            setPresetMeta(pageMeta(p)); setScheduleMeta(pageMeta(s));
            const runList = pageRows(r);
            activeRef.current = Boolean(h?.active_run) || Number(h?.queued_count || 0) > 0 || runList.some((run) => ['queued', 'running', 'active', 'pending'].includes(String(run.status).toLowerCase()));
            return true;
        } catch (e) {
            if (ticket !== readSequenceRef.current) return null;
            setStale(true); if (!preserveError) setError(e?.response?.status === 409 ? 'The server is updating this operation. Refresh to see its latest state.' : 'Operations could not be refreshed. Please try again.');
            return false;
        } finally { if (!quiet) endBusy(); }
    }, [filters, get, runPath]);
    const loadRef=useRef(load);
    useEffect(()=>{loadRef.current=load;},[load]);
    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        const timer = window.setInterval(() => {
            if (activeRef.current && document.visibilityState === 'visible') load({ quiet: true });
        }, 12000);
        const refresh = () => { if (document.visibilityState === 'visible' && activeRef.current) load({ quiet: true }); };
        document.addEventListener('visibilitychange', refresh);
        return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', refresh); };
    }, [load]);
    const mutate = useCallback(async (action, request, { keyed = false } = {}) => {
        if (stale && !needsReconciliation) {
            setError('Refresh current server state before making another change.');
            throw new Error('Refresh required before mutation.');
        }
        beginBusy(); setError('');
        const key = keyed ? keyFor(action) : null;
        try {
            if (needsReconciliation) {
                const reconciled = await loadRef.current({ quiet: true, preserveError: true });
                if (!reconciled) {
                    setError('The prior action is still unconfirmed because current server state could not be refreshed. Refresh successfully before retrying.');
                    throw new Error('Reconciliation required before retry.');
                }
            }
            const response = await request(key);
            if (keyed) clearKey(action);
            await loadRef.current({ quiet: true });
            return unwrap(response);
        } catch (e) {
            activeRef.current = true;
            const status = e?.response?.status;
            if (e?.message === 'Reconciliation required before retry.') throw e;
            setNeedsReconciliation(true);
            const reconciled = await loadRef.current({ quiet: true, preserveError: true });
            setError(status === 409
                ? `The server rejected the action with a conflict. Current state ${reconciled ? 'was refreshed' : 'could not be refreshed'}; no success was reported.`
                : `The action result is uncertain. Current state ${reconciled ? 'was refreshed' : 'could not be refreshed'}; retry will reuse the same request key.`);
            throw e;
        } finally { endBusy(); }
    }, [needsReconciliation, stale]);
    const launch = useCallback((body, action = 'launch') => mutate(actionIdentity(action, body), (key) => api.post(runPath, body, { headers: { 'Idempotency-Key': key } }), { keyed: true }), [mutate, runPath]);
    const retryFailed = useCallback((id) => mutate(`retry-${id}`, (key) => api.post(`${runPath}/${id}/retry-failed`, {}, { headers: { 'Idempotency-Key': key } }), { keyed: true }), [mutate, runPath]);
    const loadDetail = useCallback(async (id,page=1) => { const ticket=++detailSequenceRef.current;beginBusy(); try { const result=unwrap(await api.get(`${runPath}/${id}`, { params: { page } }));if(ticket===detailSequenceRef.current)setDetail(result); } catch (e) { if(ticket===detailSequenceRef.current)setError('Run details could not be loaded.'); } finally { endBusy(); } }, [runPath]);
    const closeDetail = useCallback(()=>{detailSequenceRef.current+=1;setDetail(null);},[]);
    return { health, coverage, runs, runMeta, detail, setDetail, closeDetail, loadDetail, gaps, gapMeta, warnings, warningMeta, presets, presetMeta, schedules, scheduleMeta, error, busy, stale, needsReconciliation, filters, setFilters, load, mutate, launch, retryFailed };
}
