import React, { useState } from 'react';
import api from '../api';
import { runApiMutation } from '../hooks/useApiMutation';
import { tosData } from '../utils/tosEnvelope';

/** Account-tab StoX execution-code enrollment and backup-code management. */
export default function TotpSettingsPanel() {
    const [status, setStatus] = useState(null);
    const [enrollment, setEnrollment] = useState(null);
    const [recoveryCodes, setRecoveryCodes] = useState(null);
    const [code, setCode] = useState('');
    const [appName, setAppName] = useState('');
    const [busy, setBusy] = useState(false);

    const load = async () => {
        const res = await api.get('/v1/totp', { skipErrorToast: true });
        setStatus(tosData(res) || res.data?.data || null);
    };

    React.useEffect(() => {
        load().catch(() => setStatus({ enabled: false, pending: false, authenticator_app_name: 'Authenticator app' }));
    }, []);

    const begin = async () => {
        setBusy(true);
        try {
            const { ok, data } = await runApiMutation(async () => {
                const res = await api.post('/v1/totp/begin', {}, { skipErrorToast: true });
                return tosData(res);
            }, { errorFallback: 'Could not start StoX execution-code setup' });
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
                const res = await api.post('/v1/totp/confirm', {
                    code,
                    authenticator_app_name: appName.trim() || undefined,
                }, { skipErrorToast: true });
                return tosData(res);
            }, { successMessage: 'StoX execution code enabled', errorFallback: `Invalid ${setupCodeLabel}` });
            if (ok) {
                setRecoveryCodes(data?.recovery_codes || []);
                setEnrollment(null);
                setCode('');
                setAppName('');
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
            }, { successMessage: 'StoX execution code disabled', errorFallback: `Could not verify ${executionCodeLabel}` });
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
    const executionCodeLabel = status?.execution_code_label
        || `StoX execution code — ${status?.authenticator_app_name || 'Authenticator app'}`;
    const setupCodeLabel = `StoX execution code — ${appName.trim() || 'Authenticator app'}`;

    return (
        <div className="card">
            <div className="card-header">StoX execution code</div>
            <div className="card-body">
                <p className="text-muted small">
                    {enabled ? executionCodeLabel : setupCodeLabel} is the rotating code required for Semi-Automatic execution and Emergency Halt recovery.
                    Supported apps include Google Authenticator and Microsoft Authenticator; you may enter another display name.
                    StoX recovery codes are separate, one-time backup codes. Kite login OTPs are entered only on Kite/Zerodha.
                </p>
                {enabled ? (
                    <form className="row g-2 align-items-end" onSubmit={disable}>
                        <div className="col-12">
                            <span className="badge text-bg-success">Enabled</span>
                            <span className="small text-muted ms-2">{executionCodeLabel}</span>
                        </div>
                        <div className="col-md-5">
                            <label className="form-label" htmlFor="totp-disable">{executionCodeLabel}</label>
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
                                Set up StoX execution code
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
                                    Scan the QR code, or enter this secret in your authenticator app. StoX stores the app display name, never the entered code.
                                </p>
                                <div className="row g-2 align-items-end">
                                    <div className="col-md-4">
                                        <label className="form-label" htmlFor="totp-app-name">Authenticator app name</label>
                                        <input
                                            id="totp-app-name"
                                            className="form-control"
                                            value={appName}
                                            onChange={(e) => setAppName(e.target.value)}
                                            maxLength={80}
                                            placeholder="Google Authenticator"
                                            autoComplete="organization"
                                        />
                                    </div>
                                    <div className="col-md-4">
                                        <label className="form-label" htmlFor="totp-confirm">{setupCodeLabel}</label>
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
                        <strong>StoX recovery codes (save these one-time backup codes now):</strong>
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
