import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';
import ComboButton from './ComboButton';
import { useKiteConnection } from '../context/KiteConnectionContext';
import { tosData } from '../utils/tosEnvelope';

const label = (value) => {
    if (!value) return 'Not yet checked';

    const readable = value.replaceAll('_', ' ');
    return readable.charAt(0).toUpperCase() + readable.slice(1);
};
const tone = (value) => value === 'reconciled' ? 'text-success' : value === 'mismatch' || value === 'attention_required' ? 'text-danger' : 'text-muted';
const amount = (value) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const quantity = (value) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 });
const completedAt = (value) => value ? new Date(value).toLocaleString(undefined, {
    year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true,
}) : '—';
const tradingDate = (value) => value ? new Date(`${value}T12:00:00`).toLocaleDateString() : 'Unknown date';
const syncStatus = (run) => run.status === 'gap_unavailable' ? 'Historical trades unavailable'
    : run.status === 'sync_failed' ? 'Sync failed'
        : run.status === 'needs_review' ? 'Needs review'
            : run.status === 'running' ? 'In progress' : 'Completed';
const syncTone = (status) => ['gap_unavailable', 'sync_failed'].includes(status) ? 'text-danger'
    : status === 'needs_review' ? 'text-warning' : status === 'completed' ? 'text-success' : 'text-muted';

function ComparisonValue({ value, mismatch, money = false }) {
    return <span className={mismatch ? 'text-danger fw-semibold' : 'text-muted'}>{money ? `₹${amount(value)}` : quantity(value)}</span>;
}

export default function PortfolioReconciliationCard({ executionMode }) {
    const { status: brokerStatus, state: brokerState, refresh: refreshBroker } = useKiteConnection();
    const eligible = executionMode === 'semi_automatic' || executionMode === 'automatic';
    const [snapshot, setSnapshot] = useState(null);
    const [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false);
    const [reviewItems, setReviewItems] = useState([]);
    const [reviewDetail, setReviewDetail] = useState(null);
    const [matchTransactionId, setMatchTransactionId] = useState('');

    const load = useCallback(async () => {
        if (!eligible) return;
        const response = await api.get('/reconciliation', { skipErrorToast: true });
        setSnapshot(response.data?.data || null);
        const reviews = await api.get('/reconciliation/trades/review', { skipErrorToast: true });
        setReviewItems(reviews.data?.data?.data || []);
    }, [eligible]);

    useEffect(() => {
        setSelected(null);
        if (eligible) load().catch(() => setSnapshot(null));
    }, [eligible, load]);

    useEffect(() => {
        if (!eligible) return undefined;
        const timer = window.setInterval(() => load().catch(() => {}), 60_000);
        return () => window.clearInterval(timer);
    }, [eligible, load]);

    if (!eligible) return null;

    const reconcile = async (ignoreTodayTransactions) => {
        const latestBrokerStatus = await refreshBroker();
        const latestExpiry = latestBrokerStatus?.expires_at ? Date.parse(latestBrokerStatus.expires_at) : null;
        if (latestBrokerStatus?.usable !== true
            || (latestExpiry !== null && (!Number.isFinite(latestExpiry) || latestExpiry <= Date.now()))) {
            return;
        }
        setBusy(true);
        try {
            await api.post('/reconciliation', { ignore_today_transactions: ignoreTodayTransactions }, { skipErrorToast: true });
            await load();
            showToast('Portfolio reconciliation completed', 'success');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not run reconciliation'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    const syncTrades = async () => {
        setBusy(true);
        try {
            const response = await api.post('/reconciliation/trades/sync', {}, { skipErrorToast: true });
            await load();
            const result = response.data?.data || {};
            showToast(`Kite trades synced: ${result.imported || 0} added, ${result.matched || 0} matched, ${result.review || 0} for review`, result.review ? 'warning' : 'success');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not sync Kite trades'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    const openRun = async (id) => {
        try {
            const response = await api.get(`/reconciliation/${id}`, { skipErrorToast: true });
            setSelected(response.data?.data || null);
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not load reconciliation evidence'), 'danger');
        }
    };

    const acknowledgeReview = async (item) => {
        setBusy(true);
        try {
            await api.post(`/reconciliation/trades/review/${item.id}/resolve`, {
                action: 'acknowledge_unresolved',
                idempotency_key: window.crypto?.randomUUID?.() || `review-${item.id}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
                evidence_key: item.review_version,
            }, { skipErrorToast: true });
            await load();
            showToast('Review recorded; this item remains unresolved and no transaction was created', 'warning');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not record review'), 'danger');
        } finally { setBusy(false); }
    };

    const openReview = async (item) => {
        try {
            const response = await api.get(`/reconciliation/trades/review/${item.id}`, { skipErrorToast: true });
            setReviewDetail(response.data?.data || null);
            setMatchTransactionId('');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not load row review evidence'), 'danger');
        }
    };

    const linkExistingTransaction = async () => {
        if (!reviewDetail || !matchTransactionId) return;
        setBusy(true);
        try {
            await api.post(`/reconciliation/trades/review/${reviewDetail.id}/resolve`, {
                action: 'match_existing', transaction_id: Number(matchTransactionId),
                idempotency_key: window.crypto?.randomUUID?.() || `match-${reviewDetail.id}-${Date.now()}`,
                evidence_key: reviewDetail.review_version,
            }, { skipErrorToast: true });
            setReviewDetail(null);
            await load();
            showToast('Linked to the existing transaction; no transaction was created', 'success');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not link transaction'), 'danger');
        } finally { setBusy(false); }
    };

    const status = snapshot?.status || {};
    const brokerUsable = brokerState === 'confirmed' && brokerStatus?.usable === true
        && (!brokerStatus.expires_at || Date.parse(brokerStatus.expires_at) > Date.now());
    const discrepancies = selected?.discrepancies || {};

    return (
        <div className="card h-100">
            <div className="card-header d-flex justify-content-between align-items-center gap-2">
                <span>Broker portfolio reconciliation</span>
                <button type="button" className="btn btn-outline-secondary btn-sm" onClick={syncTrades} disabled={busy || !brokerUsable}>Sync today’s CNC trades</button>
                <ComboButton
                    label={busy ? 'Reconciling…' : 'Reconcile excluding today'}
                    onPrimaryClick={() => reconcile(true)}
                    menuItems={[{ key: 'including-today', label: 'Reconcile including today', onClick: () => reconcile(false) }]}
                    variant="outline-primary"
                    disabled={busy || !brokerUsable}
                    title={brokerUsable ? 'Choose which transactions to include in reconciliation' : 'Connect Kite to enable reconciliation'}
                />
            </div>
            <div className="card-body">
                {!brokerUsable ? <div className="alert alert-warning py-2 small d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
                    <span>{brokerState === 'loading' ? 'Checking Kite session. Reconciliation is paused.' : brokerState === 'unavailable' ? 'Kite status is unavailable. Reconciliation is paused until status can be confirmed.' : brokerStatus?.connected ? 'Kite session expired. Reconnect to run reconciliation.' : brokerStatus?.configured === false ? 'Kite is not configured on this server.' : 'Connect Kite to run portfolio reconciliation.'}</span>
                    {brokerState === 'confirmed' && brokerStatus?.configured === true && <button type="button" className="btn btn-outline-primary btn-sm" onClick={async () => {
                        try {
                            const response = await api.get('/v1/broker/kite/login-url', { params: { return_to: 'dashboard' }, skipErrorToast: true });
                            const data = tosData(response);
                            if (data?.url) window.location.assign(data.url);
                        } catch {
                            showToast('Could not start Kite login', 'danger');
                            await refreshBroker();
                        }
                    }}>Connect Kite</button>}
                    {brokerState === 'confirmed' && brokerStatus?.configured === false && <Link className="btn btn-outline-secondary btn-sm" to="/settings/account">Kite settings</Link>}
                </div> : null}
                {!snapshot ? <p className="small text-muted mb-0">Reconciliation status is unavailable.</p> : <>
                    <p className="small text-muted mb-2">Holdings can use transactions through the previous day; cash and overall attention may still reflect today’s activity.</p>
                    <div className="d-flex flex-wrap gap-4 mb-2 small">
                        <span>Overall: <strong className={tone(status.overall)}>{label(status.overall)}</strong></span>
                        <span>Holdings: <strong className={tone(status.holdings)}>{label(status.holdings)}</strong></span>
                        <span>Funds: <strong className={tone(status.funds)}>{label(status.funds)}</strong></span>
                    </div>
                    {status.execution_blocked ? <div className="alert alert-danger py-2 small">New broker execution is blocked until a successful reconciliation confirms that holdings match.</div> : null}
                    {status.last_failure ? <div className="alert alert-warning py-2 small">Latest sync failed: {status.last_failure}. The last successful result remains authoritative.</div> : null}
                    <p className="small text-muted">Last successful check: {status.last_successful_at ? new Date(status.last_successful_at).toLocaleString() : 'Never'}. Correct discrepancies through normal holdings or cash transactions; broker values are never applied automatically.</p>
                    <section aria-label="Kite CNC trade sync activity" className="mb-3">
                        <h3 className="h6 mb-2">Kite CNC trade sync activity</h3>
                        <p className="small text-muted mb-2">Scheduled and reconnect syncs are shown here for this active portfolio. Kite can provide current-day trades only; historical gaps need review from a broker tradebook.</p>
                        {(snapshot.trade_sync_runs || []).length === 0 ? <p className="small text-muted">No CNC trade sync activity recorded.</p> : <div className="table-responsive">
                            <table className="table table-sm align-middle mb-0">
                                <thead><tr><th>Trading date</th><th>Portfolio</th><th>Source</th><th>Status</th><th>Last run</th><th>Added</th><th>Matched</th><th>Review</th><th>Failed / unavailable details</th></tr></thead>
                                <tbody>{snapshot.trade_sync_runs.map((run) => <tr key={run.id}>
                                    <td>{tradingDate(run.trading_date)}</td>
                                    <td>#{run.profile_id}</td>
                                    <td>{label(run.trigger)}</td>
                                    <td className={syncTone(run.status)}>{syncStatus(run)}</td>
                                    <td>{completedAt(run.completed_at || run.started_at)}</td>
                                    <td>{run.imported_count ?? 0}</td><td>{run.matched_count ?? 0}</td><td>{run.review_count ?? 0}</td>
                                    <td className="small">{run.failure_summary || (run.review_count > 0 ? `${run.review_count} fill${run.review_count === 1 ? '' : 's'} require review.` : '—')}</td>
                                </tr>)}</tbody>
                            </table>
                        </div>}
                    </section>
                    <section aria-label="Kite CNC fill review" className="mb-3">
                        <h3 className="h6 mb-2">Fill exceptions requiring review</h3>
                        <p className="small text-muted">Review acknowledgment records who inspected the evidence. It does not reconcile the fill or change StoX cash or holdings. Incomplete evidence cannot be imported.</p>
                        {reviewItems.length === 0 ? <p className="small text-muted">No row level CNC review items.</p> : <div className="table-responsive"><table className="table table-sm align-middle mb-0">
                            <thead><tr><th>Date</th><th>Evidence</th><th>Fill</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>{reviewItems.map((item) => <tr key={item.id}>
                                <td>{tradingDate(item.sync_run?.trading_date)}</td>
                                <td>{item.symbol} / {item.exchange}<div className="small text-muted">{item.evidence_kind === 'malformed_candidate' ? 'Malformed CNC candidate' : item.evidence_kind === 'legacy_unbound' ? 'Legacy account unknown' : item.evidence_kind === 'account_conflict' ? 'Legacy trade ID conflict' : `Trade ${item.broker_trade_id}`}</div></td>
                                <td>{item.side} {quantity(item.quantity)} @ ₹{amount(item.price)}</td>
                                <td className="text-warning">{label(item.status)}</td>
                                <td><button type="button" className="btn btn-link btn-sm p-0 me-2" disabled={busy} onClick={() => openReview(item)}>Evidence</button>{item.status !== 'acknowledged_unresolved' ? <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy} onClick={() => acknowledgeReview(item)}>Record review, keep unresolved</button> : <span className="small text-muted">Reviewed {completedAt(item.reviewed_at)}; still unresolved</span>}</td>
                            </tr>)}</tbody>
                        </table></div>}
                        {reviewDetail ? <div className="border rounded p-3 mt-2" aria-label="Row review details">
                            <div className="d-flex justify-content-between"><strong>{reviewDetail.symbol} {reviewDetail.side} {quantity(reviewDetail.quantity)} @ ₹{amount(reviewDetail.price)}</strong><button type="button" className="btn-close" aria-label="Close row review" onClick={() => setReviewDetail(null)} /></div>
                            <p className="small text-muted mb-2">Broker evidence is sanitized. Linking requires an exact existing transaction in this portfolio and will not create or alter a transaction.</p>
                            {reviewDetail.exact_match_candidates?.length ? <div className="d-flex gap-2 align-items-center"><select className="form-select form-select-sm" value={matchTransactionId} onChange={(event) => setMatchTransactionId(event.target.value)} aria-label="Exact existing transaction">
                                <option value="">Select exact existing transaction</option>{reviewDetail.exact_match_candidates.map((candidate) => <option key={candidate.id} value={candidate.id}>#{candidate.id} · {candidate.type} {quantity(candidate.quantity)} @ ₹{amount(candidate.price)} · {tradingDate(candidate.transaction_date)} · {candidate.source}</option>)}
                            </select><button type="button" className="btn btn-primary btn-sm" disabled={busy || !matchTransactionId} onClick={linkExistingTransaction}>Link existing</button></div> : <p className="small text-muted mb-0">No exact unlinked transaction is available.</p>}
                            <pre className="small bg-light p-2 mt-2 mb-0">{JSON.stringify(reviewDetail.evidence, null, 2)}</pre>
                        </div> : null}
                    </section>
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead><tr><th>Run</th><th>Trigger</th><th>Result</th><th>Completed</th><th /></tr></thead>
                            <tbody>{(snapshot.runs || []).slice(0, 10).map((run) => <tr key={run.id}>
                                <td>#{run.id}</td><td>{label(run.trigger)}</td><td className={tone(run.overall_status)}>{run.status === 'sync_failed' ? 'Sync failed' : label(run.overall_status)}</td>
                                <td>{completedAt(run.completed_at)}</td>
                                <td>{run.overall_status !== 'reconciled' ? <button type="button" className="btn btn-link btn-sm p-0" onClick={() => openRun(run.id)}>Evidence</button> : null}</td>
                            </tr>)}</tbody>
                        </table>
                    </div>
                    {selected ? <div className="border rounded p-3 mt-3 small">
                        <div className="d-flex justify-content-between"><strong>Run #{selected.id} evidence</strong><button type="button" className="btn-close" aria-label="Close evidence" onClick={() => setSelected(null)} /></div>
                        {selected.failure ? <p className="text-warning mb-1">Sync failure: {selected.failure}</p> : null}
                        {selected.stox_snapshot?.comparison ? <p className="text-muted mb-1">Holdings basis: {selected.stox_snapshot.comparison.holdings_as_of || 'current'} ({selected.stox_snapshot.comparison.timezone}). {selected.stox_snapshot.comparison.funds_comparison}</p> : null}
                        {selected.stox_snapshot?.comparison?.overall_status_note ? <p className="text-warning mb-1">{selected.stox_snapshot.comparison.overall_status_note}</p> : null}
                        {(discrepancies.holdings || []).length ? <div className="table-responsive mt-3">
                            <table className="table table-sm align-middle mb-0">
                                <thead><tr><th>Stock</th><th className="text-end">StoX qty</th><th className="text-end">Kite qty</th><th className="text-end">Difference</th><th className="text-end">StoX cost</th><th className="text-end">Kite cost</th><th className="text-end">Difference</th><th className="text-end">Allowed</th></tr></thead>
                                <tbody>{(discrepancies.holdings || []).map((row) => {
                                    const quantityMismatch = Number(row.brokerQty) !== Number(row.stoxQty);
                                    const costMismatch = row.costDifference !== null && row.costDifference !== undefined && Math.abs(Number(row.costDifference)) > Number(selected.tolerances?.holding_cost ?? 0);
                                    return <tr key={row.symbol}>
                                        <td>{row.symbol}{row.isin && row.stoxSymbol && row.brokerSymbol && row.stoxSymbol !== row.brokerSymbol ? <div className="text-muted small">ISIN {row.isin}; Kite: {row.brokerSymbol}</div> : null}</td>
                                        <td className="text-end"><ComparisonValue value={row.stoxQty} mismatch={quantityMismatch} /></td>
                                        <td className="text-end"><ComparisonValue value={row.brokerQty} mismatch={quantityMismatch} /></td>
                                        <td className="text-end"><ComparisonValue value={Number(row.brokerQty) - Number(row.stoxQty)} mismatch={quantityMismatch} /></td>
                                        <td className="text-end">{row.stoxCost == null ? <span className="text-muted">—</span> : <ComparisonValue value={row.stoxCost} mismatch={costMismatch} money />}</td>
                                        <td className="text-end">{row.brokerCost == null ? <span className="text-muted">—</span> : <ComparisonValue value={row.brokerCost} mismatch={costMismatch} money />}</td>
                                        <td className="text-end">{row.costDifference == null ? <span className="text-muted">—</span> : <ComparisonValue value={row.costDifference} mismatch={costMismatch} money />}</td>
                                        <td className="text-end text-muted">₹{amount(selected.tolerances?.holding_cost ?? 0)}</td>
                                    </tr>;
                                })}</tbody>
                            </table>
                        </div> : null}
                        {selected.funds_status === 'mismatch' ? <div className="table-responsive mt-3"><table className="table table-sm align-middle mb-0"><thead><tr><th>Cash</th><th className="text-end">StoX</th><th className="text-end">Kite</th><th className="text-end">Difference</th><th className="text-end">Allowed</th></tr></thead><tbody><tr><td>Cash balance</td><td className="text-end"><ComparisonValue value={discrepancies.funds?.stox_cash} mismatch money /></td><td className="text-end"><ComparisonValue value={discrepancies.funds?.broker_cash} mismatch money /></td><td className="text-end"><ComparisonValue value={discrepancies.funds?.difference} mismatch money /></td><td className="text-end text-muted">₹{amount(selected.tolerances?.funds ?? 0)}</td></tr></tbody></table></div> : null}
                        {(selected.unsupported_instruments || []).length ? <div className="text-muted mt-1">Informational unsupported Kite instruments: {selected.unsupported_instruments.map((row) => row.symbol).join(', ')}</div> : null}
                    </div> : null}
                </>}
            </div>
        </div>
    );
}
