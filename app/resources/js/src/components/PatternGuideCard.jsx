import React from 'react';
import PatternSketch from './PatternSketch';

const CATEGORY_LABELS = {
    bullish: 'Bullish',
    bearish: 'Bearish',
    neutral: 'Neutral',
    bullish_continuation: 'Bullish continuation',
    bearish_continuation: 'Bearish continuation',
    bullish_reversal: 'Bullish reversal',
    bearish_reversal: 'Bearish reversal',
};

const CATEGORY_CLASS = {
    bullish: 'text-success',
    bearish: 'text-danger',
    neutral: 'text-body',
    bullish_continuation: 'text-success',
    bearish_continuation: 'text-danger',
    bullish_reversal: 'text-success',
    bearish_reversal: 'text-danger',
};

function categoryClass(category) {
    return CATEGORY_CLASS[category] || 'text-body';
}

function categoryLabel(category) {
    return CATEGORY_LABELS[category] || category;
}

export default function PatternGuideCard({
    pattern,
    variant = 'chart',
    open = false,
    onOpenChange,
}) {
    const candleLabel = pattern.candleCount
        ? `${pattern.candleCount} candle${pattern.candleCount > 1 ? 's' : ''}`
        : null;
    const barLabel = pattern.minBars ? `~${pattern.minBars}+ bars` : null;

    const handleToggle = () => {
        onOpenChange?.(!open);
    };

    return (
        <div id={pattern.id} className="card lido-pattern-guide-card lido-pattern-guide-anchor">
            <button
                type="button"
                className="card-header d-flex justify-content-between align-items-center gap-2 w-100 border-0 bg-transparent text-start"
                onClick={handleToggle}
                aria-expanded={open}
                aria-controls={`${pattern.id}-details`}
            >
                <span className="d-flex align-items-center gap-2 min-w-0">
                    <PatternSketch patternId={pattern.id} className="flex-shrink-0" />
                    <span className="min-w-0">
                        <span className="fw-semibold">{pattern.name}</span>
                        {' '}
                        <span className={`small ${categoryClass(pattern.category)}`}>
                            ({categoryLabel(pattern.category)})
                        </span>
                    </span>
                </span>
                <span className="text-muted small text-nowrap">
                    {variant === 'candle' ? candleLabel : barLabel}
                    {' '}
                    {open ? '▾' : '▸'}
                </span>
            </button>
            {open ? (
                <div id={`${pattern.id}-details`} className="card-body pt-0">
                    {pattern.trendContext ? (
                        <p className="small text-muted mb-2">
                            <strong>Context:</strong>
                            {' '}
                            {pattern.trendContext}
                        </p>
                    ) : null}

                    <h6 className="small text-uppercase text-muted mb-1">Characteristics</h6>
                    <ul className="small mb-3">
                        {pattern.characteristics.map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>

                    <h6 className="small text-uppercase text-muted mb-1">What it means</h6>
                    <p className="small mb-3">{pattern.meaning}</p>

                    <h6 className="small text-uppercase text-muted mb-1">OHLCV math (detection rules)</h6>
                    <p className="small text-muted mb-2">
                        Scanners test these inequalities on OHLCV windows (see Watchlist scan and chart hints).
                        Terms are defined in the glossary below.
                    </p>
                    <ol className="small lido-pattern-math-list mb-0">
                        {pattern.mathRules.map((rule) => (
                            <li key={rule}>
                                <code>{rule}</code>
                            </li>
                        ))}
                    </ol>
                </div>
            ) : null}
        </div>
    );
}
