import React, { useEffect, useState } from 'react';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { formatInrWhole } from '../utils/tableFormat';
import {
    TERMINOLOGY,
    actualExecutionAmount,
    capitalResolutionStateLabel,
} from '../utils/capitalRecallUi';

function money(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return formatInrWhole(v);
}

/**
 * Compact capital-resolution outcome for recommendation detail (Phase 3A API).
 */
export default function RecommendationCapitalResolution({ recommendationId }) {
    const [resolution, setResolution] = useState(null);
    const [error, setError] = useState(null);

    const { loading } = useApiGet({
        deps: [recommendationId],
        enabled: Boolean(recommendationId),
        errorFallback: 'Failed to load capital resolution',
        onError: () => {
            setResolution(null);
            setError('Unable to load capital resolution.');
        },
        request: async () => {
            setError(null);
            const { data } = await api.get(
                `/v1/recommendations/${recommendationId}/capital-resolution`,
                { skipErrorToast: true },
            );
            const payload = data?.data || null;
            setResolution(payload);
            return payload;
        },
    });

    useEffect(() => {
        setResolution(null);
        setError(null);
    }, [recommendationId]);

    if (!recommendationId) return null;

    if (loading && !resolution) {
        return (
            <div className="mb-3">
                <h6 className="mb-1">Capital resolution</h6>
                <p className="text-muted small mb-0">Loading…</p>
            </div>
        );
    }

    if (error) {
        return (
            <div className="mb-3">
                <h6 className="mb-1">Capital resolution</h6>
                <p className="text-muted small mb-0">{error}</p>
            </div>
        );
    }

    if (!resolution) return null;

    const actual = actualExecutionAmount(resolution);
    const requested = resolution.requested_investment_amount;
    const shortfall = resolution.unresolved_amount;

    return (
        <div className="mb-3">
            <h6 className="mb-1">Capital resolution</h6>
            <p className="text-muted small mb-2">
                Strategies fund buys from own capital first, then recall, then borrow.
                Execution uses the amount actually available — not the full recommendation target when funding is short.
            </p>
            <div className="table-responsive">
                <table className="table table-sm align-middle mb-2">
                    <tbody>
                        <tr>
                            <th scope="row" className="small text-muted">Requested</th>
                            <td className="text-end">{money(requested)}</td>
                        </tr>
                        <tr>
                            <th scope="row" className="small text-muted">Own capital used</th>
                            <td className="text-end">{money(resolution.own_capital_used)}</td>
                        </tr>
                        <tr>
                            <th scope="row" className="small text-muted">Recall requested</th>
                            <td className="text-end">{money(resolution.recalled_capital_requested)}</td>
                        </tr>
                        <tr>
                            <th scope="row" className="small text-muted">Recall received</th>
                            <td className="text-end">{money(resolution.recalled_capital_received)}</td>
                        </tr>
                        <tr>
                            <th scope="row" className="small text-muted">{TERMINOLOGY.bridgeLoan}</th>
                            <td className="text-end">{money(resolution.bridge_capital_used)}</td>
                        </tr>
                        <tr>
                            <th scope="row" className="small text-muted">Immediately available</th>
                            <td className="text-end">{money(resolution.total_immediately_available)}</td>
                        </tr>
                        <tr className="table-primary">
                            <th scope="row" className="small">Actual execution amount</th>
                            <td className="text-end fw-semibold">{money(actual)}</td>
                        </tr>
                        {shortfall != null && Number(shortfall) > 0.0001 ? (
                            <tr>
                                <th scope="row" className="small text-muted">Unresolved</th>
                                <td className="text-end">{money(shortfall)}</td>
                            </tr>
                        ) : null}
                    </tbody>
                </table>
            </div>
            <div className="d-flex flex-wrap gap-2 align-items-center">
                <span className="badge text-bg-light border">
                    {capitalResolutionStateLabel(resolution.capital_resolution_state)}
                </span>
                {requested != null && actual != null && Number(actual) + 0.0001 < Number(requested) ? (
                    <span className="small text-muted">
                        Partially funded — execute
                        {' '}
                        {money(actual)}
                        {' '}
                        of
                        {' '}
                        {money(requested)}
                        .
                    </span>
                ) : null}
            </div>
        </div>
    );
}
