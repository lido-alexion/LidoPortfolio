import React, { useMemo, useState } from 'react';
import HalfDonutShell, { BULLISH_GRADIENT_STOPS, clampScore } from './HalfDonutShell';

/**
 * Market breadth zones aligned with MarketAnalysisEngine::buildBreadthV1.
 */
export const BREADTH_ZONES = [
    {
        id: 'weak',
        label: 'WEAK',
        displayName: 'Weak',
        min: 0,
        max: 45,
        definition:
            'Narrow participation. Advances are lagging declines — upside may lack confirmation.',
    },
    {
        id: 'neutral',
        label: 'NEUTRAL',
        displayName: 'Neutral',
        min: 45,
        max: 65,
        definition:
            'Balanced advance/decline. Breadth is neither confirming nor contradicting the move.',
    },
    {
        id: 'strong',
        label: 'STRONG',
        displayName: 'Strong',
        min: 65,
        max: 100,
        definition:
            'Broad participation. Advances dominate declines — healthier confirmation of strength.',
    },
];

export function breadthZoneForScore(score) {
    const s = clampScore(score);
    if (s == null) {
        return null;
    }
    return (
        BREADTH_ZONES.find((z) => s >= z.min && (s < z.max || z.max === 100))
        || BREADTH_ZONES[1]
    );
}

/**
 * @param {{
 *   score: number|null|undefined,
 *   advanceDeclineRatio?: number|null,
 *   className?: string,
 * }} props
 */
export default function MarketBreadthGauge({
    score,
    advanceDeclineRatio = null,
    className = '',
}) {
    const [hovered, setHovered] = useState(false);
    const clamped = clampScore(score);
    const zone = useMemo(() => breadthZoneForScore(clamped), [clamped]);
    const adNote = advanceDeclineRatio != null ? ` A/D ${advanceDeclineRatio}.` : '';

    if (clamped == null || !zone) {
        return null;
    }

    return (
        <HalfDonutShell
            score={clamped}
            zones={BREADTH_ZONES}
            gradientStops={BULLISH_GRADIENT_STOPS}
            className={className}
            tabIndex={0}
            ariaLabel={`Market breadth ${Math.round(clamped)}, ${zone.displayName}.${adNote} ${zone.definition}`}
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
                            {advanceDeclineRatio != null ? ` · A/D ${advanceDeclineRatio}` : ''}
                        </span>
                    </div>
                    <div className="lido-sentiment-gauge__tooltip-def">{zone.definition}</div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
