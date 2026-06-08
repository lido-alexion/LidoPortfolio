import React, { useRef } from 'react';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
    parseTransactionDateDisplay,
} from '../utils/transactionDate';

function CalendarIcon() {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width="16"
            height="16"
            fill="currentColor"
            viewBox="0 0 16 16"
            aria-hidden="true"
        >
            <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4z" />
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
}) {
    const nativeDateInputRef = useRef(null);

    const openDatePicker = () => {
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
                onChange={handleNativeDateChange}
            />
        </div>
    );
}
