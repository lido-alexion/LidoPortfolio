import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

function formatTimestamp(value) {
    if (!value) {
        return '—';
    }
    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
}

function severityBadgeClass(severity) {
    return severity === 'critical' ? 'bg-danger' : 'bg-warning text-dark';
}

function alertCardClass(severity, acknowledged) {
    if (acknowledged) {
        return 'border-secondary opacity-75';
    }
    return severity === 'critical' ? 'border-danger' : 'border-warning';
}

export default function AdminAlertsPage() {
    const [alerts, setAlerts] = useState([]);
    const [unacknowledgedCount, setUnacknowledgedCount] = useState(0);
    const [telegramRecipients, setTelegramRecipients] = useState(0);
    const [loading, setLoading] = useState(true);
    const [clearingKey, setClearingKey] = useState('');
    const [clearingAll, setClearingAll] = useState(false);
    const [clearingOffKey, setClearingOffKey] = useState('');
    const [clearingDismissedAll, setClearingDismissedAll] = useState(false);
    const [loadError, setLoadError] = useState('');

    const applyPayload = useCallback((payload) => {
        setAlerts(payload?.active ?? []);
        setUnacknowledgedCount(payload?.unacknowledged_count ?? 0);
        setTelegramRecipients(payload?.admin_telegram_recipients ?? 0);
    }, []);

    const loadAlerts = useCallback(async () => {
        setLoadError('');
        try {
            const { data } = await api.get('/operational-alerts');
            applyPayload(data.data);
        } catch (error) {
            setLoadError(error?.response?.data?.message || 'Failed to load admin alerts.');
        } finally {
            setLoading(false);
        }
    }, [applyPayload]);

    useEffect(() => {
        loadAlerts();
    }, [loadAlerts]);

    const dismissAlert = async (alertKey) => {
        setClearingKey(alertKey);
        try {
            const { data } = await api.post('/operational-alerts/acknowledge', { key: alertKey });
            applyPayload(data.data);
            showToast('Alert dismissed. It will return if the issue persists.', 'info');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Could not dismiss alert.', 'danger');
        } finally {
            setClearingKey('');
        }
    };

    const dismissAll = async () => {
        setClearingAll(true);
        try {
            const { data } = await api.post('/operational-alerts/acknowledge-all');
            applyPayload(data.data);
            const cleared = data.data?.cleared_count ?? 0;
            showToast(
                cleared > 0
                    ? `Dismissed ${cleared} alert${cleared === 1 ? '' : 's'}.`
                    : 'No alerts needed dismissing.',
                cleared > 0 ? 'success' : 'info',
            );
        } catch (error) {
            showToast(error?.response?.data?.message || 'Could not dismiss alerts.', 'danger');
        } finally {
            setClearingAll(false);
        }
    };

    const clearOffAlert = async (alertKey) => {
        setClearingOffKey(alertKey);
        try {
            const { data } = await api.post('/operational-alerts/clear', { key: alertKey });
            applyPayload(data.data);
            showToast('Alert cleared. It stays hidden while the issue persists.', 'success');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Could not clear alert.', 'danger');
        } finally {
            setClearingOffKey('');
        }
    };

    const clearOffAllDismissed = async () => {
        setClearingDismissedAll(true);
        try {
            const { data } = await api.post('/operational-alerts/clear-dismissed');
            applyPayload(data.data);
            const cleared = data.data?.cleared_count ?? 0;
            showToast(
                cleared > 0
                    ? `Cleared ${cleared} dismissed alert${cleared === 1 ? '' : 's'}.`
                    : 'No dismissed alerts to clear.',
                cleared > 0 ? 'success' : 'info',
            );
        } catch (error) {
            showToast(error?.response?.data?.message || 'Could not clear dismissed alerts.', 'danger');
        } finally {
            setClearingDismissedAll(false);
        }
    };

    const pendingAlerts = useMemo(
        () => alerts.filter((row) => !row.acknowledged),
        [alerts],
    );
    const dismissedAlerts = useMemo(
        () => alerts.filter((row) => row.acknowledged),
        [alerts],
    );

    return (
        <div className="contentPane">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Admin alerts</h1>
                    <p className="text-muted small mb-0">
                        Sync health and scheduler issues detected for administrators.
                    </p>
                </div>
                <Link to="/settings/global" className="btn btn-outline-secondary btn-sm">
                    Back to settings
                </Link>
            </div>

            {loadError && (
                <div className="alert alert-danger" role="alert">{loadError}</div>
            )}

            <div className="card mb-3">
                <div className="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div className="small">
                        <span className="me-3">
                            Active:
                            {' '}
                            <strong>{alerts.length}</strong>
                        </span>
                        <span className="me-3">
                            Needs attention:
                            {' '}
                            <strong>{unacknowledgedCount}</strong>
                        </span>
                        <span className="text-muted">
                            Telegram recipients:
                            {' '}
                            {telegramRecipients}
                        </span>
                    </div>
                    <div className="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            disabled={loading || clearingAll}
                            onClick={loadAlerts}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className="btn btn-warning btn-sm"
                            disabled={loading || clearingAll || clearingDismissedAll || unacknowledgedCount === 0}
                            onClick={dismissAll}
                        >
                            {clearingAll ? 'Dismissing…' : 'Dismiss all'}
                        </button>
                        {dismissedAlerts.length > 0 && (
                            <button
                                type="button"
                                className="btn btn-outline-danger btn-sm"
                                disabled={loading || clearingAll || clearingDismissedAll}
                                onClick={clearOffAllDismissed}
                            >
                                {clearingDismissedAll ? 'Clearing…' : 'Clear all dismissed'}
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {loading ? (
                <p className="text-muted">Loading alerts…</p>
            ) : alerts.length === 0 ? (
                <div className="alert alert-success mb-0" role="status">
                    No active operational alerts right now.
                </div>
            ) : (
                <>
                    {pendingAlerts.length > 0 && (
                        <section className="mb-4">
                            <h2 className="h6 text-uppercase text-muted mb-2">Needs attention</h2>
                            <div className="d-grid gap-3">
                                {pendingAlerts.map((alert) => (
                                    <div key={alert.key} className={`card ${alertCardClass(alert.severity, false)}`}>
                                        <div className="card-body">
                                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                <div>
                                                    <span className={`badge ${severityBadgeClass(alert.severity)} me-2`}>
                                                        {alert.severity}
                                                    </span>
                                                    <strong>{alert.title}</strong>
                                                    <p className="small mb-1 mt-2">{alert.message}</p>
                                                    <p className="text-muted small mb-0">
                                                        Last seen:
                                                        {' '}
                                                        {formatTimestamp(alert.last_triggered_at)}
                                                    </p>
                                                </div>
                                                <div className="d-flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-outline-secondary"
                                                        disabled={clearingKey === alert.key || clearingOffKey === alert.key}
                                                        onClick={() => dismissAlert(alert.key)}
                                                    >
                                                        {clearingKey === alert.key ? 'Dismissing…' : 'Dismiss'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-outline-danger"
                                                        disabled={clearingKey === alert.key || clearingOffKey === alert.key}
                                                        onClick={() => clearOffAlert(alert.key)}
                                                    >
                                                        {clearingOffKey === alert.key ? 'Clearing…' : 'Clear off'}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    {dismissedAlerts.length > 0 && (
                        <section>
                            <h2 className="h6 text-uppercase text-muted mb-2">Dismissed (still active)</h2>
                            <div className="d-grid gap-3">
                                {dismissedAlerts.map((alert) => (
                                    <div key={alert.key} className={`card ${alertCardClass(alert.severity, true)}`}>
                                        <div className="card-body">
                                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                <div>
                                                    <span className={`badge ${severityBadgeClass(alert.severity)} me-2`}>
                                                        {alert.severity}
                                                    </span>
                                                    <strong>{alert.title}</strong>
                                                    <p className="small mb-1 mt-2">{alert.message}</p>
                                                    <p className="text-muted small mb-0">
                                                        Dismissed:
                                                        {' '}
                                                        {formatTimestamp(alert.acknowledged_at)}
                                                    </p>
                                                </div>
                                                <div className="d-flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-outline-danger"
                                                        disabled={clearingOffKey === alert.key}
                                                        onClick={() => clearOffAlert(alert.key)}
                                                    >
                                                        {clearingOffKey === alert.key ? 'Clearing…' : 'Clear off'}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}
                </>
            )}

            <p className="text-muted small mt-4 mb-0">
                <strong>Dismiss</strong>
                {' '}
                moves an alert out of “Needs attention” while the underlying issue is still tracked.
                {' '}
                <strong>Clear off</strong>
                {' '}
                removes it from this list even if the issue persists (manual override). Alerts return only after the issue is fixed and then happens again.
                {' '}
                <Link to="/settings/universe-price-sync">Universe price sync</Link>
                {' '}
                has related run controls and provider issue details.
            </p>
        </div>
    );
}
