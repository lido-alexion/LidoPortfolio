import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { getApiErrorMessage } from '../api';
import TurnstileWidget from '../components/TurnstileWidget';

const buildTurnstileSiteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY || '';

export default function RequestAccountPage() {
    const [form, setForm] = useState({ full_name: '', email: '' });
    const [turnstileSiteKey, setTurnstileSiteKey] = useState(buildTurnstileSiteKey);
    const [captchaConfigLoaded, setCaptchaConfigLoaded] = useState(Boolean(buildTurnstileSiteKey));
    const [captchaToken, setCaptchaToken] = useState('');
    const [message, setMessage] = useState('');
    const [success, setSuccess] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const onCaptchaExpire = useCallback(() => setCaptchaToken(''), []);
    const captchaRequired = Boolean(turnstileSiteKey) || import.meta.env.PROD;

    useEffect(() => {
        if (buildTurnstileSiteKey) {
            return;
        }
        api.get('/auth/access-requests/config')
            .then((res) => setTurnstileSiteKey(res.data?.data?.turnstile_site_key || ''))
            .catch(() => {})
            .finally(() => setCaptchaConfigLoaded(true));
    }, []);

    const submit = async (e) => {
        e.preventDefault();
        setMessage('');
        if (captchaRequired && !captchaConfigLoaded) {
            setMessage('Loading human verification. Please try again in a moment.');
            return;
        }
        if (captchaRequired && !turnstileSiteKey) {
            setMessage('Human verification is temporarily unavailable. Please try again later.');
            return;
        }
        if (captchaRequired && !captchaToken) {
            setMessage('Please complete human verification before submitting.');
            return;
        }
        setSubmitting(true);
        try {
            const token = turnstileSiteKey ? captchaToken : 'local-dev-bypass';
            const res = await api.post('/auth/access-requests', {
                ...form,
                captcha_token: token,
            });
            setSuccess(true);
            setMessage(res.data?.message || 'Check your email for a verification link.');
        } catch (error) {
            setMessage(getApiErrorMessage(error, 'Could not submit your request. Please try again.'));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="login-shell px-3">
            <div className="login-card shadow p-4">
                <h2 className="h6 mb-3 text-center">Request an account</h2>
                <p className="text-muted small">
                    StoX is invite-only. Submit your name and email to request access. You must verify your email before an administrator can review your request.
                </p>
                {success ? (
                    <div className="alert alert-success py-2">{message}</div>
                ) : (
                    <form onSubmit={submit}>
                        <div className="mb-3">
                            <label className="form-label" htmlFor="access-full-name">Full name</label>
                            <input
                                id="access-full-name"
                                className="form-control"
                                required
                                maxLength={255}
                                value={form.full_name}
                                onChange={(e) => setForm({ ...form, full_name: e.target.value })}
                            />
                        </div>
                        <div className="mb-3">
                            <label className="form-label" htmlFor="access-email">Email</label>
                            <input
                                id="access-email"
                                type="email"
                                className="form-control"
                                required
                                autoComplete="email"
                                value={form.email}
                                onChange={(e) => setForm({ ...form, email: e.target.value })}
                            />
                        </div>
                        {turnstileSiteKey ? (
                            <TurnstileWidget
                                siteKey={turnstileSiteKey}
                                onToken={setCaptchaToken}
                                onExpire={onCaptchaExpire}
                            />
                        ) : !captchaRequired ? (
                            <p className="small text-warning mb-3">
                                Human verification is not configured in this environment.
                            </p>
                        ) : null}
                        {!success && message && <div className="alert alert-danger py-2">{message}</div>}
                        <button className="btn btn-primary w-100" type="submit" disabled={submitting}>
                            {submitting ? 'Please wait…' : 'Send verification email'}
                        </button>
                    </form>
                )}
                <p className="text-center mt-3 mb-0 small">
                    <Link to="/login">Back to login</Link>
                </p>
            </div>
        </div>
    );
}
