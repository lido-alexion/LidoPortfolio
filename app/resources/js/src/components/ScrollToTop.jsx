import React, { useEffect, useState } from 'react';

const DEFAULT_THRESHOLD = 320;

export default function ScrollToTop({ threshold = DEFAULT_THRESHOLD, className = '' }) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const container = document.querySelector('.lido-shell > .lido-main');
        if (!container) {
            return undefined;
        }

        const update = () => setVisible(container.scrollTop > threshold);
        update();
        container.addEventListener('scroll', update, { passive: true });
        return () => container.removeEventListener('scroll', update);
    }, [threshold]);

    if (!visible) {
        return null;
    }

    const scrollToTop = () => {
        const container = document.querySelector('.lido-shell > .lido-main');
        if (!container) return;
        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        if (typeof container.scrollTo === 'function') {
            container.scrollTo({ top: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
        } else {
            container.scrollTop = 0;
        }
    };

    return (
        <button
            type="button"
            className={`lido-scroll-to-top ${className}`.trim()}
            aria-label="Scroll to top of page"
            title="Scroll to top"
            onClick={scrollToTop}
        >
            <i className="bi bi-arrow-up" aria-hidden="true" />
        </button>
    );
}
