import React, { useEffect, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

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
    const [emailDestinations, setEmailDestinations] = useState([]);
    const [additionalEmail, setAdditionalEmail] = useState('');

    const load = async () => {
        setLoading(true);
        try {
            const response = await api.get('/notification-settings', { skipErrorToast: true });
            const next = response.data?.data || [];
            setChannels(next);
            const destinations = await api.get('/notification-settings/email-destinations', { skipErrorToast: true });
            setEmailDestinations(destinations.data?.data || []);
            setForms((current) => Object.fromEntries(next.filter((item) => item.channel !== 'in_app').map((item) => [
                item.channel,
                { ...current[item.channel], enabled: !!item.enabled, chat_id: item.chat_id || '', url: item.url || '' },
            ])));
        } catch {
            showToast('Could not load notification settings.', 'danger');
        } finally {
            setLoading(false);
        }
    };

    const addEmailDestination = async (event) => {
        event.preventDefault();
        setBusy('email-destination');
        try {
            await api.post('/notification-settings/email-destinations', { email: additionalEmail }, { skipErrorToast: true });
            setAdditionalEmail('');
            showToast('Verification email sent.', 'success');
            await load();
        } catch (error) {
            showToast(error.response?.data?.message || 'Could not add email recipient.', 'danger');
        } finally {
            setBusy(null);
        }
    };

    const removeEmailDestination = async (id) => {
        setBusy(`email-destination-${id}`);
        try {
            await api.delete(`/notification-settings/email-destinations/${id}`, { skipErrorToast: true });
            showToast('Email recipient removed.', 'success');
            await load();
        } catch (error) {
            showToast(error.response?.data?.message || 'Could not remove email recipient.', 'danger');
        } finally {
            setBusy(null);
        }
    };

    useEffect(() => { load(); }, []);

    const save = async (channel) => {
        setBusy(channel);
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
            await load();
        } catch (error) {
            showToast(error.response?.data?.message || `Could not save ${channel} settings.`, 'danger');
        } finally {
            setBusy(null);
        }
    };

    const test = async (channel) => {
        setBusy(`${channel}-test`);
        try {
            const response = await api.post(`/notification-settings/${channel}/test`, null, { skipErrorToast: true });
            showToast(response.data?.data?.message || 'Channel verified.', 'success');
            await load();
        } catch (error) {
            showToast(error.response?.data?.message || 'Channel test failed.', 'danger');
        } finally {
            setBusy(null);
        }
    };

    if (loading) return <div className="container-fluid py-3 text-muted">Loading notification settings…</div>;

    return (
        <div className="container-fluid py-3">
            <h1 className="h3 mb-1">Notification Settings</h1>
            <p className="text-muted small mb-4">Configure account-level delivery channels. In-app notifications are always enabled.</p>
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
                        <input type="email" className="form-control" value={additionalEmail} onChange={(event) => setAdditionalEmail(event.target.value)} placeholder="alerts@example.com" required />
                        <button type="submit" className="btn btn-outline-primary" disabled={busy === 'email-destination'}>Add</button>
                    </form>
                </div>
            </section>
            <div className="row g-3">
                {channels.filter((item) => item.channel !== 'in_app').map((channel) => {
                    const form = forms[channel.channel] || {};
                    const canTest = channel.channel === 'email'
                        ? !!channel.account_email
                        : channel.channel === 'telegram'
                            ? !!(form.bot_token || channel.bot_token_configured) && !!form.chat_id
                            : !!(form.url || channel.url);
                    return (
                        <div className="col-12 col-xl-4" key={channel.channel}>
                            <section className="card h-100">
                                <div className="card-body">
                                    <div className="d-flex justify-content-between align-items-start mb-3">
                                        <h2 className="h5 text-capitalize mb-0">{channel.channel}</h2>
                                        <span className={`badge ${channel.health_status === 'healthy' ? 'text-bg-success' : 'text-bg-secondary'}`}>{channel.health_status}</span>
                                    </div>
                                    {channel.channel === 'telegram' && <>
                                        <label className="form-label" htmlFor="notification-telegram-token">Bot token</label>
                                        <input id="notification-telegram-token" type="password" className="form-control mb-2" value={form.bot_token || ''} onChange={(event) => setForms({ ...forms, telegram: { ...form, bot_token: event.target.value } })} placeholder={channel.bot_token_configured ? 'Configured — enter to replace' : ''} />
                                        <label className="form-label" htmlFor="notification-telegram-chat">Chat ID</label>
                                        <input id="notification-telegram-chat" className="form-control mb-2" value={form.chat_id || ''} onChange={(event) => setForms({ ...forms, telegram: { ...form, chat_id: event.target.value } })} />
                                    </>}
                                    {channel.channel === 'email' && <p className="small">Account email: <strong>{channel.account_email}</strong><br />Verification: {channel.account_email_verified ? 'verified' : 'unverified'}</p>}
                                    {channel.channel === 'webhook' && <>
                                        <label className="form-label" htmlFor="notification-webhook-url">HTTPS endpoint</label>
                                        <input id="notification-webhook-url" type="url" className="form-control mb-2" value={form.url || ''} onChange={(event) => setForms({ ...forms, webhook: { ...form, url: event.target.value } })} placeholder="https://example.com/stox" />
                                        {channel.signing_secret_configured && <p className="small text-muted">Signing secret configured and hidden.</p>}
                                    </>}
                                    <label className="form-check mb-3"><input className="form-check-input" type="checkbox" checked={!!form.enabled} onChange={(event) => setForms({ ...forms, [channel.channel]: { ...form, enabled: event.target.checked } })} /> <span className="form-check-label">Enabled</span></label>
                                    <div className="d-flex gap-2">
                                        <button type="button" className="btn btn-primary btn-sm" disabled={busy === channel.channel} onClick={() => save(channel.channel)}>Save</button>
                                        <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy === `${channel.channel}-test` || !canTest} onClick={() => test(channel.channel)}>Test channel</button>
                                    </div>
                                </div>
                            </section>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
