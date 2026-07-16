import React, { useEffect, useRef } from 'react';
import { Tooltip as BootstrapTooltip } from 'bootstrap';

/**
 * Wraps a label; shows a Bootstrap tooltip on hover/focus.
 */
export default function FieldHint({ text, id, children }) {
    const triggerRef = useRef(null);

    useEffect(() => {
        const el = triggerRef.current;
        if (!el || !text) {
            return undefined;
        }

        BootstrapTooltip.getInstance(el)?.dispose();

        const tooltip = new BootstrapTooltip(el, {
            title: text,
            placement: 'top',
            trigger: 'hover focus',
            customClass: 'lido-field-hint-tooltip',
        });

        return () => tooltip.dispose();
    }, [text]);

    if (!text) {
        return children ?? null;
    }

    return (
        <span
            ref={triggerRef}
            id={id}
            className="lido-field-hint-label"
            tabIndex={0}
            role="button"
            aria-label={text}
        >
            {children}
        </span>
    );
}
