import React, { useEffect, useState } from 'react';
import api from '../api';
import { tosData } from '../utils/tosEnvelope';
import { showToast } from '../toast';

export default function KiteReadinessCard({ executionMode }) {
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);
    const automatic = executionMode === 'automatic';

    useEffect(() => {
        if (!automatic) return;
        api.get('/v1/broker/status', { skipErrorToast: true })
            .then((response) => setStatus(tosData(response)))
            .catch(() => setStatus({ configured: false, usable: false }));
    }, [automatic]);

    if (!automatic || status == null || status.usable) return null;

    const connect = async () => {
        setBusy(true);
        try {
            const response = await api.get('/v1/broker/kite/login-url', {
                params: { return_to: 'dashboard' },
                skipErrorToast: true,
            });
            const url = tosData(response)?.url;
            if (url) window.location.assign(url);
        } catch (error) {
            showToast(error?.response?.data?.error?.message || 'Could not start Kite connection', 'danger');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-3 mb-0" role="alert">
            <div>
                <strong>Automatic execution is not ready</strong>
                <div className="small">{status.configured === false ? 'Kite is not configured on this server.' : 'Your daily Kite session is missing or expired.'}</div>
            </div>
            <button type="button" className="btn btn-danger btn-sm" disabled={busy || status.configured === false} onClick={connect}>
                {busy ? 'Opening Kite…' : 'Connect Kite'}
            </button>
        </div>
    );
}
