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

const DEFAULT_COLUMN_SIZE = 140;
const DEFAULT_COLUMN_MIN = 56;
const DEFAULT_COLUMN_MAX = 720;

/**
 * Scale visible column widths proportionally to fill a target table width.
 *
 * @param {Array<{ id: string, getSize?: () => number, columnDef?: { size?: number, minSize?: number, maxSize?: number } }>} columns
 * @param {number} targetWidth
 * @returns {Record<string, number>}
 */
export function distributeColumnWidths(columns, targetWidth) {
    if (!Array.isArray(columns) || columns.length === 0) {
        return {};
    }

    const numericTarget = Number(targetWidth);
    if (!Number.isFinite(numericTarget) || numericTarget <= 0) {
        return {};
    }

    const entries = columns.map((column) => {
        const min = column.columnDef?.minSize ?? DEFAULT_COLUMN_MIN;
        const max = column.columnDef?.maxSize ?? DEFAULT_COLUMN_MAX;
        const current = Number(typeof column.getSize === 'function' ? column.getSize() : column.columnDef?.size);
        const weight = Number.isFinite(current) && current > 0 ? current : DEFAULT_COLUMN_SIZE;

        return {
            id: column.id,
            weight,
            min,
            max,
            width: min,
        };
    });

    const minTotal = entries.reduce((sum, entry) => sum + entry.min, 0);
    const effectiveTarget = Math.max(numericTarget, minTotal);
    const weightTotal = entries.reduce((sum, entry) => sum + entry.weight, 0);

    entries.forEach((entry) => {
        const scaled = weightTotal > 0
            ? Math.round((entry.weight / weightTotal) * effectiveTarget)
            : Math.round(effectiveTarget / entries.length);
        entry.width = Math.min(entry.max, Math.max(entry.min, scaled));
    });

    let remainder = effectiveTarget - entries.reduce((sum, entry) => sum + entry.width, 0);
    const flexibleOrder = [...entries.keys()].sort(
        (left, right) => entries[right].weight - entries[left].weight,
    );

    let guard = 0;
    while (remainder !== 0 && guard < 10000) {
        guard += 1;
        let adjusted = false;

        for (const index of flexibleOrder) {
            if (remainder === 0) {
                break;
            }

            const entry = entries[index];
            const step = remainder > 0 ? 1 : -1;
            const next = entry.width + step;

            if (next >= entry.min && next <= entry.max) {
                entry.width = next;
                remainder -= step;
                adjusted = true;
            }
        }

        if (!adjusted) {
            break;
        }
    }

    return Object.fromEntries(entries.map((entry) => [entry.id, entry.width]));
}
