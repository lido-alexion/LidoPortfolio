import React, { useEffect, useRef } from 'react';

// Non-modal confirmation disclosure: move focus into the prompt, let Tab move
// naturally through the page, and return focus to its trigger when dismissed.
export default function ConfirmationPrompt({ titleId, descriptionId, onDismiss, children }) {
    const prompt = useRef(null);
    const dismiss = useRef(onDismiss);
    const lifecycle = useRef(0);
    const returnFocus = useRef(null);
    dismiss.current = onDismiss;
    useEffect(() => {
        const generation = ++lifecycle.current;
        if (!returnFocus.current) returnFocus.current = document.activeElement;
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
            queueMicrotask(() => {
                // StrictMode runs setup → cleanup → setup on mount. Ignore the
                // simulated cleanup when a newer effect generation owns focus.
                if (lifecycle.current !== generation) return;
                if (returnFocus.current instanceof HTMLElement && returnFocus.current.isConnected) returnFocus.current.focus();
            });
        };
    }, []);

    return <div ref={prompt} className="alert alert-warning mt-3" role="alertdialog" aria-modal="false" aria-labelledby={titleId} aria-describedby={descriptionId}>
        {children}
    </div>;
}
