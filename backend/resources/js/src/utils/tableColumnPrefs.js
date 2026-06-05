const PREFIX = 'portfolio_datatable_';

export function loadTableColumnPrefs(storageKey, columnIds) {
    if (!storageKey) {
        return null;
    }
    try {
        const raw = localStorage.getItem(`${PREFIX}${storageKey}`);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }
        const order = Array.isArray(parsed.columnOrder)
            ? parsed.columnOrder.filter((id) => columnIds.includes(id))
            : null;
        const visibility = parsed.columnVisibility && typeof parsed.columnVisibility === 'object'
            ? parsed.columnVisibility
            : null;

        return { columnOrder: order, columnVisibility: visibility };
    } catch {
        return null;
    }
}

export function saveTableColumnPrefs(storageKey, columnOrder, columnVisibility) {
    if (!storageKey) {
        return;
    }
    try {
        localStorage.setItem(`${PREFIX}${storageKey}`, JSON.stringify({
            columnOrder,
            columnVisibility,
        }));
    } catch {
        // Quota or private mode — ignore.
    }
}

export function buildDefaultColumnOrder(columns) {
    return columns.map((col, index) => col.id || col.accessorKey || `col_${index}`);
}
