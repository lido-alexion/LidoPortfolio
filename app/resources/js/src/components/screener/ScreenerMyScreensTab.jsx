import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../api';
import { DataTableCard } from '../DataTable';
import usePortfolioChanged from '../../hooks/usePortfolioChanged';
import { showToast } from '../../toast';
import {
    LastRunSummaryCell,
    ScreenerDescriptionCell,
    runStatsWarning,
    scheduleDisplay,
    scopeDisplay,
} from './screenerTableHelpers';

function validationMessage(error) {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) return first;
    }
    return error?.response?.data?.message || 'Something went wrong.';
}

export default function ScreenerMyScreensTab() {
    const navigate = useNavigate();
    const [screeners, setScreeners] = useState([]);
    const [loading, setLoading] = useState(true);
    const [deletingId, setDeletingId] = useState(null);
    const [runningId, setRunningId] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/screeners', { skipErrorToast: true });
            setScreeners(res.data?.data ?? []);
        } catch (error) {
            showToast(validationMessage(error), 'danger');
            setScreeners([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    usePortfolioChanged(load);

    const deleteScreener = useCallback(async (id) => {
        if (!window.confirm('Delete this screener and its run history?')) return;
        setDeletingId(id);
        try {
            await api.delete(`/screeners/${id}`);
            showToast('Screener deleted');
            await load();
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setDeletingId(null);
        }
    }, [load]);

    const pollContinue = useCallback(async (runId) => {
        let guard = 0;
        while (guard < 500) {
            guard += 1;
            const cont = await api.post(`/screener-runs/${runId}/continue`);
            if (cont.data?.completed) {
                return cont.data.data;
            }
            if (!cont.data?.continued) {
                return cont.data?.data;
            }
        }
        return null;
    }, []);

    const runNow = useCallback(async (id) => {
        setRunningId(id);
        try {
            const res = await api.post(`/screeners/${id}/run`);
            let run = res.data?.data;
            if (res.data?.continued && run?.id) {
                showToast('Scanning universe in chunks…');
                run = await pollContinue(run.id);
            }
            const matched = run?.stats?.matched ?? 0;
            const scanned = run?.stats?.scanned ?? 0;
            const warning = runStatsWarning(run?.stats, run?.error_message);
            showToast(
                warning
                    ? `Run finished: ${matched} match(es) / ${scanned} scanned. Warning: ${warning}`
                    : `Run finished: ${matched} match(es) / ${scanned} scanned`,
                warning ? 'warning' : undefined,
            );
            await load();
            if (run?.id) {
                navigate(`/screeners/${id}?run=${run.id}`);
            }
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setRunningId(null);
        }
    }, [load, navigate, pollContinue]);

    const columns = useMemo(() => [
        {
            id: 'name',
            header: 'Name',
            accessorKey: 'name',
            cell: ({ row }) => (
                <>
                    <Link to={`/screeners/${row.original.id}`} className="fw-semibold text-decoration-none">
                        {row.original.name}
                    </Link>
                    {!row.original.is_enabled && (
                        <span className="badge text-bg-secondary ms-2">Off</span>
                    )}
                    {row.original.is_shared && (
                        <span className="badge text-bg-info ms-2">Shared</span>
                    )}
                </>
            ),
        },
        {
            id: 'description',
            header: 'Description',
            accessorKey: 'description',
            enableSorting: false,
            cell: ({ getValue }) => <ScreenerDescriptionCell text={getValue()} />,
        },
        {
            id: 'scope',
            header: 'Scope',
            accessorFn: (row) => scopeDisplay(row),
            cell: ({ row }) => (
                row.original.watchlist_issue
                    ? (
                        <span className="text-danger" title={row.original.watchlist_issue}>
                            {scopeDisplay(row.original)}
                        </span>
                    )
                    : scopeDisplay(row.original)
            ),
        },
        {
            id: 'max_lookback',
            header: 'Lookback',
            meta: { columnMenuLabel: 'Lookback (min sessions)' },
            accessorKey: 'max_lookback',
            cell: ({ getValue }) => {
                const v = getValue();
                return v != null ? `≥ ${v} sessions` : '—';
            },
        },
        {
            id: 'schedule',
            header: 'Schedule',
            accessorFn: (row) => scheduleDisplay(row),
            cell: ({ row }) => scheduleDisplay(row.original) || '—',
        },
        {
            id: 'last_run_at',
            header: 'Last run',
            accessorKey: 'last_run_at',
            enableSorting: false,
            cell: ({ row }) => <LastRunSummaryCell row={row.original} />,
        },
        {
            id: 'actions',
            header: 'Actions',
            enableSorting: false,
            enableHiding: false,
            minSize: 240,
            size: 260,
            cell: ({ row }) => {
                const screenerId = row.original.id;
                return (
                    <div className="text-nowrap">
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-primary me-1"
                            disabled={runningId === screenerId}
                            onClick={() => runNow(screenerId)}
                        >
                            {runningId === screenerId ? 'Running…' : 'Run'}
                        </button>
                        <Link
                            to={`/screeners/${screenerId}`}
                            className="btn btn-sm btn-outline-secondary me-1"
                        >
                            Edit
                        </Link>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-danger"
                            disabled={deletingId === screenerId}
                            onClick={() => deleteScreener(screenerId)}
                        >
                            Delete
                        </button>
                    </div>
                );
            },
        },
    ], [deleteScreener, deletingId, runNow, runningId]);

    return (
        <DataTableCard
            title="My screens"
            columns={columns}
            data={screeners}
            storageKey="screeners-v2"
            loading={loading}
            emptyMessage="No screeners yet. Create one to filter stocks by EMA, RSI, Bollinger, and more."
            defaultColumnOrder={['name', 'description', 'scope', 'max_lookback', 'schedule', 'last_run_at', 'actions']}
            initialSorting={[{ id: 'name', desc: false }]}
            headerExtra={(
                <Link to="/screeners/new" className="btn btn-sm btn-primary">
                    New screener
                </Link>
            )}
        />
    );
}
