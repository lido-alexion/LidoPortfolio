import React, { useMemo, useState } from 'react';
import HalfDonutShell from './HalfDonutShell';

const GREEN = '#22c55e';
const AMBER = '#f59e0b';
const RED = '#ef4444';

/**
 * Market-cycle phases on an upper half-donut.
 * Logical order is Pullback → … → Correction; Consolidation is not drawn
 * (needle maps between Capitulation and Bear). invertScale mirrors the dial so
 * colours read red→green left→right (aligned with Trend / Momentum gauges).
 */
export const MARKET_PHASES = [
    {
        id: 'pullback',
        name: 'Pullback',
        label: 'PULLBACK',
        color: AMBER,
        meaning: 'Temporary decline within an uptrend.',
        characteristics: 'Short-term weakness, long-term trend still bullish.',
        newBuys: 'Selective',
        existingPositions: 'Reduce weak names',
    },
    {
        id: 'bull',
        name: 'Bull',
        label: 'BULL',
        color: GREEN,
        meaning: 'Positive trend, but less explosive.',
        characteristics: 'Trend intact, moderate momentum.',
        newBuys: 'Yes',
        existingPositions: 'Hold',
    },
    {
        id: 'strong_bull',
        name: 'Strong Bull',
        label: 'STR BULL',
        color: GREEN,
        meaning: 'Strong, healthy uptrend.',
        characteristics: 'Higher highs, higher lows, strong momentum, broad participation.',
        newBuys: 'Yes',
        existingPositions: 'Add / Hold',
    },
    {
        id: 'recovery',
        name: 'Recovery',
        label: 'RECOVERY',
        color: GREEN,
        meaning: 'Early recovery after a bear market.',
        characteristics: 'Momentum improving, trend beginning to reverse.',
        newBuys: 'Yes (after confirmation)',
        existingPositions: 'Hold',
    },
    {
        id: 'capitulation',
        name: 'Capitulation',
        label: 'CAPIT.',
        color: AMBER,
        meaning: 'Panic selling after a prolonged decline. Often near the bottom.',
        characteristics: 'Very high volatility, oversold, fear, large volume.',
        newBuys: 'Wait for confirmation',
        existingPositions: 'Mostly wait',
    },
    {
        id: 'bear',
        name: 'Bear',
        label: 'BEAR',
        color: RED,
        meaning: 'Sustained downtrend.',
        characteristics: 'Lower highs, lower lows, weak breadth, negative momentum.',
        newBuys: 'No',
        existingPositions: 'Exit weak positions',
    },
    {
        id: 'correction',
        name: 'Correction',
        label: 'CORRECT',
        color: RED,
        meaning: 'Larger decline than a pullback. Trend becoming uncertain.',
        characteristics: 'Breakdown of short-term trend, increased volatility.',
        newBuys: 'No',
        existingPositions: 'Tighten risk',
    },
];

const PHASE_COUNT = MARKET_PHASES.length;

const CONSOLIDATION_PHASE = {
    id: 'consolidation',
    name: 'Consolidation',
    label: 'CONSOLIDATION',
    color: AMBER,
    meaning: 'Sideways market. Neither bulls nor bears in control.',
    characteristics: 'Low trend strength, range-bound movement.',
    newBuys: 'Selective',
    existingPositions: 'Hold',
    /** Between Capitulation (index 4) and Bear (index 5). */
    needleScore: (100 / PHASE_COUNT) * 4.5,
};

const PHASE_ZONES = MARKET_PHASES.map((p, i) => {
    const span = 100 / PHASE_COUNT;
    return {
        ...p,
        min: i * span,
        max: (i + 1) * span,
    };
});

/**
 * Continuous left→right gradient from zone colors, with soft mixes at boundaries
 * (same visual language as the nearby half-donut gauges).
 */
function mixHex(a, b, t) {
    const parse = (hex) => {
        const h = hex.replace('#', '');
        return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
    };
    const [ar, ag, ab] = parse(a);
    const [br, bg, bb] = parse(b);
    const u = Math.min(1, Math.max(0, t));
    const to = (n) => n.toString(16).padStart(2, '0');
    return `#${to(Math.round(ar + (br - ar) * u))}${to(Math.round(ag + (bg - ag) * u))}${to(Math.round(ab + (bb - ab) * u))}`;
}

const PHASE_GRADIENT_STOPS = (() => {
    const stops = [];
    PHASE_ZONES.forEach((z, i) => {
        const mid = (z.min + z.max) / 2;
        if (i === 0) {
            stops.push({ offset: '0%', color: z.color });
        }
        // Soft blend band into the next zone
        if (i < PHASE_ZONES.length - 1) {
            const next = PHASE_ZONES[i + 1];
            const boundary = z.max;
            stops.push({ offset: `${mid}%`, color: z.color });
            if (z.color !== next.color) {
                stops.push({
                    offset: `${boundary - (boundary - mid) * 0.25}%`,
                    color: mixHex(z.color, next.color, 0.35),
                });
                stops.push({
                    offset: `${boundary}%`,
                    color: mixHex(z.color, next.color, 0.5),
                });
                stops.push({
                    offset: `${boundary + (next.max - boundary) * 0.25}%`,
                    color: mixHex(z.color, next.color, 0.65),
                });
            } else {
                stops.push({ offset: `${boundary}%`, color: z.color });
            }
        } else {
            stops.push({ offset: `${mid}%`, color: z.color });
            stops.push({ offset: '100%', color: z.color });
        }
    });
    return stops;
})();

export function marketPhaseByName(name) {
    if (!name) {
        return null;
    }
    const normalized = String(name).trim().toLowerCase();
    if (normalized === 'consolidation') {
        return CONSOLIDATION_PHASE;
    }
    return MARKET_PHASES.find((p) => p.name.toLowerCase() === normalized) || null;
}

function needleScoreForPhase(phaseObj) {
    if (!phaseObj) {
        return null;
    }
    if (phaseObj.needleScore != null) {
        return phaseObj.needleScore;
    }
    const zone = PHASE_ZONES.find((p) => p.id === phaseObj.id);
    if (!zone) {
        return null;
    }
    return (zone.min + zone.max) / 2;
}

/**
 * @param {{
 *   phase: string|null|undefined,
 *   className?: string,
 * }} props
 */
export default function MarketPhaseGauge({ phase, className = '' }) {
    const [hovered, setHovered] = useState(false);
    const current = useMemo(() => marketPhaseByName(phase), [phase]);
    const needleScore = useMemo(() => needleScoreForPhase(current), [current]);

    if (!current || needleScore == null) {
        return (
            <div className={`lido-sentiment-gauge ${className}`.trim()}>
                <div className="text-muted small" style={{ lineHeight: 1.35 }}>
                    Phase unavailable
                </div>
            </div>
        );
    }

    return (
        <HalfDonutShell
            score={needleScore}
            zones={PHASE_ZONES}
            colorMode="gradient"
            gradientStops={PHASE_GRADIENT_STOPS}
            invertScale
            className={className}
            tabIndex={0}
            ariaLabel={`Market phase ${current.name}. New buys: ${current.newBuys}. Existing positions: ${current.existingPositions}.`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setHovered(true)}
            onBlur={() => setHovered(false)}
        >
            {hovered ? (
                <div className="lido-market-phase-gauge__tooltip" role="tooltip">
                    <div className="lido-market-phase-gauge__tooltip-title">
                        {current.name}
                    </div>
                    <div className="lido-market-phase-gauge__tooltip-row">
                        <span className="lido-market-phase-gauge__tooltip-k">Meaning</span>
                        {current.meaning}
                    </div>
                    <div className="lido-market-phase-gauge__tooltip-row">
                        <span className="lido-market-phase-gauge__tooltip-k">Characteristics</span>
                        {current.characteristics}
                    </div>
                    <div className="lido-market-phase-gauge__tooltip-actions">
                        <div>
                            <span className="lido-market-phase-gauge__tooltip-k">New buys</span>
                            {current.newBuys}
                        </div>
                        <div>
                            <span className="lido-market-phase-gauge__tooltip-k">Positions</span>
                            {current.existingPositions}
                        </div>
                    </div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
