import React, { useState } from 'react';
import api from '../api';
import { runApiMutation } from '../hooks/useApiMutation';
import { tosData } from '../utils/tosEnvelope';

/**
 * Account-tab TOTP enrollment / disable (V4-FEAT-001).
 */
export default function TotpSettingsPanel() {
    const [status, setStatus] = useState(null);
    const [enrollment, setEnrollment] = useState(null);
    const [recoveryCodes, setRecoveryCodes] = useState(null);
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);

    const load = async () => {
        const res = await api.get('/v1/totp', { skipErrorToast: true });
        setStatus(tosData(res) || res.data?.data || null);
    };

    React.useEffect(() => {
        load().catch(() => setStatus({ enabled: false, pending: false }));
    }, []);

    const begin = async () => {
        setBusy(true);
        try {
            const { ok, data } = await runApiMutation(async () => {
                const res = await api.post('/v1/totp/begin', {}, { skipErrorToast: true });
                return tosData(res);
            }, { errorFallback: 'Could not start authenticator setup' });
            if (ok) {
                setEnrollment(data);
                setRecoveryCodes(null);
                await load();
            }
        } finally {
            setBusy(false);
        }
    };

    const confirm = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const { ok, data } = await runApiMutation(async () => {
                const res = await api.post('/v1/totp/confirm', { code }, { skipErrorToast: true });
                return tosData(res);
            }, { successMessage: 'Authenticator enabled', errorFallback: 'Invalid authenticator code' });
            if (ok) {
                setRecoveryCodes(data?.recovery_codes || []);
                setEnrollment(null);
                setCode('');
                await load();
            }
        } finally {
            setBusy(false);
        }
    };

    const disable = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const { ok } = await runApiMutation(async () => {
                await api.post('/v1/totp/disable', { code }, { skipErrorToast: true });
            }, { successMessage: 'Authenticator disabled', errorFallback: 'Could not disable authenticator' });
            if (ok) {
                setCode('');
                setRecoveryCodes(null);
                await load();
            }
        } finally {
            setBusy(false);
        }
    };

    const enabled = Boolean(status?.enabled);

    return (
        <div className="card">
            <div className="card-header">Authenticator (TOTP)</div>
            <div className="card-body">
                <p className="text-muted small">
                    Required before Lido can submit orders to Zerodha in Semi-Automatic or Automatic mode.
                    Manual execution does not need an authenticator. Compatible apps include Google Authenticator.
                    Save recovery codes when shown — they are shown once.
                </p>
                {enabled ? (
                    <form className="row g-2 align-items-end" onSubmit={disable}>
                        <div className="col-12">
                            <span className="badge text-bg-success">Enabled</span>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="totp-disable">Authenticator code</label>
                            <input
                                id="totp-disable"
                                className="form-control"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                value={code}
                                onChange={(e) => setCode(e.target.value)}
                                required
                            />
                        </div>
                        <div className="col-md-auto">
                            <button type="submit" className="btn btn-outline-danger" disabled={busy}>Disable</button>
                        </div>
                    </form>
                ) : (
                    <>
                        {!enrollment && (
                            <button type="button" className="btn btn-primary" onClick={begin} disabled={busy}>
                                Set up authenticator
                            </button>
                        )}
                        {enrollment && (
                            <form className="d-grid gap-2" onSubmit={confirm}>
                                {enrollment.qr_svg && (
                                    <div
                                        className="border rounded p-2 bg-white"
                                        style={{ maxWidth: 240 }}
                                        // QR SVG from the enrollment API (same-origin).
                                        dangerouslySetInnerHTML={{ __html: enrollment.qr_svg }}
                                    />
                                )}
                                <p className="small mb-0">
                                    Scan the QR code, or enter this secret in your authenticator:
                                    {' '}
                                    <code>{enrollment.secret}</code>
                                </p>
                                <div className="row g-2 align-items-end">
                                    <div className="col-md-4">
                                        <label className="form-label" htmlFor="totp-confirm">Authenticator code</label>
                                        <input
                                            id="totp-confirm"
                                            className="form-control"
                                            inputMode="numeric"
                                            autoComplete="one-time-code"
                                            value={code}
                                            onChange={(e) => setCode(e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="col-md-auto">
                                        <button type="submit" className="btn btn-primary" disabled={busy}>Confirm</button>
                                    </div>
                                </div>
                            </form>
                        )}
                    </>
                )}
                {Array.isArray(recoveryCodes) && recoveryCodes.length > 0 && (
                    <div className="alert alert-warning mt-3 mb-0">
                        <strong>Recovery codes (save these now):</strong>
                        <ul className="mb-0 mt-2">
                            {recoveryCodes.map((c) => (
                                <li key={c}><code>{c}</code></li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </div>
    );
}
