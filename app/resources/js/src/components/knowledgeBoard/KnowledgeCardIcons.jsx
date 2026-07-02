import React from 'react';

function SvgIcon({ children, size = 16, className = '' }) {
    return (
        <svg
            className={['lido-kb-icon', className].filter(Boolean).join(' ')}
            width={size}
            height={size}
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {children}
        </svg>
    );
}

export function IconClock(props) {
    return (
        <SvgIcon {...props}>
            <circle cx="8" cy="8" r="5.5" />
            <path d="M8 5v3.2l2 1.2" />
        </SvgIcon>
    );
}

export function IconPin({ filled = false, ...props }) {
    if (filled) {
        return (
            <svg className="lido-kb-icon" width={16} height={16} viewBox="0 0 16 16" aria-hidden="true" {...props}>
                <path fill="currentColor" d="M8 1.5 5.5 6H3.5L6 8.5V13l2 1.5 2-1.5V8.5L12.5 6h-2L8 1.5Z" />
            </svg>
        );
    }
    return (
        <SvgIcon {...props}>
            <path d="M8 2.5 6 6.5H4l2 2v4.5l2 1.5 2-1.5V8.5l2-2h-2L8 2.5Z" />
        </SvgIcon>
    );
}

export function IconEdit(props) {
    return (
        <SvgIcon {...props}>
            <path d="M10.5 3.5 12.5 5.5 6 12H4v-2l6.5-6.5Z" />
            <path d="M9.5 4.5l2 2" />
        </SvgIcon>
    );
}

export function IconDuplicate(props) {
    return (
        <SvgIcon {...props}>
            <rect x="5.5" y="5.5" width="7" height="7" rx="1" />
            <path d="M3.5 10.5H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h6.5a1 1 0 0 1 1 1v.5" />
        </SvgIcon>
    );
}

export function IconArchive(props) {
    return (
        <SvgIcon {...props}>
            <path d="M2.5 4.5h11" />
            <path d="M4 4.5V12a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.5" />
            <path d="M6 7.5h4" />
            <path d="M6.5 2.5h3l.5 2H6l.5-2Z" />
        </SvgIcon>
    );
}

export function IconDelete(props) {
    return (
        <SvgIcon {...props}>
            <path d="M3.5 5h9" />
            <path d="M6 5V3.5h4V5" />
            <path d="M5 5l.4 7h5.2L11 5" />
        </SvgIcon>
    );
}

export function IconDrag(props) {
    return (
        <SvgIcon {...props}>
            <circle cx="6" cy="4" r="0.75" fill="currentColor" stroke="none" />
            <circle cx="10" cy="4" r="0.75" fill="currentColor" stroke="none" />
            <circle cx="6" cy="8" r="0.75" fill="currentColor" stroke="none" />
            <circle cx="10" cy="8" r="0.75" fill="currentColor" stroke="none" />
            <circle cx="6" cy="12" r="0.75" fill="currentColor" stroke="none" />
            <circle cx="10" cy="12" r="0.75" fill="currentColor" stroke="none" />
        </SvgIcon>
    );
}

export function IconMenu(props) {
    return (
        <SvgIcon {...props}>
            <circle cx="8" cy="4" r="0.85" fill="currentColor" stroke="none" />
            <circle cx="8" cy="8" r="0.85" fill="currentColor" stroke="none" />
            <circle cx="8" cy="12" r="0.85" fill="currentColor" stroke="none" />
        </SvgIcon>
    );
}

export function IconChevronDown(props) {
    return (
        <SvgIcon {...props}>
            <path d="M4 6.5 8 10.5 12 6.5" />
        </SvgIcon>
    );
}
