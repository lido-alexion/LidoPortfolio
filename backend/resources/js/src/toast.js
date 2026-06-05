export const TOAST_AUTO_DISMISS_MS = {
    success: 7_000,
    info: 7_000,
    warning: 12_000,
    danger: 5 * 60 * 1000,
};

export function getToastAutoDismissMs(variant = 'success') {
    return TOAST_AUTO_DISMISS_MS[variant] ?? TOAST_AUTO_DISMISS_MS.success;
}

export function showToast(message, variant = 'success') {
    window.dispatchEvent(
        new CustomEvent('portfolio-toast', {
            detail: { message, variant },
        }),
    );
}
