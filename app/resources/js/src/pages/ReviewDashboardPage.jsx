import React from 'react';
import { Link } from 'react-router-dom';
import { ROUTES } from '../navigation/routes';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { runApiMutation } from '../hooks/useApiMutation';
import { formatInrCompactWhole } from '../utils/tableFormat';
import { tosData, tosList } from '../utils/tosEnvelope';

function fmtPct(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Number(v).toFixed(2)}%`;
}

function fmtNum(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toFixed(2);
}

export default function ReviewDashboardPage() {
    const { data, loading, reload: load } = useApiGet({
        errorFallback: 'Failed to load review dashboard',
        request: async () => {
            const [d, o] = await Promise.all([
                api.get('/v1/review/dashboard', { skipErrorToast: true }),
                api.get('/v1/orders', { skipErrorToast: true }),
            ]);
            return {
                dash: tosData(d),
                orders: tosList(o),
            };
        },
    });
    const dash = data?.dash ?? null;
    const orders = data?.orders ?? [];

    const executePending = async (orderId) => {
        const price = window.prompt('Execution price');
        if (!price) return;
        const { ok } = await runApiMutation(async () => {
            await api.post(`/v1/orders/${orderId}/execute`, { price: Number(price) }, { skipErrorToast: true });
        }, { successMessage: 'Transaction added', errorFallback: 'Execute failed' });
        if (ok) {
            await load();
        }
    };

    const cancelPending = async (orderId) => {
        const { ok } = await runApiMutation(async () => {
            await api.post(`/v1/orders/${orderId}/cancel`, null, { skipErrorToast: true });
        }, { successMessage: 'Order cancelled', errorFallback: 'Cancel failed' });
        if (ok) {
            await load();
        }
    };

    const counts = dash?.actionable_counts || dash?.recommendation_counts || {};
    const infoCounts = dash?.informational_counts || {};
    const portfolio = dash?.portfolio || {};
    const outcomes = dash?.outcomes || [];
    const infoOutcomes = dash?.informational_outcomes || [];

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Review</h1>
                    <p className="text-muted small mb-0">
                        Live observational dashboard for recommendations, insights, and recent decisions.
                        Stored ReviewEngine reports are on Reports — those numbers are not this live snapshot.
                    </p>
                </div>
                <div className="d-flex gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to={ROUTES.REVIEW_REPORTS}>Reports</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/recommendations">Recommendations</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/notification-history">Notifications</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading}>Refresh</button>
                </div>
            </div>

            {loading || !dash ? (
                <p className="text-muted">Loading…</p>
            ) : (
                <>
                    <h2 className="h6 text-muted text-uppercase mb-2">Trade recommendations (actionable)</h2>
                    <div className="row g-3 mb-4">
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Portfolio value</div>
                                <div className="fs-5">{formatInrCompactWhole(portfolio.portfolio_value)}</div>
                            </div>
                        </div>
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">XIRR</div>
                                <div className="fs-5">{fmtPct(portfolio.xirr != null ? Number(portfolio.xirr) * 100 : null)}</div>
                            </div>
                        </div>
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Accepted / Rejected</div>
                                <div className="fs-5">
                                    {counts.accepted ?? 0}
                                    {' / '}
                                    {counts.rejected ?? 0}
                                </div>
                            </div>
                        </div>
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Executed / Pending review</div>
                                <div className="fs-5">
                                    {counts.executed ?? 0}
                                    {' / '}
                                    {counts.pending_review ?? 0}
                                </div>
                            </div>
                        </div>
                    </div>

                    <h2 className="h6 text-muted text-uppercase mb-2">Market insights (HOLD / WATCH)</h2>
                    <div className="row g-3 mb-4">
                        <div className="col-md-4">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Published</div>
                                <div className="fs-5">{infoCounts.published ?? 0}</div>
                            </div>
                        </div>
                        <div className="col-md-4">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Expired</div>
                                <div className="fs-5">{infoCounts.expired ?? 0}</div>
                            </div>
                        </div>
                        <div className="col-md-4">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Total insights</div>
                                <div className="fs-5">{infoCounts.total ?? 0}</div>
                            </div>
                        </div>
                    </div>

                    <h2 className="h5">Actionable outcomes</h2>
                    <p className="small text-muted">BUY/SELL reference price at generation vs latest close (SELL flips sign so favourable moves are positive).</p>
                    <div className="table-responsive mb-4">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Ref</th>
                                    <th>Current</th>
                                    <th>P/L</th>
                                    <th>P/L %</th>
                                </tr>
                            </thead>
                            <tbody>
                                {outcomes.length === 0 ? (
                                    <tr><td colSpan={7} className="text-muted">No actionable outcomes yet.</td></tr>
                                ) : outcomes.map((o) => (
                                    <tr key={o.recommendation_id}>
                                        <td><strong>{o.symbol}</strong></td>
                                        <td>{o.recommendation_type}</td>
                                        <td>{o.status}</td>
                                        <td>{fmtNum(o.reference_price)}</td>
                                        <td>{fmtNum(o.current_price)}</td>
                                        <td className={o.gain_loss > 0 ? 'text-success' : o.gain_loss < 0 ? 'text-danger' : ''}>{fmtNum(o.gain_loss)}</td>
                                        <td className={o.gain_loss_pct > 0 ? 'text-success' : o.gain_loss_pct < 0 ? 'text-danger' : ''}>{fmtPct(o.gain_loss_pct)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="h5">Insight outcomes</h2>
                    <p className="small text-muted">HOLD/WATCH price movement vs reference (informational only).</p>
                    <div className="table-responsive mb-4">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Ref</th>
                                    <th>Current</th>
                                    <th>P/L</th>
                                    <th>P/L %</th>
                                </tr>
                            </thead>
                            <tbody>
                                {infoOutcomes.length === 0 ? (
                                    <tr><td colSpan={7} className="text-muted">No insight outcomes yet.</td></tr>
                                ) : infoOutcomes.map((o) => (
                                    <tr key={o.recommendation_id}>
                                        <td><strong>{o.symbol}</strong></td>
                                        <td>{o.recommendation_type}</td>
                                        <td>{o.status}</td>
                                        <td>{fmtNum(o.reference_price)}</td>
                                        <td>{fmtNum(o.current_price)}</td>
                                        <td className={o.gain_loss > 0 ? 'text-success' : o.gain_loss < 0 ? 'text-danger' : ''}>{fmtNum(o.gain_loss)}</td>
                                        <td className={o.gain_loss_pct > 0 ? 'text-success' : o.gain_loss_pct < 0 ? 'text-danger' : ''}>{fmtPct(o.gain_loss_pct)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="h5">Orders</h2>
                    <div className="table-responsive mb-4">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Symbol</th>
                                    <th>Side</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {orders.length === 0 ? (
                                    <tr><td colSpan={6} className="text-muted">No orders.</td></tr>
                                ) : orders.map((o) => (
                                    <tr key={o.id}>
                                        <td>{o.id}</td>
                                        <td>{o.security?.symbol || o.symbol || '—'}</td>
                                        <td>{o.side}</td>
                                        <td>{o.quantity}</td>
                                        <td>{o.status}</td>
                                        <td className="text-nowrap">
                                            {o.status === 'pending' && (
                                                <>
                                                    <button type="button" className="btn btn-link btn-sm px-0 me-2" onClick={() => executePending(o.id)}>Add transaction</button>
                                                    <button type="button" className="btn btn-link btn-sm px-0 text-danger" onClick={() => cancelPending(o.id)}>Cancel</button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="h5">Recent review decisions</h2>
                    <ul className="small">
                        {(dash.recent_reviews || []).length === 0 && <li className="text-muted">None yet.</li>}
                        {(dash.recent_reviews || []).map((r) => (
                            <li key={r.id}>
                                {r.symbol}
                                {' '}
                                —
                                {' '}
                                <strong>{r.decision}</strong>
                                {' '}
                                by
                                {' '}
                                {r.user || 'user'}
                                {' '}
                                {r.created_at ? new Date(r.created_at).toLocaleString() : ''}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}
