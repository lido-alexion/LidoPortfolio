import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function LoginPage() {
    const { login, sessionExpired, consumeRedirectPath } = useAuth();
    const navigate = useNavigate();
    const [form, setForm] = useState({ email: '', password: '', remember: true });
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setMessage('');
        setSubmitting(true);
        try {
            await login({
                email: form.email,
                password: form.password,
                remember: form.remember,
            });
            navigate(consumeRedirectPath() || '/');
        } catch (error) {
            const data = error?.response?.data;
            if (data?.invite_setup_required && data?.invite_token) {
                navigate(`/invite/${data.invite_token}`);
                return;
            }
            setMessage(data?.message
                || data?.errors?.email?.[0]
                || error?.message
                || 'Authentication failed');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="login-shell px-3">
            <div className="login-card shadow p-4">
                <p className="text-muted small text-center mb-4">Indian stock portfolio tracker</p>
                <h2 className="h6 mb-3 text-center">Login</h2>
                {sessionExpired && (
                    <div className="alert alert-warning py-2">
                        Your session expired. Please sign in again.
                    </div>
                )}
                <form onSubmit={submit}>
                    <div className="mb-3">
                        <label className="form-label">Email</label>
                        <input
                            type="email"
                            className="form-control"
                            required
                            autoComplete="email"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                        />
                    </div>
                    <div className="mb-3">
                        <label className="form-label">Password</label>
                        <input
                            type="password"
                            className="form-control"
                            required
                            autoComplete="current-password"
                            value={form.password}
                            onChange={(e) => setForm({ ...form, password: e.target.value })}
                        />
                    </div>
                    <div className="form-check mb-3">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            id="rememberMe"
                            checked={form.remember}
                            onChange={(e) => setForm({ ...form, remember: e.target.checked })}
                        />
                        <label className="form-check-label" htmlFor="rememberMe">
                            Remember me on this device
                        </label>
                    </div>
                    {message && <div className="alert alert-danger py-2">{message}</div>}
                    <button className="btn btn-info w-100" type="submit" disabled={submitting}>
                        {submitting ? 'Please wait…' : 'Login'}
                    </button>
                </form>
                <p className="text-muted small mt-3 mb-0 text-center">
                    New accounts are invite-only. Contact your administrator if you need access.
                </p>
            </div>
        </div>
    );
}
