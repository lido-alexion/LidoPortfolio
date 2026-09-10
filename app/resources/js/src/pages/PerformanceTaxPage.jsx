import React, { useCallback, useEffect, useMemo, useState } from 'react';
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
    const [savingPreferences, setSavingPreferences] = useState(false);
    const [preferenceForm, setPreferenceForm] = useState(null);

    const taxParams = useMemo(() => ({
        financial_year: financialYear,
        ...(whatIf ? { portfolio_ids: selectedIds } : {}),
    }), [financialYear, whatIf, selectedIds]);
    const request = useCallback(async () => {
        const [performance, accountPerformance, attribution, tax, preferences, benchmarks] = await Promise.all([
            api.get('/analysis/performance', { params: { from, to }, skipErrorToast: true }),
            api.get('/analysis/account-performance', { params: { from, to, ...(whatIf ? { portfolio_ids: selectedIds } : {}) }, skipErrorToast: true }),
            api.get('/analysis/attribution', { params: { from, to }, skipErrorToast: true }),
            api.get('/tax/report', { params: taxParams, skipErrorToast: true }),
            api.get('/analysis/preferences', { skipErrorToast: true }),
            api.get('/analysis/benchmarks', { skipErrorToast: true }),
        ]);
        return {
            performance: performance.data.data, accountPerformance: accountPerformance.data.data,
            attribution: attribution.data.data, tax: tax.data.data,
            preferences: preferences.data.data, benchmarks: benchmarks.data.data,
        };
    }, [from, to, taxParams, whatIf, selectedIds]);
    const valid = from < to && to <= today && /^\d{4}-\d{2}$/.test(financialYear);
    const { data, loading, error, reload } = useApiGet({
        request, deps: [activePortfolio?.id, from, to, financialYear, whatIf, selectedIds.join('|')],
        enabled: Boolean(activePortfolio?.id) && valid,
        errorFallback: 'Could not load performance and tax analysis', initialData: null,
    });
    useEffect(() => {
        if (data?.preferences) setPreferenceForm(data.preferences);
    }, [data?.preferences]);

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

    const savePreferences = async () => {
        setSavingPreferences(true);
        try {
            await api.put('/analysis/preferences', {
                primary_benchmark_id: Number(preferenceForm.primary_benchmark_id),
                include_in_account_performance: Boolean(preferenceForm.include_in_account_performance),
                include_in_account_tax: Boolean(preferenceForm.include_in_account_tax),
                risk_free_rate: preferenceForm.risk_free_rate === '' ? null : Number(preferenceForm.risk_free_rate),
                annualization_days: preferenceForm.annualization_days === '' ? null : Number(preferenceForm.annualization_days),
            });
            showToast('Analysis preferences saved', 'success');
            reload();
        } finally { setSavingPreferences(false); }
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
            {preferenceForm ? <div className="card mb-3"><div className="card-header">Analysis settings · {activePortfolio.name}</div><div className="card-body row g-2 align-items-end">
                <div className="col-md-4"><label className="form-label small" htmlFor="primary-benchmark">Primary benchmark</label><select id="primary-benchmark" className="form-select form-select-sm" value={preferenceForm.primary_benchmark_id ?? ''} onChange={(e) => setPreferenceForm((value) => ({ ...value, primary_benchmark_id: e.target.value }))}>{data.benchmarks.map((benchmark) => <option key={benchmark.id} value={benchmark.id}>{benchmark.name} · {benchmark.return_type}</option>)}</select></div>
                <div className="col-md-2"><label className="form-label small" htmlFor="risk-free">Annual risk-free rate</label><input id="risk-free" className="form-control form-control-sm" type="number" step="0.0001" min="-0.25" max="1" value={preferenceForm.risk_free_rate ?? ''} onChange={(e) => setPreferenceForm((value) => ({ ...value, risk_free_rate: e.target.value }))} /></div>
                <div className="col-md-2"><label className="form-label small" htmlFor="annualization-days">Annualization days</label><input id="annualization-days" className="form-control form-control-sm" type="number" min="1" max="366" value={preferenceForm.annualization_days ?? 252} onChange={(e) => setPreferenceForm((value) => ({ ...value, annualization_days: e.target.value }))} /></div>
                <div className="col-md-3"><label className="d-block small"><input className="form-check-input me-1" type="checkbox" checked={preferenceForm.include_in_account_performance} onChange={(e) => setPreferenceForm((value) => ({ ...value, include_in_account_performance: e.target.checked }))} />Include in Account performance</label><label className="d-block small mt-2"><input className="form-check-input me-1" type="checkbox" checked={preferenceForm.include_in_account_tax} onChange={(e) => setPreferenceForm((value) => ({ ...value, include_in_account_tax: e.target.checked }))} />Include in Account Tax</label></div>
                <div className="col-auto"><button type="button" className="btn btn-sm btn-outline-primary" disabled={savingPreferences} onClick={savePreferences}>{savingPreferences ? 'Saving…' : 'Save settings'}</button></div>
                <div className="col-12 small text-muted">Tax and performance inclusion are independent. Benchmark levels use the latest authoritative value on or before each endpoint without interpolation.</div>
            </div></div> : null}
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
