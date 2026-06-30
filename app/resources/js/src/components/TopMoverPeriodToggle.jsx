import React from 'react';

function AllTimeIcon() {
    return (
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
            <path
                d="M4 18l4-6 4 3 5-8 3 5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M4 20h16"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
            />
        </svg>
    );
}

function LatestDayIcon() {
    return (
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
            <rect
                x="4"
                y="5"
                width="16"
                height="15"
                rx="2"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
            />
            <line x1="4" y1="9" x2="20" y2="9" stroke="currentColor" strokeWidth="2" />
            <line x1="8" y1="3" x2="8" y2="7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            <line x1="16" y1="3" x2="16" y2="7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            <circle cx="12" cy="15" r="2" fill="currentColor" />
        </svg>
    );
}

const OPTIONS = [
    { value: 'all_time', label: 'All time', Icon: AllTimeIcon },
    { value: 'latest_day', label: 'Latest day', Icon: LatestDayIcon },
];

export default function TopMoverPeriodToggle({ value, onChange }) {
    return (
        <div
            className="lido-top-mover-period-toggle lido-segment-toggle-track"
            role="group"
            aria-label="Top mover period"
        >
            {OPTIONS.map(({ value: optionValue, label, Icon }) => {
                const isActive = value === optionValue;
                return (
                    <button
                        key={optionValue}
                        type="button"
                        className={`lido-top-mover-period-toggle-btn lido-segment-toggle-btn${isActive ? ' is-active' : ''}`}
                        onClick={() => onChange(optionValue)}
                        aria-pressed={isActive}
                        aria-label={label}
                        title={label}
                    >
                        <Icon />
                    </button>
                );
            })}
        </div>
    );
}
