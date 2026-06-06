import React from 'react';
import { useTheme } from '../context/ThemeContext';

function SunIcon() {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="4" fill="currentColor" />
            <g stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
                <line x1="12" y1="2" x2="12" y2="5" />
                <line x1="12" y1="19" x2="12" y2="22" />
                <line x1="2" y1="12" x2="5" y2="12" />
                <line x1="19" y1="12" x2="22" y2="12" />
                <line x1="4.2" y1="4.2" x2="6.3" y2="6.3" />
                <line x1="17.7" y1="17.7" x2="19.8" y2="19.8" />
                <line x1="17.7" y1="6.3" x2="19.8" y2="4.2" />
                <line x1="4.2" y1="19.8" x2="6.3" y2="17.7" />
            </g>
        </svg>
    );
}

function MonitorIcon() {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <rect x="3" y="4" width="18" height="12" rx="1.5" fill="none" stroke="currentColor" strokeWidth="1.75" />
            <line x1="9" y1="20" x2="15" y2="20" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
            <line x1="12" y1="16" x2="12" y2="20" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
        </svg>
    );
}

function MoonIcon() {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path
                d="M20 14.5A7.5 7.5 0 0 1 9.5 4 6.5 6.5 0 1 0 20 14.5Z"
                fill="currentColor"
            />
        </svg>
    );
}

const THEME_SEGMENTS = [
    { value: 'light', label: 'Light theme', Icon: SunIcon },
    { value: 'system', label: 'System theme', Icon: MonitorIcon },
    { value: 'dark', label: 'Dark theme', Icon: MoonIcon },
];

const ACTIVE_INDEX = {
    light: 0,
    system: 1,
    dark: 2,
};

export default function ThemeToggle() {
    const { theme, setTheme } = useTheme();
    const activeSegment = THEME_SEGMENTS.find((segment) => segment.value === theme) ?? THEME_SEGMENTS[1];
    const ActiveIcon = activeSegment.Icon;

    return (
        <div className="lido-theme-toggle-wrap">
            <span className="lido-theme-toggle-label">Theme</span>
            <div
                className="lido-theme-toggle"
                data-active-index={ACTIVE_INDEX[theme] ?? 1}
                role="group"
                aria-label="Theme"
            >
                <span className="lido-theme-toggle-slider" aria-hidden="true">
                    <ActiveIcon />
                </span>
                {THEME_SEGMENTS.map(({ value, label }) => {
                    const isActive = theme === value;
                    return (
                        <button
                            key={value}
                            type="button"
                            className={`lido-theme-toggle-segment${isActive ? ' is-active' : ''}`}
                            onClick={() => setTheme(value)}
                            aria-label={label}
                            aria-pressed={isActive}
                            title={label}
                        />
                    );
                })}
            </div>
        </div>
    );
}
