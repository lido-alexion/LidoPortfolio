import React from 'react';
import { useLocation } from 'react-router-dom';
import { openDocumentationForPath } from '../utils/documentationLinks';

export default function HeaderHelpButton() {
    const { pathname } = useLocation();

    return (
        <button
            type="button"
            className="lido-header-help"
            title="Open documentation for this page"
            aria-label="Open documentation for this page"
            onClick={() => openDocumentationForPath(pathname)}
        >
            <svg
                className="lido-header-help-icon"
                viewBox="0 0 24 24"
                width="20"
                height="20"
                aria-hidden="true"
                focusable="false"
            >
                <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" strokeWidth="1.75" />
                <path
                    d="M9.5 9.25a2.5 2.5 0 0 1 4.85.75c0 1.5-2.35 1.75-2.35 3"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                />
                <circle cx="12" cy="17" r="1.1" fill="currentColor" />
            </svg>
        </button>
    );
}
