import React, { useEffect, useState } from 'react';
import api from '../api';
import { runApiMutation } from '../hooks/useApiMutation';
import { tosData } from '../utils/tosEnvelope';

function isBrokerStatus(value) {
    return value && typeof value === 'object'
        && typeof value.configured === 'boolean'
        && typeof value.connected === 'boolean'
        && typeof value.usable === 'boolean';
}

export default function BrokerConnectionPanel() {
    const [status, setStatus] = useState(null);
    const [statusState, setStatusState] = useState('loading');
    const [busy, setBusy] = useState(false);

    const load = async () => {
        setStatusState('loading');
        try {
            const res = await api.get('/v1/broker/status', { skipErrorToast: true });
            const nextStatus = tosData(res);
            if (!isBrokerStatus(nextStatus)) {
                setStatus(null);
                setStatusState('unavailable');
                return;
            }
            setStatus(nextStatus);
            setStatusState('confirmed');
        } catch {
            setStatus(null);
            setStatusState('unavailable');
        }
    };

    useEffect(() => {
        void load();
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
            }, { successMessage: 'Kite disconnected', errorFallback: 'Could not disconnect Kite' });
            if (ok) {
                setStatus(null);
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
                    If Kite asks for a Kite login OTP, enter it only on the Kite/Zerodha login page; StoX never uses that OTP as an execution code.
                </p>
                {statusState === 'loading' && (
                    <p className="mb-2" role="status">Checking Kite connection status…</p>
                )}
                {statusState === 'unavailable' && (
                    <div className="alert alert-warning" role="alert">
                        <p className="mb-2">Kite connection status is unavailable. Retry to check the current connection.</p>
                        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={busy}>
                            Retry status
                        </button>
                    </div>
                )}
                {statusState === 'confirmed' && status?.configured === false && (
                    <p className="small text-warning mb-2">Kite API keys are not configured on this server.</p>
                )}
                {statusState === 'confirmed' && status?.usable ? (
                    <p className="mb-2">
                        <span className="badge text-bg-success">Connected</span>
                        {status.expires_at && (
                            <span className="small text-muted ms-2">
                                Expires {new Date(status.expires_at).toLocaleString()}
                            </span>
                        )}
                    </p>
                ) : statusState === 'confirmed' && status?.connected ? (
                    <p className="mb-2"><span className="badge text-bg-warning">Session expired</span></p>
                ) : statusState === 'confirmed' ? (
                    <p className="mb-2"><span className="badge text-bg-secondary">Not connected</span></p>
                ) : null}
                <div className="d-flex flex-wrap gap-2">
                    <button type="button" className="btn btn-primary btn-sm" onClick={connect} disabled={busy || statusState !== 'confirmed' || status?.configured === false}>
                        Connect Kite
                    </button>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={disconnect} disabled={busy || statusState !== 'confirmed' || !status?.connected}>
                        Disconnect
                    </button>
                </div>
            </div>
        </div>
    );
}
