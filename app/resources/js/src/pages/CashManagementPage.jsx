import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import NumberInput from '../components/NumberInput';
import TransactionDateInput from '../components/TransactionDateInput';
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
    const [loading, setLoading] = useState(true);
    const [showReservations, setShowReservations] = useState(false);
    const [op, setOp] = useState('deposit');
    const [amount, setAmount] = useState('');
    const [remarks, setRemarks] = useState('');
    const [transactionDate, setTransactionDate] = useState(() => getLocalTodayDateString());
    const [transactionDateInput, setTransactionDateInput] = useState(() => (
        formatTransactionDateDisplay(getLocalTodayDateString())
    ));
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        if (!profileId) {
            setSummary(null);
            setLedger([]);
            setLoading(false);
            return;
        }
        setLoading(true);
        try {
            const [summaryRes, ledgerRes] = await Promise.all([
                api.get('/cash', { params: { include_reservations: true } }),
                api.get('/cash/ledger', { params: { limit: 100 } }),
            ]);
            setSummary(summaryRes.data?.data || null);
            setLedger(Array.isArray(ledgerRes.data?.data) ? ledgerRes.data.data : []);
        } catch (e) {
            showToast(e?.response?.data?.message || e.message || 'Failed to load cash', 'danger');
            setSummary(null);
            setLedger([]);
        } finally {
            setLoading(false);
        }
    }, [profileId]);

    useEffect(() => { load(); }, [load]);

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
            await api.post(selected.endpoint, {
                amount: num,
                remarks: remarks.trim() || undefined,
                transaction_date: transactionDate,
            });
            showToast(`${selected.label} recorded`, 'success');
            setAmount('');
            setRemarks('');
            resetFormDates();
            notifyPortfolioDashboardRefresh();
            await load();
        } catch (err) {
            const msg = err?.response?.data?.message
                || err?.response?.data?.errors?.amount?.[0]
                || err?.response?.data?.errors?.remarks?.[0]
                || err?.response?.data?.errors?.reason?.[0]
                || err?.response?.data?.errors?.transaction_date?.[0]
                || err.message
                || `${selected.label} failed`;
            showToast(msg, 'danger');
        } finally {
            setBusy(false);
        }
    };

    const reservations = summary?.reservations || [];
    const selectedOp = OPS.find((o) => o.id === op) || OPS[0];
    const availableCash = Number(summary?.available_investable_cash ?? 0);

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Cash management</h1>
                    <p className="text-muted small mb-0">
                        Deposit, withdraw, and adjust portfolio cash. Reserved cash is committed to approved buys awaiting execution.
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
                                    <div className="h5 m-0">{money(summary?.available_investable_cash)}</div>
                                    <div className="text-muted small mt-1">Balance − reserved</div>
                                </div>
                            </div>
                        </div>
                    </div>

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
