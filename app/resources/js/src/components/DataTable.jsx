import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    flexRender,
    getCoreRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import {
    buildDefaultColumnOrder,
    distributeColumnWidths,
    loadTableColumnPrefs,
    saveTableColumnPrefs,
} from '../utils/tableColumnPrefs';

function ColumnGripIcon() {
    return (
        <svg viewBox="0 0 10 16" width="10" height="14" aria-hidden="true" focusable="false">
            <circle cx="3" cy="2.5" r="1.25" fill="currentColor" />
            <circle cx="3" cy="8" r="1.25" fill="currentColor" />
            <circle cx="3" cy="13.5" r="1.25" fill="currentColor" />
            <circle cx="7" cy="2.5" r="1.25" fill="currentColor" />
            <circle cx="7" cy="8" r="1.25" fill="currentColor" />
            <circle cx="7" cy="13.5" r="1.25" fill="currentColor" />
        </svg>
    );
}

function ColumnsMenuIcon() {
    return (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
            <rect x="3" y="5" width="5" height="14" rx="1" fill="currentColor" />
            <rect x="10" y="5" width="5" height="14" rx="1" fill="currentColor" opacity="0.75" />
            <rect x="17" y="5" width="4" height="14" rx="1" fill="currentColor" opacity="0.5" />
        </svg>
    );
}

function FitColumnsIcon() {
    return (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
            <path
                d="M4 12h5M4 12l2.5-2.5M4 12l2.5 2.5M20 12h-5M20 12l-2.5-2.5M20 12l-2.5 2.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function ChevronUpIcon() {
    return (
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
            <path
                d="M7.5 14.5L12 10l4.5 4.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function ChevronDownIcon() {
    return (
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
            <path
                d="M7.5 9.5L12 14l4.5-4.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function columnLabel(column) {
    if (column.columnDef.meta?.columnMenuLabel) {
        return column.columnDef.meta.columnMenuLabel;
    }
    return typeof column.columnDef.header === 'string'
        ? column.columnDef.header
        : column.id;
}

function useDataTableController({
    columns,
    data,
    enableSorting = true,
    enableColumnControls = true,
    enableColumnHiding = true,
    enableColumnReorder = true,
    enableColumnResizing = true,
    storageKey = null,
    defaultColumnOrder: defaultColumnOrderOverride = null,
    defaultColumnVisibility: defaultColumnVisibilityOverride = null,
    initialSorting = [],
    tableClassName = 'table table-sm mb-0 datatable-table',
    striped = false,
}) {
    const defaultColumnOrder = useMemo(() => {
        const fromColumns = buildDefaultColumnOrder(columns);
        if (!defaultColumnOrderOverride?.length) {
            return fromColumns;
        }
        const preferred = defaultColumnOrderOverride.filter((id) => fromColumns.includes(id));
        const missing = fromColumns.filter((id) => !preferred.includes(id));
        return [...preferred, ...missing];
    }, [columns, defaultColumnOrderOverride]);

    const defaultColumnVisibility = useMemo(
        () => defaultColumnVisibilityOverride ?? {},
        [defaultColumnVisibilityOverride],
    );

    const savedPrefs = useMemo(
        () => loadTableColumnPrefs(storageKey, defaultColumnOrder),
        [storageKey, defaultColumnOrder],
    );

    const [columnVisibility, setColumnVisibility] = useState(
        () => savedPrefs?.columnVisibility ?? defaultColumnVisibility,
    );
    const [columnOrder, setColumnOrder] = useState(() => {
        const saved = savedPrefs?.columnOrder;
        if (saved?.length) {
            const missing = defaultColumnOrder.filter((id) => !saved.includes(id));
            return [...saved, ...missing];
        }
        return defaultColumnOrder;
    });
    const [columnSizing, setColumnSizing] = useState(
        () => savedPrefs?.columnSizing ?? {},
    );
    const [dragColumnId, setDragColumnId] = useState(null);
    const [panelOpen, setPanelOpen] = useState(false);
    const tableContainerRef = useRef(null);

    useEffect(() => {
        setColumnOrder((prev) => {
            const missing = defaultColumnOrder.filter((id) => !prev.includes(id));
            if (missing.length === 0) {
                return prev;
            }
            return [...prev, ...missing];
        });
    }, [defaultColumnOrder]);

    useEffect(() => {
        if (storageKey) {
            saveTableColumnPrefs(storageKey, columnOrder, columnVisibility, columnSizing);
        }
    }, [storageKey, columnOrder, columnVisibility, columnSizing]);

    const table = useReactTable({
        data,
        columns,
        defaultColumn: {
            size: 140,
            minSize: 56,
            maxSize: 720,
        },
        enableColumnResizing,
        columnResizeMode: 'onChange',
        state: {
            columnVisibility,
            columnOrder,
            columnSizing,
        },
        initialState: {
            sorting: initialSorting,
        },
        onColumnVisibilityChange: setColumnVisibility,
        onColumnOrderChange: setColumnOrder,
        onColumnSizingChange: setColumnSizing,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: enableSorting ? getSortedRowModel() : undefined,
    });

    const showControls = enableColumnControls && (enableColumnHiding || enableColumnReorder);

    const orderedColumns = useMemo(() => {
        const byId = Object.fromEntries(
            table.getAllLeafColumns().map((column) => [column.id, column]),
        );
        return columnOrder.map((id) => byId[id]).filter(Boolean);
    }, [table, columnOrder]);

    const resetColumns = useCallback(() => {
        setColumnVisibility(defaultColumnVisibility);
        setColumnOrder(defaultColumnOrder);
        setColumnSizing({});
        table.resetColumnSizing(true);
        if (storageKey) {
            localStorage.removeItem(`portfolio_datatable_${storageKey}`);
        }
    }, [defaultColumnOrder, defaultColumnVisibility, storageKey, table]);

    const fitColumnsToWidth = useCallback(() => {
        if (!enableColumnResizing) {
            return;
        }

        const container = tableContainerRef.current;
        if (!container) {
            return;
        }

        const visibleColumns = table.getVisibleLeafColumns();
        const nextSizing = distributeColumnWidths(visibleColumns, container.clientWidth);
        if (Object.keys(nextSizing).length === 0) {
            return;
        }

        setColumnSizing(nextSizing);
    }, [enableColumnResizing, table]);

    const moveColumn = useCallback((columnId, direction) => {
        setColumnOrder((old) => {
            const next = [...old];
            const index = next.indexOf(columnId);
            if (index < 0) {
                return old;
            }
            const swapWith = direction === 'up' ? index - 1 : index + 1;
            if (swapWith < 0 || swapWith >= next.length) {
                return old;
            }
            [next[index], next[swapWith]] = [next[swapWith], next[index]];
            return next;
        });
    }, []);

    const handleDragStart = useCallback((columnId) => {
        setDragColumnId(columnId);
    }, []);

    const handleDragOver = useCallback((event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }, []);

    const handleDrop = useCallback((targetColumnId) => {
        if (!dragColumnId || dragColumnId === targetColumnId) {
            setDragColumnId(null);
            return;
        }
        setColumnOrder((old) => {
            const next = [...old];
            const from = next.indexOf(dragColumnId);
            const to = next.indexOf(targetColumnId);
            if (from < 0 || to < 0) {
                return old;
            }
            next.splice(from, 1);
            next.splice(to, 0, dragColumnId);
            return next;
        });
        setDragColumnId(null);
    }, [dragColumnId]);

    const tableClasses = [tableClassName, striped ? 'table-striped' : ''].filter(Boolean).join(' ');
    const visibleColumnCount = table.getVisibleLeafColumns().length || columns.length;

    return {
        table,
        storageKey,
        columnOrder,
        dragColumnId,
        panelOpen,
        setPanelOpen,
        setDragColumnId,
        showControls,
        enableSorting,
        enableColumnHiding,
        enableColumnReorder,
        enableColumnResizing,
        orderedColumns,
        resetColumns,
        fitColumnsToWidth,
        tableContainerRef,
        moveColumn,
        handleDragStart,
        handleDragOver,
        handleDrop,
        tableClasses,
        visibleColumnCount,
    };
}

export function DataTableColumnMenu({ controller }) {
    const {
        storageKey,
        columnOrder,
        dragColumnId,
        panelOpen,
        setPanelOpen,
        setDragColumnId,
        showControls,
        enableColumnHiding,
        enableColumnReorder,
        enableColumnResizing,
        orderedColumns,
        resetColumns,
        fitColumnsToWidth,
        moveColumn,
        handleDragStart,
        handleDragOver,
        handleDrop,
    } = controller;

    if (!showControls && !enableColumnResizing) {
        return null;
    }

    return (
        <div className="d-flex align-items-center gap-1 datatable-toolbar">
            {enableColumnResizing ? (
                <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary datatable-col-menu-btn"
                    onClick={fitColumnsToWidth}
                    aria-label="Fit columns to table width"
                    title="Fit columns to table width"
                >
                    <FitColumnsIcon />
                </button>
            ) : null}
            {showControls ? (
        <div className="dropdown datatable-col-menu">
            <button
                type="button"
                className="btn btn-sm btn-outline-secondary datatable-col-menu-btn"
                onClick={() => setPanelOpen((v) => !v)}
                aria-expanded={panelOpen}
                aria-label="Columns"
                title="Columns"
            >
                <ColumnsMenuIcon />
            </button>
            {panelOpen && (
                <>
                    <div
                        className="position-fixed top-0 start-0 w-100 h-100"
                        style={{ zIndex: 1040 }}
                        onClick={() => setPanelOpen(false)}
                        aria-hidden="true"
                    />
                    <div
                        className="dropdown-menu datatable-col-panel show dropdown-menu-end p-3 shadow"
                        style={{ minWidth: 280, zIndex: 1050 }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="small text-muted mb-2">
                            {enableColumnHiding && enableColumnReorder && (
                                'Show or hide columns. Drag the grip to reorder. Drag a column edge to resize.'
                            )}
                            {enableColumnHiding && !enableColumnReorder && 'Show or hide columns.'}
                            {!enableColumnHiding && enableColumnReorder && (
                                'Drag the grip to reorder columns (arrows on small screens).'
                            )}
                        </div>
                        <div className="datatable-col-list">
                            {orderedColumns.map((column) => {
                                const label = columnLabel(column);
                                const orderIndex = columnOrder.indexOf(column.id);
                                const canHide = enableColumnHiding && column.getCanHide();
                                const isAlwaysVisible = enableColumnHiding && !column.getCanHide();
                                const checkboxId = `col-vis-${storageKey || 'table'}-${column.id}`;
                                const isDragging = dragColumnId === column.id;

                                return (
                                    <div
                                        key={column.id}
                                        className={`datatable-col-row${isDragging ? ' is-dragging' : ''}`}
                                        onDragOver={enableColumnReorder ? handleDragOver : undefined}
                                        onDrop={enableColumnReorder
                                            ? () => handleDrop(column.id)
                                            : undefined}
                                    >
                                        {enableColumnReorder && (
                                            <button
                                                type="button"
                                                className="datatable-col-grip datatable-col-grip--desktop"
                                                draggable
                                                title="Drag to reorder"
                                                aria-label={`Drag to reorder ${label}`}
                                                onDragStart={(event) => {
                                                    event.dataTransfer.effectAllowed = 'move';
                                                    handleDragStart(column.id);
                                                }}
                                                onDragEnd={() => setDragColumnId(null)}
                                            >
                                                <ColumnGripIcon />
                                            </button>
                                        )}
                                        {canHide && (
                                            <input
                                                className="form-check-input datatable-col-check"
                                                type="checkbox"
                                                id={checkboxId}
                                                checked={column.getIsVisible()}
                                                onChange={column.getToggleVisibilityHandler()}
                                            />
                                        )}
                                        {isAlwaysVisible && (
                                            <input
                                                className="form-check-input datatable-col-check"
                                                type="checkbox"
                                                id={checkboxId}
                                                checked
                                                disabled
                                                title="Always visible"
                                                aria-label={`${label} (always visible)`}
                                            />
                                        )}
                                        {!canHide && !isAlwaysVisible && (
                                            <span className="datatable-col-check-spacer" aria-hidden="true" />
                                        )}
                                        <label
                                            className={`datatable-col-label${isAlwaysVisible ? ' datatable-col-label--locked' : ''}`}
                                            htmlFor={canHide ? checkboxId : undefined}
                                        >
                                            {label}
                                        </label>
                                        {enableColumnReorder && (
                                            <span className="datatable-col-mobile-order">
                                                <button
                                                    type="button"
                                                    className="datatable-col-move-btn"
                                                    title="Move up"
                                                    aria-label={`Move ${label} up`}
                                                    disabled={orderIndex <= 0}
                                                    onClick={() => moveColumn(column.id, 'up')}
                                                >
                                                    <ChevronUpIcon />
                                                </button>
                                                <button
                                                    type="button"
                                                    className="datatable-col-move-btn"
                                                    title="Move down"
                                                    aria-label={`Move ${label} down`}
                                                    disabled={orderIndex >= columnOrder.length - 1}
                                                    onClick={() => moveColumn(column.id, 'down')}
                                                >
                                                    <ChevronDownIcon />
                                                </button>
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                        <button
                            type="button"
                            className="btn btn-sm btn-link mt-2 p-0"
                            onClick={resetColumns}
                        >
                            Reset columns
                        </button>
                    </div>
                </>
            )}
        </div>
            ) : null}
        </div>
    );
}

export function TableLoadingRow({ colSpan, label = 'Loading…' }) {
    return (
        <tr className="datatable-loading-row">
            <td colSpan={colSpan} className="text-center py-4">
                <div className="d-inline-flex align-items-center gap-2 text-muted">
                    <span
                        className="spinner-border spinner-border-sm"
                        role="status"
                        aria-hidden="true"
                    />
                    <span>{label}</span>
                </div>
            </td>
        </tr>
    );
}

export function DataTableView({ controller, emptyMessage = 'No data.', loading = false }) {
    const {
        table,
        enableSorting,
        enableColumnResizing,
        tableClasses,
        visibleColumnCount,
        tableContainerRef,
    } = controller;

    const isResizingColumn = Boolean(table.getState().columnSizingInfo?.isResizingColumn);

    return (
        <div
            ref={tableContainerRef}
            className={`table-responsive${isResizingColumn ? ' datatable-is-resizing' : ''}`}
        >
            <table
                className={`${tableClasses}${enableColumnResizing ? ' datatable-resizable' : ''}`.trim()}
                style={enableColumnResizing ? { width: table.getCenterTotalSize() } : undefined}
            >
                <thead>
                {table.getHeaderGroups().map((headerGroup) => (
                    <tr key={headerGroup.id}>
                        {headerGroup.headers.map((header) => {
                            const canSort = enableSorting && header.column.getCanSort();
                            const canResize = enableColumnResizing && header.column.getCanResize();
                            const headerSize = header.getSize();
                            return (
                                <th
                                    key={header.id}
                                    onClick={canSort ? header.column.getToggleSortingHandler() : undefined}
                                    style={{
                                        width: headerSize,
                                        minWidth: headerSize,
                                        maxWidth: headerSize,
                                        cursor: canSort ? 'pointer' : undefined,
                                        userSelect: canSort ? 'none' : undefined,
                                    }}
                                >
                                    <div className="datatable-th-inner">
                                        <div className="datatable-th-label">
                                            {flexRender(header.column.columnDef.header, header.getContext())}
                                        </div>
                                        {canSort && (
                                            <span className="datatable-th-sort text-muted" aria-hidden="true">
                                                {{
                                                    asc: '▲',
                                                    desc: '▼',
                                                }[header.column.getIsSorted()] ?? '⇅'}
                                            </span>
                                        )}
                                    </div>
                                    {canResize ? (
                                        <div
                                            role="separator"
                                            aria-orientation="vertical"
                                            aria-label={`Resize ${columnLabel(header.column)} column`}
                                            className={[
                                                'datatable-col-resizer',
                                                header.column.getIsResizing() ? 'is-active' : '',
                                            ].filter(Boolean).join(' ')}
                                            onMouseDown={header.getResizeHandler()}
                                            onTouchStart={header.getResizeHandler()}
                                            onClick={(event) => event.stopPropagation()}
                                            onDoubleClick={(event) => {
                                                event.stopPropagation();
                                                header.column.resetSize();
                                            }}
                                        />
                                    ) : null}
                                </th>
                            );
                        })}
                    </tr>
                ))}
                </thead>
                <tbody>
                {loading && table.getRowModel().rows.length === 0 ? (
                    <TableLoadingRow colSpan={visibleColumnCount} />
                ) : table.getRowModel().rows.length === 0 ? (
                    <tr>
                        <td colSpan={visibleColumnCount} className="text-muted">
                            {emptyMessage}
                        </td>
                    </tr>
                ) : (
                    table.getRowModel().rows.map((row) => (
                        <tr key={row.id}>
                            {row.getVisibleCells().map((cell) => {
                                const cellSize = cell.column.getSize();
                                return (
                                    <td
                                        key={cell.id}
                                        style={enableColumnResizing ? {
                                            width: cellSize,
                                            minWidth: cellSize,
                                            maxWidth: cellSize,
                                        } : undefined}
                                    >
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </td>
                                );
                            })}
                        </tr>
                    ))
                )}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Card wrapper with column menu icon in the header (right edge).
 */
export function DataTableCard({
    title,
    className = '',
    bodyClassName = '',
    headerExtra = null,
    loading = false,
    emptyMessage,
    ...tableProps
}) {
    const controller = useDataTableController(tableProps);

    return (
        <div className={`card ${className}`.trim()}>
            <div className="card-header d-flex justify-content-between align-items-center gap-2">
                <div className="mb-0">{title}</div>
                <div className="d-flex align-items-center gap-2 ms-auto">
                    {headerExtra}
                    <DataTableColumnMenu controller={controller} />
                </div>
            </div>
            <div className={`card-body ${bodyClassName}`.trim()}>
                <DataTableView
                    controller={controller}
                    emptyMessage={emptyMessage}
                    loading={loading}
                />
            </div>
        </div>
    );
}

/** @deprecated Use DataTableCard for tables inside cards. */
export default function DataTable({ loading = false, emptyMessage, ...tableProps }) {
    const controller = useDataTableController(tableProps);

    return (
        <div>
            <div className="d-flex justify-content-end mb-2">
                <DataTableColumnMenu controller={controller} />
            </div>
            <DataTableView
                controller={controller}
                emptyMessage={emptyMessage}
                loading={loading}
            />
        </div>
    );
}

export { useDataTableController };
