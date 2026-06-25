import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { ensureCsrfCookie } from '../auth/csrf';
import { consumeRedirectPath } from '../auth/redirect';
import { showToast } from '../toast';

export default function AcceptInvitePage() {
    const { token } = useParams();
    const navigate = useNavigate();
    const { refreshUser } = useAuth();
    const [loading, setLoading] = useState(true);
    const [invite, setInvite] = useState(null);
    const [error, setError] = useState('');
    const [form, setForm] = useState({
        name: '',
        password: '',
        password_confirmation: '',
        remember: true,
    });
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        let cancelled = false;

        (async () => {
            setLoading(true);
            setError('');
            try {
                const res = await api.get(`/invites/${token}`, { skipErrorToast: true });
                if (!cancelled) {
                    setInvite(res.data.data);
                }
            } catch (err) {
                if (!cancelled) {
                    setInvite(null);
                    setError(
                        err?.response?.data?.message
                        || 'This invite link is invalid or has expired. Please contact your administrator for a new invite.',
                    );
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [token]);

    const submit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setError('');
        try {
            await ensureCsrfCookie({ force: true });
            await api.post('/invites/accept', {
                token,
                name: form.name,
                password: form.password,
                password_confirmation: form.password_confirmation,
                remember: form.remember,
            });
            await refreshUser();
            showToast('Account created. Welcome!');
            navigate(consumeRedirectPath() || '/');
        } catch (err) {
            setError(
                err?.response?.data?.message
                || err?.response?.data?.errors?.password?.[0]
                || err?.response?.data?.errors?.token?.[0]
                || 'Failed to complete invite setup.',
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="login-shell px-3">
            <div className="login-card shadow p-4">
                <p className="text-muted small text-center mb-4">Indian stock portfolio tracker</p>
                <h2 className="h6 mb-3 text-center">Set your password</h2>

                {loading ? (
                    <div className="text-center py-4">
                        <div className="spinner-border text-info" role="status" />
                        <p className="text-muted small mt-3 mb-0">Checking invite…</p>
                    </div>
                ) : error ? (
                    <div>
                        <div className="alert alert-warning py-2">{error}</div>
                        <Link className="btn btn-outline-secondary w-100" to="/">
                            Back to sign in
                        </Link>
                    </div>
                ) : (
                    <form onSubmit={submit}>
                        <div className="mb-3">
                            <label className="form-label">Email</label>
                            <input
                                type="email"
                                className="form-control"
                                value={invite?.email || ''}
                                readOnly
                                disabled
                            />
                        </div>
                        <div className="mb-3">
                            <label className="form-label">Your name</label>
                            <input
                                type="text"
                                className="form-control"
                                autoComplete="name"
                                value={form.name}
                                onChange={(e) => setForm({ ...form, name: e.target.value })}
                                placeholder={invite?.email?.split('@')[0] || 'Your name'}
                            />
                        </div>
                        <div className="mb-3">
                            <label className="form-label">Password</label>
                            <input
                                type="password"
                                className="form-control"
                                required
                                minLength={8}
                                autoComplete="new-password"
                                value={form.password}
                                onChange={(e) => setForm({ ...form, password: e.target.value })}
                            />
                        </div>
                        <div className="mb-3">
                            <label className="form-label">Confirm password</label>
                            <input
                                type="password"
                                className="form-control"
                                required
                                minLength={8}
                                autoComplete="new-password"
                                value={form.password_confirmation}
                                onChange={(e) => setForm({
                                    ...form,
                                    password_confirmation: e.target.value,
                                })}
                            />
                        </div>
                        <div className="form-check mb-3">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                id="inviteRememberMe"
                                checked={form.remember}
                                onChange={(e) => setForm({ ...form, remember: e.target.checked })}
                            />
                            <label className="form-check-label" htmlFor="inviteRememberMe">
                                Remember me on this device
                            </label>
                        </div>
                        {error && <div className="alert alert-danger py-2">{error}</div>}
                        <button className="btn btn-info w-100" type="submit" disabled={submitting}>
                            {submitting ? 'Creating account…' : 'Create account & sign in'}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}
