import React, { useCallback, useMemo } from 'react';

function parseStep(step) {
    const n = Number(step);
    return Number.isNaN(n) || n <= 0 ? 1 : n;
}

function decimalPlaces(step) {
    const parts = String(step).split('.');
    return parts.length > 1 ? parts[1].length : 0;
}

function formatForStep(value, step) {
    const decimals = decimalPlaces(step);
    if (decimals > 0) {
        return value.toFixed(decimals);
    }
    return String(Math.round(value));
}

function parseNumericValue(value) {
    if (value === '' || value === null || value === undefined) {
        return null;
    }
    const num = Number(value);
    return Number.isNaN(num) ? null : num;
}

export default function NumberInput({
    value,
    onChange,
    onBlur,
    step = 1,
    min,
    max,
    className = '',
    id,
    disabled = false,
    allowDecimals = null,
    ...rest
}) {
    const stepNum = parseStep(step);
    const decimalsAllowed = allowDecimals ?? decimalPlaces(stepNum) > 0;

    const numericValue = useMemo(() => parseNumericValue(value), [value]);
    const minNum = min != null ? Number(min) : null;
    const maxNum = max != null ? Number(max) : null;

    const atMin = minNum != null && (numericValue === null || numericValue <= minNum);
    const atMax = maxNum != null && numericValue != null && numericValue >= maxNum;

    const bump = useCallback((direction) => {
        if (disabled) {
            return;
        }

        if (numericValue === null && direction < 0) {
            return;
        }

        const raw = numericValue ?? (minNum != null ? minNum : 0);

        if (Number.isNaN(raw)) {
            return;
        }

        let next = raw + direction * stepNum;
        const decimals = decimalPlaces(stepNum);
        if (decimals > 0) {
            const factor = 10 ** decimals;
            next = Math.round(next * factor) / factor;
        }

        if (minNum != null && next < minNum) {
            next = minNum;
        }
        if (maxNum != null && next > maxNum) {
            next = maxNum;
        }

        onChange?.({
            target: {
                value: formatForStep(next, stepNum),
                id,
            },
        });
    }, [disabled, id, maxNum, minNum, numericValue, onChange, stepNum]);

    const handleInputChange = (event) => {
        const next = event.target.value;
        if (next === '') {
            onChange?.({ target: { value: '', id } });
            return;
        }

        const pattern = decimalsAllowed ? /^\d*\.?\d*$/ : /^\d+$/;
        if (pattern.test(next)) {
            onChange?.({ target: { value: next, id } });
        }
    };

    const wrapperClassName = className
        ? `lido-number-input ${className}`
        : 'lido-number-input';

    return (
        <div className={wrapperClassName}>
            <button
                type="button"
                className="lido-number-input-btn lido-number-input-btn--minus"
                disabled={disabled || atMin}
                onClick={() => bump(-1)}
                aria-label="Decrease value"
                tabIndex={-1}
            >
                −
            </button>
            <input
                type="text"
                inputMode={decimalsAllowed ? 'decimal' : 'numeric'}
                id={id}
                className="lido-number-input-field"
                value={value ?? ''}
                onChange={handleInputChange}
                onBlur={onBlur}
                disabled={disabled}
                autoComplete="off"
                {...rest}
            />
            <button
                type="button"
                className="lido-number-input-btn lido-number-input-btn--plus"
                disabled={disabled || atMax}
                onClick={() => bump(1)}
                aria-label="Increase value"
                tabIndex={-1}
            >
                +
            </button>
        </div>
    );
}
