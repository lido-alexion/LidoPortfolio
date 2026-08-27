import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { runApiMutation } from '../hooks/useApiMutation';
import { tosList } from '../utils/tosEnvelope';

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
    const [busyId, setBusyId] = useState(null);
    const { data, loading, reload: load } = useApiGet({
        errorFallback: 'Failed to load notifications',
        request: async () => {
            const response = await api.get('/v1/notifications', { skipErrorToast: true });
            return tosList(response);
        },
    });
    const items = Array.isArray(data) ? data : [];

    const retry = async (id) => {
        setBusyId(id);
        try {
            const { ok } = await runApiMutation(async () => {
                await api.post(`/v1/notifications/${id}/retry`, null, { skipErrorToast: true });
            }, { successMessage: 'Retry attempted', errorFallback: 'Retry failed' });
            if (ok) {
                await load();
            }
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
                    <Link className="btn btn-outline-secondary btn-sm" to="/settings/portfolio">Back to settings</Link>
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
