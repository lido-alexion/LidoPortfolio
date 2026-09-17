import React, { useEffect, useRef } from 'react';
import ContextualNotesContent from './ContextualNotesContent';

export default function ContextualNotesDesktopPane({ onClose }) {
    const paneRef = useRef(null);

    useEffect(() => {
        paneRef.current?.querySelector('button, textarea:not([disabled])')?.focus();
    }, []);

    const handleKeyDown = (event) => {
        if (event.key === 'Escape') onClose();
    };

    return (
        <aside
            ref={paneRef}
            className="lido-context-notes-pane lido-context-notes-pane--desktop"
            aria-labelledby="lido-context-notes-title"
            onKeyDown={handleKeyDown}
        >
            <ContextualNotesContent onClose={onClose} />
        </aside>
    );
}
