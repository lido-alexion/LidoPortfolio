import React, { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import TransactionDateInput from '../components/TransactionDateInput';
import useApiGet from '../hooks/useApiGet';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { ROUTES } from '../navigation/routes';
import { usePortfolio } from '../context/PortfolioContext';
import {
    formatSignedPercent2,
    formatSignedTableMoney2,
    formatTableMoney2,
    percentChangeColorClass,
} from '../utils/tableFormat';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
    isTransactionDateInFuture,
    isValidTransactionDate,
    parseTransactionDateDisplay,
} from '../utils/transactionDate';

function moneyOrUnavailable(value) {
    if (value == null || Number.isNaN(Number(value))) {
        return <span className="text-muted">Unavailable</span>;
    }
    return formatTableMoney2(value);
}

function signedMoneyOrUnavailable(value) {
    if (value == null || Number.isNaN(Number(value))) {
        return <span className="text-muted">Unavailable</span>;
    }
    const formatted = formatSignedTableMoney2(value);
    if (formatted === '—') {
        return <span className="text-muted">—</span>;
    }
    return (
        <span className={percentChangeColorClass(value)}>
            {formatted}
        </span>
    );
}

export default function HistoricalHoldingsPage() {
    const { activePortfolio } = usePortfolio();
    const profileId = activePortfolio?.id;
    const today = getLocalTodayDateString();
    const [asOf, setAsOf] = useState(today);
    const [asOfDisplay, setAsOfDisplay] = useState(formatTransactionDateDisplay(today));

    const asOfValid = isValidTransactionDate(asOf) && !isTransactionDateInFuture(asOf);

    const loadHistorical = useCallback(async () => {
        if (!asOfValid) {
            return null;
        }
        const { data } = await api.get('/portfolio/historical-holdings', {
            params: { as_of: asOf },
            skipErrorToast: true,
        });
        return data;
    }, [asOf, asOfValid]);

    const {
        data,
        loading,
        error,
        reload,
    } = useApiGet({
        request: loadHistorical,
        deps: [profileId, asOf, asOfValid],
        enabled: Boolean(profileId) && asOfValid,
        errorFallback: 'Failed to load historical holdings',
        initialData: null,
    });

    usePortfolioChanged(useCallback(() => {
        reload();
    }, [reload]));

    const holdings = data?.holdings || [];
    const warnings = data?.warnings || [];
    const totals = data?.totals || {};
    const completeness = data?.completeness || {};
    const valuationComplete = completeness.valuation_complete !== false
        && totals.valuation_complete !== false;
    const missingPriceCount = Number(completeness.missing_price_count || 0);

    const empty = !loading && !error && asOfValid && data && holdings.length === 0;

    const applyAsOf = useCallback((iso, display) => {
        if (!iso || !isValidTransactionDate(iso) || isTransactionDateInFuture(iso)) {
            return;
        }
        setAsOf(iso);
        setAsOfDisplay(display ?? formatTransactionDateDisplay(iso));
    }, []);

    const warningSummary = useMemo(() => {
        if (warnings.length === 0) {
            return null;
        }
        const symbols = [...new Set(warnings.map((w) => w.symbol || `stock #${w.stock_id}`))];
        return `${warnings.length} historical inconsistency warning(s)`
            + (symbols.length ? ` involving ${symbols.join(', ')}` : '');
    }, [warnings]);

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Historical Holdings</h1>
                    <p className="text-muted small mb-0">
                        Reconstruct open positions as of a past date from the transaction ledger
                        (not live Holdings, and not Portfolio Snapshots).
                        {' '}
                        <Link to={ROUTES.HOLDINGS}>Live Holdings</Link>
                        {' · '}
                        <Link to={ROUTES.PORTFOLIO_SNAPSHOTS}>Portfolio Snapshots</Link>
                    </p>
                </div>
                <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    onClick={() => reload()}
                    disabled={loading || !asOfValid}
                >
                    Refresh
                </button>
            </div>

            <div className="card mb-3">
                <div className="card-body py-3">
                    <div className="row g-3 align-items-end">
                        <div className="col-md-4 col-lg-3">
                            <label className="form-label small mb-1" htmlFor="f014-as-of">
                                As-of date
                            </label>
                            <TransactionDateInput
                                id="f014-as-of"
                                displayValue={asOfDisplay}
                                isoValue={asOf}
                                onDisplayChange={(display) => {
                                    setAsOfDisplay(display);
                                    const iso = parseTransactionDateDisplay(display);
                                    if (iso && isValidTransactionDate(iso) && !isTransactionDateInFuture(iso)) {
                                        setAsOf(iso);
                                    }
                                }}
                                onIsoChange={(iso) => applyAsOf(iso)}
                            />
                            {!asOfValid && (
                                <div className="form-text text-danger">
                                    Choose a valid date on or before today.
                                </div>
                            )}
                        </div>
                        <div className="col-md-8 col-lg-9 small text-muted">
                            Includes transactions on the selected date.
                            Weekends and holidays use the latest available price on or before that date.
                        </div>
                    </div>
                </div>
            </div>

            {warningSummary && (
                <div className="alert alert-warning py-2 small" role="status">
                    <strong>Historical data inconsistency.</strong>
                    {' '}
                    {warningSummary}.
                    Reconstruction continued with affected sells skipped.
                    The ledger was not modified.
                    <ul className="mb-0 mt-2">
                        {warnings.map((w, idx) => (
                            <li key={`${w.transaction_id || 'w'}-${idx}`}>
                                {w.symbol || `Stock #${w.stock_id}`}
                                {w.transaction_date ? ` on ${w.transaction_date}` : ''}
                                {`: tried to sell ${w.quantity} while holding ${w.held_quantity}`}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {!valuationComplete && holdings.length > 0 && (
                <div className="alert alert-secondary py-2 small" role="status">
                    Valuation is incomplete:
                    {' '}
                    {missingPriceCount}
                    {' '}
                    holding(s) have no market price on or before
                    {' '}
                    {asOf}
                    . Market value and unrealized totals are marked unavailable where prices are missing.
                </div>
            )}

            {error && (
                <div className="alert alert-danger py-2 small">
                    {typeof error === 'string' ? error : 'Could not load historical holdings.'}
                </div>
            )}

            <div className="card mb-3">
                <div className="card-header py-2 fw-semibold small d-flex flex-wrap gap-3 justify-content-between">
                    <span>
                        As of
                        {' '}
                        {formatTransactionDateDisplay(asOf)}
                    </span>
                    <span className="text-muted fw-normal">
                        Invested:
                        {' '}
                        {formatTableMoney2(totals.invested_value ?? 0)}
                        {' · '}
                        Market:
                        {' '}
                        {valuationComplete
                            ? formatTableMoney2(totals.market_value)
                            : 'Incomplete'}
                        {' · '}
                        Unrealized:
                        {' '}
                        {valuationComplete
                            ? (
                                <span className={percentChangeColorClass(totals.unrealized_profit)}>
                                    {formatSignedTableMoney2(totals.unrealized_profit)}
                                    {totals.unrealized_gain_percent != null
                                        ? ` (${formatSignedPercent2(totals.unrealized_gain_percent)})`
                                        : ''}
                                </span>
                            )
                            : 'Incomplete'}
                    </span>
                </div>
                <div className="table-responsive">
                    <table className="table table-sm table-hover mb-0 align-middle">
                        <thead className="table-light">
                            <tr>
                                <th>Stock</th>
                                <th>Name</th>
                                <th className="text-end">Qty</th>
                                <th className="text-end">Avg Buy</th>
                                <th className="text-end">Invested</th>
                                <th className="text-end">As-of price</th>
                                <th className="text-end">Market value</th>
                                <th className="text-end">Unrealized P/L</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr>
                                    <td colSpan={8} className="text-muted small p-3">
                                        Loading historical holdings…
                                    </td>
                                </tr>
                            )}
                            {empty && (
                                <tr>
                                    <td colSpan={8} className="text-muted small p-3">
                                        No open holdings on this date. Choose a later date, or add
                                        transactions if the portfolio is empty.
                                    </td>
                                </tr>
                            )}
                            {!loading && holdings.map((row) => (
                                <tr key={row.stock_id}>
                                    <td className="fw-semibold">{row.symbol || '—'}</td>
                                    <td className="small text-muted">{row.name || '—'}</td>
                                    <td className="text-end">{row.quantity}</td>
                                    <td className="text-end">{formatTableMoney2(row.avg_buy_price)}</td>
                                    <td className="text-end">{formatTableMoney2(row.invested_amount)}</td>
                                    <td className="text-end">
                                        {row.price_available
                                            ? formatTableMoney2(row.as_of_price)
                                            : <span className="text-muted">Unavailable</span>}
                                    </td>
                                    <td className="text-end">{moneyOrUnavailable(row.market_value)}</td>
                                    <td className="text-end">
                                        {signedMoneyOrUnavailable(row.unrealized_profit)}
                                        {row.unrealized_gain_percent != null && (
                                            <div className={`small ${percentChangeColorClass(row.unrealized_gain_percent)}`}>
                                                ({formatSignedPercent2(row.unrealized_gain_percent)})
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
