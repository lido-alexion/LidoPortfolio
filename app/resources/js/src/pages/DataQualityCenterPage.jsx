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
