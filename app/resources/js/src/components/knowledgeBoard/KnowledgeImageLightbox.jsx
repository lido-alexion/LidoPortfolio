import React, { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * Click-to-open lightbox for knowledge-board images.
 * Listens for clicks on img.lido-knowledge-image or img[data-full-src]
 * within the given container (document by default when mounted).
 */
export default function KnowledgeImageLightbox({ rootRef = null }) {
    const [src, setSrc] = useState(null);

    const close = useCallback(() => setSrc(null), []);

    useEffect(() => {
        const root = rootRef?.current || document;
        const onClick = (event) => {
            const target = event.target;
            if (!(target instanceof HTMLImageElement)) {
                return;
            }
            if (!target.classList.contains('lido-knowledge-image') && !target.hasAttribute('data-full-src')) {
                return;
            }
            const full = target.getAttribute('data-full-src') || target.getAttribute('src');
            if (!full) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            setSrc(full);
        };
        root.addEventListener('click', onClick);
        return () => root.removeEventListener('click', onClick);
    }, [rootRef]);

    useEffect(() => {
        if (!src) {
            return undefined;
        }
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
            }
        };
        window.addEventListener('keydown', onKey);
        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            window.removeEventListener('keydown', onKey);
            document.body.style.overflow = prevOverflow;
        };
    }, [src, close]);

    if (!src) {
        return null;
    }

    return createPortal(
        <div
            className="lido-knowledge-image-lightbox"
            role="dialog"
            aria-modal="true"
            aria-label="Full-size image"
            onClick={close}
        >
            <button
                type="button"
                className="btn-close btn-close-white lido-knowledge-image-lightbox-close"
                aria-label="Close image"
                onClick={close}
            />
            <img
                src={src}
                alt="Full-size"
                className="lido-knowledge-image-lightbox-img"
                onClick={(event) => event.stopPropagation()}
            />
        </div>,
        document.body,
    );
}
