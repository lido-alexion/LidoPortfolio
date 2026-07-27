import React, { useMemo, useState } from 'react';
import HalfDonutShell, { BULLISH_GRADIENT_STOPS } from './HalfDonutShell';

/**
 * Market regime zones from MarketAnalysisEngine::regimeFromPhase.
 */
export const MARKET_REGIME_ZONES = [
    {
        id: 'bearish',
        name: 'Bearish',
        label: 'BEARISH',
        min: 0,
        max: 100 / 3,
        definition:
            'Risk-off regime. Bearish, correction, or capitulation phases dominate — favor defense.',
    },
    {
        id: 'neutral',
        name: 'Neutral',
        label: 'NEUTRAL',
        min: 100 / 3,
        max: 200 / 3,
        definition:
            'Mixed or sideways regime. Consolidation or pullback — selective risk only.',
    },
    {
        id: 'bullish',
        name: 'Bullish',
        label: 'BULLISH',
        min: 200 / 3,
        max: 100,
        definition:
            'Risk-on regime. Bull, strong bull, or recovery — constructive for participation.',
    },
];

export function marketRegimeZone(regime) {
    if (!regime) {
        return null;
    }
    const normalized = String(regime).trim().toLowerCase();
    return (
        MARKET_REGIME_ZONES.find((z) => z.name.toLowerCase() === normalized)
        || null
    );
}

/**
 * @param {{
 *   regime: string|null|undefined,
 *   className?: string,
 * }} props
 */
export default function MarketRegimeGauge({ regime, className = '' }) {
    const [hovered, setHovered] = useState(false);
    const zone = useMemo(() => marketRegimeZone(regime), [regime]);
    const needleScore = zone ? (zone.min + zone.max) / 2 : null;

    if (!zone || needleScore == null) {
        return null;
    }

    return (
        <HalfDonutShell
            score={needleScore}
            zones={MARKET_REGIME_ZONES}
            gradientStops={BULLISH_GRADIENT_STOPS}
            className={className}
            tabIndex={0}
            ariaLabel={`Market regime ${zone.name}. ${zone.definition}`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setHovered(true)}
            onBlur={() => setHovered(false)}
        >
            {hovered ? (
                <div className="lido-sentiment-gauge__tooltip" role="tooltip">
                    <div className="lido-sentiment-gauge__tooltip-score">
                        {zone.name}
                    </div>
                    <div className="lido-sentiment-gauge__tooltip-def">{zone.definition}</div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
