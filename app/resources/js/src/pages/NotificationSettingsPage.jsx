import React, { useEffect, useRef, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';
import DataState from '../components/DataState';

const defaults = {
    telegram: { bot_token: '', chat_id: '', enabled: false },
    email: { enabled: false },
    webhook: { url: '', enabled: false },
};

export default function NotificationSettingsPage() {
    const [channels, setChannels] = useState([]);
    const [forms, setForms] = useState(defaults);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(null);
    const [saveErrors, setSaveErrors] = useState({});
    const [testErrors, setTestErrors] = useState({});
    const busyRef = useRef(null);
    const beginOperation = key => { if (busyRef.current !== null) return false; busyRef.current = key; setBusy(key); return true; };
    const endOperation = () => { busyRef.current = null; setBusy(null); };
    const [emailDestinations, setEmailDestinations] = useState([]);
    const [additionalEmail, setAdditionalEmail] = useState('');
    const [loadError, setLoadError] = useState(null);
    const [optionalPrefs, setOptionalPrefs] = useState({ enabled: false, categories: {}, quiet_start: '', quiet_end: '', digest_time: '09:00', timezone: 'UTC', catalogue: {} });

    const updateChannelForm = (channel, patch) => {
        if (Object.keys(patch).some((key) => ['bot_token', 'chat_id', 'url'].includes(key))) {
            setTestErrors((currentErrors) => ({ ...currentErrors, [channel]: null }));
            setSaveErrors((currentErrors) => ({ ...currentErrors, [channel]: null }));
        }
        setForms((current) => {
            const form = current[channel] || {};
            const materialEdit = Object.keys(patch).some((key) => ['bot_token', 'chat_id', 'url'].includes(key) && patch[key] !== form[key]);
            return { ...current, [channel]: { ...form, ...patch, ...(materialEdit ? { enabled: false } : {}) } };
        });
    };

    const load = async (resetChannel = null) => {
        setLoading(true);
        setLoadError(null);
        try {
            const response = await api.get('/notification-settings', { skipErrorToast: true });
            const next = response.data?.data || [];
            setChannels(next);
            setOptionalPrefs(response.data?.optional_email_preferences || optionalPrefs);
            const destinations = await api.get('/notification-settings/email-destinations', { skipErrorToast: true });
            setEmailDestinations(destinations.data?.data || []);
            setForms((current) => Object.fromEntries(next.filter((item) => item.channel !== 'in_app').map((item) => [
                item.channel,
                typeof resetChannel === 'string' && item.channel !== resetChannel
                    ? current[item.channel]
                    : { ...defaults[item.channel], enabled: !!item.enabled, chat_id: item.chat_id || '', url: item.url || '' },
            ])));
        } catch (error) {
            setLoadError(error);
            showToast('Could not load notification settings.', 'danger');
        } finally {
            setLoading(false);
        }
    };

    const saveOptionalPreferences = async () => {
        if (!beginOperation('optional-email-preferences')) return;
        try {
            const response = await api.put('/notification-settings/optional-emails', optionalPrefs, { skipErrorToast: true });
            setOptionalPrefs(response.data?.data || optionalPrefs);
            showToast('Optional email preferences saved.', 'success');
        } catch (error) {
            showToast(error.response?.data?.message || 'Could not save optional email preferences.', 'danger');
        } finally {
            endOperation();
        }
    };

    const addEmailDestination = async (event) => {
        event.preventDefault();
        if (!beginOperation('email-destination')) return;
        try {
            await api.post('/notification-settings/email-destinations', { email: additionalEmail }, { skipErrorToast: true });
            setAdditionalEmail('');
            showToast('Verification email sent.', 'success');
            await load();
        } catch (error) {
            showToast(error.response?.data?.message || 'Could not add email recipient.', 'danger');
        } finally {
            endOperation();
        }
    };

    const removeEmailDestination = async (id) => {
        if (!beginOperation(`email-destination-${id}`)) return;
        try {
            await api.delete(`/notification-settings/email-destinations/${id}`, { skipErrorToast: true });
            showToast('Email recipient removed.', 'success');
            await load();
        } catch (error) {
            showToast(error.response?.data?.message || 'Could not remove email recipient.', 'danger');
        } finally {
            endOperation();
        }
    };

    useEffect(() => { load(); }, []);

    const save = async (channel) => {
        if (!beginOperation(channel)) return;
        setSaveErrors((current) => ({ ...current, [channel]: null }));
        try {
            const payload = { ...forms[channel] };
            if (channel === 'telegram' && !payload.bot_token && channels.find((item) => item.channel === channel)?.bot_token_configured) {
                delete payload.bot_token;
            }
            const response = await api.put(`/notification-settings/${channel}`, payload, { skipErrorToast: true });
            if (response.data?.data?.signing_secret_once) {
                showToast(`Webhook signing secret (save it now): ${response.data.data.signing_secret_once}`, 'warning');
            } else {
                showToast(`${channel[0].toUpperCase() + channel.slice(1)} settings saved.`, 'success');
            }
            await load(channel);
        } catch (error) {
            const message = error.response?.data?.message || `Could not save ${channel} settings. Check your settings and try again.`;
            setSaveErrors((current) => ({ ...current, [channel]: message }));
            showToast(message, 'danger');
        } finally {
            endOperation();
        }
    };

    const test = async (channel) => {
        if (!beginOperation(`${channel}-test`)) return;
        setTestErrors((current) => ({ ...current, [channel]: null }));
        try {
            const response = await api.post(`/notification-settings/${channel}/test`, null, { skipErrorToast: true });
            showToast(response.data?.data?.message || 'Channel verified.', 'success');
            await load(channel);
        } catch (error) {
            const message = error.response?.data?.message || 'Channel test failed. Check the saved settings and try again.';
            setTestErrors((current) => ({ ...current, [channel]: message }));
            showToast(message, 'danger');
        } finally {
            endOperation();
        }
    };

    if (loading) return <div className="container-fluid py-3 text-muted">Loading notification settings…</div>;

    if (loadError) return (
        <div className="container-fluid py-3">
            <DataState
                variant="error"
                title="Notification settings unavailable"
                message="Your notification channel settings could not be loaded."
                action={<button type="button" className="btn btn-sm btn-outline-primary" onClick={load}>Retry</button>}
            />
        </div>
    );

    return (
        <div className="container-fluid py-3">
            <fieldset disabled={Boolean(busy)} className="border-0 p-0 m-0">
            <legend className="visually-hidden">Notification delivery settings</legend>
            <h1 className="h3 mb-1">Notification Settings</h1>
            <p className="text-muted small mb-4">Configure account-level delivery channels. In-app notifications are always enabled.</p>
            <section className="card mb-3" aria-labelledby="optional-email-heading">
                <div className="card-body">
                    <h2 className="h5" id="optional-email-heading">Optional product emails</h2>
                    <p className="small text-muted">Account and security messages remain enabled. Optional emails are off by default.</p>
                    <label className="form-check mb-3"><input className="form-check-input" type="checkbox" checked={!!optionalPrefs.enabled} onChange={(event) => setOptionalPrefs({ ...optionalPrefs, enabled: event.target.checked })} /><span className="form-check-label">Enable optional emails</span></label>
                    <fieldset className="mb-3" disabled={!optionalPrefs.enabled}><legend className="small fw-semibold">Categories and delivery</legend>{Object.entries(optionalPrefs.catalogue || {}).map(([key, label]) => <div className="d-flex flex-wrap align-items-center gap-3 mb-2" key={key}><label className="form-check mb-0"><input className="form-check-input" type="checkbox" checked={!!optionalPrefs.categories?.[key]} onChange={(event) => setOptionalPrefs({ ...optionalPrefs, categories: { ...optionalPrefs.categories, [key]: event.target.checked } })} /><span className="form-check-label">{label}</span></label><select className="form-select form-select-sm" aria-label={`${label} delivery`} style={{ maxWidth: 190 }} value={optionalPrefs.modes?.[key] || 'immediate'} onChange={(event) => setOptionalPrefs({ ...optionalPrefs, modes: { ...optionalPrefs.modes, [key]: event.target.value } })}><option value="immediate">Immediate</option><option value="digest">Daily digest</option></select></div>)}</fieldset>
                    <div className="row g-2 mb-3"><div className="col-6 col-md-3"><label className="form-label" htmlFor="optional-quiet-start">Quiet hours start</label><input id="optional-quiet-start" type="time" className="form-control" value={optionalPrefs.quiet_start || ''} onChange={(event) => setOptionalPrefs({ ...optionalPrefs, quiet_start: event.target.value || null })} /></div><div className="col-6 col-md-3"><label className="form-label" htmlFor="optional-quiet-end">Quiet hours end</label><input id="optional-quiet-end" type="time" className="form-control" value={optionalPrefs.quiet_end || ''} onChange={(event) => setOptionalPrefs({ ...optionalPrefs, quiet_end: event.target.value || null })} /></div><div className="col-6 col-md-3"><label className="form-label" htmlFor="optional-digest-time">Daily digest time</label><input id="optional-digest-time" type="time" className="form-control" value={optionalPrefs.digest_time || '09:00'} onChange={(event) => setOptionalPrefs({ ...optionalPrefs, digest_time: event.target.value })} /></div><div className="col-6 col-md-3"><label className="form-label" htmlFor="optional-timezone">Timezone</label><input id="optional-timezone" className="form-control" value={optionalPrefs.timezone || 'UTC'} onChange={(event) => setOptionalPrefs({ ...optionalPrefs, timezone: event.target.value })} /></div></div>
                    <button type="button" className="btn btn-primary btn-sm" onClick={saveOptionalPreferences} disabled={busy === 'optional-email-preferences'}>Save email preferences</button>
                </div>
            </section>
            <section className="card mb-3">
                <div className="card-body">
                    <h2 className="h5">Email recipients</h2>
                    <p className="small text-muted">Your account email is always retained. Additional addresses must be verified before delivery.</p>
                    <div className="d-flex flex-wrap gap-2 mb-3">
                        {emailDestinations.map((destination) => <span className="badge text-bg-light border" key={destination.id}>
                            {destination.email} · {destination.verified_at ? 'verified' : 'verification pending'}
                            {!destination.is_account_email && <button type="button" className="btn btn-link btn-sm p-0 ms-2" disabled={busy === `email-destination-${destination.id}`} onClick={() => removeEmailDestination(destination.id)}>Remove</button>}
                        </span>)}
                    </div>
                    <form className="d-flex gap-2" onSubmit={addEmailDestination}>
                        <input aria-label="Additional email recipient" type="email" className="form-control" value={additionalEmail} onChange={(event) => setAdditionalEmail(event.target.value)} placeholder="alerts@example.com" required />
                        <button type="submit" className="btn btn-outline-primary" disabled={busy === 'email-destination'}>Add</button>
                    </form>
                </div>
            </section>
            <div className="row g-3">
                {channels.filter((item) => item.channel !== 'in_app').map((channel) => {
                    const form = forms[channel.channel] || {};
                    const isDirty = channel.channel === 'telegram'
                        ? Boolean(form.bot_token) || (form.chat_id || '') !== (channel.chat_id || '')
                        : channel.channel === 'webhook'
                            ? (form.url || '') !== (channel.url || '')
                            : false;
                    const isEnabledDirty = !!form.enabled !== !!channel.enabled;
                    const canTest = channel.channel === 'email'
                        ? !!channel.account_email
                        : channel.channel === 'telegram'
                            ? !!(form.bot_token || channel.bot_token_configured) && !!form.chat_id
                            : !!(form.url || channel.url);
                    const canEnable = !isDirty && (!!channel.verified_at || (!!channel.enabled && !!form.enabled));
                    const enableHelp = isDirty
                        ? 'These edits are not tested yet. Save them, then test the saved settings before enabling delivery.'
                        : channel.verified_at
                            ? 'This channel has passed its latest test.'
                            : 'Save these settings and test the channel before enabling delivery.';
                    const statusText = isDirty
                        ? 'Unsaved changes · save before testing'
                        : !channel.configured
                            ? 'Not saved · save settings before testing'
                            : isEnabledDirty
                                ? 'Enablement change · save to apply'
                            : testErrors[channel.channel] || channel.last_test_status === 'failed'
                                ? 'Test failed · check the saved settings and try again'
                                : channel.verified_at
                                    ? 'Test passed · ready to enable'
                                    : 'Saved · test before enabling';
                    return (
                        <div className="col-12 col-xl-4" key={channel.channel}>
                            <section className="card h-100" aria-labelledby={`notification-channel-${channel.channel}`}>
                                <div className="card-body">
                                    <div className="d-flex justify-content-between align-items-start mb-3">
                                        <h2 id={`notification-channel-${channel.channel}`} className="h5 text-capitalize mb-0">{channel.channel}</h2>
                                        <span className={`badge ${channel.health_status === 'healthy' ? 'text-bg-success' : 'text-bg-secondary'}`}>{channel.health_status}</span>
                                    </div>
                                    {channel.channel === 'telegram' && <>
                                        <label className="form-label" htmlFor="notification-telegram-token">Bot token</label>
                                        <input id="notification-telegram-token" type="password" className="form-control mb-2" value={form.bot_token || ''} onChange={(event) => updateChannelForm('telegram', { bot_token: event.target.value })} placeholder={channel.bot_token_configured ? 'Configured — enter to replace' : ''} autoComplete="new-password" />
                                        <label className="form-label" htmlFor="notification-telegram-chat">Chat ID</label>
                                        <input id="notification-telegram-chat" className="form-control mb-2" value={form.chat_id || ''} onChange={(event) => updateChannelForm('telegram', { chat_id: event.target.value })} />
                                    </>}
                                    {channel.channel === 'email' && <p className="small">Account email: <strong>{channel.account_email}</strong><br />Verification: {channel.account_email_verified ? 'verified' : 'unverified'}</p>}
                                    {channel.channel === 'webhook' && <>
                                        <label className="form-label" htmlFor="notification-webhook-url">HTTPS endpoint</label>
                                        <input id="notification-webhook-url" type="url" className="form-control mb-2" value={form.url || ''} onChange={(event) => updateChannelForm('webhook', { url: event.target.value })} placeholder="https://example.com/stox" />
                                        {channel.signing_secret_configured && <p className="small text-muted">Signing secret configured and hidden.</p>}
                                    </>}
                                    <p className="small text-muted mb-2" role="status">{statusText}</p>
                                    {saveErrors[channel.channel] && <p className="small text-danger" role="alert">{saveErrors[channel.channel]}</p>}
                                    {testErrors[channel.channel] && <p className="small text-danger" role="alert">{testErrors[channel.channel]}</p>}
                                    <label className="form-check mb-1"><span className="d-inline-block" tabIndex={canEnable ? undefined : 0} title={canEnable ? undefined : enableHelp} aria-label={canEnable ? undefined : enableHelp}><input className="form-check-input" type="checkbox" checked={!!form.enabled} disabled={!canEnable} aria-describedby={`notification-${channel.channel}-enable-help`} onChange={(event) => updateChannelForm(channel.channel, { enabled: event.target.checked })} /></span> <span className="form-check-label">Enabled</span></label>
                                    <p className="small text-muted mb-3" id={`notification-${channel.channel}-enable-help`}>{enableHelp}</p>
                                    <div className="d-flex gap-2">
                                        <button type="button" className="btn btn-primary btn-sm" disabled={busy === channel.channel} onClick={() => save(channel.channel)}>Save</button>
                                        {channel.configured && !isDirty && <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy === `${channel.channel}-test` || !canTest} onClick={() => test(channel.channel)}>Test channel</button>}
                                    </div>
                                </div>
                            </section>
                        </div>
                    );
                })}
            </div>
            </fieldset>
        </div>
    );
}
