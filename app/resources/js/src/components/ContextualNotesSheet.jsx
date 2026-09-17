import React, { useEffect, useRef } from 'react';
import ContextualNotesContent from './ContextualNotesContent';

const FOCUSABLE_SELECTOR = [
    'a[href]:not([disabled])',
    'button:not([disabled])',
    'textarea:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export default function ContextualNotesSheet({ onClose }) {
    const sheetRef = useRef(null);

    useEffect(() => {
        const sheet = sheetRef.current;
        if (!sheet) return undefined;

        const focusables = () => Array.from(sheet.querySelectorAll(FOCUSABLE_SELECTOR));
        focusables()[0]?.focus();

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose();
                return;
            }
            if (event.key !== 'Tab') return;

            const items = focusables();
            if (!items.length) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey) {
                if (document.activeElement === first || !sheet.contains(document.activeElement)) {
                    event.preventDefault();
                    last.focus();
                }
            } else if (document.activeElement === last || !sheet.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    return (
        <div
            className="lido-context-notes-overlay"
            role="presentation"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                ref={sheetRef}
                className="lido-context-notes-pane lido-context-notes-pane--mobile"
                role="dialog"
                aria-modal="true"
                aria-labelledby="lido-context-notes-title"
            >
                <ContextualNotesContent onClose={onClose} />
            </section>
        </div>
    );
}
