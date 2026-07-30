import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api';

function statusBadgeClass(status) {
    switch (status) {
        case 'active':
            return 'text-bg-success';
        case 'stub':
            return 'text-bg-warning';
        case 'planned':
            return 'text-bg-secondary';
        case 'deprecated':
            return 'text-bg-dark';
        default:
            return 'text-bg-light';
    }
}

function DependencyTree({ node, depth = 0 }) {
    if (!node) return null;
    const indent = { paddingLeft: `${depth * 1.25}rem` };
    const cycle = node.cycle ? ' (cycle)' : '';
    const truncated = node.truncated ? ' (truncated)' : '';
    return (
        <div>
            <div style={indent} className="font-monospace small py-1">
                {depth > 0 ? '└ ' : ''}
                <Link to={`/settings/indicators/${encodeURIComponent(node.id)}`}>
                    {node.display_name || node.id}
                </Link>
                <span className="text-muted">
                    {' '}
                    (
                    {node.id}
                    {node.type ? ` · ${node.type}` : ''}
                    {node.status ? ` · ${node.status}` : ''}
                    )
                    {cycle}
                    {truncated}
                </span>
            </div>
            {(node.depends_on || []).map((child) => (
                <DependencyTree key={`${node.id}->${child.id}-${depth}`} node={child} depth={depth + 1} />
            ))}
        </div>
    );
}

export default function IndicatorRegistryDetailPage() {
    const { id } = useParams();
    const [payload, setPayload] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let cancelled = false;
        (async () => {
            setLoading(true);
            setError('');
            try {
                const res = await api.get(`/v1/indicators/${encodeURIComponent(id)}`);
                if (!cancelled) setPayload(res.data?.data || null);
            } catch (err) {
                if (!cancelled) {
                    setError(err?.response?.data?.error?.message || err.message || 'Failed to load indicator');
                    setPayload(null);
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => { cancelled = true; };
    }, [id]);

    const indicator = payload?.indicator;

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 className="h4 mb-1">{indicator?.display_name || id}</h2>
                    <p className="text-muted small mb-0">
                        <code>{indicator?.id || id}</code>
                        {indicator?.version ? ` · v${indicator.version}` : ''}
                    </p>
                </div>
                <Link to="/settings/indicators" className="btn btn-outline-secondary btn-sm">Back to registry</Link>
            </div>

            {loading && <div className="text-muted">Loading…</div>}
            {error && <div className="alert alert-danger mb-0">{error}</div>}

            {indicator && (
                <>
                    <div className="card">
                        <div className="card-header">Overview</div>
                        <div className="card-body d-grid gap-2">
                            <div className="d-flex flex-wrap gap-2">
                                <span className={`badge ${statusBadgeClass(indicator.status)}`}>{indicator.status}</span>
                                <span className="badge text-bg-light text-dark border">{indicator.type}</span>
                                <span className="badge text-bg-light text-dark border">{indicator.category_label || indicator.category}</span>
                                {indicator.screenable && <span className="badge text-bg-info">screenable</span>}
                                {indicator.chartable && <span className="badge text-bg-info">chartable</span>}
                            </div>
                            <p className="mb-0">{indicator.description || 'No description.'}</p>
                        </div>
                    </div>

                    <div className="row g-3">
                        <div className="col-md-6">
                            <div className="card h-100">
                                <div className="card-header">Parameters</div>
                                <div className="card-body">
                                    {(indicator.parameters || []).length === 0 && (
                                        <p className="text-muted small mb-0">No parameters.</p>
                                    )}
                                    {(indicator.parameters || []).length > 0 && (
                                        <ul className="mb-0 small">
                                            {indicator.parameters.map((p) => (
                                                <li key={p.id}>
                                                    <strong>{p.label || p.id}</strong>
                                                    {' '}
                                                    (
                                                    {p.id}
                                                    )
                                                    {p.default != null ? ` · default ${p.default}` : ''}
                                                    {p.min != null || p.max != null
                                                        ? ` · range ${p.min ?? '—'}–${p.max ?? '—'}`
                                                        : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        </div>
                        <div className="col-md-6">
                            <div className="card h-100">
                                <div className="card-header">Consumers & capabilities</div>
                                <div className="card-body d-grid gap-2">
                                    <div>
                                        <div className="small text-muted mb-1">Consumers</div>
                                        <div className="d-flex flex-wrap gap-1">
                                            {(indicator.consumers || []).map((c) => (
                                                <span key={c} className="badge text-bg-secondary">{c}</span>
                                            ))}
                                            {(indicator.consumers || []).length === 0 && (
                                                <span className="text-muted small">None</span>
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="small text-muted mb-1">Capabilities</div>
                                        <ul className="mb-0 small">
                                            {Object.entries(indicator.capabilities || {}).map(([k, v]) => (
                                                <li key={k}>
                                                    {k}
                                                    :
                                                    {' '}
                                                    {v ? 'true' : 'false'}
                                                </li>
                                            ))}
                                            {Object.keys(indicator.capabilities || {}).length === 0 && (
                                                <li className="text-muted">None declared</li>
                                            )}
                                        </ul>
                                    </div>
                                    <div className="small text-muted">
                                        Units:
                                        {' '}
                                        {indicator.units}
                                        {' · '}
                                        Precision:
                                        {' '}
                                        {indicator.precision}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Dependencies</div>
                        <div className="card-body">
                            {(payload.dependencies || []).length === 0 && (
                                <p className="text-muted small mb-2">No declared dependencies.</p>
                            )}
                            {(payload.dependencies || []).length > 0 && (
                                <ul className="small mb-3">
                                    {payload.dependencies.map((d) => (
                                        <li key={d.id}>
                                            {d.missing ? (
                                                <span className="text-danger">
                                                    Missing:
                                                    {' '}
                                                    {d.id}
                                                </span>
                                            ) : (
                                                <Link to={`/settings/indicators/${encodeURIComponent(d.id)}`}>
                                                    {d.display_name}
                                                    {' '}
                                                    (
                                                    {d.id}
                                                    )
                                                </Link>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="small text-muted mb-1">Dependency tree</div>
                            <div className="border rounded p-2 bg-body-tertiary">
                                <DependencyTree node={payload.dependency_tree} />
                            </div>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Formula explanation</div>
                        <div className="card-body">
                            <p className="small text-muted">
                                Documentation only. Indicators ship in application releases — there is no in-app formula editor.
                            </p>
                            {indicator.formula_explanation ? (
                                <pre className="small mb-0 text-wrap" style={{ whiteSpace: 'pre-wrap' }}>
                                    {indicator.formula_explanation}
                                </pre>
                            ) : (
                                <p className="text-muted small mb-0">No formula explanation recorded for this indicator.</p>
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
