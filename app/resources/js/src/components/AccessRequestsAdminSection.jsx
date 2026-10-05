import React, { useCallback, useEffect, useState } from 'react';
import api from '../api';
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

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [reqRes, banRes] = await Promise.all([
                api.get('/access-requests', { params: { pending_only: !showAll } }),
                api.get('/access-request-bans'),
            ]);
            setRequests(reqRes.data?.data || []);
            setPendingCount(reqRes.data?.pending_count ?? 0);
            setBans((banRes.data?.data || []).filter((b) => b.active));
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to load access requests', 'danger');
        } finally {
            setLoading(false);
        }
    }, [showAll]);

    useEffect(() => {
        load();
    }, [load]);

    const openDetail = async (row) => {
        setSelected(row);
        setDetail(null);
        try {
            const res = await api.get(`/access-requests/${row.id}`);
            setDetail(res.data);
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to load request detail', 'danger');
        }
    };

    const act = async (row, action, body = {}) => {
        setBusyId(row.id);
        try {
            await api.post(`/access-requests/${row.id}/${action}`, body);
            showToast('Request updated');
            setSelected(null);
            setDetail(null);
            await load();
        } catch (error) {
            showToast(error?.response?.data?.message || 'Action failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    const clearBan = async (ban) => {
        setBusyId(`ban-${ban.id}`);
        try {
            await api.post(`/access-request-bans/${ban.id}/clear`);
            showToast('Ban cleared');
            await load();
        } catch (error) {
            showToast(error?.response?.data?.message || 'Could not clear ban', 'danger');
        } finally {
            setBusyId(null);
        }
    };

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
        <div className="card mb-3">
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
                {loading ? (
                    <div className="text-muted">Loading access requests…</div>
                ) : requests.length === 0 ? (
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
                                    <th />
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
                                                        disabled={busyId === row.id}
                                                        onClick={() => {
                                                            if (row.verification_status !== 'verified' && !window.confirm('This email address has not been verified. Approve this request anyway?')) return;
                                                            act(row, 'create-invite', row.verification_status === 'unverified' ? { confirm_unverified: true } : {});
                                                        }}
                                                    >
                                                        Create invite
                                                    </button>
                                                    {row.verification_status === 'unverified' ? <button type="button" className="btn btn-sm btn-outline-primary me-1" disabled={busyId === row.id} onClick={() => act(row, 'resend-verification')}>Resend verification</button> : null}
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-outline-secondary me-1"
                                                        disabled={busyId === row.id}
                                                        onClick={() => {
                                                            const reason = window.prompt(
                                                                'Optional internal reason (not shown to the applicant):',
                                                            );
                                                            if (reason === null) {
                                                                return;
                                                            }
                                                            const trimmed = reason.trim();
                                                            act(row, 'ignore', trimmed ? { reason: trimmed } : {});
                                                        }}
                                                    >
                                                        Ignore
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-outline-danger"
                                                        disabled={busyId === row.id}
                                                        onClick={() => {
                                                            const reason = window.prompt(
                                                                'Optional internal reason (not shown to the applicant):',
                                                            );
                                                            if (reason === null) {
                                                                return;
                                                            }
                                                            const trimmed = reason.trim();
                                                            act(row, 'reject', trimmed ? { reason: trimmed } : {});
                                                        }}
                                                    >
                                                        Reject
                                                    </button>
                                                </>
                                            ) : null}
                                            {row.status === 'created' ? <><button type="button" className="btn btn-sm btn-outline-primary me-1" onClick={() => copyInvitation(row)}>Copy invitation email</button><button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => copyInvitation(row, 'invite_url')}>Copy invitation link</button></> : null}
                                            {row.status === 'created' && row.invite_email_delivery_status === 'failed' ? <button type="button" className="btn btn-sm btn-outline-warning ms-1" onClick={async () => { try { await api.post(`/invites/${row.user_invite_id}/retry-email`); showToast('Invitation email queued for retry.'); await load(); } catch (error) { showToast(error?.response?.data?.message || 'Email retry failed.', 'danger'); } }}>Retry email</button> : null}
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
                                        disabled={busyId === `ban-${ban.id}`}
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
            {selected && detail ? (
                <div className="card-footer">
                    <h3 className="h6">Review — {detail.request?.email}</h3>
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
                    <button type="button" className="btn btn-sm btn-link mt-2" onClick={() => { setSelected(null); setDetail(null); }}>
                        Close
                    </button>
                </div>
            ) : null}
        </div>
    );
}
