import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';

function fmt(v) {
    if (!v) return '—';
    return new Date(v).toLocaleString();
}

export default function CorporateActionHistoryPage() {
    const [rows, setRows] = useState([]);
    const [selected, setSelected] = useState(null);
    const [notes, setNotes] = useState('');
    const [ratio, setRatio] = useState('');
    const [saving, setSaving] = useState(false);
    const [loading, setLoading] = useState(true);

    const load = async () => {
        setLoading(true);
        try {
            const res = await api.get('/data-quality/issues/history', {
                params: { issue_type: 'corporate_action' },
            });
            const list = res.data.data || [];
            setRows(list);
            if (selected) {
                setSelected(list.find((row) => row.id === selected.id) || null);
            }
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, []);

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
                });
            }
            setNotes('');
            setRatio('');
            await load();
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <h2 className="h4 mb-1">Corporate Action History</h2>
                    <p className="text-muted small mb-0">Resolved and reversed data-quality actions with audit trail.</p>
                </div>
                <Link to="/settings/data-quality" className="btn btn-outline-secondary btn-sm">Back to Data Quality Center</Link>
            </div>

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Stock</th>
                                <th>Issue</th>
                                <th>Detected</th>
                                <th>Resolved</th>
                                <th>Status</th>
                                <th>Suggested</th>
                                <th>Applied</th>
                                <th>Method</th>
                                <th>Source</th>
                                <th>Auto</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {!loading && rows.length === 0 ? (
                                <tr><td colSpan={10} className="text-muted">No resolved corporate action issues yet.</td></tr>
                            ) : rows.map((row) => (
                                <tr key={row.id}>
                                    <td>{row.symbol || row.stock?.symbol || '—'}</td>
                                    <td>{row.issue_type}</td>
                                    <td>{fmt(row.detected_at)}</td>
                                    <td>{fmt(row.resolved_at)}</td>
                                    <td>{row.issue_status}</td>
                                    <td>{row.suggested_ratio ?? '—'}</td>
                                    <td>{row.applied_ratio ?? '—'}</td>
                                    <td>{row.detection_method}</td>
                                    <td>{row.detection_source || '—'}</td>
                                    <td>{row.auto_resolved ? 'Yes' : 'No'}</td>
                                    <td className="text-end">
                                        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setSelected(row)}>
                                            Open
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {selected ? (
                <div className="card">
                    <div className="card-header">Reverse / Update Issue #{selected.id}</div>
                    <div className="card-body">
                        <p className="small text-muted">
                            Use this panel to reverse or update a prior decision. A new resolution event is appended to history.
                        </p>
                        <div className="row g-2">
                            <div className="col-12 col-md-4">
                                <label className="form-label">Applied Ratio (optional)</label>
                                <input className="form-control" type="number" min="0.0001" step="0.0001" value={ratio} onChange={(e) => setRatio(e.target.value)} />
                            </div>
                            <div className="col-12 col-md-8">
                                <label className="form-label">Notes</label>
                                <input className="form-control" value={notes} onChange={(e) => setNotes(e.target.value)} />
                            </div>
                        </div>
                        <div className="d-flex gap-2 mt-3">
                            <button type="button" className="btn btn-success" disabled={saving} onClick={() => resolveIssue('accept')}>Accept / Update</button>
                            <button type="button" className="btn btn-outline-danger" disabled={saving} onClick={() => resolveIssue('reject')}>Reject</button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
