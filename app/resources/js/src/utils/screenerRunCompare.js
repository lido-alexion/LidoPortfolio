/**
 * Consecutive green-cell streak marks for screener run compare matrix.
 * Presence is left→right; grey (false) resets the counter.
 *
 * @param {boolean[]} presence
 * @returns {(number|null)[]}
 */
export function streakMarksForPresence(presence) {
    let streak = 0;
    return (presence || []).map((hit) => {
        if (!hit) {
            streak = 0;
            return null;
        }
        streak += 1;
        return streak;
    });
}

/**
 * @param {Array<{ symbol: string, presence: boolean[] }>} rows
 * @returns {Array<{ symbol: string, presence: boolean[], count: number, streaks: (number|null)[] }>}
 */
export function enrichCompareRows(rows) {
    return (rows || []).map((row) => {
        const presence = Array.isArray(row.presence) ? row.presence : [];
        const count = presence.filter(Boolean).length;
        return {
            ...row,
            count,
            streaks: streakMarksForPresence(presence),
        };
    });
}
