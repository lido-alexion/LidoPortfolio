import React, { useEffect, useState } from 'react';
import api from '../../api';
import NumberInput from '../NumberInput';

/**
 * Per-portfolio India VIX threshold alert controls.
 */
export default function IndiaVixAlertSettings() {
    const [enabled, setEnabled] = useState(true);
    const [threshold, setThreshold] = useState('20');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        api.get('/settings')
            .then((res) => {
                if (cancelled) {
                    return;
                }
                const data = res.data?.data || {};
                setEnabled(data.indiavix_alert_enabled !== 'false');
                setThreshold(String(data.indiavix_alert_threshold ?? '20'));
            })
            .catch(() => {
                if (!cancelled) {
                    setError('Could not load VIX alert settings.');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });
        return () => { cancelled = true; };
    }, []);

    const save = async (nextEnabled, nextThreshold) => {
        setSaving(true);
        setMessage('');
        setError('');
        try {
            const res = await api.put('/settings', {
                indiavix_alert_enabled: nextEnabled ? 'true' : 'false',
                indiavix_alert_threshold: nextThreshold,
            });
            const data = res.data?.data || {};
            setEnabled(data.indiavix_alert_enabled !== 'false');
            setThreshold(String(data.indiavix_alert_threshold ?? nextThreshold));
            setMessage('VIX alert settings saved.');
        } catch {
            setError('Failed to save VIX alert settings.');
        } finally {
            setSaving(false);
        }
    };

    const onToggle = (checked) => {
        setEnabled(checked);
        save(checked, threshold);
    };

    const onThresholdBlur = () => {
        const parsed = Number(threshold);
        if (!Number.isFinite(parsed) || parsed < 1 || parsed > 100) {
            setError('Threshold must be between 1 and 100.');
            return;
        }
        save(enabled, String(parsed));
    };

    return (
        <div className="indices-vix-alert mt-3">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <h3 className="h6 mb-0">India VIX alert</h3>
                <div className="form-check form-switch mb-0">
                    <input
                        className="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="indiavix-alert-enabled"
                        checked={enabled}
                        disabled={loading || saving}
                        onChange={(e) => onToggle(e.target.checked)}
                    />
                    <label className="form-check-label" htmlFor="indiavix-alert-enabled">
                        {enabled ? 'Enabled' : 'Disabled'}
                    </label>
                </div>
            </div>
            <p className="small text-muted mb-3">
                When enabled, notifies you the first time India VIX closes above your threshold
                (if notifications are configured for this portfolio). Alerts re-arm after VIX
                falls back to or below the threshold.
            </p>
            <div className="indices-vix-alert-threshold">
                <label className="form-label small mb-1" htmlFor="indiavix-alert-threshold">
                    Alert when VIX above
                </label>
                <NumberInput
                    id="indiavix-alert-threshold"
                    value={threshold}
                    min={1}
                    max={100}
                    step={0.1}
                    disabled={loading || saving || !enabled}
                    onChange={(e) => setThreshold(e.target.value)}
                    onBlur={onThresholdBlur}
                />
            </div>
            {message ? <p className="small text-success mb-0 mt-2">{message}</p> : null}
            {error ? <p className="small text-danger mb-0 mt-2">{error}</p> : null}
        </div>
    );
}
