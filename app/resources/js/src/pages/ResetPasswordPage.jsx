import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { ensureCsrfCookie } from '../auth/csrf';
import { consumeRedirectPath } from '../auth/redirect';
import { showToast } from '../toast';

export default function ResetPasswordPage() {
    const { token } = useParams();
    const navigate = useNavigate();
    const { refreshUser } = useAuth();
    const [loading, setLoading] = useState(true);
    const [resetInfo, setResetInfo] = useState(null);
    const [error, setError] = useState('');
    const [form, setForm] = useState({
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
                const res = await api.get(`/reset-password/${token}`, { skipErrorToast: true });
                if (!cancelled) {
                    setResetInfo(res.data.data);
                }
            } catch (err) {
                if (!cancelled) {
                    setResetInfo(null);
                    setError(
                        err?.response?.data?.message
                        || 'This password reset link is invalid or has expired. Please contact your administrator for a new link.',
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
            await api.post('/reset-password/accept', {
                token,
                password: form.password,
                password_confirmation: form.password_confirmation,
                remember: form.remember,
            });
            await refreshUser();
            showToast('Password updated. Welcome back!');
            navigate(consumeRedirectPath() || '/');
        } catch (err) {
            setError(
                err?.response?.data?.message
                || err?.response?.data?.errors?.password?.[0]
                || err?.response?.data?.errors?.token?.[0]
                || 'Failed to reset password.',
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="login-shell px-3">
            <div className="login-card shadow p-4">
                <p className="text-muted small text-center mb-4">Indian stock portfolio tracker</p>
                <h2 className="h6 mb-3 text-center">Set a new password</h2>

                {loading ? (
                    <div className="text-center py-4">
                        <div className="spinner-border text-info" role="status" />
                        <p className="text-muted small mt-3 mb-0">Checking reset link…</p>
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
                            <label className="form-label">Account</label>
                            <input
                                type="text"
                                className="form-control"
                                value={resetInfo?.name
                                    ? `${resetInfo.name} (${resetInfo.email})`
                                    : (resetInfo?.email || '')}
                                readOnly
                                disabled
                            />
                        </div>
                        <div className="mb-3">
                            <label className="form-label">New password</label>
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
                            <label className="form-label">Confirm new password</label>
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
                                id="resetRememberMe"
                                checked={form.remember}
                                onChange={(e) => setForm({ ...form, remember: e.target.checked })}
                            />
                            <label className="form-check-label" htmlFor="resetRememberMe">
                                Remember me on this device
                            </label>
                        </div>
                        {error && <div className="alert alert-danger py-2">{error}</div>}
                        <button className="btn btn-info w-100" type="submit" disabled={submitting}>
                            {submitting ? 'Saving…' : 'Update password & sign in'}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}
