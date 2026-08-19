import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import NumberInput from '../components/NumberInput';
import TransactionDateInput from '../components/TransactionDateInput';
import useApiGet from '../hooks/useApiGet';
import { runApiMutation } from '../hooks/useApiMutation';
import { showToast } from '../toast';
import { formatInrWhole } from '../utils/tableFormat';
import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';
import { usePortfolio } from '../context/PortfolioContext';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
} from '../utils/transactionDate';

const OPS = [
    { id: 'deposit', label: 'Deposit', endpoint: '/cash/deposit', amountPlaceholder: 'Amount deposited' },
    { id: 'withdraw', label: 'Withdraw', endpoint: '/cash/withdraw', amountPlaceholder: 'Amount withdrawn' },
    { id: 'adjust', label: 'Adjust', endpoint: '/cash/adjust', amountPlaceholder: 'Amount adjusted' },
];

function money(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return formatInrWhole(v);
}

function entryTypeLabel(type) {
    switch (type) {
        case 'deposit': return 'Deposit';
        case 'withdrawal': return 'Withdrawal';
        case 'adjustment': return 'Adjustment';
        case 'buy': return 'Buy';
        case 'sell': return 'Sell';
        default: return type || '—';
    }
}

function fmtEntryDate(entry) {
    if (entry?.entry_date) {
        return formatTransactionDateDisplay(entry.entry_date) || entry.entry_date;
    }
    if (!entry?.created_at) return '—';
    try {
        return formatTransactionDateDisplay(String(entry.created_at).slice(0, 10))
            || new Date(entry.created_at).toLocaleDateString();
    } catch {
        return entry.created_at;
    }
}

function fmtWhen(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

export default function CashManagementPage() {
    const { activePortfolio } = usePortfolio();
    const profileId = activePortfolio?.id;

    const [summary, setSummary] = useState(null);
    const [ledger, setLedger] = useState([]);
    const [showReservations, setShowReservations] = useState(false);
    const [op, setOp] = useState('deposit');
    const [amount, setAmount] = useState('');
    const [remarks, setRemarks] = useState('');
    const [transactionDate, setTransactionDate] = useState(() => getLocalTodayDateString());
    const [transactionDateInput, setTransactionDateInput] = useState(() => (
        formatTransactionDateDisplay(getLocalTodayDateString())
    ));
    const [busy, setBusy] = useState(false);
    const [allocDraft, setAllocDraft] = useState([]);
    const [allocBusy, setAllocBusy] = useState(false);

    useEffect(() => {
        if (!profileId) {
            setSummary(null);
            setLedger([]);
        }
    }, [profileId]);

    const { loading, reload: load } = useApiGet({
        deps: [profileId],
        enabled: Boolean(profileId),
        errorFallback: 'Failed to load cash',
        onError: () => {
            setSummary(null);
            setLedger([]);
        },
        request: async () => {
            const [summaryRes, ledgerRes] = await Promise.all([
                api.get('/cash', { params: { include_reservations: true }, skipErrorToast: true }),
                api.get('/cash/ledger', { params: { limit: 100 }, skipErrorToast: true }),
            ]);
            const nextSummary = summaryRes.data?.data || null;
            const nextLedger = Array.isArray(ledgerRes.data?.data) ? ledgerRes.data.data : [];
            setSummary(nextSummary);
            setLedger(nextLedger);
            const strategies = Array.isArray(nextSummary?.strategies) ? nextSummary.strategies : [];
            setAllocDraft(strategies.map((row) => ({
                strategy_id: row.strategy_id,
                name: row.name,
                allocation_pct: row.allocation_pct == null ? '' : String(row.allocation_pct),
            })));
            return { summary: nextSummary, ledger: nextLedger };
        },
    });

    const resetFormDates = () => {
        const today = getLocalTodayDateString();
        setTransactionDate(today);
        setTransactionDateInput(formatTransactionDateDisplay(today));
    };

    const submitMutation = async (e) => {
        e.preventDefault();
        const selected = OPS.find((o) => o.id === op) || OPS[0];
        const num = Number(amount);
        if (amount === '' || amount === '-' || Number.isNaN(num)
            || (op !== 'adjust' && !(num > 0))
            || (op === 'adjust' && num === 0)) {
            showToast(op === 'adjust' ? 'Enter a non-zero whole-rupee amount' : 'Enter a positive whole-rupee amount', 'danger');
            return;
        }
        if (!Number.isInteger(num)) {
            showToast('Amount must be a whole number of rupees', 'danger');
            return;
        }
        if (op === 'withdraw') {
            const available = Number(summary?.available_investable_cash ?? 0);
            if (num > available) {
                showToast(`Withdrawal cannot exceed available cash (${money(available)})`, 'danger');
                return;
            }
        }
        if (!transactionDate) {
            showToast('Select a transaction date', 'danger');
            return;
        }
        setBusy(true);
        try {
            await runApiMutation(async () => {
                await api.post(selected.endpoint, {
                    amount: num,
                    remarks: remarks.trim() || undefined,
                    transaction_date: transactionDate,
                }, { skipErrorToast: true });
                setAmount('');
                setRemarks('');
                resetFormDates();
                notifyPortfolioDashboardRefresh();
                await load();
            }, {
                successMessage: `${selected.label} recorded`,
                errorFallback: `${selected.label} failed`,
            });
        } finally {
            setBusy(false);
        }
    };

    const reservations = summary?.reservations || [];
    const selectedOp = OPS.find((o) => o.id === op) || OPS[0];
    const availableCash = Number(summary?.available_physical_cash ?? summary?.available_investable_cash ?? 0);
    const allocSum = allocDraft.reduce((sum, row) => sum + (Number(row.allocation_pct) || 0), 0);
    const allocSumIs100 = Math.abs(allocSum - 100) <= 0.01;

    const saveAllocations = async () => {
        if (!allocDraft.length) {
            showToast('No enabled strategies to allocate', 'danger');
            return;
        }
        setAllocBusy(true);
        try {
            await runApiMutation(async () => {
                await api.put('/v1/capital/allocations', {
                    allocations: allocDraft.map((row) => ({
                        strategy_id: row.strategy_id,
                        allocation_pct: Number(row.allocation_pct),
                    })),
                }, { skipErrorToast: true });
                notifyPortfolioDashboardRefresh();
                await load();
            }, {
                successMessage: 'Strategy allocations saved',
                errorFallback: 'Could not save allocations. Enabled strategy percentages must sum to 100.',
            });
        } finally {
            setAllocBusy(false);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Cash management</h1>
                    <p className="text-muted small mb-0">
                        Deposit, withdraw, and adjust portfolio cash. Physical cash is one portfolio pool.
                        Reserved cash is committed to approved buys awaiting execution. Strategy allocation
                        percentages are a capital-accounting policy over that same pool — not separate bank accounts.
                    </p>
                </div>
                <div className="d-flex gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to="/transactions/pending">Pending execution</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading}>
                        Refresh
                    </button>
                </div>
            </div>

            {loading && !summary ? (
                <p className="text-muted">Loading…</p>
            ) : (
                <>
                    <div className="row g-3 mb-3">
                        <div className="col-12 col-md-4">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="text-muted small">Cash balance</div>
                                    <div className="h5 m-0">{money(summary?.cash_balance)}</div>
                                    <div className="text-muted small mt-1">Physical portfolio cash</div>
                                </div>
                            </div>
                        </div>
                        <div className="col-12 col-md-4">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="text-muted small">Reserved cash</div>
                                    <div className="h5 m-0">{money(summary?.reserved_cash)}</div>
                                    <button
                                        type="button"
                                        className="btn btn-link btn-sm px-0 mt-1"
                                        onClick={() => setShowReservations((v) => !v)}
                                        disabled={!reservations.length && !(summary?.reserved_cash > 0)}
                                    >
                                        {showReservations ? 'Hide reservation details' : 'See reservation details'}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div className="col-12 col-md-4">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="text-muted small">Available cash</div>
                                    <div className="h5 m-0">{money(summary?.available_physical_cash ?? summary?.available_investable_cash)}</div>
                                    <div className="text-muted small mt-1">Balance − reserved (physical)</div>
                                </div>
                            </div>
                        </div>
                        <div className="col-12 col-md-4">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="text-muted small">Required cash reserve</div>
                                    <div className="h5 m-0">{money(summary?.required_cash_reserve)}</div>
                                    <div className="text-muted small mt-1">
                                        {summary?.portfolio_cash_reserve_pct != null
                                            ? `${summary.portfolio_cash_reserve_pct}% of max(invested, holdings market value)`
                                            : 'Portfolio-level; not investable or lendable'}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="col-12 col-md-4">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="text-muted small">Unallocated Cash</div>
                                    <div className="h5 m-0">{money(summary?.unallocated_cash)}</div>
                                    <div className="text-muted small mt-1">
                                        Presentation only — residual after reserve not claimed by unused strategy allocation
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="col-12 col-md-4">
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="text-muted small">Investable capital</div>
                                    <div className="h5 m-0">{money(summary?.investable_capital)}</div>
                                    <div className="text-muted small mt-1">
                                        After reserve and pending reservations, plus strategy-owned holdings
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {summary?.reserve_shortfall_exists ? (
                        <div className="alert alert-warning" role="alert">
                            Portfolio cash reserve is below the required level. Replenish portfolio/broker cash.
                            Withdrawals are still recorded; recommendations are not cancelled solely for this shortfall.
                        </div>
                    ) : null}

                    {showReservations ? (
                        <div className="card mb-3">
                            <div className="card-header d-flex justify-content-between align-items-center">
                                <span>Reservation details</span>
                                <Link to="/transactions/pending" className="small">Open pending execution</Link>
                            </div>
                            <div className="card-body p-0">
                                {reservations.length === 0 ? (
                                    <p className="text-muted mb-0 p-3">No active cash reservations.</p>
                                ) : (
                                    <div className="table-responsive">
                                        <table className="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Symbol</th>
                                                    <th>Action</th>
                                                    <th className="text-end">Reserved</th>
                                                    <th className="text-end">Qty</th>
                                                    <th className="text-end">Ref. price</th>
                                                    <th>Reserved at</th>
                                                    <th>Rec.</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {reservations.map((r) => (
                                                    <tr key={r.recommendation_id}>
                                                        <td>
                                                            <div className="fw-semibold">{r.symbol || '—'}</div>
                                                            {r.name ? <div className="text-muted small">{r.name}</div> : null}
                                                        </td>
                                                        <td>{r.ui_label || r.portfolio_action || '—'}</td>
                                                        <td className="text-end">{money(r.reserved_amount)}</td>
                                                        <td className="text-end">{r.suggested_quantity ?? '—'}</td>
                                                        <td className="text-end">{r.reference_price != null ? money(r.reference_price) : '—'}</td>
                                                        <td className="small">{fmtWhen(r.reserved_at || r.approved_at)}</td>
                                                        <td>
                                                            <Link to="/recommendations" className="small">#{r.recommendation_id}</Link>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    ) : null}

                    {Array.isArray(summary?.strategies) && summary.strategies.length > 0 ? (
                        <div className="card mb-3">
                            <div className="card-header">Strategy capital allocation</div>
                            <div className="card-body">
                                <p className="text-muted small">
                                    Each enabled strategy claims a share of investable capital from the same physical cash pool.
                                    Percentages must sum to 100. They are not normalized automatically.
                                    Retained capital is an accounting floor, not a cash ledger balance.
                                </p>
                                {!summary?.capital?.allocation_pct_sum_is_100 ? (
                                    <div className="alert alert-secondary py-2">
                                        Stored enabled allocations currently sum to {summary?.capital?.allocation_pct_sum ?? '—'}.
                                        Accounting uses the stored percentages as-is until you save a 100% set.
                                    </div>
                                ) : null}
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-2">
                                        <thead>
                                            <tr>
                                                <th>Strategy</th>
                                                <th className="text-end" style={{ width: '8rem' }}>Allocation %</th>
                                                <th className="text-end">Allocated capital</th>
                                                <th className="text-end">Unused allocation</th>
                                                <th className="text-end">Retained capital</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {allocDraft.map((row, idx) => {
                                                const live = (summary.strategies || []).find(
                                                    (s) => s.strategy_id === row.strategy_id,
                                                );
                                                return (
                                                    <tr key={row.strategy_id}>
                                                        <td>
                                                            <div className="fw-semibold">{row.name || `Strategy #${row.strategy_id}`}</div>
                                                            <div className="text-muted small">
                                                                Owned MV {money(live?.strategy_owned_market_value)}
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <NumberInput
                                                                className="form-control form-control-sm text-end"
                                                                min="0"
                                                                max="100"
                                                                step="0.01"
                                                                value={row.allocation_pct}
                                                                onChange={(e) => {
                                                                    const next = [...allocDraft];
                                                                    next[idx] = {
                                                                        ...next[idx],
                                                                        allocation_pct: e.target.value,
                                                                    };
                                                                    setAllocDraft(next);
                                                                }}
                                                            />
                                                        </td>
                                                        <td className="text-end">{money(live?.strategy_capital_allocation)}</td>
                                                        <td className="text-end">{money(live?.unused_allocation)}</td>
                                                        <td className="text-end">
                                                            {live?.minimum_retained_capital == null
                                                                ? '—'
                                                                : money(live.minimum_retained_capital)}
                                                            <div className="text-muted small">Not physical cash</div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="d-flex flex-wrap align-items-center gap-2">
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-primary"
                                        onClick={saveAllocations}
                                        disabled={allocBusy || !profileId}
                                    >
                                        {allocBusy ? 'Saving…' : 'Save allocations'}
                                    </button>
                                    <span className={`small ${allocSumIs100 ? 'text-muted' : 'text-danger'}`}>
                                        Draft sum: {allocSum.toFixed(2)}%
                                        {allocSumIs100 ? '' : ' — must equal 100 to save'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    ) : null}

                    <div className="card mb-3">
                        <div className="card-header">Record cash movement</div>
                        <div className="card-body">
                            <div className="btn-group mb-3" role="group" aria-label="Cash operation">
                                {OPS.map((o) => (
                                    <button
                                        key={o.id}
                                        type="button"
                                        className={`btn btn-sm ${op === o.id ? 'btn-primary' : 'btn-outline-primary'}`}
                                        onClick={() => {
                                            setOp(o.id);
                                            setAmount('');
                                        }}
                                    >
                                        {o.label}
                                    </button>
                                ))}
                            </div>
                            <form className="row g-2 align-items-end" onSubmit={submitMutation}>
                                <div className="col-12 col-md-3">
                                    <label className="form-label small mb-0" htmlFor="cash-amount">Amount</label>
                                    <NumberInput
                                        id="cash-amount"
                                        step="1"
                                        min={op === 'adjust' ? undefined : '1'}
                                        max={op === 'withdraw' ? String(Math.max(0, Math.floor(availableCash))) : undefined}
                                        allowDecimals={false}
                                        allowNegative={op === 'adjust'}
                                        placeholder={selectedOp.amountPlaceholder}
                                        value={amount}
                                        onChange={(e) => setAmount(e.target.value)}
                                        required
                                    />
                                </div>
                                <div className="col-12 col-md-3">
                                    <label className="form-label small mb-0" htmlFor="cash-date">Transaction date</label>
                                    <TransactionDateInput
                                        id="cash-date"
                                        displayValue={transactionDateInput}
                                        isoValue={transactionDate}
                                        fallbackIso={transactionDate}
                                        required
                                        onDisplayChange={setTransactionDateInput}
                                        onIsoChange={setTransactionDate}
                                    />
                                </div>
                                <div className="col-12 col-md-4">
                                    <label className="form-label small mb-0" htmlFor="cash-remarks">Remarks</label>
                                    <input
                                        id="cash-remarks"
                                        type="text"
                                        className="form-control form-control-sm"
                                        value={remarks}
                                        onChange={(e) => setRemarks(e.target.value)}
                                        placeholder="Optional remarks"
                                        maxLength={500}
                                    />
                                </div>
                                <div className="col-auto">
                                    <button type="submit" className="btn btn-sm btn-primary" disabled={busy || !profileId}>
                                        {busy ? 'Saving…' : selectedOp.label}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Cash account statement</div>
                        <div className="card-body p-0">
                            {ledger.length === 0 ? (
                                <p className="text-muted mb-0 p-3">No cash ledger entries yet. Deposit cash to start.</p>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th className="text-end">Amount</th>
                                                <th className="text-end">Balance after</th>
                                                <th>Remarks</th>
                                                <th>Links</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {ledger.map((entry) => (
                                                <tr key={entry.id}>
                                                    <td className="small">{fmtEntryDate(entry)}</td>
                                                    <td>{entryTypeLabel(entry.entry_type)}</td>
                                                    <td className={`text-end ${Number(entry.amount) < 0 ? 'text-danger' : Number(entry.amount) > 0 ? 'text-success' : ''}`}>
                                                        {money(entry.amount)}
                                                    </td>
                                                    <td className="text-end">{money(entry.balance_after)}</td>
                                                    <td className="small">{entry.reason || '—'}</td>
                                                    <td className="small text-muted">
                                                        {entry.transaction_id ? `Tx #${entry.transaction_id}` : ''}
                                                        {entry.transaction_id && entry.recommendation_id ? ' · ' : ''}
                                                        {entry.recommendation_id ? `Rec #${entry.recommendation_id}` : ''}
                                                        {!entry.transaction_id && !entry.recommendation_id ? '—' : ''}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
