import React, { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../api';
import { useNotifications } from '../context/NotificationContext';

const VIEWS = [['all', 'All'], ['needs_attention', 'Needs attention'], ['unread', 'Unread'], ['critical', 'Critical'], ['resolved', 'Resolved']];

function severityClass(severity) {
    if (severity === 'critical') return 'text-bg-danger';
    if (severity === 'action_required') return 'text-bg-warning';
    return 'text-bg-secondary';
}

export default function NotificationHistoryPage() {
    const [params, setParams] = useSearchParams();
    const { refresh: refreshChrome } = useNotifications();
    const [payload, setPayload] = useState({ data: [], meta: {} });
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(true);
    const view = params.get('view') || 'all';
    const selectedId = params.get('notification');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const response = await api.get(`/notification-center?view=${encodeURIComponent(view)}&per_page=50`, { skipErrorToast: true });
            setPayload(response.data || { data: [], meta: {} });
        } finally {
            setLoading(false);
        }
    }, [view]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        if (!selectedId) {
            setDetail(null);
            return;
        }
        api.get(`/notification-center/${selectedId}`, { skipErrorToast: true })
            .then(async (response) => {
                setDetail(response.data?.data || null);
                await api.post(`/notification-center/${selectedId}/read`, null, { skipErrorToast: true });
                await reloadAll();
            })
            .catch(() => setDetail(null));
        // Opening one notification intentionally marks attention read.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedId]);

    const reloadAll = () => Promise.all([load(), refreshChrome()]);
    const markRead = async (id) => {
        await api.post(`/notification-center/${id}/read`, null, { skipErrorToast: true });
        await reloadAll();
    };
    const markAllRead = async () => {
        await api.post('/notification-center/mark-all-read', null, { skipErrorToast: true });
        await reloadAll();
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Notification Center</h1>
                    <p className="text-muted small mb-0">Account-level conditions, events, and delivery activity.</p>
                </div>
                <button type="button" className="btn btn-outline-secondary btn-sm" onClick={markAllRead} disabled={!payload.meta?.unread_count}>Mark all as read</button>
            </div>

            <div className="nav nav-pills gap-1 mb-3" aria-label="Notification views">
                {VIEWS.map(([key, label]) => (
                    <button type="button" key={key} className={`nav-link${view === key ? ' active' : ''}`} onClick={() => setParams(key === 'all' ? {} : { view: key })}>{label}</button>
                ))}
            </div>

            {selectedId && detail && (
                <section className="card mb-3" aria-label="Notification detail">
                    <div className="card-body">
                        <div className="d-flex justify-content-between gap-3">
                            <div>
                                <span className={`badge ${severityClass(detail.severity)}`}>{detail.severity.replace('_', ' ')}</span>
                                <h2 className="h5 mt-2">{detail.title}</h2>
                                <p>{detail.message}</p>
                            </div>
                            <button type="button" className="btn-close" aria-label="Close detail" onClick={() => setParams(view === 'all' ? {} : { view })} />
                        </div>
                        <div className="small text-muted mb-2">Condition: {detail.condition_state} · Occurrences: {detail.occurrence_count}</div>
                        <ol className="small mb-0">
                            {(detail.timeline || []).map((entry) => <li key={entry.id}>{entry.activity_type.replace('_', ' ')} · {entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : '—'}</li>)}
                        </ol>
                    </div>
                </section>
            )}

            {loading ? <p className="text-muted">Loading…</p> : payload.data.length === 0 ? (
                <div className="border rounded p-4 text-muted">No notifications in this view.</div>
            ) : (
                <div className="list-group">
                    {payload.data.map((item) => (
                        <div key={item.id} className={`list-group-item${item.attention_state === 'unread' ? ' border-start border-4 border-primary' : ''}`}>
                            <div className="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <span className={`badge me-2 ${severityClass(item.severity)}`}>{item.severity.replace('_', ' ')}</span>
                                    <button type="button" className="btn btn-link p-0 fw-semibold text-start" onClick={() => setParams({ ...(view === 'all' ? {} : { view }), notification: String(item.id) })}>{item.title}</button>
                                    <div className="small mt-1">{item.message}</div>
                                    <div className="small text-muted mt-1">{item.condition_state} · {item.latest_activity_at ? new Date(item.latest_activity_at).toLocaleString() : '—'}</div>
                                </div>
                                {item.attention_state === 'unread' && <button type="button" className="btn btn-outline-secondary btn-sm align-self-start" onClick={() => markRead(item.id)}>Mark as read</button>}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
