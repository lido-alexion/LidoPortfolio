import React from 'react';

const VARIANTS = {
    info: { className: 'alert-info', icon: 'bi-info-circle', label: 'Information' },
    tip: { className: 'alert-success', icon: 'bi-lightbulb', label: 'Tip' },
    important: { className: 'alert-primary', icon: 'bi-bookmark-star', label: 'Important' },
    warning: { className: 'alert-warning', icon: 'bi-exclamation-triangle', label: 'Warning' },
};

/**
 * Bootstrap alert callout for documentation tips / warnings.
 * @param {{ variant?: 'info'|'tip'|'important'|'warning', title?: string, children: React.ReactNode }} props
 */
export default function DocCallout({ variant = 'info', title, children }) {
    const meta = VARIANTS[variant] || VARIANTS.info;
    return (
        <div className={`alert ${meta.className} lido-docs-callout d-flex gap-2 mb-3`} role="note">
            <i className={`bi ${meta.icon} flex-shrink-0 mt-1`} aria-hidden="true" />
            <div className="flex-grow-1">
                <div className="fw-semibold small mb-1">{title || meta.label}</div>
                <div className="small mb-0">{children}</div>
            </div>
        </div>
    );
}
