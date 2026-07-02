import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { usePortfolio } from '../context/PortfolioContext';
import { DataTableCard } from '../components/DataTable';
import DashboardTopMoverCard from '../components/DashboardTopMoverCard';
import { showToast } from '../toast';
import { categoryClassName, categoryLabel } from '../utils/patternDetection';
import {
    clearDashboardCache,
    flattenPatternScanResults,
    formatDashboardCacheLabel,
    readDashboardCache,
    writeDashboardCache,
} from '../utils/dashboardCache';
import { showAdminOperationalAlertsToastIfAny } from '../utils/adminOperationalAlertsToast';
import { patternGuideLink } from '../utils/patternGuideLinks';
import { formatInrCompactWhole, formatInrWhole, formatTablePercent0 } from '../utils/tableFormat';
import { formatChartAxisDate, formatTransactionDateDisplay } from '../utils/transactionDate';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Legend,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const TOP_MOVER_PERIOD_KEY = 'portfolio_dashboard_top_mover_period';

function loadTopMoverPeriod() {
    try {
        return localStorage.getItem(TOP_MOVER_PERIOD_KEY) === 'latest_day' ? 'latest_day' : 'all_time';
    } catch {
        return 'all_time';
    }
}

function saveTopMoverPeriod(period) {
    try {
        localStorage.setItem(TOP_MOVER_PERIOD_KEY, period);
    } catch {
        // Quota or private mode — ignore.
    }
}

const growthChartTooltipStyle = {
    backgroundColor: 'var(--lido-chart-tooltip-bg)',
    border: '1px solid var(--lido-chart-tooltip-border)',
    borderRadius: '6px',
    color: 'var(--lido-chart-tooltip-text)',
};

const growthChartTooltipLabelStyle = {
    color: 'var(--lido-chart-tooltip-label)',
    fontWeight: 600,
    marginBottom: 4,
};

function compareValueClass(a, b) {
    const left = Number(a);
    const right = Number(b);
    if (Number.isNaN(left) || Number.isNaN(right)) {
        return '';
    }
    if (left > right) {
        return 'text-success';
    }
    if (left < right) {
        return 'text-danger';
    }
    return 'text-body';
}

function signedMetricClass(value) {
    if (value == null || value === '') {
        return '';
    }
    const num = Number(value);
    if (Number.isNaN(num)) {
        return '';
    }
    if (num > 0) {
        return 'text-success';
    }
    if (num < 0) {
        return 'text-danger';
    }
    return 'text-body';
}

function allocationPercentClass(percent) {
    const n = Number(percent);
    if (Number.isNaN(n)) {
        return '';
    }
    if (n > 20) {
        return 'text-danger fw-semibold';
    }
    if (n > 15) {
        return 'text-allocation-elevated fw-semibold';
    }
    return '';
}

function allocationPercentCell(getValue) {
    const v = getValue();
    if (v == null || v === '') {
        return '—';
    }
    const n = Number(v);
    if (Number.isNaN(n)) {
        return '—';
    }
    const label = `${Math.round(n)}%`;
    const colorClass = allocationPercentClass(n);
    return colorClass ? <span className={colorClass}>{label}</span> : label;
}

function relativeStrengthCell(getValue) {
    const value = getValue();
    const formatted = formatTablePercent0(value);
    if (formatted === '—') {
        return <span className="text-muted">N/A</span>;
    }
    return <span className={signedMetricClass(value)}>{formatted}</span>;
}

function alertContextCell(contextJson) {
    if (contextJson && typeof contextJson === 'object' && !Array.isArray(contextJson) && contextJson.text) {
        return (
            <div className="small lh-sm" style={{ whiteSpace: 'pre-line' }}>
                {contextJson.text}
            </div>
        );
    }

    if (!Array.isArray(contextJson) || contextJson.length === 0) {
        return <span className="text-muted">—</span>;
    }

    return (
        <div className="small lh-sm">
            {contextJson.map((item) => (
                <div key={item.key || item.label}>
                    <span className="text-muted">{item.label || item.key}:</span>
                    {' '}
                    {item.value ?? '—'}
                </div>
            ))}
        </div>
    );
}

function averageRelativeStrength(metrics) {
    if (!metrics) {
        return null;
    }
    const values = [
        metrics.relative_strength_1m,
        metrics.relative_strength_3m,
        metrics.relative_strength_6m,
    ]
        .map((value) => (value == null || value === '' ? null : Number(value)))
        .filter((value) => value != null && !Number.isNaN(value));
    if (values.length === 0) {
        return null;
    }
    return values.reduce((sum, value) => sum + value, 0) / values.length;
}

export default function DashboardPage() {
    const { user } = useAuth();
    const { activePortfolio } = usePortfolio();
    const userId = user?.id;
    const profileId = activePortfolio?.id;
    const isAdmin = Boolean(user?.is_admin);
    const [data, setData] = useState(null);
    const [loadError, setLoadError] = useState('');
    const [topMoverPeriod, setTopMoverPeriod] = useState(loadTopMoverPeriod);
    const [rebuildingHistory, setRebuildingHistory] = useState(false);
    const [syncingPrices, setSyncingPrices] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [clearingAlerts, setClearingAlerts] = useState(false);
    const [acknowledgingId, setAcknowledgingId] = useState(null);
    const [patternRows, setPatternRows] = useState([]);
    const [patternLoading, setPatternLoading] = useState(true);
    const [servedFromCache, setServedFromCache] = useState(false);
    const [cachedAt, setCachedAt] = useState(null);

    const handleTopMoverPeriodChange = useCallback((period) => {
        setTopMoverPeriod(period);
        saveTopMoverPeriod(period);
    }, []);

    const fetchDashboard = useCallback(({ force = false } = {}) => {
        if (!userId || !profileId) {
            return Promise.resolve();
        }

        if (!force) {
            const cached = readDashboardCache(userId, profileId);
            if (cached) {
                setData(cached.dashboard);
                setPatternRows(cached.patternRows);
                setPatternLoading(false);
                setCachedAt(cached.cachedAt);
                setServedFromCache(true);
                setLoadError('');
                return Promise.resolve();
            }
        }

        setServedFromCache(false);
        setPatternLoading(true);
        setLoadError('');

        return Promise.all([
            api.get('/dashboard'),
            api.get('/patterns/scan', { params: { scope: 'holdings', actionable_only: true } }),
        ])
            .then(([dashboardRes, patternRes]) => {
                const dashboard = dashboardRes.data;
                const flat = flattenPatternScanResults(patternRes.data);
                setData(dashboard);
                setPatternRows(flat);
                writeDashboardCache(userId, profileId, { dashboard, patternRows: flat });
                setCachedAt(new Date().toISOString());
                setServedFromCache(false);
                if (isAdmin) {
                    showAdminOperationalAlertsToastIfAny();
                }
            })
            .catch(() => {
                setLoadError('Failed to load dashboard');
                setPatternRows([]);
            })
            .finally(() => setPatternLoading(false));
    }, [userId, profileId, isAdmin]);

    const handleRefreshDashboard = useCallback(() => {
        if (!userId || !profileId) {
            return;
        }
        setRefreshing(true);
        clearDashboardCache(userId, profileId);
        fetchDashboard({ force: true }).finally(() => setRefreshing(false));
    }, [userId, profileId, fetchDashboard]);

    const runDailyPriceSync = useCallback((force = false) => {
        setSyncingPrices(true);
        api.post('/sync/daily', { force })
            .then((res) => {
                const body = res.data || {};
                showToast(body.message || 'Daily price sync finished.');
                if (!body.skipped) {
                    clearDashboardCache(userId, profileId);
                }
                return fetchDashboard({ force: true });
            })
            .catch((err) => {
                const msg = err?.response?.data?.message || 'Failed to run daily price sync.';
                showToast(msg, 'danger');
            })
            .finally(() => setSyncingPrices(false));
    }, [userId, profileId, fetchDashboard]);

    const requestRebuildPortfolioHistory = useCallback(() => {
        const confirmed = window.confirm(
            'Rebuild portfolio history from your first transaction through today?\n\n'
            + 'This refetches missing stock prices and recalculates every trading day. '
            + 'It can take a minute or longer for large portfolios.',
        );
        if (!confirmed) {
            return;
        }

        setRebuildingHistory(true);
        setLoadError('');
        api.post('/portfolio/rebuild-history')
            .then((res) => {
                const written = res.data?.rebuild?.snapshots_written;
                const msg = written != null
                    ? `Portfolio history rebuilt (${written} snapshots).`
                    : (res.data?.message || 'Portfolio history rebuilt.');
                showToast(msg);
                clearDashboardCache(userId, profileId);
                return fetchDashboard({ force: true });
            })
            .catch(() => setLoadError('Failed to rebuild portfolio history'))
            .finally(() => setRebuildingHistory(false));
    }, [userId, profileId, fetchDashboard]);

    const clearAllAlerts = useCallback(() => {
        setClearingAlerts(true);
        api.post('/alerts/expire-all')
            .then((res) => {
                showToast(res.data?.message || 'Alerts cleared.');
                clearDashboardCache(userId, profileId);
                return fetchDashboard({ force: true });
            })
            .catch(() => showToast('Failed to clear alerts.', 'danger'))
            .finally(() => setClearingAlerts(false));
    }, [userId, profileId, fetchDashboard]);

    const acknowledgeAlert = useCallback((alertId) => {
        setAcknowledgingId(alertId);
        api.post(`/alerts/${alertId}/acknowledge`)
            .then(() => {
                clearDashboardCache(userId, profileId);
                return fetchDashboard({ force: true });
            })
            .catch(() => showToast('Failed to acknowledge alert.', 'danger'))
            .finally(() => setAcknowledgingId(null));
    }, [userId, profileId, fetchDashboard]);

    useEffect(() => {
        if (!userId || !profileId) {
            setData(null);
            setPatternRows([]);
            setPatternLoading(true);
            setServedFromCache(false);
            setCachedAt(null);
            return;
        }
        setData(null);
        setPatternRows([]);
        setLoadError('');
        fetchDashboard({ force: false });
    }, [userId, profileId, fetchDashboard]);

    const allocationColumns = useMemo(() => [
        { accessorKey: 'symbol', header: 'Symbol' },
        {
            accessorKey: 'allocation_market_percent',
            header: 'Market %',
            meta: { columnMenuLabel: 'Allocation (market value)' },
            cell: ({ getValue }) => allocationPercentCell(getValue),
        },
        {
            accessorKey: 'allocation_invested_percent',
            header: 'Invested %',
            meta: { columnMenuLabel: 'Allocation (invested)' },
            cell: ({ getValue }) => allocationPercentCell(getValue),
        },
        {
            accessorKey: 'market_value',
            header: 'Market Value',
            cell: ({ getValue }) => formatInrWhole(getValue()),
        },
    ], []);

    const rsColumns = useMemo(() => [
        { accessorKey: 'symbol', header: 'Symbol' },
        {
            id: 'rsAvg',
            header: 'Avg. strength',
            accessorFn: (row) => averageRelativeStrength(row.metrics),
            cell: ({ getValue }) => relativeStrengthCell(getValue),
            sortUndefined: 'last',
        },
        {
            id: 'rs1m',
            header: '1M',
            accessorFn: (row) => row.metrics?.relative_strength_1m,
            cell: ({ getValue }) => relativeStrengthCell(getValue),
        },
        {
            id: 'rs3m',
            header: '3M',
            accessorFn: (row) => row.metrics?.relative_strength_3m,
            cell: ({ getValue }) => relativeStrengthCell(getValue),
        },
        {
            id: 'rs6m',
            header: '6M',
            accessorFn: (row) => row.metrics?.relative_strength_6m,
            cell: ({ getValue }) => relativeStrengthCell(getValue),
        },
    ], []);

    const alertColumns = useMemo(() => [
        {
            id: 'symbol',
            header: 'Symbol',
            accessorFn: (row) => row.stock?.symbol,
        },
        {
            id: 'created_at',
            header: 'Date',
            accessorKey: 'created_at',
            cell: ({ getValue }) => formatTransactionDateDisplay(getValue()) || '—',
        },
        {
            id: 'message',
            header: 'Message',
            accessorKey: 'message',
            cell: ({ getValue }) => getValue() || '—',
        },
        {
            id: 'condition_display',
            header: 'Condition',
            accessorKey: 'condition_display',
            cell: ({ getValue }) => getValue() || '—',
        },
        {
            id: 'action_suggested',
            header: 'Action',
            accessorKey: 'action_suggested',
            cell: ({ getValue }) => getValue() || '—',
        },
        {
            id: 'context',
            header: 'Context',
            accessorKey: 'context_json',
            enableSorting: false,
            cell: ({ getValue }) => alertContextCell(getValue()),
        },
        {
            id: 'acknowledge',
            header: 'Acknowledge',
            enableSorting: false,
            enableHiding: false,
            cell: ({ row }) => (
                <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    disabled={acknowledgingId === row.original.id}
                    onClick={() => acknowledgeAlert(row.original.id)}
                >
                    {acknowledgingId === row.original.id ? '…' : 'Acknowledge'}
                </button>
            ),
        },
    ], [acknowledgeAlert, acknowledgingId]);

    const patternColumns = useMemo(() => [
        {
            id: 'symbol',
            header: 'Symbol',
            accessorKey: 'symbol',
            cell: ({ row }) => (
                <Link to={`/stocks/${row.original.stock_id}/prices`}>
                    {row.original.symbol}
                </Link>
            ),
        },
        {
            id: 'pattern_name',
            header: 'Pattern',
            accessorKey: 'pattern_name',
            cell: ({ row }) => (
                row.original.pattern_id ? (
                    <Link to={patternGuideLink(row.original.pattern_id)}>
                        {row.original.pattern_name}
                    </Link>
                ) : (
                    row.original.pattern_name
                )
            ),
        },
        {
            id: 'category',
            header: 'Signal',
            accessorKey: 'category',
            cell: ({ getValue }) => (
                <span className={categoryClassName(getValue())}>
                    {categoryLabel(getValue())}
                </span>
            ),
        },
        {
            id: 'bar_date',
            header: 'As of',
            accessorKey: 'bar_date',
            cell: ({ getValue }) => formatTransactionDateDisplay(getValue()) || '—',
        },
        {
            id: 'variant',
            header: 'Type',
            accessorKey: 'variant',
            cell: ({ getValue }) => (getValue() === 'chart' ? 'Chart' : 'Candle'),
        },
    ], []);

    if (!data && loadError) return <div className="alert alert-danger">{loadError}</div>;
    if (!data) return <div className="text-muted">Loading dashboard...</div>;

    const portfolioVsInvestedClass = compareValueClass(
        data.portfolio_value,
        data.invested_value,
    );

    const cards = [
        {
            title: 'Portfolio Value',
            value: formatInrWhole(data.portfolio_value),
            valueClassName: portfolioVsInvestedClass,
        },
        {
            title: 'Invested Value',
            value: formatInrWhole(data.invested_value),
            valueClassName: '',
        },
        {
            title: 'Total Gain/Loss',
            value: formatInrWhole(data.total_gain_loss),
            valueClassName: portfolioVsInvestedClass,
        },
        {
            title: 'XIRR',
            value: data.xirr != null ? `${Number(data.xirr).toFixed(2)}%` : 'N/A',
            valueClassName: signedMetricClass(data.xirr),
        },
    ];

    const topMoversForPeriod = data.top_movers?.[topMoverPeriod] || {};
    const topGainer = topMoversForPeriod.gainer ?? data.top_gainer;
    const topLoser = topMoversForPeriod.loser ?? data.top_loser;

    const growthData = (data.portfolio_growth || []).map((point) => {
        const rawDate = point.snapshot_date;
        const date = typeof rawDate === 'string'
            ? rawDate.slice(0, 10)
            : rawDate;
        return {
            date,
            portfolio_value: Number(point.portfolio_value || 0),
            invested_value: Number(point.invested_value || 0),
        };
    });
    const showGrowthDots = growthData.length > 0 && growthData.length <= 5;
    const growthChartManyPoints = growthData.length > 24;
    const growthChartBottomMargin = growthChartManyPoints ? 52 : 28;

    const alerts = (data.alerts || []).slice(0, 10);
    const dailySync = data.daily_market_sync || {};
    const pricesSyncedToday = Boolean(dailySync.synced_today);
    const syncInProgress = Boolean(dailySync.in_progress) || syncingPrices;
    const rsBenchmarkSymbol = data.nifty_comparison?.benchmark?.symbol || 'NIFTY50';

    return (
        <div className="row g-3">
            {loadError ? (
                <div className="col-12">
                    <div className="alert alert-warning mb-0">{loadError}</div>
                </div>
            ) : null}
            <div className="col-12 d-flex flex-wrap justify-content-end align-items-center gap-2">
                {servedFromCache && cachedAt ? (
                    <span className="text-muted small" title="Dashboard data is cached locally for faster navigation">
                        Cached {formatDashboardCacheLabel(cachedAt)}
                    </span>
                ) : null}
                <button
                    type="button"
                    className="btn btn-outline-secondary btn-sm"
                    onClick={handleRefreshDashboard}
                    disabled={refreshing || !userId || !profileId}
                    title="Clear local cache and reload dashboard from the server"
                >
                    {refreshing ? 'Refreshing…' : 'Refresh dashboard'}
                </button>
                {isAdmin && pricesSyncedToday && !syncInProgress ? (
                    <span className="text-muted small">
                        Synced for {dailySync.today || 'today'}
                    </span>
                ) : null}
                {isAdmin ? (
                <button
                    type="button"
                    className="btn btn-outline-primary btn-sm"
                    onClick={() => runDailyPriceSync(pricesSyncedToday)}
                    disabled={syncInProgress}
                    title={
                        syncInProgress
                            ? 'Price sync is running'
                            : pricesSyncedToday
                                ? 'Fetch latest prices again for held stocks'
                                : 'Fetch latest prices for held stocks'
                    }
                >
                    {syncInProgress
                        ? 'Syncing prices…'
                        : pricesSyncedToday
                            ? 'Sync again today'
                            : 'Sync prices for today'}
                </button>
                ) : null}
            </div>
            {cards.map(({ title, value, valueClassName }) => (
                <div className="col-12 col-md-6 col-lg-4" key={title}>
                    <div className="card h-100">
                        <div className="card-body">
                            <div className="text-muted small">{title}</div>
                            <div className={`h5 m-0 ${valueClassName}`.trim()}>{value}</div>
                        </div>
                    </div>
                </div>
            ))}
            <DashboardTopMoverCard
                title="Top Gainer"
                mover={topGainer}
                period={topMoverPeriod}
                onPeriodChange={handleTopMoverPeriodChange}
            />
            <DashboardTopMoverCard
                title="Top Loser"
                mover={topLoser}
                period={topMoverPeriod}
                onPeriodChange={handleTopMoverPeriodChange}
            />
            <div className="col-12">
                {alerts.length > 0 ? (
                    <DataTableCard
                        className="h-100"
                        title="Alerts"
                        columns={alertColumns}
                        data={alerts}
                        storageKey="dashboard-alerts-v3"
                        defaultColumnOrder={[
                            'symbol',
                            'created_at',
                            'message',
                            'condition_display',
                            'action_suggested',
                            'context',
                            'acknowledge',
                        ]}
                        emptyMessage="No active alerts"
                        headerExtra={(
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-danger"
                                onClick={clearAllAlerts}
                                disabled={clearingAlerts}
                            >
                                {clearingAlerts ? 'Clearing…' : 'Clear all'}
                            </button>
                        )}
                    />
                ) : (
                    <div className="card h-100">
                        <div className="card-header">
                            <div className="mb-0">Alerts</div>
                        </div>
                        <div className="card-body text-muted">
                            No active alerts
                        </div>
                    </div>
                )}
            </div>
            <div className="col-12">
                <DataTableCard
                    className="h-100"
                    title={(
                        <div className="lido-col-header-stack">
                            <span>Pattern signals (holdings)</span>
                            <span className="lido-col-header-sub">
                                Actionable candle &amp; chart patterns on cached OHLCV (latest bar)
                            </span>
                        </div>
                    )}
                    columns={patternColumns}
                    data={patternRows}
                    storageKey="dashboard-pattern-signals-v1"
                    loading={patternLoading}
                    emptyMessage="No actionable patterns on your holdings right now. Patterns need sufficient OHLCV history."
                    headerExtra={(
                        <Link to="/patterns" className="btn btn-sm btn-outline-secondary">
                            Pattern guide
                        </Link>
                    )}
                />
            </div>
            <div className="col-12 col-lg-6">
                <DataTableCard
                    className="h-100"
                    title={(
                        <div className="lido-col-header-stack">
                            <span>Relative Strength</span>
                            <span className="lido-col-header-sub">
                                vs {rsBenchmarkSymbol} — stock % minus index %
                            </span>
                        </div>
                    )}
                    columns={rsColumns}
                    data={data.relative_strength_trends || []}
                    storageKey="dashboard-rs-v2"
                    initialSorting={[{ id: 'rsAvg', desc: true }]}
                    bodyClassName="pt-2"
                    emptyMessage={`No relative strength data. Values need ${rsBenchmarkSymbol} and stock OHLCV (run daily price sync).`}
                />
            </div>
            <div className="col-12 col-lg-6">
                <DataTableCard
                    className="h-100"
                    title="Allocation"
                    columns={allocationColumns}
                    data={data.allocation || []}
                    storageKey="dashboard-allocation-v2"
                    emptyMessage="No allocation data"
                />
            </div>
            <div className="col-12">
                <div className="card">
                    <div className="card-header d-flex justify-content-between align-items-center gap-2">
                        <span>Portfolio Growth (transaction-aware history)</span>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary"
                            onClick={requestRebuildPortfolioHistory}
                            disabled={rebuildingHistory}
                            title="Recalculate daily portfolio snapshots from transactions and price history"
                        >
                            {rebuildingHistory ? 'Rebuilding…' : 'Rebuild history'}
                        </button>
                    </div>
                    <div className="card-body">
                        {growthData.length === 0 ? (
                            <div className="text-center py-4">
                                <p className="text-muted mb-0">
                                    No portfolio history yet. History is built from your transactions and
                                    stock price data. Use <strong>Rebuild history</strong> above to populate
                                    the chart.
                                </p>
                            </div>
                        ) : (
                            <div style={{ width: '100%', height: 280, minHeight: 280 }}>
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart
                                        data={growthData}
                                        margin={{ top: 8, right: 16, left: 4, bottom: growthChartBottomMargin }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis
                                            dataKey="date"
                                            tickFormatter={(v) => formatChartAxisDate(v) || v}
                                            tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                                            stroke="var(--lido-border-strong)"
                                            minTickGap={36}
                                            interval="preserveStartEnd"
                                            angle={growthChartManyPoints ? -40 : 0}
                                            textAnchor={growthChartManyPoints ? 'end' : 'middle'}
                                            height={growthChartManyPoints ? 48 : 28}
                                        />
                                        <YAxis tickFormatter={(v) => formatInrCompactWhole(v)} width={80} />
                                        <Tooltip
                                            contentStyle={growthChartTooltipStyle}
                                            labelStyle={growthChartTooltipLabelStyle}
                                            itemStyle={{ color: 'var(--lido-chart-tooltip-text)' }}
                                            formatter={(value, name) => [
                                                formatInrWhole(value),
                                                name === 'portfolio_value' ? 'Portfolio Value' : 'Invested Value',
                                            ]}
                                            labelFormatter={(label) => formatTransactionDateDisplay(label) || label}
                                        />
                                        <Legend />
                                        <Line
                                            type="monotone"
                                            dataKey="portfolio_value"
                                            name="Portfolio Value"
                                            stroke="#0d6efd"
                                            dot={showGrowthDots}
                                            strokeWidth={2}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="invested_value"
                                            name="Invested Value"
                                            stroke="#198754"
                                            dot={showGrowthDots}
                                            strokeWidth={2}
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
