import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Tooltip as BootstrapTooltip } from 'bootstrap';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import api from '../api';
import NumberInput from '../components/NumberInput';
import SegmentToggle from '../components/SegmentToggle';
import StockAutocomplete from '../components/StockAutocomplete';
import { formatTableMoney2 } from '../utils/tableFormat';
import { formatTransactionDateDisplay } from '../utils/transactionDate';
import { showToast } from '../toast';

const PERIOD_OPTIONS = [
    { value: '1', label: '1 month' },
    { value: '3', label: '3 months' },
    { value: '6', label: '6 months' },
];

function formatPct(value) {
    if (value === null || value === undefined) {
        return '—';
    }
    return `${Number(value).toFixed(2)}%`;
}

function toNumericString(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    const num = Number(value);
    return Number.isNaN(num) ? '' : num.toFixed(2);
}

function parsePositive(value) {
    const num = Number(value);
    return Number.isFinite(num) && num > 0 ? num : null;
}

function periodAgoDateDisplay(months, startDateIso) {
    if (startDateIso) {
        const formatted = formatTransactionDateDisplay(startDateIso);
        if (formatted) {
            return formatted;
        }
    }
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    d.setMonth(d.getMonth() - months);
    const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return formatTransactionDateDisplay(iso);
}

function ChevronUpIcon() {
    return (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
            <path
                d="M7.5 14.5L12 10l4.5 4.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function ChevronDownIcon() {
    return (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
            <path
                d="M7.5 9.5L12 14l4.5-4.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function ManualRsInputCard({
    expanded,
    onToggleExpanded,
    stockSymbol,
    benchmarkSymbol,
    periodLabel,
    periodAgoDate,
    manualRsValues,
    onManualRsValuesChange,
    onSubmit,
    description,
    showCollapsedHint,
}) {
    const toggleRef = useRef(null);

    useEffect(() => {
        const el = toggleRef.current;
        if (!el) {
            return undefined;
        }
        const tooltip = new BootstrapTooltip(el);
        return () => tooltip.dispose();
    }, [expanded]);

    const toggleLabel = expanded ? 'Collapse' : 'Expand';
    const canSubmitManualRs = Boolean(
        parsePositive(manualRsValues.stockLatestClose)
        && parsePositive(manualRsValues.indexLatestClose)
        && parsePositive(manualRsValues.stockPreviousClose)
        && parsePositive(manualRsValues.indexPreviousClose),
    );

    return (
        <div className="card mb-3">
            <div className="card-header d-flex justify-content-between align-items-center gap-2">
                <span>Manual Relative Strength Input</span>
                <button
                    ref={toggleRef}
                    type="button"
                    className="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center explorer-manual-toggle-btn"
                    onClick={() => onToggleExpanded(!expanded)}
                    data-bs-toggle="tooltip"
                    data-bs-placement="left"
                    title={toggleLabel}
                    aria-label={toggleLabel}
                    aria-expanded={expanded}
                >
                    {expanded ? <ChevronUpIcon /> : <ChevronDownIcon />}
                </button>
            </div>
            {expanded ? (
                <div className="card-body">
                    <p className="text-muted small mb-3">{description}</p>
                    <form className="row g-3" onSubmit={onSubmit}>
                        <div className="col-12 col-md-6">
                            <label className="form-label" htmlFor="manual-stock-latest">{stockSymbol} latest close</label>
                            <NumberInput
                                id="manual-stock-latest"
                                min="0.01"
                                step="0.01"
                                placeholder="0.00"
                                value={manualRsValues.stockLatestClose}
                                onChange={(e) => onManualRsValuesChange({ stockLatestClose: e.target.value })}
                                onBlur={(e) => onManualRsValuesChange({
                                    stockLatestClose: e.target.value === '' ? '' : toNumericString(e.target.value),
                                })}
                            />
                        </div>
                        <div className="col-12 col-md-6">
                            <label className="form-label" htmlFor="manual-index-latest">{benchmarkSymbol} latest close</label>
                            <NumberInput
                                id="manual-index-latest"
                                min="0.01"
                                step="0.01"
                                placeholder="0.00"
                                value={manualRsValues.indexLatestClose}
                                onChange={(e) => onManualRsValuesChange({ indexLatestClose: e.target.value })}
                                onBlur={(e) => onManualRsValuesChange({
                                    indexLatestClose: e.target.value === '' ? '' : toNumericString(e.target.value),
                                })}
                            />
                        </div>
                        <div className="col-12 col-md-6">
                            <label className="form-label" htmlFor="manual-stock-previous">
                                {stockSymbol} close {periodLabel} ago ({periodAgoDate})
                            </label>
                            <NumberInput
                                id="manual-stock-previous"
                                min="0.01"
                                step="0.01"
                                placeholder="0.00"
                                value={manualRsValues.stockPreviousClose}
                                onChange={(e) => onManualRsValuesChange({ stockPreviousClose: e.target.value })}
                                onBlur={(e) => onManualRsValuesChange({
                                    stockPreviousClose: e.target.value === '' ? '' : toNumericString(e.target.value),
                                })}
                            />
                        </div>
                        <div className="col-12 col-md-6">
                            <label className="form-label" htmlFor="manual-index-previous">
                                {benchmarkSymbol} close {periodLabel} ago ({periodAgoDate})
                            </label>
                            <NumberInput
                                id="manual-index-previous"
                                min="0.01"
                                step="0.01"
                                placeholder="0.00"
                                value={manualRsValues.indexPreviousClose}
                                onChange={(e) => onManualRsValuesChange({ indexPreviousClose: e.target.value })}
                                onBlur={(e) => onManualRsValuesChange({
                                    indexPreviousClose: e.target.value === '' ? '' : toNumericString(e.target.value),
                                })}
                            />
                        </div>
                        <div className="col-12 text-end">
                            <button
                                type="submit"
                                className="btn btn-outline-primary"
                                disabled={!canSubmitManualRs}
                            >
                                Calculate RS from manual values
                            </button>
                        </div>
                    </form>
                </div>
            ) : showCollapsedHint ? (
                <div className="card-body py-2">
                    <p className="small text-muted mb-0">
                        Manual values are applied. Expand to edit and recalculate.
                    </p>
                </div>
            ) : null}
        </div>
    );
}

export default function StockExplorerPage() {
    const [selectedStock, setSelectedStock] = useState(null);
    const [symbol, setSymbol] = useState('');
    const [exchange, setExchange] = useState('NSE');
    const [periodMonths, setPeriodMonths] = useState('3');
    const [benchmark, setBenchmark] = useState('NIFTY50');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState(null);
    const [lastRequestedSymbol, setLastRequestedSymbol] = useState('');
    const [manualFallbackVisible, setManualFallbackVisible] = useState(false);
    const [manualFormExpanded, setManualFormExpanded] = useState(true);
    const [manualRsValues, setManualRsValues] = useState({
        stockLatestClose: '',
        indexLatestClose: '',
        stockPreviousClose: '',
        indexPreviousClose: '',
    });
    const [manualRsResult, setManualRsResult] = useState(null);

    const runAnalysis = async (e) => {
        e.preventDefault();
        const targetSymbol = selectedStock?.symbol || symbol.trim();
        if (!targetSymbol) {
            showToast('Select or enter a stock symbol', 'danger');
            return;
        }

        const months = Number(periodMonths);
        setLastRequestedSymbol(targetSymbol);

        setLoading(true);
        setResult(null);
        setManualFallbackVisible(false);
        setManualRsResult(null);
        setManualFormExpanded(true);
        try {
            const res = await api.post('/analytics/explore', {
                symbol: targetSymbol,
                exchange: selectedStock?.exchange || exchange,
                benchmark_symbol: benchmark,
                periods: [months],
            }, { skipErrorToast: true });
            setResult(res.data.data);
        } catch (err) {
            const allErrors = err?.response?.data?.errors;
            const firstError = Array.isArray(allErrors)
                ? allErrors[0]
                : Object.values(allErrors || {}).flat()[0];
            const msg = err?.response?.data?.message || firstError || 'Analysis failed';
            showToast(msg, 'danger');
            if (err?.response?.status === 422) {
                setManualFallbackVisible(true);
                setManualFormExpanded(true);
                setManualRsValues({
                    stockLatestClose: '',
                    indexLatestClose: '',
                    stockPreviousClose: '',
                    indexPreviousClose: '',
                });
            }
        } finally {
            setLoading(false);
        }
    };

    const chartData = result?.chart || [];
    const periodKey = `${periodMonths}m`;
    const periodLabel = PERIOD_OPTIONS.find((p) => p.value === periodMonths)?.label ?? `${periodMonths} month`;
    const periodClose = result?.period_closes?.[periodKey];
    const benchmarkSymbol = result?.benchmark?.symbol ?? benchmark;
    const stockSymbol = result?.stock?.symbol ?? lastRequestedSymbol ?? symbol;
    const fetchedRsInputs = useMemo(() => ({
        stockLatestClose: result?.latest_close ?? periodClose?.stock_end_close ?? null,
        indexLatestClose: result?.benchmark?.latest_close ?? periodClose?.benchmark_end_close ?? null,
        stockPreviousClose: periodClose?.stock_start_close ?? null,
        indexPreviousClose: periodClose?.benchmark_start_close ?? null,
    }), [periodClose, result]);
    const hasMissingRsInput = Object.values(fetchedRsInputs).some((v) => v === null || v === undefined);
    const showManualRsForm = hasMissingRsInput || manualFallbackVisible;
    const displayedRelativeStrength = manualRsResult ?? result?.relative_strength?.[periodKey];
    const hasSymbolInput = Boolean((selectedStock?.symbol || symbol).trim());
    const periodAgoDate = useMemo(
        () => periodAgoDateDisplay(Number(periodMonths), periodClose?.start_date),
        [periodClose?.start_date, periodMonths],
    );

    useEffect(() => {
        if (!result) {
            return;
        }
        setManualRsValues({
            stockLatestClose: toNumericString(fetchedRsInputs.stockLatestClose),
            indexLatestClose: toNumericString(fetchedRsInputs.indexLatestClose),
            stockPreviousClose: toNumericString(fetchedRsInputs.stockPreviousClose),
            indexPreviousClose: toNumericString(fetchedRsInputs.indexPreviousClose),
        });
        setManualRsResult(null);
        setManualFormExpanded(hasMissingRsInput);
    }, [fetchedRsInputs, hasMissingRsInput, result]);

    const handleManualRsSubmit = (e) => {
        e.preventDefault();
        const stockLatest = parsePositive(manualRsValues.stockLatestClose);
        const indexLatest = parsePositive(manualRsValues.indexLatestClose);
        const stockPrevious = parsePositive(manualRsValues.stockPreviousClose);
        const indexPrevious = parsePositive(manualRsValues.indexPreviousClose);

        if (!stockLatest || !indexLatest || !stockPrevious || !indexPrevious) {
            showToast('Enter all four close values as numbers greater than zero', 'warning');
            return;
        }

        const stockGrowth = ((stockLatest - stockPrevious) / stockPrevious) * 100;
        const indexGrowth = ((indexLatest - indexPrevious) / indexPrevious) * 100;
        setManualRsResult(stockGrowth - indexGrowth);
        setManualFormExpanded(false);
    };

    const manualStockLatest = parsePositive(manualRsValues.stockLatestClose);
    const manualIndexLatest = parsePositive(manualRsValues.indexLatestClose);
    const manualStockPrevious = parsePositive(manualRsValues.stockPreviousClose);
    const manualIndexPrevious = parsePositive(manualRsValues.indexPreviousClose);
    const manualCanRenderMetrics = Boolean(
        manualRsResult !== null
        && manualStockLatest
        && manualIndexLatest
        && manualStockPrevious
        && manualIndexPrevious,
    );
    const manualStockGrowth = manualCanRenderMetrics
        ? ((manualStockLatest - manualStockPrevious) / manualStockPrevious) * 100
        : null;
    const manualIndexGrowth = manualCanRenderMetrics
        ? ((manualIndexLatest - manualIndexPrevious) / manualIndexPrevious) * 100
        : null;
    const manualChartData = manualCanRenderMetrics
        ? [{
            period: periodKey.toUpperCase(),
            growth_percent: manualStockGrowth,
            benchmark_growth_percent: manualIndexGrowth,
        }]
        : [];

    const updateManualRsValues = (patch) => {
        setManualRsValues((prev) => ({ ...prev, ...patch }));
    };

    const manualRsDescription = manualFallbackVisible && !result
        ? 'Analysis data is unavailable for this symbol. Enter all four close values to calculate RS temporarily.'
        : 'Fill the four required close values to calculate RS temporarily. Available values from local cache/providers are prefilled when present.';

    const manualRsCard = showManualRsForm ? (
        <ManualRsInputCard
            expanded={manualFormExpanded}
            onToggleExpanded={setManualFormExpanded}
            stockSymbol={stockSymbol}
            benchmarkSymbol={benchmarkSymbol}
            periodLabel={periodLabel}
            periodAgoDate={periodAgoDate}
            manualRsValues={manualRsValues}
            onManualRsValuesChange={updateManualRsValues}
            onSubmit={handleManualRsSubmit}
            description={manualRsDescription}
            showCollapsedHint={manualRsResult !== null || manualCanRenderMetrics}
        />
    ) : null;

    return (
        <div className="row g-3">
            <div className="col-12 col-lg-4">
                <div className="card">
                    <div className="card-header">Calculate relative strength</div>
                    <div className="card-body">
                        <p className="text-muted small mb-3">
                            Analyze any NSE/BSE symbol. History is cached locally after first fetch.
                            {' '}
                            Search a stock and run analysis to see growth % and relative strength vs NIFTY50.
                        </p>
                        <form className="d-grid gap-3" onSubmit={runAnalysis}>
                            <SegmentToggle
                                label="Exchange"
                                ariaLabel="Stock exchange"
                                value={exchange}
                                onChange={(next) => {
                                    setExchange(next);
                                    setSelectedStock(null);
                                }}
                                options={[
                                    { value: 'NSE', label: 'NSE' },
                                    { value: 'BSE', label: 'BSE' },
                                ]}
                            />
                            <StockAutocomplete
                                value={symbol}
                                exchange={exchange}
                                onChange={(s) => {
                                    setSymbol(s);
                                    setSelectedStock(null);
                                }}
                                onSelect={(stock) => {
                                    setSelectedStock(stock);
                                    setSymbol(stock.symbol);
                                    setExchange(stock.exchange || 'NSE');
                                }}
                            />
                            <SegmentToggle
                                label="Strength period"
                                ariaLabel="Relative strength period"
                                value={periodMonths}
                                onChange={setPeriodMonths}
                                options={PERIOD_OPTIONS}
                            />
                            <div>
                                <label className="form-label">Benchmark</label>
                                <select
                                    className="form-select"
                                    value={benchmark}
                                    onChange={(e) => setBenchmark(e.target.value)}
                                >
                                    <option value="NIFTY50">NIFTY50</option>
                                </select>
                            </div>
                            <button className="btn btn-primary" type="submit" disabled={loading || !hasSymbolInput}>
                                {loading ? 'Analyzing…' : 'Run Analysis'}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div className="col-12 col-lg-8">
                {loading && (
                    <div className="text-muted">Running analysis…</div>
                )}
                {result && (
                    <>
                        {manualRsCard}
                        <div className="row g-3 mb-3">
                            <div className="col-6 col-md-4">
                                <div className="card text-center h-100">
                                    <div className="card-body">
                                        <div className="text-muted small">Latest close</div>
                                        <div className="h4 mb-0">{formatTableMoney2(result.latest_close)}</div>
                                        <div className="small text-muted">{stockSymbol}</div>
                                    </div>
                                </div>
                            </div>
                            <div className="col-6 col-md-4">
                                <div className="card text-center h-100">
                                    <div className="card-body">
                                        <div className="text-muted small">Close {periodLabel} ago</div>
                                        <div className="h4 mb-0">
                                            {formatTableMoney2(periodClose?.stock_start_close)}
                                        </div>
                                        <div className="small text-muted">{stockSymbol}</div>
                                    </div>
                                </div>
                            </div>
                            <div className="col-6 col-md-4">
                                <div className="card text-center h-100">
                                    <div className="card-body">
                                        <div className="text-muted small">{benchmarkSymbol} latest</div>
                                        <div className="h4 mb-0">
                                            {formatTableMoney2(result.benchmark?.latest_close)}
                                        </div>
                                        <div className="small text-muted">{benchmarkSymbol}</div>
                                    </div>
                                </div>
                            </div>
                            <div className="col-6 col-md-4">
                                <div className="card text-center h-100">
                                    <div className="card-body">
                                        <div className="text-muted small">{benchmarkSymbol} close {periodLabel} ago</div>
                                        <div className="h4 mb-0">
                                            {formatTableMoney2(periodClose?.benchmark_start_close)}
                                        </div>
                                        <div className="small text-muted">{benchmarkSymbol}</div>
                                    </div>
                                </div>
                            </div>
                            <div className="col-6 col-md-4">
                                <div className="card text-center h-100">
                                    <div className="card-body">
                                        <div className="text-muted small">{stockSymbol} growth ({periodLabel})</div>
                                        <div className="h4 mb-0">{formatPct(result.growth_percent?.[periodKey])}</div>
                                    </div>
                                </div>
                            </div>
                            <div className="col-6 col-md-4">
                                <div className="card text-center h-100">
                                    <div className="card-body">
                                        <div className="text-muted small">{benchmarkSymbol} growth ({periodLabel})</div>
                                        <div className="h4 mb-0">
                                            {formatPct(result.benchmark_growth_percent?.[periodKey])}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="card mb-3">
                            <div className="card-body py-3">
                                <div className="text-muted small">Relative strength vs {benchmarkSymbol} ({periodLabel})</div>
                                <div className="h3 mb-0">{formatPct(displayedRelativeStrength)}</div>
                                <div className="small text-muted">
                                    Stock return minus {benchmarkSymbol} return over the same period.
                                </div>
                                {manualRsResult !== null && (
                                    <div className="small text-success mt-1">
                                        Calculated from manual inputs.
                                    </div>
                                )}
                            </div>
                        </div>
                        {result.history?.benchmark_fetch && !result.history.benchmark_fetch.cache_hit
                            && (result.history.benchmark_fetch.errors?.length > 0
                                || result.history.benchmark_fetch.stored_rows === 0) && (
                            <div className="alert alert-warning small">
                                NIFTY50 price history may be incomplete. Relative strength needs benchmark data in the selected period.
                            </div>
                        )}
                        <div className="card mb-3">
                            <div className="card-header">Growth % Comparison</div>
                            <div className="card-body explorer-growth-chart-body">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData} maxBarSize={32}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="period" />
                                        <YAxis />
                                        <Tooltip formatter={(v) => `${Number(v).toFixed(2)}%`} />
                                        <Legend />
                                        <Bar dataKey="growth_percent" name="Stock growth %" fill="#0d6efd" />
                                        <Bar dataKey="benchmark_growth_percent" name="NIFTY growth %" fill="#6c757d" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                        {result.history?.stock_fetch?.cache_hit && (
                            <p className="text-muted small mt-2 mb-0">
                                Price history served from local cache (no provider fetch required).
                            </p>
                        )}
                    </>
                )}
                {!loading && !result && manualFallbackVisible && (
                    <>
                        {manualRsCard}
                        {manualCanRenderMetrics && (
                            <>
                                <div className="row g-3 mb-3">
                                    <div className="col-6 col-md-4">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">Latest close</div>
                                                <div className="h4 mb-0">{formatTableMoney2(manualStockLatest)}</div>
                                                <div className="small text-muted">{stockSymbol}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-6 col-md-4">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">Close {periodLabel} ago</div>
                                                <div className="h4 mb-0">{formatTableMoney2(manualStockPrevious)}</div>
                                                <div className="small text-muted">{stockSymbol}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-6 col-md-4">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">{benchmarkSymbol} latest</div>
                                                <div className="h4 mb-0">{formatTableMoney2(manualIndexLatest)}</div>
                                                <div className="small text-muted">{benchmarkSymbol}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-6 col-md-4">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">{benchmarkSymbol} close {periodLabel} ago</div>
                                                <div className="h4 mb-0">{formatTableMoney2(manualIndexPrevious)}</div>
                                                <div className="small text-muted">{benchmarkSymbol}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-6 col-md-4">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">{stockSymbol} growth ({periodLabel})</div>
                                                <div className="h4 mb-0">{formatPct(manualStockGrowth)}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-6 col-md-4">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">{benchmarkSymbol} growth ({periodLabel})</div>
                                                <div className="h4 mb-0">{formatPct(manualIndexGrowth)}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="card mb-3">
                                    <div className="card-body py-3">
                                        <div className="text-muted small">Relative strength vs {benchmarkSymbol} ({periodLabel})</div>
                                        <div className="h3 mb-0">{formatPct(manualRsResult)}</div>
                                        <div className="small text-muted">
                                            Stock return minus {benchmarkSymbol} return over the same period.
                                        </div>
                                        <div className="small text-success mt-1">Calculated from manual inputs.</div>
                                    </div>
                                </div>
                                <div className="card mb-3">
                                    <div className="card-header">Growth % Comparison</div>
                                    <div className="card-body explorer-growth-chart-body">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={manualChartData} maxBarSize={32}>
                                                <CartesianGrid strokeDasharray="3 3" />
                                                <XAxis dataKey="period" />
                                                <YAxis />
                                                <Tooltip formatter={(v) => `${Number(v).toFixed(2)}%`} />
                                                <Legend />
                                                <Bar dataKey="growth_percent" name="Stock growth %" fill="#0d6efd" />
                                                <Bar dataKey="benchmark_growth_percent" name="NIFTY growth %" fill="#6c757d" />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
