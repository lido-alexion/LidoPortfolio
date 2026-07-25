import React, { useCallback, useMemo, useState } from 'react';

function parseStep(step) {
    const n = Number(step);
    return Number.isNaN(n) || n <= 0 ? 1 : n;
}

function decimalPlaces(step) {
    const parts = String(step).split('.');
    return parts.length > 1 ? parts[1].length : 0;
}

function trimTrailingDecimalZeros(formatted) {
    if (!formatted.includes('.')) {
        return formatted;
    }
    return formatted.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
}

function formatForStep(value, step) {
    const decimals = decimalPlaces(step);
    if (decimals > 0) {
        return trimTrailingDecimalZeros(value.toFixed(decimals));
    }
    return String(Math.round(value));
}

function formatValue(value, step, fixedDecimals) {
    if (fixedDecimals != null && Number.isInteger(fixedDecimals) && fixedDecimals >= 0) {
        return Number(value).toFixed(fixedDecimals);
    }
    return formatForStep(value, step);
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
    onFocus,
    step = 1,
    min,
    max,
    className = '',
    id,
    disabled = false,
    allowDecimals = null,
    allowNegative = false,
    height,
    compact = false,
    fixedDecimals = null,
    ...rest
}) {
    const [focused, setFocused] = useState(false);
    const stepNum = parseStep(step);
    const decimalsAllowed = allowDecimals ?? decimalPlaces(stepNum) > 0;

    const numericValue = useMemo(() => parseNumericValue(value), [value]);
    const minNum = min != null ? Number(min) : null;
    const maxNum = max != null ? Number(max) : null;

    const atMin = minNum != null && (numericValue === null || numericValue <= minNum);
    const atMax = maxNum != null && numericValue != null && numericValue >= maxNum;

    const displayValue = useMemo(() => {
        if (value === '' || value === null || value === undefined) {
            return '';
        }
        if (focused || fixedDecimals == null) {
            return value ?? '';
        }
        const num = parseNumericValue(value);
        if (num === null) {
            return value ?? '';
        }
        return formatValue(num, stepNum, fixedDecimals);
    }, [value, focused, fixedDecimals, stepNum]);

    const bump = useCallback((direction) => {
        if (disabled) {
            return;
        }

        if (numericValue === null && direction < 0 && !allowNegative) {
            return;
        }

        const raw = numericValue ?? (minNum != null ? minNum : 0);

        if (Number.isNaN(raw)) {
            return;
        }

        let next = raw + direction * stepNum;
        const roundPrecision = fixedDecimals != null
            ? Math.max(fixedDecimals, decimalPlaces(stepNum))
            : decimalPlaces(stepNum);
        if (roundPrecision > 0) {
            const factor = 10 ** roundPrecision;
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
                value: formatValue(next, stepNum, fixedDecimals),
                id,
            },
        });
    }, [allowNegative, disabled, fixedDecimals, id, maxNum, minNum, numericValue, onChange, stepNum]);

    const handleInputChange = (event) => {
        const next = event.target.value;
        if (next === '' || (allowNegative && next === '-')) {
            onChange?.({ target: { value: next === '-' ? '-' : '', id } });
            return;
        }

        const pattern = decimalsAllowed
            ? (allowNegative ? /^-?\d*\.?\d*$/ : /^\d*\.?\d*$/)
            : (allowNegative ? /^-?\d+$/ : /^\d+$/);
        if (pattern.test(next)) {
            onChange?.({ target: { value: next, id } });
        }
    };

    const handleFocus = (event) => {
        setFocused(true);
        onFocus?.(event);
    };

    const handleBlur = (event) => {
        setFocused(false);
        if (numericValue !== null && !Number.isNaN(numericValue)) {
            const normalized = formatValue(numericValue, stepNum, fixedDecimals);
            if (normalized !== (value ?? '')) {
                onChange?.({ target: { value: normalized, id } });
            }
        }
        onBlur?.(event);
    };

    const wrapperClassName = [
        'lido-number-input',
        compact ? 'lido-number-input--compact' : '',
        className,
    ].filter(Boolean).join(' ');

    const wrapperStyle = height
        ? { height, minHeight: height }
        : undefined;

    return (
        <div className={wrapperClassName} style={wrapperStyle}>
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
                value={displayValue}
                onChange={handleInputChange}
                onFocus={handleFocus}
                onBlur={handleBlur}
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
