import React from 'react';
import { formatTableInteger, formatTableMoney2, percentChangeColorClass } from './tableFormat';
import { formatTransactionDateDisplay } from './transactionDate';

function sellOnlyMoneyCell(getValue, row) {
    if (row.original.type !== 'sell') {
        return <span className="text-muted">—</span>;
    }

    const value = getValue();
    if (value == null || value === '') {
        return <span className="text-muted">—</span>;
    }

    return (
        <span className={percentChangeColorClass(value)}>
            {formatTableMoney2(value)}
        </span>
    );
}

export function buildTransactionTableColumns({ onEdit, onDelete, showRealization = false }) {
    const columns = [
        {
            accessorKey: 'transaction_date',
            header: 'Date',
            cell: ({ getValue }) => formatTransactionDateDisplay(getValue()),
        },
        {
            id: 'stock',
            header: 'Stock',
            accessorFn: (row) => row.stock?.symbol,
        },
        { accessorKey: 'type', header: 'Type' },
        {
            accessorKey: 'quantity',
            header: 'Qty',
            cell: ({ getValue }) => formatTableInteger(getValue()),
        },
        {
            accessorKey: 'price',
            header: 'Price',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
        },
    ];

    if (showRealization) {
        columns.push(
            {
                accessorKey: 'realized_pl',
                header: 'Realized P/L',
                meta: { columnMenuLabel: 'Realized profit/loss (FIFO, sells only)' },
                cell: ({ row, getValue }) => sellOnlyMoneyCell(getValue, row),
            },
            {
                accessorKey: 'squared_off_fees',
                header: 'Fees',
                meta: { columnMenuLabel: 'Squared-off fees (sell + matched buys)' },
                cell: ({ row, getValue }) => {
                    if (row.original.type !== 'sell') {
                        return <span className="text-muted">—</span>;
                    }
                    const value = getValue();
                    if (value == null || value === '') {
                        return <span className="text-muted">—</span>;
                    }
                    return formatTableMoney2(value);
                },
            },
        );
    }

    columns.push(
        {
            accessorKey: 'notes',
            header: 'Notes',
            cell: ({ getValue }) => {
                const value = String(getValue() ?? '').trim();
                if (!value) {
                    return <span className="text-muted">—</span>;
                }
                return (
                    <span className="lido-table-notes" title={value}>
                        {value}
                    </span>
                );
            },
        },
        {
            id: 'actions',
            header: 'Actions',
            enableSorting: false,
            enableHiding: false,
            cell: ({ row }) => (
                <>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary me-2"
                        onClick={() => onEdit(row.original)}
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-danger"
                        onClick={() => onDelete(row.original.id)}
                    >
                        Delete
                    </button>
                </>
            ),
        },
    );

    return columns;
}
