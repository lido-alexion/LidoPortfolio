import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { usePortfolio } from '../context/PortfolioContext';
import { DataTableCard } from '../components/DataTable';
import AnalyseStockButton from '../components/AnalyseStockButton';
import DashboardTopMoverCard from '../components/DashboardTopMoverCard';
import DashboardAllocationCard from '../components/DashboardAllocationCard';
import PercentGradientBar from '../components/PercentGradientBar';
import SentimentGauge from '../components/SentimentGauge';
import MarketPhaseGauge from '../components/MarketPhaseGauge';
import TrendGauge from '../components/TrendGauge';
import MomentumGauge from '../components/MomentumGauge';
import VolatilityGauge from '../components/VolatilityGauge';
import RiskGauge from '../components/RiskGauge';
import MarketRegimeGauge from '../components/MarketRegimeGauge';
import MarketBreadthGauge from '../components/MarketBreadthGauge';
import { DashboardCalendarCard } from '../components/calendar/CalendarDayEventsDialog';
import PatternSketch from '../components/PatternSketch';
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
    ReferenceLine,
    ResponsiveContainer,
    Legend,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const TOP_MOVER_PERIOD_KEY = 'portfolio_dashboard_top_mover_period';
const MARKET_DIAGNOSTICS_COLLAPSED_KEY = 'portfolio_dashboard_market_diagnostics_collapsed';

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

function loadMarketDiagnosticsExpanded() {
    try {
        // Default collapsed. Explicit '0' = expanded, '1' = collapsed.
        return sessionStorage.getItem(MARKET_DIAGNOSTICS_COLLAPSED_KEY) === '0';
    } catch {
        return false;
    }
}

function persistMarketDiagnosticsExpanded(expanded) {
    try {
        sessionStorage.setItem(MARKET_DIAGNOSTICS_COLLAPSED_KEY, expanded ? '0' : '1');
    } catch {
        // Ignore storage failures (private mode/quota).
    }
}

function clampDisplayScore(value) {
    const n = Number(value);
    if (value == null || value === '' || Number.isNaN(n)) {
        return null;
    }
    return Math.min(100, Math.max(0, Math.round(n)));
}

/**
 * Presentation-only Market Health score from existing analytics fields.
 * Prefer dedicated keys when present; otherwise use sentiment (engine composite).
 */
function resolveMarketHealthScore(marketAnalytics) {
    if (!marketAnalytics) {
        return null;
    }
    return clampDisplayScore(
        marketAnalytics.market_health_score
        ?? marketAnalytics.health_score
        ?? marketAnalytics.sentiment?.score,
    );
}

function marketHealthStatus(score) {
    if (score == null) return '—';
    if (score >= 80) return 'Excellent';
    if (score >= 65) return 'Healthy';
    if (score >= 50) return 'Neutral';
    if (score >= 35) return 'Weak';
    return 'Poor';
}

function marketDecisionZone(marketAnalytics, score) {
    if (marketAnalytics?.new_entry_allowed === false) {
        return { label: 'Defensive', icon: 'bi-circle-fill', className: 'text-danger' };
    }
    if (score == null) {
        return { label: '—', icon: 'bi-dash-circle', className: 'text-muted' };
    }
    if (score >= 75) {
        return { label: 'Aggressive', icon: 'bi-circle-fill', className: 'text-success' };
    }
    if (score >= 45) {
        return { label: 'Selective', icon: 'bi-circle-fill', className: 'text-warning' };
    }
    return { label: 'Defensive', icon: 'bi-circle-fill', className: 'text-danger' };
}

function suggestedExposure(marketAnalytics, score) {
    const mult = Number(marketAnalytics?.allocation_multiplier);
    if (!Number.isNaN(mult) && marketAnalytics?.allocation_multiplier != null) {
        return `${Math.round(Math.min(1, Math.max(0, mult)) * 100)}%`;
    }
    if (score == null) return '—';
    if (score >= 85) return '100%';
    if (score >= 65) return '80%';
    if (score >= 45) return '50%';
    return '20%';
}

function contributorBadgeClass(available, ok) {
    if (!available) {
        return 'bg-secondary-subtle text-secondary-emphasis';
    }
    return ok
        ? 'bg-success-subtle text-success-emphasis'
        : 'bg-warning-subtle text-warning-emphasis';
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
    const navigate = useNavigate();
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
    const [calendarEvents, setCalendarEvents] = useState([]);
    const [calendarLoading, setCalendarLoading] = useState(true);
    const [servedFromCache, setServedFromCache] = useState(false);
    const [cachedAt, setCachedAt] = useState(null);
    const [marketDiagnosticsExpanded, setMarketDiagnosticsExpanded] = useState(loadMarketDiagnosticsExpanded);

    const handleTopMoverPeriodChange = useCallback((period) => {
        setTopMoverPeriod(period);
        saveTopMoverPeriod(period);
    }, []);

    const fetchCalendarUpcoming = useCallback(() => {
        if (!profileId) {
            return Promise.resolve();
        }
        setCalendarLoading(true);
        return api.get('/calendar/upcoming')
            .then((res) => setCalendarEvents(res.data?.data ?? []))
            .catch(() => setCalendarEvents([]))
            .finally(() => setCalendarLoading(false));
    }, [profileId]);

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
                fetchCalendarUpcoming();
                return Promise.resolve();
            }
        }

        setServedFromCache(false);
        setPatternLoading(true);
        setCalendarLoading(true);
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
                fetchCalendarUpcoming();
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
                setCalendarEvents([]);
            })
            .finally(() => setPatternLoading(false));
    }, [userId, profileId, isAdmin, fetchCalendarUpcoming]);

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
            cell: ({ row }) => {
                const symbol = row.original.stock?.symbol || '—';
                const stockId = row.original.stock_id || row.original.stock?.id;
                return (
                    <span className="lido-stock-symbol-with-analyse">
                        <span>{symbol}</span>
                        <AnalyseStockButton
                            stockId={stockId}
                            symbol={row.original.stock?.symbol}
                            name={row.original.stock?.name}
                        />
                    </span>
                );
            },
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
                <span className="lido-stock-symbol-with-analyse">
                    <Link to={`/stocks/${row.original.stock_id}/prices`}>
                        {row.original.symbol}
                    </Link>
                    <AnalyseStockButton
                        stockId={row.original.stock_id}
                        symbol={row.original.symbol}
                        name={row.original.name || row.original.stock_name}
                    />
                </span>
            ),
        },
        {
            id: 'pattern_name',
            header: 'Pattern',
            accessorKey: 'pattern_name',
            cell: ({ row }) => {
                const name = row.original.pattern_name;
                const patternId = row.original.pattern_id;
                const nameLabel = patternId ? (
                    <Link to={patternGuideLink(patternId)}>{name}</Link>
                ) : name;

                return (
                    <div className="d-inline-flex align-items-center gap-2">
                        {patternId ? (
                            <PatternSketch patternId={patternId} className="lido-pattern-sketch--table" />
                        ) : null}
                        {nameLabel}
                    </div>
                );
            },
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

    const investedNum = Number(data.invested_value);
    const gainLossNum = Number(data.total_gain_loss);
    const profitLossPct = investedNum > 0 && !Number.isNaN(investedNum) && !Number.isNaN(gainLossNum)
        ? (gainLossNum / investedNum) * 100
        : null;

    const positionCount = data.portfolio_analytics?.number_of_positions;
    const oversizedPositionCount = (data.portfolio_analytics?.allocation || [])
        .filter((row) => Number(row.allocation_pct) > 15)
        .length;
    const positionsCountClass = positionCount == null
        ? ''
        : (positionCount >= 6 && positionCount <= 20 ? 'text-success' : 'text-danger');

    const profitLossPctLabel = profitLossPct != null
        ? ` ( ${profitLossPct >= 0 ? '+' : ''}${profitLossPct.toFixed(1)}% )`
        : '';

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
            value: `${formatInrWhole(data.total_gain_loss)}${profitLossPctLabel}`,
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

    const cashAvailable = Number(
        data.available_investable_cash
        ?? data.portfolio_analytics?.cash_available
        ?? data.cash?.available_investable_cash,
    );
    const cashAvailableValid = !Number.isNaN(cashAvailable);
    const cashDenom = cashAvailableValid
        ? cashAvailable + (Number.isNaN(Number(data.portfolio_value)) ? 0 : Number(data.portfolio_value))
        : 0;
    const cashAvailablePct = cashAvailableValid && cashDenom > 0
        ? (cashAvailable / cashDenom) * 100
        : null;
    const cashAvailablePctInBand = cashAvailablePct != null
        && cashAvailablePct >= 7.1
        && cashAvailablePct <= 15;
    const cashAvailablePctLabel = cashAvailablePct != null
        ? ` ( ${cashAvailablePct.toFixed(1)}% )`
        : '';

    const growthData = (data.portfolio_growth || []).map((point) => {
        const rawDate = point.snapshot_date;
        const date = typeof rawDate === 'string'
            ? rawDate.slice(0, 10)
            : rawDate;
        return {
            date,
            portfolio_value: Number(point.portfolio_value || 0),
            invested_value: Number(point.invested_value || 0),
            unrealized_pl: Number(point.portfolio_value || 0) - Number(point.invested_value || 0),
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
    const marketAnalytics = data.market_analytics || null;
    const marketHealthScore = resolveMarketHealthScore(marketAnalytics);
    const marketHealthStatusLabel = marketHealthStatus(marketHealthScore);
    const marketZone = marketDecisionZone(marketAnalytics, marketHealthScore);
    const marketExposure = suggestedExposure(marketAnalytics, marketHealthScore);
    const marketContributorFlags = [
        {
            key: 'trend',
            label: 'Trend',
            ok: (marketAnalytics?.trend?.score ?? marketAnalytics?.trend?.strength) >= 60,
            available: (marketAnalytics?.trend?.score ?? marketAnalytics?.trend?.strength) != null,
        },
        {
            key: 'momentum',
            label: 'Momentum',
            ok: marketAnalytics?.momentum?.score >= 60,
            available: marketAnalytics?.momentum?.score != null,
        },
        {
            key: 'breadth',
            label: 'Breadth',
            ok: marketAnalytics?.breadth?.score >= 55,
            available: marketAnalytics?.breadth?.score != null,
        },
        {
            key: 'risk',
            label: 'Risk',
            ok: (marketAnalytics?.risk?.raw_risk ?? (marketAnalytics?.risk?.score != null
                ? 100 - Number(marketAnalytics.risk.score)
                : null)) <= 50,
            available: (marketAnalytics?.risk?.raw_risk ?? marketAnalytics?.risk?.score) != null,
        },
        {
            key: 'sentiment',
            label: 'Sentiment',
            ok: marketAnalytics?.sentiment?.score >= 50,
            available: marketAnalytics?.sentiment?.score != null,
        },
    ];

    return (
        <div className="row g-3">
            {loadError ? (
                <div className="col-12">
                    <div className="alert alert-warning mb-0">{loadError}</div>
                </div>
            ) : null}
            <div className="col-12 d-flex flex-wrap align-items-center gap-2">
                <div className="d-flex flex-wrap align-items-center gap-2">
                    <button
                        type="button"
                        className="btn btn-outline-secondary btn-sm"
                        onClick={handleRefreshDashboard}
                        disabled={refreshing || !userId || !profileId}
                        title="Clear local cache and reload dashboard from the server"
                    >
                        {refreshing ? 'Refreshing…' : 'Refresh dashboard'}
                    </button>
                    {servedFromCache && cachedAt ? (
                        <span className="text-muted small" title="Dashboard data was loaded from local cache for faster navigation">
                            Last refreshed {formatDashboardCacheLabel(cachedAt)}
                        </span>
                    ) : null}
                </div>
                {isAdmin ? (
                    <div className="d-flex flex-wrap align-items-center gap-2 ms-auto">
                        {pricesSyncedToday && !syncInProgress ? (
                            <span className="text-muted small">
                                Synced for {dailySync.today || 'today'}
                            </span>
                        ) : null}
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
                    </div>
                ) : null}
            </div>
            <div className="col-12">
                <h2 className="h6 text-muted mb-0">Portfolio</h2>
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
                gainer={topGainer}
                loser={topLoser}
                period={topMoverPeriod}
                onPeriodChange={handleTopMoverPeriodChange}
            />
            {cashAvailableValid ? (
                <div className="col-12 col-md-6 col-lg-4" key="Cash available">
                    <div className="card h-100">
                        <div className="card-body">
                            <div className="text-muted small">Cash available</div>
                            <div
                                className={[
                                    'h5 m-0',
                                    cashAvailablePctInBand ? 'text-success' : '',
                                ].join(' ').trim()}
                            >
                                {formatInrWhole(cashAvailable)}
                                {cashAvailablePctLabel}
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}
            {data.portfolio_analytics || data.market_analytics ? (
                <>
                    {(data.portfolio_analytics ? [
                        {
                            title: 'Positions',
                            value: positionCount,
                            valueClassName: `${positionsCountClass} fw-semibold`.trim(),
                            hint: null,
                            renderValue: () => (
                                <div className={`fw-semibold ${positionsCountClass}`.trim()}>
                                    <span>{positionCount}</span>
                                    {oversizedPositionCount > 0 ? (
                                        <a
                                            href="#dashboard-allocation"
                                            className="text-allocation-elevated small ms-1 fw-normal text-decoration-none"
                                            title="Jump to Allocation"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                document.getElementById('dashboard-allocation')
                                                    ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                            }}
                                        >
                                            (Oversized: {oversizedPositionCount})
                                        </a>
                                    ) : null}
                                </div>
                            ),
                        },
                        {
                            title: 'Diversification',
                            value: data.portfolio_analytics.diversification_score,
                            valueClassName: 'fw-semibold',
                            hint: null,
                            renderValue: () => (
                                <PercentGradientBar
                                    value={data.portfolio_analytics.diversification_score}
                                    className="mt-1"
                                />
                            ),
                        },
                        {
                            title: 'Avg Relative Strength (3-month Nifty50)',
                            value: data.portfolio_analytics.average_relative_strength,
                            valueClassName: `fw-semibold ${signedMetricClass(data.portfolio_analytics.average_relative_strength)}`.trim(),
                            hint: null,
                        },
                        {
                            title: 'Avg Momentum',
                            value: data.portfolio_analytics.average_momentum_score,
                            valueClassName: 'fw-semibold',
                            hint: null,
                        },
                        {
                            title: 'Avg Trend',
                            value: data.portfolio_analytics.average_trend_score,
                            valueClassName: 'fw-semibold',
                            hint: null,
                        },
                    ] : []).filter((card) => card.value != null && card.value !== '').map((card) => (
                        <div className="col-12 col-md-6 col-lg-4" key={`pa-${card.title}`}>
                            <div className="card h-100">
                                <div className="card-body py-2">
                                    <div className="text-muted small">{card.title}</div>
                                    {card.renderValue ? card.renderValue() : (
                                        <div className={card.valueClassName}>{card.value}</div>
                                    )}
                                    {card.hint ? (
                                        <div className="text-muted small mt-1 lh-sm">
                                            {card.hint}
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        </div>
                    ))}
                    {marketAnalytics ? (
                        <div className="col-12">
                            <h2 className="h6 text-muted mb-2">Market analytics</h2>
                            <div className="card lido-market-health-card">
                                <div className="card-body py-3">
                                    <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <div className="fw-semibold">Market Health</div>
                                            <div className="small text-muted">Executive summary</div>
                                        </div>
                                        <span className="badge text-bg-secondary">0–100</span>
                                    </div>
                                    {marketHealthScore != null ? (
                                        <PercentGradientBar
                                            value={marketHealthScore}
                                            className="mt-1 mb-2"
                                            title={`${marketHealthScore} / 100`}
                                        />
                                    ) : (
                                        <div className="small text-muted mb-2">Score placeholder</div>
                                    )}
                                    <div className="d-flex flex-wrap align-items-baseline gap-2 mb-2">
                                        <div className="h5 mb-0">{marketHealthScore != null ? `${marketHealthScore} / 100` : '— / 100'}</div>
                                        <div className="small text-muted">{marketHealthStatusLabel}</div>
                                    </div>
                                    <div className="row g-2">
                                        <div className="col-sm-6">
                                            <div className="small text-muted">Decision Zone</div>
                                            <div className={`small fw-semibold d-inline-flex align-items-center gap-1 ${marketZone.className}`.trim()}>
                                                <i className={`bi ${marketZone.icon}`} aria-hidden="true" />
                                                <span>{marketZone.label}</span>
                                            </div>
                                        </div>
                                        <div className="col-sm-6">
                                            <div className="small text-muted">Suggested Exposure</div>
                                            <div className="small fw-semibold">{marketExposure}</div>
                                        </div>
                                    </div>
                                    <div className="d-flex flex-wrap align-items-center gap-1 mt-2">
                                        <span className="small text-muted me-1">Contributors</span>
                                        {marketContributorFlags.map((item) => (
                                            <span
                                                key={item.key}
                                                className={`badge rounded-pill ${contributorBadgeClass(item.available, item.ok)}`}
                                            >
                                                <i
                                                    className={`bi ${
                                                        item.available
                                                            ? (item.ok ? 'bi-check-lg' : 'bi-exclamation-triangle')
                                                            : 'bi-dash'
                                                    } me-1`}
                                                    aria-hidden="true"
                                                />
                                                {item.label}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </div>
                            <div className="lido-market-diagnostics-separator">
                                <button
                                    type="button"
                                    className="btn btn-light btn-sm lido-market-diagnostics-toggle rounded-circle shadow-sm"
                                    onClick={() => {
                                        const next = !marketDiagnosticsExpanded;
                                        setMarketDiagnosticsExpanded(next);
                                        persistMarketDiagnosticsExpanded(next);
                                    }}
                                    title={marketDiagnosticsExpanded ? 'Hide market diagnostics' : 'Show market diagnostics'}
                                    aria-label={marketDiagnosticsExpanded ? 'Hide market diagnostics' : 'Show market diagnostics'}
                                    aria-expanded={marketDiagnosticsExpanded}
                                    aria-controls="dashboard-market-diagnostics"
                                >
                                    <i className={`bi ${marketDiagnosticsExpanded ? 'bi-chevron-up' : 'bi-chevron-down'}`} aria-hidden="true" />
                                </button>
                            </div>
                            {marketDiagnosticsExpanded ? (
                            <div
                                id="dashboard-market-diagnostics"
                                className="mt-2"
                            >
                                <div className="d-flex justify-content-between align-items-center mb-2">
                                    <div className="small text-muted">Analytics gauges (diagnostics)</div>
                                    <Link
                                        to="/market-depth"
                                        className="small lido-market-depth-title-link text-decoration-none"
                                    >
                                        View Market Depth →
                                    </Link>
                                </div>
                                <div className="row g-3">
                                    {marketAnalytics?.sentiment?.score != null ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-Sentiment">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">Sentiment</div>
                                                    <SentimentGauge
                                                        score={marketAnalytics.sentiment.score}
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {marketAnalytics?.market_phase ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-MarketPhase">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">Market phase</div>
                                                    <MarketPhaseGauge
                                                        phase={marketAnalytics.market_phase}
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {(marketAnalytics?.trend?.score != null
                                        || marketAnalytics?.trend?.strength != null) ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-Trend">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">Trend</div>
                                                    <TrendGauge
                                                        score={marketAnalytics.trend?.score
                                                            ?? marketAnalytics.trend?.strength}
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {marketAnalytics?.momentum?.score != null ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-Momentum">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">Momentum</div>
                                                    <MomentumGauge
                                                        score={marketAnalytics.momentum.score}
                                                        direction={marketAnalytics.momentum.direction}
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {marketAnalytics?.volatility?.score != null ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-Volatility">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">Volatility</div>
                                                    <VolatilityGauge
                                                        score={marketAnalytics.volatility.score}
                                                        historicalVolatilityPct={
                                                            marketAnalytics.volatility.historical_volatility_pct
                                                        }
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {(marketAnalytics?.risk?.raw_risk != null
                                        || marketAnalytics?.risk?.score != null) ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-Risk">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">Risk</div>
                                                    <RiskGauge
                                                        rawRisk={marketAnalytics.risk?.raw_risk}
                                                        score={marketAnalytics.risk?.score}
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {marketAnalytics?.market_regime ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-MarketRegime">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">Market regime</div>
                                                    <MarketRegimeGauge
                                                        regime={marketAnalytics.market_regime}
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {marketAnalytics?.breadth?.score != null ? (
                                        <div className="col-6 col-md-4 col-lg-3" key="ma-MarketBreadth">
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">
                                                        <Link
                                                            to="/market-depth"
                                                            className="lido-market-depth-title-link text-muted text-decoration-none"
                                                        >
                                                            Market breadth
                                                        </Link>
                                                    </div>
                                                    <MarketBreadthGauge
                                                        score={marketAnalytics.breadth.score}
                                                        advanceDeclineRatio={
                                                            marketAnalytics.breadth.advance_decline_ratio
                                                            ?? marketAnalytics.advance_decline_ratio
                                                        }
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                    {(marketAnalytics ? [
                                        ['% above 50 DMA', marketAnalytics.pct_stocks_above_50_dma != null
                                            ? `${marketAnalytics.pct_stocks_above_50_dma}%`
                                            : null],
                                        ['% above 200 DMA', marketAnalytics.pct_stocks_above_200_dma != null
                                            ? `${marketAnalytics.pct_stocks_above_200_dma}%`
                                            : null],
                                    ] : []).filter(([, v]) => v != null && v !== '').map(([title, value]) => (
                                        <div className="col-6 col-md-4 col-lg-3" key={`ma-${title}`}>
                                            <div className="card h-100">
                                                <div className="card-body py-2">
                                                    <div className="text-muted small">{title}</div>
                                                    <div className="fw-semibold">{value}</div>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            ) : null}
                        </div>
                    ) : null}
                </>
            ) : null}
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
                <DashboardCalendarCard
                    events={calendarEvents}
                    loading={calendarLoading}
                    onOpenCalendar={() => navigate('/calendar')}
                />
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
                            Patterns guide {'>'}
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
            <div className="col-12 col-lg-6" id="dashboard-allocation">
                <DashboardAllocationCard
                    className="h-100"
                    allocation={data.allocation || []}
                    investedValue={data.invested_value}
                    columns={allocationColumns}
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
            <div className="col-12">
                <div className="card">
                    <div className="card-header">
                        Unrealized P/L (portfolio value − invested)
                    </div>
                    <div className="card-body">
                        {growthData.length === 0 ? (
                            <div className="text-center py-4">
                                <p className="text-muted mb-0">
                                    No portfolio history yet. Rebuild history on the chart above to populate
                                    this graph.
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
                                            formatter={(value) => [formatInrWhole(value), 'Unrealized P/L']}
                                            labelFormatter={(label) => formatTransactionDateDisplay(label) || label}
                                        />
                                        <ReferenceLine
                                            y={0}
                                            stroke="var(--lido-border-strong)"
                                            strokeDasharray="4 4"
                                        />
                                        <Legend />
                                        <Line
                                            type="monotone"
                                            dataKey="unrealized_pl"
                                            name="Unrealized P/L"
                                            stroke="#6610f2"
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
