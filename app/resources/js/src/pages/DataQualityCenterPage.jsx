import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';

function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString();
}

function formatRatio(value) {
    if (value == null || value === '') return '—';
    return Number(value).toFixed(4);
}

export default function DataQualityCenterPage() {
    const [dashboard, setDashboard] = useState(null);
    const [issues, setIssues] = useState([]);
    const [selected, setSelected] = useState(null);
    const [notes, setNotes] = useState('');
    const [ratio, setRatio] = useState('');
    const [historicalIsin, setHistoricalIsin] = useState('');
    const [historicalValidFrom, setHistoricalValidFrom] = useState('');
    const [manualOverride, setManualOverride] = useState(false);
    const [manualSourceReference, setManualSourceReference] = useState('');
    const [manualJustification, setManualJustification] = useState('');
    const [manualSearch, setManualSearch] = useState('');
    const [manualStocks, setManualStocks] = useState([]);
    const [manualStock, setManualStock] = useState(null);
    const [manualType, setManualType] = useState('split');
    const [manualExDate, setManualExDate] = useState('');
    const [manualRatio, setManualRatio] = useState('');
    const [manualRecordDate, setManualRecordDate] = useState('');
    const [manualPreview, setManualPreview] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const load = async () => {
        setLoading(true);
        try {
            const [d, q] = await Promise.all([
                api.get('/data-quality/dashboard'),
                api.get('/data-quality/issues/unresolved', { params: { issue_type: 'corporate_action' } }),
            ]);
            setDashboard(d.data.data || {});
            const list = q.data.data || [];
            setIssues(list);
            if (selected) {
                const refreshed = list.find((row) => row.id === selected.id) || null;
                setSelected(refreshed);
            }
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, []);

    const cards = useMemo(() => ([
        { key: 'pending_corporate_actions', label: 'Pending Corporate Actions' },
        { key: 'auto_accepted', label: 'Auto Accepted' },
        { key: 'rejected', label: 'Rejected' },
        { key: 'accepted', label: 'Accepted' },
    ]), []);

    const resolveIssue = async (action) => {
        if (!selected || saving) return;
        setSaving(true);
        try {
            if (action === 'reject') {
                await api.post(`/data-quality/issues/${selected.id}/reject`, { notes: notes.trim() || null });
            } else {
                await api.post(`/data-quality/issues/${selected.id}/accept`, {
                    notes: notes.trim() || null,
                    applied_ratio: ratio.trim() ? Number(ratio) : null,
                    manual_evidence_override: manualOverride,
                    confirm_manual_evidence_override: manualOverride || undefined,
                    manual_source_reference: manualSourceReference.trim() || undefined,
                    manual_justification: manualJustification.trim() || undefined,
                    historical_isin: historicalIsin.trim().toUpperCase() || undefined,
                    historical_valid_from: historicalValidFrom || undefined,
                });
            }
            setNotes('');
            setRatio('');
            setHistoricalIsin('');
            setHistoricalValidFrom('');
            setManualOverride(false);
            setManualSourceReference('');
            setManualJustification('');
            await load();
        } finally {
            setSaving(false);
        }
    };

    const searchManualStocks = async () => {
        const query = manualSearch.trim();
        if (query.length < 2) return;
        const response = await api.get('/stocks/search', { params: { q: query, limit: 20 } });
        setManualStocks(response.data.data || []);
    };

    const previewManualAction = async () => {
        if (!manualStock || !manualExDate || !manualRatio) return;
        const response = await api.post('/data-quality/manual-corporate-actions/preview', {
            stock_id: manualStock.id, corporate_action_type: manualType,
            ex_date: manualExDate, applied_ratio: Number(manualRatio),
        });
        setManualPreview(response.data.data);
    };

    const createManualAction = async () => {
        if (!manualPreview || saving) return;
        setSaving(true);
        try {
            await api.post('/data-quality/manual-corporate-actions', {
                stock_id: manualStock.id, corporate_action_type: manualType,
                ex_date: manualExDate, applied_ratio: Number(manualRatio),
                record_date: manualRecordDate || undefined,
                source_reference: manualSourceReference.trim() || undefined,
                justification: manualJustification.trim() || undefined,
                preview_confirmed: true,
                preview_token: manualPreview.preview_token,
            });
            setManualPreview(null);
            setManualStock(null);
            setManualStocks([]);
            setManualSearch('');
            setManualExDate('');
            setManualRatio('');
            setManualRecordDate('');
            setManualSourceReference('');
            setManualJustification('');
            await load();
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 className="h4 mb-1">Data Quality Center</h2>
                    <p className="text-muted small mb-0">
                        Review unresolved market-data anomalies before they affect analytics and recommendations.
                    </p>
                </div>
                <Link to="/settings/global" className="btn btn-outline-secondary btn-sm">Back to settings</Link>
            </div>

            <div className="row g-3">
                {cards.map((card) => (
                    <div className="col-6 col-md-3" key={card.key}>
                        <div className="card">
                            <div className="card-body">
                                <div className="text-muted small">{card.label}</div>
                                <div className="h4 mb-0">{dashboard?.[card.key] ?? 0}</div>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            <div className="card">
                <div className="card-header">Enter a corporate action manually</div>
                <div className="card-body row g-2">
                    <div className="col-12 col-lg-4">
                        <label className="form-label" htmlFor="manual-stock-search">Stock</label>
                        <div className="input-group">
                            <input id="manual-stock-search" className="form-control" value={manualSearch} onChange={(event) => setManualSearch(event.target.value)} placeholder="Symbol or company name" />
                            <button type="button" className="btn btn-outline-secondary" onClick={searchManualStocks}>Search</button>
                        </div>
                        {manualStocks.length > 0 && <select className="form-select mt-2" value={manualStock?.id || ''} onChange={(event) => setManualStock(manualStocks.find((stock) => String(stock.id) === event.target.value) || null)}><option value="">Select stock</option>{manualStocks.map((stock) => <option key={stock.id} value={stock.id}>{stock.symbol} — {stock.name} ({stock.exchange})</option>)}</select>}
                        {manualStock && <div className="small text-muted mt-1">Selected: {manualStock.symbol} ({manualStock.isin || 'ISIN unavailable'})</div>}
                    </div>
                    <div className="col-6 col-lg-2"><label className="form-label" htmlFor="manual-action-type">Action</label><select id="manual-action-type" className="form-select" value={manualType} onChange={(event) => { setManualType(event.target.value); setManualPreview(null); }}><option value="split">Split</option><option value="bonus">Bonus</option></select></div>
                    <div className="col-6 col-lg-2"><label className="form-label" htmlFor="manual-action-date">Ex-date</label><input id="manual-action-date" className="form-control" type="date" value={manualExDate} onChange={(event) => { setManualExDate(event.target.value); setManualPreview(null); }} /></div>
                    <div className="col-6 col-lg-2"><label className="form-label" htmlFor="manual-action-ratio">Applied ratio</label><input id="manual-action-ratio" className="form-control" type="number" min="0.000001" max="10000" step="0.000001" value={manualRatio} onChange={(event) => { setManualRatio(event.target.value); setManualPreview(null); }} placeholder="e.g. 2 or 0.5" /></div>
                    <div className="col-6 col-lg-2"><label className="form-label" htmlFor="manual-record-date">Record date</label><input id="manual-record-date" className="form-control" type="date" value={manualRecordDate} onChange={(event) => setManualRecordDate(event.target.value)} /></div>
                    <div className="col-12"><label className="form-label" htmlFor="manual-action-source">Independent source reference (optional)</label><input id="manual-action-source" className="form-control" maxLength={500} value={manualSourceReference} onChange={(event) => setManualSourceReference(event.target.value)} /></div>
                    <div className="col-12"><label className="form-label" htmlFor="manual-action-justification">Review notes (optional)</label><textarea id="manual-action-justification" className="form-control" rows={2} maxLength={2000} value={manualJustification} onChange={(event) => setManualJustification(event.target.value)} /></div>
                    <div className="col-12"><button type="button" className="btn btn-outline-primary" onClick={previewManualAction} disabled={!manualStock || !manualExDate || !manualRatio || saving}>Preview affected prices</button></div>
                    {manualPreview && <div className="col-12"><div className="alert alert-info mb-2"><strong>Read-only preview:</strong> {manualPreview.stock.symbol} {manualPreview.corporate_action_type} effective {manualPreview.ex_date}; {manualPreview.affected_price_rows} earlier price rows; price divisor {Number(manualPreview.price_divisor).toFixed(6)}. No prices were changed. Review the sample rows before creating this pending issue.</div><div className="table-responsive"><table className="table table-sm"><thead><tr><th>Date</th><th>Close before</th><th>Close after</th><th>Volume before</th><th>Volume after</th></tr></thead><tbody>{manualPreview.sample_rows.map((row) => <tr key={row.price_date}><td>{row.price_date}</td><td>{row.close_before}</td><td>{row.close_after}</td><td>{row.volume_before}</td><td>{row.volume_after}</td></tr>)}</tbody></table></div><button type="button" className="btn btn-warning" onClick={createManualAction} disabled={saving}>Create pending issue after reviewing preview</button></div>}
                </div>
            </div>

            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <span>Pending review queue</span>
                    <Link to="/settings/data-quality/history" className="btn btn-sm btn-outline-secondary">Corporate Action History</Link>
                </div>
                <div className="table-responsive">
                    <table className="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Stock</th>
                                <th>Issue</th>
                                <th>Method</th>
                                <th>Suggested Ratio</th>
                                <th>Confidence</th>
                                <th>Detected</th>
                            </tr>
                        </thead>
                        <tbody>
                            {issues.length === 0 && !loading ? (
                                <tr><td colSpan={6} className="text-muted">No pending issues.</td></tr>
                            ) : issues.map((issue) => (
                                <tr key={issue.id} role="button" onClick={() => setSelected(issue)}>
                                    <td>{issue.symbol || issue.stock?.symbol || '—'}</td>
                                    <td>{issue.issue_type}</td>
                                    <td>{issue.detection_method}</td>
                                    <td>{formatRatio(issue.suggested_ratio)}</td>
                                    <td>{issue.confidence != null ? Number(issue.confidence).toFixed(2) : '—'}</td>
                                    <td>{formatDateTime(issue.detected_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {selected ? (
                <div className="card">
                    <div className="card-header">Review Issue #{selected.id} ({selected.symbol || selected.stock?.symbol})</div>
                    <div className="card-body row g-3">
                        <div className="col-12 col-lg-6 small">
                            <div><strong>Issue Type:</strong> {selected.issue_type}</div>
                            <div><strong>Detection Method:</strong> {selected.detection_method}</div>
                            <div><strong>Source:</strong> {selected.detection_source || '—'}</div>
                            <div><strong>Suggested Ratio:</strong> {formatRatio(selected.suggested_ratio)}</div>
                            <div><strong>Previous Close:</strong> {selected.previous_close || '—'}</div>
                            <div><strong>Current Open:</strong> {selected.current_open || '—'}</div>
                            <div><strong>Gap %:</strong> {selected.gap_percent || '—'}</div>
                            <div><strong>Volume Change %:</strong> {selected.volume_change_percent || '—'}</div>
                            <div><strong>Exchange Match:</strong> {selected.exchange_match ? 'Yes' : 'No'}</div>
                            <div><strong>Status:</strong> {selected.issue_status}</div>
                        </div>
                        <div className="col-12 col-lg-6">
                            <label className="form-label">Applied Ratio (optional)</label>
                            <input
                                type="number"
                                step="0.0001"
                                min="0.0001"
                                className="form-control mb-2"
                                value={ratio}
                                onChange={(e) => setRatio(e.target.value)}
                                placeholder={selected.suggested_ratio || ''}
                            />
                            <div className="form-check mb-2">
                                <input id="dq-manual-override" className="form-check-input" type="checkbox" checked={manualOverride} onChange={(e) => setManualOverride(e.target.checked)} />
                                <label className="form-check-label" htmlFor="dq-manual-override">Approve using independent off-platform evidence</label>
                            </div>
                            {manualOverride && <div className="alert alert-warning py-2 small">This records your authenticated approval without requiring StoX-collected evidence. For an ISIN change, enter the old ISIN and its first valid date. The stock master supplies the new ISIN.</div>}
                            {manualOverride && <>
                                <label className="form-label" htmlFor="dq-manual-source">Off-platform source reference (optional)</label>
                                <input id="dq-manual-source" className="form-control mb-2" maxLength={500} value={manualSourceReference} onChange={(e) => setManualSourceReference(e.target.value)} />
                                <label className="form-label" htmlFor="dq-manual-justification">Review justification (optional)</label>
                                <textarea id="dq-manual-justification" className="form-control mb-2" rows={2} maxLength={2000} value={manualJustification} onChange={(e) => setManualJustification(e.target.value)} />
                                <label className="form-label" htmlFor="dq-old-isin">Historical ISIN (optional)</label>
                                <input id="dq-old-isin" className="form-control mb-2" maxLength={12} value={historicalIsin} onChange={(e) => setHistoricalIsin(e.target.value.toUpperCase())} placeholder="INE000A01010" />
                                <label className="form-label" htmlFor="dq-isin-valid-from">Historical ISIN valid from</label>
                                <input id="dq-isin-valid-from" className="form-control mb-2" type="date" value={historicalValidFrom} onChange={(e) => setHistoricalValidFrom(e.target.value)} />
                            </>}
                            <label className="form-label">Admin Notes</label>
                            <textarea
                                className="form-control mb-3"
                                rows={3}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                            />
                            <div className="d-flex flex-wrap gap-2">
                                <button type="button" className="btn btn-success" onClick={() => resolveIssue('accept')} disabled={saving}>
                                    Accept
                                </button>
                                <button type="button" className="btn btn-primary" onClick={() => resolveIssue('modify')} disabled={saving}>
                                    Modify Ratio & Accept
                                </button>
                                <button type="button" className="btn btn-outline-danger" onClick={() => resolveIssue('reject')} disabled={saving}>
                                    Reject
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
