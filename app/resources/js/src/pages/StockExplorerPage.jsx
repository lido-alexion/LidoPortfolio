import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Tooltip as BootstrapTooltip } from 'bootstrap';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import api from '../api';
import AnalyseStockButton from '../components/AnalyseStockButton';
import NumberInput from '../components/NumberInput';
import StockAutocomplete from '../components/StockAutocomplete';
import { stockExchangeLabel } from '../utils/exchangeDisplay';
import { formatTableMoney2 } from '../utils/tableFormat';
import { formatTransactionDateDisplay, formatChartAxisDate } from '../utils/transactionDate';
import { showToast } from '../toast';

const ANALYSIS_PERIODS = [1, 3, 6, 12];

const PERIOD_OPTIONS = [
    { value: '1', label: '1 month' },
    { value: '3', label: '3 months' },
    { value: '6', label: '6 months' },
    { value: '12', label: '1 year' },
];

const explorerChartTooltipStyle = {
    backgroundColor: 'var(--lido-chart-tooltip-bg)',
    border: '1px solid var(--lido-chart-tooltip-border)',
    borderRadius: '6px',
    color: 'var(--lido-chart-tooltip-text)',
};

const explorerChartTooltipLabelStyle = {
    color: 'var(--lido-chart-tooltip-label)',
    fontWeight: 600,
    marginBottom: 4,
};

function periodLabelForMonths(months) {
    return PERIOD_OPTIONS.find((p) => p.value === String(months))?.label ?? `${months} month`;
}

function PeriodHistoricalPricesCard({
    title,
    symbol,
    periodCloses,
    valueKey,
}) {
    return (
        <div className="card h-100">
            <div className="card-header py-2">{title}</div>
            <div className="card-body p-0">
                <table className="table table-sm mb-0 align-middle">
                    <tbody>
                        {ANALYSIS_PERIODS.map((months) => {
                            const key = `${months}m`;
                            const closes = periodCloses?.[key];
                            const price = closes?.[valueKey];
                            return (
                                <tr key={key}>
                                    <td className="text-muted ps-3">{periodLabelForMonths(months)} ago</td>
                                    <td className="text-end fw-medium pe-3">{formatTableMoney2(price)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div className="text-muted small px-3 py-2 border-top">{symbol}</div>
            </div>
        </div>
    );
}

function rsColorClass(value) {
    if (value === null || value === undefined) {
        return '';
    }
    return Number(value) >= 0 ? 'text-success' : 'text-danger';
}

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
                                fixedDecimals={2}
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
                                fixedDecimals={2}
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
                                fixedDecimals={2}
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
                                fixedDecimals={2}
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
    const [benchmark, setBenchmark] = useState('NIFTY50');
    const [benchmarkOptions, setBenchmarkOptions] = useState([]);
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

    useEffect(() => {
        let active = true;
        api.get('/indexes', { skipErrorToast: true })
            .then((res) => {
                if (!active) {
                    return;
                }
                const list = res.data?.data?.indexes ?? [];
                if (list.length > 0) {
                    setBenchmarkOptions(list);
                }
            })
            .catch(() => {
                // Keep the default NIFTY50 option if the catalog cannot be loaded.
            });
        return () => {
            active = false;
        };
    }, []);

    const benchmarkGroups = useMemo(() => {
        const source = benchmarkOptions.length > 0
            ? benchmarkOptions
            : [{ symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE', is_primary: true }];
        const groups = {};
        source.forEach((idx) => {
            const key = idx.exchange || 'NSE';
            (groups[key] = groups[key] || []).push(idx);
        });
        return groups;
    }, [benchmarkOptions]);

    const runAnalysis = async (e) => {
        e.preventDefault();
        const targetSymbol = selectedStock?.symbol || symbol.trim();
        if (!targetSymbol) {
            showToast('Select or enter a stock symbol', 'danger');
            return;
        }

        const months = ANALYSIS_PERIODS;
        setLastRequestedSymbol(targetSymbol);

        setLoading(true);
        setResult(null);
        setManualFallbackVisible(false);
        setManualRsResult(null);
        setManualFormExpanded(true);
        try {
            const res = await api.post('/analytics/explore', {
                symbol: targetSymbol,
                exchange: selectedStock?.exchange || 'NSE',
                benchmark_symbol: benchmark,
                periods: months,
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
    const normalizedGainData = result?.normalized_gain_chart || [];
    const normalizedGainManyPoints = normalizedGainData.length > 24;
    const manualPeriodMonths = 6;
    const periodKey = `${manualPeriodMonths}m`;
    const periodLabel = PERIOD_OPTIONS.find((p) => p.value === String(manualPeriodMonths))?.label ?? '6 months';
    const periodClose = result?.period_closes?.[periodKey];
    const benchmarkSymbol = result?.benchmark?.symbol ?? benchmark;
    const stockSymbol = result?.stock?.symbol ?? lastRequestedSymbol ?? symbol;
    const fetchedRsInputs = useMemo(() => ({
        stockLatestClose: result?.latest_close ?? periodClose?.stock_end_close ?? null,
        indexLatestClose: result?.benchmark?.latest_close ?? periodClose?.benchmark_end_close ?? null,
        stockPreviousClose: periodClose?.stock_start_close ?? null,
        indexPreviousClose: periodClose?.benchmark_start_close ?? null,
    }), [periodClose, result]);
    const hasMissingRsInput = ANALYSIS_PERIODS.some((months) => {
        const key = `${months}m`;
        const closes = result?.period_closes?.[key];
        return (
            result?.latest_close == null
            || result?.benchmark?.latest_close == null
            || closes?.stock_start_close == null
            || closes?.benchmark_start_close == null
        );
    });
    const displayedRelativeStrengthByPeriod = useMemo(() => {
        const map = {};
        ANALYSIS_PERIODS.forEach((months) => {
            const key = `${months}m`;
            if (months === manualPeriodMonths && manualRsResult !== null) {
                map[key] = manualRsResult;
            } else {
                map[key] = result?.relative_strength?.[key];
            }
        });
        return map;
    }, [manualRsResult, result]);
    const hasSymbolInput = Boolean((selectedStock?.symbol || symbol).trim());
    const periodAgoDate = useMemo(
        () => periodAgoDateDisplay(manualPeriodMonths, periodClose?.start_date),
        [periodClose?.start_date],
    );
    const showManualRsForm = hasMissingRsInput || manualFallbackVisible;

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
        ? ANALYSIS_PERIODS.map((months) => {
            const key = `${months}m`;
            if (months === manualPeriodMonths) {
                return {
                    period: months === 12 ? '1Y' : key.toUpperCase(),
                    growth_percent: manualStockGrowth,
                    benchmark_growth_percent: manualIndexGrowth,
                    relative_strength: manualRsResult,
                };
            }
            return {
                period: months === 12 ? '1Y' : key.toUpperCase(),
                growth_percent: result?.growth_percent?.[key] ?? null,
                benchmark_growth_percent: result?.benchmark_growth_percent?.[key] ?? null,
                relative_strength: result?.relative_strength?.[key] ?? null,
            };
        })
        : [];

    const updateManualRsValues = (patch) => {
        setManualRsValues((prev) => ({ ...prev, ...patch }));
    };

    const manualRsDescription = manualFallbackVisible && !result
        ? 'Analysis data is unavailable for this symbol. Enter all four close values to calculate RS temporarily (6-month period).'
        : 'Fill the four required close values to calculate RS temporarily for the 6-month period. Available values from universe price cache are prefilled when present.';

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
                            Analyze any symbol in the local stock master. Price history comes from universe
                            price sync (no on-demand provider fetch). Search a stock, pick a benchmark index,
                            and run analysis to see relative strength for 1, 3, 6, and 12 months.
                        </p>
                        <form className="d-grid gap-3" onSubmit={runAnalysis}>
                            <StockAutocomplete
                                value={symbol}
                                exchange={null}
                                onChange={(s) => {
                                    setSymbol(s);
                                    setSelectedStock(null);
                                }}
                                onSelect={(stock) => {
                                    setSelectedStock(stock);
                                    setSymbol(stock.symbol);
                                }}
                            />
                            {selectedStock && (
                                <div className="form-text text-muted mb-0">
                                    {selectedStock.symbol}
                                    {' · '}
                                    {stockExchangeLabel(selectedStock)}
                                </div>
                            )}
                            <div>
                                <label className="form-label">Benchmark index</label>
                                <select
                                    className="form-select"
                                    value={benchmark}
                                    onChange={(e) => setBenchmark(e.target.value)}
                                >
                                    {Object.entries(benchmarkGroups).map(([groupExchange, groupIndexes]) => (
                                        <optgroup key={groupExchange} label={groupExchange}>
                                            {groupIndexes.map((idx) => (
                                                <option key={idx.symbol} value={idx.symbol}>
                                                    {idx.name}
                                                    {' ('}
                                                    {idx.symbol}
                                                    {idx.is_primary ? ', primary' : ''}
                                                    {')'}
                                                </option>
                                            ))}
                                        </optgroup>
                                    ))}
                                </select>
                            </div>
                            <button className="btn btn-primary mb-3" type="submit" disabled={loading || !hasSymbolInput}>
                                {loading ? 'Calculating…' : 'Calculate relative strength'}
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
                            <div className="col-6 col-md-6">
                                <div className="card text-center h-100">
                                    <div className="card-body">
                                        <div className="text-muted small">Latest close</div>
                                        <div className="h4 mb-0">{formatTableMoney2(result.latest_close)}</div>
                                        <div className="small text-muted lido-stock-symbol-with-analyse justify-content-center">
                                            <span>{stockSymbol}</span>
                                            <AnalyseStockButton
                                                stockId={result.stock?.id}
                                                symbol={result.stock?.symbol || stockSymbol}
                                                name={result.stock?.name}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="col-6 col-md-6">
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
                            <div className="col-12 col-md-6">
                                <PeriodHistoricalPricesCard
                                    title="Stock prices (period start)"
                                    symbol={stockSymbol}
                                    periodCloses={result.period_closes}
                                    valueKey="stock_start_close"
                                />
                            </div>
                            <div className="col-12 col-md-6">
                                <PeriodHistoricalPricesCard
                                    title={`${benchmarkSymbol} prices (period start)`}
                                    symbol={benchmarkSymbol}
                                    periodCloses={result.period_closes}
                                    valueKey="benchmark_start_close"
                                />
                            </div>
                            {ANALYSIS_PERIODS.map((months) => {
                                const key = `${months}m`;
                                const label = periodLabelForMonths(months);
                                const rs = displayedRelativeStrengthByPeriod[key];
                                return (
                                    <div className="col-6 col-md-3" key={key}>
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">Relative strength ({label})</div>
                                                <div className={`h4 mb-0 ${rsColorClass(rs)}`}>{formatPct(rs)}</div>
                                                <div className="small text-muted">vs {benchmarkSymbol}</div>
                                                {months === manualPeriodMonths && manualRsResult !== null && (
                                                    <div className="small text-success mt-1">From manual inputs</div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        {result.history?.benchmark_fetch && !result.history.benchmark_fetch.cache_hit && (
                            <div className="alert alert-warning small">
                                {benchmarkSymbol}
                                {' '}
                                price history may be incomplete in the index cache. Relative strength needs benchmark data for all periods — run the index sync in Settings if needed.
                            </div>
                        )}
                        {result.history?.stock_fetch && !result.history.stock_fetch.cache_hit && (
                            <div className="alert alert-warning small">
                                Price history for {stockSymbol} may be incomplete. Run universe price sync in Settings if needed.
                            </div>
                        )}
                        <div className="card mb-3">
                            <div className="card-header">Growth % comparison (1M / 3M / 6M / 1Y)</div>
                            <div className="card-body explorer-growth-chart-body">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData} maxBarSize={32}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="period" />
                                        <YAxis tickFormatter={(v) => `${Number(v).toFixed(0)}%`} />
                                        <Tooltip formatter={(v) => `${Number(v).toFixed(2)}%`} />
                                        <Legend />
                                        <Bar dataKey="growth_percent" name="Stock growth %" fill="#0d6efd" />
                                        <Bar dataKey="benchmark_growth_percent" name={`${benchmarkSymbol} growth %`} fill="#6c757d" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                        {normalizedGainData.length > 0 ? (
                            <div className="card mb-3">
                                <div className="card-header">
                                    1-year % gain from period start ({stockSymbol} vs {benchmarkSymbol})
                                </div>
                                <div className="card-body explorer-growth-chart-body">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart
                                            data={normalizedGainData}
                                            margin={{
                                                top: 8,
                                                right: 16,
                                                left: 4,
                                                bottom: normalizedGainManyPoints ? 52 : 28,
                                            }}
                                        >
                                            <CartesianGrid strokeDasharray="3 3" />
                                            <XAxis
                                                dataKey="date"
                                                tickFormatter={(v) => formatChartAxisDate(v) || v}
                                                tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                                                stroke="var(--lido-border-strong)"
                                                minTickGap={36}
                                                interval="preserveStartEnd"
                                                angle={normalizedGainManyPoints ? -40 : 0}
                                                textAnchor={normalizedGainManyPoints ? 'end' : 'middle'}
                                                height={normalizedGainManyPoints ? 48 : 28}
                                            />
                                            <YAxis tickFormatter={(v) => `${Number(v).toFixed(0)}%`} width={56} />
                                            <Tooltip
                                                contentStyle={explorerChartTooltipStyle}
                                                labelStyle={explorerChartTooltipLabelStyle}
                                                itemStyle={{ color: 'var(--lido-chart-tooltip-text)' }}
                                                formatter={(value) => [`${Number(value).toFixed(2)}%`]}
                                                labelFormatter={(label) => formatTransactionDateDisplay(label) || label}
                                            />
                                            <Legend />
                                            <Line
                                                type="monotone"
                                                dataKey="stock_gain_percent"
                                                name={`${stockSymbol} % gain`}
                                                stroke="#0d6efd"
                                                dot={false}
                                                strokeWidth={2}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="benchmark_gain_percent"
                                                name={`${benchmarkSymbol} % gain`}
                                                stroke="#6c757d"
                                                dot={false}
                                                strokeWidth={2}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        ) : null}
                        {result.history?.stock_fetch?.cache_hit && result.history?.benchmark_fetch?.cache_hit && (
                            <p className="text-muted small mt-2 mb-0">
                                Price history served from universe cache (no provider fetch).
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
                                    <div className="col-6 col-md-6">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">Latest close</div>
                                                <div className="h4 mb-0">{formatTableMoney2(manualStockLatest)}</div>
                                                <div className="small text-muted lido-stock-symbol-with-analyse justify-content-center">
                                                    <span>{stockSymbol}</span>
                                                    <AnalyseStockButton
                                                        stockId={selectedStock?.id || result?.stock?.id}
                                                        symbol={selectedStock?.symbol || stockSymbol}
                                                        name={selectedStock?.name || result?.stock?.name}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-6 col-md-6">
                                        <div className="card text-center h-100">
                                            <div className="card-body">
                                                <div className="text-muted small">{benchmarkSymbol} latest</div>
                                                <div className="h4 mb-0">{formatTableMoney2(manualIndexLatest)}</div>
                                                <div className="small text-muted">{benchmarkSymbol}</div>
                                            </div>
                                        </div>
                                    </div>
                                    {ANALYSIS_PERIODS.map((months) => {
                                        const key = `${months}m`;
                                        const label = periodLabelForMonths(months);
                                        const rs = months === manualPeriodMonths ? manualRsResult : null;
                                        return (
                                            <div className="col-6 col-md-3" key={key}>
                                                <div className="card text-center h-100">
                                                    <div className="card-body">
                                                        <div className="text-muted small">Relative strength ({label})</div>
                                                        <div className={`h4 mb-0 ${rsColorClass(rs)}`}>{formatPct(rs)}</div>
                                                        <div className="small text-muted">vs {benchmarkSymbol}</div>
                                                        {months === manualPeriodMonths && (
                                                            <div className="small text-success mt-1">From manual inputs</div>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="card mb-3">
                                    <div className="card-header">Growth % comparison (1M / 3M / 6M / 1Y)</div>
                                    <div className="card-body explorer-growth-chart-body">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={manualChartData} maxBarSize={32}>
                                                <CartesianGrid strokeDasharray="3 3" />
                                                <XAxis dataKey="period" />
                                                <YAxis />
                                                <Tooltip formatter={(v) => `${Number(v).toFixed(2)}%`} />
                                                <Legend />
                                                <Bar dataKey="growth_percent" name="Stock growth %" fill="#0d6efd" />
                                                <Bar dataKey="benchmark_growth_percent" name={`${benchmarkSymbol} growth %`} fill="#6c757d" />
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
