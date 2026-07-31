import React from 'react';

/**
 * Inline success cue shown above Validate / Import when artifact JSON validated OK.
 */
export default function ValidationSuccessBanner({ show }) {
    if (!show) return null;

    return (
        <div className="d-flex align-items-center gap-2 text-success small fw-semibold" role="status">
            <i className="bi bi-check-circle-fill fs-5" aria-hidden="true" />
            <span>Validated successfully</span>
        </div>
    );
}
