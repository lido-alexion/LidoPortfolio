import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { usePortfolio } from '../context/PortfolioContext';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import FeeComponentsSettings from '../components/FeeComponentsSettings';
import NumberInput from '../components/NumberInput';
import TimeInput, { isValidCronTime } from '../components/TimeInput';
import { normalizeFeeComponents } from '../utils/feeCalculator';
import { showToast } from '../toast';

function roundToTwoDecimals(value) {
    const num = Number(value);
    if (Number.isNaN(num)) {
        return '';
    }
    return (Math.round(num * 100) / 100).toFixed(2);
}

function formatSyncRunLabel(run) {
    if (!run) {
        return 'No runs recorded yet';
    }
    const status = run.status || 'unknown';
    const when = run.finished_at || run.started_at;
    const whenLabel = when ? new Date(when).toLocaleString() : '';
    const parts = [status];
    if (whenLabel) {
        parts.push(whenLabel);
    }
    if (run.summary) {
        parts.push(run.summary);
    } else if (run.stocks_processed != null) {
        parts.push(`processed=${run.stocks_processed}, failures=${run.failures ?? 0}`);
    }
    return parts.join(' · ');
}

function formatDate(value) {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleString();
}

export default function SettingsPage() {
    const { logout, user } = useAuth();
    const { activePortfolio } = usePortfolio();
    const isAdmin = Boolean(user?.is_admin);
    const [settings, setSettings] = useState({});
    const [sessions, setSessions] = useState([]);
    const [status, setStatus] = useState('');
    const [sessionsLoading, setSessionsLoading] = useState(true);
    const [cronTimeTouched, setCronTimeTouched] = useState(false);
    const [scheduleTouched, setScheduleTouched] = useState({});
    const [feeSectionOpen, setFeeSectionOpen] = useState(false);
    const [telegramTesting, setTelegramTesting] = useState(false);
    const [activeScope, setActiveScope] = useState('portfolio');

    const notificationSchedules = useMemo(
        () => (Array.isArray(settings.notification_schedules)
            ? settings.notification_schedules
            : []),
        [settings.notification_schedules],
    );

    const cronTimeInvalid = useMemo(() => {
        const cron = settings.cron_time?.trim();
        if (!cron) {
            return false;
        }
        return !isValidCronTime(cron);
    }, [settings.cron_time]);

    const notificationSchedulesInvalid = useMemo(
        () => notificationSchedules.some((time, index) => {
            if (!scheduleTouched[index]) {
                return false;
            }
            const t = time?.trim();
            return !t || !isValidCronTime(t);
        }),
        [notificationSchedules, scheduleTouched],
    );

    const canSaveGlobal = !cronTimeInvalid;
    const canSavePortfolio = !notificationSchedulesInvalid;

    const telegramConfigured = useMemo(
        () => Boolean(
            settings.telegram_bot_token?.trim()
            && settings.telegram_chat_id?.trim(),
        ),
        [settings.telegram_bot_token, settings.telegram_chat_id],
    );

    const loadSettings = async () => {
        const res = await api.get('/settings');
        const data = res.data.data || {};
        setSettings({
            ...data,
            fee_components: normalizeFeeComponents(data.fee_components),
        });
    };

    const loadSessions = async () => {
        setSessionsLoading(true);
        try {
            const res = await api.get('/auth/sessions');
            setSessions(res.data.data || []);
        } finally {
            setSessionsLoading(false);
        }
    };

    useEffect(() => {
        loadSettings();
        loadSessions();
    }, []);

    usePortfolioChanged(() => {
        loadSettings();
    });

    const saveGlobal = async (e) => {
        e.preventDefault();
        setCronTimeTouched(true);

        if (!isValidCronTime(settings.cron_time)) {
            showToast('Enter a valid data syncing time (24-hour HH:MM, e.g. 18:30)', 'danger');
            return;
        }

        await api.put('/settings', {
            cron_time: settings.cron_time,
            nse_retry_count: settings.nse_retry_count,
            backend_log_level: settings.backend_log_level,
            sync_log_retention_days: settings.sync_log_retention_days,
            fee_components: normalizeFeeComponents(settings.fee_components),
        });
        setStatus('Global settings saved');
        showToast('Global settings saved');
    };

    const savePortfolioSettings = async (e) => {
        e.preventDefault();

        const invalidSchedule = notificationSchedules.find((time) => {
            const t = time?.trim();
            return t && !isValidCronTime(t);
        });
        if (invalidSchedule) {
            showToast('Each notification time must be valid 24-hour HH:MM', 'danger');
            return;
        }

        const notificationPayload = notificationSchedules
            .map((t) => t?.trim())
            .filter((t) => t && isValidCronTime(t));

        await api.put('/settings', {
            default_stoploss_percent: settings.default_stoploss_percent,
            telegram_bot_token: settings.telegram_bot_token,
            telegram_chat_id: settings.telegram_chat_id,
            notification_schedules: notificationPayload,
        });
        setStatus('Portfolio settings saved');
        showToast('Portfolio settings saved');
    };

    const logoutOthers = async () => {
        await api.post('/auth/sessions/logout-others');
        showToast('Other devices logged out');
        await loadSessions();
    };

    const testTelegram = async () => {
        setTelegramTesting(true);
        try {
            const res = await api.post('/settings/test-telegram', {
                telegram_bot_token: settings.telegram_bot_token.trim(),
                telegram_chat_id: settings.telegram_chat_id.trim(),
            });
            showToast(res.data.message || 'Test message sent to Telegram');
        } catch (error) {
            const msg = error?.response?.data?.message || 'Telegram test failed';
            showToast(msg, 'danger');
        } finally {
            setTelegramTesting(false);
        }
    };

    const revokeSession = async (sessionId, isCurrent) => {
        if (isCurrent) {
            await logout();
            window.location.href = '/';
            return;
        }
        await api.delete(`/auth/sessions/${sessionId}`);
        showToast('Session revoked');
        await loadSessions();
    };

    const scopeTabs = [
        ...(isAdmin ? [{ id: 'global', label: 'Global' }] : []),
        { id: 'portfolio', label: 'Portfolio' },
        { id: 'account', label: 'Account' },
    ];

    return (
        <div className="d-grid gap-3">
            <div>
                <h2 className="h4 mb-1">Settings</h2>
                <p className="text-muted small mb-3">
                    Global app options, per-portfolio alerts, and account sessions.
                </p>
                <ul className="nav nav-tabs lido-settings-scope-tabs mb-3" role="tablist">
                    {scopeTabs.map((tab) => (
                        <li className="nav-item" key={tab.id} role="presentation">
                            <button
                                type="button"
                                className={`nav-link${activeScope === tab.id ? ' active' : ''}`}
                                role="tab"
                                aria-selected={activeScope === tab.id}
                                onClick={() => setActiveScope(tab.id)}
                            >
                                {tab.label}
                            </button>
                        </li>
                    ))}
                </ul>
            </div>

            {activeScope === 'global' && isAdmin && (
                <form className="d-grid gap-3" onSubmit={saveGlobal}>
                    <div className="card">
                        <div className="card-header p-0">
                            <button
                                type="button"
                                id="settings-fee-section-toggle"
                                className="lido-collapsible-card-toggle"
                                onClick={() => setFeeSectionOpen((open) => !open)}
                                aria-expanded={feeSectionOpen}
                                aria-controls="settings-fee-section"
                            >
                                <span>Transaction fees</span>
                                <span className="lido-collapsible-card-chevron" aria-hidden="true">
                                    {feeSectionOpen ? '▾' : '▸'}
                                </span>
                            </button>
                        </div>
                        <div
                            id="settings-fee-section"
                            className={`collapse${feeSectionOpen ? ' show' : ''}`}
                        >
                            <div className="card-body">
                                <FeeComponentsSettings
                                    components={settings.fee_components}
                                    onChange={(fee_components) => setSettings({ ...settings, fee_components })}
                                />
                            </div>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Application settings</div>
                        <div className="card-body">
                            <div className="row g-3">
                                <div className="col-12 col-md-4">
                                    <label className="form-label" htmlFor="settings-cron-time">
                                        Data syncing time
                                    </label>
                                    <TimeInput
                                        id="settings-cron-time"
                                        value={settings.cron_time || ''}
                                        invalid={cronTimeTouched && cronTimeInvalid}
                                        describedBy={
                                            cronTimeTouched && cronTimeInvalid ? 'settings-cron-time-error' : undefined
                                        }
                                        onChange={(e) => setSettings({ ...settings, cron_time: e.target.value })}
                                        onBlur={() => setCronTimeTouched(true)}
                                    />
                                    {cronTimeTouched && cronTimeInvalid && (
                                        <div id="settings-cron-time-error" className="invalid-feedback d-block">
                                            Use 24-hour time between 00:00 and 23:59 (e.g. 18:30).
                                        </div>
                                    )}
                                </div>
                                <div className="col-12 col-md-4">
                                    <label className="form-label" htmlFor="settings-nse-retry">
                                        NSE Retry Count
                                    </label>
                                    <NumberInput
                                        id="settings-nse-retry"
                                        min="1"
                                        max="10"
                                        step="1"
                                        allowDecimals={false}
                                        value={settings.nse_retry_count ?? ''}
                                        onChange={(e) => setSettings({
                                            ...settings,
                                            nse_retry_count: e.target.value,
                                        })}
                                    />
                                </div>
                                <div className="col-12 col-md-4">
                                    <label className="form-label">Backend log level</label>
                                    <select
                                        className="form-select"
                                        value={settings.backend_log_level || 'info'}
                                        onChange={(e) => setSettings({ ...settings, backend_log_level: e.target.value })}
                                    >
                                        <option value="debug">debug</option>
                                        <option value="info">info</option>
                                        <option value="warning">warning</option>
                                        <option value="error">error</option>
                                    </select>
                                </div>
                                <div className="col-12 col-md-4">
                                    <label className="form-label" htmlFor="settings-sync-log-retention">
                                        Sync log retention (days)
                                    </label>
                                    <NumberInput
                                        id="settings-sync-log-retention"
                                        min="0"
                                        max="90"
                                        step="1"
                                        allowDecimals={false}
                                        value={settings.sync_log_retention_days ?? ''}
                                        onChange={(e) => setSettings({
                                            ...settings,
                                            sync_log_retention_days: e.target.value,
                                        })}
                                    />
                                    <p className="text-muted small mb-0 mt-1">
                                        In-app sync logs only. Set to 0 to disable. File logs are unchanged.
                                    </p>
                                </div>
                                <div className="col-12">
                                    <p className="text-muted small mb-2">
                                        Daily sync:
                                        {' '}
                                        {formatSyncRunLabel(settings.sync_log_latest_runs?.daily_market_data)}
                                        {' · '}
                                        Stock master:
                                        {' '}
                                        {formatSyncRunLabel(settings.sync_log_latest_runs?.stock_master)}
                                        {' · '}
                                        Universe prices:
                                        {' '}
                                        {formatSyncRunLabel(settings.sync_log_latest_runs?.universe_price_sync)}
                                    </p>
                                    <div className="d-flex flex-wrap gap-2">
                                    <Link to="/settings/admin-alerts" className="btn btn-outline-warning btn-sm">
                                        Admin alerts
                                    </Link>
                                    <Link to="/settings/sync-logs" className="btn btn-outline-secondary btn-sm">
                                        View sync logs
                                    </Link>
                                    <Link to="/settings/universe-price-sync" className="btn btn-outline-secondary btn-sm">
                                        Universe price sync
                                    </Link>
                                    </div>
                                </div>
                                <div className="col-12">
                                    <p className="text-muted small mb-0">
                                        Frontend log level (browser only): set in devtools —
                                        <code>localStorage.setItem(&quot;logLevel&quot;, &quot;debug&quot;)</code>
                                    </p>
                                </div>
                                <div className="col-12">
                                    <button className="btn btn-primary" type="submit" disabled={!canSaveGlobal}>
                                        Save global settings
                                    </button>
                                    {status && activeScope === 'global' && (
                                        <span className="text-success ms-3">{status}</span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            )}

            {activeScope === 'portfolio' && (
                <>
                    <div className="card">
                        <div className="card-header">Alerts</div>
                        <div className="card-body d-flex flex-wrap gap-2">
                            <Link to="/settings/alert-policies" className="btn btn-primary">
                                Manage alert policies
                            </Link>
                        </div>
                    </div>
                    <form className="d-grid gap-3" onSubmit={savePortfolioSettings}>
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span>Portfolio settings</span>
                            {activePortfolio && (
                                <span className="badge text-bg-secondary">
                                    Active:
                                    {' '}
                                    {activePortfolio.name}
                                </span>
                            )}
                        </div>
                        <div className="card-body">
                            <p className="text-muted small">
                                Telegram, stoploss, and notification schedules apply to the
                                {' '}
                                <strong>active portfolio</strong>
                                {' '}
                                in this tab.
                            </p>
                            <div className="row g-3">
                                <div className="col-12">
                                    <label className="form-label d-block">Telegram notification times</label>
                                    <p className="text-muted small mb-2">
                                        Your notification schedule for this portfolio. Separate from data syncing. At each time, the app sends Telegram
                                        messages for your active portfolio alerts. Uses timezone from{' '}
                                        <code>{settings.cron_timezone || 'Asia/Kolkata'}</code>
                                        . If there are no alerts, nothing is sent.
                                    </p>
                                    {notificationSchedules.length === 0 ? (
                                        <p className="text-muted small mb-2">No notification times configured.</p>
                                    ) : (
                                        <div className="d-flex flex-column gap-2 mb-2">
                                            {notificationSchedules.map((time, index) => {
                                                const invalid = scheduleTouched[index]
                                                    && (!time?.trim() || !isValidCronTime(time));
                                                return (
                                                    <div
                                                        key={`notification-schedule-${index}`}
                                                        className="d-flex flex-wrap align-items-start gap-2"
                                                    >
                                                        <div style={{ maxWidth: '10rem' }}>
                                                            <TimeInput
                                                                id={`settings-notification-time-${index}`}
                                                                value={time || ''}
                                                                invalid={invalid}
                                                                onChange={(e) => {
                                                                    const next = [...notificationSchedules];
                                                                    next[index] = e.target.value;
                                                                    setSettings({
                                                                        ...settings,
                                                                        notification_schedules: next,
                                                                    });
                                                                }}
                                                                onBlur={() => setScheduleTouched((prev) => ({
                                                                    ...prev,
                                                                    [index]: true,
                                                                }))}
                                                            />
                                                            {invalid && (
                                                                <div className="invalid-feedback d-block">
                                                                    Use HH:MM (e.g. 09:00).
                                                                </div>
                                                            )}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-danger"
                                                            onClick={() => {
                                                                const next = notificationSchedules.filter(
                                                                    (_, i) => i !== index,
                                                                );
                                                                setSettings({
                                                                    ...settings,
                                                                    notification_schedules: next,
                                                                });
                                                                setScheduleTouched({});
                                                            }}
                                                        >
                                                            Remove
                                                        </button>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-outline-secondary"
                                        onClick={() => setSettings({
                                            ...settings,
                                            notification_schedules: [...notificationSchedules, '09:00'],
                                        })}
                                    >
                                        Add notification time
                                    </button>
                                </div>
                                <div className="col-12 col-md-4">
                                    <label className="form-label" htmlFor="settings-stoploss">
                                        Trailing Stoploss %age
                                    </label>
                                    <NumberInput
                                        id="settings-stoploss"
                                        min="1"
                                        max="50"
                                        step="0.05"
                                        value={settings.default_stoploss_percent ?? ''}
                                        onChange={(e) => setSettings({
                                            ...settings,
                                            default_stoploss_percent: e.target.value,
                                        })}
                                        onBlur={(e) => setSettings({
                                            ...settings,
                                            default_stoploss_percent: e.target.value === ''
                                                ? ''
                                                : roundToTwoDecimals(e.target.value),
                                        })}
                                    />
                                    <p className="text-muted small mb-0 mt-1">
                                        Applies to holdings in the active portfolio.
                                    </p>
                                </div>
                                <div className="col-12">
                                    <label className="form-label">Telegram Bot Token</label>
                                    <input
                                        className="form-control"
                                        value={settings.telegram_bot_token || ''}
                                        onChange={(e) => setSettings({ ...settings, telegram_bot_token: e.target.value })}
                                    />
                                </div>
                                <div className="col-12">
                                    <label className="form-label">Telegram Chat ID</label>
                                    <input
                                        className="form-control"
                                        value={settings.telegram_chat_id || ''}
                                        onChange={(e) => setSettings({ ...settings, telegram_chat_id: e.target.value })}
                                    />
                                    <p className="text-muted small mb-0 mt-1">
                                        Telegram bot and chat ID are saved for the active portfolio.
                                    </p>
                                </div>
                                {telegramConfigured && (
                                    <div className="col-12">
                                        <button
                                            type="button"
                                            className="btn btn-outline-secondary btn-sm"
                                            onClick={testTelegram}
                                            disabled={telegramTesting}
                                        >
                                            {telegramTesting ? 'Sending…' : 'Test telegram integration'}
                                        </button>
                                        <p className="text-muted small mb-0 mt-1">
                                            Sends active alerts now, or &quot;No active alerts at this time&quot; if none.
                                        </p>
                                    </div>
                                )}
                                <div className="col-12">
                                    <button className="btn btn-primary" type="submit" disabled={!canSavePortfolio}>
                                        Save portfolio settings
                                    </button>
                                    {status && activeScope === 'portfolio' && (
                                        <span className="text-success ms-3">{status}</span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                </>
            )}

            {activeScope === 'account' && (
                <div className="d-grid gap-3">
                    <div className="card lido-settings-admin-card">
                        <div className="card-header">Management</div>
                        <div className="card-body d-flex flex-wrap gap-2">
                            <Link to="/portfolios" className="btn btn-primary">
                                Manage portfolios
                            </Link>
                            {isAdmin && (
                                <Link to="/settings/users" className="btn btn-outline-primary">
                                    Manage users
                                </Link>
                            )}
                            <Link to="/profile" className="btn btn-outline-secondary">
                                My account
                            </Link>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <span>Active sessions</span>
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={logoutOthers}>
                                Log out other devices
                            </button>
                        </div>
                        <div className="card-body">
                            <p className="text-muted small">
                                Each browser or device keeps its own session. You can stay signed in on laptop and mobile at the same time.
                            </p>
                            {sessionsLoading ? (
                                <div className="text-muted">Loading sessions…</div>
                            ) : sessions.length === 0 ? (
                                <div className="text-muted">No active sessions found.</div>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle">
                                        <thead>
                                            <tr>
                                                <th>Device</th>
                                                <th>IP</th>
                                                <th>Login</th>
                                                <th>Last activity</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {sessions.map((session) => (
                                                <tr key={session.id}>
                                                    <td>
                                                        {session.device}
                                                        {session.is_current && (
                                                            <span className="badge bg-primary ms-2">This device</span>
                                                        )}
                                                    </td>
                                                    <td>{session.ip_address || '—'}</td>
                                                    <td>{formatDate(session.login_time)}</td>
                                                    <td>{formatDate(session.last_activity)}</td>
                                                    <td className="text-end">
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-secondary"
                                                            onClick={() => revokeSession(session.id, session.is_current)}
                                                        >
                                                            {session.is_current ? 'Log out' : 'Revoke'}
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
