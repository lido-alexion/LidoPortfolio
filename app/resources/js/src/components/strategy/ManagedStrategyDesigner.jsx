import React, { useEffect, useRef, useState } from 'react';
import api from '../../api';
import EmbeddedAiResult, { copyAiText } from '../EmbeddedAiResult';
import AssistantRun from '../AssistantRun';
import { getActivePortfolioId } from '../../portfolio/activePortfolioStorage';

export default function ManagedStrategyDesigner({ inputs, fallback }) {
    const [scope, setScope] = useState(getActivePortfolioId);
    const key = JSON.stringify([scope, inputs]);
    const latestKey = useRef(key);
    latestKey.current = key;
    useEffect(() => {
        const changed = () => setScope(getActivePortfolioId());
        window.addEventListener('portfolio-changed', changed);
        return () => window.removeEventListener('portfolio-changed', changed);
    }, []);
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);
    const [run, setRun] = useState(null);
    const [draftError, setDraftError] = useState('');
    const request = useRef(0);
    const controller = useRef(null);
    const lookupTimer = useRef(null);
    const current = state?.key === key ? state.result : null;
    async function load(refresh = false, lookupOnly = false) {
        if (!lookupOnly) clearTimeout(lookupTimer.current);
        controller.current?.abort();
        const abort = new AbortController(); controller.current = abort;
        const id = ++request.current;
        setBusy(!lookupOnly);
        try {
            const response = await api.post('/ai/insights/strategy', { inputs, refresh, lookup_only: lookupOnly }, { signal: abort.signal, timeout: 60000, skipErrorToast: true });
            if (id === request.current) setState({ key, result: response.data.data });
        } catch {
            if (id === request.current && !abort.signal.aborted && !lookupOnly) setState(previous => ({ key, result: { ...(previous?.key === key ? previous.result : {}), degraded: true } }));
        } finally { if (id === request.current) setBusy(false); }
    }
    useEffect(() => {
        setRun(null); setDraftError(''); setState(null);
        // Lookup only restores a saved result; input edits never trigger generation.
        const timer = setTimeout(() => load(false, true), 200);
        lookupTimer.current = timer;
        return () => { clearTimeout(timer); ++request.current; controller.current?.abort(); };
    }, [key]);
    async function draft() {
        setBusy(true); setDraftError('');
        try {
            const response = await api.post('/ai/insights/strategy/draft', { fingerprint: current.fingerprint }, { skipErrorToast: true });
            if (latestKey.current === key) setRun(response.data.data);
        } catch (error) {
            if (latestKey.current !== key) return;
            const code = error.response?.data?.error?.code;
            setDraftError(code === 'preview_too_large' ? 'Create draft strategy is unavailable for this result: it exceeds the governed limit of 100 field changes. Simplify the design and regenerate. No strategy was created.' : code === 'artifact_validation_failed' ? 'Create draft strategy is unavailable for this result: the generated envelope does not pass StoX artifact validation. Regenerate or revise the design.' : 'Draft preview is unavailable. No strategy was created. Please try again.');
        } finally { if (latestKey.current === key) setBusy(false); }
    }
    return <div className="mt-3">
        {!current?.response && !current?.degraded && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => load()}>Generate strategy</button>}
        {(busy || current?.response || current?.degraded) && <EmbeddedAiResult strategy result={current} busy={busy} onRefresh={() => load(true)} onFallback={() => copyAiText(fallback())}>
            {current?.response && <button type="button" className="btn btn-sm btn-primary" disabled={busy || Boolean(run && ['awaiting_approval', 'completed'].includes(run.status))} onClick={draft}>Create draft strategy</button>}
        </EmbeddedAiResult>}
        {draftError && <p role="alert" className="mt-2">{draftError}</p>}
        {run && <AssistantRun run={run} onChange={setRun} onRetry={draft} />}
    </div>;
}
