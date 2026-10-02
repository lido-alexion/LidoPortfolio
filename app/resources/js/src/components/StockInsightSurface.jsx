import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import api from '../api';
import { buildStockAnalysisPrompt } from '../utils/stockAnalysisPrompt';
import { showToast } from '../toast';
import EmbeddedAiResult, { copyAiText } from './EmbeddedAiResult';
import './embeddedAi.css';

export default function StockInsightSurface({ stockId, symbol, name, inlineHost, onClose }) {
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState(true);
    const [wide, setWide] = useState(() => window.matchMedia('(min-width: 1600px)').matches);
    const abort = useRef(null);
    const generation = useRef(0);
    const panel = useRef(null);
    const load = async (refresh = false) => {
        abort.current?.abort();
        const controller = new AbortController();
        abort.current = controller;
        const version = ++generation.current;
        setBusy(true);
        try {
            const response = await api.post(`/ai/insights/stocks/${stockId}`, { refresh }, { signal: controller.signal, timeout: 60000, skipErrorToast: true });
            if (version === generation.current) setResult(response.data.data);
        } catch {
            if (!controller.signal.aborted && version === generation.current) setResult(previous => ({ ...previous, degraded: true }));
        } finally { if (version === generation.current) setBusy(false); }
    };
    useEffect(() => { setResult(null); load(); return () => { ++generation.current; abort.current?.abort(); }; }, [stockId]); // explicit mounted invocation only
    useEffect(() => {
        const media = window.matchMedia('(min-width: 1600px)');
        const changed = () => setWide(media.matches);
        media.addEventListener('change', changed);
        return () => media.removeEventListener('change', changed);
    }, []);
    useEffect(() => {
        if (inlineHost) return undefined;
        const prior = document.activeElement;
        panel.current?.focus();
        document.body.classList.add(wide ? 'stox-insight-pane-open' : 'stox-insight-modal-open');
        return () => { document.body.classList.remove('stox-insight-pane-open', 'stox-insight-modal-open'); prior?.focus?.(); };
    }, [inlineHost, wide]);
    const fallback = async () => {
        try {
            const response = await api.get(`/stocks/${stockId}/market-prices`, { skipErrorToast: true });
            await copyAiText(buildStockAnalysisPrompt({ symbol, name, ohlcvRows: response.data?.data || [] }));
        } catch { showToast('Could not build the AI prompt. Please try again.', 'danger'); }
    };
    const keyDown = event => {
        event.stopPropagation();
        if (event.key === 'Escape') onClose();
        if (!inlineHost && !wide && event.key === 'Tab') {
            const nodes = panel.current.querySelectorAll('button:not(:disabled), a[href]');
            const first = nodes[0]; const last = nodes[nodes.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.current)) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    };
    return createPortal(<div className={inlineHost ? 'stox-insight-inline' : wide ? 'stox-insight-pane' : 'stox-insight-overlay'} onClick={event => event.stopPropagation()}>
        <section ref={panel} tabIndex={-1} onKeyDown={keyDown} role={inlineHost || wide ? 'region' : 'dialog'} aria-modal={!inlineHost && !wide ? true : undefined} aria-label={`AI Insights — ${symbol}`} className="stox-insight-content">
            <div className="d-flex justify-content-between align-items-center"><h3 className="h5">AI Insights — {symbol}</h3><button type="button" className="btn btn-sm btn-outline-secondary" onClick={onClose} aria-label="Close AI Insights">Close</button></div>
            <EmbeddedAiResult result={result} busy={busy} onRefresh={() => load(true)} onFallback={fallback}>
                <Link className="btn btn-sm btn-outline-secondary" to={`/holdings/${stockId}/prices`} onClick={onClose}>Open stock details</Link>
            </EmbeddedAiResult>
        </section>
    </div>, inlineHost || document.body);
}
