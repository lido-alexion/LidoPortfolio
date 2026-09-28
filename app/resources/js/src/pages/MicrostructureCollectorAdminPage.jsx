import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

const COMMANDS = [
    { id: 'start', label: 'Start / Resume', variant: 'success' },
    { id: 'stop', label: 'Stop (manual hold)', variant: 'danger' },
    { id: 'force_resubscribe', label: 'Force resubscribe', variant: 'outline-primary' },
    { id: 'retry_finalization', label: 'Retry finalization', variant: 'outline-secondary' },
    { id: 'retry_backup', label: 'Retry backup', variant: 'outline-secondary' },
    { id: 'refresh_universe', label: 'Refresh NIFTY 500', variant: 'outline-secondary' },
];

function formatValue(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }
    return String(value);
}

export default function MicrostructureCollectorAdminPage() {
    const [status, setStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/microstructure-collector/status');
            setStatus(res.data?.data || null);
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to load collector status', 'danger');
            setStatus(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const runCommand = async (command) => {
        setBusy(true);
        try {
            const res = await api.post('/microstructure-collector/command', { command });
            setStatus(res.data?.data || status);
            showToast(res.data?.message || 'Command sent');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Command failed', 'danger');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h1 className="h5 mb-0">Live microstructure collector</h1>
                <Link to="/settings/admin-alerts" className="btn btn-sm btn-outline-secondary">← Admin alerts</Link>
            </div>
            <p className="text-muted small">
                Prospective Kite full-mode minute aggregates (Dataset C). Status merges Laravel state with the collector heartbeat file on the VPS.
            </p>
            {loading ? (
                <div className="text-muted">Loading…</div>
            ) : !status?.enabled ? (
                <div className="alert alert-secondary">
                    Collector integration is disabled. Set <code>MICROSTRUCTURE_COLLECTOR_ENABLED=true</code> on the VPS to activate.
                </div>
            ) : (
                <>
                    <div className="card mb-3">
                        <div className="card-header">Operational status</div>
                        <div className="card-body row g-2 small">
                            <div className="col-md-4"><strong>Manual hold</strong><br />{formatValue(status.manual_hold)}</div>
                            <div className="col-md-4"><strong>Kite session</strong><br />{formatValue(status.kite_session_connected)}</div>
                            <div className="col-md-4"><strong>WebSocket</strong><br />{formatValue(status.websocket_connected)}</div>
                            <div className="col-md-4"><strong>Collector state</strong><br />{formatValue(status.collector_state)}</div>
                            <div className="col-md-4"><strong>Session phase</strong><br />{formatValue(status.session_phase)}</div>
                            <div className="col-md-4"><strong>Subscribed instruments</strong><br />{formatValue(status.subscribed_instrument_count)}</div>
                            <div className="col-md-4"><strong>NIFTY 500 symbols (cache)</strong><br />{formatValue(status.nifty500_symbol_count)}</div>
                            <div className="col-md-4"><strong>Last packet</strong><br />{formatValue(status.last_packet_at)}</div>
                            <div className="col-md-4"><strong>Reconnects</strong><br />{formatValue(status.reconnect_count)}</div>
                            <div className="col-md-4"><strong>Disk free (GB)</strong><br />{formatValue(status.disk_free_gb)}</div>
                            <div className="col-md-6"><strong>Latest finalized partition</strong><br />{formatValue(status.latest_finalized_partition)}</div>
                            <div className="col-md-6"><strong>Backup</strong><br />{formatValue(status.backup_status)}</div>
                            <div className="col-md-6"><strong>Finalization</strong><br />{formatValue(status.finalization?.status)}</div>
                            <div className="col-md-6"><strong>Raw-tick spool</strong><br />{formatValue(status.raw_tick_spool?.total_bytes)} bytes / {formatValue(status.raw_tick_spool?.file_count)} files</div>
                            {status.latest_error ? (
                                <div className="col-12">
                                    <div className="alert alert-warning py-2 mb-0 mt-2">{status.latest_error}</div>
                                </div>
                            ) : null}
                        </div>
                    </div>
                    <div className="card">
                        <div className="card-header">Controls</div>
                        <div className="card-body d-flex flex-wrap gap-2">
                            {COMMANDS.map((cmd) => (
                                <button
                                    key={cmd.id}
                                    type="button"
                                    className={`btn btn-${cmd.variant}`}
                                    disabled={busy}
                                    onClick={() => runCommand(cmd.id)}
                                >
                                    {cmd.label}
                                </button>
                            ))}
                            <button type="button" className="btn btn-link" disabled={busy} onClick={load}>
                                Refresh status
                            </button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
