import React, { useEffect, useState } from 'react';
import api from '../api';
import { runApiMutation } from '../hooks/useApiMutation';
import { tosData } from '../utils/tosEnvelope';

const MODE_LABELS = {
    manual: 'Manual',
    semi_automatic: 'Semi-Automatic',
    automatic: 'Automatic',
};

export default function ExecutionModePanel() {
    const [snap, setSnap] = useState(null);
    const [mode, setMode] = useState('manual');
    const [totp, setTotp] = useState('');
    const [confirmAutomatic, setConfirmAutomatic] = useState(false);
    const [busy, setBusy] = useState(false);

    const load = async () => {
        const res = await api.get('/v1/execution/mode', { skipErrorToast: true });
        const data = tosData(res);
        setSnap(data);
        setMode(data?.execution_mode || 'manual');
    };

    useEffect(() => {
        load().catch(() => setSnap({ execution_mode: 'manual', blockers: ['unavailable'] }));
    }, []);

    const save = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const { ok } = await runApiMutation(async () => {
                const res = await api.put('/v1/execution/mode', {
                    execution_mode: mode,
                    confirm_automatic: confirmAutomatic,
                    totp: totp || undefined,
                }, { skipErrorToast: true });
                return tosData(res);
            }, { successMessage: 'Execution mode saved', errorFallback: 'Could not change execution mode' });
            if (ok) {
                setTotp('');
                setConfirmAutomatic(false);
                await load();
            }
        } finally {
            setBusy(false);
        }
    };

    const blockers = snap?.blockers || [];

    return (
        <div className="card">
            <div className="card-header">Execution mode</div>
            <div className="card-body">
                <p className="text-muted small">
                    This setting is per portfolio. New portfolios default to Manual (no broker submission).
                    Semi-Automatic and Automatic require admin entitlement, authenticator enrollment, and a Kite connection.
                    Switching Automatic → Manual stops future automatic submissions and does not cancel broker orders already sent.
                </p>
                <form className="row g-3" onSubmit={save}>
                    <div className="col-md-4">
                        <label className="form-label" htmlFor="execution-mode">Mode</label>
                        <select
                            id="execution-mode"
                            className="form-select"
                            value={mode}
                            onChange={(e) => setMode(e.target.value)}
                        >
                            {Object.entries(MODE_LABELS).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </div>
                    {mode !== 'manual' && (
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="execution-mode-totp">Authenticator code</label>
                            <input
                                id="execution-mode-totp"
                                className="form-control"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                value={totp}
                                onChange={(e) => setTotp(e.target.value)}
                            />
                        </div>
                    )}
                    {mode === 'automatic' && snap?.execution_mode !== 'automatic' && (
                        <div className="col-12">
                            <div className="form-check">
                                <input
                                    id="confirm-automatic"
                                    className="form-check-input"
                                    type="checkbox"
                                    checked={confirmAutomatic}
                                    onChange={(e) => setConfirmAutomatic(e.target.checked)}
                                />
                                <label className="form-check-label" htmlFor="confirm-automatic">
                                    I understand Automatic will submit eligible orders to Zerodha without per-order approval.
                                </label>
                            </div>
                        </div>
                    )}
                    <div className="col-12">
                        {blockers.length > 0 && mode !== 'manual' && (
                            <p className="small text-warning mb-2">
                                Broker submission is blocked:
                                {' '}
                                {blockers.join(', ')}
                            </p>
                        )}
                        {!snap?.entitled && (
                            <p className="small text-muted">Automated execution entitlement is off for this account (admin controlled).</p>
                        )}
                        <button type="submit" className="btn btn-primary" disabled={busy}>Save execution mode</button>
                    </div>
                </form>
            </div>
        </div>
    );
}
