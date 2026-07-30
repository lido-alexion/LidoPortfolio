import React, { useMemo, useState } from 'react';
import HalfDonutShell, { clampScore } from './HalfDonutShell';

/** Classic fear→greed zones (score 0–100). With invertScale, dial reads red→green left→right (extreme greed left / extreme fear right). */
export const SENTIMENT_ZONES = [
    {
        id: 'extreme_fear',
        label: 'EXTREME FEAR',
        min: 0,
        max: 20,
        definition:
            'People are very scared. Prices may be low, which could be a chance to buy.',
    },
    {
        id: 'fear',
        label: 'FEAR',
        min: 20,
        max: 40,
        definition:
            'Investors are worried. It’s good to be careful and watch the market.',
    },
    {
        id: 'neutral',
        label: 'NEUTRAL',
        min: 40,
        max: 60,
        definition:
            'The market is balanced. There is no strong buying or selling.',
    },
    {
        id: 'greed',
        label: 'GREED',
        min: 60,
        max: 80,
        definition:
            'Investors are buying more. It may be a good time to take some profit.',
    },
    {
        id: 'extreme_greed',
        label: 'EXTREME GREED',
        min: 80,
        max: 100,
        definition:
            'People are too confident. Prices may be too high, and a drop could happen soon.',
    },
];

export function sentimentZoneForScore(score) {
    const s = clampScore(score);
    if (s == null) {
        return null;
    }
    return (
        SENTIMENT_ZONES.find((z) => s >= z.min && (s < z.max || z.max === 100))
        || SENTIMENT_ZONES[2]
    );
}

function zoneDisplayName(zone) {
    if (!zone) {
        return '';
    }
    return zone.label.toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Semi-circular fear/greed donut. Score is shown only on hover.
 *
 * @param {{
 *   score: number|null|undefined,
 *   className?: string,
 * }} props
 */
export default function SentimentGauge({ score, className = '' }) {
    const [hovered, setHovered] = useState(false);
    const clamped = clampScore(score);
    const zone = useMemo(() => sentimentZoneForScore(clamped), [clamped]);
    const zoneTitle = zoneDisplayName(zone);

    if (clamped == null || !zone) {
        return null;
    }

    return (
        <HalfDonutShell
            score={clamped}
            zones={SENTIMENT_ZONES}
            invertScale
            className={className}
            tabIndex={0}
            ariaLabel={`Sentiment ${Math.round(clamped)}, ${zoneTitle}. ${zone.definition}`}
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
                            · {zoneTitle}
                        </span>
                    </div>
                    <div className="lido-sentiment-gauge__tooltip-def">{zone.definition}</div>
                </div>
            ) : null}
        </HalfDonutShell>
    );
}
