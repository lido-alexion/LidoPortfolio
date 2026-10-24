import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api';
import { useNotifications } from '../context/NotificationContext';
import DataState from '../components/DataState';
import { showToast } from '../toast';

const VIEWS = [['all', 'All'], ['needs_attention', 'Needs attention'], ['unread', 'Unread'], ['critical', 'Critical'], ['resolved', 'Resolved']];

function severityClass(severity) {
    if (severity === 'critical') return 'text-bg-danger';
    if (severity === 'action_required') return 'text-bg-warning';
    return 'text-bg-secondary';
}

function safePrimaryAction(action) {
    if (!action || typeof action !== 'object') return null;
    const label = typeof action.label === 'string' ? action.label.trim() : '';
    const route = typeof action.route === 'string' ? action.route.trim() : '';
    if (!label || !route || !route.startsWith('/') || route.startsWith('//') || /^[a-z][a-z\d+.-]*:/i.test(route)) return null;
    return { label, route };
}

function deliveryKindLabel(kind) {
    return {
        initial: 'Initial',
        escalation: 'Escalation',
        reminder: 'Reminder',
    }[kind] || String(kind || 'Delivery').replaceAll('_', ' ');
}

export default function NotificationHistoryPage() {
    const [params, setParams] = useSearchParams();
    const { refresh: refreshChrome } = useNotifications();
    const [payload, setPayload] = useState({ data: [], meta: {} });
    const [detailState, setDetailState] = useState({ id: null, status: 'idle', data: null, notice: null });
    const [detailAttempt, setDetailAttempt] = useState(0);
    const detailRequest = useRef(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [retryingDelivery, setRetryingDelivery] = useState(null);
    const [filters, setFilters] = useState({ q: '', from: '', to: '', category: '', channel: '', delivery_status: '', read_state: '' });
    const [appliedFilters, setAppliedFilters] = useState({});
    const view = params.get('view') || 'all';
    const selectedId = params.get('notification');
    const selectedIdRef = useRef(selectedId);
    selectedIdRef.current = selectedId;
    const detail = detailState.id === selectedId ? detailState.data : null;
    const detailLoading = selectedId && (detailState.id !== selectedId || detailState.status === 'loading');
    const primaryAction = safePrimaryAction(detail?.primary_action);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const query = new URLSearchParams({ view, per_page: '50' });
            Object.entries(appliedFilters).forEach(([key, value]) => { if (value) query.set(key, value); });
            const response = await api.get(`/notification-center?${query.toString()}`, { skipErrorToast: true });
            setPayload(response.data || { data: [], meta: {} });
        } catch (requestError) {
            setError(requestError);
        } finally {
            setLoading(false);
        }
    }, [view, appliedFilters]);

    const reloadAll = () => Promise.all([load(), refreshChrome()]);
    const reloadAllRef = useRef(reloadAll);
    reloadAllRef.current = reloadAll;

    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        const request = {};
        detailRequest.current = request;
        const isCurrent = () => detailRequest.current === request && selectedIdRef.current === selectedId;
        if (!selectedId) {
            setDetailState({ id: null, status: 'idle', data: null, notice: null });
        } else {
            setDetailState({ id: selectedId, status: 'loading', data: null, notice: null });
            const loadDetail = async () => {
                let data;
                try {
                    const response = await api.get(`/notification-center/${encodeURIComponent(selectedId)}`, { skipErrorToast: true });
                    data = response.data?.data;
                    if (!data) throw new Error('Notification detail unavailable');
                } catch {
                    if (isCurrent()) setDetailState({ id: selectedId, status: 'error', data: null, notice: null });
                    return;
                }
                if (!isCurrent()) return;
                setDetailState({ id: selectedId, status: 'ready', data, notice: null });

                // Attention updates are secondary: their failure must not erase loaded detail.
                try {
                    await api.post(`/notification-center/${encodeURIComponent(selectedId)}/read`, null, { skipErrorToast: true });
                } catch {
                    if (isCurrent()) setDetailState((current) => ({ ...current, notice: 'Notification loaded, but its read status could not be updated.' }));
                    return;
                }
                if (!isCurrent()) return;
                try {
                    await reloadAllRef.current();
                } catch {
                    if (isCurrent()) setDetailState((current) => ({ ...current, notice: 'Notification loaded, but the notification list could not be refreshed.' }));
                }
            };
            void loadDetail();
        }
        return () => {
            if (detailRequest.current === request) detailRequest.current = null;
        };
        // Opening one notification intentionally marks attention read.
    }, [selectedId, detailAttempt]);

    const markRead = async (id) => {
        await api.post(`/notification-center/${id}/read`, null, { skipErrorToast: true });
        await reloadAll();
    };
    const markUnread = async (id) => {
        await api.post(`/notification-center/${id}/unread`, null, { skipErrorToast: true });
        await reloadAll();
    };
    const markAllRead = async () => {
        await api.post('/notification-center/mark-all-read', null, { skipErrorToast: true });
        await reloadAll();
    };

    const retryDelivery = async (deliveryId) => {
        if (!selectedId) return;
        const request = detailRequest.current;
        const attempt = { selectedId, deliveryId };
        const isCurrent = () => detailRequest.current === request && selectedIdRef.current === selectedId;
        setRetryingDelivery(attempt);
        try {
            await api.post(`/notification-center/${selectedId}/deliveries/${deliveryId}/retry`, null, { skipErrorToast: true });
            if (!isCurrent()) return;
            const response = await api.get(`/notification-center/${selectedId}`, { skipErrorToast: true });
            if (!isCurrent()) return;
            setDetailState((current) => ({ ...current, data: response.data?.data || current.data }));
            showToast('Delivery queued for retry.', 'success');
        } catch (requestError) {
            if (isCurrent()) showToast(requestError.response?.data?.message || 'Could not queue delivery retry.', 'danger');
        } finally {
            setRetryingDelivery((current) => current === attempt ? null : current);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h2 className="h3 mb-1">Notification Center</h2>
                    <p className="text-muted small mb-0">Account-level conditions, events, and delivery activity.</p>
                </div>
                <button type="button" className="btn btn-outline-secondary btn-sm" onClick={markAllRead} disabled={!payload.meta?.unread_count}>Mark all as read</button>
            </div>

            <div className="nav nav-pills gap-1 mb-3" aria-label="Notification views">
                {VIEWS.map(([key, label]) => (
                    <button type="button" key={key} className={`nav-link${view === key ? ' active' : ''}`} onClick={() => setParams(key === 'all' ? {} : { view: key })}>{label}</button>
                ))}
            </div>

            <form className="row g-2 mb-3" role="search" aria-label="Filter notification history" onSubmit={(event) => { event.preventDefault(); setAppliedFilters(filters); }}>
                <div className="col-12 col-md-4"><label className="visually-hidden" htmlFor="notification-search">Search notifications</label><input id="notification-search" className="form-control" value={filters.q} onChange={(event) => setFilters({ ...filters, q: event.target.value })} placeholder="Search title or message" /></div>
                <div className="col-6 col-md-2"><label className="visually-hidden" htmlFor="notification-from">From date</label><input id="notification-from" type="date" className="form-control" value={filters.from} onChange={(event) => setFilters({ ...filters, from: event.target.value })} /></div>
                <div className="col-6 col-md-2"><label className="visually-hidden" htmlFor="notification-to">To date</label><input id="notification-to" type="date" className="form-control" value={filters.to} onChange={(event) => setFilters({ ...filters, to: event.target.value })} /></div>
                <div className="col-6 col-md-2"><label className="visually-hidden" htmlFor="notification-category">Category</label><input id="notification-category" className="form-control" value={filters.category} onChange={(event) => setFilters({ ...filters, category: event.target.value })} placeholder="Category" /></div>
                <div className="col-6 col-md-2"><label className="visually-hidden" htmlFor="notification-read-state">Read state</label><select id="notification-read-state" className="form-select" value={filters.read_state} onChange={(event) => setFilters({ ...filters, read_state: event.target.value })}><option value="">Any read state</option><option value="unread">Unread</option><option value="read">Read</option></select></div>
                <div className="col-6 col-md-2"><label className="visually-hidden" htmlFor="notification-channel">Channel</label><select id="notification-channel" className="form-select" value={filters.channel} onChange={(event) => setFilters({ ...filters, channel: event.target.value })}><option value="">Any channel</option><option value="email">Email</option><option value="telegram">Telegram</option><option value="webhook">Webhook</option></select></div>
                <div className="col-6 col-md-2"><label className="visually-hidden" htmlFor="notification-delivery-status">Delivery status</label><select id="notification-delivery-status" className="form-select" value={filters.delivery_status} onChange={(event) => setFilters({ ...filters, delivery_status: event.target.value })}><option value="">Any delivery status</option>{['queued', 'processing', 'delivered', 'failed', 'suppressed'].map((status) => <option key={status} value={status}>{status}</option>)}</select></div>
                <div className="col-12"><button className="btn btn-sm btn-primary" type="submit">Apply filters</button><button className="btn btn-sm btn-link" type="button" onClick={() => { const empty = { q: '', from: '', to: '', category: '', channel: '', delivery_status: '', read_state: '' }; setFilters(empty); setAppliedFilters(empty); }}>Clear</button></div>
            </form>

            {detailLoading && <DataState variant="loading" message="Loading notification details." className="mb-3" />}
            {selectedId && detailState.id === selectedId && detailState.status === 'error' && (
                <DataState variant="error" title="Notification details unavailable" message="This notification could not be loaded. It may no longer be available, or the request failed." className="mb-3" action={<button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setDetailAttempt((value) => value + 1)}>Retry notification</button>} />
            )}
            {selectedId && detail && (
                <section className="card mb-3" aria-label="Notification detail">
                    <div className="card-body">
                        {detailState.notice && <p className="alert alert-warning" role="alert">{detailState.notice}</p>}
                        <div className="d-flex justify-content-between gap-3">
                            <div>
                                <span className={`badge ${severityClass(detail.severity)}`}>{detail.severity.replace('_', ' ')}</span>
                                <h2 className="h5 mt-2">{detail.title}</h2>
                                <p>{detail.message}</p>
                            </div>
                            <button type="button" className="btn-close" aria-label="Close detail" onClick={() => setParams(view === 'all' ? {} : { view })} />
                        </div>
                        <div className="small text-muted mb-2">Condition: {detail.condition_state} · Occurrences: {detail.occurrence_count}</div>
                        {primaryAction && (
                            <p className="mb-3">
                                <Link className="btn btn-outline-primary btn-sm" to={primaryAction.route}>
                                    {primaryAction.label}
                                </Link>
                            </p>
                        )}
                        {detail.deliveries?.length > 0 && (
                            <section className="border-top pt-3 mt-3" aria-label="External delivery status">
                                <h3 className="h6">Delivery</h3>
                                <div className="list-group list-group-flush">
                                    {detail.deliveries.map((delivery) => {
                                        const status = delivery.status === 'suppressed'
                                            ? 'Suppressed because the condition was resolved.'
                                            : delivery.status.replace('_', ' ');
                                        return (
                                            <div className="list-group-item px-0" key={delivery.id}>
                                                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                    <div>
                                                        <strong className="text-capitalize">{delivery.channel}</strong>
                                                        <div className="small text-muted">{deliveryKindLabel(delivery.delivery_kind)} · {status}</div>
                                                        {delivery.attempt_count > 0 && <div className="small text-muted">{delivery.attempt_count} {delivery.attempt_count === 1 ? 'attempt' : 'attempts'}</div>}
                                                        {delivery.last_error_code && <div className="small text-danger">Provider error: {delivery.last_error_code}{delivery.last_response_status ? ` (${delivery.last_response_status})` : ''}</div>}
                                                        {delivery.delivered_at && <div className="small text-muted">Delivered {new Date(delivery.delivered_at).toLocaleString()}</div>}
                                                    </div>
                                                    {delivery.retryable && (
                                                        <button
                                                            type="button"
                                                            className="btn btn-outline-primary btn-sm"
                                                            aria-label={`Retry ${delivery.channel} delivery`}
                                                            disabled={retryingDelivery?.selectedId === selectedId && retryingDelivery?.deliveryId === delivery.id}
                                                            onClick={() => retryDelivery(delivery.id)}
                                                        >
                                                            {retryingDelivery?.selectedId === selectedId && retryingDelivery?.deliveryId === delivery.id ? 'Queuing…' : 'Retry'}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        )}
                        <ol className="small mb-0">
                            {(detail.timeline || []).map((entry) => <li key={entry.id}>{entry.activity_type.replace('_', ' ')} · {entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : '—'}</li>)}
                        </ol>
                    </div>
                </section>
            )}

            {loading ? <DataState variant="loading" message="Loading notification history." /> : error ? (
                <DataState variant="error" message="Notification history is unavailable right now." action={<button type="button" className="btn btn-sm btn-outline-primary" onClick={load}>Retry</button>} />
            ) : payload.data.length === 0 ? (
                <DataState variant="empty" title="No notifications in this view." />
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
                                <button type="button" className="btn btn-outline-secondary btn-sm align-self-start" onClick={() => item.attention_state === 'unread' ? markRead(item.id) : markUnread(item.id)}>{item.attention_state === 'unread' ? 'Mark as read' : 'Mark unread'}</button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
