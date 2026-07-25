import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

function statusClass(status) {
    switch (status) {
        case 'delivered': return 'text-bg-success';
        case 'failed': return 'text-bg-danger';
        case 'queued':
        case 'sending': return 'text-bg-warning';
        default: return 'text-bg-secondary';
    }
}

export default function NotificationHistoryPage() {
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await api.get('/v1/notifications');
            setItems(Array.isArray(data?.data) ? data.data : []);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load notifications', 'danger');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const retry = async (id) => {
        setBusyId(id);
        try {
            await api.post(`/v1/notifications/${id}/retry`);
            showToast('Retry attempted', 'success');
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Retry failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Notifications</h1>
                    <p className="text-muted small mb-0">
                        Trading OS Telegram deliveries — status, timestamps, and retries.
                    </p>
                </div>
                <div className="d-flex gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to="/review">Review</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading}>Refresh</button>
                </div>
            </div>

            {loading ? <p className="text-muted">Loading…</p> : items.length === 0 ? (
                <div className="border rounded p-4 text-muted">
                    No Trading OS notifications yet. Run the pipeline with notifications enabled and Telegram configured.
                </div>
            ) : (
                <div className="table-responsive">
                    <table className="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Channel</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Attempts</th>
                                <th>Created</th>
                                <th>Delivered</th>
                                <th>Error</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((n) => (
                                <tr key={n.id}>
                                    <td>{n.id}</td>
                                    <td>{n.channel}</td>
                                    <td>{n.notification_type}</td>
                                    <td><span className={`badge ${statusClass(n.status)}`}>{n.status}</span></td>
                                    <td>{n.attempt_count}</td>
                                    <td className="small">{n.created_at ? new Date(n.created_at).toLocaleString() : '—'}</td>
                                    <td className="small">{n.delivered_at ? new Date(n.delivered_at).toLocaleString() : '—'}</td>
                                    <td className="small text-danger">{n.last_error || '—'}</td>
                                    <td>
                                        {n.status !== 'delivered' && (
                                            <button
                                                type="button"
                                                className="btn btn-link btn-sm px-0"
                                                disabled={busyId === n.id}
                                                onClick={() => retry(n.id)}
                                            >
                                                Retry
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
