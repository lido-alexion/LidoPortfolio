import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import api, { getApiErrorMessage } from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import { useAuth } from '../context/AuthContext';
import { formatTableMoney2, formatTablePercent2 } from '../utils/tableFormat';
import { getLocalTodayDateString } from '../utils/transactionDate';
import { downloadTaxCsv } from '../utils/taxCsvExport';
import { showToast } from '../toast';
import TaxEvidenceEntryPanel from '../components/TaxEvidenceEntryPanel';

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
    const { user } = useAuth();
    const today = getLocalTodayDateString();
    const [from, setFrom] = useState(`${today.slice(0, 4)}-01-01`);
    const [to, setTo] = useState(today);
    const [financialYear, setFinancialYear] = useState(currentFinancialYear(today));
    const [whatIf, setWhatIf] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [savingEvidence, setSavingEvidence] = useState(false);
    const [savingPreferences, setSavingPreferences] = useState(false);
    const [operationError, setOperationError] = useState('');
    const [preferenceForm, setPreferenceForm] = useState(null);
    const [accountPreferenceForm, setAccountPreferenceForm] = useState(null);

    const sortedSelectedIds = useMemo(() => [...selectedIds].sort((left, right) => String(left).localeCompare(String(right))), [selectedIds]);
    const taxParams = useMemo(() => ({
        financial_year: financialYear,
        ...(whatIf ? { portfolio_ids: selectedIds } : {}),
    }), [financialYear, whatIf, selectedIds]);
    const valid = from < to && to <= today && /^\d{4}-\d{2}$/.test(financialYear);
    const portfolioId = activePortfolio?.id == null ? null : String(activePortfolio.id);
    const enabled = Boolean(activePortfolio?.id) && valid;
    const accountId = user?.id == null ? 'account' : String(user.id);
    const resourceKeys = useMemo(() => ({
        portfolioPerformance: JSON.stringify([portfolioId, from, to]),
        attribution: JSON.stringify([portfolioId, from, to]),
        portfolioPreferences: JSON.stringify([portfolioId]),
        accountPerformance: JSON.stringify([accountId, from, to, whatIf ? 'what-if' : 'configured', ...(whatIf ? sortedSelectedIds : [])]),
        tax: JSON.stringify([accountId, financialYear, whatIf ? 'what-if' : 'configured', ...(whatIf ? sortedSelectedIds : [])]),
        accountPreferences: JSON.stringify([accountId]),
        benchmarks: 'global',
    }), [portfolioId, from, to, accountId, financialYear, whatIf, sortedSelectedIds]);
    const query = useMemo(() => ({
        portfolioId, accountId, from, to, financialYear, whatIf, selectedIds, taxParams, valid, resourceKeys,
        key: JSON.stringify([portfolioId, accountId, from, to, financialYear, whatIf, selectedIds, valid]),
    }), [portfolioId, accountId, from, to, financialYear, whatIf, selectedIds, taxParams, valid, resourceKeys, sortedSelectedIds]);
    const request = useCallback(async (current) => {
        const [performance, accountPerformance, attribution, tax, preferences, accountPreferences, benchmarks] = await Promise.all([
            api.get('/analysis/performance', { params: { from: current.from, to: current.to }, skipErrorToast: true }),
            api.get('/analysis/account-performance', { params: { from: current.from, to: current.to, ...(current.whatIf ? { portfolio_ids: current.selectedIds } : {}) }, skipErrorToast: true }),
            api.get('/analysis/attribution', { params: { from: current.from, to: current.to }, skipErrorToast: true }),
            api.get('/tax/report', { params: current.taxParams, skipErrorToast: true }),
            api.get('/analysis/preferences', { skipErrorToast: true }),
            api.get('/analysis/preferences', { params: { scope: 'account' }, skipErrorToast: true }),
            api.get('/analysis/benchmarks', { skipErrorToast: true }),
        ]);
        return {
            performance: performance.data.data, accountPerformance: accountPerformance.data.data,
            attribution: attribution.data.data, tax: tax.data.data,
            preferences: preferences.data.data, benchmarks: benchmarks.data.data,
            accountPreferences: accountPreferences.data.data,
        };
    }, []);
    const [resourceData, setResourceData] = useState({});
    const [loadState, setLoadState] = useState({ key: null, loading: false, error: null });
    const [hasConfirmedBatch, setHasConfirmedBatch] = useState(false);
    const generationRef = useRef(0);
    const mountedRef = useRef(false);
    const committedQueryRef = useRef(null);
    const queryEpochRef = useRef(0);
    const committedResourceKeysRef = useRef(null);
    const resourceEpochsRef = useRef({});
    const formKeyRef = useRef({ portfolio: null, account: null });
    const seedPortfolioFormRef = useRef(true);

    useLayoutEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            generationRef.current += 1;
        };
    }, []);
    useLayoutEffect(() => {
        const previous = committedQueryRef.current;
        if (!previous || previous.key !== query.key) queryEpochRef.current += 1;
        const previousResourceKeys = committedResourceKeysRef.current;
        const nextResourceEpochs = { ...resourceEpochsRef.current };
        Object.entries(query.resourceKeys).forEach(([name, key]) => {
            if (nextResourceEpochs[name] == null) nextResourceEpochs[name] = 0;
            else if (previousResourceKeys && previousResourceKeys[name] !== key) nextResourceEpochs[name] += 1;
        });
        resourceEpochsRef.current = nextResourceEpochs;
        committedResourceKeysRef.current = query.resourceKeys;
        const committed = { ...query, epoch: queryEpochRef.current };
        committedQueryRef.current = committed;
        if (previous && previous.portfolioId !== committed.portfolioId) {
            formKeyRef.current.portfolio = committed.resourceKeys.portfolioPreferences;
            seedPortfolioFormRef.current = true;
            setPreferenceForm(null);
        }
    }, [query]);

    const loadLatest = useCallback(async (rehydrate = false) => {
        const current = committedQueryRef.current;
        if (!current || !current.valid || !current.portfolioId) {
            if (mountedRef.current) setLoadState({ key: current?.key ?? null, loading: false, error: null });
            return null;
        }
        const generation = ++generationRef.current;
        const isCurrent = () => mountedRef.current
            && generationRef.current === generation
            && committedQueryRef.current?.epoch === current.epoch
            && committedQueryRef.current?.key === current.key;
        setLoadState({ key: current.key, loading: true, error: null });
        try {
            const result = await request(current);
            if (!isCurrent()) return null;
            const records = {
                portfolioPerformance: { key: current.resourceKeys.portfolioPerformance, epoch: resourceEpochsRef.current.portfolioPerformance, value: result.performance },
                attribution: { key: current.resourceKeys.attribution, epoch: resourceEpochsRef.current.attribution, value: result.attribution },
                portfolioPreferences: { key: current.resourceKeys.portfolioPreferences, epoch: resourceEpochsRef.current.portfolioPreferences, value: result.preferences },
                accountPerformance: { key: current.resourceKeys.accountPerformance, epoch: resourceEpochsRef.current.accountPerformance, value: result.accountPerformance },
                tax: { key: current.resourceKeys.tax, epoch: resourceEpochsRef.current.tax, value: result.tax },
                accountPreferences: { key: current.resourceKeys.accountPreferences, epoch: resourceEpochsRef.current.accountPreferences, value: result.accountPreferences },
                benchmarks: { key: current.resourceKeys.benchmarks, epoch: resourceEpochsRef.current.benchmarks, value: result.benchmarks },
            };
            setResourceData((previous) => ({ ...previous, ...records }));
            setHasConfirmedBatch(true);
            setLoadState({ key: current.key, loading: false, error: null });
            if (rehydrate || seedPortfolioFormRef.current || formKeyRef.current.portfolio !== current.resourceKeys.portfolioPreferences) {
                setPreferenceForm(result.preferences ?? null);
                formKeyRef.current.portfolio = current.resourceKeys.portfolioPreferences;
                seedPortfolioFormRef.current = false;
            }
            if (rehydrate || formKeyRef.current.account !== current.resourceKeys.accountPreferences) {
                setAccountPreferenceForm(result.accountPreferences ?? null);
                formKeyRef.current.account = current.resourceKeys.accountPreferences;
            }
            return result;
        } catch (error) {
            if (isCurrent()) {
                setLoadState({ key: current.key, loading: false, error });
                showToast(getApiErrorMessage(error, 'Could not load performance and tax analysis'), 'danger');
            }
            return null;
        }
    }, [request]);
    const reload = useCallback(() => loadLatest(true), [loadLatest]);
    useEffect(() => {
        if (enabled) loadLatest(false);
        else {
            generationRef.current += 1;
            setResourceData({});
            setHasConfirmedBatch(false);
            formKeyRef.current = { portfolio: null, account: null };
            seedPortfolioFormRef.current = true;
            setLoadState({ key: query.key, loading: false, error: null });
        }
    }, [query.key, enabled, loadLatest]);

    const hasMatchingRecord = (name, key) => enabled
        && resourceData[name]?.key === key
        && resourceData[name]?.epoch === resourceEpochsRef.current[name];
    const matches = (name, key) => hasMatchingRecord(name, key) ? resourceData[name].value : null;
    const portfolioPerformance = matches('portfolioPerformance', resourceKeys.portfolioPerformance);
    const attribution = matches('attribution', resourceKeys.attribution);
    const portfolioPreferences = matches('portfolioPreferences', resourceKeys.portfolioPreferences);
    const accountPerformance = matches('accountPerformance', resourceKeys.accountPerformance);
    const tax = matches('tax', resourceKeys.tax);
    const accountPreferences = matches('accountPreferences', resourceKeys.accountPreferences);
    const benchmarks = matches('benchmarks', resourceKeys.benchmarks);
    const hasAnyData = enabled && Boolean(portfolioPerformance || attribution || portfolioPreferences || accountPerformance || tax || accountPreferences || benchmarks);
    const hasMatchingConfirmedResource = Object.entries(resourceKeys).some(([name, key]) => hasMatchingRecord(name, key));
    const hasPortfolioPerformance = Boolean(portfolioPerformance);
    const hasPortfolioSettings = Boolean(portfolioPreferences && benchmarks && formKeyRef.current.portfolio === resourceKeys.portfolioPreferences && preferenceForm);
    const hasAccountSettings = Boolean(accountPreferences && benchmarks && formKeyRef.current.account === resourceKeys.accountPreferences && accountPreferenceForm);
    const loading = enabled && (loadState.key !== query.key || loadState.loading);
    const error = loadState.key === query.key ? loadState.error : null;
    useEffect(() => {
        if (portfolioPreferences && formKeyRef.current.portfolio !== resourceKeys.portfolioPreferences) {
            setPreferenceForm(portfolioPreferences);
            formKeyRef.current.portfolio = resourceKeys.portfolioPreferences;
        }
        if (accountPreferences && formKeyRef.current.account !== resourceKeys.accountPreferences) {
            setAccountPreferenceForm(accountPreferences);
            formKeyRef.current.account = resourceKeys.accountPreferences;
        }
    }, [portfolioPreferences, accountPreferences, resourceKeys]);

    const preserve = async (calculationType) => {
        setSavingEvidence(true);
        setOperationError('');
        try {
            const payload = calculationType === 'portfolio_performance'
                ? { calculation_type: calculationType, from, to }
                : calculationType === 'account_performance'
                    ? { calculation_type: calculationType, from, to, ...(whatIf ? { portfolio_ids: selectedIds } : {}) }
                : { calculation_type: 'account_tax', financial_year: financialYear, ...(whatIf ? { portfolio_ids: selectedIds } : {}) };
            await api.post('/analysis/evidence', payload);
            showToast('Immutable calculation evidence preserved', 'success');
        } catch { setOperationError('Calculation evidence could not be preserved. Please retry.'); }
        finally { setSavingEvidence(false); }
    };

    const savePreferences = async () => {
        setSavingPreferences(true);
        setOperationError('');
        try {
            await api.put('/analysis/preferences', {
                primary_benchmark_id: Number(preferenceForm.primary_benchmark_id),
                comparison_benchmark_ids: preferenceForm.comparison_benchmark_ids ?? [],
                include_in_account_performance: Boolean(preferenceForm.include_in_account_performance),
                include_in_account_tax: Boolean(preferenceForm.include_in_account_tax),
                risk_free_rate: preferenceForm.risk_free_rate === '' ? null : Number(preferenceForm.risk_free_rate),
                annualization_days: preferenceForm.annualization_days === '' ? null : Number(preferenceForm.annualization_days),
            });
            showToast('Analysis preferences saved', 'success');
            reload();
        } catch { setOperationError('Analysis preferences could not be saved. Please retry.'); }
        finally { setSavingPreferences(false); }
    };
    const saveAccountPreferences = async () => {
        setSavingPreferences(true);
        setOperationError('');
        try {
            await api.put('/analysis/preferences', {
                scope: 'account',
                primary_benchmark_id: Number(accountPreferenceForm.primary_benchmark_id),
                comparison_benchmark_ids: accountPreferenceForm.comparison_benchmark_ids ?? [],
                risk_free_rate: accountPreferenceForm.risk_free_rate === '' ? null : Number(accountPreferenceForm.risk_free_rate),
                annualization_days: accountPreferenceForm.annualization_days === '' ? null : Number(accountPreferenceForm.annualization_days),
            });
            showToast('Account analysis overrides saved', 'success');
            reload();
        } catch { setOperationError('Account analysis overrides could not be saved. Please retry.'); }
        finally { setSavingPreferences(false); }
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
        {loading ? <div className="alert alert-info py-2 small" role="status">{hasAnyData ? 'Refreshing analysis. Previously confirmed values are shown only when their profile and inputs still match; unmatched sections are unavailable.' : 'Loading analysis for the current profile and inputs. Results are not confirmed yet.'}</div> : null}
        {error && hasMatchingConfirmedResource ? <div className="alert alert-warning py-2 small" role="status">Some current analysis reads are unavailable. Any retained values are last-confirmed and shown only for matching profile and input keys.</div> : null}
        {error ? <div className="alert alert-danger py-2 small" role="alert">{getApiErrorMessage(error, 'Could not load portfolio analysis. Please retry.')}</div> : null}
        {operationError && <div className="alert alert-danger py-2 small" role="alert">{operationError}</div>}
        {hasAnyData || (hasConfirmedBatch && enabled) ? <div>
            {hasPortfolioSettings ? <div className="card mb-3"><div className="card-header">Analysis settings · {activePortfolio.name}</div><div className="card-body row g-2 align-items-end">
                <div className="col-md-4"><label className="form-label small" htmlFor="primary-benchmark">Primary benchmark</label><select id="primary-benchmark" className="form-select form-select-sm" value={preferenceForm.primary_benchmark_id ?? ''} onChange={(e) => setPreferenceForm((value) => ({ ...value, primary_benchmark_id: e.target.value }))}>{benchmarks.map((benchmark) => <option key={benchmark.id} value={benchmark.id}>{benchmark.name} · {benchmark.return_type}</option>)}</select></div>
                <div className="col-md-4"><div className="small mb-1">Comparison benchmarks</div>{benchmarks.filter((benchmark) => benchmark.id !== Number(preferenceForm.primary_benchmark_id)).map((benchmark) => <label className="d-block small" key={benchmark.id}><input className="form-check-input me-1" type="checkbox" checked={(preferenceForm.comparison_benchmark_ids ?? []).includes(benchmark.id)} onChange={(event) => setPreferenceForm((value) => ({ ...value, comparison_benchmark_ids: event.target.checked ? [...(value.comparison_benchmark_ids ?? []), benchmark.id] : (value.comparison_benchmark_ids ?? []).filter((id) => id !== benchmark.id) }))} />{benchmark.name}</label>)}</div>
                <div className="col-md-2"><label className="form-label small" htmlFor="risk-free">Annual risk-free rate</label><input id="risk-free" className="form-control form-control-sm" type="number" step="0.0001" min="-0.25" max="1" value={preferenceForm.risk_free_rate ?? ''} onChange={(e) => setPreferenceForm((value) => ({ ...value, risk_free_rate: e.target.value }))} /></div>
                <div className="col-md-2"><label className="form-label small" htmlFor="annualization-days">Annualization days</label><input id="annualization-days" className="form-control form-control-sm" type="number" min="1" max="366" value={preferenceForm.annualization_days ?? 252} onChange={(e) => setPreferenceForm((value) => ({ ...value, annualization_days: e.target.value }))} /></div>
                <div className="col-md-3"><label className="d-block small"><input className="form-check-input me-1" type="checkbox" checked={preferenceForm.include_in_account_performance} onChange={(e) => setPreferenceForm((value) => ({ ...value, include_in_account_performance: e.target.checked }))} />Include in Account performance</label><label className="d-block small mt-2"><input className="form-check-input me-1" type="checkbox" checked={preferenceForm.include_in_account_tax} onChange={(e) => setPreferenceForm((value) => ({ ...value, include_in_account_tax: e.target.checked }))} />Include in Account Tax</label></div>
                <div className="col-auto"><button type="button" className="btn btn-sm btn-outline-primary" disabled={savingPreferences} onClick={savePreferences}>{savingPreferences ? 'Saving…' : 'Save settings'}</button></div>
                <div className="col-12 small text-muted">Tax and performance inclusion are independent. Benchmark levels use the latest authoritative value on or before each endpoint without interpolation.</div>
            </div></div> : null}
            {hasAccountSettings ? <div className="card mb-3"><div className="card-header">Optional Account analytics overrides</div><div className="card-body row g-2 align-items-end"><div className="col-md-4"><label className="form-label small" htmlFor="account-benchmark">Account benchmark</label><select id="account-benchmark" className="form-select form-select-sm" value={accountPreferenceForm.primary_benchmark_id ?? ''} onChange={(e) => setAccountPreferenceForm((value) => ({ ...value, primary_benchmark_id: e.target.value }))}>{benchmarks.map((benchmark) => <option key={benchmark.id} value={benchmark.id}>{benchmark.name}</option>)}</select></div><div className="col-md-3"><div className="small mb-1">Account comparisons</div>{benchmarks.filter((benchmark) => benchmark.id !== Number(accountPreferenceForm.primary_benchmark_id)).map((benchmark) => <label className="d-block small" key={benchmark.id}><input className="form-check-input me-1" type="checkbox" checked={(accountPreferenceForm.comparison_benchmark_ids ?? []).includes(benchmark.id)} onChange={(event) => setAccountPreferenceForm((value) => ({ ...value, comparison_benchmark_ids: event.target.checked ? [...(value.comparison_benchmark_ids ?? []), benchmark.id] : (value.comparison_benchmark_ids ?? []).filter((id) => id !== benchmark.id) }))} />{benchmark.name}</label>)}</div><div className="col-md-3"><label className="form-label small" htmlFor="account-risk-free">Account risk-free rate</label><input id="account-risk-free" className="form-control form-control-sm" type="number" min="-0.25" max="1" step="0.0001" value={accountPreferenceForm.risk_free_rate ?? ''} onChange={(e) => setAccountPreferenceForm((value) => ({ ...value, risk_free_rate: e.target.value }))} /></div><div className="col-md-2"><label className="form-label small" htmlFor="account-days">Annualization days</label><input id="account-days" className="form-control form-control-sm" type="number" min="1" max="366" value={accountPreferenceForm.annualization_days ?? 252} onChange={(e) => setAccountPreferenceForm((value) => ({ ...value, annualization_days: e.target.value }))} /></div><div className="col-auto"><button type="button" className="btn btn-sm btn-outline-primary" disabled={savingPreferences} onClick={saveAccountPreferences}>Save Account overrides</button></div></div></div> : null}
            {hasPortfolioPerformance ? <>
            <div className="d-flex justify-content-between align-items-center mb-2"><h2 className="h5 mb-0">Portfolio performance</h2><button type="button" className="btn btn-sm btn-outline-secondary" disabled={savingEvidence} onClick={() => preserve('portfolio_performance')}>Preserve evidence</button></div>
            <div className="row g-3 mb-3"><Metric label="XIRR" value={portfolioPerformance.xirr_percent} /><Metric label="TWR" value={portfolioPerformance.twr_percent} /><Metric label="Excess return" value={portfolioPerformance.excess_return_percent} /><Metric label="Maximum drawdown" value={portfolioPerformance.maximum_drawdown_percent} /></div>
            <div className="alert alert-secondary py-2 small">Benchmark: {portfolioPerformance.benchmark?.name || 'Unavailable'} · Volatility {portfolioPerformance.volatility_percent == null ? 'requires 30 observations' : formatTablePercent2(portfolioPerformance.volatility_percent)} · Sharpe {portfolioPerformance.sharpe_ratio ?? 'Incomplete'} · Completeness: {portfolioPerformance.completeness}</div>
            </> : valid && !loading ? <div className="alert alert-secondary py-2 small">Portfolio performance is unavailable for the current portfolio and date range.</div> : null}
            {accountPerformance ? <div className="card mb-3"><div className="card-header d-flex justify-content-between align-items-center"><span>Account performance · {accountPerformance.calculation_mode}</span><button type="button" className="btn btn-sm btn-outline-secondary" disabled={savingEvidence} onClick={() => preserve('account_performance')}>Preserve evidence</button></div><div className="card-body small">Independently aggregated across {accountPerformance.portfolio_ids.length} included portfolio(s), never averaged: XIRR {accountPerformance.xirr_percent == null ? 'Incomplete' : formatTablePercent2(accountPerformance.xirr_percent)} · TWR {accountPerformance.twr_percent == null ? 'Incomplete' : formatTablePercent2(accountPerformance.twr_percent)}.</div></div> : valid && !loading ? <div className="alert alert-secondary py-2 small">Account performance is unavailable for the current account and inputs.</div> : null}
            {attribution ? <div className="card mb-4"><div className="card-header">Reconciliation attribution</div><div className="card-body small">Method: {attribution.method}. Explained realized: {formatTableMoney2(attribution.explained_net_realized)}; residual: {attribution.reconciliation_residual == null ? 'Incomplete' : formatTableMoney2(attribution.reconciliation_residual)}. {attribution.limitations.join(' ')}</div></div> : null}
            {tax ? <>
            <div className="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-2"><h2 className="h5 mb-0">Account Tax · FY {financialYear}</h2><div className="d-flex flex-wrap gap-1"><button type="button" className="btn btn-sm btn-outline-secondary" disabled={savingEvidence} onClick={() => preserve('account_tax')}>Preserve evidence</button>{['summary', 'realized_gains', 'open_lots', 'dividends', 'losses', 'assumptions'].map((dataset) => <button key={dataset} type="button" className="btn btn-sm btn-outline-primary" onClick={() => downloadTaxCsv(dataset, taxParams)}>Export {dataset.replaceAll('_', ' ')}</button>)}</div></div>
            <div className="row g-3 mb-3"><Metric label="STCG" value={tax.summary.short_term_realized_gain} kind="money" /><Metric label="LTCG" value={tax.summary.long_term_realized_gain} kind="money" /><Metric label="Dividends" value={tax.summary.dividend_income} kind="money" /><Metric label="Estimated tax" value={tax.summary.estimated_tax} kind="money" /></div>
            <div className={`alert py-2 small ${tax.completeness === 'complete' ? 'alert-success' : 'alert-warning'}`}>Mode: {tax.calculation_mode}. Completeness: {tax.completeness}. {tax.limitations.join(' ') || 'All required evidence is available.'}</div>
            </> : valid && !loading ? <div className="alert alert-secondary py-2 small">Account tax is unavailable for the current financial year and inputs.</div> : null}
        </div> : null}
        {hasConfirmedBatch && enabled ? <TaxEvidenceEntryPanel financialYear={financialYear} onChanged={reload} /> : null}
    </div>;
}
