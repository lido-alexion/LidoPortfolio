import React from 'react';

/**
 * Independent Buy / Sell tap toggles (multi-select) for fee applicability.
 */
export default function FeeSideToggle({
    appliesBuy,
    appliesSell,
    onChange,
    disabled = false,
    ariaLabel = 'Fee applies to',
    compact = false,
}) {
    return (
        <div
            className={[
                'lido-segment-toggle-track',
                compact ? 'lido-segment-toggle-track--compact' : '',
            ].filter(Boolean).join(' ')}
            role="group"
            aria-label={ariaLabel}
        >
            <button
                type="button"
                className={`lido-segment-toggle-btn${appliesBuy ? ' is-active' : ''}`}
                onClick={() => onChange({ applies_buy: !appliesBuy, applies_sell: appliesSell })}
                disabled={disabled}
                aria-pressed={appliesBuy}
            >
                Buy
            </button>
            <button
                type="button"
                className={`lido-segment-toggle-btn${appliesSell ? ' is-active' : ''}`}
                onClick={() => onChange({ applies_buy: appliesBuy, applies_sell: !appliesSell })}
                disabled={disabled}
                aria-pressed={appliesSell}
            >
                Sell
            </button>
        </div>
    );
}
