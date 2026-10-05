import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useLocation, Link } from 'react-router-dom';
import api from '../api';
import AssistantRun from './AssistantRun';
import { resolveDocKeywordFromPath } from '../utils/documentationLinks';
import { streamAssistant, safeSourceUrl } from '../utils/assistantStream';
import { searchJourneyTopics } from '../data/journeyMetadata';

export default function AssistantDrawer() {
    const { pathname } = useLocation();
    const [open, setOpen] = useState(false), [question, setQuestion] = useState('');
    useEffect(() => {
        const handoff = (event) => { setQuestion(event.detail?.question || ''); setOpen(true); };
        window.addEventListener('lido-assistant-handoff', handoff);
        return () => window.removeEventListener('lido-assistant-handoff', handoff);
    }, []);
    const [turns, setTurns] = useState([]), [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [mode, setMode] = useState('documentation'), [runs, setRuns] = useState([]);
    const dialog = useRef(null), trigger = useRef(null), controller = useRef(null);
    const generation = useRef(0);
    const clear = () => { generation.current++; controller.current?.abort(); setBusy(false); setTurns([]); setRuns([]); setNotice('Conversation cleared.'); };
    useEffect(() => () => controller.current?.abort(), []);
    useEffect(() => {
        if (open) dialog.current?.showModal?.();
        else if (dialog.current?.open) { dialog.current?.close?.(); trigger.current?.focus(); }
    }, [open]);
    const help = searchJourneyTopics(question || turns.at(-1)?.question || resolveDocKeywordFromPath(pathname)).slice(0, 3);
    async function send(event, suggested) {
        event?.preventDefault();
        const text = (suggested || question).trim();
        if (!text || busy) return;
        if (mode === 'account') { await investigate(text); return; }
        const index = turns.length, version = generation.current;
        const update = patch => { if (generation.current === version) setTurns(items => items.map((item, i) => i === index ? { ...item, ...patch } : item)); };
        setTurns(items => [...items, { question: text, answer: '', sources: [], grounding: 'pending' }]);
        setQuestion(''); setBusy(true); setNotice('');
        controller.current = new AbortController();
        let answer = '';
        try {
            await streamAssistant({ question: text, conversation: turns.slice(-6).map(t => ({ question: t.question, answer: t.answer.slice(0, 6000) })),
                page_context: { route: pathname, topic: resolveDocKeywordFromPath(pathname),
                    section: (document.querySelector('[role="tab"][aria-selected="true"]')?.textContent || '').trim().slice(0, 100),
                    visible: Array.from(document.querySelectorAll('[data-assistant-label]')).filter(el => el.getClientRects().length).slice(0, 20).map(el => ({ label: el.getAttribute('data-assistant-label').slice(0, 100), value: (el.textContent || '').trim().slice(0, 160) })) } }, controller.current.signal, (type, data) => {
                if (type === 'message.delta') { answer += data.text || ''; update({ answer }); }
                if (type === 'message.completed') update({ grounding: data.grounding, sources: (data.provenance || []).filter(source => safeSourceUrl(source.url)), requestId: data.request_id });
                if (type === 'error') update({ answer: data.code === 'read_only_scope' ? 'I can explain StoX documentation, but cannot execute trades, change data or provide investment advice.' : data.code === 'grounding_insufficient' ? 'StoX documentation does not provide enough evidence to answer this question.' : 'The assistant is temporarily unavailable. You can still use How do I? help.', grounding: 'insufficient', sources: (data.provenance || []).filter(source => safeSourceUrl(source.url)), requestId: data.request_id });
            });
        } catch (error) {
            if (error.name !== 'AbortError') update({ answer: 'The assistant is temporarily unavailable. You can still use How do I? help.', grounding: 'insufficient', sources: [] });
        } finally { if (generation.current === version) setBusy(false); }
    }
    async function investigate(objective, retryRunId) {
        const version = generation.current;
        setBusy(true); setNotice('Investigating account evidence…'); setQuestion('');
        controller.current = new AbortController();
        try {
            const response = await api.post('/ai/assistant/runs', { objective, ...(retryRunId ? { retry_run_id: retryRunId } : {}) }, { signal: controller.current.signal });
            if (generation.current === version) { setRuns(items => [response.data.data, ...items]); setNotice(''); }
        } catch { if (generation.current === version) setNotice('The investigation could not finish. Check run history for its current state.'); }
        finally { if (generation.current === version) setBusy(false); }
    }
    async function history() {
        const version = generation.current;
        try { const response = await api.get('/ai/assistant/runs'); if (generation.current === version) setRuns(response.data.data); }
        catch { setNotice('Run history is temporarily unavailable.'); }
    }
    async function feedback(turn, helpful, comment = '') {
        try { await api.post('/ai/assistant/feedback', { request_id: turn.requestId, helpful, comment }); setNotice('Feedback saved.'); }
        catch { setNotice('Feedback could not be saved. Please try again.'); }
    }
    return <>
        <button ref={trigger} className="btn border text-body btn-sm" onClick={() => setOpen(true)} aria-label="Open StoX assistant">Ask StoX</button>
        {createPortal(<dialog ref={dialog} aria-labelledby="assistant-title" onCancel={() => { setOpen(false); trigger.current?.focus(); }} style={{ margin: '0 0 0 auto', height: '100dvh', maxHeight: '100dvh', width: 'min(480px, 100vw)', maxWidth: '100vw', padding: '1rem', border: '1px solid var(--bs-border-color)', background: 'var(--bs-body-bg)', color: 'var(--bs-body-color)' }}>
            <div className="d-flex justify-content-between align-items-center"><h2 id="assistant-title" className="h5">StoX assistant</h2><button className="btn btn-sm border text-body" onClick={() => setOpen(false)}>Close assistant</button></div>
            <p className="small text-muted">Documentation help and governed account investigations. Changes require your explicit approval. Broker trading is unavailable.</p>
            <label className="d-block mb-2">Assistant mode<select className="form-select" value={mode} onChange={event => setMode(event.target.value)} disabled={busy}><option value="documentation">Documentation help</option><option value="account">Account investigation and actions</option></select></label>
            <button className="btn btn-sm border text-body mb-3 ms-2" onClick={history}>Run history</button>
            <button className="btn btn-sm border text-body mb-3" onClick={clear}>Clear conversation</button>
            <div aria-live="polite" aria-busy={busy}>
                {turns.map((turn, index) => <article key={index} className="border rounded p-3 mb-3">
                    <h3 className="h6">{turn.question}</h3><p style={{ whiteSpace: 'pre-wrap' }}>{turn.answer || 'Looking for documentation…'}</p>
                    <p className="small fw-semibold">{({ grounded: 'Grounded', partial: 'Partially grounded', insufficient: 'Insufficient documentation', pending: 'Checking documentation' })[turn.grounding]}</p>
                    {turn.sources.length > 0 && <details><summary>Sources used ({turn.sources.length})</summary>{turn.sources.map(source => <div key={source.source_id} className="my-2"><a href={safeSourceUrl(source.url)} target="_blank" rel="noreferrer">{source.title} — {source.section}</a><p className="small">{source.snippet}</p></div>)}</details>}
                    {turn.answer && turn.grounding !== 'pending' && <button className="btn btn-sm border text-body me-2" onClick={async () => { try { await navigator.clipboard.writeText(turn.answer + '\n\n' + turn.sources.map(source => `${source.title}: ${new URL(safeSourceUrl(source.url), window.location.origin)}`).join('\n')); setNotice('Response copied.'); } catch { setNotice('Copy unavailable. Select the response text to copy.'); } }}>Copy response</button>}
                    {turn.requestId && <><button className="btn btn-sm border text-body me-2" onClick={() => feedback(turn, true)}>Helpful</button><details><summary>Not helpful</summary><form onSubmit={event => { event.preventDefault(); feedback(turn, false, new FormData(event.currentTarget).get('comment')); }}><label className="d-block">Optional comment<textarea name="comment" maxLength={500} className="form-control" /></label><button className="btn btn-sm border text-body">Send feedback</button></form></details></>}
                    {turn.sources.slice(0, 2).map(source => <button key={source.source_id} disabled={busy} className="btn btn-link text-start" onClick={event => send(event, `Explain ${source.section} in StoX`)}>Explain {source.section}</button>)}
                </article>)}
            </div>
            {runs.map(run => <AssistantRun key={run.id} run={run} onChange={updated => setRuns(items => items.map(item => item.id === updated.id ? updated : item))} onRetry={previous => investigate(previous.objective, previous.id)} />)}
            <form onSubmit={send}><label htmlFor="assistant-question">Ask about StoX</label><textarea id="assistant-question" className="form-control" maxLength={4000} value={question} onChange={event => setQuestion(event.target.value)} required /><button className="btn btn-primary my-2" disabled={busy || !question.trim()}>Ask</button></form>
            <nav aria-label="How do I? deterministic help"><strong>How do I? help</strong><p className="small">Documentation search works independently of AI.</p>{help.map(topic => <Link className="d-block mb-2" key={topic.id} to={`/documentation?journey=${encodeURIComponent(topic.id)}`} onClick={() => setOpen(false)}>{topic.title}</Link>)}<Link to="/documentation?q=help" onClick={() => setOpen(false)}>Browse documentation</Link></nav>
            <p role="status">{notice}</p>
        </dialog>, document.body)}
    </>;
}
