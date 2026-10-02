import React, { useEffect, useRef, useState } from 'react';
import StockInsightSurface from './StockInsightSurface';

function PuzzlePieceIcon({ size = 14 }) {
    return (
        <svg
            viewBox="0 0 24 24"
            width={size}
            height={size}
            aria-hidden="true"
            focusable="false"
            fill="currentColor"
        >
            <path d="M20.5 11H19V7c0-1.1-.9-2-2-2h-4V3.5C13 2.12 11.88 1 10.5 1S8 2.12 8 3.5V5H4c-1.1 0-1.99.9-1.99 2v3.8H3.5c1.49 0 2.7 1.21 2.7 2.7s-1.21 2.7-2.7 2.7H2V20c0 1.1.9 2 2 2h3.8v-1.5c0-1.49 1.21-2.7 2.7-2.7 1.49 0 2.7 1.21 2.7 2.7V22H17c1.1 0 2-.9 2-2v-4h1.5c1.38 0 2.5-1.12 2.5-2.5S21.88 11 20.5 11z" />
        </svg>
    );
}

/** All seven stock placements invoke one managed capability. */
export default function AnalyseStockButton({ stockId, symbol = '', name = '', className = '', size = 14, stopPropagation = false, presentation = 'dense' }) {
    const [open, setOpen] = useState(false);
    const [host, setHost] = useState(null);
    const button = useRef(null);
    useEffect(() => {
        const close = event => { if (event.detail !== button.current) setOpen(false); };
        window.addEventListener('stox:open-insight', close);
        window.addEventListener('portfolio-changed', close);
        return () => { window.removeEventListener('stox:open-insight', close); window.removeEventListener('portfolio-changed', close); };
    }, []);
    useEffect(() => { setOpen(false); }, [stockId]);
    useEffect(() => {
        if (!open || presentation !== 'inline') return undefined;
        const element = document.createElement('div');
        const card = button.current.closest('.card-body');
        const resultRow = button.current.closest('[data-ai-insight-context]') ? card?.closest('.row') : null;
        if (resultRow) resultRow.after(element);
        else (card || button.current.parentElement.parentElement).appendChild(element);
        setHost(element);
        return () => { element.remove(); setHost(null); };
    }, [open, presentation]);
    return <>
        <button ref={button} type="button" className={['btn btn-link p-0 lido-analyse-stock-btn', className].filter(Boolean).join(' ')}
            title="Open AI Insights" aria-label="Open AI Insights" aria-expanded={open} disabled={!stockId}
            onClick={event => { if (stopPropagation) { event.preventDefault(); event.stopPropagation(); } window.dispatchEvent(new CustomEvent('stox:open-insight', { detail: button.current })); setOpen(value => !value); }}
            onMouseDown={stopPropagation ? event => event.stopPropagation() : undefined}><PuzzlePieceIcon size={size} /></button>
        {open && (presentation !== 'inline' || host) && <StockInsightSurface key={stockId} stockId={stockId} symbol={symbol} name={name} inlineHost={presentation === 'inline' ? host : null} onClose={() => setOpen(false)} />}
    </>;
}
