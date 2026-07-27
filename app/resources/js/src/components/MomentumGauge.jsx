import React, { useMemo, useState } from 'react';
import HalfDonutShell, { clampScore } from './HalfDonutShell';

/**
 * Momentum zones (score 0–100), aligned with MarketAnalysisEngine::buildMomentum.
 */
export const MOMENTUM_ZONES = [
    {
        id: 'low',
        label: 'LOW',
        displayName: 'Low',
        min: 0,
        max: 45,
        definition:
            'Weak momentum. Buying pressure is limited; moves may lack follow-through.',
    },
    {
        id: 'moderate',
        label: 'MODERATE',
        displayName: 'Moderate',
        min: 45,
        max: 70,
        definition:
            'Balanced momentum. Neither strongly accelerating nor stalling.',
    },
    {
        id: 'high',
        label: 'HIGH',
        displayName: 'High',
        min: 70,
        max: 100,
        definition:
            'Strong momentum. Price moves are accelerating with firm directional pressure.',
    },
];

export function momentumZoneForScore(score) {
    const s = clampScore(score);
    if (s == null) {
        return null;
    }
    return (
        MOMENTUM_ZONES.find((z) => s >= z.min && (s < z.max || z.max === 100))
        || MOMENTUM_ZONES[1]
    );
}

/**
 * @param {{
 *   score: number|null|undefined,
 *   direction?: string|null,
 *   className?: string,
 * }} props
 */
export default function MomentumGauge({ score, direction = null, className = '' }) {
    const [hovered, setHovered] = useState(false);
    const clamped = clampScore(score);
    const zone = useMemo(() => momentumZoneForScore(clamped), [clamped]);
    const directionNote = direction ? ` Direction: ${direction}.` : '';

    if (clamped == null || !zone) {
        return null;
    }

    return (
        <HalfDonutShell
            score={clamped}
            zones={MOMENTUM_ZONES}
            className={className}
            tabIndex={0}
            ariaLabel={`Momentum ${Math.round(clamped)}, ${zone.displayName}.${directionNote} ${zone.definition}`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setHovered(true)}
            onBlur={() => setHovered(false)}
        >
            {hovered ? (
                <div className="lido-sentiment-gauge__tooltip" role="tooltip">
                    <div className="lido-sentiment-gauge__tooltip-score">
                        {Math.round(clamped)}
                        <span className="lido-sentiment-gauge__tooltip-zone">
                            {' '}
                            · {zone.displayName}
                            {direction ? ` · ${direction}` : ''}
                        </span>
                    </div>
                    <div className="lido-sentiment-gauge__tooltip-def">{zone.definition}</div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
