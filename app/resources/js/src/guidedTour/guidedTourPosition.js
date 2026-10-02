const PANEL_MARGIN = 16;
const TARGET_GAP = 12;
const PANEL_HEIGHT_ESTIMATE = 280;

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), Math.max(min, max));
}

function panelDimensions(viewport) {
    return {
        width: Math.min(420, Math.max(0, viewport.width - PANEL_MARGIN * 2)),
        height: Math.min(PANEL_HEIGHT_ESTIMATE, Math.max(0, viewport.height - PANEL_MARGIN * 2)),
    };
}

/**
 * Position the fixed tour panel without allowing an edge target to push it
 * outside the viewport. Placement is preferred, then flipped when there is
 * insufficient room; final coordinates are clamped for unusual layouts.
 */
export function guidedTourTooltipStyle(targetRect, placement = 'bottom', viewport = {
    width: typeof window === 'undefined' ? 1280 : window.innerWidth,
    height: typeof window === 'undefined' ? 800 : window.innerHeight,
}) {
    const base = {
        width: 'min(420px, calc(100vw - 2rem))',
        maxWidth: 'min(420px, calc(100vw - 2rem))',
        maxHeight: 'calc(100vh - 2rem)',
    };

    if (!targetRect) {
        return {
            ...base,
            '--guided-tour-caret-position': '50%',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
        };
    }

    const { width: panelWidth, height: panelHeight } = panelDimensions(viewport);
    const targetRight = targetRect.left + targetRect.width;
    const targetBottom = targetRect.top + targetRect.height;
    const room = {
        top: targetRect.top,
        bottom: viewport.height - targetBottom,
        left: targetRect.left,
        right: viewport.width - targetRight,
    };

    let resolvedPlacement = placement;
    if (placement === 'bottom' && room.bottom < panelHeight + TARGET_GAP && room.top >= room.bottom) {
        resolvedPlacement = 'top';
    } else if (placement === 'top' && room.top < panelHeight + TARGET_GAP && room.bottom >= room.top) {
        resolvedPlacement = 'bottom';
    } else if (placement === 'right' && room.right < panelWidth + TARGET_GAP && room.left >= room.right) {
        resolvedPlacement = 'left';
    } else if (placement === 'left' && room.left < panelWidth + TARGET_GAP && room.right >= room.left) {
        resolvedPlacement = 'right';
    }

    let top;
    let left;
    if (resolvedPlacement === 'right') {
        top = targetRect.top + targetRect.height / 2 - panelHeight / 2;
        left = targetRight + TARGET_GAP;
    } else if (resolvedPlacement === 'left') {
        top = targetRect.top + targetRect.height / 2 - panelHeight / 2;
        left = targetRect.left - TARGET_GAP - panelWidth;
    } else if (resolvedPlacement === 'top') {
        top = targetRect.top - TARGET_GAP - panelHeight;
        left = targetRect.left + targetRect.width / 2 - panelWidth / 2;
    } else {
        top = targetBottom + TARGET_GAP;
        left = targetRect.left + targetRect.width / 2 - panelWidth / 2;
    }

    const clampedTop = clamp(top, PANEL_MARGIN, viewport.height - PANEL_MARGIN - panelHeight);
    const clampedLeft = clamp(left, PANEL_MARGIN, viewport.width - PANEL_MARGIN - panelWidth);
    const caretPosition = resolvedPlacement === 'left' || resolvedPlacement === 'right'
        ? clamp(targetRect.top + targetRect.height / 2 - clampedTop, 18, panelHeight - 18)
        : clamp(targetRect.left + targetRect.width / 2 - clampedLeft, 18, panelWidth - 18);

    return {
        ...base,
        '--guided-tour-caret-position': `${caretPosition}px`,
        '--guided-tour-placement': resolvedPlacement,
        top: clampedTop,
        left: clampedLeft,
        transform: 'none',
    };
}
