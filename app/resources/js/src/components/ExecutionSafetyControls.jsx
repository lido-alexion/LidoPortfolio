import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, RotateCcw, ShieldCheck, Unplug, XOctagon } from 'lucide-react';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

export default function ExecutionSafetyControls({ user }) {
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        if (!user || user.is_admin) return;
        try {
            const response = await api.get('/v1/execution/state');
            setState(response.data?.data || null);
        } catch {
            setState(null);
        }
    }, [user]);

    useEffect(() => {
        load();
        const timer = window.setInterval(load, 60000);
        return () => window.clearInterval(timer);
    }, [load]);

    if (!user || user.is_admin || !state) return null;

    const halted = state.execution_state === 'emergency_halt';

    const emergencyDisconnect = async () => {
        if (!window.confirm('Enter Emergency Halt and disconnect Kite? New StoX broker orders will be blocked.')) return;
        setBusy(true);
        try {
            const response = await api.post('/v1/broker/kite/emergency-disconnect', { reason: 'Investor emergency disconnect' });
            setState(response.data?.data || null);
            showToast('Emergency Halt active; Kite disconnected');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Emergency disconnect failed'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    const cancelAndDisconnect = async () => {
        if (!window.confirm('Cancel eligible StoX primary open broker orders, enter Emergency Halt, and disconnect Kite? Protective GTT orders are left alone.')) return;
        setBusy(true);
        try {
            const response = await api.post('/v1/broker/kite/emergency-cancel-open-orders-disconnect', {
                confirm: true,
                reason: 'Investor emergency cancel and disconnect',
            });
            setState(response.data?.data || null);
            showToast(`Emergency Halt active; ${response.data?.data?.cancelled_orders || 0} order(s) cancelled`);
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Emergency cancellation failed'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    const recover = async () => {
        const code = window.prompt('Enter authenticator or recovery code to recover execution state.');
        if (!code) return;
        setBusy(true);
        try {
            const response = await api.post('/v1/execution/recover', {
                confirm: true,
                totp: code,
                recovery_code: code,
            });
            setState(response.data?.data || null);
            showToast('Execution state recovered');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not recover execution state'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className={`lido-execution-safety ${halted ? 'is-halted' : 'is-normal'}`} aria-live="polite">
            <span className="lido-execution-safety-state" title={halted ? 'Emergency Halt is active' : 'Execution state is normal'}>
                {halted ? <AlertTriangle size={16} /> : <ShieldCheck size={16} />}
                <span>{halted ? 'Emergency Halt' : 'Normal'}</span>
            </span>
            {halted && (
                <button type="button" className="lido-icon-action" title="Recover execution state" disabled={busy} onClick={recover}>
                    <RotateCcw size={16} />
                </button>
            )}
            <button type="button" className="lido-icon-action" title="Emergency Kite disconnect" disabled={busy} onClick={emergencyDisconnect}>
                <Unplug size={16} />
            </button>
            <button type="button" className="lido-icon-action lido-icon-action--danger" title="Cancel eligible open orders and disconnect" disabled={busy} onClick={cancelAndDisconnect}>
                <XOctagon size={16} />
            </button>
        </div>
    );
}
