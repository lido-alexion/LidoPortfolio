import React from 'react';
import { getIconComponent } from '../../navigation/icons';

const DEFAULT_SIZE = 16;
const DEFAULT_STROKE = 1.75;

/**
 * Renders a registered nav icon by name (no direct Lucide imports in consumers).
 */
export default function NavIcon({
    name,
    size = DEFAULT_SIZE,
    strokeWidth = DEFAULT_STROKE,
    className = 'lido-sidebar-icon',
}) {
    if (!name) {
        return null;
    }
    const Icon = getIconComponent(name);
    if (!Icon) {
        if (import.meta.env?.DEV) {
            // eslint-disable-next-line no-console
            console.warn(`[NavIcon] Unknown icon "${name}". Register it via registerIcon().`);
        }
        return null;
    }
    return (
        <Icon
            className={className}
            size={size}
            strokeWidth={strokeWidth}
            aria-hidden="true"
        />
    );
}

export { ChevronRight, GripVertical, Star } from '../../navigation/icons';
