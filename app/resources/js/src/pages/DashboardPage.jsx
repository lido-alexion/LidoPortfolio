import React, { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { DataTableCard } from '../components/DataTable';
import { showToast } from '../toast';
import { formatInrCompactWhole, formatInrWhole, formatTablePercent0 } from '../utils/tableFormat';
import { formatChartAxisDate, formatTransactionDateDisplay } from '../utils/transactionDate';
import {
    notifyPortfolioDashboardRefresh,
    PORTFOLIO_DASHBOARD_REFRESH,
} from '../utils/portfolioEvents';
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
    const isAdmin = Boolean(user?.is_admin);
    const [data, setData] = useState(null);
    const [loadError, setLoadError] = useState('');
    const [rebuildingHistory, setRebuildingHistory] = useState(false);
    const [syncingPrices, setSyncingPrices] = useState(false);
    const [clearingAlerts, setClearingAlerts] = useState(false);
    const [acknowledgingId, setAcknowledgingId] = useState(null);

    const load = useCallback(() => {
        setLoadError('');
        return api.get('/dashboard')
            .then((res) => setData(res.data))
            .catch(() => setLoadError('Failed to load dashboard'));
    }, []);

    const runDailyPriceSync = useCallback((force = false) => {
        setSyncingPrices(true);
        api.post('/sync/daily', { force })
            .then((res) => {
                const body = res.data || {};
                showToast(body.message || 'Daily price sync finished.');
                return load().then(() => {
                    if (!body.skipped) {
                        notifyPortfolioDashboardRefresh();
                    }
                });
            })
            .catch((err) => {
                const msg = err?.response?.data?.message || 'Failed to run daily price sync.';
                showToast(msg, 'danger');
            })
            .finally(() => setSyncingPrices(false));
    }, [load]);

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
                return load();
            })
            .catch(() => setLoadError('Failed to rebuild portfolio history'))
            .finally(() => setRebuildingHistory(false));
    }, [load]);

    const clearAllAlerts = useCallback(() => {
        setClearingAlerts(true);
        api.post('/alerts/expire-all')
            .then((res) => {
                showToast(res.data?.message || 'Alerts cleared.');
                return load();
            })
            .catch(() => showToast('Failed to clear alerts.', 'danger'))
            .finally(() => setClearingAlerts(false));
    }, [load]);

    const acknowledgeAlert = useCallback((alertId) => {
        setAcknowledgingId(alertId);
        api.post(`/alerts/${alertId}/acknowledge`)
            .then(() => load())
            .catch(() => showToast('Failed to acknowledge alert.', 'danger'))
            .finally(() => setAcknowledgingId(null));
    }, [load]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        const onRefresh = () => load();
        window.addEventListener(PORTFOLIO_DASHBOARD_REFRESH, onRefresh);
        return () => window.removeEventListener(PORTFOLIO_DASHBOARD_REFRESH, onRefresh);
    }, [load]);

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
        {
            title: 'Top Gainer',
            value: data.top_gainer?.symbol || 'N/A',
            valueClassName: '',
        },
        {
            title: 'Top Loser',
            value: data.top_loser?.symbol || 'N/A',
            valueClassName: '',
        },
    ];

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

    const alerts = (data.stoploss_alerts || []).slice(0, 10);
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
