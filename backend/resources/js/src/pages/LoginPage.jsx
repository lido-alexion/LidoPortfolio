import React, { useState } from 'react';
import { useAuth } from '../context/AuthContext';

export default function LoginPage() {
    const { login, register, sessionExpired, consumeRedirectPath } = useAuth();
    const [form, setForm] = useState({ email: '', password: '', remember: true });
    const [isRegister, setIsRegister] = useState(false);
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setMessage('');
        setSubmitting(true);
        try {
            if (isRegister) {
                await register({
                    name: form.email.split('@')[0] || 'User',
                    email: form.email,
                    password: form.password,
                    password_confirmation: form.password,
                    remember: form.remember,
                });
            } else {
                await login({
                    email: form.email,
                    password: form.password,
                    remember: form.remember,
                });
            }
            window.location.href = consumeRedirectPath();
        } catch (error) {
            setMessage(error?.response?.data?.message
                || error?.response?.data?.errors?.email?.[0]
                || 'Authentication failed');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="login-shell px-3">
            <div className="login-card shadow p-4">
                <p className="text-muted small text-center mb-4">Indian stock portfolio tracker</p>
                <h2 className="h6 mb-3 text-center">{isRegister ? 'Register' : 'Login'}</h2>
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
                                    autoComplete={isRegister ? 'new-password' : 'current-password'}
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
                                {submitting ? 'Please wait…' : (isRegister ? 'Register + Login' : 'Login')}
                            </button>
                        </form>
                <button
                    type="button"
                    className="btn btn-link mt-2 p-0 text-info login-register-toggle"
                    onClick={() => setIsRegister(!isRegister)}
                >
                    {isRegister ? 'Already have an account? Login' : 'Need an account? Register'}
                </button>
            </div>
        </div>
    );
}
