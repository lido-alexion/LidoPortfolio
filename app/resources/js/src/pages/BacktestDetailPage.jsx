import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api';
import BacktestPortfolioChart from '../components/backtest/BacktestPortfolioChart';
import BacktestTradeTimeline from '../components/backtest/BacktestTradeTimeline';
import { ROUTES } from '../navigation/routes';
import { showToast } from '../toast';
import {
    BACKTEST_DURATION_NOTICE,
    backtestStatusBadgeClass,
    continueBacktestUntilDone,
    formatBacktestStage,
    isBacktestInProgress,
    parseTagsInput,
} from '../utils/backtestHelpers';
import {
    formatInr,
    formatInrWhole,
    formatSignedPercent2,
    formatTableInteger,
} from '../utils/tableFormat';
import { formatTransactionDateDisplay } from '../utils/transactionDate';

const STAT_LABELS = {
    initial_capital: 'Initial capital',
    final_portfolio_value: 'Final portfolio value',
    absolute_return: 'Absolute return',
    return_pct: 'Return %',
    cagr: 'CAGR',
    maximum_drawdown: 'Maximum drawdown',
    total_trades: 'Total trades',
    winning_trades: 'Winning trades',
    losing_trades: 'Losing trades',
    win_rate: 'Win rate',
    largest_winner: 'Largest winner',
    largest_loser: 'Largest loser',
    average_winner: 'Average winner',
    average_loser: 'Average loser',
    average_holding_period: 'Average holding period (days)',
    longest_holding_period: 'Longest holding period (days)',
    shortest_holding_period: 'Shortest holding period (days)',
    average_portfolio_utilization: 'Average portfolio utilization',
    cash_remaining: 'Cash remaining',
    maximum_concurrent_positions: 'Maximum concurrent positions',
};

function fmtStatValue(key, value) {
    if (value == null || value === '') return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    if (key.includes('pct') || key === 'cagr' || key === 'win_rate' || key === 'maximum_drawdown' || key === 'average_portfolio_utilization') {
        return formatSignedPercent2(n);
    }
    if (key.includes('capital') || key.includes('return') || key.includes('winner') || key.includes('loser') || key === 'cash_remaining' || key.includes('value')) {
        return formatInr(n);
    }
    if (key.includes('period') || key.includes('trades') || key.includes('positions')) {
        return formatTableInteger(n);
    }
    return n.toFixed(2);
}

function SummaryCard({ label, value }) {
    return (
        <div className="col-md-4 col-lg-2">
            <div className="border rounded p-3 h-100">
                <div className="text-muted small">{label}</div>
                <div className="fs-6 fw-semibold">{value}</div>
            </div>
        </div>
    );
}

function CollapsibleTableSection({ id, title, count, open, onToggle, children }) {
    return (
        <section className="card">
            <button
                type="button"
                className="card-header py-2 btn btn-link text-decoration-none text-body w-100 text-start border-0 rounded-0 d-flex align-items-center justify-content-between gap-2 backtest-collapse-toggle"
                onClick={onToggle}
                aria-expanded={open}
                aria-controls={id}
            >
                <span className="fw-semibold small mb-0">
                    {title}
                    {count != null ? (
                        <span className="text-muted fw-normal ms-1">
                            (
                            {count}
                            )
                        </span>
                    ) : null}
                </span>
                <i className={`bi ${open ? 'bi-chevron-up' : 'bi-chevron-down'}`} aria-hidden="true" />
            </button>
            {open ? (
                <div id={id} className="card-body p-0">
                    {children}
                </div>
            ) : null}
        </section>
    );
}

function PageScrollFab() {
    const scrollMain = (to) => {
        const main = document.querySelector('.lido-shell > .lido-main');
        if (!main) {
            window.scrollTo({ top: to === 'top' ? 0 : document.documentElement.scrollHeight, behavior: 'smooth' });
            return;
        }
        main.scrollTo({
            top: to === 'top' ? 0 : main.scrollHeight,
            behavior: 'smooth',
        });
    };

    return (
        <div className="backtest-scroll-fab" role="group" aria-label="Page scroll">
            <button
                type="button"
                className="btn btn-light border shadow-sm backtest-scroll-fab-btn"
                title="Go to top"
                aria-label="Go to top of page"
                onClick={() => scrollMain('top')}
            >
                <i className="bi bi-arrow-up" aria-hidden="true" />
            </button>
            <button
                type="button"
                className="btn btn-light border shadow-sm backtest-scroll-fab-btn"
                title="Go to bottom"
                aria-label="Go to bottom of page"
                onClick={() => scrollMain('bottom')}
            >
                <i className="bi bi-arrow-down" aria-hidden="true" />
            </button>
        </div>
    );
}

function BacktestProgressBanner({ run, resuming }) {
    if (!run || !isBacktestInProgress(run)) {
        return null;
    }

    const showEligibility = run.stage === 'PREPARING' || run.status === 'preparing';

    return (
        <div className="alert alert-info backtest-progress-banner mb-0">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <div className="fw-semibold">
                        {resuming ? 'Resuming backtest…' : 'Backtest in progress'}
                        {' · '}
                        {formatBacktestStage(run.stage)}
                    </div>
                    <div className="small">
                        {run.current_date ? `Current date: ${run.current_date}` : 'Starting…'}
                    </div>
                </div>
                <div className="text-end">
                    <div className="fw-semibold">{Number(run.progress_pct || 0).toFixed(1)}%</div>
                    <div className="small">
                        {run.processed_days ?? 0}
                        {' / '}
                        {run.total_days ?? 0}
                        {' days'}
                    </div>
                </div>
            </div>
            <div className="progress mb-2" style={{ height: '0.5rem' }}>
                <div
                    className="progress-bar progress-bar-striped progress-bar-animated"
                    role="progressbar"
                    style={{ width: `${Math.min(100, Math.max(0, Number(run.progress_pct || 0)))}%` }}
                />
            </div>
            {showEligibility && (
                <div className="small mb-0">
                    Eligibility
                    {run.eligibility_phase ? `: ${run.eligibility_phase}` : ''}
                    {' · '}
                    {Number(run.eligibility_progress || 0).toFixed(1)}%
                </div>
            )}
            <p className="small mb-0 mt-2">{BACKTEST_DURATION_NOTICE}</p>
        </div>
    );
}

export default function BacktestDetailPage() {
    const { id } = useParams();
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(true);
    const [resuming, setResuming] = useState(false);
    const [savingMeta, setSavingMeta] = useState(false);
    const [editName, setEditName] = useState('');
    const [editNotes, setEditNotes] = useState('');
    const [editTags, setEditTags] = useState('');
    const [tradesOpen, setTradesOpen] = useState(false);
    const [transactionsOpen, setTransactionsOpen] = useState(false);
    const resumeAttemptedRef = useRef(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get(`/v1/backtests/${id}`);
            const data = res.data?.data || null;
            setDetail(data);
            if (data) {
                setEditName(data.name || '');
                setEditNotes(data.notes || '');
                setEditTags(Array.isArray(data.tags) ? data.tags.join(', ') : '');
            }
            return data;
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load backtest', 'danger');
            setDetail(null);
            return null;
        } finally {
            setLoading(false);
        }
    }, [id]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        resumeAttemptedRef.current = false;
    }, [id]);

    useEffect(() => {
        let cancelled = false;

        async function resumeIfNeeded() {
            if (!detail || !isBacktestInProgress(detail) || resumeAttemptedRef.current) {
                return;
            }
            resumeAttemptedRef.current = true;
            setResuming(true);
            try {
                await continueBacktestUntilDone(Number(id), (run) => {
                    if (!cancelled) {
                        setDetail((prev) => ({ ...(prev || {}), ...run }));
                    }
                });
                if (!cancelled) {
                    await load();
                }
            } catch (e) {
                if (!cancelled) {
                    showToast(e?.response?.data?.error?.message || e.message || 'Resume failed', 'danger');
                }
            } finally {
                if (!cancelled) {
                    setResuming(false);
                }
            }
        }

        resumeIfNeeded();

        return () => {
            cancelled = true;
        };
    }, [detail?.id, detail?.status, id, load]);

    const stats = detail?.statistics || {};

    const summaryCards = useMemo(() => ([
        { label: 'Return %', value: fmtStatValue('return_pct', stats.return_pct) },
        { label: 'CAGR', value: fmtStatValue('cagr', stats.cagr) },
        { label: 'Max drawdown', value: fmtStatValue('maximum_drawdown', stats.maximum_drawdown) },
        { label: 'Win rate', value: fmtStatValue('win_rate', stats.win_rate) },
        { label: 'Total trades', value: fmtStatValue('total_trades', stats.total_trades) },
        { label: 'Final value', value: fmtStatValue('final_portfolio_value', stats.final_portfolio_value) },
    ]), [stats]);

    const onSaveMeta = async () => {
        setSavingMeta(true);
        try {
            const res = await api.put(`/v1/backtests/${id}`, {
                name: editName.trim() || undefined,
                notes: editNotes,
                tags: parseTagsInput(editTags),
            });
            const updated = res.data?.data;
            setDetail((prev) => ({ ...(prev || {}), ...updated }));
            showToast('Backtest updated', 'success');
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Save failed', 'danger');
        } finally {
            setSavingMeta(false);
        }
    };

    if (loading && !detail) {
        return <p className="text-muted">Loading backtest…</p>;
    }

    if (!detail) {
        return (
            <div className="d-grid gap-3">
                <p className="text-muted mb-0">Backtest not found.</p>
                <Link to={ROUTES.BACKTESTS} className="btn btn-outline-secondary btn-sm align-self-start">
                    Back to Backtests
                </Link>
            </div>
        );
    }

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <h2 className="h4 mb-0">{detail.name || `Backtest #${detail.id}`}</h2>
                        <span className={`badge ${backtestStatusBadgeClass(detail.status)}`}>{detail.status}</span>
                    </div>
                    <div className="text-muted small">
                        {detail.strategy_name || '—'}
                        {detail.strategy_version != null ? ` · v${detail.strategy_version}` : ''}
                        {' · '}
                        {detail.from_date}
                        {' → '}
                        {detail.to_date}
                        {detail.range_key ? ` (${detail.range_key})` : ''}
                    </div>
                    {Array.isArray(detail.tags) && detail.tags.length > 0 && (
                        <div className="d-flex flex-wrap gap-1 mt-2">
                            {detail.tags.map((tag) => (
                                <span key={tag} className="badge text-bg-light border">{tag}</span>
                            ))}
                        </div>
                    )}
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link to={ROUTES.BACKTESTS} className="btn btn-outline-secondary btn-sm">All backtests</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading || resuming}>
                        Refresh
                    </button>
                </div>
            </div>

            <BacktestProgressBanner run={detail} resuming={resuming} />

            {detail.error_message && (
                <div className="alert alert-danger mb-0">{detail.error_message}</div>
            )}

            <div className="card">
                <div className="card-body d-grid gap-3">
                    <div className="row g-2">
                        <div className="col-md-4">
                            <label className="form-label small mb-1" htmlFor="bt-detail-name">Name</label>
                            <input
                                id="bt-detail-name"
                                className="form-control form-control-sm"
                                value={editName}
                                onChange={(e) => setEditName(e.target.value)}
                            />
                        </div>
                        <div className="col-md-8">
                            <label className="form-label small mb-1" htmlFor="bt-detail-tags">Tags</label>
                            <input
                                id="bt-detail-tags"
                                className="form-control form-control-sm"
                                value={editTags}
                                onChange={(e) => setEditTags(e.target.value)}
                                placeholder="comma-separated"
                            />
                        </div>
                        <div className="col-12">
                            <label className="form-label small mb-1" htmlFor="bt-detail-notes">Notes</label>
                            <textarea
                                id="bt-detail-notes"
                                className="form-control form-control-sm"
                                rows={3}
                                value={editNotes}
                                onChange={(e) => setEditNotes(e.target.value)}
                            />
                        </div>
                    </div>
                    <div>
                        <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            onClick={onSaveMeta}
                            disabled={savingMeta}
                        >
                            {savingMeta ? 'Saving…' : 'Save name / notes / tags'}
                        </button>
                    </div>
                </div>
            </div>

            <section>
                <h2 className="h6 text-muted text-uppercase mb-2">Summary</h2>
                <div className="row g-3">
                    {summaryCards.map((card) => (
                        <SummaryCard key={card.label} {...card} />
                    ))}
                </div>
            </section>

            <section className="card">
                <div className="card-header py-2">
                    <div className="fw-semibold small mb-0">Portfolio growth</div>
                </div>
                <div className="card-body pt-2">
                    <BacktestPortfolioChart chart={detail.chart} snapshots={detail.snapshots} />
                </div>
            </section>

            <section className="card">
                <div className="card-header py-2">
                    <div className="fw-semibold small mb-0">Trade timeline</div>
                    <div className="text-muted small">Green = profitable closed trade · Red = loss · Muted = open holding</div>
                </div>
                <div className="card-body pt-2">
                    <BacktestTradeTimeline timeline={detail.timeline} />
                </div>
            </section>

            <CollapsibleTableSection
                id="backtest-trades-table"
                title="Trades"
                count={(detail.trades || []).length}
                open={tradesOpen}
                onToggle={() => setTradesOpen((v) => !v)}
            >
                <div className="table-responsive">
                    <table className="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Buy</th>
                                <th>Sell</th>
                                <th className="text-end">Qty</th>
                                <th className="text-end">Buy price</th>
                                <th className="text-end">Sell price</th>
                                <th className="text-end">P/L</th>
                                <th className="text-end">Return %</th>
                                <th className="text-end">Days</th>
                                <th>Exit</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(detail.trades || []).length === 0 ? (
                                <tr><td colSpan={11} className="text-muted small p-3">No trades.</td></tr>
                            ) : (
                                detail.trades.map((t) => (
                                    <tr key={t.id}>
                                        <td className="fw-semibold">{t.symbol}</td>
                                        <td className="small">{t.buy_date}</td>
                                        <td className="small">{t.sell_date || '—'}</td>
                                        <td className="text-end">{formatTableInteger(t.quantity)}</td>
                                        <td className="text-end">{formatInr(t.buy_price)}</td>
                                        <td className="text-end">{t.sell_price != null ? formatInr(t.sell_price) : '—'}</td>
                                        <td className="text-end">{t.profit_loss != null ? formatInr(t.profit_loss) : '—'}</td>
                                        <td className="text-end">{t.return_pct != null ? formatSignedPercent2(t.return_pct) : '—'}</td>
                                        <td className="text-end">{t.holding_days ?? '—'}</td>
                                        <td className="small">{t.exit_reason || '—'}</td>
                                        <td>{t.is_open ? <span className="badge text-bg-secondary">Open</span> : <span className="badge text-bg-light border">Closed</span>}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </CollapsibleTableSection>

            <CollapsibleTableSection
                id="backtest-transactions-table"
                title="Transactions"
                count={(detail.transactions || []).length}
                open={transactionsOpen}
                onToggle={() => setTransactionsOpen((v) => !v)}
            >
                <div className="table-responsive">
                    <table className="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th className="text-end">Qty</th>
                                <th className="text-end">Price</th>
                                <th className="text-end">Value</th>
                                <th>Reason</th>
                                <th>Recommendation</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(detail.transactions || []).length === 0 ? (
                                <tr><td colSpan={8} className="text-muted small p-3">No transactions.</td></tr>
                            ) : (
                                detail.transactions.map((tx) => (
                                    <tr key={tx.id}>
                                        <td className="small">{tx.date}</td>
                                        <td className="fw-semibold">{tx.symbol}</td>
                                        <td>
                                            <span className={`badge ${tx.side === 'BUY' || tx.side === 'buy' ? 'text-bg-success' : 'text-bg-danger'}`}>
                                                {tx.side}
                                            </span>
                                        </td>
                                        <td className="text-end">{formatTableInteger(tx.quantity)}</td>
                                        <td className="text-end">{formatInr(tx.price)}</td>
                                        <td className="text-end">{formatInr(tx.value)}</td>
                                        <td className="small">{tx.reason || '—'}</td>
                                        <td className="small">{tx.recommendation || '—'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </CollapsibleTableSection>

            <section className="card">
                <div className="card-header py-2 fw-semibold small">Daily snapshots</div>
                <div className="card-body p-0">
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th className="text-end">Portfolio</th>
                                    <th className="text-end">Cash</th>
                                    <th className="text-end">Invested</th>
                                    <th className="text-end">Realized P/L</th>
                                    <th className="text-end">Unrealized P/L</th>
                                    <th className="text-end">Drawdown</th>
                                    <th className="text-end">Holdings</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(detail.snapshots || []).length === 0 ? (
                                    <tr><td colSpan={8} className="text-muted small p-3">No snapshots yet.</td></tr>
                                ) : (
                                    detail.snapshots.map((s) => (
                                        <tr key={s.date}>
                                            <td className="small">{s.date}</td>
                                            <td className="text-end">{formatInrWhole(s.portfolio_value)}</td>
                                            <td className="text-end">{formatInrWhole(s.cash)}</td>
                                            <td className="text-end">{formatInrWhole(s.invested_value)}</td>
                                            <td className="text-end">{formatInr(s.realized_profit)}</td>
                                            <td className="text-end">{formatInr(s.unrealized_profit)}</td>
                                            <td className="text-end">{formatSignedPercent2(s.drawdown_pct)}</td>
                                            <td className="text-end">{s.holdings_count}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section className="card">
                <div className="card-header py-2 fw-semibold small">Full statistics</div>
                <div className="card-body">
                    <div className="row g-3">
                        {Object.entries(STAT_LABELS).map(([key, label]) => (
                            <div className="col-md-4" key={key}>
                                <div className="border rounded p-2 h-100">
                                    <div className="text-muted small">{label}</div>
                                    <div className="fw-semibold">{fmtStatValue(key, stats[key])}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                    {detail.execution_seconds != null && (
                        <p className="text-muted small mt-3 mb-0">
                            Execution time:
                            {' '}
                            {Number(detail.execution_seconds).toFixed(1)}
                            s · Started
                            {' '}
                            {formatTransactionDateDisplay(detail.started_at)}
                            {detail.completed_at ? ` · Completed ${formatTransactionDateDisplay(detail.completed_at)}` : ''}
                        </p>
                    )}
                </div>
            </section>

            <PageScrollFab />
        </div>
    );
}
