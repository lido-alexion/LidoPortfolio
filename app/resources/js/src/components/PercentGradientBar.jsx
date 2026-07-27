import React, { useLayoutEffect, useMemo, useState } from 'react';

const BAR_HEIGHT_PX = 10;
const TIP_INSET_PX = 3;
/** Equilateral triangle: 50% larger than the bar height; side = height × 2 / √3 */
const TRIANGLE_HEIGHT_PX = BAR_HEIGHT_PX * 1.5;
const TRIANGLE_SIDE_PX = (TRIANGLE_HEIGHT_PX * 2) / Math.sqrt(3);
const MARKER_STROKE_PX = 2;

const DEFAULT_ENDS = {
    danger: { r: 220, g: 38, b: 38 },
    success: { r: 21, g: 128, b: 61 },
};

function clampPercent(value, min, max) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return min;
    }
    const span = max - min;
    if (span <= 0) {
        return min;
    }
    return Math.min(max, Math.max(min, n));
}

function parseCssColor(raw) {
    if (!raw) {
        return null;
    }
    const s = raw.trim();
    const hex = s.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (hex) {
        let h = hex[1];
        if (h.length === 3) {
            h = h.split('').map((c) => c + c).join('');
        }
        return {
            r: parseInt(h.slice(0, 2), 16),
            g: parseInt(h.slice(2, 4), 16),
            b: parseInt(h.slice(4, 6), 16),
        };
    }
    const rgb = s.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i);
    if (rgb) {
        return { r: Number(rgb[1]), g: Number(rgb[2]), b: Number(rgb[3]) };
    }
    return null;
}

function mixRgb(a, b, t) {
    const u = Math.min(1, Math.max(0, t));
    return {
        r: Math.round(a.r + (b.r - a.r) * u),
        g: Math.round(a.g + (b.g - a.g) * u),
        b: Math.round(a.b + (b.b - a.b) * u),
    };
}

function rgbToCss({ r, g, b }) {
    return `rgb(${r}, ${g}, ${b})`;
}

function readThemeGradientEnds(el) {
    const styles = getComputedStyle(el || document.documentElement);
    const danger = parseCssColor(styles.getPropertyValue('--lido-text-danger').trim());
    const success = parseCssColor(styles.getPropertyValue('--lido-text-success').trim());
    return {
        danger: danger || DEFAULT_ENDS.danger,
        success: success || DEFAULT_ENDS.success,
    };
}

/**
 * Theme-aware color at `value` on the red→green PercentGradientBar scale.
 * Returns null when value is missing / not a number.
 *
 * @param {number|null|undefined} value
 * @param {{ min?: number, max?: number }} [options]
 * @returns {string|null}
 */
export function usePercentGradientColor(value, options = {}) {
    const { min = 0, max = 100 } = options;
    const [ends, setEnds] = useState(DEFAULT_ENDS);

    useLayoutEffect(() => {
        const read = () => setEnds(readThemeGradientEnds(document.documentElement));
        read();
        const mo = new MutationObserver(read);
        mo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-bs-theme'] });
        return () => mo.disconnect();
    }, []);

    return useMemo(() => {
        if (value == null || value === '' || Number.isNaN(Number(value))) {
            return null;
        }
        const clamped = clampPercent(value, min, max);
        const span = max - min;
        const ratio = span > 0 ? (clamped - min) / span : 0;
        return rgbToCss(mixRgb(ends.danger, ends.success, ratio));
    }, [value, min, max, ends.danger, ends.success]);
}

/**
 * Horizontal 0–100% gradient bar (red → green) with an equilateral downward
 * triangle marker at `value`. Labels are not shown. Reusable across pages.
 *
 * @param {{
 *   value: number|null|undefined,
 *   min?: number,
 *   max?: number,
 *   className?: string,
 *   title?: string,
 * }} props
 */
export default function PercentGradientBar({
    value,
    min = 0,
    max = 100,
    className = '',
    title,
}) {
    const clamped = clampPercent(value, min, max);
    const span = max - min;
    const ratio = span > 0 ? (clamped - min) / span : 0;

    const halfSide = TRIANGLE_SIDE_PX / 2;
    const tipY = TRIANGLE_HEIGHT_PX;
    const points = `0,0 ${TRIANGLE_SIDE_PX},0 ${halfSide},${tipY}`;
    const markerTop = TIP_INSET_PX - TRIANGLE_HEIGHT_PX;
    const markerLabel = title ?? String(value);

    if (value == null || value === '' || Number.isNaN(Number(value))) {
        return null;
    }

    return (
        <div
            className={`lido-percent-gradient-bar ${className}`.trim()}
            role="img"
            aria-label={`Value ${markerLabel} on a scale from ${min} to ${max}`}
        >
            <div className="lido-percent-gradient-bar__track">
                <svg
                    className="lido-percent-gradient-bar__marker"
                    width={TRIANGLE_SIDE_PX}
                    height={TRIANGLE_HEIGHT_PX}
                    viewBox={`0 0 ${TRIANGLE_SIDE_PX} ${TRIANGLE_HEIGHT_PX}`}
                    style={{
                        left: `${ratio * 100}%`,
                        top: `${markerTop}px`,
                    }}
                    title={markerLabel}
                >
                    <title>{markerLabel}</title>
                    <polygon
                        points={points}
                        fill="var(--lido-percent-marker-fill)"
                        stroke="var(--lido-percent-marker-stroke)"
                        strokeWidth={MARKER_STROKE_PX}
                        strokeLinejoin="round"
                        paintOrder="fill stroke"
                    />
                </svg>
            </div>
        </div>
    );
}
