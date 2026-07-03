const PREFIX = 'portfolio_datatable_';

function sanitizeColumnSizing(columnSizing, columnIds) {
    if (!columnSizing || typeof columnSizing !== 'object') {
        return {};
    }

    const allowed = new Set(columnIds);
    const next = {};

    Object.entries(columnSizing).forEach(([columnId, width]) => {
        if (!allowed.has(columnId)) {
            return;
        }
        const numeric = Number(width);
        if (Number.isFinite(numeric) && numeric > 0) {
            next[columnId] = numeric;
        }
    });

    return next;
}

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
        const columnSizing = sanitizeColumnSizing(parsed.columnSizing, columnIds);

        return { columnOrder: order, columnVisibility: visibility, columnSizing };
    } catch {
        return null;
    }
}

export function saveTableColumnPrefs(storageKey, columnOrder, columnVisibility, columnSizing = {}) {
    if (!storageKey) {
        return;
    }
    try {
        localStorage.setItem(`${PREFIX}${storageKey}`, JSON.stringify({
            columnOrder,
            columnVisibility,
            columnSizing,
        }));
    } catch {
        // Quota or private mode — ignore.
    }
}

export function buildDefaultColumnOrder(columns) {
    return columns.map((col, index) => col.id || col.accessorKey || `col_${index}`);
}
