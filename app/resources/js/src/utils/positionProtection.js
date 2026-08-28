export function protectionStateLabel(state) {
    switch (state) {
        case 'active':
            return 'Active';
        case 'pending':
            return 'Pending';
        case 'synchronizing':
            return 'Synchronizing';
        case 'needs_attention':
            return 'Needs attention';
        case 'cancelled':
            return 'Cancelled';
        case 'reconciled':
            return 'Reconciled';
        default:
            return state ? String(state) : 'None';
    }
}

export function protectionTypeLabel(type) {
    if (type === 'target') {
        return 'GTT Target';
    }
    if (type === 'stop') {
        return 'GTT Stop-Loss';
    }
    return 'Broker protection';
}

/**
 * Smallest useful Holdings menu for FEAT-002. Manual never offers Place.
 * Automatic shows status only (no per-order place/cancel).
 */
export function protectionMenuItems({
    executionMode,
    holding,
    protection,
    onPlaceTarget,
    onPlaceStop,
    onCancel,
}) {
    if (!holding || holding.is_unmanaged) {
        return [];
    }
    const mode = executionMode || 'manual';
    const open = protection && ['pending', 'active', 'synchronizing', 'needs_attention'].includes(protection.state);
    if (mode === 'semi_automatic') {
        const items = [
            { label: 'Place GTT Target', onClick: onPlaceTarget },
            { label: 'Place GTT Stop-Loss', onClick: onPlaceStop },
        ];
        if (open && onCancel) {
            items.push({ label: 'Cancel protection', onClick: onCancel });
        }
        return items;
    }
    return [];
}
