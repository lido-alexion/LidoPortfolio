import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

function formatPct(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Math.round(Number(v) * 100)}%`;
}

export default function EvaluationsPage() {
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [running, setRunning] = useState(false);
    const [selected, setSelected] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await api.get('/v1/evaluations');
            setItems(Array.isArray(data?.data) ? data.data : []);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load evaluations', 'danger');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const runEval = async () => {
        setRunning(true);
        try {
            await api.post('/v1/evaluation/runs');
            showToast('Evaluation completed', 'success');
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Evaluation failed', 'danger');
        } finally {
            setRunning(false);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Evaluations</h1>
                    <p className="text-muted small mb-0">
                        Ranked candidates with scores, indicators, and explainable rules.
                    </p>
                </div>
                <div className="d-flex gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to="/candidates">Candidates</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading || running}>Refresh</button>
                    <button type="button" className="btn btn-primary btn-sm" onClick={runEval} disabled={running}>
                        {running ? 'Running…' : 'Run evaluation'}
                    </button>
                </div>
            </div>

            {loading ? <p className="text-muted">Loading…</p> : items.length === 0 ? (
                <div className="border rounded p-4 text-muted">
                    No evaluation results. Run discovery first, then evaluation — or use the full pipeline from
                    {' '}
                    <Link to="/recommendations">Recommendations</Link>
                    .
                </div>
            ) : (
                <div className="table-responsive">
                    <table className="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Symbol</th>
                                <th>Score</th>
                                <th>Confidence</th>
                                <th>Explanation</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((r) => (
                                <tr key={r.id}>
                                    <td>{r.rank}</td>
                                    <td>
                                        <strong>{r.symbol}</strong>
                                        {r.name ? <div className="small text-muted">{r.name}</div> : null}
                                    </td>
                                    <td>{Number(r.score).toFixed(1)}</td>
                                    <td>{formatPct(r.confidence)}</td>
                                    <td className="small text-muted" style={{ maxWidth: 360 }}>{r.explanation || '—'}</td>
                                    <td>
                                        <button type="button" className="btn btn-link btn-sm px-0" onClick={() => setSelected(r)}>
                                            Details
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {selected && (
                <div className="modal show d-block" style={{ background: 'rgba(0,0,0,.45)' }}>
                    <div className="modal-dialog modal-lg modal-dialog-scrollable">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 className="modal-title">
                                    #
                                    {selected.rank}
                                    {' '}
                                    {selected.symbol}
                                    {' '}
                                    — evaluation
                                </h5>
                                <button type="button" className="btn-close" onClick={() => setSelected(null)} aria-label="Close" />
                            </div>
                            <div className="modal-body">
                                <p className="small">
                                    Score
                                    {' '}
                                    <strong>{Number(selected.score).toFixed(2)}</strong>
                                    {' · '}
                                    Confidence
                                    {' '}
                                    <strong>{formatPct(selected.confidence)}</strong>
                                </p>
                                <h6>Indicators</h6>
                                <pre className="small bg-body-tertiary p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                    {JSON.stringify(selected.indicators || {}, null, 2)}
                                </pre>
                                <h6>Component scores</h6>
                                <pre className="small bg-body-tertiary p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                    {JSON.stringify(selected.component_scores || {}, null, 2)}
                                </pre>
                                <h6>Passed rules</h6>
                                <ul className="small">{(selected.passed_rules || []).map((x) => <li key={x}>{x}</li>)}</ul>
                                <h6>Failed rules</h6>
                                <ul className="small">{(selected.failed_rules || []).map((x) => <li key={x}>{x}</li>)}</ul>
                            </div>
                            <div className="modal-footer">
                                <Link className="btn btn-outline-secondary btn-sm" to="/recommendations">Recommendations</Link>
                                <button type="button" className="btn btn-primary btn-sm" onClick={() => setSelected(null)}>Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
