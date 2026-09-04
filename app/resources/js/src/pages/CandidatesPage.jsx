import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import PatternSketch from '../components/PatternSketch';
import useApiGet from '../hooks/useApiGet';
import { runApiMutation } from '../hooks/useApiMutation';
import { showToast } from '../toast';
import { categoryLabel, PATTERN_BY_ID } from '../utils/patternDetection';
import { patternGuideLink } from '../utils/patternGuideLinks';
import { tosList } from '../utils/tosEnvelope';

function formatPct(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Math.round(Number(v) * 100)}%`;
}

function formatScore(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toFixed(1);
}

function DefaultScreenerCard({ screener, loading, running, onRun }) {
    if (loading) {
        return <div className="card mb-3"><div className="card-body text-muted small">Loading default screener…</div></div>;
    }

    if (!screener) {
        return (
            <div className="card mb-3 border-warning-subtle">
                <div className="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div className="fw-semibold">Default screener unavailable</div>
                        <div className="small text-muted">Open Screeners to create or restore the portfolio&apos;s factory screener.</div>
                    </div>
                    <Link className="btn btn-sm btn-outline-secondary" to="/screeners">Open Screeners</Link>
                </div>
            </div>
        );
    }

    const stats = screener.last_run?.stats;
    const issue = screener.watchlist_issue || screener.index_issue;
    const scope = screener.scope === 'all_equities' ? 'All equities' : (screener.scope || '—');

    return (
        <section className="card mb-3" aria-label="Default screener">
            <div className="card-body py-3">
                <div className="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span className="text-muted small text-uppercase">Default screener</span>
                            <span className={`badge ${screener.is_enabled ? 'text-bg-success' : 'text-bg-secondary'}`}>
                                {screener.is_enabled ? 'Enabled' : 'Off'}
                            </span>
                        </div>
                        <h2 className="h5 mb-1">{screener.name}</h2>
                        <p className="small text-muted mb-2">{screener.description || screener.summary || 'Factory eligibility screen for Discovery.'}</p>
                        <div className="d-flex flex-wrap gap-3 small">
                            <span><span className="text-muted">Scope:</span> {scope}</span>
                            <span>
                                <span className="text-muted">Latest run:</span>
                                {' '}
                                {stats ? `${stats.matched ?? 0} matched / ${stats.scanned ?? 0} scanned` : 'Not run yet'}
                            </span>
                            {screener.last_run?.status && <span><span className="text-muted">Status:</span> {screener.last_run.status}</span>}
                        </div>
                        {issue && <div className="text-danger small mt-2">{issue}</div>}
                    </div>
                    <div className="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-primary"
                            disabled={running || !screener.is_enabled || Boolean(issue)}
                            onClick={onRun}
                        >
                            {running ? 'Running screener…' : 'Run default screener'}
                        </button>
                        <Link className="btn btn-sm btn-outline-secondary" to={`/screeners/${screener.id}`}>View or edit</Link>
                    </div>
                </div>
            </div>
        </section>
    );
}

function EvaluationHistory({ runs, loading, selectedRunId, onSelect, results, resultsLoading }) {
    return (
        <section className="card mb-3" aria-label="Evaluation history">
            <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span className="fw-semibold">Evaluation history</span>
                    <span className="small text-muted ms-2">Most recent 20 runs</span>
                </div>
                <select
                    className="form-select form-select-sm"
                    style={{ maxWidth: 360 }}
                    aria-label="Evaluation run"
                    value={selectedRunId || ''}
                    onChange={(event) => onSelect(event.target.value ? Number(event.target.value) : null)}
                    disabled={loading || runs.length === 0}
                >
                    <option value="">{loading ? 'Loading runs…' : 'Choose a run'}</option>
                    {runs.map((run, index) => (
                        <option key={run.id} value={run.id}>
                            {index === 0 ? 'Latest · ' : ''}Run #{run.id} · {run.status} · {run.result_count ?? run.stats?.evaluated ?? 0} results
                        </option>
                    ))}
                </select>
            </div>
            {runs.length === 0 && !loading ? (
                <div className="card-body small text-muted">No evaluation runs have been recorded yet.</div>
            ) : selectedRunId ? (
                <div className="table-responsive">
                    <table className="table table-sm align-middle mb-0">
                        <thead><tr><th>Rank</th><th>Symbol</th><th>Score</th><th>Confidence</th><th>Explanation</th></tr></thead>
                        <tbody>
                            {resultsLoading ? <tr><td colSpan="5" className="text-muted">Loading run…</td></tr> : results.length === 0 ? (
                                <tr><td colSpan="5" className="text-muted">This run has no results.</td></tr>
                            ) : results.map((row) => (
                                <tr key={row.id}>
                                    <td>{row.rank ?? '—'}</td>
                                    <td><strong>{row.symbol || '—'}</strong></td>
                                    <td>{formatScore(row.score)}</td>
                                    <td>{formatPct(row.confidence)}</td>
                                    <td className="small text-muted">{row.explanation || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : <div className="card-body small text-muted">Choose a run to inspect its ranked results.</div>}
        </section>
    );
}

/**
 * Pattern matches as sketches (name on hover); other signals as compact text badges.
 */
function DiscoveryReasonCell({ candidate }) {
    const signals = useMemo(() => {
        const raw = candidate?.evidence?.signals || candidate?.evidence?.patterns || [];
        if (!Array.isArray(raw) || raw.length === 0) {
            return [];
        }
        const seen = new Set();
        const out = [];
        for (const signal of raw) {
            const id = signal?.id;
            if (!id || seen.has(id)) continue;
            seen.add(id);
            out.push(signal);
        }
        return out;
    }, [candidate]);

    if (signals.length === 0) {
        return <span className="small text-muted">{candidate?.discovery_reason || '—'}</span>;
    }

    return (
        <div className="lido-discovery-reason d-flex flex-wrap align-items-center gap-1">
            {signals.map((signal) => {
                const meta = PATTERN_BY_ID[signal.id];
                if (meta) {
                    const name = signal.label || meta.name || signal.id;
                    const tip = [name, categoryLabel(signal.category || meta.category)].filter(Boolean).join(' · ');
                    return (
                        <Link
                            key={signal.id}
                            to={patternGuideLink(signal.id)}
                            className="lido-watchlist-pattern-link"
                            title={tip}
                            aria-label={tip}
                        >
                            <PatternSketch
                                patternId={signal.id}
                                className="lido-pattern-sketch--watchlist"
                                title=""
                            />
                        </Link>
                    );
                }

                const label = signal.label || signal.id;
                return (
                    <span
                        key={signal.id}
                        className="badge text-bg-light border"
                        title={label}
                    >
                        {label}
                    </span>
                );
            })}
        </div>
    );
}

export default function CandidatesPage() {
    const [source, setSource] = useState('');
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(null);
    const [detailMode, setDetailMode] = useState('evidence'); // evidence | evaluation
    const [runningDiscovery, setRunningDiscovery] = useState(false);
    const [runningEval, setRunningEval] = useState(false);
    const [runningScreener, setRunningScreener] = useState(false);
    const [selectedRunId, setSelectedRunId] = useState(null);

    const { data, loading, reload: load } = useApiGet({
        deps: [source, search],
        errorFallback: 'Failed to load candidates',
        request: async () => {
            const params = {};
            if (source) params.source = source;
            if (search.trim()) params.search = search.trim();
            const response = await api.get('/v1/candidates', { params, skipErrorToast: true });
            return tosList(response);
        },
    });
    const items = Array.isArray(data) ? data : [];

    const {
        data: screenerData,
        loading: screenerLoading,
        reload: loadScreeners,
    } = useApiGet({
        errorFallback: 'Failed to load the default screener',
        request: async () => {
            const response = await api.get('/screeners', { skipErrorToast: true });
            return response.data?.data ?? [];
        },
    });
    const screeners = Array.isArray(screenerData) ? screenerData : [];
    const defaultScreener = screeners.find((row) => row.factory_key === 'minervini_trend_template')
        || screeners.find((row) => row.is_factory)
        || null;

    const { data: runData, loading: runLoading, reload: loadRuns } = useApiGet({
        errorFallback: 'Failed to load evaluation history',
        request: async () => {
            const response = await api.get('/v1/evaluation/runs', { params: { limit: 20 }, skipErrorToast: true });
            return tosList(response);
        },
    });
    const evaluationRuns = Array.isArray(runData) ? runData : [];
    const { data: historyData, loading: historyLoading } = useApiGet({
        deps: [selectedRunId],
        enabled: Boolean(selectedRunId),
        errorFallback: 'Failed to load evaluation run',
        request: async () => {
            const response = await api.get('/v1/evaluations', {
                params: { evaluation_run_id: selectedRunId },
                skipErrorToast: true,
            });
            return tosList(response);
        },
    });
    const historyResults = Array.isArray(historyData) ? historyData : [];

    const sources = useMemo(() => {
        const set = new Set(items.map((i) => i.source).filter(Boolean));
        return Array.from(set).sort();
    }, [items]);

    const busy = runningDiscovery || runningEval || runningScreener;

    const runDefaultScreener = async () => {
        if (!defaultScreener) return;
        setRunningScreener(true);
        try {
            let response = await api.post(`/screeners/${defaultScreener.id}/run`);
            let run = response.data?.data;
            let guard = 0;
            while (response.data?.continued && run?.id && guard < 500) {
                guard += 1;
                response = await api.post(`/screener-runs/${run.id}/continue`);
                run = response.data?.data;
            }
            const matched = run?.stats?.matched ?? 0;
            const scanned = run?.stats?.scanned ?? 0;
            showToast(`Default screener finished: ${matched} match(es) / ${scanned} scanned. Run discovery to refresh candidates.`, 'success');
            await loadScreeners();
        } catch (error) {
            showToast(error?.response?.data?.message || error.message || 'Default screener failed', 'danger');
        } finally {
            setRunningScreener(false);
        }
    };

    const runDiscovery = async () => {
        setRunningDiscovery(true);
        try {
            await api.post('/v1/discovery/runs', null, { skipErrorToast: true });
            showToast('Discovery run completed', 'success');
            try {
                await api.post('/v1/evaluation/runs', null, { skipErrorToast: true });
                showToast('Evaluation completed', 'success');
            } catch (evalErr) {
                showToast(
                    evalErr?.response?.data?.error?.message
                        || evalErr?.response?.data?.message
                        || evalErr.message
                        || 'Discovery done; evaluation failed',
                    'warning',
                );
            }
            await Promise.all([load(), loadRuns()]);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Discovery failed', 'danger');
        } finally {
            setRunningDiscovery(false);
        }
    };

    const runEvaluation = async () => {
        setRunningEval(true);
        try {
            const { ok } = await runApiMutation(async () => {
                await api.post('/v1/evaluation/runs', null, { skipErrorToast: true });
            }, { successMessage: 'Evaluation completed', errorFallback: 'Evaluation failed' });
            if (ok) {
                await Promise.all([load(), loadRuns()]);
            }
        } finally {
            setRunningEval(false);
        }
    };

    const openDetails = (row, mode) => {
        setSelected(row);
        setDetailMode(mode);
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Discovery</h1>
                    <p className="text-muted small mb-1">
                        Which stocks deserve attention today? Screeners, candidates, and breakouts —
                        not portfolio statistics.
                    </p>
                    <p className="text-muted small mb-0">
                        Evaluation and informational scoring on this page are
                        {' '}
                        <strong>long-focused</strong>
                        {' '}
                        (trend / momentum / relative strength favour longs). Bearish screener hits are still listed,
                        but scores are not rewritten for a sell viewpoint — see Documentation for how Discovery and
                        Evaluation relate.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to="/screeners">Screeners</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/watchlist">Research on Watchlist</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading || busy}>Refresh</button>
                    <button type="button" className="btn btn-outline-primary btn-sm" onClick={runEvaluation} disabled={busy}>
                        {runningEval ? 'Evaluating…' : 'Run evaluation'}
                    </button>
                    <button type="button" className="btn btn-primary btn-sm" onClick={runDiscovery} disabled={busy}>
                        {runningDiscovery ? 'Running…' : 'Run discovery'}
                    </button>
                </div>
            </div>

            <DefaultScreenerCard
                screener={defaultScreener}
                loading={screenerLoading}
                running={runningScreener}
                onRun={runDefaultScreener}
            />

            <div className="row g-2 mb-3">
                <div className="col-md-4 col-lg-3">
                    <div className="card h-100">
                        <div className="card-body py-2">
                            <div className="text-muted small">Candidates</div>
                            <div className="fw-semibold">{loading ? '…' : items.length}</div>
                        </div>
                    </div>
                </div>
                <div className="col-md-4 col-lg-3">
                    <div className="card h-100">
                        <div className="card-body py-2">
                            <div className="text-muted small">Screener hits</div>
                            <div className="fw-semibold">{loading ? '…' : items.filter((i) => i.source === 'screener').length}</div>
                        </div>
                    </div>
                </div>
                <div className="col-md-4 col-lg-3">
                    <div className="card h-100">
                        <div className="card-body py-2">
                            <div className="text-muted small">Evaluated</div>
                            <div className="fw-semibold">{loading ? '…' : items.filter((i) => i.evaluation_result_id).length}</div>
                        </div>
                    </div>
                </div>
            </div>

            <EvaluationHistory
                runs={evaluationRuns}
                loading={runLoading}
                selectedRunId={selectedRunId}
                onSelect={setSelectedRunId}
                results={historyResults}
                resultsLoading={historyLoading}
            />

            <div className="row g-2 mb-3">
                <div className="col-md-4">
                    <input
                        className="form-control form-control-sm"
                        placeholder="Search symbol or name"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="col-md-3">
                    <select className="form-select form-select-sm" value={source} onChange={(e) => setSource(e.target.value)}>
                        <option value="">All sources</option>
                        {sources.map((s) => <option key={s} value={s}>{s}</option>)}
                        {!sources.includes('pattern') && <option value="pattern">pattern</option>}
                        {!sources.includes('screener') && <option value="screener">screener</option>}
                        {!sources.includes('holding') && <option value="holding">holding</option>}
                        {!sources.includes('watchlist') && <option value="watchlist">watchlist</option>}
                    </select>
                </div>
            </div>

            {loading ? <p className="text-muted">Loading…</p> : items.length === 0 ? (
                <div className="border rounded p-4 text-muted">
                    No candidates yet. Run discovery (evaluation follows automatically), or run evaluation after an earlier discovery run.
                </div>
            ) : (
                <div className="table-responsive">
                    <table className="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Symbol</th>
                                <th>Source</th>
                                <th>Discovery reason</th>
                                <th>Score</th>
                                <th>Confidence</th>
                                <th>Explanation</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((c) => (
                                <tr key={c.id}>
                                    <td className="text-muted">{c.rank ?? '—'}</td>
                                    <td>
                                        <strong>{c.symbol}</strong>
                                        {c.name ? <div className="small text-muted">{c.name}</div> : null}
                                    </td>
                                    <td><span className="badge text-bg-light">{c.source}</span></td>
                                    <td><DiscoveryReasonCell candidate={c} /></td>
                                    <td>{formatScore(c.score)}</td>
                                    <td>{formatPct(c.confidence)}</td>
                                    <td className="small text-muted" style={{ maxWidth: 320 }}>{c.explanation || '—'}</td>
                                    <td className="text-nowrap">
                                        <button type="button" className="btn btn-link btn-sm px-0 me-2" onClick={() => openDetails(c, 'evidence')}>
                                            Evidence
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-link btn-sm px-0"
                                            onClick={() => openDetails(c, 'evaluation')}
                                            disabled={!c.evaluation_result_id}
                                        >
                                            Factors
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
                                    {selected.symbol}
                                    {' '}
                                    —
                                    {' '}
                                    {detailMode === 'evaluation' ? 'evaluation factors' : 'discovery evidence'}
                                </h5>
                                <button type="button" className="btn-close" onClick={() => setSelected(null)} aria-label="Close" />
                            </div>
                            <div className="modal-body">
                                {detailMode === 'evaluation' ? (
                                    <>
                                        <p className="small">
                                            Score
                                            {' '}
                                            <strong>{formatScore(selected.score)}</strong>
                                            {' · '}
                                            Confidence
                                            {' '}
                                            <strong>{formatPct(selected.confidence)}</strong>
                                            {selected.rank != null ? (
                                                <>
                                                    {' · '}
                                                    Rank
                                                    {' '}
                                                    <strong>{selected.rank}</strong>
                                                </>
                                            ) : null}
                                        </p>
                                        <p className="small text-muted">{selected.explanation || '—'}</p>
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
                                    </>
                                ) : (
                                    <pre className="small bg-body-tertiary p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                        {JSON.stringify(selected.evidence || {}, null, 2)}
                                    </pre>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
