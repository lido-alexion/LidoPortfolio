import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import AlertPolicyForm from '../components/AlertPolicyForm';
import { usePortfolio } from '../context/PortfolioContext';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
import { showToast } from '../toast';

function validationMessage(error) {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) {
            return first;
        }
    }
    return error?.response?.data?.message || 'Something went wrong. Please try again.';
}

const OUTCOME_LABELS = {
    generated: 'Alert created',
    condition_not_met: 'Condition not met',
    missing_left: 'Missing left value',
    missing_right: 'Missing compare value',
    duplicate_active: 'Already active',
    formula_error: 'Formula error',
    error: 'Error',
};

function outcomeBadgeClass(outcome) {
    switch (outcome) {
    case 'generated':
        return 'text-bg-success';
    case 'duplicate_active':
    case 'missing_left':
    case 'missing_right':
        return 'text-bg-warning';
    case 'formula_error':
    case 'error':
        return 'text-bg-danger';
    default:
        return 'text-bg-secondary';
    }
}

function formatEvalNum(value) {
    if (value === null || value === undefined) {
        return '—';
    }
    const num = Number(value);
    if (Number.isNaN(num)) {
        return String(value);
    }
    return num.toFixed(2);
}

function isValidPolicyMeta(meta) {
    if (!meta || typeof meta !== 'object' || Array.isArray(meta)) {
        return false;
    }
    const optionLists = ['stock_universes', 'condition_operators', 'compare_types', 'action_types'];
    const isOptionalArray = (key, validItem) => meta[key] == null
        || (Array.isArray(meta[key]) && meta[key].every(validItem));
    return isOptionalArray('columns', (column) => column
            && typeof column.key === 'string'
            && typeof column.label === 'string')
        && optionLists.every((key) => isOptionalArray(key, (option) => option
            && typeof option.value === 'string'
            && typeof option.label === 'string'));
}

export default function AlertPoliciesPage() {
    const { activePortfolioId } = usePortfolio();
    const [meta, setMeta] = useState(null);
    const [policies, setPolicies] = useState([]);
    const [loading, setLoading] = useState(true);
    const [evaluating, setEvaluating] = useState(false);
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [deletingId, setDeletingId] = useState(null);
    const [evalReport, setEvalReport] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});
    const [listConfirmed, setListConfirmed] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [waitingForPortfolio, setWaitingForPortfolio] = useState(
        () => getActivePortfolioId() == null,
    );
    const loadGenerationRef = React.useRef(0);
    const scopeRef = React.useRef({ id: getActivePortfolioId() });
    const startupBridgePendingRef = React.useRef(getActivePortfolioId() == null);
    const startupBridgeLoadedByEventRef = React.useRef(null);
    const initialLoadDispatchedRef = React.useRef(false);
    const hasConfirmedScopeRef = React.useRef(getActivePortfolioId() != null);

    const resetScopePresentation = useCallback((scopeId) => {
        scopeRef.current.id = scopeId;
        setPolicies([]);
        setListConfirmed(false);
        setEditing(null);
        setCreating(false);
        setEvalReport(null);
        setFieldErrors({});
        setDeletingId(null);
    }, []);

    const clearFieldError = (key) => {
        setFieldErrors((prev) => {
            if (!prev[key]) {
                return prev;
            }
            const next = { ...prev };
            delete next[key];
            return next;
        });
    };

    const load = useCallback(async () => {
        const scopeId = getActivePortfolioId();
        if (!scopeId) {
            loadGenerationRef.current += 1;
            if (scopeRef.current.id !== null) {
                resetScopePresentation(null);
            }
            setWaitingForPortfolio(true);
            setLoading(false);
            setLoadError(false);
            return;
        }
        const generation = ++loadGenerationRef.current;
        setWaitingForPortfolio(false);
        if (scopeRef.current.id !== scopeId) {
            if (scopeRef.current.id === null && !hasConfirmedScopeRef.current) {
                scopeRef.current.id = scopeId;
                hasConfirmedScopeRef.current = true;
            } else {
                resetScopePresentation(scopeId);
            }
        }
        setLoading(true);
        setLoadError(false);
        try {
            const [metaRes, listRes] = await Promise.all([
                api.get('/alert-policies/meta', { skipErrorToast: true }),
                api.get('/alert-policies', { skipErrorToast: true }),
            ]);
            const nextMeta = metaRes.data?.data;
            const nextPolicies = listRes.data?.data;
            if (!isValidPolicyMeta(nextMeta) || !Array.isArray(nextPolicies)) {
                throw new Error('Alert policy response was invalid.');
            }
            if (generation !== loadGenerationRef.current) {
                return;
            }
            if (getActivePortfolioId() !== scopeId) {
                resetScopePresentation(getActivePortfolioId());
                setLoadError(true);
                setLoading(false);
                return;
            }
            setMeta(nextMeta);
            setPolicies(nextPolicies);
            setListConfirmed(true);
        } catch (error) {
            if (generation !== loadGenerationRef.current) {
                return;
            }
            if (getActivePortfolioId() !== scopeId) {
                resetScopePresentation(getActivePortfolioId());
                setLoadError(true);
                setLoading(false);
                return;
            }
            showToast(validationMessage(error), 'danger');
            setLoadError(true);
        } finally {
            if (generation === loadGenerationRef.current && getActivePortfolioId() === scopeId) {
                setLoading(false);
            }
        }
    }, [resetScopePresentation]);

    useEffect(() => {
        const activeId = activePortfolioId == null ? null : String(activePortfolioId);
        const canonicalId = getActivePortfolioId();

        if (startupBridgePendingRef.current) {
            if (!activeId || canonicalId !== activeId) {
                setWaitingForPortfolio(true);
                setLoading(false);
                return;
            }
            startupBridgePendingRef.current = false;
            setWaitingForPortfolio(false);
        } else if (activeId && startupBridgeLoadedByEventRef.current === activeId) {
            startupBridgeLoadedByEventRef.current = null;
            setWaitingForPortfolio(false);
            return;
        }

        if (!activeId) {
            if (canonicalId) {
                loadGenerationRef.current += 1;
                resetScopePresentation(null);
                setWaitingForPortfolio(true);
                setLoading(false);
                setLoadError(false);
                return;
            }
            if (scopeRef.current.id !== null) {
                load();
            } else {
                setWaitingForPortfolio(true);
                setLoading(false);
            }
            return;
        }

        if (canonicalId !== activeId) {
            loadGenerationRef.current += 1;
            resetScopePresentation(null);
            setWaitingForPortfolio(true);
            setLoading(false);
            setLoadError(false);
            return;
        }

        if (initialLoadDispatchedRef.current) {
            if (scopeRef.current.id !== activeId) {
                loadGenerationRef.current += 1;
                resetScopePresentation(null);
                setLoadError(true);
                setLoading(false);
                setWaitingForPortfolio(false);
            }
            return;
        }

        initialLoadDispatchedRef.current = true;
        load();
    }, [activePortfolioId, load, resetScopePresentation]);

    const handlePortfolioChanged = useCallback(() => {
        const activeId = getActivePortfolioId();
        if (startupBridgePendingRef.current && activeId) {
            startupBridgePendingRef.current = false;
            startupBridgeLoadedByEventRef.current = activeId;
            initialLoadDispatchedRef.current = true;
        }
        load();
    }, [load]);

    usePortfolioChanged(handlePortfolioChanged);

    const runPolicies = async () => {
        setEvaluating(true);
        setEvalReport(null);
        try {
            const res = await api.post('/alert-policies/evaluate');
            setEvalReport(res.data?.data ?? null);
            showToast(res.data?.message || 'Policies evaluated');
            await load();
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setEvaluating(false);
        }
    };

    const savePolicy = async (payload) => {
        setSaving(true);
        setFieldErrors({});
        try {
            if (editing) {
                await api.put(`/alert-policies/${editing.id}`, payload);
                showToast('Policy updated');
            } else {
                await api.post('/alert-policies', payload);
                showToast('Policy created');
            }
            setEditing(null);
            setCreating(false);
            setFieldErrors({});
            await load();
        } catch (error) {
            const errors = error?.response?.data?.errors;
            if (errors) {
                setFieldErrors(errors);
            }
            showToast(validationMessage(error), 'danger');
        } finally {
            setSaving(false);
        }
    };

    const deletePolicy = async (policy) => {
        if (!window.confirm(`Delete policy "${policy.name}"?`)) {
            return;
        }
        setDeletingId(policy.id);
        try {
            await api.delete(`/alert-policies/${policy.id}`);
            showToast('Policy deleted');
            if (editing?.id === policy.id) {
                setEditing(null);
            }
            await load();
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setDeletingId(null);
        }
    };

    const showForm = creating || editing;
    const loadErrorMessage = policies.length > 0
        ? 'Could not refresh alert policies. Showing the last successfully loaded policies; they may be out of date.'
        : 'Alert policies could not be loaded. Retry to check this portfolio’s policies.';

    return (
        <div className="container py-4">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
                <div>
                    <h2 className="h4 mb-1">Alert policies</h2>
                    <p className="text-muted small mb-0">
                        Define rules evaluated against holdings after daily price sync or on demand.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link to="/settings/portfolio" className="btn btn-outline-secondary btn-sm">Back to settings</Link>
                    <button
                        type="button"
                        className="btn btn-outline-primary btn-sm"
                        disabled={evaluating || loading}
                        onClick={runPolicies}
                    >
                        {evaluating ? 'Running…' : 'Run policies now'}
                    </button>
                    {!showForm && (
                        <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            onClick={() => {
                                setCreating(true);
                                setEditing(null);
                            }}
                        >
                            New policy
                        </button>
                    )}
                </div>
            </div>

            {evalReport && (
                <div className="card mb-4">
                    <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span>Last evaluation</span>
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            onClick={() => setEvalReport(null)}
                        >
                            Dismiss
                        </button>
                    </div>
                    <div className="card-body">
                        <p className="small text-muted mb-3">
                            {evalReport.policies ?? 0} policies · {evalReport.holdings_checked ?? 0} holdings checked ·{' '}
                            <span className="text-success">{evalReport.generated ?? 0} generated</span>
                            {' · '}
                            <span>{evalReport.skipped ?? 0} skipped</span>
                            {evalReport.details_truncated && (
                                <span className="ms-1">(first {evalReport.details?.length ?? 0} rows shown)</span>
                            )}
                        </p>
                        {evalReport.details?.length ? (
                            <div className="table-responsive">
                                <table className="table table-sm table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Policy</th>
                                            <th>Symbol</th>
                                            <th>Outcome</th>
                                            <th>Left</th>
                                            <th>Right</th>
                                            <th>Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {evalReport.details.map((row, idx) => (
                                            <tr key={`${row.policy_id}-${row.stock_id}-${idx}`}>
                                                <td className="small">{row.policy_name}</td>
                                                <td>{row.stock_symbol}</td>
                                                <td>
                                                    <span className={`badge ${outcomeBadgeClass(row.outcome)}`}>
                                                        {OUTCOME_LABELS[row.outcome] || row.outcome}
                                                    </span>
                                                </td>
                                                <td className="small text-muted">{formatEvalNum(row.left)}</td>
                                                <td className="small text-muted">{formatEvalNum(row.right)}</td>
                                                <td className="small">{row.summary}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="text-muted small mb-0">
                                No holdings matched any enabled policy (or no policies are enabled).
                            </p>
                        )}
                        <p className="small text-muted mt-3 mb-0">
                            Server logs also record each evaluation under category <code>AlertPolicy</code> (app log channel).
                        </p>
                    </div>
                </div>
            )}

            {showForm && meta && !waitingForPortfolio && (
                <div className="card mb-4">
                    <div className="card-header">
                        {editing ? `Edit: ${editing.name}` : 'New alert policy'}
                    </div>
                    <div className="card-body">
                        <AlertPolicyForm
                            meta={meta}
                            initialPolicy={editing}
                            saving={saving}
                            fieldErrors={fieldErrors}
                            onClearFieldError={clearFieldError}
                            onSubmit={savePolicy}
                            onCancel={() => {
                                setEditing(null);
                                setCreating(false);
                                setFieldErrors({});
                            }}
                        />
                    </div>
                </div>
            )}

            {loadError && (
                <div className="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
                    <span>{loadErrorMessage}</span>
                    <button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={load}>Retry policies</button>
                </div>
            )}

            <div className="card">
                <div className="card-body p-0">
                    {waitingForPortfolio ? (
                        <p className="text-muted p-3 mb-0">Waiting for a selected portfolio to load alert policies…</p>
                    ) : loading ? (
                        <p className="text-muted p-3 mb-0">Loading policies…</p>
                    ) : (
                        <ul className="list-group list-group-flush">
                            {policies.map((policy) => (
                                <li key={policy.id} className="list-group-item">
                                    <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <div>
                                            <div className="fw-semibold">
                                                {policy.name}
                                                {!policy.is_enabled && (
                                                    <span className="badge text-bg-secondary ms-2">Disabled</span>
                                                )}
                                                {policy.is_system && (
                                                    <span className="badge text-bg-info ms-2">System</span>
                                                )}
                                            </div>
                                            <div className="small text-muted">
                                                {policy.alert_definition?.trim()
                                                    || 'No definition provided'}
                                            </div>
                                        </div>
                                        <div className="d-flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                className="btn btn-outline-secondary btn-sm"
                                                onClick={() => {
                                                    setEditing(policy);
                                                    setCreating(false);
                                                }}
                                            >
                                                Edit
                                            </button>
                                            {!policy.is_system && (
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-danger btn-sm"
                                                    disabled={deletingId === policy.id}
                                                    onClick={() => deletePolicy(policy)}
                                                >
                                                    {deletingId === policy.id ? 'Deleting…' : 'Delete'}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                            {!loading && !loadError && listConfirmed && !policies.length && (
                                <li className="list-group-item text-muted">
                                    No alert policies yet. Create one to generate alerts from holdings.
                                </li>
                            )}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
