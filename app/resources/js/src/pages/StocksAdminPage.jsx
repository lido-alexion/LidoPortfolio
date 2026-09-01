import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { DataTableCard } from '../components/DataTable';
import TablePagination from '../components/TablePagination';
import { showToast } from '../toast';

const PER_PAGE = 25;

const STATUS_OPTIONS = [
    { value: 'all', label: 'All stocks' },
    { value: 'active', label: 'Effectively active' },
    { value: 'inactive', label: 'System inactive' },
    { value: 'admin_deactivated', label: 'Admin deactivated' },
];

function boolBadge(value, trueLabel, falseLabel, trueClass = 'bg-success', falseClass = 'bg-secondary') {
    return (
        <span className={`badge ${value ? trueClass : falseClass}`}>
            {value ? trueLabel : falseLabel}
        </span>
    );
}

export default function StocksAdminPage() {
    const [stocks, setStocks] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState('all');
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(true);
    const [actionStockId, setActionStockId] = useState(null);

    const load = useCallback(async (pageNum, searchTerm, statusFilter) => {
        setLoading(true);
        try {
            const res = await api.get('/admin/stocks', {
                params: {
                    page: pageNum,
                    per_page: PER_PAGE,
                    q: searchTerm || undefined,
                    status: statusFilter,
                },
            });
            setStocks(res.data.data || []);
            setPagination({
                current_page: res.data.current_page,
                last_page: res.data.last_page,
                from: res.data.from,
                to: res.data.to,
                total: res.data.total,
            });
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(page, search, status);
    }, [load, page, search, status]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    const handleActivate = useCallback(async (stock) => {
        setActionStockId(stock.id);
        try {
            await api.post(`/stocks/${stock.id}/activate`);
            showToast(`${stock.symbol} activated`, 'success');
            await load(page, search, status);
        } catch (error) {
            showToast(error?.response?.data?.message || 'Activation failed', 'danger');
        } finally {
            setActionStockId(null);
        }
    }, [load, page, search, status]);

    const handleDeactivate = useCallback(async (stock) => {
        if (!window.confirm(`Deactivate ${stock.symbol} (${stock.exchange}) for new activity? System feed state is unchanged.`)) {
            return;
        }
        setActionStockId(stock.id);
        try {
            await api.post(`/stocks/${stock.id}/deactivate`);
            showToast(`${stock.symbol} deactivated`, 'success');
            await load(page, search, status);
        } catch (error) {
            showToast(error?.response?.data?.message || 'Deactivation failed', 'danger');
        } finally {
            setActionStockId(null);
        }
    }, [load, page, search, status]);

    const columns = useMemo(() => [
        {
            id: 'symbol',
            header: 'Symbol',
            accessorKey: 'symbol',
            cell: ({ row }) => (
                <span className="fw-semibold">{row.original.symbol}</span>
            ),
        },
        {
            id: 'name',
            header: 'Name',
            accessorKey: 'name',
        },
        {
            id: 'exchange',
            header: 'Exchange',
            accessorKey: 'exchange_label',
            cell: ({ row }) => row.original.exchange_label || row.original.exchange,
        },
        {
            id: 'sector',
            header: 'Sector',
            accessorKey: 'sector',
            cell: ({ row }) => row.original.sector || '—',
        },
        {
            id: 'is_active',
            header: 'System',
            cell: ({ row }) => boolBadge(
                row.original.is_active,
                'In feed',
                'Not in feed',
                'bg-primary',
                'bg-secondary',
            ),
        },
        {
            id: 'admin_deactivated',
            header: 'Admin override',
            cell: ({ row }) => boolBadge(
                row.original.admin_deactivated,
                'Deactivated',
                'None',
                'bg-warning text-dark',
                'bg-light text-dark border',
            ),
        },
        {
            id: 'effective_active',
            header: 'Effective',
            cell: ({ row }) => boolBadge(
                row.original.effective_active,
                'Available',
                'Unavailable',
            ),
        },
        {
            id: 'actions',
            header: 'Actions',
            cell: ({ row }) => {
                const stock = row.original;
                const busy = actionStockId === stock.id;
                return (
                    <div className="d-flex gap-1">
                        {stock.admin_deactivated ? (
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-success"
                                disabled={busy}
                                onClick={() => handleActivate(stock)}
                            >
                                Activate
                            </button>
                        ) : (
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-warning"
                                disabled={busy}
                                onClick={() => handleDeactivate(stock)}
                            >
                                Deactivate
                            </button>
                        )}
                    </div>
                );
            },
        },
    ], [actionStockId, handleActivate, handleDeactivate]);

    const emptyMessage = search
        ? 'No stocks match this search.'
        : 'No stocks found for the selected filter.';

    return (
        <div className="row g-3" data-testid="stocks-admin-page">
            <div className="col-12">
                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <h1 className="h4 mb-1">Stocks catalogue</h1>
                        <p className="text-muted small mb-0">
                            Manage admin availability overrides for the existing stock master.
                            System feed state (<code>is_active</code>) is maintained by stock-master sync.
                        </p>
                    </div>
                    <Link to="/settings/global" className="btn btn-sm btn-outline-secondary">
                        Back to Settings
                    </Link>
                </div>
            </div>
            <div className="col-12">
                <DataTableCard
                    title="Stocks"
                    columns={columns}
                    data={stocks}
                    storageKey="stocks-admin"
                    loading={loading}
                    emptyMessage={emptyMessage}
                    headerExtra={(
                        <div className="d-flex flex-wrap align-items-center gap-2">
                            <input
                                type="search"
                                className="form-control form-control-sm lido-table-search"
                                placeholder="Search symbol or name"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                aria-label="Search stocks by symbol or name"
                                data-testid="stocks-admin-search"
                            />
                            <select
                                className="form-select form-select-sm"
                                value={status}
                                onChange={(event) => {
                                    setStatus(event.target.value);
                                    setPage(1);
                                }}
                                aria-label="Filter stocks by status"
                                data-testid="stocks-admin-status-filter"
                            >
                                {STATUS_OPTIONS.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                />
                <TablePagination meta={pagination} onPageChange={setPage} />
            </div>
        </div>
    );
}
