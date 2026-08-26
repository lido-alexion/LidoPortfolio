import React, { useEffect, useState } from 'react';
import api from '../api';
import { runApiMutation } from '../hooks/useApiMutation';
import { formatInrWhole } from '../utils/tableFormat';
import {
    canSelectLenderForStatus,
    capitalRequestIdFromRecommendation,
} from '../utils/capitalRecallUi';

/**
 * Presentation-only lender select / approve / reject against existing capital APIs.
 * Does not change lending business rules.
 */
export default function RecommendationLenderActions({ recommendation, onChanged }) {
    const requestId = capitalRequestIdFromRecommendation(recommendation);
    const status = recommendation?.capital_allocation_status
        || recommendation?.evidence?.capital_allocation?.status
        || null;
    const show = Boolean(requestId) && canSelectLenderForStatus(status);

    const [lenders, setLenders] = useState([]);
    const [amount, setAmount] = useState(null);
    const [lenderId, setLenderId] = useState('');
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!show || !requestId) {
            setLenders([]);
            setAmount(null);
            setLenderId('');
            setError('');
            return undefined;
        }
        let cancelled = false;
        (async () => {
            setLoading(true);
            setError('');
            try {
                const { data } = await api.get(`/v1/capital/requests/${requestId}/lenders`, {
                    skipErrorToast: true,
                });
                if (cancelled) return;
                const payload = data?.data || {};
                const list = Array.isArray(payload.lenders) ? payload.lenders : [];
                setLenders(list);
                setAmount(payload.amount != null ? Number(payload.amount) : null);
                setLenderId(list[0]?.strategy_id != null ? String(list[0].strategy_id) : '');
            } catch (err) {
                if (!cancelled) {
                    setLenders([]);
                    setError(err?.response?.data?.error?.message || 'Could not load lenders.');
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [show, requestId]);

    if (!show) return null;

    const approve = async () => {
        if (!lenderId) return;
        setBusy(true);
        try {
            await runApiMutation(async () => {
                await api.post(`/v1/capital/requests/${requestId}/approve`, {
                    lender_strategy_id: Number(lenderId),
                }, { skipErrorToast: true });
                if (typeof onChanged === 'function') await onChanged();
            }, {
                successMessage: 'Lender approved — capital committed',
                errorFallback: 'Lender approval failed',
            });
        } finally {
            setBusy(false);
        }
    };

    const reject = async () => {
        if (!window.confirm('Reject this capital request? No loan will be created.')) return;
        setBusy(true);
        try {
            await runApiMutation(async () => {
                await api.post(`/v1/capital/requests/${requestId}/reject`, null, { skipErrorToast: true });
                if (typeof onChanged === 'function') await onChanged();
            }, {
                successMessage: 'Capital request rejected',
                errorFallback: 'Reject failed',
            });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mb-3 border rounded p-2 bg-light">
            <h6 className="mb-1">Select lender</h6>
            <p className="text-muted small mb-2">
                This buy needs a loan from another enabled strategy.
                {amount != null ? ` Requested loan ${formatInrWhole(amount)}.` : null}
                {' '}
                Approving commits capital; you still Approve the trade separately.
            </p>
            {loading ? <p className="small text-muted mb-0">Loading lenders…</p> : null}
            {error ? <p className="small text-danger mb-2">{error}</p> : null}
            {!loading && !error && lenders.length === 0 ? (
                <p className="small text-muted mb-0">No eligible lenders right now.</p>
            ) : null}
            {!loading && lenders.length > 0 ? (
                <div className="d-flex flex-wrap gap-2 align-items-end">
                    <div className="flex-grow-1" style={{ minWidth: '12rem' }}>
                        <label className="form-label small mb-1" htmlFor={`lender-select-${requestId}`}>
                            Lender strategy
                        </label>
                        <select
                            id={`lender-select-${requestId}`}
                            className="form-select form-select-sm"
                            value={lenderId}
                            disabled={busy}
                            onChange={(e) => setLenderId(e.target.value)}
                        >
                            {lenders.map((l) => (
                                <option key={l.strategy_id} value={String(l.strategy_id)}>
                                    {l.name || `Strategy #${l.strategy_id}`}
                                    {l.available_for_lending != null
                                        ? ` · AFL ${formatInrWhole(l.available_for_lending)}`
                                        : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        disabled={busy || !lenderId}
                        onClick={approve}
                    >
                        Approve lender
                    </button>
                    <button
                        type="button"
                        className="btn btn-outline-danger btn-sm"
                        disabled={busy}
                        onClick={reject}
                    >
                        Reject
                    </button>
                </div>
            ) : null}
        </div>
    );
}
