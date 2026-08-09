import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../api';
import { DataTableCard } from '../DataTable';
import usePortfolioChanged from '../../hooks/usePortfolioChanged';
import { showToast } from '../../toast';

function validationMessage(error) {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) return first;
    }
    return error?.response?.data?.message || 'Something went wrong.';
}

export default function ScreenerSharedTab() {
    const navigate = useNavigate();
    const [screeners, setScreeners] = useState([]);
    const [loading, setLoading] = useState(true);
    const [importingId, setImportingId] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/screeners/shared', { skipErrorToast: true });
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

    const importScreener = useCallback(async (sourceId) => {
        setImportingId(sourceId);
        try {
            const res = await api.post(`/screeners/shared/${sourceId}/import`);
            const imported = res.data?.data;
            showToast(`Imported "${imported?.name || 'screener'}" to My screens`);
            if (imported?.id) {
                navigate(`/screeners/${imported.id}`);
            }
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setImportingId(null);
        }
    }, [navigate]);

    const columns = useMemo(() => [
        {
            id: 'name',
            header: 'Name',
            accessorKey: 'name',
        },
        {
            id: 'actions',
            header: 'Actions',
            enableSorting: false,
            enableHiding: false,
            minSize: 120,
            size: 140,
            cell: ({ row }) => (
                <button
                    type="button"
                    className="btn btn-sm btn-outline-primary"
                    disabled={importingId === row.original.id}
                    onClick={() => importScreener(row.original.id)}
                >
                    {importingId === row.original.id ? 'Importing…' : 'Import'}
                </button>
            ),
        },
    ], [importingId, importScreener]);

    return (
        <DataTableCard
            title="Shared screens"
            description="Screeners shared from your other portfolios. Import creates a private copy in the active portfolio."
            columns={columns}
            data={screeners}
            loading={loading}
            storageKey="screeners-shared-v2"
            getRowId={(row) => String(row.id)}
            emptyMessage="No shared screeners from your other portfolios yet."
        />
    );
}
