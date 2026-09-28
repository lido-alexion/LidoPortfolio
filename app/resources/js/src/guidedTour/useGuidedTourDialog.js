import { useEffect } from 'react';

const FOCUSABLE = 'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export default function useGuidedTourDialog(open, dialogRef, onEscape) {
    useEffect(() => {
        if (!open || !dialogRef.current) {
            return undefined;
        }

        const dialog = dialogRef.current;
        const previous = document.activeElement;
        const focusable = () => Array.from(dialog.querySelectorAll(FOCUSABLE));
        const first = focusable()[0];
        (first || dialog).focus();

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onEscape?.();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const elements = focusable();
            if (elements.length === 0) {
                event.preventDefault();
                dialog.focus();
                return;
            }
            const current = elements.indexOf(document.activeElement);
            const next = event.shiftKey
                ? (current <= 0 ? elements.length - 1 : current - 1)
                : (current < 0 || current === elements.length - 1 ? 0 : current + 1);
            if (current < 0 || (event.shiftKey && current === 0) || (!event.shiftKey && current === elements.length - 1)) {
                event.preventDefault();
                elements[next].focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
            if (previous?.isConnected && typeof previous.focus === 'function') {
                previous.focus();
            }
        };
    }, [dialogRef, onEscape, open]);
}
