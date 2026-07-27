import React, { useMemo, useState } from 'react';
import HalfDonutShell, { clampScore } from './HalfDonutShell';

/**
 * Volatility intensity zones. Engine stores inverted safety score; gauge uses
 * intensity = 100 − score so right = more volatile.
 */
export const VOLATILITY_ZONES = [
    {
        id: 'low',
        label: 'LOW',
        displayName: 'Low',
        min: 0,
        max: 30,
        definition:
            'Calm market. Price swings are relatively small and ranges are contained.',
    },
    {
        id: 'moderate',
        label: 'MODERATE',
        displayName: 'Moderate',
        min: 30,
        max: 50,
        definition:
            'Normal volatility. Moves are noticeable but not disorderly.',
    },
    {
        id: 'high',
        label: 'HIGH',
        displayName: 'High',
        min: 50,
        max: 70,
        definition:
            'Elevated swings. Risk of sharp moves is higher; position sizing matters more.',
    },
    {
        id: 'extreme',
        label: 'EXTREME',
        displayName: 'Extreme',
        min: 70,
        max: 100,
        definition:
            'Very high volatility. Large, fast moves are common; caution is warranted.',
    },
];

export function volatilityIntensityFromScore(score) {
    const s = clampScore(score);
    if (s == null) {
        return null;
    }
    return clampScore(100 - s);
}

export function volatilityZoneForIntensity(intensity) {
    const s = clampScore(intensity);
    if (s == null) {
        return null;
    }
    return (
        VOLATILITY_ZONES.find((z) => s >= z.min && (s < z.max || z.max === 100))
        || VOLATILITY_ZONES[1]
    );
}

/**
 * @param {{
 *   score: number|null|undefined,
 *   historicalVolatilityPct?: number|null,
 *   className?: string,
 * }} props
 */
export default function VolatilityGauge({
    score,
    historicalVolatilityPct = null,
    className = '',
}) {
    const [hovered, setHovered] = useState(false);
    const intensity = useMemo(() => volatilityIntensityFromScore(score), [score]);
    const zone = useMemo(() => volatilityZoneForIntensity(intensity), [intensity]);
    const hvNote = historicalVolatilityPct != null
        ? ` HV ${historicalVolatilityPct}%.`
        : '';

    if (intensity == null || !zone) {
        return null;
    }

    return (
        <HalfDonutShell
            score={intensity}
            zones={VOLATILITY_ZONES}
            className={className}
            tabIndex={0}
            ariaLabel={`Volatility ${zone.displayName}.${hvNote} ${zone.definition}`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setHovered(true)}
            onBlur={() => setHovered(false)}
        >
            {hovered ? (
                <div className="lido-sentiment-gauge__tooltip" role="tooltip">
                    <div className="lido-sentiment-gauge__tooltip-score">
                        {zone.displayName}
                        {historicalVolatilityPct != null ? (
                            <span className="lido-sentiment-gauge__tooltip-zone">
                                {' '}
                                · HV {historicalVolatilityPct}%
                            </span>
                        ) : (
                            <span className="lido-sentiment-gauge__tooltip-zone">
                                {' '}
                                · {Math.round(intensity)}
                            </span>
                        )}
                    </div>
                    <div className="lido-sentiment-gauge__tooltip-def">{zone.definition}</div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
