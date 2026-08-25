/**
 * Human-readable labels for V3 §13.2 / §13.5 exit attribution.
 * Canonical backend values are preserved; UI maps them for display only.
 */

export const EXIT_ATTRIBUTION_LABELS = {
    strategy_exit: 'Strategy exit',
    stop_loss: 'Portfolio stop-loss',
    trailing_stop: 'Portfolio trailing stop',
    horizon_expiry: 'Strategy horizon',
};

/**
 * @param {string|null|undefined} reason
 * @returns {string}
 */
export function exitAttributionLabel(reason) {
    if (reason == null || reason === '') {
        return '—';
    }
    const key = String(reason);
    return EXIT_ATTRIBUTION_LABELS[key] ?? key;
}
