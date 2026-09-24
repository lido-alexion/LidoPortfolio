import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, KeyRound, RotateCcw, ShieldCheck, Unplug, XOctagon } from 'lucide-react';
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
    const executionCodeLabel = state.execution_code_label || 'StoX execution code — Authenticator app';

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

    const recover = async (useRecoveryCode) => {
        const label = useRecoveryCode ? 'StoX recovery code' : executionCodeLabel;
        const code = window.prompt(`Enter ${label}.`);
        if (!code) return;
        setBusy(true);
        try {
            const proof = useRecoveryCode ? { recovery_code: code } : { totp: code };
            const response = await api.post('/v1/execution/recover', { confirm: true, ...proof });
            setState(response.data?.data || null);
            showToast('Execution state recovered');
        } catch (error) {
            showToast(getApiErrorMessage(error, `Could not verify ${label}`), 'danger');
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
                <>
                    <button type="button" className="lido-icon-action" title={`Recover with ${executionCodeLabel}`} aria-label={`Recover with ${executionCodeLabel}`} disabled={busy} onClick={() => recover(false)}>
                        <RotateCcw size={16} />
                    </button>
                    <button type="button" className="lido-icon-action" title="Recover with StoX recovery code" aria-label="Recover with StoX recovery code" disabled={busy} onClick={() => recover(true)}>
                        <KeyRound size={16} />
                    </button>
                </>
            )}
            <button type="button" className="lido-icon-action" title="Emergency Kite disconnect" aria-label="Emergency Kite disconnect" disabled={busy} onClick={emergencyDisconnect}>
                <Unplug size={16} />
            </button>
            <button type="button" className="lido-icon-action lido-icon-action--danger" title="Cancel eligible open orders and disconnect Kite" aria-label="Cancel eligible open orders and disconnect Kite" disabled={busy} onClick={cancelAndDisconnect}>
                <XOctagon size={16} />
            </button>
        </div>
    );
}
