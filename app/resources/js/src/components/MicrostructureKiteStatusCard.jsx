import React, { useEffect, useState } from 'react';
import api from '../api';
import { tosData } from '../utils/tosEnvelope';

const labels = {
    login_needed: 'Login needed',
    connecting: 'Connecting',
    receiving_live_ticks: 'Receiving live ticks',
    attention: 'Attention',
};

export default function MicrostructureKiteStatusCard() {
    const [status, setStatus] = useState(null);
    useEffect(() => {
        api.get('/microstructure-kite/status', { skipErrorToast: true })
            .then((response) => setStatus(tosData(response)))
            .catch(() => setStatus(null));
    }, []);
    if (!status) return null;
    const state = status.display_state || 'attention';
    const good = state === 'receiving_live_ticks';
    return (
        <div className={`alert ${good ? 'alert-success' : 'alert-warning'} d-flex flex-wrap justify-content-between align-items-center gap-3 mb-0`} role="status">
            <div>
                <strong>{labels[state] || labels.attention}</strong>
                <div className="small">{status.kite.usable ? `Session available · WebSocket ${status.collector.websocket_connected ? 'connected' : 'disconnected'} · ${status.collector.packet_recent ? 'recent packets received' : 'no recent packets'}` : 'The configured collector account needs today’s Kite login.'}</div>
            </div>
            <a className="btn btn-primary btn-sm" href="/kite-connect">Connect Kite</a>
        </div>
    );
}
