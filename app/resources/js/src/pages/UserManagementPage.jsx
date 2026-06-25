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
            return <span className="badge bg-success">Accepted</span>;
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

export default function UserManagementPage() {
    const { user: currentUser } = useAuth();
    const [users, setUsers] = useState([]);
    const [invites, setInvites] = useState([]);
    const [inviteEmail, setInviteEmail] = useState('');
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [updatingId, setUpdatingId] = useState(null);
    const [inviteBusyId, setInviteBusyId] = useState(null);
    const [creatingInvite, setCreatingInvite] = useState(false);

    const loadAll = useCallback(async () => {
        setLoading(true);
        setLoadError('');
        try {
            const [usersRes, invitesRes] = await Promise.all([
                api.get('/users'),
                api.get('/invites'),
            ]);
            setUsers(usersRes.data.data || []);
            setInvites(invitesRes.data.data || []);
        } catch (error) {
            const msg = error?.response?.data?.message || 'Failed to load user management data';
            setLoadError(msg);
            setUsers([]);
            setInvites([]);
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
            setInviteEmail('');
            setInvites((prev) => [res.data.data, ...prev]);
            showToast('Invite created');
        } catch (error) {
            const msg = error?.response?.data?.message
                || error?.response?.data?.errors?.email?.[0]
                || 'Failed to create invite';
            showToast(msg, 'danger');
        } finally {
            setCreatingInvite(false);
        }
    };

    const regenerateInvite = async (invite) => {
        setInviteBusyId(invite.id);
        try {
            const res = await api.post(`/invites/${invite.id}/regenerate`);
            setInvites((prev) => prev.map((row) => (
                row.id === invite.id ? res.data.data : row
            )));
            showToast('Invite link regenerated (72 hours)');
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
            showToast('Invite revoked');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to revoke invite', 'danger');
        } finally {
            setInviteBusyId(null);
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
                        <Link className="btn btn-sm btn-outline-secondary" to="/settings">
                            ← Back to settings
                        </Link>
                    </div>
                    <div className="card-body">
                        <p className="text-muted small">
                            Create a 72-hour invite link. Copy the message below and send it to the user.
                            They must set a password before they can sign in.
                        </p>
                        <form className="row g-2 align-items-end mb-3" onSubmit={createInvite}>
                            <div className="col-12 col-md-6">
                                <label className="form-label" htmlFor="invite-email">Email address</label>
                                <input
                                    id="invite-email"
                                    type="email"
                                    className="form-control"
                                    required
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
                                                        {invite.status === 'pending' && invite.invite_url ? (
                                                            <div className="d-flex flex-wrap gap-1 justify-content-end">
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-outline-primary"
                                                                    disabled={busy}
                                                                    onClick={() => copyText(
                                                                        invite.invite_url,
                                                                        'Invite link copied',
                                                                    )}
                                                                >
                                                                    Copy link
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-outline-secondary"
                                                                    disabled={busy}
                                                                    onClick={() => copyText(
                                                                        invite.invite_message,
                                                                        'Invite message copied',
                                                                    )}
                                                                >
                                                                    Copy message
                                                                </button>
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted small">—</span>
                                                        )}
                                                        {canManage ? (
                                                            <div className="d-flex flex-wrap gap-1 justify-content-end mt-1">
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-outline-secondary"
                                                                    disabled={busy}
                                                                    onClick={() => regenerateInvite(invite)}
                                                                >
                                                                    Regenerate
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-outline-danger"
                                                                    disabled={busy}
                                                                    onClick={() => revokeInvite(invite)}
                                                                >
                                                                    Revoke
                                                                </button>
                                                            </div>
                                                        ) : null}
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
                                                        {isSelf ? (
                                                            <span className="text-muted small">—</span>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className={`btn btn-sm ${row.is_admin ? 'btn-outline-secondary' : 'btn-outline-primary'}`}
                                                                onClick={() => toggleRole(row)}
                                                                disabled={busy}
                                                            >
                                                                {busy
                                                                    ? 'Saving…'
                                                                    : row.is_admin
                                                                        ? 'Make user'
                                                                        : 'Make admin'}
                                                            </button>
                                                        )}
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
