import React, { useState } from 'react';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { formatInrWhole } from '../utils/tableFormat';
import {
    TERMINOLOGY,
    bridgeStatusBadgeClass,
    bridgeStatusLabel,
    proceedsStatusBadgeClass,
    proceedsStatusLabel,
    recallKindLabel,
    recallStateBadgeClass,
    recallStateLabel,
} from '../utils/capitalRecallUi';

function money(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return formatInrWhole(v);
}

function fmtWhen(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

/**
 * Lending / recall / bridge / proceeds panels on Cash management (existing card + table patterns).
 */
export default function CapitalRecallPanel({ profileId }) {
    const [recalls, setRecalls] = useState([]);
    const [bridges, setBridges] = useState([]);
    const [proceeds, setProceeds] = useState([]);
    const [selectedRecall, setSelectedRecall] = useState(null);
    const [detailBusy, setDetailBusy] = useState(false);

    const { loading, reload } = useApiGet({
        deps: [profileId],
        enabled: Boolean(profileId),
        errorFallback: 'Failed to load recalls',
        onError: () => {
            setRecalls([]);
            setBridges([]);
            setProceeds([]);
        },
        request: async () => {
            const [rRes, bRes, pRes] = await Promise.all([
                api.get('/v1/capital/recalls', { params: { limit: 50 }, skipErrorToast: true }),
                api.get('/v1/capital/bridge-loans', { params: { limit: 50 }, skipErrorToast: true }),
                api.get('/v1/capital/pending-sale-proceeds', { params: { limit: 50 }, skipErrorToast: true }),
            ]);
            const nextRecalls = Array.isArray(rRes.data?.data) ? rRes.data.data : [];
            const nextBridges = Array.isArray(bRes.data?.data) ? bRes.data.data : [];
            const nextProceeds = Array.isArray(pRes.data?.data) ? pRes.data.data : [];
            setRecalls(nextRecalls);
            setBridges(nextBridges);
            setProceeds(nextProceeds);
            return { recalls: nextRecalls, bridges: nextBridges, proceeds: nextProceeds };
        },
    });

    const openRecall = async (id) => {
        setDetailBusy(true);
        try {
            const { data } = await api.get(`/v1/capital/recalls/${id}`, { skipErrorToast: true });
            setSelectedRecall(data?.data || null);
        } catch {
            setSelectedRecall(null);
        } finally {
            setDetailBusy(false);
        }
    };

    if (!profileId) return null;

    return (
        <>
            <div className="card mb-3">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <span>Recalls &amp; lending</span>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={reload} disabled={loading}>
                        Refresh
                    </button>
                </div>
                <div className="card-body">
                    <p className="text-muted small mb-3">
                        Recalls, {TERMINOLOGY.bridgeLoan}s, and {TERMINOLOGY.proceeds.toLowerCase()} are automated.
                        Partial recalls use ₹5,000 multiples; full recalls and bridge repayments may be any amount up to outstanding.
                        Sale execution does not mean proceeds are immediately usable cash.
                    </p>

                    <h6 className="mb-2">Recalls</h6>
                    {loading && recalls.length === 0 ? (
                        <p className="text-muted small">Loading…</p>
                    ) : recalls.length === 0 ? (
                        <p className="text-muted small">No recalls.</p>
                    ) : (
                        <div className="table-responsive mb-3">
                            <table className="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Kind</th>
                                        <th>Lender</th>
                                        <th>Borrower</th>
                                        <th className="text-end">Requested</th>
                                        <th className="text-end">Settled</th>
                                        <th className="text-end">Outstanding</th>
                                        <th>State</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {recalls.map((row) => (
                                        <tr key={row.id}>
                                            <td className="small">{recallKindLabel(row.kind)}</td>
                                            <td className="small">{row.lender_strategy_name || `#${row.lender_strategy_id}`}</td>
                                            <td className="small">{row.borrower_strategy_name || `#${row.borrower_strategy_id}`}</td>
                                            <td className="text-end">{money(row.recall_amount)}</td>
                                            <td className="text-end">{money(row.settled_amount)}</td>
                                            <td className="text-end">{money(row.outstanding_recall_amount)}</td>
                                            <td>
                                                <span className={`badge ${recallStateBadgeClass(row.state)}`}>
                                                    {recallStateLabel(row.state)}
                                                </span>
                                            </td>
                                            <td className="text-end">
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-secondary btn-sm"
                                                    disabled={detailBusy}
                                                    onClick={() => openRecall(row.id)}
                                                >
                                                    Details
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <h6 className="mb-2">{TERMINOLOGY.bridgeLoan}</h6>
                    {bridges.length === 0 ? (
                        <p className="text-muted small">No {TERMINOLOGY.bridgeLoan}s.</p>
                    ) : (
                        <div className="table-responsive mb-3">
                            <table className="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Lender</th>
                                        <th>Borrower</th>
                                        <th className="text-end">Principal</th>
                                        <th className="text-end">Outstanding</th>
                                        <th className="text-end">Repaid</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {bridges.map((row) => (
                                        <tr key={row.id}>
                                            <td className="small">{row.lender_strategy_name || `#${row.lender_strategy_id}`}</td>
                                            <td className="small">{row.borrower_strategy_name || `#${row.borrower_strategy_id}`}</td>
                                            <td className="text-end">{money(row.principal)}</td>
                                            <td className="text-end">{money(row.outstanding)}</td>
                                            <td className="text-end">{money(row.repaid_amount)}</td>
                                            <td>
                                                <span className={`badge ${bridgeStatusBadgeClass(row.status)}`}>
                                                    {bridgeStatusLabel(row.status)}
                                                </span>
                                            </td>
                                            <td className="small">{fmtWhen(row.committed_at || row.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <h6 className="mb-2">{TERMINOLOGY.proceeds}</h6>
                    {proceeds.length === 0 ? (
                        <p className="text-muted small mb-0">No pending {TERMINOLOGY.proceeds.toLowerCase()}.</p>
                    ) : (
                        <div className="table-responsive">
                            <table className="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Strategy</th>
                                        <th className="text-end">Actual</th>
                                        <th>Sold</th>
                                        <th>Available at</th>
                                        <th className="text-end">Applied</th>
                                        <th className="text-end">Remaining</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {proceeds.map((row) => (
                                        <tr key={row.id}>
                                            <td className="small">{row.strategy_name || `#${row.strategy_id}`}</td>
                                            <td className="text-end">{money(row.actual_proceeds_amount ?? row.amount)}</td>
                                            <td className="small">{fmtWhen(row.sold_at)}</td>
                                            <td className="small">{fmtWhen(row.available_at)}</td>
                                            <td className="text-end">{money(row.amount_applied)}</td>
                                            <td className="text-end">{money(row.amount_remaining)}</td>
                                            <td>
                                                <span className={`badge ${proceedsStatusBadgeClass(row.status)}`}>
                                                    {proceedsStatusLabel(row.status)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {selectedRecall && (
                <div
                    className="modal show d-block"
                    style={{ background: 'rgba(0,0,0,.45)' }}
                    role="dialog"
                    aria-modal="true"
                >
                    <div className="modal-dialog modal-lg modal-dialog-scrollable">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 className="modal-title">
                                    {recallKindLabel(selectedRecall.kind)}
                                    {' '}
                                    #
                                    {selectedRecall.id}
                                </h5>
                                <button type="button" className="btn-close" aria-label="Close" onClick={() => setSelectedRecall(null)} />
                            </div>
                            <div className="modal-body">
                                <p className="mb-2">
                                    <span className={`badge ${recallStateBadgeClass(selectedRecall.state)}`}>
                                        {recallStateLabel(selectedRecall.state)}
                                    </span>
                                </p>
                                <div className="row g-2 small mb-3">
                                    <div className="col-md-6">
                                        <div className="text-muted">Lender</div>
                                        <div>{selectedRecall.lender_strategy_name || `#${selectedRecall.lender_strategy_id}`}</div>
                                    </div>
                                    <div className="col-md-6">
                                        <div className="text-muted">Borrower</div>
                                        <div>{selectedRecall.borrower_strategy_name || `#${selectedRecall.borrower_strategy_id}`}</div>
                                    </div>
                                    <div className="col-md-4">
                                        <div className="text-muted">Requested</div>
                                        <div>{money(selectedRecall.recall_amount)}</div>
                                    </div>
                                    <div className="col-md-4">
                                        <div className="text-muted">Settled / immediate</div>
                                        <div>{money(selectedRecall.settled_amount ?? selectedRecall.immediate_settlement_amount)}</div>
                                    </div>
                                    <div className="col-md-4">
                                        <div className="text-muted">Outstanding</div>
                                        <div>{money(selectedRecall.outstanding_recall_amount)}</div>
                                    </div>
                                    <div className="col-md-4">
                                        <div className="text-muted">{TERMINOLOGY.bridgeLoan}</div>
                                        <div>{money(selectedRecall.bridge_amount)}</div>
                                    </div>
                                    <div className="col-md-4">
                                        <div className="text-muted">Requested at</div>
                                        <div>{fmtWhen(selectedRecall.requested_at)}</div>
                                    </div>
                                    <div className="col-md-4">
                                        <div className="text-muted">Completed</div>
                                        <div>{fmtWhen(selectedRecall.completed_at)}</div>
                                    </div>
                                </div>
                                {selectedRecall.liquidation ? (
                                    <div className="mb-2 small">
                                        <h6 className="h6">{TERMINOLOGY.proceeds}</h6>
                                        <p className="mb-1">
                                            Expected
                                            {' '}
                                            {money(selectedRecall.liquidation.expected_proceeds)}
                                            {' · actual '}
                                            {money(selectedRecall.liquidation.actual_proceeds)}
                                            {' · applied '}
                                            {money(selectedRecall.liquidation.applied_proceeds)}
                                        </p>
                                        <p className="text-muted mb-0">
                                            Sale executed is not the same as proceeds available.
                                        </p>
                                    </div>
                                ) : null}
                            </div>
                            <div className="modal-footer">
                                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setSelectedRecall(null)}>
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
