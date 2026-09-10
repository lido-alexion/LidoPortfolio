import React, { useCallback, useMemo, useState } from 'react';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { usePortfolio } from '../context/PortfolioContext';
import { formatTableMoney2, formatTablePercent2 } from '../utils/tableFormat';
import { getLocalTodayDateString } from '../utils/transactionDate';
import { downloadTaxCsv } from '../utils/taxCsvExport';
import { showToast } from '../toast';

function currentFinancialYear(today) {
    const year = Number(today.slice(0, 4));
    const month = Number(today.slice(5, 7));
    const start = month >= 4 ? year : year - 1;
    return `${start}-${String((start + 1) % 100).padStart(2, '0')}`;
}

function Metric({ label, value, kind = 'percent' }) {
    const display = value == null ? 'Incomplete' : kind === 'money' ? formatTableMoney2(value) : formatTablePercent2(value);
    return <div className="col-sm-6 col-xl-3"><div className="card h-100"><div className="card-body"><div className="small text-muted">{label}</div><div className="h5 mb-0">{display}</div></div></div></div>;
}

export default function PerformanceTaxPage() {
    const { activePortfolio, portfolios } = usePortfolio();
    const today = getLocalTodayDateString();
    const [from, setFrom] = useState(`${today.slice(0, 4)}-01-01`);
    const [to, setTo] = useState(today);
    const [financialYear, setFinancialYear] = useState(currentFinancialYear(today));
    const [whatIf, setWhatIf] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [savingEvidence, setSavingEvidence] = useState(false);

    const taxParams = useMemo(() => ({
        financial_year: financialYear,
        ...(whatIf ? { portfolio_ids: selectedIds } : {}),
    }), [financialYear, whatIf, selectedIds]);
    const request = useCallback(async () => {
        const [performance, accountPerformance, attribution, tax] = await Promise.all([
            api.get('/analysis/performance', { params: { from, to }, skipErrorToast: true }),
            api.get('/analysis/account-performance', { params: { from, to, ...(whatIf ? { portfolio_ids: selectedIds } : {}) }, skipErrorToast: true }),
            api.get('/analysis/attribution', { params: { from, to }, skipErrorToast: true }),
            api.get('/tax/report', { params: taxParams, skipErrorToast: true }),
        ]);
        return { performance: performance.data.data, accountPerformance: accountPerformance.data.data, attribution: attribution.data.data, tax: tax.data.data };
    }, [from, to, taxParams, whatIf, selectedIds]);
    const valid = from < to && to <= today && /^\d{4}-\d{2}$/.test(financialYear);
    const { data, loading, error, reload } = useApiGet({
        request, deps: [activePortfolio?.id, from, to, financialYear, whatIf, selectedIds.join('|')],
        enabled: Boolean(activePortfolio?.id) && valid,
        errorFallback: 'Could not load performance and tax analysis', initialData: null,
    });

    const preserve = async (calculationType) => {
        setSavingEvidence(true);
        try {
            const payload = calculationType === 'portfolio_performance'
                ? { calculation_type: calculationType, from, to }
                : { calculation_type: 'account_tax', financial_year: financialYear, ...(whatIf ? { portfolio_ids: selectedIds } : {}) };
            await api.post('/analysis/evidence', payload);
            showToast('Immutable calculation evidence preserved', 'success');
        } finally { setSavingEvidence(false); }
    };

    return <div className="container-fluid py-3">
        <div className="mb-3"><h1 className="h4 mb-1">Performance & Tax</h1><p className="small text-muted mb-0">Cash-flow-adjusted investment analytics and India-focused estimates—not certified tax advice.</p></div>
        <div className="card mb-3"><div className="card-body row g-2 align-items-end">
            <div className="col-sm-3"><label className="form-label small" htmlFor="perf-from">Performance from</label><input id="perf-from" className="form-control form-control-sm" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></div>
            <div className="col-sm-3"><label className="form-label small" htmlFor="perf-to">Performance to</label><input id="perf-to" className="form-control form-control-sm" type="date" value={to} min={from} max={today} onChange={(e) => setTo(e.target.value)} /></div>
            <div className="col-sm-3"><label className="form-label small" htmlFor="tax-fy">Tax financial year</label><input id="tax-fy" className="form-control form-control-sm" value={financialYear} onChange={(e) => setFinancialYear(e.target.value)} placeholder="2025-26" /></div>
            <div className="col-auto"><button className="btn btn-sm btn-primary" type="button" disabled={!valid || loading} onClick={reload}>{loading ? 'Calculating…' : 'Calculate'}</button></div>
            <div className="col-12 form-check ms-2"><input id="tax-what-if" className="form-check-input" type="checkbox" checked={whatIf} onChange={(e) => setWhatIf(e.target.checked)} /><label className="form-check-label small" htmlFor="tax-what-if">What-if portfolio selection (does not change configured inclusion)</label></div>
            {whatIf ? <div className="col-12 d-flex flex-wrap gap-3">{portfolios.map((portfolio) => <label className="small" key={portfolio.id}><input type="checkbox" className="form-check-input me-1" checked={selectedIds.includes(portfolio.id)} onChange={(e) => setSelectedIds((ids) => e.target.checked ? [...ids, portfolio.id] : ids.filter((id) => id !== portfolio.id))} />{portfolio.name}</label>)}</div> : null}
        </div></div>
        {error ? <div className="alert alert-danger py-2 small">{String(error)}</div> : null}
        {data ? <>
            <div className="d-flex justify-content-between align-items-center mb-2"><h2 className="h5 mb-0">Portfolio performance</h2><button type="button" className="btn btn-sm btn-outline-secondary" disabled={savingEvidence} onClick={() => preserve('portfolio_performance')}>Preserve evidence</button></div>
            <div className="row g-3 mb-3"><Metric label="XIRR" value={data.performance.xirr_percent} /><Metric label="TWR" value={data.performance.twr_percent} /><Metric label="Excess return" value={data.performance.excess_return_percent} /><Metric label="Maximum drawdown" value={data.performance.maximum_drawdown_percent} /></div>
            <div className="alert alert-secondary py-2 small">Benchmark: {data.performance.benchmark?.name || 'Unavailable'} · Volatility {data.performance.volatility_percent == null ? 'requires 30 observations' : formatTablePercent2(data.performance.volatility_percent)} · Sharpe {data.performance.sharpe_ratio ?? 'Incomplete'} · Completeness: {data.performance.completeness}</div>
            <div className="card mb-3"><div className="card-header">Account performance · {data.accountPerformance.calculation_mode}</div><div className="card-body small">Independently aggregated across {data.accountPerformance.portfolio_ids.length} included portfolio(s), never averaged: XIRR {data.accountPerformance.xirr_percent == null ? 'Incomplete' : formatTablePercent2(data.accountPerformance.xirr_percent)} · TWR {data.accountPerformance.twr_percent == null ? 'Incomplete' : formatTablePercent2(data.accountPerformance.twr_percent)}.</div></div>
            <div className="card mb-4"><div className="card-header">Reconciliation attribution</div><div className="card-body small">Method: {data.attribution.method}. Explained realized: {formatTableMoney2(data.attribution.explained_net_realized)}; residual: {data.attribution.reconciliation_residual == null ? 'Incomplete' : formatTableMoney2(data.attribution.reconciliation_residual)}. {data.attribution.limitations.join(' ')}</div></div>
            <div className="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-2"><h2 className="h5 mb-0">Account Tax · FY {financialYear}</h2><div className="d-flex flex-wrap gap-1"><button type="button" className="btn btn-sm btn-outline-secondary" disabled={savingEvidence} onClick={() => preserve('account_tax')}>Preserve evidence</button>{['summary', 'realized_gains', 'open_lots', 'dividends', 'losses', 'assumptions'].map((dataset) => <button key={dataset} type="button" className="btn btn-sm btn-outline-primary" onClick={() => downloadTaxCsv(dataset, taxParams)}>Export {dataset.replaceAll('_', ' ')}</button>)}</div></div>
            <div className="row g-3 mb-3"><Metric label="STCG" value={data.tax.summary.short_term_realized_gain} kind="money" /><Metric label="LTCG" value={data.tax.summary.long_term_realized_gain} kind="money" /><Metric label="Dividends" value={data.tax.summary.dividend_income} kind="money" /><Metric label="Estimated tax" value={data.tax.summary.estimated_tax} kind="money" /></div>
            <div className={`alert py-2 small ${data.tax.completeness === 'complete' ? 'alert-success' : 'alert-warning'}`}>Mode: {data.tax.calculation_mode}. Completeness: {data.tax.completeness}. {data.tax.limitations.join(' ') || 'All required evidence is available.'}</div>
        </> : null}
    </div>;
}
