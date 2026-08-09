import React, { useRef } from 'react';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
    parseTransactionDateDisplay,
} from '../utils/transactionDate';

function CalendarIcon() {
    const dotCols = [8, 12, 16];
    const dotRows = [13.5, 16.5, 19.5];

    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect x="3" y="4" width="18" height="18" rx="2" />
            <line x1="3" y1="10" x2="21" y2="10" />
            <line x1="8" y1="2" x2="8" y2="6" />
            <line x1="16" y1="2" x2="16" y2="6" />
            {dotRows.flatMap((y) => dotCols.map((x) => (
                <circle
                    key={`${x}-${y}`}
                    cx={x}
                    cy={y}
                    r="1.1"
                    fill="currentColor"
                    stroke="none"
                />
            )))}
        </svg>
    );
}

export default function TransactionDateInput({
    id,
    displayValue,
    isoValue,
    onDisplayChange,
    onIsoChange,
    onFocus,
    onBlur,
    invalid = false,
    describedBy,
    required = false,
    fallbackIso,
    disabled = false,
}) {
    const nativeDateInputRef = useRef(null);

    const openDatePicker = () => {
        if (disabled) {
            return;
        }
        const el = nativeDateInputRef.current;
        if (!el) {
            return;
        }
        if (typeof el.showPicker === 'function') {
            try {
                el.showPicker();
                return;
            } catch {
                // showPicker can throw if not triggered by user gesture in some browsers
            }
        }
        el.click();
    };

    const handleNativeDateChange = (event) => {
        const iso = event.target.value;
        if (!iso) {
            return;
        }
        onIsoChange(iso);
        onDisplayChange(formatTransactionDateDisplay(iso));
    };

    const handleBlur = () => {
        const iso = parseTransactionDateDisplay(displayValue);
        if (iso) {
            onIsoChange(iso);
            onDisplayChange(formatTransactionDateDisplay(iso));
        } else {
            onDisplayChange(formatTransactionDateDisplay(fallbackIso || isoValue));
        }
        onBlur?.();
    };

    return (
        <div className="lido-date-input">
            <input
                id={id}
                className={`form-control lido-date-input-field${invalid ? ' is-invalid' : ''}`}
                type="text"
                inputMode="text"
                autoComplete="off"
                placeholder="dd-mmm-yyyy"
                value={displayValue}
                disabled={disabled}
                onChange={(event) => {
                    const next = event.target.value;
                    onDisplayChange(next);
                    const iso = parseTransactionDateDisplay(next);
                    if (iso) {
                        onIsoChange(iso);
                    }
                }}
                onFocus={() => onFocus?.()}
                onBlur={handleBlur}
                required={required}
                aria-invalid={invalid}
                aria-describedby={describedBy}
            />
            <button
                type="button"
                className="lido-date-input-btn"
                onClick={openDatePicker}
                disabled={disabled}
                aria-label="Open calendar"
                title="Open calendar"
            >
                <CalendarIcon />
            </button>
            <input
                ref={nativeDateInputRef}
                type="date"
                className="lido-date-input-native"
                tabIndex={-1}
                aria-hidden="true"
                value={isoValue || ''}
                max={getLocalTodayDateString()}
                disabled={disabled}
                onChange={handleNativeDateChange}
            />
        </div>
    );
}
