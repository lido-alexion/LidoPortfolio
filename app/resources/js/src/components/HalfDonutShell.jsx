import React, { useId, useMemo } from 'react';

/** Original ring thickness before the 60% slim-down. */
const ORIGINAL_STROKE = 92 - 58; // 34

/** Outer radius unchanged; ring width is 60% of original via a larger inner radius. */
export const R_OUTER = 92;
export const STROKE = Math.round(ORIGINAL_STROKE * 0.6); // 20
export const R_INNER = R_OUTER - STROKE; // 72
export const R_MID = (R_OUTER + R_INNER) / 2;
/** Gap from outer ring edge to label path (SVG user units ≈ CSS px at default scale). */
export const LABEL_GAP = 8;
export const LABEL_R = R_OUTER + LABEL_GAP;
/** Longer needle: tip reaches into the ring band. */
export const NEEDLE_TIP_R = R_INNER + STROKE * 0.45;
export const HUB_R = 7;

const LABEL_PAD = 16;
export const CX = LABEL_R + LABEL_PAD;
export const CY = LABEL_R + LABEL_PAD;
export const VB_W = CX + LABEL_R + LABEL_PAD;
export const VB_H = CY + HUB_R + 6;

export const DEFAULT_GRADIENT_STOPS = [
    { offset: '0%', color: '#22c55e' },
    { offset: '18%', color: '#84cc16' },
    { offset: '28%', color: '#eab308' },
    { offset: '42%', color: '#cbd5e1' },
    { offset: '50%', color: '#94a3b8' },
    { offset: '58%', color: '#cbd5e1' },
    { offset: '72%', color: '#fb923c' },
    { offset: '82%', color: '#f97316' },
    { offset: '100%', color: '#ef4444' },
];

export function clampScore(value) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return null;
    }
    return Math.min(100, Math.max(0, n));
}

/** Score 0 → left (π), 100 → right (0). */
export function scoreToRadians(score) {
    return Math.PI - (score / 100) * Math.PI;
}

export function polar(cx, cy, r, angleRad) {
    return {
        x: cx + r * Math.cos(angleRad),
        y: cy - r * Math.sin(angleRad),
    };
}

export function semicirclePath(cx, cy, r) {
    const left = polar(cx, cy, r, Math.PI);
    const right = polar(cx, cy, r, 0);
    return `M ${left.x} ${left.y} A ${r} ${r} 0 0 1 ${right.x} ${right.y}`;
}

export function arcPath(cx, cy, r, startAngle, endAngle) {
    const start = polar(cx, cy, r, startAngle);
    const end = polar(cx, cy, r, endAngle);
    const delta = ((startAngle - endAngle) + 2 * Math.PI) % (2 * Math.PI);
    const large = delta > Math.PI ? 1 : 0;
    return `M ${start.x} ${start.y} A ${r} ${r} 0 ${large} 1 ${end.x} ${end.y}`;
}

function hexToRgb(hex) {
    const h = hex.replace('#', '');
    return {
        r: parseInt(h.slice(0, 2), 16),
        g: parseInt(h.slice(2, 4), 16),
        b: parseInt(h.slice(4, 6), 16),
    };
}

function rgbToCss({ r, g, b }) {
    return `rgb(${Math.round(r)}, ${Math.round(g)}, ${Math.round(b)})`;
}

function mixRgb(a, b, t) {
    const u = Math.min(1, Math.max(0, t));
    return {
        r: a.r + (b.r - a.r) * u,
        g: a.g + (b.g - a.g) * u,
        b: a.b + (b.b - a.b) * u,
    };
}

function smoothstep(edge0, edge1, x) {
    const t = Math.min(1, Math.max(0, (x - edge0) / (edge1 - edge0)));
    return t * t * (3 - 2 * t);
}

/**
 * Shared upper half-donut shell: slim ring, outside labels, long needle.
 *
 * @param {{
 *   score: number,
 *   zones: Array<{ id: string, label: string, min: number, max: number, color?: string }>,
 *   gradientStops?: Array<{ offset: string, color: string }>,
 *   colorMode?: 'gradient' | 'zoneBlend',
 *   hitZones?: boolean,
 *   onZoneEnter?: (zoneId: string|null) => void,
 *   className?: string,
 *   ariaLabel?: string,
 *   children?: React.ReactNode,
 *   tabIndex?: number,
 *   onMouseEnter?: () => void,
 *   onMouseLeave?: () => void,
 *   onFocus?: () => void,
 *   onBlur?: () => void,
 * }} props
 */
export default function HalfDonutShell({
    score,
    zones,
    gradientStops = DEFAULT_GRADIENT_STOPS,
    colorMode = 'gradient',
    hitZones = false,
    onZoneEnter,
    className = '',
    ariaLabel,
    children = null,
    tabIndex,
    onMouseEnter,
    onMouseLeave,
    onFocus,
    onBlur,
}) {
    const uid = useId().replace(/:/g, '');
    const gradientId = `lido-half-donut-grad-${uid}`;
    const labelPathId = `lido-half-donut-label-${uid}`;

    const clamped = clampScore(score);
    const needleTip = useMemo(() => {
        if (clamped == null) {
            return null;
        }
        return polar(CX, CY, NEEDLE_TIP_R, scoreToRadians(clamped));
    }, [clamped]);

    const blendedSlices = useMemo(() => {
        if (colorMode !== 'zoneBlend' || !zones?.length) {
            return [];
        }
        const n = zones.length;
        const sliceCount = n * 12;
        return Array.from({ length: sliceCount }, (_, i) => {
            const startScore = (i / sliceCount) * 100;
            const endScore = ((i + 1.15) / sliceCount) * 100;
            const t = (i + 0.5) / sliceCount;
            const pos = Math.min(0.9999, t) * n;
            const zi = Math.floor(pos);
            const f = pos - zi;
            const curr = hexToRgb(zones[zi].color || '#94a3b8');
            const next = hexToRgb(zones[Math.min(n - 1, zi + 1)].color || '#94a3b8');
            const blend = zi >= n - 1 ? 0 : smoothstep(0.7, 1, f);
            return {
                key: `slice-${i}`,
                d: arcPath(
                    CX,
                    CY,
                    R_MID,
                    scoreToRadians(startScore),
                    scoreToRadians(Math.min(100, endScore)),
                ),
                color: rgbToCss(mixRgb(curr, next, blend)),
            };
        });
    }, [colorMode, zones]);

    if (clamped == null || !needleTip || !zones?.length) {
        return null;
    }

    return (
        <div
            className={`lido-sentiment-gauge ${className}`.trim()}
            tabIndex={tabIndex}
            role={ariaLabel ? 'img' : undefined}
            aria-label={ariaLabel}
            onMouseEnter={onMouseEnter}
            onMouseLeave={() => {
                onZoneEnter?.(null);
                onMouseLeave?.();
            }}
            onFocus={onFocus}
            onBlur={onBlur}
        >
            <svg
                className="lido-sentiment-gauge__svg"
                viewBox={`0 0 ${VB_W} ${VB_H}`}
                width="100%"
                aria-hidden="true"
            >
                <defs>
                    {colorMode === 'gradient' ? (
                        <linearGradient id={gradientId} x1="0%" y1="0%" x2="100%" y2="0%">
                            {gradientStops.map((s) => (
                                <stop key={s.offset} offset={s.offset} stopColor={s.color} />
                            ))}
                        </linearGradient>
                    ) : null}
                    <path id={labelPathId} d={semicirclePath(CX, CY, LABEL_R)} fill="none" />
                    {zones.map((z, i) => (
                        <path
                            key={`lp-${z.id}`}
                            id={`${labelPathId}-${i}`}
                            d={arcPath(
                                CX,
                                CY,
                                LABEL_R,
                                scoreToRadians(z.min),
                                scoreToRadians(z.max),
                            )}
                            fill="none"
                        />
                    ))}
                </defs>

                {colorMode === 'gradient' ? (
                    <path
                        d={semicirclePath(CX, CY, R_MID)}
                        fill="none"
                        stroke={`url(#${gradientId})`}
                        strokeWidth={STROKE}
                        strokeLinecap="butt"
                        pointerEvents="none"
                    />
                ) : (
                    blendedSlices.map((s) => (
                        <path
                            key={s.key}
                            d={s.d}
                            fill="none"
                            stroke={s.color}
                            strokeWidth={STROKE}
                            strokeLinecap="butt"
                            pointerEvents="none"
                        />
                    ))
                )}

                {zones.map((z, i) => {
                    const midFrac = (z.min + z.max) / 2 / 100;
                    const useSegmentPath = zones.length > 5;
                    return (
                        <text
                            key={z.id}
                            className="lido-sentiment-gauge__label"
                        >
                            <textPath
                                href={useSegmentPath ? `#${labelPathId}-${i}` : `#${labelPathId}`}
                                startOffset={useSegmentPath ? '50%' : `${midFrac * 100}%`}
                                textAnchor="middle"
                            >
                                {z.label}
                            </textPath>
                        </text>
                    );
                })}

                {hitZones
                    ? zones.map((z) => (
                        <path
                            key={`hit-${z.id}`}
                            d={arcPath(
                                CX,
                                CY,
                                R_MID,
                                scoreToRadians(z.min),
                                scoreToRadians(z.max),
                            )}
                            fill="none"
                            stroke="transparent"
                            strokeWidth={STROKE + 10}
                            strokeLinecap="butt"
                            className="lido-market-phase-gauge__hit"
                            onMouseEnter={() => onZoneEnter?.(z.id)}
                        />
                    ))
                    : null}

                <line
                    className="lido-sentiment-gauge__needle"
                    x1={CX}
                    y1={CY}
                    x2={needleTip.x}
                    y2={needleTip.y}
                    strokeWidth={2.5}
                    strokeLinecap="round"
                    pointerEvents="none"
                />
                <circle
                    className="lido-sentiment-gauge__hub"
                    cx={CX}
                    cy={CY}
                    r={HUB_R}
                    pointerEvents="none"
                />
            </svg>
            {children}
        </div>
    );
}
