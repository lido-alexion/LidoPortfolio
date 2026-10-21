import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
import { PORTFOLIO_CHANGED } from '../utils/portfolioEvents';
import { showToast } from '../toast';
import RecommendationCapitalResolution from '../components/RecommendationCapitalResolution';
import RecommendationLenderActions from '../components/RecommendationLenderActions';
import DataState from '../components/DataState';
import { exitAttributionLabel } from '../utils/exitAttributionLabels';
import {
    capitalAllocationStatusBadgeClass,
    capitalAllocationStatusLabel,
    canSelectLenderForStatus,
    capitalRequestIdFromRecommendation,
    showsCapitalAllocationStatus,
} from '../utils/capitalRecallUi';

const ACTIONABLE = new Set(['OPEN_POSITION', 'INCREASE_POSITION', 'REDUCE_POSITION', 'EXIT_POSITION', 'BUY', 'SELL']);

function typeBadgeClass(action) {
    switch (String(action || '').toUpperCase()) {
        case 'OPEN_POSITION':
        case 'INCREASE_POSITION':
        case 'BUY':
            return 'text-bg-success';
        case 'REDUCE_POSITION':
        case 'EXIT_POSITION':
        case 'SELL':
            return 'text-bg-danger';
        case 'HOLD_POSITION':
        case 'HOLD':
            return 'text-bg-secondary';
        case 'WATCH':
            return 'text-bg-warning';
        default:
            return 'text-bg-light';
    }
}

function statusBadge(status) {
    switch (status) {
        case 'pending_execution':
        case 'accepted':
            return 'text-bg-success';
        case 'executed':
            return 'text-bg-primary';
        case 'cancelled':
        case 'expired':
            return 'text-bg-secondary';
        case 'rejected': return 'text-bg-danger';
        case 'deferred': return 'text-bg-warning';
        case 'pending_review': return 'text-bg-info';
        case 'published': return 'text-bg-secondary';
        default: return 'text-bg-secondary';
    }
}

function formatPct(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Math.round(Number(v) * 100)}%`;
}

function formatAlloc(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Number(v).toFixed(2)}%`;
}

function formatExecutionDate(value) {
    if (!value) return '—';
    return String(value).slice(0, 10);
}

function recommendationAction(r) {
    return String(r.portfolio_action || r.recommendation_type || '').toUpperCase();
}

function isHoldRec(r) {
    return ['HOLD', 'HOLD_POSITION'].includes(recommendationAction(r));
}

function allocationLabel(r) {
    const action = recommendationAction(r);
    const current = Number(r.current_allocation_pct);
    const suggested = r.suggested_allocation_pct ?? r.target_allocation_pct;
    if (action === 'WATCH' && (!Number.isFinite(current) || current === 0)
        && (suggested == null || Number(suggested) === 0)) {
        return 'No position → Watch';
    }
    if (isHoldRec(r)) {
        return Number.isFinite(current) && current > 0
            ? `${formatAlloc(current)} · Hold`
            : 'Hold existing position';
    }
    return `${formatAlloc(r.current_allocation_pct)} → ${formatAlloc(suggested)}`;
}

function isActionableRec(r) {
    const action = String(r.portfolio_action || r.recommendation_type || '').toUpperCase();
    return r.category === 'actionable' || ACTIONABLE.has(action);
}

function displayStatus(r) {
    return !isActionableRec(r) && r.status === 'published' ? 'informational' : r.status;
}

function displayLabel(r) {
    return r.ui_label || r.recommendation_type || '—';
}

function CapitalStatusCell({ r, expandedId, onToggleLender, onLenderChanged, canInteract }) {
    if (!showsCapitalAllocationStatus(r)) {
        return <span className="text-muted">—</span>;
    }
    const status = r.capital_allocation_status
        || r.evidence?.capital_allocation?.status
        || null;
    const label = capitalAllocationStatusLabel(status);
    if (!label) {
        return <span className="text-muted">—</span>;
    }
    const requestId = capitalRequestIdFromRecommendation(r);
    const canLender = Boolean(requestId) && canSelectLenderForStatus(status);
    return (
        <div className="d-flex flex-column gap-1 align-items-start">
            <span className={`badge ${capitalAllocationStatusBadgeClass(status)}`}>{label}</span>
            {canLender ? (
                <button
                    type="button"
                    className="btn btn-link btn-sm px-0 py-0"
                    onClick={() => { if (canInteract()) onToggleLender(r.id); }}
                >
                    {expandedId === r.id ? 'Hide lenders' : 'Select lender'}
                </button>
            ) : null}
            {expandedId === r.id && canLender ? (
                <div
                    className="w-100"
                    style={{ minWidth: '16rem' }}
                    onClickCapture={(event) => {
                        if (!canInteract()) { event.preventDefault(); event.stopPropagation(); }
                    }}
                >
                    <RecommendationLenderActions
                        recommendation={r}
                        onChanged={async () => {
                            if (typeof onLenderChanged === 'function') await onLenderChanged(r.id);
                        }}
                    />
                </div>
            ) : null}
        </div>
    );
}

function RecTable({ rows, actionLabel, onOpen, expandedLenderId, onToggleLender, onLenderChanged, canInteract }) {
    if (rows.length === 0) {
        return <p className="text-muted small mb-0">None.</p>;
    }

    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Symbol</th>
                        <th>Strategy</th>
                        <th>Opinion</th>
                        <th>Status</th>
                        <th>Capital</th>
                        <th>Alloc</th>
                        <th>Confidence</th>
                        <th>Generated</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.id}>
                            <td>
                                <span className={`badge ${typeBadgeClass(r.portfolio_action || r.recommendation_type)}`}>
                                    {displayLabel(r)}
                                </span>
                            </td>
                            <td>
                                <strong>{r.symbol}</strong>
                                {r.name ? <div className="small text-muted">{r.name}</div> : null}
                            </td>
                            <td className="small">{r.strategy_name || 'Legacy / unassigned'}</td>
                            <td className="small">
                                {r.market_opinion?.direction || '—'}
                                {r.market_opinion?.strength ? (
                                    <div className="text-muted">{r.market_opinion.strength}</div>
                                ) : null}
                            </td>
                            <td><span className={`badge ${statusBadge(r.status)}`}>{displayStatus(r)}</span></td>
                            <td className="small">
                                <CapitalStatusCell
                                    r={r}
                                    expandedId={expandedLenderId}
                                    onToggleLender={onToggleLender}
                                    onLenderChanged={onLenderChanged}
                                    canInteract={canInteract}
                                />
                            </td>
                            <td className="small">{allocationLabel(r)}</td>
                            <td>{formatPct(r.confidence)}</td>
                            <td className="small">{r.generated_at ? new Date(r.generated_at).toLocaleString() : '—'}</td>
                            <td>
                                <button type="button" className="btn btn-link btn-sm px-0" onClick={() => { if (canInteract()) onOpen(r.id); }}>
                                    {actionLabel}
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function RecommendationsPage() {
    const { activePortfolio, activePortfolioId } = usePortfolio();
    const portfolioId = activePortfolioId == null ? null : String(activePortfolioId);
    const portfolioConfirmed = Boolean(activePortfolio && String(activePortfolio.id) === portfolioId);
    const contextRef = useRef({ portfolioId: null, portfolioConfirmed: false });
    const committedScope = useRef(null);
    const ownershipEpoch = useRef(0);
    const listSequence = useRef(0);
    const detailSequence = useRef(0);
    const mounted = useRef(false);
    const operationSequence = useRef(0);
    const operationOwner = useRef(null);
    const pipelineOwner = useRef(null);
    const [recs, setRecs] = useState([]);
    const [running, setRunning] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [pipelineMeta, setPipelineMeta] = useState(null);
    const [selected, setSelected] = useState(null);
    const [notes, setNotes] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [showAll, setShowAll] = useState(false);
    const [showHold, setShowHold] = useState(false);
    const [resultMeta, setResultMeta] = useState(null);
    const [expandedLenderId, setExpandedLenderId] = useState(null);
    const [loadedPortfolioId, setLoadedPortfolioId] = useState(null);
    const committedPortfolioId = contextRef.current.portfolioId;
    const currentOwner = useCallback((id, epoch) => mounted.current
        && epoch === ownershipEpoch.current
        && id != null
        && contextRef.current.portfolioConfirmed
        && contextRef.current.portfolioId === id
        && String(getActivePortfolioId() ?? '') === id, []);
    const beginOperation = (id, epoch, busyKey = null, channel = 'detail') => {
        const operation = { id: ++operationSequence.current, portfolioId: id, epoch, channel };
        const owner = channel === 'pipeline' ? pipelineOwner : operationOwner;
        owner.current = operation;
        if (busyKey !== null) setBusyId(busyKey);
        return operation;
    };
    const operationOwns = (operation) => currentOwner(operation?.portfolioId, operation?.epoch)
        && (operation?.channel === 'pipeline' ? pipelineOwner : operationOwner).current?.id === operation?.id;
    const finishOperation = (operation) => {
        if (!operationOwns(operation)) return;
        const owner = operation.channel === 'pipeline' ? pipelineOwner : operationOwner;
        owner.current = null;
        if (operation.channel === 'pipeline') setRunning(false);
        else setBusyId(null);
    };
    const invalidateOperation = () => {
        operationSequence.current += 1;
        operationOwner.current = null;
        setBusyId(null);
    };
    const runOwnedMutation = async (operation, fn, { successMessage, errorFallback = 'Request failed' } = {}) => {
        try {
            const data = await fn();
            if (operationOwns(operation) && successMessage) showToast(successMessage, 'success');
            return { ok: true, data };
        } catch (e) {
            if (operationOwns(operation) && !e?.stalePortfolio) showToast(getApiErrorMessage(e, errorFallback), 'danger');
            return { ok: false, error: e };
        }
    };
    const resetScope = useCallback(() => {
        ownershipEpoch.current += 1;
        operationSequence.current += 1;
        operationOwner.current = null;
        pipelineOwner.current = null;
        listSequence.current += 1;
        detailSequence.current += 1;
        setRecs([]); setResultMeta(null); setPipelineMeta(null); setSelected(null);
        setNotes(''); setExpandedLenderId(null); setBusyId(null); setRunning(false);
        setError(null); setLoadedPortfolioId(null);
        setLoading(Boolean(contextRef.current.portfolioId) && contextRef.current.portfolioConfirmed);
    }, []);
    const load = useCallback(async () => {
        const id = contextRef.current.portfolioId;
        if (!id || !contextRef.current.portfolioConfirmed || String(getActivePortfolioId() ?? '') !== id) {
            return null;
        }
        const epoch = ownershipEpoch.current;
        const sequence = ++listSequence.current;
        setLoading(true); setError(null);
        try {
            const { data } = await api.get('/v1/recommendations', {
                params: showAll ? { all: 1 } : { open: 1 }, skipErrorToast: true,
            });
            if (!currentOwner(id, epoch) || sequence !== listSequence.current) return null;
            const list = Array.isArray(data?.data) ? data.data : [];
            setRecs(list); setResultMeta(data?.meta || null); setLoadedPortfolioId(id);
            return list;
        } catch (e) {
            if (currentOwner(id, epoch) && sequence === listSequence.current) {
                setError(e);
                showToast(getApiErrorMessage(e, 'Failed to load recommendations'), 'danger');
            }
            return null;
        } finally {
            if (currentOwner(id, epoch) && sequence === listSequence.current) setLoading(false);
        }
    }, [currentOwner, showAll]);
    const loadRef = useRef(load);
    useLayoutEffect(() => { loadRef.current = load; }, [load]);
    useLayoutEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            ownershipEpoch.current += 1;
            operationSequence.current += 1;
            operationOwner.current = null;
            pipelineOwner.current = null;
            committedScope.current = null;
            listSequence.current += 1;
            detailSequence.current += 1;
        };
    }, []);
    useLayoutEffect(() => {
        contextRef.current = { portfolioId, portfolioConfirmed };
        const scopeKey = portfolioConfirmed ? portfolioId : null;
        if (committedScope.current !== scopeKey) {
            committedScope.current = scopeKey;
            resetScope();
            if (scopeKey && String(getActivePortfolioId() ?? '') === scopeKey) loadRef.current();
        }
    }, [portfolioId, portfolioConfirmed, resetScope]);
    useLayoutEffect(() => {
        const onPortfolioChanged = () => {
            resetScope();
            const id = contextRef.current.portfolioId;
            if (id && contextRef.current.portfolioConfirmed && String(getActivePortfolioId() ?? '') === id) loadRef.current();
        };
        window.addEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
        return () => window.removeEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
    }, [resetScope]);
    const lastShowAll = useRef(showAll);
    useEffect(() => {
        if (lastShowAll.current === showAll) return;
        lastShowAll.current = showAll;
        if (portfolioConfirmed) loadRef.current();
    }, [showAll, portfolioConfirmed]);

    const refreshAfterLenderChange = async (id, ownerId, epoch, detailToken, expectedOperationId) => {
        if (!currentOwner(ownerId, epoch) || detailSequence.current !== detailToken
            || (operationOwner.current?.id ?? null) !== expectedOperationId) return;
        const operation = beginOperation(ownerId, epoch, `lender:${id}`);
        try {
            await loadRef.current();
            if (!operationOwns(operation) || detailSequence.current !== detailToken) return;
            const requestSequence = ++detailSequence.current;
            const { data } = await api.get(`/v1/recommendations/${id}`, { skipErrorToast: true });
            if (operationOwns(operation) && detailSequence.current === requestSequence) {
                setSelected(data?.data || null);
                setNotes('');
                setExpandedLenderId(null);
            }
        } catch (e) {
            if (operationOwns(operation)) showToast(getApiErrorMessage(e, 'Failed to load detail'), 'danger');
        } finally {
            finishOperation(operation);
        }
    };

    const visibleOwner = portfolioConfirmed && portfolioId === committedPortfolioId
        && currentOwner(committedPortfolioId, ownershipEpoch.current);
    const scopedRecs = visibleOwner && loadedPortfolioId === committedPortfolioId ? recs : [];
    const tradeRecs = useMemo(() => scopedRecs.filter(isActionableRec), [scopedRecs]);
    const allInsights = useMemo(() => scopedRecs.filter((r) => !isActionableRec(r)), [scopedRecs]);
    const hiddenHoldCount = useMemo(() => allInsights.filter(isHoldRec).length, [allInsights]);
    const insights = useMemo(
        () => allInsights.filter((r) => showHold || !isHoldRec(r)),
        [allInsights, showHold],
    );
    const generatedDates = useMemo(() => scopedRecs
        .map((r) => r.generated_at && new Date(r.generated_at))
        .filter((date) => date && !Number.isNaN(date.getTime())), [scopedRecs]);

    const openDetail = async (id) => {
        const ownerId = contextRef.current.portfolioId;
        const epoch = ownershipEpoch.current;
        if (!ownerId || !currentOwner(ownerId, epoch)) return;
        invalidateOperation();
        const sequence = ++detailSequence.current;
        try {
            const { data } = await api.get(`/v1/recommendations/${id}`, { skipErrorToast: true });
            if (currentOwner(ownerId, epoch) && sequence === detailSequence.current) {
                setSelected(data?.data || null); setNotes('');
            }
        } catch (e) {
            if (currentOwner(ownerId, epoch) && sequence === detailSequence.current) showToast(getApiErrorMessage(e, 'Failed to load detail'), 'danger');
        }
    };

    const closeDetail = () => {
        detailSequence.current += 1;
        invalidateOperation();
        setSelected(null);
        setNotes('');
    };

    const runPipeline = async () => {
        const ownerId = contextRef.current.portfolioId;
        const epoch = ownershipEpoch.current;
        if (!ownerId || !currentOwner(ownerId, epoch)) return;
        if (pipelineOwner.current && operationOwns(pipelineOwner.current)) return;
        const operation = beginOperation(ownerId, epoch, null, 'pipeline');
        setRunning(true);
        setPipelineMeta(null);
        try {
            const { ok, data: stages } = await runOwnedMutation(operation, async () => {
                if (!operationOwns(operation)) return null;
                const { data } = await api.post('/v1/pipeline/run', null, {
                    params: { notify: 1, review: 1 },
                    skipErrorToast: true,
                });
                return data?.data?.stages || null;
            }, {
                successMessage: 'Decision pipeline completed',
                errorFallback: 'Pipeline failed',
            });
            if (ok && operationOwns(operation)) {
                setPipelineMeta(stages);
                await load();
            }
        } finally {
            finishOperation(operation);
        }
    };

    const reopen = async () => {
        if (!selected) return;
        const ownerId = contextRef.current.portfolioId;
        const epoch = ownershipEpoch.current;
        if (!ownerId || !currentOwner(ownerId, epoch)) return;
        const detailToken = detailSequence.current;
        const operation = beginOperation(ownerId, epoch, selected.id);
        const id = selected.id;
        try {
            await runOwnedMutation(operation, async () => {
                if (!operationOwns(operation) || detailSequence.current !== detailToken) return;
                await api.post(`/v1/recommendations/${id}/reopen`, {
                    notes: notes || null,
                }, { skipErrorToast: true });
                if (!operationOwns(operation) || detailSequence.current !== detailToken) return;
                await load();
                if (!operationOwns(operation) || detailSequence.current !== detailToken) return;
                const requestSequence = ++detailSequence.current;
                const { data } = await api.get(`/v1/recommendations/${id}`, { skipErrorToast: true });
                if (operationOwns(operation) && detailSequence.current === requestSequence) setSelected(data?.data || null);
            }, {
                successMessage: 'Reopened for review',
                errorFallback: 'Reopen failed',
            });
        } finally {
            finishOperation(operation);
        }
    };

    const decide = async (decision) => {
        if (!selected) return;
        const ownerId = contextRef.current.portfolioId;
        const epoch = ownershipEpoch.current;
        if (!ownerId || !currentOwner(ownerId, epoch)) return;
        const detailToken = detailSequence.current;
        const operation = beginOperation(ownerId, epoch, selected.id);
        const recId = selected.id;
        try {
            const { ok } = await runOwnedMutation(operation, async () => {
                if (!operationOwns(operation) || detailSequence.current !== detailToken) return;
                await api.post(`/v1/recommendations/${recId}/review`, {
                    decision,
                    notes: notes || null,
                }, { skipErrorToast: true });
                if (!operationOwns(operation) || detailSequence.current !== detailToken) return;
                await load();
                if (operationOwns(operation) && detailSequence.current === detailToken) setSelected(null);
            }, {
                successMessage: `Marked ${decision === 'approved' || decision === 'accepted' ? 'approved for execution' : decision}`,
                errorFallback: 'Review failed',
            });
            if (ok && operationOwns(operation) && (decision === 'approved' || decision === 'accepted')) {
                showToast('Open Transactions → Pending Execution to record the trade when ready', 'success');
            }
        } finally {
            finishOperation(operation);
        }
    };

    const selectedActionable = selected && isActionableRec(selected);
    const opinion = selected?.market_opinion;
    const plan = selected?.execution_plan;
    const renderedPortfolioId = contextRef.current.portfolioId;
    const renderedEpoch = ownershipEpoch.current;
    const renderedDetailToken = detailSequence.current;
    const renderedOperationId = operationOwner.current?.id ?? null;
    const canInteractInRenderedScope = () => currentOwner(renderedPortfolioId, renderedEpoch);

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Recommendations</h1>
                    <p className="text-muted small mb-0">
                        Market opinion is independent of your book; portfolio decisions respect holdings and target allocation.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2 align-items-center">
                    <div className="form-check form-switch mb-0">
                        <input className="form-check-input" type="checkbox" id="showAllRecs" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} />
                        <label className="form-check-label small" htmlFor="showAllRecs">Include closed history</label>
                    </div>
                    <Link className="btn btn-outline-secondary btn-sm" to="/strategy">Strategy</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={!visibleOwner || loading || running}>Refresh</button>
                    <button type="button" className="btn btn-primary btn-sm" onClick={runPipeline} disabled={!visibleOwner || running}>
                        {running ? 'Running pipeline…' : 'Run decision pipeline'}
                    </button>
                </div>
            </div>

            {visibleOwner && pipelineMeta && (
                <div className="alert alert-info small py-2">
                    Pipeline: discovery candidates {pipelineMeta.discovery?.candidates ?? '—'}
                    {' · '}
                    candidate evaluations {pipelineMeta.evaluation?.results ?? '—'}
                    {' · '}
                    strategy-specific recommendations {pipelineMeta.recommendation?.count ?? '—'}
                </div>
            )}

            {visibleOwner && error ? (
                <DataState
                    variant="unavailable"
                    title="Recommendations unavailable"
                    message="The recommendation list could not be loaded. Existing results remain unchanged."
                    action={<button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={!visibleOwner || loading || running}>Retry</button>}
                    className="mb-3"
                />
            ) : null}

            {visibleOwner && !loading && (
                <p className="small text-muted mb-3">
                    {showAll
                        ? 'All recommendation statuses, newest first.'
                        : 'Current/open statuses only; this view is status-based, not date-based.'}
                    {' Showing '}
                    {scopedRecs.length}
                    {visibleOwner && resultMeta?.total != null ? ` of ${resultMeta.total}` : ''}
                    {generatedDates.length > 0 ? (
                        ` · Generated ${new Date(Math.min(...generatedDates)).toLocaleString()} to ${new Date(Math.max(...generatedDates)).toLocaleString()}`
                    ) : ''}
                </p>
            )}

            {!visibleOwner || loading ? (
                <p className="text-muted">Loading…</p>
            ) : (
                <>
                    <section className="mb-4">
                        <h2 className="h5 mb-1">Trade recommendations</h2>
                        <p className="small text-muted mb-2">Open / Buy More / Sell Partial / Sell All — review before recording a trade.</p>
                        {tradeRecs.length === 0 ? (
                            <div className="border rounded p-3 text-muted small">
                                {pipelineMeta
                                    ? `No actionable trades. This pipeline produced ${pipelineMeta.recommendation?.count ?? 0} informational recommendations.`
                                    : 'No trade recommendations are actionable in the current view. Informational insights may appear below.'}
                            </div>
                        ) : (
                            <RecTable
                                rows={tradeRecs}
                                actionLabel="Review"
                                onOpen={openDetail}
                                expandedLenderId={expandedLenderId}
                                canInteract={canInteractInRenderedScope}
                                onToggleLender={(id) => setExpandedLenderId((cur) => (id == null ? null : (cur === id ? null : id)))}
                                onLenderChanged={(id) => refreshAfterLenderChange(
                                    id, renderedPortfolioId, renderedEpoch, renderedDetailToken, renderedOperationId,
                                )}
                            />
                        )}
                    </section>

                    <section className="mb-2">
                        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                            <h2 className="h5 mb-0">Market insights</h2>
                            <div className="form-check form-switch mb-0">
                                <input
                                    className="form-check-input"
                                    type="checkbox"
                                    id="showHoldInsights"
                                    checked={showHold}
                                    onChange={(e) => setShowHold(e.target.checked)}
                                />
                                <label className="form-check-label small" htmlFor="showHoldInsights">Show HOLD insights</label>
                            </div>
                        </div>
                        <p className="small text-muted mb-2">
                            Watch and optional HOLD records — informational only; no approval or orders.
                            {!showHold && hiddenHoldCount > 0 ? ` ${hiddenHoldCount} HOLD ${hiddenHoldCount === 1 ? 'record is' : 'records are'} hidden.` : ''}
                        </p>
                        {insights.length === 0 ? (
                            <div className="border rounded p-3 text-muted small">No insights right now.</div>
                        ) : (
                            <RecTable
                                rows={insights}
                                actionLabel="View details"
                                onOpen={openDetail}
                                expandedLenderId={null}
                                canInteract={canInteractInRenderedScope}
                                onToggleLender={() => {}}
                            />
                        )}
                    </section>
                </>
            )}

            {visibleOwner && selected && (
                <div className="modal show d-block lido-tos-review-modal" style={{ background: 'rgba(0,0,0,.45)' }} role="dialog" aria-modal="true" aria-labelledby="recommendation-review-dialog-title">
                    <div className="modal-dialog modal-lg modal-dialog-scrollable">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 id="recommendation-review-dialog-title" className="modal-title">
                                    {displayLabel(selected)}
                                    {' '}
                                    {selected.symbol}
                                    {!selectedActionable ? (
                                        <span className="badge text-bg-secondary ms-2">Insight</span>
                                    ) : null}
                                </h5>
                                <button type="button" className="btn-close" onClick={closeDetail} aria-label="Close" />
                            </div>
                            <div className="modal-body">
                                <p className="small text-muted mb-2">
                                    Status
                                    {' '}
                                    <span className={`badge ${statusBadge(selected.status)}`}>{displayStatus(selected)}</span>
                                    {selected.review_status ? (
                                        <span className="badge text-bg-light border ms-1">Review: {selected.review_status}</span>
                                    ) : null}
                                    {selected.execution_status ? (
                                        <span className="badge text-bg-light border ms-1">Execution: {selected.execution_status}</span>
                                    ) : null}
                                    {showsCapitalAllocationStatus(selected) && capitalAllocationStatusLabel(
                                        selected.capital_allocation_status
                                            || selected.evidence?.capital_allocation?.status,
                                    ) ? (
                                        <span
                                            className={`badge ms-1 ${capitalAllocationStatusBadgeClass(
                                                selected.capital_allocation_status
                                                    || selected.evidence?.capital_allocation?.status,
                                            )}`}
                                        >
                                            {capitalAllocationStatusLabel(
                                                selected.capital_allocation_status
                                                    || selected.evidence?.capital_allocation?.status,
                                            )}
                                        </span>
                                    ) : null}
                                    {' · '}
                                    Confidence
                                    {' '}
                                    {formatPct(selected.confidence)}
                                    {' · '}
                                    Ref ₹
                                    {selected.reference_price ?? '—'}
                                </p>

                                {selected.status === 'superseded' ? (
                                    <div className="alert alert-warning py-2 small mb-3">
                                        <p className="mb-1">This recommendation has been superseded and is no longer a current execution instruction.</p>
                                        {selected.superseded_by_id ? (
                                            <button
                                                type="button"
                                                className="btn btn-outline-dark btn-sm"
                                                onClick={() => openDetail(selected.superseded_by_id)}
                                            >
                                                View replacement recommendation
                                            </button>
                                        ) : (
                                            <span>The replacement recommendation is not available.</span>
                                        )}
                                    </div>
                                ) : null}

                                <h6>Market opinion</h6>
                                <p className="small mb-2">
                                    {opinion?.direction || '—'}
                                    {' · '}
                                    {opinion?.strength || '—'}
                                    {' · '}
                                    Confidence
                                    {' '}
                                    {formatPct(opinion?.confidence ?? selected.confidence)}
                                    {' · Score '}
                                    {selected.strategy_score ?? selected.score ?? '—'}
                                    {selected.strategy_name ? ` · ${selected.strategy_name}` : ''}
                                    {selected.strategy_version != null ? ` · Version ${selected.strategy_version}` : ''}
                                </p>
                                {(selected.source_screener_id ?? selected.evidence?.screener_id) != null ? (
                                    <p className="small text-muted mb-2" data-testid="recommendation-provenance">
                                        Source screener #{selected.source_screener_id ?? selected.evidence?.screener_id}
                                        {(selected.source_screener_version ?? selected.evidence?.screener_version) != null
                                            ? ` · Version ${selected.source_screener_version ?? selected.evidence?.screener_version}` : ''}
                                        {selected.strategy_id != null ? ` · Strategy #${selected.strategy_id}` : ''}
                                        {selected.strategy_version_id != null ? ` · Strategy version #${selected.strategy_version_id}` : ''}
                                    </p>
                                ) : null}

                                {(selected.factor_breakdown || selected.evidence?.factor_breakdown)?.length ? (
                                    <>
                                        <h6>Factor breakdown</h6>
                                        <p className="small mb-1">
                                            Overall score
                                            {' '}
                                            <strong>{selected.strategy_score ?? selected.score ?? '—'}</strong>
                                        </p>
                                        <ul className="small mb-3">
                                            {(selected.factor_breakdown || selected.evidence?.factor_breakdown || []).map((row) => (
                                                <li key={row.key}>
                                                    {row.display_name || row.key}
                                                    {': '}
                                                    {Number(row.contribution).toFixed(1)}
                                                    {' / '}
                                                    {Number(row.max_contribution).toFixed(1)}
                                                    {row.gated ? ' (gated)' : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                ) : null}

                                <h6>Portfolio decision</h6>
                                <p className="small mb-1">
                                    <span className={`badge ${typeBadgeClass(selected.portfolio_action || selected.recommendation_type)}`}>
                                        {displayLabel(selected)}
                                    </span>
                                    <span className="text-muted ms-2">{selected.portfolio_action || selected.recommendation_type}</span>
                                </p>
                                <p className="small text-muted mb-2">
                                    Current
                                    {' '}
                                    {formatAlloc(selected.current_allocation_pct)}
                                    {' · Target '}
                                    {formatAlloc(selected.target_allocation_pct)}
                                    {' · Suggested '}
                                    {formatAlloc(selected.suggested_allocation_pct)}
                                </p>
                                {selected.reasoning ? <p className="small">{selected.reasoning}</p> : null}
                                {selected.suggested_quantity != null || selected.suggested_investment_amount != null ? (
                                    <p className="small text-muted">
                                        Suggested execution
                                        {selected.suggested_quantity != null ? ` · ${selected.suggested_quantity} shares` : ''}
                                        {selected.suggested_investment_amount != null ? ` · ₹${selected.suggested_investment_amount}` : ''}
                                    </p>
                                ) : null}

                                {selected.execution_anchor_date ? (
                                    <div className="mb-3">
                                        <h6>Execution progress</h6>
                                        <p className="small mb-1">
                                            Recommendation date {formatExecutionDate(selected.execution_anchor_date)}
                                            {' · First eligible '}
                                            {formatExecutionDate(selected.first_eligible_execution_date)}
                                            {' · Expires '}
                                            {selected.execution_expires_at
                                                ? new Date(selected.execution_expires_at).toLocaleString()
                                                : '—'}
                                        </p>
                                        <p className="small mb-0">
                                            Target ₹{selected.target_amount ?? '—'}
                                            {' · Capital-ready ₹'}{selected.capital_resolved_amount ?? '—'}
                                            {' · Internal ₹'}{selected.internal_executed_amount ?? 0}
                                            {' · External ₹'}{selected.external_executed_amount ?? 0}
                                            {' · Remaining ₹'}{selected.remaining_target_amount ?? '—'}
                                        </p>
                                    </div>
                                ) : null}

                                {(selected.position_target_amount != null
                                    || plan?.position_target_amount != null
                                    || selected.is_first_entry != null
                                    || plan?.is_first_entry != null) ? (
                                    <div className="mb-3">
                                        <h6>Position target (OD-12)</h6>
                                        <p className="small mb-1">
                                            Target ₹
                                            {selected.position_target_amount
                                                ?? plan?.position_target_amount
                                                ?? '—'}
                                            {' · This cycle ₹'}
                                            {selected.this_cycle_amount
                                                ?? plan?.this_cycle_amount
                                                ?? '—'}
                                            {' · Filled ₹'}
                                            {selected.position_filled_amount
                                                ?? plan?.filled_amount
                                                ?? '—'}
                                            {' · Remaining ₹'}
                                            {selected.remaining_target_amount
                                                ?? plan?.remaining_amount
                                                ?? '—'}
                                        </p>
                                        <p className="form-text mb-0">
                                            {(selected.is_first_entry ?? plan?.is_first_entry)
                                                ? 'First entry: about 50% of current target (strategy first_entry_pct).'
                                                : 'Subsequent BUY uses remaining target after the 1-day BUY cooldown.'}
                                            {' '}
                                            Target is not reduced by partial funding or capital-resolution shortfalls.
                                        </p>
                                    </div>
                                ) : null}

                                {(selected.primary_exit_reason
                                    || selected.exit_attribution?.primary_reason
                                    || plan?.primary_exit_reason) ? (
                                    <div className="mb-3">
                                        <h6>Primary exit reason</h6>
                                        <p className="small mb-0">
                                            <span className="badge text-bg-light border">
                                                {exitAttributionLabel(
                                                    selected.primary_exit_reason
                                                        || selected.exit_attribution?.primary_reason
                                                        || plan?.primary_exit_reason,
                                                )}
                                            </span>
                                            <span className="text-muted ms-2">
                                                {selected.primary_exit_reason
                                                    || selected.exit_attribution?.primary_reason
                                                    || plan?.primary_exit_reason}
                                            </span>
                                        </p>
                                        <p className="form-text mb-0 mt-1">
                                            When multiple exit mechanisms are true, only the highest-precedence
                                            reason is shown (Strategy exit → Portfolio Stop-Loss → Portfolio
                                            Trailing Stop → Strategy Horizon).
                                        </p>
                                    </div>
                                ) : null}

                                {plan && (
                                    <>
                                        <h6>Execution plan</h6>
                                        <pre className="small lido-tos-review-pre p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                            {JSON.stringify(plan, null, 2)}
                                        </pre>
                                    </>
                                )}

                                <h6>Evidence</h6>
                                <ul className="small">
                                    {(selected.evidence?.passed_rules || opinion?.evidence?.passed_rules || []).map((x) => (
                                        <li key={`p-${x}`}>✓ {x}</li>
                                    ))}
                                    {(selected.failed_checks || []).map((x) => <li key={`f-${x}`}>✗ {x}</li>)}
                                </ul>
                                <pre className="small lido-tos-review-pre p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                    {JSON.stringify(selected.evidence?.indicators || opinion?.evidence?.indicators || {}, null, 2)}
                                </pre>

                                {selected.can_reopen && selectedActionable && (
                                    <div className="mb-3">
                                        <button
                                            type="button"
                                            className="btn btn-outline-secondary btn-sm"
                                            disabled={busyId === selected.id}
                                            onClick={reopen}
                                        >
                                            Undo decision — reopen for review
                                        </button>
                                        <p className="form-text mb-0 mt-1">
                                            Clears Approve / Reject / Defer / Cancelled and returns to pending review.
                                        </p>
                                    </div>
                                )}

                                {selected.available_cash_at_generation != null && (
                                    <p className="small text-muted mb-2">
                                        Cash at generation — balance
                                        {' '}
                                        {Number(selected.cash_balance_at_generation).toLocaleString()}
                                        {' · reserved '}
                                        {Number(selected.reserved_cash_at_generation).toLocaleString()}
                                        {' · available '}
                                        {Number(selected.available_cash_at_generation).toLocaleString()}
                                        {selected.reserved_amount != null ? ` · reserved now ${Number(selected.reserved_amount).toLocaleString()}` : ''}
                                    </p>
                                )}

                                {selectedActionable ? (
                                    <>
                                        <div onClickCapture={(event) => {
                                            if (!canInteractInRenderedScope()) { event.preventDefault(); event.stopPropagation(); }
                                        }}>
                                            <RecommendationLenderActions
                                                recommendation={selected}
                                                onChanged={() => refreshAfterLenderChange(
                                                    selected.id, renderedPortfolioId, renderedEpoch, renderedDetailToken, renderedOperationId,
                                                )}
                                            />
                                        </div>
                                        <RecommendationCapitalResolution recommendationId={selected.id} />
                                    </>
                                ) : null}

                                {selected.can_execute_manually && selectedActionable && (
                                    <div className="mb-3">
                                        <Link
                                            className="btn btn-primary btn-sm"
                                            to="/transactions/pending"
                                        >
                                            Go to Pending Execution
                                        </Link>
                                        <p className="form-text mb-0 mt-1">
                                            Approval does not create a trade. Record the actual fill on Transactions when ready.
                                        </p>
                                    </div>
                                )}

                                {selected.execution && (
                                    <div className="mb-3 small">
                                        <h6>Execution</h6>
                                        <p className="mb-1">
                                            Transaction #
                                            {selected.execution.transaction_id}
                                            {' · '}
                                            {selected.execution.transaction_date}
                                            {' · qty '}
                                            {selected.execution.quantity}
                                            {' @ '}
                                            {selected.execution.price}
                                        </p>
                                        {selected.execution.exit_reason ? (
                                            <p className="mb-0 text-muted">
                                                Exit reason:
                                                {' '}
                                                {exitAttributionLabel(selected.execution.exit_reason)}
                                                {' '}
                                                (
                                                {selected.execution.exit_reason}
                                                )
                                            </p>
                                        ) : null}
                                    </div>
                                )}

                                {selected.cancellation_reason_label && (
                                    <p className="small text-muted">
                                        Cancelled:
                                        {' '}
                                        {selected.cancellation_reason_label}
                                        {selected.cancelled_at ? ` (${new Date(selected.cancelled_at).toLocaleString()})` : ''}
                                    </p>
                                )}

                                {selected.can_review && selectedActionable && (
                                    <>
                                        <label className="form-label" htmlFor="tos-review-notes">Review notes (optional)</label>
                                        <textarea
                                            id="tos-review-notes"
                                            className="form-control form-control-sm mb-2"
                                            rows={2}
                                            value={notes}
                                            onChange={(e) => setNotes(e.target.value)}
                                        />
                                        <div className="d-flex flex-wrap gap-2 mb-1">
                                            <button type="button" className="btn btn-success btn-sm" disabled={busyId === selected.id} onClick={() => decide('approved')}>Approve</button>
                                            <button type="button" className="btn btn-outline-warning btn-sm" disabled={busyId === selected.id} onClick={() => decide('deferred')}>Defer</button>
                                            <button type="button" className="btn btn-outline-danger btn-sm" disabled={busyId === selected.id} onClick={() => decide('rejected')}>Reject</button>
                                        </div>
                                    </>
                                )}

                                {(selected.reviews || []).length > 0 && (
                                    <>
                                        <h6>Review history</h6>
                                        <ul className="small">
                                            {selected.reviews.map((rev) => (
                                                <li key={rev.id}>
                                                    {rev.decision}
                                                    {' by '}
                                                    {rev.user || 'user'}
                                                    {' at '}
                                                    {rev.created_at ? new Date(rev.created_at).toLocaleString() : ''}
                                                    {rev.notes ? ` — ${rev.notes}` : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </div>
                            <div className="modal-footer">
                                <Link className="btn btn-outline-secondary btn-sm" to="/review">Review dashboard</Link>
                                <button type="button" className="btn btn-primary btn-sm" onClick={closeDetail}>Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
