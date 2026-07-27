import React, { useMemo, useState } from 'react';
import HalfDonutShell, { BULLISH_GRADIENT_STOPS, clampScore } from './HalfDonutShell';

/**
 * Trend zones (score 0–100), aligned with MarketAnalysisEngine::buildTrend labels.
 */
export const TREND_ZONES = [
    {
        id: 'strong_downtrend',
        label: 'STRONG DOWN',
        displayName: 'Strong Downtrend',
        min: 0,
        max: 25,
        definition:
            'Clear, sustained decline. Price and moving averages are stacked bearishly.',
    },
    {
        id: 'weak_downtrend',
        label: 'WEAK DOWN',
        displayName: 'Weak Downtrend',
        min: 25,
        max: 45,
        definition:
            'Soft downward bias. Selling pressure is present but not extreme.',
    },
    {
        id: 'sideways',
        label: 'SIDEWAYS',
        displayName: 'Sideways',
        min: 45,
        max: 65,
        definition:
            'No clear directional trend. The market is range-bound or mixed.',
    },
    {
        id: 'uptrend',
        label: 'UPTREND',
        displayName: 'Uptrend',
        min: 65,
        max: 85,
        definition:
            'Prices are generally rising with a constructive trend structure.',
    },
    {
        id: 'strong_uptrend',
        label: 'STRONG UP',
        displayName: 'Strong Uptrend',
        min: 85,
        max: 100,
        definition:
            'Healthy, aligned uptrend with strong directional momentum.',
    },
];

export function trendZoneForScore(score) {
    const s = clampScore(score);
    if (s == null) {
        return null;
    }
    return (
        TREND_ZONES.find((z) => s >= z.min && (s < z.max || z.max === 100))
        || TREND_ZONES[2]
    );
}

/**
 * @param {{
 *   score: number|null|undefined,
 *   className?: string,
 * }} props
 */
export default function TrendGauge({ score, className = '' }) {
    const [hovered, setHovered] = useState(false);
    const clamped = clampScore(score);
    const zone = useMemo(() => trendZoneForScore(clamped), [clamped]);

    if (clamped == null || !zone) {
        return null;
    }

    return (
        <HalfDonutShell
            score={clamped}
            zones={TREND_ZONES}
            gradientStops={BULLISH_GRADIENT_STOPS}
            className={className}
            tabIndex={0}
            ariaLabel={`Trend ${Math.round(clamped)}, ${zone.displayName}. ${zone.definition}`}
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
                        </span>
                    </div>
                    <div className="lido-sentiment-gauge__tooltip-def">{zone.definition}</div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
