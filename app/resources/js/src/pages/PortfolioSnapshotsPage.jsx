import React, { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import PortfolioSnapshotGrowthChart from '../components/portfolio/PortfolioSnapshotGrowthChart';
import useApiGet from '../hooks/useApiGet';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { ROUTES } from '../navigation/routes';
import { showToast } from '../toast';
import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';
import { usePortfolio } from '../context/PortfolioContext';
import { formatInrWhole, formatTablePercent2 } from '../utils/tableFormat';
import { formatTransactionDateDisplay, getLocalTodayDateString } from '../utils/transactionDate';

const RANGE_OPTIONS = [
    { id: '90d', label: '90 days', days: 90, limit: 90 },
    { id: '180d', label: '180 days', days: 180, limit: 180 },
    { id: '365d', label: '365 days', days: 365, limit: 365 },
    { id: 'all', label: 'All', days: null, limit: 2000 },
];

function subtractDays(dateString, days) {
    const date = new Date(`${dateString}T12:00:00`);
    date.setDate(date.getDate() - days);
    return date.toISOString().slice(0, 10);
}

function normalizeSnapshotRows(payload) {
    return (payload?.snapshots || []).map((row) => ({
        snapshot_date: String(row.snapshot_date).slice(0, 10),
        portfolio_value: Number(row.portfolio_value || 0),
        invested_value: Number(row.invested_value || 0),
    }));
}

function signedClass(value) {
    if (value == null || Number.isNaN(Number(value)) || Number(value) === 0) {
        return '';
    }
    return Number(value) > 0 ? 'text-success' : 'text-danger';
}

export default function PortfolioSnapshotsPage() {
    const { activePortfolio } = usePortfolio();
    const profileId = activePortfolio?.id;
    const [rangeId, setRangeId] = useState('365d');
    const [rebuilding, setRebuilding] = useState(false);

    const range = RANGE_OPTIONS.find((opt) => opt.id === rangeId) || RANGE_OPTIONS[2];

    const loadSnapshots = useCallback(async () => {
        const params = { limit: range.limit };
        if (range.days != null) {
            params.from_date = subtractDays(getLocalTodayDateString(), range.days);
        }
        const { data } = await api.get('/portfolio/snapshots', { params, skipErrorToast: true });
        return data;
    }, [range.days, range.limit]);

    const {
        data,
        loading,
        error,
        reload,
    } = useApiGet({
        request: loadSnapshots,
        deps: [profileId, rangeId],
        enabled: Boolean(profileId),
        errorFallback: 'Failed to load portfolio snapshots',
        initialData: { snapshots: [], meta: { count: 0 } },
    });

    usePortfolioChanged(useCallback(() => {
        reload();
    }, [reload]));

    const snapshotsAsc = useMemo(() => normalizeSnapshotRows(data), [data]);
    const snapshotsDesc = useMemo(
        () => [...snapshotsAsc].reverse(),
        [snapshotsAsc],
    );

    const latest = snapshotsAsc[snapshotsAsc.length - 1] || null;
    const latestUnrealized = latest
        ? latest.portfolio_value - latest.invested_value
        : null;

    const tableRows = useMemo(() => snapshotsDesc.map((row, index) => {
        const prev = snapshotsDesc[index + 1];
        const dailyChange = prev
            ? row.portfolio_value - prev.portfolio_value
            : null;
        const dailyChangePct = prev && prev.portfolio_value !== 0
            ? (dailyChange / prev.portfolio_value) * 100
            : null;

        return {
            ...row,
            unrealized_pl: row.portfolio_value - row.invested_value,
            daily_change: dailyChange,
            daily_change_pct: dailyChangePct,
        };
    }), [snapshotsDesc]);

    const requestRebuild = useCallback(() => {
        const confirmed = window.confirm(
            'Rebuild portfolio snapshot history from transactions and stock prices?\n\n'
            + 'This recalculates daily portfolio_value and invested_value rows. '
            + 'It can take a minute or longer for large portfolios.',
        );
        if (!confirmed) {
            return;
        }

        setRebuilding(true);
        api.post('/portfolio/rebuild-history')
            .then((res) => {
                const written = res.data?.rebuild?.snapshots_written;
                const msg = written != null
                    ? `Portfolio history rebuilt (${written} snapshots).`
                    : (res.data?.message || 'Portfolio history rebuilt.');
                showToast(msg);
                notifyPortfolioDashboardRefresh();
                return reload();
            })
            .catch(() => {
                showToast('Failed to rebuild portfolio history', 'danger');
            })
            .finally(() => setRebuilding(false));
    }, [reload]);

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Portfolio Snapshots</h1>
                    <p className="text-muted small mb-0">
                        Daily portfolio value history rebuilt from transactions and closing prices.
                        Values are backend-calculated; this page does not recompute holdings.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link to={ROUTES.HOME} className="btn btn-sm btn-outline-secondary">
                        Dashboard
                    </Link>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary"
                        onClick={reload}
                        disabled={loading || rebuilding}
                    >
                        Refresh
                    </button>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        onClick={requestRebuild}
                        disabled={loading || rebuilding}
                    >
                        {rebuilding ? 'Rebuilding…' : 'Rebuild history'}
                    </button>
                </div>
            </div>

            <div className="d-flex flex-wrap gap-2 mb-3">
                {RANGE_OPTIONS.map((opt) => (
                    <button
                        key={opt.id}
                        type="button"
                        className={`btn btn-sm ${rangeId === opt.id ? 'btn-primary' : 'btn-outline-secondary'}`}
                        onClick={() => setRangeId(opt.id)}
                        disabled={loading}
                    >
                        {opt.label}
                    </button>
                ))}
            </div>

            {error && !loading && (
                <div className="alert alert-danger py-2" role="alert">
                    Could not load portfolio snapshots. Try Refresh or Rebuild history.
                </div>
            )}

            <div className="row g-3 mb-3">
                <div className="col-6 col-md-3">
                    <div className="card h-100">
                        <div className="card-body py-3">
                            <div className="text-muted small">Latest snapshot</div>
                            <div className="fw-semibold">
                                {latest
                                    ? formatTransactionDateDisplay(latest.snapshot_date)
                                    : '—'}
                            </div>
                        </div>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="card h-100">
                        <div className="card-body py-3">
                            <div className="text-muted small">Portfolio value</div>
                            <div className="fw-semibold">
                                {latest ? formatInrWhole(latest.portfolio_value) : '—'}
                            </div>
                        </div>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="card h-100">
                        <div className="card-body py-3">
                            <div className="text-muted small">Invested value</div>
                            <div className="fw-semibold">
                                {latest ? formatInrWhole(latest.invested_value) : '—'}
                            </div>
                        </div>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="card h-100">
                        <div className="card-body py-3">
                            <div className="text-muted small">Unrealized P/L</div>
                            <div className={`fw-semibold ${signedClass(latestUnrealized)}`}>
                                {latest ? formatInrWhole(latestUnrealized) : '—'}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card mb-3">
                <div className="card-header py-2 fw-semibold small d-flex justify-content-between align-items-center gap-2">
                    <span>Portfolio growth</span>
                    <span className="text-muted fw-normal">
                        {loading ? 'Loading…' : `${data?.meta?.count ?? 0} snapshot(s)`}
                    </span>
                </div>
                <div className="card-body">
                    {loading ? (
                        <div className="text-center py-5 text-muted">Loading snapshot history…</div>
                    ) : (
                        <PortfolioSnapshotGrowthChart snapshots={snapshotsAsc} />
                    )}
                </div>
            </div>

            <section className="card">
                <div className="card-header py-2 fw-semibold small">Daily snapshots</div>
                <div className="card-body p-0">
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th className="text-end">Portfolio</th>
                                    <th className="text-end">Invested</th>
                                    <th className="text-end">Unrealized P/L</th>
                                    <th className="text-end">Day change</th>
                                </tr>
                            </thead>
                            <tbody>
                                {loading ? (
                                    <tr>
                                        <td colSpan={5} className="text-muted small p-3">
                                            Loading snapshots…
                                        </td>
                                    </tr>
                                ) : tableRows.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="text-muted small p-4 text-center">
                                            No portfolio snapshots yet. History is built from your transactions
                                            and stock price data. Use <strong>Rebuild history</strong> to populate
                                            daily rows.
                                        </td>
                                    </tr>
                                ) : (
                                    tableRows.map((row) => (
                                        <tr key={row.snapshot_date}>
                                            <td className="small">
                                                {formatTransactionDateDisplay(row.snapshot_date)}
                                            </td>
                                            <td className="text-end">{formatInrWhole(row.portfolio_value)}</td>
                                            <td className="text-end">{formatInrWhole(row.invested_value)}</td>
                                            <td className={`text-end ${signedClass(row.unrealized_pl)}`}>
                                                {formatInrWhole(row.unrealized_pl)}
                                            </td>
                                            <td className={`text-end ${signedClass(row.daily_change)}`}>
                                                {row.daily_change == null
                                                    ? '—'
                                                    : (
                                                        <>
                                                            {formatInrWhole(row.daily_change)}
                                                            {row.daily_change_pct != null && (
                                                                <span className="text-muted ms-1">
                                                                    (
                                                                    {formatTablePercent2(row.daily_change_pct)}
                                                                    )
                                                                </span>
                                                            )}
                                                        </>
                                                    )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    );
}
