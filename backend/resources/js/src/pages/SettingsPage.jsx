import React, { useEffect, useMemo, useState } from 'react';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import NumberInput from '../components/NumberInput';
import TimeInput, { isValidCronTime } from '../components/TimeInput';
import { showToast } from '../toast';

function roundToTwoDecimals(value) {
    const num = Number(value);
    if (Number.isNaN(num)) {
        return '';
    }
    return (Math.round(num * 100) / 100).toFixed(2);
}

export default function SettingsPage() {
    const { logout } = useAuth();
    const [settings, setSettings] = useState({});
    const [sessions, setSessions] = useState([]);
    const [status, setStatus] = useState('');
    const [sessionsLoading, setSessionsLoading] = useState(true);
    const [cronTimeTouched, setCronTimeTouched] = useState(false);
    const [scheduleTouched, setScheduleTouched] = useState({});

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

    const canSave = !cronTimeInvalid && !notificationSchedulesInvalid;

    const loadSettings = async () => {
        const res = await api.get('/settings');
        setSettings(res.data.data || {});
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

    const save = async (e) => {
        e.preventDefault();
        setCronTimeTouched(true);

        if (!isValidCronTime(settings.cron_time)) {
            showToast('Enter a valid data syncing time (24-hour HH:MM, e.g. 18:30)', 'danger');
            return;
        }

        const invalidSchedule = notificationSchedules.find((time) => {
            const t = time?.trim();
            return t && !isValidCronTime(t);
        });
        if (invalidSchedule) {
            showToast('Each notification time must be valid 24-hour HH:MM', 'danger');
            return;
        }

        const payload = {
            ...settings,
            notification_schedules: notificationSchedules
                .map((t) => t?.trim())
                .filter((t) => t && isValidCronTime(t)),
        };

        await api.put('/settings', payload);
        setStatus('Settings saved');
        showToast('Settings saved');
    };

    const logoutOthers = async () => {
        await api.post('/auth/sessions/logout-others');
        showToast('Other devices logged out');
        await loadSessions();
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

    return (
        <div className="d-grid gap-3">
            <div className="card">
                <div className="card-header">Settings</div>
                <div className="card-body">
                    <form className="row g-3" onSubmit={save}>
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
                        <div className="col-12">
                            <label className="form-label d-block">Telegram notification times</label>
                            <p className="text-muted small mb-2">
                                Separate from data syncing. At each time, the app sends Telegram
                                messages for active portfolio alerts (same as the dashboard Alerts
                                card). Uses timezone from{' '}
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
                        <div className="col-12">
                            <p className="text-muted small mb-0">
                                Frontend log level (browser only): set in devtools —
                                <code>localStorage.setItem(&quot;logLevel&quot;, &quot;debug&quot;)</code>
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
                        </div>
                        <div className="col-12">
                            <button className="btn btn-primary" type="submit" disabled={!canSave}>
                                Save Settings
                            </button>
                            {status && <span className="text-success ms-3">{status}</span>}
                        </div>
                    </form>
                </div>
            </div>

            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <span>Active Sessions</span>
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
    );
}

function formatDate(value) {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleString();
}
