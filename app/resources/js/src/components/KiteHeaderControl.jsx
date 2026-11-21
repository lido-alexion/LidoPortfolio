import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Link2, PlugZap, RefreshCw } from 'lucide-react';
import ComboButton from './ComboButton';
import api from '../api';
import { tosData } from '../utils/tosEnvelope';
import { useKiteConnection } from '../context/KiteConnectionContext';
import { showToast } from '../toast';

export default function KiteHeaderControl({ user }) {
    const { status, state, refresh } = useKiteConnection();
    const navigate = useNavigate();
    const [busy, setBusy] = useState(false);
    if (!user || user.is_admin) return null;

    const sessionNotExpired = !status?.expires_at || Date.parse(status.expires_at) > Date.now();
    const connected = state === 'confirmed' && status?.usable === true && sessionNotExpired;
    const unavailable = state === 'loading' || state === 'unavailable';
    const configured = state === 'confirmed' && status?.configured === true;
    const title = connected
        ? `Kite connected${status.expires_at ? ` · expires ${new Date(status.expires_at).toLocaleString()}` : ''}`
        : state === 'confirmed' && status?.connected ? 'Kite session expired · Connect Kite'
            : unavailable ? 'Kite status unknown · Refresh to check' : status?.configured === false ? 'Kite is not configured on this server' : 'Connect Kite';
    const Icon = connected ? PlugZap : unavailable ? RefreshCw : Link2;
    const tone = connected ? 'success' : unavailable || status?.configured === false ? 'secondary' : 'warning';

    const connect = async () => {
        if (!configured || busy) return;
        setBusy(true);
        try {
            const response = await api.get('/v1/broker/kite/login-url', { params: { return_to: 'dashboard' }, skipErrorToast: true });
            const data = tosData(response);
            if (data?.url) window.location.assign(data.url);
            else showToast('Kite login link is unavailable', 'warning');
        } catch {
            showToast('Could not start Kite login', 'danger');
            await refresh();
        } finally {
            setBusy(false);
        }
    };

    const disconnect = async () => {
        if (!status?.connected || !window.confirm('Disconnect your Kite account from StoX? This will not change the emergency execution stop.')) return;
        setBusy(true);
        try {
            await api.post('/v1/broker/kite/disconnect', {}, { skipErrorToast: true });
            await refresh();
            showToast('Kite disconnected', 'success');
        } catch {
            showToast('Could not disconnect Kite', 'danger');
            await refresh();
        } finally {
            setBusy(false);
        }
    };

    const items = [
        ...(status?.connected ? [{ key: 'disconnect', label: 'Disconnect Kite…', danger: true, onClick: disconnect }] : []),
        { key: 'account', label: 'Kite settings', onClick: () => navigate('/settings/account') },
    ];

    return (
        <ComboButton
            label={<Icon size={17} aria-hidden="true" />}
            ariaLabel={title}
            onPrimaryClick={connected || state !== 'confirmed' ? refresh : connect}
            menuItems={items}
            variant={`outline-${tone}`}
            size="sm"
            className="lido-kite-header-control"
            disabled={busy || (state === 'confirmed' && status?.configured === false)}
            title={title}
        />
    );
}
