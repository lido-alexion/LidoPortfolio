import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
import { DataTableCard } from '../components/DataTable';
import TablePagination from '../components/TablePagination';
import { showToast } from '../toast';
import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { buildTransactionTableColumns } from '../utils/transactionTableColumns';

const PER_PAGE = 25;

export default function ClosedTransactionsPage() {
    const navigate = useNavigate();
    const [transactions, setTransactions] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [page, setPage] = useState(1);
    const [stockSearch, setStockSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [loading, setLoading] = useState(true);

    const load = useCallback(async (pageNum, search) => {
        setLoading(true);
        try {
            const txRes = await api.get('/transactions', {
                params: {
                    scope: 'closed',
                    page: pageNum,
                    per_page: PER_PAGE,
                    search: search || undefined,
                },
            });
            setTransactions(txRes.data.data || []);
            setPagination({
                current_page: txRes.data.current_page,
                last_page: txRes.data.last_page,
                from: txRes.data.from,
                to: txRes.data.to,
                total: txRes.data.total,
            });
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(page, stockSearch);
    }, [load, page, stockSearch]);

    usePortfolioChanged(() => {
        load(page, stockSearch);
    });

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setStockSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    const handleEdit = useCallback((tx) => {
        navigate('/transactions', { state: { editTransaction: tx } });
    }, [navigate]);

    const handleDelete = useCallback(async (id) => {
        if (!window.confirm('Delete this transaction?')) {
            return;
        }
        await api.delete(`/transactions/${id}`);
        showToast('Transaction deleted');
        await load(page, stockSearch);
        notifyPortfolioDashboardRefresh();
    }, [load, page, stockSearch]);

    const columns = useMemo(
        () => buildTransactionTableColumns({
            onEdit: handleEdit,
            onDelete: handleDelete,
            showRealization: true,
        }),
        [handleEdit, handleDelete],
    );

    const emptyMessage = stockSearch
        ? 'No squared-off transactions match this search.'
        : 'No squared-off transactions yet.';

    return (
        <div className="row g-3">
            <div className="col-12">
                <DataTableCard
                    title="Squared-off transactions"
                    columns={columns}
                    data={transactions}
                    storageKey="transactions-closed"
                    loading={loading}
                    emptyMessage={emptyMessage}
                    headerExtra={(
                        <div className="d-flex align-items-center gap-2">
                            <input
                                type="search"
                                className="form-control form-control-sm lido-table-search"
                                placeholder="Search symbol or name"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                aria-label="Search squared-off transactions by stock symbol or name"
                            />
                            <Link className="btn btn-sm btn-outline-secondary text-nowrap" to="/transactions">
                                Active transactions
                            </Link>
                        </div>
                    )}
                />
                <TablePagination meta={pagination} onPageChange={setPage} />
            </div>
        </div>
    );
}
