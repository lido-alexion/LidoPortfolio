/**
 * Client-side Strategy page validation for §29 / §3.5 keys stored in portfolio_rules.
 * Engine behavior is unchanged: unset horizon never fires; invalid first_entry_pct falls back to 50.
 */

export function validateHorizonCalendarDays(value) {
    if (value === '' || value === null || value === undefined) {
        return { ok: true, persist: null };
    }
    const n = Number(value);
    if (!Number.isInteger(n) || n < 1) {
        return {
            ok: false,
            message: 'Horizon (calendar days) must be a positive whole number, or left empty for no horizon expiry.',
        };
    }
    return { ok: true, persist: n };
}

export function validateFirstEntryPct(value) {
    if (value === '' || value === null || value === undefined) {
        return { ok: true, persist: null };
    }
    const n = Number(value);
    if (!Number.isFinite(n) || n < 1 || n > 100) {
        return {
            ok: false,
            message: 'First entry % must be between 1 and 100, or left empty to use the engine default (50%).',
        };
    }
    return { ok: true, persist: n };
}

export function validateMaxHoldings(value) {
    if (value === '' || value === null || value === undefined) {
        return { ok: true, persist: null };
    }
    const n = Number(value);
    if (!Number.isInteger(n) || n < 1) {
        return {
            ok: false,
            message: 'Hard maximum holdings must be a positive whole number (≥ 1), or left empty.',
        };
    }
    return { ok: true, persist: n };
}

/** OD-16 — top-level config.weakest_position_window_days (WeakestPositionRanker). Empty → engine default 90. */
export function validateWeakestPositionWindowDays(value) {
    if (value === '' || value === null || value === undefined) {
        return { ok: true, persist: null };
    }
    const n = Number(value);
    if (!Number.isInteger(n) || n < 1) {
        return {
            ok: false,
            message: 'Weakest-position evaluation window must be a positive whole number of calendar days (≥ 1), or left empty for the engine default (90).',
        };
    }
    return { ok: true, persist: n };
}
