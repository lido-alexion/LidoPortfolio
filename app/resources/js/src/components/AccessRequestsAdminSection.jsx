import React, { useCallback, useEffect, useRef, useState } from 'react';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

function formatDate(value) {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleString();
}

function statusBadge(status) {
    switch (status) {
        case 'pending':
            return <span className="badge bg-primary">Pending</span>;
        case 'created':
            return <span className="badge bg-success">Created</span>;
        case 'ignored':
            return <span className="badge bg-secondary">Ignored</span>;
        case 'rejected':
            return <span className="badge bg-danger">Rejected</span>;
        case 'expired':
            return <span className="badge bg-secondary">Expired</span>;
        default:
            return <span className="badge bg-secondary">{status}</span>;
    }
}

export default function AccessRequestsAdminSection() {
    const [requests, setRequests] = useState([]);
    const [pendingCount, setPendingCount] = useState(0);
    const [bans, setBans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState(null);
    const [showAll, setShowAll] = useState(false);
    const [selected, setSelected] = useState(null);
    const [detail, setDetail] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const [detailError, setDetailError] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [actionError, setActionError] = useState(null);
    const readRequest = useRef(0);
    const detailRequest = useRef(0);
    const selectedRef = useRef(null);
    const actionBusy = useRef(false);
    const actionEpoch = useRef(0);
    const reasonDrafts = useRef(new Map());

    const load = useCallback(async () => {
        const request = ++readRequest.current;
        setLoading(true);
        setLoadError(null);
        try {
            const [reqRes, banRes] = await Promise.all([
                api.get('/access-requests', { params: { pending_only: !showAll } }),
                api.get('/access-request-bans'),
            ]);
            if (request !== readRequest.current) return;
            setRequests(reqRes.data?.data || []);
            setPendingCount(reqRes.data?.pending_count ?? 0);
            setBans((banRes.data?.data || []).filter((b) => b.active));
        } catch (error) {
            if (request === readRequest.current) setLoadError(`Access requests could not be loaded. ${getApiErrorMessage(error)}`);
        } finally {
            if (request === readRequest.current) setLoading(false);
        }
    }, [showAll]);

    useEffect(() => {
        load();
        return () => { readRequest.current++; };
    }, [load]);

    useEffect(() => () => { detailRequest.current++; actionEpoch.current++; }, []);

    const closeDetail = () => {
        detailRequest.current++;
        selectedRef.current = null;
        setSelected(null);
        setDetail(null);
        setDetailError(null);
        setDetailLoading(false);
    };

    const openDetail = async (row) => {
        const request = ++detailRequest.current;
        selectedRef.current = row;
        setSelected(row);
        setDetail(null);
        setDetailError(null);
        setDetailLoading(true);
        try {
            const res = await api.get(`/access-requests/${row.id}`);
            if (request === detailRequest.current) setDetail(res.data);
        } catch (error) {
            if (request === detailRequest.current) setDetailError(`Request details could not be loaded. ${getApiErrorMessage(error)}`);
        } finally {
            if (request === detailRequest.current) setDetailLoading(false);
        }
    };

    const mutate = async (busyKey, path, body = {}, message = 'Request updated', requestId = null, draftKey = null) => {
        if (actionBusy.current || loading || loadError) return;
        actionBusy.current = true;
        const epoch = actionEpoch.current;
        setBusyId(busyKey);
        setActionError(null);
        try {
            await api.post(path, body);
            if (epoch !== actionEpoch.current) return;
            if (draftKey) reasonDrafts.current.delete(draftKey);
            showToast(message);
            if (requestId !== null && selectedRef.current?.id === requestId) closeDetail();
            await load();
        } catch (error) {
            if (epoch !== actionEpoch.current) return;
            const message = getApiErrorMessage(error, 'Action failed');
            setActionError(message);
            showToast(message, 'danger');
        } finally {
            actionBusy.current = false;
            if (epoch === actionEpoch.current) setBusyId(null);
        }
    };

    const act = (row, action, body = {}) => mutate(row.id, `/access-requests/${row.id}/${action}`, body, 'Request updated', row.id, `${row.id}:${action}`);

    const decide = (row, action) => {
        if (actionBusy.current || loading || loadError) return;
        const key = `${row.id}:${action}`;
        const reason = window.prompt('Optional internal reason (not shown to the applicant):', reasonDrafts.current.get(key) || '');
        if (reason === null) return;
        const trimmed = reason.trim();
        reasonDrafts.current.set(key, trimmed);
        act(row, action, trimmed ? { reason: trimmed } : {});
    };

    const clearBan = ban => mutate(`ban-${ban.id}`, `/access-request-bans/${ban.id}/clear`, {}, 'Ban cleared');
    const retryInvitationEmail = row => mutate(row.id, `/invites/${row.user_invite_id}/retry-email`, {}, 'Invitation email queued for retry.');

    const copyInvitation = async (row, field = 'invite_message') => {
        try {
            const response = await api.get(`/access-requests/${row.id}/invitation-copy`);
            await navigator.clipboard.writeText(response.data?.data?.[field] || '');
            showToast(field === 'invite_url' ? 'Existing invitation link copied.' : 'Ready-to-send invitation email copied.', 'success');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Could not copy invitation email.', 'danger');
        }
    };

    return (
        <div className="card mb-3 lido-access-requests-admin" role="region" aria-label="Account access requests">
            <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    Account access requests
                    {pendingCount > 0 ? (
                        <span className="badge bg-danger ms-2">{pendingCount} pending</span>
                    ) : null}
                </span>
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setShowAll((v) => !v)}>
                    {showAll ? 'Show pending only' : 'Show all statuses'}
                </button>
            </div>
            <div className="card-body">
                <p className="text-muted small">
                    Requests appear here before email verification. Verification is advisory; approval issues the standard secure invitation.
                </p>
                {loadError && <div className="alert alert-danger" role="alert">{loadError} <button type="button" className="btn btn-sm btn-outline-danger" onClick={load}>Retry access requests</button></div>}
                {actionError && <div className="alert alert-danger" role="alert">{actionError}</div>}
                {loading ? (
                    <div className="text-muted">Loading access requests…</div>
                ) : requests.length === 0 && !loadError ? (
                    <div className="text-muted">No access requests in this view.</div>
                ) : (
                    <div className="table-responsive">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Verified</th>
                                    <th>Email delivery</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.map((row) => (
                                    <tr key={row.id}>
                                        <td>{row.full_name}</td>
                                        <td>{row.email}</td>
                                        <td>{statusBadge(row.status)}</td>
                                        <td><span className={`badge ${row.verification_status === 'verified' ? 'bg-success' : 'bg-warning text-dark'}`}>{row.verification_status === 'verified' ? 'Verified' : 'Unverified'}</span></td>
                                        <td className="small">{row.invite_email_delivery_status || row.verification_email_delivery_status || '—'}{(row.invite_email_last_error_code || row.verification_email_last_error_code) ? <span className="text-danger d-block">{row.invite_email_last_error_code || row.verification_email_last_error_code}</span> : null}</td>
                                        <td className="text-end">
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-primary me-1"
                                                onClick={() => openDetail(row)}
                                            >
                                                Review
                                            </button>
                                            {row.status === 'pending' ? (
                                                <>
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-success me-1"
                                                        disabled={busyId !== null || loading || Boolean(loadError)}
                                                        onClick={() => {
                                                            if (row.verification_status !== 'verified' && !window.confirm('This email address has not been verified. Approve this request anyway?')) return;
                                                            act(row, 'create-invite', row.verification_status === 'unverified' ? { confirm_unverified: true } : {});
                                                        }}
                                                    >
                                                        Create invite
                                                    </button>
                                                    {row.verification_status === 'unverified' ? <button type="button" className="btn btn-sm btn-outline-primary me-1" disabled={busyId !== null || loading || Boolean(loadError)} onClick={() => act(row, 'resend-verification')}>Resend verification</button> : null}
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-outline-secondary me-1"
                                                        disabled={busyId !== null || loading || Boolean(loadError)}
                                                        onClick={() => decide(row, 'ignore')}
                                                    >
                                                        Ignore
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-outline-danger"
                                                        disabled={busyId !== null || loading || Boolean(loadError)}
                                                        onClick={() => decide(row, 'reject')}
                                                    >
                                                        Reject
                                                    </button>
                                                </>
                                            ) : null}
                                            {row.status === 'created' ? <><button type="button" className="btn btn-sm btn-outline-primary me-1" onClick={() => copyInvitation(row)}>Copy invitation email</button><button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => copyInvitation(row, 'invite_url')}>Copy invitation link</button></> : null}
                                            {row.status === 'created' && row.invite_email_delivery_status === 'failed' ? <button type="button" className="btn btn-sm btn-outline-warning ms-1" disabled={busyId !== null || loading || Boolean(loadError)} onClick={() => retryInvitationEmail(row)}>Retry email</button> : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {bans.length > 0 ? (
                    <div className="mt-3">
                        <h3 className="h6">Active request bans</h3>
                        <ul className="list-group list-group-flush">
                            {bans.map((ban) => (
                                <li key={ban.id} className="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span>{ban.email}</span>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-outline-secondary"
                                        disabled={busyId !== null || loading || Boolean(loadError)}
                                        onClick={() => clearBan(ban)}
                                    >
                                        Clear ban
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
            {selected ? (
                <div className="card-footer">
                    <h3 className="h6">Review — {selected.email}</h3>
                    {detailLoading && <p role="status">Loading request details…</p>}
                    {detailError && <div className="alert alert-danger" role="alert">{detailError} <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => openDetail(selected)}>Retry request details</button></div>}
                    {detail && <>
                    {detail.ban ? (
                        <p className="small text-danger mb-2">Active ban until {formatDate(detail.ban.expires_at)}</p>
                    ) : null}
                    <h4 className="small fw-semibold">Prior requests (same email)</h4>
                    <ul className="small mb-3">
                        {(detail.history || []).map((h) => (
                            <li key={h.id}>
                                #{h.id} — {h.status} / {h.verification_status} — {formatDate(h.resolved_at || h.verified_at || h.created_at)}
                            </li>
                        ))}
                    </ul>
                    <h4 className="small fw-semibold">Audit trail</h4>
                    <ul className="small mb-0">
                        {(detail.audit_events || []).map((ev) => (
                            <li key={ev.id}>
                                {ev.event_type} — {formatDate(ev.created_at)}
                                {ev.actor_user_id ? ` (admin #${ev.actor_user_id})` : ''}
                            </li>
                        ))}
                    </ul>
                    </>}
                    <button type="button" className="btn btn-sm btn-link mt-2" onClick={closeDetail}>
                        Close
                    </button>
                </div>
            ) : null}
        </div>
    );
}
