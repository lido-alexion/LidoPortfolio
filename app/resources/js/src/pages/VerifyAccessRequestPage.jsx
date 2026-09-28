import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api';
import { getApiErrorMessage } from '../api';

export default function VerifyAccessRequestPage() {
    const { token } = useParams();
    const [message, setMessage] = useState('');
    const [status, setStatus] = useState('loading');

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                const res = await api.post(`/auth/access-requests/verify/${token}`);
                if (!cancelled) {
                    setStatus(res.data?.status || 'ok');
                    setMessage(res.data?.message || 'Email verified.');
                }
            } catch (error) {
                if (!cancelled) {
                    setStatus('error');
                    setMessage(getApiErrorMessage(error, 'This verification link is invalid or has expired.'));
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [token]);

    const alertClass = status === 'error' ? 'alert-danger' : 'alert-success';

    return (
        <div className="login-shell px-3">
            <div className="login-card shadow p-4 text-center">
                <h2 className="h6 mb-3">Verify email</h2>
                {status === 'loading' ? (
                    <p className="text-muted">Verifying your email…</p>
                ) : (
                    <div className={`alert ${alertClass} py-2`}>{message}</div>
                )}
                <Link to="/login" className="small">Back to login</Link>
            </div>
        </div>
    );
}
