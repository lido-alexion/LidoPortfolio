import React, { useEffect, useId, useRef, useState } from 'react';

export default function InfoHelp({ label, children }) {
    const reactId = useId();
    const id = `help-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${reactId.replace(/:/g, '')}`;
    const [expanded, setExpanded] = useState(false);
    const container = useRef(null);
    useEffect(() => {
        if (!expanded) return undefined;
        const onPointerDown = (event) => { if (!container.current?.contains(event.target)) setExpanded(false); };
        const onKeyDown = (event) => { if (event.key === 'Escape') setExpanded(false); };
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [expanded]);
    return <span className="ms-1 d-inline-flex align-items-center acceptance-help-container" ref={container}>
        <button type="button" className="btn btn-link p-0 acceptance-help" aria-label={`More information about ${label}`} aria-describedby={id} aria-controls={id} aria-expanded={expanded} onClick={() => setExpanded((open) => !open)}>ⓘ</button>
        <span id={id} role="tooltip" className="acceptance-help-text">{children}</span>
    </span>;
}
