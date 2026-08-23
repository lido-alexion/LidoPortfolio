/**
 * V3 recall / capital-resolution UI labels (presentation only — no business rules).
 */

export const RECALL_STATES = Object.freeze({
    requested: 'requested',
    immediate_settlement: 'immediate_settlement',
    pending_held: 'pending_held',
    liquidation: 'liquidation',
    settlement: 'settlement',
    completed: 'completed',
});

/** User-facing recall lifecycle labels */
export function recallStateLabel(state) {
    switch (String(state || '')) {
        case 'requested':
            return 'Requested';
        case 'immediate_settlement':
            return 'Immediate settlement';
        case 'pending_held':
            return 'Pending — funds being arranged';
        case 'liquidation':
            return 'Liquidation';
        case 'settlement':
            return 'Settlement';
        case 'completed':
            return 'Completed';
        default:
            return state ? String(state) : '—';
    }
}

export function recallStateBadgeClass(state) {
    switch (String(state || '')) {
        case 'completed':
            return 'text-bg-success';
        case 'pending_held':
            return 'text-bg-warning';
        case 'liquidation':
        case 'settlement':
            return 'text-bg-info';
        case 'immediate_settlement':
            return 'text-bg-primary';
        case 'requested':
            return 'text-bg-secondary';
        default:
            return 'text-bg-light';
    }
}

export function bridgeStatusLabel(status) {
    switch (String(status || '')) {
        case 'outstanding':
            return 'Outstanding';
        case 'partially_returned':
            return 'Partially repaid';
        case 'returned':
            return 'Repaid';
        default:
            return status ? String(status) : '—';
    }
}

export function bridgeStatusBadgeClass(status) {
    switch (String(status || '')) {
        case 'returned':
            return 'text-bg-success';
        case 'partially_returned':
            return 'text-bg-info';
        case 'outstanding':
            return 'text-bg-warning';
        default:
            return 'text-bg-secondary';
    }
}

/**
 * Distinguish sale executed vs proceeds available (DEP-SALE-PROCEEDS).
 */
export function proceedsStatusLabel(status) {
    switch (String(status || '')) {
        case 'pending':
            return 'Sale executed — proceeds pending';
        case 'available':
            return 'Proceeds available';
        case 'applied':
            return 'Applied';
        default:
            return status ? String(status) : '—';
    }
}

export function proceedsStatusBadgeClass(status) {
    switch (String(status || '')) {
        case 'pending':
            return 'text-bg-warning';
        case 'available':
            return 'text-bg-info';
        case 'applied':
            return 'text-bg-success';
        default:
            return 'text-bg-secondary';
    }
}

export function recallKindLabel(kind) {
    switch (String(kind || '').toLowerCase()) {
        case 'full':
            return 'Full Recall';
        case 'partial':
            return 'Partial Recall';
        default:
            return kind ? String(kind) : 'Recall';
    }
}

export function capitalResolutionStateLabel(state) {
    switch (String(state || '')) {
        case 'resolved_at_actual':
            return 'Resolved at actual amount';
        case 'closed_at_actual_with_shortfall':
            return 'Closed at actual (shortfall remains)';
        case 'recall_in_progress':
            return 'Recall in progress';
        case 'unfunded':
            return 'Unfunded';
        default:
            return state ? String(state) : '—';
    }
}

/**
 * Pick the headline execution amount for UI (never invent funding).
 */
export function actualExecutionAmount(resolution) {
    if (!resolution || typeof resolution !== 'object') return null;
    if (resolution.actual_execution_amount != null) {
        return Number(resolution.actual_execution_amount);
    }
    if (resolution.total_immediately_available != null) {
        return Number(resolution.total_immediately_available);
    }
    return null;
}

export const TERMINOLOGY = Object.freeze({
    bridgeLoan: 'Recall Bridge Loan',
    proceeds: 'Proceeds from Stock Sale',
    recall: 'Recall',
});
