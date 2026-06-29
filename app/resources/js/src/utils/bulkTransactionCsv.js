/**
 * Parse bulk transaction CSV pasted by the user.
 * Expected columns: Stock, Quantity, Average Price, Transaction Type
 */

const HEADER_HINTS = ['stock', 'quantity', 'price', 'type'];

function normalizeHeaderCell(cell) {
    return String(cell || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function looksLikeHeaderLine(cells) {
    const joined = cells.map(normalizeHeaderCell).join(' ');
    return HEADER_HINTS.every((hint) => joined.includes(hint));
}

export function normalizeBulkTransactionType(raw) {
    const value = String(raw || '').trim().toLowerCase();
    if (value === 'buy' || value === 'b') {
        return 'buy';
    }
    if (value === 'sell' || value === 's') {
        return 'sell';
    }
    return null;
}

function parsePositiveInteger(raw) {
    const num = Number(String(raw || '').trim());
    if (!Number.isInteger(num) || num < 1) {
        return null;
    }
    return num;
}

function parsePositivePrice(raw) {
    const num = Number(String(raw || '').trim());
    if (Number.isNaN(num) || num <= 0) {
        return null;
    }
    const rounded = Math.round(num * 100) / 100;
    if (Math.abs(rounded - num) > 0.000001) {
        return null;
    }
    return rounded;
}

function splitCsvLine(line) {
    return line.split(',').map((part) => part.trim());
}

function createRowId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `bulk-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
}

/**
 * @param {string} text
 * @returns {{ rows: Array<object>, errors: string[] }}
 */
export function parseBulkTransactionCsv(text) {
    const lines = String(text || '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line.length > 0);

    if (lines.length === 0) {
        return { rows: [], errors: ['Paste at least one data row.'] };
    }

    let startIndex = 0;
    const firstCells = splitCsvLine(lines[0]);
    if (looksLikeHeaderLine(firstCells)) {
        startIndex = 1;
    }

    if (startIndex >= lines.length) {
        return { rows: [], errors: ['CSV has a header row but no data rows.'] };
    }

    const rows = [];
    const errors = [];

    for (let i = startIndex; i < lines.length; i += 1) {
        const lineNumber = i + 1;
        const cells = splitCsvLine(lines[i]);
        if (cells.length < 4) {
            errors.push(`Line ${lineNumber}: expected Stock, Quantity, Average Price, and Transaction Type.`);
            continue;
        }

        const symbol = String(cells[0] || '').trim().toUpperCase();
        const quantity = parsePositiveInteger(cells[1]);
        const price = parsePositivePrice(cells[2]);
        const type = normalizeBulkTransactionType(cells[3]);

        if (!symbol) {
            errors.push(`Line ${lineNumber}: stock symbol is required.`);
            continue;
        }
        if (quantity === null) {
            errors.push(`Line ${lineNumber}: quantity must be a positive whole number.`);
            continue;
        }
        if (price === null) {
            errors.push(`Line ${lineNumber}: average price must be a positive number with up to 2 decimals.`);
            continue;
        }
        if (!type) {
            errors.push(`Line ${lineNumber}: transaction type must be BUY or SELL.`);
            continue;
        }

        rows.push({
            id: createRowId(),
            symbol,
            quantity,
            price,
            type,
        });
    }

    if (rows.length === 0 && errors.length === 0) {
        errors.push('No valid rows found.');
    }

    return { rows, errors };
}
