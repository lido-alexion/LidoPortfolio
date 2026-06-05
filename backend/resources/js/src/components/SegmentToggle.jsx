import React from 'react';

export default function SegmentToggle({
    label,
    value,
    onChange,
    options,
    disabled = false,
    ariaLabel,
    className = '',
}) {
    return (
        <div className={`lido-segment-toggle${className ? ` ${className}` : ''}`}>
            {label && <span className="form-label d-block mb-1">{label}</span>}
            <div className="lido-segment-toggle-track" role="group" aria-label={ariaLabel || label}>
                {options.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        className={`lido-segment-toggle-btn${value === option.value ? ' is-active' : ''}`}
                        onClick={() => onChange(option.value)}
                        disabled={disabled}
                        aria-pressed={value === option.value}
                    >
                        {option.label}
                    </button>
                ))}
            </div>
        </div>
    );
}
