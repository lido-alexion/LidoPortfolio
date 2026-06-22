import React from 'react';
import { formatTableInteger, formatTableMoney2 } from './tableFormat';
import { formatTransactionDateDisplay } from './transactionDate';

export function buildTransactionTableColumns({ onEdit, onDelete }) {
    return [
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
    ];
}
