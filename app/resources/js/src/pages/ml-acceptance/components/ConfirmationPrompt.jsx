import React, { useEffect, useRef } from 'react';

// Non-modal confirmation disclosure: move focus into the prompt, let Tab move
// naturally through the page, and return focus to its trigger when dismissed.
export default function ConfirmationPrompt({ titleId, descriptionId, onDismiss, children }) {
    const prompt = useRef(null);
    const dismiss = useRef(onDismiss);
    dismiss.current = onDismiss;
    useEffect(() => {
        const returnFocus = document.activeElement;
        prompt.current?.querySelector('[data-confirmation-title]')?.focus();
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                dismiss.current();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            window.setTimeout(() => {
                if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus();
            }, 0);
        };
    }, []);

    return <div ref={prompt} className="alert alert-warning mt-3" role="alertdialog" aria-modal="false" aria-labelledby={titleId} aria-describedby={descriptionId}>
        {children}
    </div>;
}
