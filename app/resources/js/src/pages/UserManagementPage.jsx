import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';
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
        case 'accepted':
        case 'used':
            return <span className="badge bg-success">{status === 'used' ? 'Used' : 'Accepted'}</span>;
        case 'expired':
            return <span className="badge bg-secondary">Expired</span>;
        default:
            return <span className="badge bg-secondary">{status}</span>;
    }
}

async function copyText(text, successMessage) {
    try {
        await navigator.clipboard.writeText(text);
        showToast(successMessage);
    } catch {
        showToast('Copy failed — select the text manually.', 'warning');
    }
}

function LinkActionButtons({
    busy,
    url,
    message,
    onRegenerate,
    onRevoke,
    linkLabel,
    messageLabel,
    regenerateLabel = 'Regenerate',
}) {
    const canManage = Boolean(onRegenerate || onRevoke);

    return (
        <>
            {url ? (
                <div className="d-flex flex-wrap gap-1 justify-content-end">
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        disabled={busy}
                        onClick={() => copyText(url, linkLabel)}
                    >
                        Copy link
                    </button>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary"
                        disabled={busy}
                        onClick={() => copyText(message, messageLabel)}
                    >
                        Copy message
                    </button>
                </div>
            ) : (
                <span className="text-muted small">—</span>
            )}
            {canManage ? (
                <div className="d-flex flex-wrap gap-1 justify-content-end mt-1">
                    {onRegenerate ? (
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary"
                            disabled={busy}
                            onClick={onRegenerate}
                        >
                            {regenerateLabel}
                        </button>
                    ) : null}
                    {onRevoke ? (
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-danger"
                            disabled={busy}
                            onClick={onRevoke}
                        >
                            Revoke
                        </button>
                    ) : null}
                </div>
            ) : null}
        </>
    );
}

function IssuedInviteBanner({ invite, onDismiss }) {
    if (!invite?.invite_url) {
        return null;
    }

    return (
        <div className="alert alert-info mb-3">
            <div className="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                <div>
                    <strong>Active invitation URL</strong>
                    <p className="small mb-2 mt-1">
                        Copy and save this URL now. It is shown only after create or regenerate.
                        Regenerating later will invalidate this URL; the previous link will stop working.
                        Expiry stays {formatDate(invite.expires_at)} (72 hours from original creation).
                    </p>
                    <code className="small d-block text-break mb-2">{invite.invite_url}</code>
                    <div className="d-flex flex-wrap gap-1">
                        <button
                            type="button"
                            className="btn btn-sm btn-primary"
                            onClick={() => copyText(invite.invite_url, 'Invitation URL copied')}
                        >
                            Copy Invitation URL
                        </button>
                        {invite.invite_message ? (
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-secondary"
                                onClick={() => copyText(invite.invite_message, 'Invite message copied')}
                            >
                                Copy message
                            </button>
                        ) : null}
                    </div>
                </div>
                {onDismiss ? (
                    <button type="button" className="btn-close" aria-label="Dismiss" onClick={onDismiss} />
                ) : null}
            </div>
        </div>
    );
}

export default function UserManagementPage() {
    const { user: currentUser } = useAuth();
    const [users, setUsers] = useState([]);
    const [invites, setInvites] = useState([]);
    const [issuedInvite, setIssuedInvite] = useState(null);
    const [resetLinks, setResetLinks] = useState([]);
    const [inviteEmail, setInviteEmail] = useState('');
    const [resetUserId, setResetUserId] = useState('');
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [updatingId, setUpdatingId] = useState(null);
    const [inviteBusyId, setInviteBusyId] = useState(null);
    const [resetBusyId, setResetBusyId] = useState(null);
    const [creatingInvite, setCreatingInvite] = useState(false);
    const [creatingResetLink, setCreatingResetLink] = useState(false);

    const loadAll = useCallback(async () => {
        setLoading(true);
        setLoadError('');
        try {
            const [usersRes, invitesRes, resetRes] = await Promise.all([
                api.get('/users'),
                api.get('/invites'),
                api.get('/password-reset-links'),
            ]);
            setUsers(usersRes.data.data || []);
            setInvites(invitesRes.data.data || []);
            setResetLinks(resetRes.data.data || []);
        } catch (error) {
            const msg = error?.response?.data?.message || 'Failed to load user management data';
            setLoadError(msg);
            setUsers([]);
            setInvites([]);
            setResetLinks([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadAll();
    }, [loadAll]);

    const createInvite = async (e) => {
        e.preventDefault();
        const email = inviteEmail.trim();
        if (!email) {
            return;
        }

        setCreatingInvite(true);
        try {
            const res = await api.post('/invites', { email });
            const created = res.data.data;
            setInviteEmail('');
            setIssuedInvite(created);
            setInvites((prev) => [
                { ...created, invite_url: null, invite_message: null, url_available: false },
                ...prev.filter((row) => row.id !== created.id),
            ]);
            showToast(res.data.message || 'Invite created — copy the invitation URL now');
        } catch (error) {
            const msg = error?.response?.data?.message
                || error?.response?.data?.errors?.email?.[0]
                || 'Failed to create invite';
            showToast(msg, 'danger');
        } finally {
            setCreatingInvite(false);
        }
    };

    const createResetLink = async (userId) => {
        const id = Number(userId);
        if (!id) {
            return;
        }

        setCreatingResetLink(true);
        try {
            const res = await api.post('/password-reset-links', { user_id: id });
            setResetLinks((prev) => [res.data.data, ...prev]);
            if (String(resetUserId) === String(id)) {
                setResetUserId('');
            }
            showToast('Password reset link created');
        } catch (error) {
            const msg = error?.response?.data?.message
                || error?.response?.data?.errors?.user_id?.[0]
                || 'Failed to create password reset link';
            showToast(msg, 'danger');
        } finally {
            setCreatingResetLink(false);
        }
    };

    const submitResetLink = async (e) => {
        e.preventDefault();
        await createResetLink(resetUserId);
    };

    const regenerateInvite = async (invite) => {
        const confirmed = window.confirm(
            'Regenerating the invitation will invalidate the current invitation URL.\n'
            + 'The previous URL will no longer work.\n\n'
            + 'Continue?'
        );
        if (!confirmed) {
            return;
        }

        setInviteBusyId(invite.id);
        try {
            const res = await api.post(`/invites/${invite.id}/regenerate`);
            const updated = res.data.data;
            setInvites((prev) => prev.map((row) => (
                row.id === invite.id ? { ...updated, invite_url: null, invite_message: null, url_available: false } : row
            )));
            setIssuedInvite(updated);
            showToast(res.data.message || 'Invitation URL regenerated — copy the new URL now');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to regenerate invite', 'danger');
        } finally {
            setInviteBusyId(null);
        }
    };

    const revokeInvite = async (invite) => {
        setInviteBusyId(invite.id);
        try {
            await api.delete(`/invites/${invite.id}`);
            setInvites((prev) => prev.filter((row) => row.id !== invite.id));
            setIssuedInvite((prev) => (prev?.id === invite.id ? null : prev));
            showToast('Invite revoked');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to revoke invite', 'danger');
        } finally {
            setInviteBusyId(null);
        }
    };

    const regenerateResetLink = async (link) => {
        setResetBusyId(link.id);
        try {
            const res = await api.post(`/password-reset-links/${link.id}/regenerate`);
            setResetLinks((prev) => prev.map((row) => (
                row.id === link.id ? res.data.data : row
            )));
            showToast('Password reset link regenerated (72 hours)');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to regenerate link', 'danger');
        } finally {
            setResetBusyId(null);
        }
    };

    const revokeResetLink = async (link) => {
        setResetBusyId(link.id);
        try {
            await api.delete(`/password-reset-links/${link.id}`);
            setResetLinks((prev) => prev.filter((row) => row.id !== link.id));
            showToast('Password reset link revoked');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to revoke link', 'danger');
        } finally {
            setResetBusyId(null);
        }
    };

    const toggleRole = async (targetUser) => {
        if (targetUser.id === currentUser?.id) {
            return;
        }

        const nextIsAdmin = !targetUser.is_admin;
        setUpdatingId(targetUser.id);
        try {
            const res = await api.put(`/users/${targetUser.id}/admin`, {
                is_admin: nextIsAdmin,
            });
            const updated = res.data.data;
            setUsers((prev) => prev.map((row) => (
                row.id === updated.id ? { ...row, ...updated } : row
            )));
            showToast(
                nextIsAdmin
                    ? `${updated.name || updated.email} is now an admin`
                    : `${updated.name || updated.email} is now a regular user`,
            );
        } catch (error) {
            const msg = error?.response?.data?.message
                || error?.response?.data?.errors?.is_admin?.[0]
                || 'Failed to update role';
            showToast(msg, 'danger');
        } finally {
            setUpdatingId(null);
        }
    };

    return (
        <div className="row g-3">
            <div className="col-12">
                <div className="card">
                    <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span>Invite new users</span>
                        <Link className="btn btn-sm btn-outline-secondary" to="/settings/account">
                            ← Back to settings
                        </Link>
                    </div>
                    <div className="card-body">
                        <p className="text-muted small">
                            Create a 72-hour invitation (expiry starts at creation). After create, copy the
                            invitation URL immediately — it is not stored for later re-copy. Use
                            <strong> Regenerate Invitation URL</strong> if the link is lost; that invalidates
                            the previous URL and does not extend the original expiry.
                        </p>
                        <form className="row g-2 align-items-end mb-3" onSubmit={createInvite}>
                            <div className="col-12 col-md-6">
                                <label className="form-label" htmlFor="invite-email">Email address</label>
                                <input
                                    id="invite-email"
                                    name="invite-email"
                                    type="email"
                                    className="form-control"
                                    required
                                    autoComplete="off"
                                    value={inviteEmail}
                                    onChange={(e) => setInviteEmail(e.target.value)}
                                    placeholder="user@example.com"
                                />
                            </div>
                            <div className="col-12 col-md-auto">
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                    disabled={creatingInvite}
                                >
                                    {creatingInvite ? 'Creating…' : 'Create invite'}
                                </button>
                            </div>
                        </form>
                        <IssuedInviteBanner
                            invite={issuedInvite}
                            onDismiss={() => setIssuedInvite(null)}
                        />
                        {loadError ? (
                            <div className="alert alert-danger py-2 small">{loadError}</div>
                        ) : null}
                        {loading ? (
                            <div className="text-muted">Loading invites…</div>
                        ) : invites.length === 0 ? (
                            <div className="text-muted">No invites yet.</div>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Email</th>
                                            <th>Status</th>
                                            <th>Expires</th>
                                            <th>Created</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invites.map((invite) => {
                                            const busy = inviteBusyId === invite.id;
                                            const canManage = invite.status === 'pending' || invite.status === 'expired';

                                            return (
                                                <tr key={invite.id}>
                                                    <td>{invite.email}</td>
                                                    <td>{statusBadge(invite.status)}</td>
                                                    <td>{formatDate(invite.expires_at)}</td>
                                                    <td>{formatDate(invite.created_at)}</td>
                                                    <td className="text-end">
                                                        {invite.status === 'pending' ? (
                                                            <p className="text-muted small mb-1 text-end">
                                                                Regenerating invalidates the current invitation URL.
                                                            </p>
                                                        ) : null}
                                                        <LinkActionButtons
                                                            busy={busy}
                                                            url={null}
                                                            message={null}
                                                            linkLabel="Invitation URL copied"
                                                            messageLabel="Invite message copied"
                                                            regenerateLabel="Regenerate Invitation URL"
                                                            onRegenerate={canManage ? () => regenerateInvite(invite) : null}
                                                            onRevoke={canManage ? () => revokeInvite(invite) : null}
                                                        />
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="col-12">
                <div className="card">
                    <div className="card-header">Password reset links</div>
                    <div className="card-body">
                        <p className="text-muted small">
                            For existing users who forgot their password. Create a 72-hour link so they can
                            set a new password without the old one.
                        </p>
                        <form className="row g-2 align-items-end mb-3" onSubmit={submitResetLink}>
                            <div className="col-12 col-md-6">
                                <label className="form-label" htmlFor="reset-user">User</label>
                                <select
                                    id="reset-user"
                                    className="form-select"
                                    value={resetUserId}
                                    onChange={(e) => setResetUserId(e.target.value)}
                                    required
                                >
                                    <option value="">Select a user…</option>
                                    {users.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name ? `${row.name} (${row.email})` : row.email}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-auto">
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                    disabled={creatingResetLink || loading || users.length === 0}
                                >
                                    {creatingResetLink ? 'Creating…' : 'Generate reset link'}
                                </button>
                            </div>
                        </form>
                        {loading ? (
                            <div className="text-muted">Loading reset links…</div>
                        ) : resetLinks.length === 0 ? (
                            <div className="text-muted">No password reset links yet.</div>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Status</th>
                                            <th>Expires</th>
                                            <th>Created</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {resetLinks.map((link) => {
                                            const busy = resetBusyId === link.id;
                                            const canManage = link.status === 'pending' || link.status === 'expired';

                                            return (
                                                <tr key={link.id}>
                                                    <td>
                                                        {link.user_name || '—'}
                                                        <div className="text-muted small">{link.email}</div>
                                                    </td>
                                                    <td>{statusBadge(link.status)}</td>
                                                    <td>{formatDate(link.expires_at)}</td>
                                                    <td>{formatDate(link.created_at)}</td>
                                                    <td className="text-end">
                                                        <LinkActionButtons
                                                            busy={busy}
                                                            url={link.status === 'pending' ? link.reset_url : null}
                                                            message={link.reset_message}
                                                            linkLabel="Reset link copied"
                                                            messageLabel="Reset message copied"
                                                            onRegenerate={canManage ? () => regenerateResetLink(link) : null}
                                                            onRevoke={canManage ? () => revokeResetLink(link) : null}
                                                        />
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="col-12">
                <div className="card">
                    <div className="card-header">Existing users</div>
                    <div className="card-body">
                        <p className="text-muted small">
                            Promote or demote accounts. You cannot change your own role.
                        </p>
                        {loading ? (
                            <div className="text-muted">Loading users…</div>
                        ) : users.length === 0 ? (
                            <div className="text-muted">No users found.</div>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Joined</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {users.map((row) => {
                                            const isSelf = row.id === currentUser?.id;
                                            const busy = updatingId === row.id;

                                            return (
                                                <tr key={row.id}>
                                                    <td>
                                                        {row.name || '—'}
                                                        {isSelf && (
                                                            <span className="badge bg-primary ms-2">You</span>
                                                        )}
                                                    </td>
                                                    <td>{row.email}</td>
                                                    <td>
                                                        {row.is_admin ? (
                                                            <span className="badge bg-dark">Admin</span>
                                                        ) : (
                                                            <span className="badge bg-secondary">User</span>
                                                        )}
                                                    </td>
                                                    <td>{formatDate(row.created_at)}</td>
                                                    <td className="text-end">
                                                        <div className="d-flex flex-wrap gap-1 justify-content-end">
                                                            <button
                                                                type="button"
                                                                className="btn btn-sm btn-outline-secondary"
                                                                disabled={creatingResetLink}
                                                                onClick={() => createResetLink(row.id)}
                                                            >
                                                                Reset password
                                                            </button>
                                                            {!isSelf ? (
                                                                <button
                                                                    type="button"
                                                                    className={`btn btn-sm ${row.is_admin ? 'btn-outline-secondary' : 'btn-outline-primary'}`}
                                                                    onClick={() => toggleRole(row)}
                                                                    disabled={busy}
                                                                >
                                                                    {busy
                                                                        ? 'Saving…'
                                                                        : row.is_admin
                                                                            ? 'Revoke admin access'
                                                                            : 'Make admin'}
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
