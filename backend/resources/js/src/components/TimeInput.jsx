import React from 'react';
import { isValidCronTime, normalizeCronTime } from '../utils/cronTime';

export default function TimeInput({
    value,
    onChange,
    onBlur,
    id,
    disabled = false,
    className = '',
    invalid = false,
    describedBy,
    ...rest
}) {
    const inputClassName = `form-control${invalid ? ' is-invalid' : ''}${className ? ` ${className}` : ''}`;

    return (
        <input
            type="time"
            id={id}
            className={inputClassName}
            value={value || ''}
            disabled={disabled}
            step={60}
            onChange={(event) => {
                onChange?.({
                    target: {
                        value: event.target.value,
                        id,
                    },
                });
            }}
            onBlur={(event) => {
                const normalized = normalizeCronTime(event.target.value);
                if (normalized !== event.target.value) {
                    onChange?.({
                        target: {
                            value: normalized,
                            id,
                        },
                    });
                }
                onBlur?.(event);
            }}
            aria-invalid={invalid || undefined}
            aria-describedby={describedBy}
            {...rest}
        />
    );
}

export { isValidCronTime };
