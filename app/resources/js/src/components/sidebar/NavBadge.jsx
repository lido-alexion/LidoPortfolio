import React from 'react';

/**
 * Reusable badge + NEW/BETA tag from nav metadata.
 */
export default function NavBadge({ badge, tag, collapsed = false, className = '' }) {
    if (collapsed) {
        return null;
    }

    const hasBadge = badge != null && badge !== '';
    const normalizedTag = typeof tag === 'string' ? tag.toUpperCase() : null;
    const showTag = normalizedTag === 'NEW' || normalizedTag === 'BETA';

    if (!hasBadge && !showTag) {
        return null;
    }

    return (
        <span className={`lido-sidebar-meta ${className}`.trim()}>
            {showTag && (
                <span className={`lido-sidebar-tag lido-sidebar-tag--${normalizedTag.toLowerCase()}`}>
                    {normalizedTag}
                </span>
            )}
            {hasBadge && (
                <span className="lido-sidebar-badge">{badge}</span>
            )}
        </span>
    );
}
