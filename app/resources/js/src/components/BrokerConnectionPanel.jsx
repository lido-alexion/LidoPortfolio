import React, { useEffect, useState } from 'react';
import api from '../api';
import { runApiMutation } from '../hooks/useApiMutation';
import { tosData } from '../utils/tosEnvelope';

export default function BrokerConnectionPanel() {
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = async () => {
        const res = await api.get('/v1/broker/status', { skipErrorToast: true });
        setStatus(tosData(res) || null);
    };

    useEffect(() => {
        load().catch(() => setStatus({ configured: false, connected: false, usable: false }));
    }, []);

    const connect = async () => {
        setBusy(true);
        try {
            const { ok, data } = await runApiMutation(async () => {
                const res = await api.get('/v1/broker/kite/login-url', { skipErrorToast: true });
                return tosData(res);
            }, { errorFallback: 'Kite is not configured on this server' });
            if (ok && data?.url) {
                window.location.assign(data.url);
            }
        } finally {
            setBusy(false);
        }
    };

    const disconnect = async () => {
        setBusy(true);
        try {
            const { ok } = await runApiMutation(async () => {
                await api.post('/v1/broker/kite/disconnect', {}, { skipErrorToast: true });
            }, { successMessage: 'Zerodha disconnected', errorFallback: 'Could not disconnect' });
            if (ok) {
                await load();
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="card">
            <div className="card-header">Zerodha / Kite</div>
            <div className="card-body">
                <p className="text-muted small">
                    Connect your own Kite account to submit orders in Semi-Automatic or Automatic mode.
                    Manual mode works without a broker connection. Kite sessions typically expire around 6:00 AM IST.
                </p>
                {status?.configured === false && (
                    <p className="small text-warning mb-2">Kite API keys are not configured on this server.</p>
                )}
                {status?.usable ? (
                    <p className="mb-2">
                        <span className="badge text-bg-success">Connected</span>
                        {status.expires_at && (
                            <span className="small text-muted ms-2">
                                Expires {new Date(status.expires_at).toLocaleString()}
                            </span>
                        )}
                    </p>
                ) : status?.connected ? (
                    <p className="mb-2"><span className="badge text-bg-warning">Session expired</span></p>
                ) : (
                    <p className="mb-2"><span className="badge text-bg-secondary">Not connected</span></p>
                )}
                <div className="d-flex flex-wrap gap-2">
                    <button type="button" className="btn btn-primary btn-sm" onClick={connect} disabled={busy || status?.configured === false}>
                        Connect Kite
                    </button>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={disconnect} disabled={busy || !status?.connected}>
                        Disconnect
                    </button>
                </div>
            </div>
        </div>
    );
}
