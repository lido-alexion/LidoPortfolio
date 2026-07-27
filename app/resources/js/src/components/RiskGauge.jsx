import React, { useMemo, useState } from 'react';
import HalfDonutShell, { clampScore } from './HalfDonutShell';

/**
 * Risk intensity zones from raw_risk (or 100 − safety score).
 */
export const RISK_ZONES = [
    {
        id: 'low',
        label: 'LOW',
        displayName: 'Low',
        min: 0,
        max: 30,
        definition:
            'Contained risk. Drawdown, volatility, and trend stress look manageable.',
    },
    {
        id: 'medium',
        label: 'MEDIUM',
        displayName: 'Medium',
        min: 30,
        max: 50,
        definition:
            'Moderate risk. Conditions are watchable; avoid oversized new risk.',
    },
    {
        id: 'high',
        label: 'HIGH',
        displayName: 'High',
        min: 50,
        max: 70,
        definition:
            'Elevated risk. Volatility, drawdown, or trend failure is pressuring the market.',
    },
    {
        id: 'extreme',
        label: 'EXTREME',
        displayName: 'Extreme',
        min: 70,
        max: 100,
        definition:
            'Severe risk. Stress is high across key factors; defensive positioning is prudent.',
    },
];

export function riskIntensity({ rawRisk, score }) {
    const raw = clampScore(rawRisk);
    if (raw != null) {
        return raw;
    }
    const safety = clampScore(score);
    if (safety == null) {
        return null;
    }
    return clampScore(100 - safety);
}

export function riskZoneForIntensity(intensity) {
    const s = clampScore(intensity);
    if (s == null) {
        return null;
    }
    return (
        RISK_ZONES.find((z) => s >= z.min && (s < z.max || z.max === 100))
        || RISK_ZONES[1]
    );
}

/**
 * @param {{
 *   score?: number|null,
 *   rawRisk?: number|null,
 *   className?: string,
 * }} props
 */
export default function RiskGauge({ score = null, rawRisk = null, className = '' }) {
    const [hovered, setHovered] = useState(false);
    const intensity = useMemo(
        () => riskIntensity({ rawRisk, score }),
        [rawRisk, score],
    );
    const zone = useMemo(() => riskZoneForIntensity(intensity), [intensity]);

    if (intensity == null || !zone) {
        return null;
    }

    return (
        <HalfDonutShell
            score={intensity}
            zones={RISK_ZONES}
            className={className}
            tabIndex={0}
            ariaLabel={`Risk ${Math.round(intensity)}, ${zone.displayName}. ${zone.definition}`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setHovered(true)}
            onBlur={() => setHovered(false)}
        >
            {hovered ? (
                <div className="lido-sentiment-gauge__tooltip" role="tooltip">
                    <div className="lido-sentiment-gauge__tooltip-score">
                        {Math.round(intensity)}
                        <span className="lido-sentiment-gauge__tooltip-zone">
                            {' '}
                            · {zone.displayName}
                        </span>
                    </div>
                    <div className="lido-sentiment-gauge__tooltip-def">{zone.definition}</div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
