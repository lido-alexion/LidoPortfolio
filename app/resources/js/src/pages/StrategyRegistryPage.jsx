import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { appUrl } from '../appBase';
import ValidationSuccessBanner from '../components/artifacts/ValidationSuccessBanner';
import NumberInput from '../components/NumberInput';
import { showToast } from '../toast';
import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';

function statusBadgeClass(status) {
    switch (status) {
        case 'active':
            return 'text-bg-success';
        case 'draft':
            return 'text-bg-warning';
        case 'archived':
            return 'text-bg-secondary';
        default:
            return 'text-bg-light';
    }
}

function isEnabledRow(row) {
    const m = row?.metadata || {};
    return Boolean(m.is_enabled || m.is_selected || m.status === 'active');
}

function strategyIdOf(row) {
    const m = row?.metadata || {};
    const id = m.legacy_id ?? row?.artifact_id;
    const n = Number(id);
    return Number.isFinite(n) && n > 0 ? n : null;
}

function allocationPctOf(row) {
    const m = row?.metadata || {};
    if (m.allocation_pct == null || m.allocation_pct === '') return null;
    const n = Number(m.allocation_pct);
    return Number.isFinite(n) ? n : null;
}

function downloadJson(filename, data) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

/**
 * Strategy Artifact Registry — list / select / import / export / validate.
 * @param {{ adminMode?: boolean }} props
 */
export default function StrategyRegistryPage({ adminMode = false }) {
    const basePath = adminMode ? '/settings/strategy-registry' : '/strategy/registry';
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('');
    const [origin, setOrigin] = useState('');
    const [importText, setImportText] = useState('');
    const [validateResult, setValidateResult] = useState(null);
    const [busy, setBusy] = useState(false);
    const [allocDraft, setAllocDraft] = useState([]);
    const [allocBusy, setAllocBusy] = useState(false);
    const [allocError, setAllocError] = useState('');
    const importValidated = Boolean(validateResult?.ok);

    const syncAllocDraft = (list) => {
        const enabled = (Array.isArray(list) ? list : []).filter(isEnabledRow);
        setAllocDraft(enabled.map((row) => {
            const sid = strategyIdOf(row);
            const pct = allocationPctOf(row);
            return {
                strategy_id: sid,
                name: row.name,
                allocation_pct: pct == null ? '' : String(pct),
            };
        }).filter((row) => row.strategy_id != null));
    };

    const load = async () => {
        setLoading(true);
        setError('');
        try {
            const params = {};
            if (q.trim()) params.q = q.trim();
            if (status) params.status = status;
            if (origin) params.origin = origin;
            const [listRes, metaRes] = await Promise.all([
                api.get('/v1/strategy-registry', { params }),
                api.get('/v1/strategy-registry/meta'),
            ]);
            const list = listRes.data?.data || [];
            setRows(list);
            setMeta(metaRes.data?.data || null);
            syncAllocDraft(list);
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Failed to load Strategy Registry');
            setRows([]);
            setAllocDraft([]);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, status, origin]);

    const countsLabel = useMemo(() => {
        if (!meta?.counts) return '';
        const c = meta.counts;
        return `${c.total} total · ${c.active} active · ${c.draft} draft · ${c.archived} archived`;
    }, [meta]);

    const allocSum = allocDraft.reduce((sum, row) => sum + (Number(row.allocation_pct) || 0), 0);
    const allocSumIs100 = Math.abs(allocSum - 100) <= 0.01;

    const saveAllocations = async () => {
        setAllocError('');
        if (!allocDraft.length) {
            showToast('No enabled strategies to allocate', 'danger');
            return;
        }
        if (!allocSumIs100) {
            setAllocError(`Allocation % must sum to 100 (currently ${allocSum.toFixed(2)}).`);
            return;
        }
        setAllocBusy(true);
        try {
            await api.put('/v1/capital/allocations', {
                allocations: allocDraft.map((row) => ({
                    strategy_id: row.strategy_id,
                    allocation_pct: Number(row.allocation_pct),
                })),
            });
            showToast('Strategy allocations saved');
            notifyPortfolioDashboardRefresh();
            await load();
        } catch (err) {
            const msg = err?.response?.data?.error?.message
                || err?.response?.data?.message
                || err.message
                || 'Could not save allocations. Enabled strategy percentages must sum to 100.';
            setAllocError(msg);
            showToast(msg, 'danger');
        } finally {
            setAllocBusy(false);
        }
    };

    const parseImportPayload = () => {
        const parsed = JSON.parse(importText);
        if (parsed && typeof parsed === 'object' && parsed.artifact && typeof parsed.artifact === 'object') {
            return parsed.artifact;
        }
        return parsed;
    };

    const onValidate = async () => {
        setBusy(true);
        setValidateResult(null);
        setError('');
        try {
            const envelope = parseImportPayload();
            const res = await api.post('/v1/strategy-registry/validate', envelope);
            const result = res.data?.data || null;
            setValidateResult(result);
            if (!result?.ok) {
                showToast('Validation reported issues. See details below.', 'warning');
            }
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Validate failed');
        } finally {
            setBusy(false);
        }
    };

    const onImport = async () => {
        if (!validateResult?.ok) {
            setError('Validate the JSON successfully before importing.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            const envelope = parseImportPayload();
            const res = await api.post('/v1/strategy-registry/import', envelope);
            const created = res.data?.data;
            showToast(
                `Imported “${created?.name || 'strategy'}” as draft (slug ${created?.slug || '—'}). Use Enable to turn it on — other enabled strategies stay enabled.`,
            );
            setImportText('');
            setValidateResult(null);
            await load();
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Import failed');
        } finally {
            setBusy(false);
        }
    };

    const onExport = async (row) => {
        setBusy(true);
        setError('');
        try {
            const id = row.artifact_id || row.slug;
            const res = await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/export`);
            const env = res.data?.data;
            downloadJson(`${env?.slug || 'strategy'}.json`, env);
            showToast(`Exported ${env?.slug || id} (portable Screener/Indicator refs only).`);
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Export failed');
        } finally {
            setBusy(false);
        }
    };

    const onActivate = async (row) => {
        const id = row.artifact_id || row.slug;
        if (!window.confirm(`Enable “${row.name}” for this portfolio? Other enabled strategies stay enabled.`)) return;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/activate`);
            showToast(`“${row.name}” is now enabled for this portfolio.`);
            await load();
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Selection failed');
        } finally {
            setBusy(false);
        }
    };

    const onArchive = async (row) => {
        const id = row.artifact_id || row.slug;
        if (!window.confirm(
            'Archive this strategy? It will stop generating recommendations. Other enabled strategies stay enabled. Past holdings/recommendations keep their attribution.',
        )) return;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/archive`);
            showToast(`“${row.name}” archived.`);
            await load();
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Archive failed');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 className="h4 mb-1">{adminMode ? 'Strategy Registry (Admin)' : 'Strategy Registry'}</h2>
                    <p className="text-muted small mb-0">
                        Reusable Strategy definitions with metadata, versions, and JSON import/export.
                        A portfolio may enable multiple strategies. Strategies reference Screeners by registry slug / factory key — they never embed Screener trees.
                    </p>
                    {countsLabel && <p className="text-muted small mb-0 mt-1">{countsLabel}</p>}
                </div>
                <div className="d-flex flex-wrap gap-2">
                    {!adminMode && (
                        <Link to="/strategy" className="btn btn-outline-secondary btn-sm">Back to Strategy editor</Link>
                    )}
                    {adminMode && (
                        <Link to="/settings/global" className="btn btn-outline-secondary btn-sm">Back to settings</Link>
                    )}
                    <Link to="/strategy" className="btn btn-outline-primary btn-sm">Open Strategy editor</Link>
                </div>
            </div>

            <div className="card">
                <div className="card-body">
                    <div className="row g-2 align-items-end">
                        <div className="col-md-4">
                            <label className="form-label small mb-1" htmlFor="str-search">Search</label>
                            <input
                                id="str-search"
                                className="form-control form-control-sm"
                                placeholder="slug, name, intent"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="str-status">Status</label>
                            <select
                                id="str-status"
                                className="form-select form-select-sm"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                            >
                                <option value="">All</option>
                                {(meta?.statuses || []).map((s) => (
                                    <option key={s.id} value={s.id}>{s.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="str-origin">Origin</label>
                            <select
                                id="str-origin"
                                className="form-select form-select-sm"
                                value={origin}
                                onChange={(e) => setOrigin(e.target.value)}
                            >
                                <option value="">All</option>
                                {(meta?.origins || []).map((o) => (
                                    <option key={o.id} value={o.id}>{o.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-2">
                            <button
                                type="button"
                                className="btn btn-outline-secondary btn-sm w-100"
                                onClick={() => {
                                    setQ('');
                                    setStatus('');
                                    setOrigin('');
                                }}
                            >
                                Clear filters
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {error && <div className="alert alert-danger mb-0">{error}</div>}

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-sm table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Slug</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Version</th>
                                <th>Enabled</th>
                                <th className="text-end">Allocation %</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr>
                                    <td colSpan={7} className="text-muted">Loading…</td>
                                </tr>
                            )}
                            {!loading && rows.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted">No strategies match the filters.</td>
                                </tr>
                            )}
                            {!loading && rows.map((row) => {
                                const m = row.metadata || {};
                                const id = row.artifact_id || row.slug;
                                const selected = isEnabledRow(row);
                                const alloc = allocationPctOf(row);
                                return (
                                    <tr key={id}>
                                        <td>
                                            <Link to={`${basePath}/${encodeURIComponent(id)}`}>
                                                <code>{row.slug}</code>
                                            </Link>
                                        </td>
                                        <td>{row.name}</td>
                                        <td>
                                            <span className={`badge ${statusBadgeClass(m.status)}`}>{m.status || '—'}</span>
                                        </td>
                                        <td>{row.artifact_version}</td>
                                        <td>{selected ? 'Yes' : '—'}</td>
                                        <td className="text-end small">
                                            {selected && alloc != null ? `${Number(alloc).toFixed(2)}%` : '—'}
                                        </td>
                                        <td>
                                            <div className="d-flex flex-wrap gap-1">
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-secondary btn-sm"
                                                    disabled={busy}
                                                    onClick={() => onExport(row)}
                                                >
                                                    Export
                                                </button>
                                                {!selected && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-primary btn-sm"
                                                        disabled={busy}
                                                        onClick={() => onActivate(row)}
                                                    >
                                                        Enable
                                                    </button>
                                                )}
                                                {selected && (
                                                    <>
                                                        <Link to={`/strategy?strategy_id=${encodeURIComponent(id)}`} className="btn btn-outline-primary btn-sm">
                                                            Edit
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            className="btn btn-outline-secondary btn-sm"
                                                            disabled={busy}
                                                            onClick={() => onArchive(row)}
                                                        >
                                                            Archive
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            {!adminMode && allocDraft.length > 0 ? (
                <div className="card" id="strategy-registry-allocation">
                    <div className="card-body d-grid gap-2">
                        <h3 className="h6 mb-0">Allocation</h3>
                        <p className="text-muted small mb-0">
                            Enabled strategies claim a share of investable capital. Percentages must sum to 100
                            (same policy as Cash). They are not normalized automatically.
                        </p>
                        {allocError ? <div className="alert alert-danger py-2 mb-0">{allocError}</div> : null}
                        {!allocSumIs100 ? (
                            <div className="alert alert-secondary py-2 mb-0">
                                Current sum: {allocSum.toFixed(2)}% — save requires 100%.
                            </div>
                        ) : null}
                        <div className="table-responsive">
                            <table className="table table-sm align-middle mb-2">
                                <thead>
                                    <tr>
                                        <th>Strategy</th>
                                        <th className="text-end" style={{ width: '8rem' }}>Allocation %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {allocDraft.map((row, idx) => (
                                        <tr key={row.strategy_id}>
                                            <td className="fw-semibold">{row.name || `Strategy #${row.strategy_id}`}</td>
                                            <td>
                                                <NumberInput
                                                    className="form-control form-control-sm text-end"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    value={row.allocation_pct}
                                                    onChange={(e) => {
                                                        const next = [...allocDraft];
                                                        next[idx] = {
                                                            ...next[idx],
                                                            allocation_pct: e.target.value,
                                                        };
                                                        setAllocDraft(next);
                                                        setAllocError('');
                                                    }}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="d-flex flex-wrap gap-2 align-items-center">
                            <button
                                type="button"
                                className="btn btn-primary btn-sm"
                                disabled={allocBusy || !allocSumIs100}
                                onClick={saveAllocations}
                            >
                                {allocBusy ? 'Saving…' : 'Save allocations'}
                            </button>
                            <span className="small text-muted">
                                Sum
                                {' '}
                                {allocSum.toFixed(2)}
                                %
                            </span>
                        </div>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-body d-grid gap-2">
                    <h3 className="h6 mb-0">Import / validate Strategy JSON</h3>
                    <p className="text-muted small mb-0">
                        Paste a Trading Artifact envelope (<code>artifact_type: strategy</code>).
                        Mandatory fields: <code>schema_version</code>, <code>artifact_type</code>, <code>slug</code>, <code>name</code>, <code>metadata</code>, and <code>definition.scoring_model</code> with enabled weights summing to 100.
                        Eligibility must reference Screeners by <code>screener_slug</code> / <code>screener_factory_key</code> only — never embed condition trees.
                        {' '}
                        <a href={appUrl('/docs/strategy-registry.html')} target="_blank" rel="noopener noreferrer">
                            Import schema guide
                        </a>
                        {' '}
                        (mandatory vs optional fields, scoring keys, minimal example).
                        {' '}
                        <a
                            href={appUrl('/docs/stox-trading-artifacts-ai-guide.md')}
                            download="stox-trading-artifacts-ai-guide.md"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Download AI authoring guide (.md)
                        </a>
                        {' '}
                        — full Indicator + Screener + Strategy + Cookbook pack for AI/offline use.
                        Run <strong>Validate</strong> first — <strong>Import</strong> stays disabled until validation succeeds.
                        Import creates a <strong>draft</strong> — use <strong>Enable</strong> to turn it on for this portfolio.
                        Multiple strategies may be enabled at the same time; Enable does not disable others.
                    </p>
                    <textarea
                        className="form-control font-monospace small"
                        rows={10}
                        placeholder='{"schema_version":"1.0","artifact_type":"strategy","slug":"...", ...}'
                        value={importText}
                        onChange={(e) => {
                            setImportText(e.target.value);
                            setValidateResult(null);
                        }}
                    />
                    <ValidationSuccessBanner show={importValidated} />
                    <div className="d-flex flex-wrap gap-2">
                        <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy || !importText.trim()} onClick={onValidate}>
                            Validate
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            disabled={busy || !importText.trim() || !importValidated}
                            title={importValidated ? 'Import validated JSON as draft' : 'Validate successfully before importing'}
                            onClick={onImport}
                        >
                            Import
                        </button>
                    </div>
                    {validateResult && (
                        <pre className="bg-light border rounded p-2 small mb-0 overflow-auto" style={{ maxHeight: 240 }}>
                            {JSON.stringify(validateResult, null, 2)}
                        </pre>
                    )}
                </div>
            </div>
        </div>
    );
}
