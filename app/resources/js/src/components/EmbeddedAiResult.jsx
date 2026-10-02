import React from 'react';
import { showToast } from '../toast';

export async function copyAiText(text) {
    try {
        if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(text);
        else {
            const field = document.createElement('textarea');
            field.value = text;
            field.style.position = 'fixed';
            field.style.left = '-9999px';
            document.body.appendChild(field);
            field.select();
            try { if (!document.execCommand('copy')) throw new Error('clipboard'); }
            finally { field.remove(); }
        }
        showToast('Copied to clipboard.');
    } catch { showToast('Could not copy. Please try again.', 'danger'); }
}

export const INSIGHT_LABELS = {
    summary: 'Summary', technical_price_context: 'Technical / price context', fundamental_context: 'Fundamental context',
    positive_signals: 'Positive signals', risks_watch_items: 'Risks / watch items', what_to_check_next: 'What to check next', data_limitations: 'Data limitations',
    strategy_summary: 'Strategy summary', target_market_universe: 'Target market / universe', entry_logic: 'Entry logic', exit_logic: 'Exit logic',
    allocation_position_sizing: 'Allocation / position sizing', risk_controls: 'Risk controls', assumptions: 'Assumptions', caveats: 'Caveats',
    rationale: 'Rationale', artifact_compatibility_notes: 'StoX artifact compatibility',
};
export function provenanceLines(data = {}) {
    return [data.ohlcv_through && `OHLCV through ${data.ohlcv_through}`, data.fundamentals_period && `Fundamentals through ${data.fundamentals_period}`,
        data.rs_benchmark && `RS vs ${data.rs_benchmark} as of ${data.rs_as_of}`, data.pattern_as_of && `Pattern scan as of ${data.pattern_as_of}`,
        data.holding_personalized && 'Personalized with your active portfolio holding'].filter(Boolean);
}
export function insightText(result) {
    return [...Object.entries(result.response || {}).filter(([key]) => INSIGHT_LABELS[key]).map(([key, value]) => `${INSIGHT_LABELS[key]}\n${value}`),
        `Based on / Data used\n${provenanceLines(result.data_as_of || {}).join('\n')}`].join('\n\n');
}
export default function EmbeddedAiResult({ result, busy, onRefresh, onFallback, children, strategy = false }) {
    return <section aria-label={strategy ? 'Strategy design result' : 'AI insight result'} aria-busy={busy}>
        {busy && <p role="status">Generating {strategy ? 'strategy design' : 'AI insight'}…</p>}
        {result?.degraded && <p role="status" className="text-muted small">{result.response ? 'Fresh AI refresh unavailable; showing the latest cached insight.' : 'Managed AI is unavailable. You can copy the AI prompt and try manually.'}</p>}
        {result?.response && <>
            {Object.entries(result.response).filter(([key]) => INSIGHT_LABELS[key]).map(([key, value]) => <section key={key}><h4 className="h6 mt-3">{INSIGHT_LABELS[key]}</h4><p style={{ whiteSpace: 'pre-wrap' }}>{value}</p></section>)}
            <div className="small text-muted" aria-label="Based on / Data used"><strong>Based on / Data used</strong>{provenanceLines(result.data_as_of || {}).map(line => <div key={line}>{line}</div>)}</div>
        </>}
        <div className="d-flex flex-wrap gap-2 mt-3">
            <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={onRefresh}>{strategy ? 'Regenerate' : 'Refresh insight'}</button>
            {result?.response && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => copyAiText(insightText(result))}>{strategy ? 'Copy result' : 'Copy insight'}</button>}
            {result?.degraded && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={onFallback}>Copy AI Prompt</button>}
            {children}
        </div>
    </section>;
}
