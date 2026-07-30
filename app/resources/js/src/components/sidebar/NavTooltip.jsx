import React from 'react';

/**
 * Collapsed-ribbon tooltip for sidebar controls.
 */
export default function NavTooltip({ children, visible }) {
    if (!visible) {
        return null;
    }
    return (
        <span className="lido-sidebar-tooltip" role="tooltip">
            {children}
        </span>
    );
}

/**
 * @param {{ title: string, tag?: string|null, disabled?: boolean, external?: boolean }} item
 */
export function formatNavTooltipLabel(item) {
    const parts = [item.title];
    if (item.tag) {
        parts.push(String(item.tag).toUpperCase());
    }
    let label = parts.join(' · ');
    if (item.disabled) {
        label += ' (unavailable)';
    }
    if (item.external) {
        label += ' ↗';
    }
    return label;
}
