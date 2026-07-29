import React, { useEffect, useId, useRef, useState } from 'react';

/**
 * Lazy-loads Mermaid and renders a flowchart / sequence diagram.
 * Falls back to a pre block if Mermaid fails.
 */
export default function DocMermaid({ chart }) {
    const raw = String(chart || '').trim();
    const reactId = useId().replace(/:/g, '');
    const hostRef = useRef(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;
        if (!raw || !hostRef.current) {
            return undefined;
        }

        (async () => {
            try {
                const mermaid = (await import('mermaid')).default;
                mermaid.initialize({
                    startOnLoad: false,
                    securityLevel: 'strict',
                    theme: document.documentElement.dataset.bsTheme === 'dark' ? 'dark' : 'default',
                    fontFamily: 'inherit',
                });
                const id = `lido-mermaid-${reactId}-${Math.random().toString(36).slice(2, 8)}`;
                const { svg } = await mermaid.render(id, raw);
                if (!cancelled && hostRef.current) {
                    hostRef.current.innerHTML = svg;
                }
            } catch {
                if (!cancelled) {
                    setFailed(true);
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [raw, reactId]);

    if (!raw) {
        return null;
    }

    if (failed) {
        return <pre className="lido-docs-pre small mb-3">{raw}</pre>;
    }

    return (
        <div
            className="lido-docs-mermaid mb-3 border rounded-3 p-2 p-md-3"
            ref={hostRef}
            role="img"
            aria-label="Diagram"
        />
    );
}
