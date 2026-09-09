import React, { useCallback, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { usePortfolio } from '../context/PortfolioContext';
import { ROUTES } from '../navigation/routes';
import { formatTableMoney2 } from '../utils/tableFormat';
import { getLocalTodayDateString } from '../utils/transactionDate';
import { downloadPortfolioCsv } from '../utils/portfolioCsvExport';

function monthAgo(dateString) {
    const date = new Date(`${dateString}T12:00:00`);
    date.setMonth(date.getMonth() - 1);
    return date.toISOString().slice(0, 10);
}

function money(value) {
    return value == null ? 'Incomplete' : formatTableMoney2(value);
}

export default function PortfolioComparePage() {
    const { activePortfolio } = usePortfolio();
    const [params, setParams] = useSearchParams();
    const today = getLocalTodayDateString();
    const [dateA, setDateA] = useState(params.get('date_a') || monthAgo(today));
    const [dateB, setDateB] = useState(params.get('date_b') || today);
    const [exporting, setExporting] = useState(false);
    const valid = Boolean(dateA && dateB && dateA < dateB && dateB <= today);

    const request = useCallback(async () => {
        if (!valid) return null;
        const response = await api.get('/portfolio/compare', {
            params: { date_a: dateA, date_b: dateB },
            skipErrorToast: true,
        });
        return response.data?.data || null;
    }, [dateA, dateB, valid]);

    const { data, loading, error, reload } = useApiGet({
        request,
        deps: [activePortfolio?.id, dateA, dateB],
        enabled: Boolean(activePortfolio?.id) && valid,
        errorFallback: 'Could not compare portfolio dates',
        initialData: null,
    });

    const apply = () => {
        if (!valid) return;
        setParams({ date_a: dateA, date_b: dateB });
        reload();
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Compare portfolio dates</h1>
                    <p className="small text-muted mb-0">
                        Physical holdings and cash at two dates. Value change is not investment return or causal attribution.
                    </p>
                </div>
                <Link className="btn btn-sm btn-outline-secondary" to={ROUTES.PORTFOLIO_HISTORICAL_HOLDINGS}>Historical holdings</Link>
                <button type="button" className="btn btn-sm btn-outline-primary" disabled={!valid || exporting} onClick={async () => {
                    setExporting(true);
                    try { await downloadPortfolioCsv('portfolio_compare', { date_a: dateA, date_b: dateB }); } finally { setExporting(false); }
                }}>{exporting ? 'Exporting…' : 'Export comparison'}</button>
            </div>

            <div className="card mb-3"><div className="card-body row g-2 align-items-end">
                <div className="col-sm-4 col-lg-3"><label className="form-label small" htmlFor="compare-a">Date A</label><input id="compare-a" className="form-control form-control-sm" type="date" max={today} value={dateA} onChange={(event) => setDateA(event.target.value)} /></div>
                <div className="col-sm-4 col-lg-3"><label className="form-label small" htmlFor="compare-b">Date B</label><input id="compare-b" className="form-control form-control-sm" type="date" min={dateA} max={today} value={dateB} onChange={(event) => setDateB(event.target.value)} /></div>
                <div className="col-auto"><button type="button" className="btn btn-sm btn-primary" disabled={!valid || loading} onClick={apply}>{loading ? 'Comparing…' : 'Compare'}</button></div>
                {!valid ? <div className="col-12 small text-danger">Date A must be before Date B, and neither date may be in the future.</div> : null}
            </div></div>

            {error ? <div className="alert alert-danger py-2 small">{String(error)}</div> : null}
            {data ? <>
                <div className="row g-3 mb-3">
                    {[data.endpoint_a, data.endpoint_b].map((endpoint) => <div className="col-md-6" key={endpoint.date}><div className="card h-100"><div className="card-header">{endpoint.date}</div><div className="card-body small">Holdings {money(endpoint.holdings_market_value)} · Cash {money(endpoint.cash)} · Total {money(endpoint.total_value)}</div></div></div>)}
                </div>
                <div className="alert alert-secondary py-2 small">
                    {data.deltas.label}: {money(data.deltas.total_value)}. External flows: deposits {money(data.external_flows.deposits)}, withdrawals {money(data.external_flows.withdrawals)}, net {money(data.external_flows.net)}. Adjustments: {money(data.external_flows.adjustments)}.
                </div>
                <div className="card mb-3"><div className="card-header">Holdings difference</div><div className="table-responsive"><table className="table table-sm mb-0"><thead><tr><th>Stock</th><th>Status</th><th className="text-end">Qty A</th><th className="text-end">Qty B</th><th className="text-end">Change</th><th className="text-end">Value A</th><th className="text-end">Value B</th></tr></thead><tbody>
                    {data.holdings.map((row) => <tr key={row.stock_id}><td>{row.symbol || row.name || `#${row.stock_id}`}</td><td className="text-capitalize">{row.classification}</td><td className="text-end">{row.quantity_a}</td><td className="text-end">{row.quantity_b}</td><td className="text-end">{row.quantity_delta}</td><td className="text-end">{money(row.market_value_a)}</td><td className="text-end">{money(row.market_value_b)}</td></tr>)}
                    {data.holdings.length === 0 ? <tr><td colSpan={7} className="text-muted p-3">No holdings at either endpoint.</td></tr> : null}
                </tbody></table></div></div>
                <div className="card"><div className="card-header">Transaction evidence in (A, B]</div><div className="card-body small">
                    {data.transactions.length ? data.transactions.map((transaction) => <div key={transaction.id}>{transaction.date} · {String(transaction.type).toUpperCase()} {transaction.quantity} {transaction.symbol} at {money(transaction.price)}</div>) : <span className="text-muted">No transactions in this interval.</span>}
                </div></div>
            </> : null}
        </div>
    );
}
