import React, { useMemo } from 'react';
import { AllCommunityModule, ModuleRegistry, themeQuartz, type ColDef } from 'ag-grid-community';
import { AgGridReact } from 'ag-grid-react';

ModuleRegistry.registerModules([AllCommunityModule]);

export type EvaluationHistoryRow = {
    id: number;
    rank?: number | null;
    symbol?: string | null;
    score?: number | string | null;
    confidence?: number | string | null;
    explanation?: string | null;
};

type Props = { rows: EvaluationHistoryRow[] };

export default function EvaluationHistoryGrid({ rows }: Props) {
    const columns = useMemo<ColDef<EvaluationHistoryRow>[]>(() => [
        { field: 'rank', headerName: 'Rank', width: 85, sort: 'asc' },
        { field: 'symbol', headerName: 'Symbol', width: 120 },
        { field: 'score', headerName: 'Score', width: 105, valueFormatter: ({ value }) => value == null ? '—' : Number(value).toFixed(1) },
        { field: 'confidence', headerName: 'Confidence', width: 125, valueFormatter: ({ value }) => value == null ? '—' : `${Math.round(Number(value) * 100)}%` },
        { field: 'explanation', headerName: 'Explanation', flex: 1, minWidth: 260, wrapText: true, autoHeight: true },
    ], []);

    return (
        <AgGridReact<EvaluationHistoryRow>
            theme={themeQuartz}
            rowData={rows}
            columnDefs={columns}
            domLayout="autoHeight"
            getRowId={({ data }) => String(data.id)}
            suppressCellFocus
        />
    );
}
